<?php
session_start();
require 'config/database.php';

// ===== READ ACTIVE FILTERS FROM GET =====
$f_processor = trim($_GET['processor'] ?? '');
$f_end_user = trim($_GET['end_user'] ?? '');
$f_supplier = trim($_GET['supplier'] ?? '');
$f_pr_number = trim($_GET['pr_number'] ?? '');
$f_category = trim($_GET['category'] ?? '');

// ===== BUILD DYNAMIC WHERE CLAUSE FOR DB QUERIES =====
$db_conditions = [];
$db_params = [];

if ($f_processor !== '') {
    $db_conditions[] = "processor = :processor";
    $db_params['processor'] = $f_processor;
}
if ($f_end_user !== '') {
    $db_conditions[] = "end_user = :end_user";
    $db_params['end_user'] = $f_end_user;
}
if ($f_supplier !== '') {
    $db_conditions[] = "supplier LIKE :supplier";
    $db_params['supplier'] = "%$f_supplier%";
}
if ($f_pr_number !== '') {
    $db_conditions[] = "pr_number LIKE :pr_number";
    $db_params['pr_number'] = "%$f_pr_number%";
}
if ($f_category !== '') {
    $db_conditions[] = "category = :category";
    $db_params['category'] = $f_category;
}

$base_where = !empty($db_conditions) ? "WHERE " . implode(" AND ", $db_conditions) : "";

function countWhere($pdo, $extra, $params, $base_where, $db_conditions)
{
    $conds = $db_conditions;
    if ($extra)
        $conds[] = $extra;
    $where = !empty($conds) ? "WHERE " . implode(" AND ", $conds) : "";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_requests $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// ===== FETCH DASHBOARD METRICS =====
$today = date("Y-m-d");

$total_prs = countWhere($pdo, null, $db_params, $base_where, $db_conditions);
$paid_prs = countWhere($pdo, "paid_date IS NOT NULL", $db_params, $base_where, $db_conditions);
$cancelled_prs = countWhere($pdo, "status = 'CANCELLED'", $db_params, $base_where, $db_conditions);
$pending_prs = countWhere($pdo, "paid_date IS NULL AND status != 'CANCELLED'", $db_params, $base_where, $db_conditions);

$overdue_conds = $db_conditions;
$overdue_conds[] = "paid_date IS NULL AND status != 'CANCELLED' AND date_endorsement IS NOT NULL";
$overdue_where = "WHERE " . implode(" AND ", $overdue_conds);
$stmt = $pdo->prepare("SELECT id, date_endorsement FROM purchase_requests $overdue_where");
$stmt->execute($db_params);
$overdue_rows = $stmt->fetchAll();
$overdue_count = 0;
foreach ($overdue_rows as $pr) {
    if ((strtotime($today) - strtotime($pr['date_endorsement'])) / 86400 > 3)
        $overdue_count++;
}

$no_payment = max(0, $pending_prs - $overdue_count);

$stmt = $pdo->prepare("
    SELECT processor, COUNT(*) as count FROM purchase_requests
    WHERE processor IS NOT NULL AND processor != ''
    GROUP BY processor ORDER BY count DESC LIMIT 20
");
$stmt->execute();
$processor_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT end_user, COUNT(*) as count FROM purchase_requests
    WHERE end_user IS NOT NULL AND end_user != ''
    GROUP BY end_user ORDER BY end_user ASC
");
$stmt->execute();
$end_user_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FETCH APP STATS FROM GOOGLE SHEET =====
$app_stats = ['total_amount' => 0, 'abc' => 0, 'savings' => 0, 'total_items' => 0, 'loaded' => false];
try {
    $app_csv_url = 'https://docs.google.com/spreadsheets/d/1WhY3oBDU__XUc9KPCyRF-1_QcDzQ_10HTH3Kk1gMWKk/export?format=csv&gid=0';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    $raw = @file_get_contents($app_csv_url, false, $ctx);
    if ($raw !== false && trim($raw) !== '') {
        $toNum = fn($v) => (float) preg_replace('/[^\d.\-]/', '', str_replace(',', '', (string) $v));
        $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
        $col_a_sum = 0.0; // Col A = Total Amount per APP
        $col_b_sum = 0.0; // Col B = ABC
        $col_c_sum = 0.0; // Col C = Savings
        $count = 0;
        for ($i = 1; $i < count($lines); $i++) {
            $row = str_getcsv($lines[$i]);
            $a = trim($row[0] ?? '');
            $b = trim($row[1] ?? '');
            $c = trim($row[2] ?? '');
            $row_vals = array_map('trim', $row);
            $row_nonempty = array_filter($row_vals, fn($v) => $v !== '');
            if (empty($row_nonempty))
                continue;
            $count++;
            $col_a_sum += $toNum($a);
            $col_b_sum += $toNum($b);
            $col_c_sum += $toNum($c);
        }
        $app_stats = [
            'total_amount' => round($col_a_sum, 2),  // Col A
            'abc' => round($col_b_sum, 2),  // Col B
            'savings' => round($col_c_sum, 2),  // Col C
            'total_items' => $count,
            'loaded' => true,
        ];
    }
} catch (Exception $e) {
}

// ===== FETCH PR STATUS TRACKING DATA FROM GOOGLE SHEET =====
$tracking_sheet_url = 'https://docs.google.com/spreadsheets/d/1TP5cu3kwKE_h_jwe5UZ8XLZCDBlKKlWkHTyg7Vqi6-o/edit?usp=sharing';
preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $tracking_sheet_url, $idMatch);
$sheet_id = $idMatch[1] ?? '';
$sheet_csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv&gid=0";

$tracking_stats = [];
$category_stats = [];
$posted_canvas_stats = ['posted_count' => 0, 'canvas_count' => 0];
$tracking_total = 0;
$sheet_data = [];
$sheet_suppliers = [];
$sheet_pr_numbers = [];

try {
    $csv = @file_get_contents($sheet_csv_url);
    if ($csv !== false) {
        $lines = array_filter(array_map('trim', explode("\n", $csv)));
        if (count($lines) >= 2) {
            $rows = array_map('str_getcsv', $lines);

            $header_row = array_map('trim', $rows[0]);
            $col = [];
            foreach ($header_row as $i => $h) {
                $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $h));
                $col[$key] = $i;
            }

            $idx_end_user = $col['enduser'] ?? $col['end_user'] ?? $col['user'] ?? 0;
            $idx_pr = $col['prnumber'] ?? $col['pr_number'] ?? $col['prno'] ?? $col['pr'] ?? 1;
            $idx_posted = $col['posted'] ?? 2;
            $idx_canvas = $col['canvas'] ?? 3;
            $idx_category = $col['category'] ?? $col['cat'] ?? 4;
            $idx_supplier = $col['supplier'] ?? $col['suppliername'] ?? $col['vendor'] ?? 5;

            for ($r = 1; $r < count($rows); $r++) {
                $row = $rows[$r] ?? [];
                $allEmpty = true;
                foreach ($row as $c) {
                    if (trim((string) $c) !== '') {
                        $allEmpty = false;
                        break;
                    }
                }
                if ($allEmpty)
                    continue;

                $posted_val = in_array(strtolower(trim($row[$idx_posted] ?? '')), ['true', '1', 'yes', 'y']) ? 1 : 0;
                $canvas_val = in_array(strtolower(trim($row[$idx_canvas] ?? '')), ['true', '1', 'yes', 'y']) ? 1 : 0;
                $category = strtoupper(trim($row[$idx_category] ?? ''));
                $end_user = trim($row[$idx_end_user] ?? '');
                $pr_num = trim($row[$idx_pr] ?? '');
                $supplier = trim($row[$idx_supplier] ?? '');

                if ($pr_num !== '' && !in_array($pr_num, $sheet_pr_numbers))
                    $sheet_pr_numbers[] = $pr_num;
                if ($supplier !== '' && !in_array($supplier, $sheet_suppliers))
                    $sheet_suppliers[] = $supplier;

                if ($f_end_user !== '' && strcasecmp($end_user, $f_end_user) !== 0)
                    continue;
                if ($f_supplier !== '' && stripos($supplier, $f_supplier) === false)
                    continue;
                if ($f_pr_number !== '' && stripos($pr_num, $f_pr_number) === false)
                    continue;
                if ($f_category !== '' && strcasecmp($category, $f_category) !== 0)
                    continue;

                $tracking_total++;
                $sheet_data[] = [
                    'end_user' => $end_user,
                    'pr_number' => $pr_num,
                    'posted' => $posted_val,
                    'canvas' => $canvas_val,
                    'category' => $category,
                    'supplier' => $supplier
                ];

                if ($posted_val)
                    $posted_canvas_stats['posted_count']++;
                if ($canvas_val)
                    $posted_canvas_stats['canvas_count']++;

                if ($category !== '') {
                    $found = false;
                    foreach ($category_stats as &$cat) {
                        if (strtoupper($cat['category']) === $category) {
                            $cat['count']++;
                            $found = true;
                            break;
                        }
                    }
                    unset($cat);
                    if (!$found)
                        $category_stats[] = ['category' => $category, 'count' => 1];
                }

                if ($end_user !== '') {
                    $found = false;
                    foreach ($tracking_stats as &$eu) {
                        if (strtolower($eu['end_user']) === strtolower($end_user)) {
                            $eu['count']++;
                            $found = true;
                            break;
                        }
                    }
                    unset($eu);
                    if (!$found)
                        $tracking_stats[] = ['end_user' => $end_user, 'count' => 1];
                }
            }

            usort($category_stats, fn($a, $b) => $b['count'] - $a['count']);
            usort($tracking_stats, fn($a, $b) => $b['count'] - $a['count']);
            $tracking_stats = array_slice($tracking_stats, 0, 10);
            sort($sheet_suppliers);
            sort($sheet_pr_numbers);
        }
    }
} catch (Exception $e) {
}

$tracking_table_exists = $tracking_total > 0;

$all_end_users = [
    'CBSS-PCD',
    'CPS-OANS',
    'CRS-VSD',
    'CTCO-CBSS',
    'EIAD-MAS',
    'ESSS-CSD',
    'ESSS-FSD',
    'ESSS-ISD',
    'ESSS-LPSD',
    'ESSS-PSD',
    'ESSS-SSD',
    'ESSS-TSD',
    'FAS-BD',
    'FAS-CSD',
    'FAS-HRD',
    'FMCMS-FGD',
    'ITDS-KMCD',
    'ITDS-SOID',
    'MAS-EIAD',
    'MAS-ENRAD',
    'MAS-PAD',
    'MAS-SEAD',
    'NCS-AFCD',
    'NCS-CPCD',
    'NCS-PHCD',
    'NCS-SICD',
    'ONS-CAD',
    'ONS-FMD',
    'ONS-ICU',
    'ONS-PMS',
    'PMS-MED',
    'PMS-PPCD',
    'PRO',
    'PRO-UCDMS',
    'SISS-ISMD',
    'SS-SCD',
    'SS-SSD',
    'SSSS-DHSD',
    'SSSS-IESD',
    'SSSS-LDRSSD',
    'SSSS-LSSD',
    'SSSS-PHDSD',
    'SSSS-SDSD'
];
$db_end_users = array_column($end_user_stats, 'end_user');
foreach ($db_end_users as $dbu) {
    if ($dbu !== '' && !in_array($dbu, $all_end_users))
        $all_end_users[] = $dbu;
}
sort($all_end_users);

// ===== BUILD ALL CATEGORIES LIST FOR FILTER DROPDOWN =====
$all_categories = array_map(fn($c) => $c['category'], $category_stats);
sort($all_categories);
?>
<!DOCTYPE html>
<html>

<head>
    <title>PR Monitoring - Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <link href="dashboard-styles.css" rel="stylesheet">
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
        }

        .chart-container canvas {
            display: block;
            max-width: 100%;
            height: auto !important;
        }

        /* ── Unified metrics grid ── */
        .metrics-unified {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        @media(max-width:1100px) {
            .metrics-unified {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width:580px) {
            .metrics-unified {
                grid-template-columns: 1fr;
            }
        }

        /* ── Live clock ── */
        .header-datetime {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            line-height: 1.3;
        }

        .header-datetime .clock-time {
            font-size: 1.15rem;
            font-weight: 700;
            color: #e2e8f0;
            letter-spacing: .04em;
            font-variant-numeric: tabular-nums;
        }

        .header-datetime .clock-date {
            font-size: .72rem;
            color: rgba(255, 255, 255, .45);
            white-space: nowrap;
        }

        /* ── 3-column charts row ── */
        .charts-grid-3col {
            display: grid;
            grid-template-columns: 1fr 240px 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }

        @media(max-width:1100px) {
            .charts-grid-3col {
                grid-template-columns: 1fr 1fr;
            }

            .app-stats-card {
                grid-column: span 2;
            }
        }

        @media(max-width:700px) {
            .charts-grid-3col {
                grid-template-columns: 1fr;
            }

            .app-stats-card {
                grid-column: span 1;
            }
        }

        /* ── APP Stats card ── */
        .app-stats-card {
            padding: 0 !important;
            background: #0b1d4e !important;
            border-color: rgba(255, 255, 255, .1) !important;
            overflow: hidden;
        }

        .app-stats-card .chart-header {
            padding: .75rem 1.1rem;
            margin-bottom: 0;
            border-bottom: 1px solid rgba(255, 255, 255, .1);
            background: rgba(0, 0, 0, .22);
            justify-content: space-between !important;
        }

        .app-stats-card .chart-title {
            color: #90caf9 !important;
            font-size: .88rem;
        }

        .app-stats-card .chart-title i {
            color: #64b5f6 !important;
        }

        .app-live-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #4ade80;
            flex-shrink: 0;
            box-shadow: 0 0 8px #4ade80;
            animation: appPulse 2s infinite;
        }

        @keyframes appPulse {

            0%,
            100% {
                opacity: 1;
                box-shadow: 0 0 8px #4ade80
            }

            50% {
                opacity: .4;
                box-shadow: 0 0 3px #4ade80
            }
        }

        .app-stats-body {
            display: flex;
            flex-direction: column;
        }

        .app-tile {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: .9rem 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, .07);
            transition: filter .2s;
            cursor: default;
        }

        .app-tile:hover {
            filter: brightness(1.13);
        }

        .app-tile:last-child {
            border-bottom: none;
        }

        .app-tile-1 {
            background: #0d2a6e;
        }

        .app-tile-2 {
            background: #1155b8;
        }

        .app-tile-3 {
            background: #1976c8;
        }

        .app-tile-4 {
            background: #29a8d8;
        }

        .app-tile-label {
            font-size: .6rem;
            color: rgba(255, 255, 255, .6);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: .28rem;
        }

        .app-tile-value {
            font-size: 1.45rem;
            font-weight: 900;
            color: #fff;
            font-family: 'Barlow Condensed', 'Nunito', sans-serif;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .app-tile-value.big {
            font-size: 1.8rem;
        }

        .app-stats-footer {
            font-size: .6rem;
            color: rgba(255, 255, 255, .35);
            padding: .45rem 1.1rem;
            display: flex;
            align-items: center;
            gap: .4rem;
            background: rgba(0, 0, 0, .28);
            border-top: 1px solid rgba(255, 255, 255, .06);
        }
    </style>
</head>

<body>
    <div class="dashboard-wrapper">

        <?php include 'sidebar.php'; ?>

        <!-- MAIN CONTENT -->
        <main class="dashboard-main">

            <!-- DASHBOARD HEADER -->
            <div class="dashboard-header-section">
                <div class="header-title-area">
                    <h1 class="dashboard-title">2026 Procurement Monitoring</h1>
                    <p class="dashboard-subtitle">Purchase Request Tracking & Analytics</p>
                </div>
                <div class="header-actions" style="display:flex; align-items:center; gap:0.75rem;">
                    <!-- Live Date & Time -->
                    <div class="header-datetime">
                        <span class="clock-time" id="liveClock">--:--:--</span>
                        <span class="clock-date" id="liveDate">--- --, ----</span>
                    </div>
                    <!-- Theme Toggle -->
                    <button class="theme-toggle" title="Toggle dark/light mode" style="
                        background: rgba(255,255,255,0.1);
                        border: 1px solid rgba(255,255,255,0.2);
                        border-radius: 8px;
                        color: #e2e8f0;
                        width: 36px; height: 36px;
                        display: flex; align-items: center; justify-content: center;
                        cursor: pointer; font-size: 1rem;
                        transition: all 0.25s ease;
                        flex-shrink: 0;
                    " onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        <i class="bi bi-sun"></i>
                    </button>
                    <!-- Profile Avatar Button -->
                    <a href="profile.php" title="View Profile" style="
                        display:inline-flex; align-items:center; justify-content:center;
                        width:36px; height:36px; border-radius:50%;
                        background:linear-gradient(135deg,#06b6d4,#3b82f6);
                        color:#fff; font-size:0.85rem; font-weight:800;
                        text-decoration:none; flex-shrink:0;
                        box-shadow:0 2px 8px rgba(59,130,246,0.35);
                        border:2px solid rgba(255,255,255,0.25);
                        transition:all 0.2s; position:relative;
                    " onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 4px 14px rgba(59,130,246,0.5)'"
                        onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 2px 8px rgba(59,130,246,0.35)'">
                        <?php echo strtoupper(substr($_SESSION['pms_user'] ?? 'U', 0, 1)); ?>
                    </a>
                </div>
            </div>

            <!-- ACTIVE FILTERS BADGE -->
            <?php
            $active_filters = array_filter([
                'Processor' => $f_processor,
                'End User' => $f_end_user,
                'Supplier' => $f_supplier,
                'PR Number' => $f_pr_number,
                'Category' => $f_category,
            ]);
            if (!empty($active_filters)): ?>
                <div style="display:flex; align-items:center; flex-wrap:wrap; gap:0.5rem;
                background:rgba(37,99,235,0.12); border:1px solid rgba(37,99,235,0.3);
                border-radius:8px; padding:0.6rem 1rem; margin-bottom:0.5rem; font-size:0.82rem;">
                    <span style="color:#93c5fd; font-weight:600; margin-right:0.25rem;">
                        <i class="bi bi-funnel-fill"></i> Filtered by:
                    </span>
                    <?php foreach ($active_filters as $label => $val): ?>
                        <span style="background:rgba(37,99,235,0.25); border:1px solid rgba(37,99,235,0.4);
                        border-radius:20px; padding:0.2rem 0.7rem; color:#bfdbfe; font-weight:500;">
                            <?= htmlspecialchars($label) ?>: <strong><?= htmlspecialchars($val) ?></strong>
                        </span>
                    <?php endforeach; ?>
                    <a href="index.php" style="margin-left:auto; color:#f87171; font-size:0.78rem;
                    text-decoration:none; display:flex; align-items:center; gap:0.25rem;">
                        <i class="bi bi-x-circle"></i> Clear all
                    </a>
                </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════
             UNIFIED METRICS GRID  —  2 rows × 4 cards
             Row 1 : Total PR | On-going | Paid | Cancelled
             Row 2 : Overdue  | Posted   | Canvas | Category
             ═══════════════════════════════════════════ -->
            <div class="metrics-unified">

                <!-- ── Row 1 ── -->
                <div class="metric-card metric-primary" onclick="window.location.href='table.php'"
                    style="cursor:pointer;">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-file-earmark-text"
                                style="margin-right:0.3rem;"></i>No. of PR</span>
                    </div>
                    <div class="metric-value"><?= $total_prs ?></div>
                    <div class="metric-label-sub">Purchase Requests</div>
                </div>

                <div class="metric-card metric-accent-yellow" onclick="window.location.href='table.php?bucket=pending'"
                    style="cursor:pointer;">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-hourglass-split"
                                style="margin-right:0.3rem;"></i>On-going</span>
                    </div>
                    <div class="metric-value"><?= $no_payment ?></div>
                    <div class="metric-label-sub">In Progress</div>
                </div>

                <div class="metric-card metric-accent-green" onclick="window.location.href='table.php?bucket=paid'"
                    style="cursor:pointer;">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-check-circle"
                                style="margin-right:0.3rem;"></i>Paid</span>
                    </div>
                    <div class="metric-value"><?= $paid_prs ?></div>
                    <div class="metric-label-sub">Completed</div>
                </div>

                <div class="metric-card metric-secondary" onclick="window.location.href='table.php?bucket=cancelled'"
                    style="cursor:pointer;">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-x-circle"
                                style="margin-right:0.3rem;"></i>Cancelled</span>
                    </div>
                    <div class="metric-value"><?= $cancelled_prs ?></div>
                    <div class="metric-label-sub">Cancelled</div>
                </div>

                <!-- ── Row 2 ── -->
                <div class="metric-card" onclick="window.location.href='table.php?bucket=overdue'"
                    style="cursor:pointer; background:linear-gradient(135deg,#dc2626 0%,#991b1b 100%);">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-exclamation-triangle-fill"
                                style="margin-right:0.3rem;"></i>Overdue</span>
                    </div>
                    <div class="metric-value"><?= $overdue_count ?></div>
                    <div class="metric-label-sub">&gt; 3 Days Pending</div>
                </div>

                <div class="metric-card metric-posted">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-megaphone"
                                style="margin-right:0.3rem;"></i>Posted</span>
                    </div>
                    <div class="metric-value"><?= $posted_canvas_stats['posted_count'] ?? 0 ?></div>
                    <div class="metric-label-sub">Total Posted</div>
                </div>

                <div class="metric-card metric-canvas">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-clipboard-check"
                                style="margin-right:0.3rem;"></i>Canvas</span>
                    </div>
                    <div class="metric-value"><?= $posted_canvas_stats['canvas_count'] ?? 0 ?></div>
                    <div class="metric-label-sub">Total Canvas</div>
                </div>

                <div class="metric-card metric-category">
                    <div class="metric-header">
                        <span class="metric-title"><i class="bi bi-tags"
                                style="margin-right:0.3rem;"></i>Category</span>
                    </div>
                    <div class="metric-value"><?= count($category_stats) ?></div>
                    <div class="metric-label-sub">Total Categories</div>
                </div>

            </div>
            <!-- ═══ END METRICS GRID ═══ -->

            <?php if ($tracking_table_exists): ?>
                <div id="syncTrackingAlert" style="display:none;" class="alert alert-dismissible fade show" role="alert">
                    <span id="syncTrackingMessage"></span>
                    <button type="button" class="btn-close" onclick="this.parentElement.style.display='none'"
                        aria-label="Close"></button>
                </div>

                <div class="charts-grid-3col">

                    <!-- ① Category Distribution -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title"><i class="bi bi-tags"></i> Category Distribution</div>
                        </div>
                        <div class="chart-container"><canvas id="categoryChart"></canvas></div>
                    </div>

                    <!-- ② APP Stats -->
                    <div class="chart-card app-stats-card">
                        <div class="chart-header">
                            <div class="chart-title"><i class="bi bi-graph-up-arrow"></i> APP Stats</div>
                            <span class="app-live-dot" title="Live · updates every 60s"></span>
                        </div>
                        <div class="app-stats-body">
                            <div class="app-tile app-tile-1">
                                <div class="app-tile-label">Total Amount per APP</div>
                                <div class="app-tile-value" id="appTotalAmount">
                                    <?= $app_stats['loaded']
                                        ? number_format($app_stats['total_amount'], 2)
                                        : '<span style="font-size:.78rem;opacity:.5;font-style:italic">Loading…</span>' ?>
                                </div>
                            </div>
                            <div class="app-tile app-tile-2">
                                <div class="app-tile-label">ABC</div>
                                <div class="app-tile-value" id="appABC">
                                    <?= $app_stats['loaded']
                                        ? number_format($app_stats['abc'], 2)
                                        : '<span style="font-size:.78rem;opacity:.5;font-style:italic">Loading…</span>' ?>
                                </div>
                            </div>
                            <div class="app-tile app-tile-3">
                                <div class="app-tile-label">Savings</div>
                                <div class="app-tile-value" id="appSavings">
                                    <?= $app_stats['loaded']
                                        ? number_format($app_stats['savings'], 2)
                                        : '<span style="font-size:.78rem;opacity:.5;font-style:italic">Loading…</span>' ?>
                                </div>
                            </div>
                            <div class="app-tile app-tile-4">
                                <div class="app-tile-label">Total Number of Items per APP</div>
                                <div class="app-tile-value big" id="appTotalItems">
                                    <?= $app_stats['loaded']
                                        ? number_format($app_stats['total_items'])
                                        : '<span style="font-size:.78rem;opacity:.5;font-style:italic">Loading…</span>' ?>
                                </div>
                            </div>
                        </div>
                        <div class="app-stats-footer">
                            <i class="bi bi-arrow-clockwise"></i>
                            <span id="appStatsTimestamp">Live · Google Sheets</span>
                        </div>
                    </div>

                    <!-- ③ PR Count Overview -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title"><i class="bi bi-bar-chart-line"></i> PR Count Overview</div>
                        </div>
                        <div class="chart-container"><canvas id="prCountOverviewChart"></canvas></div>
                    </div>

                </div>
            <?php endif; ?>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        if (window.Chart && window.ChartDataLabels) Chart.register(ChartDataLabels);

        let categoryChart, trackingEndUserChart;

        <?php if ($tracking_table_exists): ?>
            const categoryCtx = document.getElementById('categoryChart').getContext('2d');
            categoryChart = new Chart(categoryCtx, {
                type: 'bar',
                data: {
                    labels: [<?php echo implode(',', array_map(function ($c) {
                        return "'" . htmlspecialchars(str_replace(['"', "'"], '', $c['category'])) . "'";
                    }, $category_stats)); ?>],
                    datasets: [{
                        label: 'Number of PRs',
                        data: [<?php echo implode(',', array_map(fn($c) => $c['count'], $category_stats)); ?>],
                        backgroundColor: ['#06b6d4', '#8b5cf6', '#f97316', '#14b8a6', '#ec4899', '#eab308', '#22c55e', '#ef4444'],
                        borderColor: '#e5e7eb', borderWidth: 1, borderRadius: 6
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            color: '#fff', backgroundColor: 'rgba(31,41,55,0.8)',
                            borderRadius: 4, padding: 4,
                            anchor: ctx => ctx.dataset.data[ctx.dataIndex] >= Math.max(...ctx.dataset.data) * 0.6 ? 'center' : 'end',
                            align: ctx => ctx.dataset.data[ctx.dataIndex] >= Math.max(...ctx.dataset.data) * 0.6 ? 'center' : 'end',
                            font: { weight: 'bold', size: 10 },
                            formatter: v => v.toLocaleString()
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#e5e7eb' }, ticks: { color: '#6b7280', stepSize: 5 } },
                        x: { grid: { display: false }, ticks: { color: '#6b7280', autoSkip: false, maxRotation: 45, minRotation: 45 } }
                    }
                }
            });

            const prCountOverviewCtx = document.getElementById('prCountOverviewChart').getContext('2d');
            trackingEndUserChart = new Chart(prCountOverviewCtx, {
                type: 'bar',
                data: {
                    labels: ['Total', 'Overdue', 'Pending', 'Paid', 'Cancelled'],
                    datasets: [{
                        label: 'Count',
                        data: [<?= $total_prs ?>, <?= $overdue_count ?>, <?= $no_payment ?>, <?= $paid_prs ?>, <?= $cancelled_prs ?>],
                        backgroundColor: ['#2563eb', '#ef4444', '#f59e0b', '#10b981', '#dc2626'],
                        borderColor: ['#1d4ed8', '#dc2626', '#d97706', '#059669', '#991b1b'],
                        borderWidth: 1, borderRadius: 6
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: { duration: 750 },
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            color: '#fff', backgroundColor: 'rgba(31,41,55,0.8)',
                            borderRadius: 4, padding: { top: 4, bottom: 4, left: 6, right: 6 },
                            anchor: 'end', align: 'end', font: { weight: 'bold', size: 11 },
                            formatter: v => v.toLocaleString()
                        }
                    },
                    onClick: (evt, elements) => {
                        if (!elements?.length) return;
                        const map = { 0: null, 1: 'overdue', 2: 'pending', 3: 'paid', 4: 'cancelled' };
                        const bucket = map[elements[0].index];
                        window.location.href = bucket ? 'table.php?bucket=' + bucket : 'table.php';
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#e5e7eb' }, ticks: { color: '#6b7280', stepSize: 10 } },
                        x: { grid: { display: false }, ticks: { color: '#6b7280' } }
                    }
                }
            });
        <?php endif; ?>

            // ===== LIVE CLOCK =====
            (function () {
                const clockEl = document.getElementById('liveClock');
                const dateEl = document.getElementById('liveDate');
                const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

                function tick() {
                    const now = new Date();
                    // Time: HH:MM:SS AM/PM
                    let h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
                    const ampm = h >= 12 ? 'PM' : 'AM';
                    h = h % 12 || 12;
                    clockEl.textContent =
                        String(h).padStart(2, '0') + ':' +
                        String(m).padStart(2, '0') + ':' +
                        String(s).padStart(2, '0') + ' ' + ampm;
                    // Date: Day, Mon DD YYYY
                    dateEl.textContent =
                        days[now.getDay()] + ', ' +
                        months[now.getMonth()] + ' ' +
                        now.getDate() + ' ' +
                        now.getFullYear();
                }
                tick();
                setInterval(tick, 1000);
            })();

        // Sync

        // ===== APP STATS LIVE REFRESH (via PHP proxy) =====
        (function () {
            const fmt = (n, d = 2) => n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
            const set = (id, html) => { const el = document.getElementById(id); if (el) el.innerHTML = html; };

            function load() {
                fetch('get_app_stats.php?t=' + Date.now())
                    .then(r => r.json())
                    .then(d => {
                        if (!d.success) return;
                        set('appTotalAmount', fmt(d.total_amount));
                        set('appABC', fmt(d.abc));
                        set('appSavings', fmt(d.savings));
                        set('appTotalItems', d.total_items.toLocaleString('en-US'));
                        const ts = document.getElementById('appStatsTimestamp');
                        if (ts) { const n = new Date(); ts.textContent = 'Updated ' + n.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
                    }).catch(() => { });
            }
            load();
            setInterval(load, 60000);
        })();

        // Sync original
        function syncTrackingData() {
            const btn = document.getElementById('syncTrackingBtn');
            const alertEl = document.getElementById('syncTrackingAlert');
            const message = document.getElementById('syncTrackingMessage');
            btn.disabled = true; btn.classList.add('syncing');
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Syncing...';
            if (alertEl) alertEl.style.display = 'none';

            fetch('sync_action.php?sync_type=pr_status_tracking')
                .then(r => r.json())
                .then(data => {
                    if (!alertEl) return;
                    alertEl.className = 'alert alert-dismissible fade show';
                    if (data.success) {
                        alertEl.style.background = 'rgba(34,197,94,0.15)';
                        alertEl.style.borderColor = 'rgba(34,197,94,0.3)';
                        alertEl.style.color = '#22c55e';
                        message.innerHTML = '<strong>Success!</strong> ' + data.message;
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        alertEl.style.background = 'rgba(239,68,68,0.15)';
                        alertEl.style.borderColor = 'rgba(239,68,68,0.3)';
                        alertEl.style.color = '#ef4444';
                        message.innerHTML = '<strong>Error:</strong> ' + data.message;
                    }
                    alertEl.style.display = 'block';
                })
                .catch(err => { if (message) message.innerHTML = '<strong>Sync error:</strong> ' + err.message; })
                .finally(() => {
                    btn.disabled = false; btn.classList.remove('syncing');
                    btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sync Tracking Data';
                });
        }

        // Chart resize
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                if (categoryChart) categoryChart.resize();
                if (trackingEndUserChart) trackingEndUserChart.resize();
            }, 250);
        });
        window.addEventListener('load', () => {
            setTimeout(() => {
                if (categoryChart) categoryChart.resize();
                if (trackingEndUserChart) trackingEndUserChart.resize();
            }, 100);
        });

        // ===== AUTO-SYNC EVERY 5 MINUTES =====
        const AUTO_SYNC_INTERVAL = 5 * 60 * 1000;
        let autoSyncSecs = AUTO_SYNC_INTERVAL / 1000;
        let autoSyncTimer = null, countdownTimer = null;

        (function () {
            const indicator = document.createElement('div');
            indicator.id = 'autoSyncIndicator';
            indicator.style.cssText = `
            position:fixed; bottom:1.25rem; right:1.25rem; z-index:9999;
            background:rgba(0,0,0,0.55); backdrop-filter:blur(6px);
            border:1px solid rgba(255,255,255,0.12); border-radius:8px;
            padding:0.45rem 0.85rem; font-size:0.75rem; color:#a1a1aa;
            display:flex; align-items:center; gap:0.5rem;
            transition:opacity 0.3s ease; cursor:default; user-select:none;`;
            indicator.innerHTML = `
            <span id="autoSyncDot" style="width:7px;height:7px;border-radius:50%;background:#06b6d4;display:inline-block;"></span>
            <span id="autoSyncLabel">Next sync in <strong id="autoSyncCountdown">5:00</strong></span>`;
            document.body.appendChild(indicator);
        })();

        const formatCountdown = s => `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, '0')}`;

        function runAutoSync() {
            const dot = document.getElementById('autoSyncDot');
            const label = document.getElementById('autoSyncLabel');
            if (dot) dot.style.background = '#fbbf24';
            if (label) label.innerHTML = '<em>Syncing…</em>';
            Promise.allSettled([
                fetch('sync_action.php').then(r => r.json()),
                fetch('sync_action.php?sync_type=pr_status_tracking').then(r => r.json())
            ]).then(results => {
                const allOk = results.every(r => r.status === 'fulfilled' && r.value?.success);
                if (dot) dot.style.background = allOk ? '#22c55e' : '#ef4444';
                if (label) label.textContent = allOk ? 'Synced just now' : 'Sync failed';
                setTimeout(() => location.reload(), 1200);
            });
        }

        function startCountdown() {
            autoSyncSecs = AUTO_SYNC_INTERVAL / 1000;
            clearInterval(countdownTimer);
            countdownTimer = setInterval(() => {
                autoSyncSecs--;
                const el = document.getElementById('autoSyncCountdown');
                if (el) el.textContent = formatCountdown(autoSyncSecs);
                if (autoSyncSecs <= 0) clearInterval(countdownTimer);
            }, 1000);
        }
        startCountdown();
        autoSyncTimer = setInterval(runAutoSync, AUTO_SYNC_INTERVAL);

        // ===== THEME TOGGLE =====
        (function () {
            const body = document.body;
            const saved = localStorage.getItem('pr_tracker_theme') || 'dark';
            body.classList.remove('dark-theme', 'light-theme');
            body.classList.add(saved === 'light' ? 'light-theme' : 'dark-theme');

            const toggle = document.querySelector('.theme-toggle');
            if (!toggle) return;
            const icon = toggle.querySelector('i');

            const applyIcon = () => {
                const isLight = body.classList.contains('light-theme');
                if (icon) icon.className = isLight ? 'bi bi-moon-fill' : 'bi bi-sun-fill';
                toggle.title = isLight ? 'Switch to dark mode' : 'Switch to light mode';
            };
            applyIcon();

            toggle.addEventListener('click', e => {
                e.preventDefault();
                const goLight = body.classList.contains('dark-theme');
                body.classList.remove('dark-theme', 'light-theme');
                body.classList.add(goLight ? 'light-theme' : 'dark-theme');
                localStorage.setItem('pr_tracker_theme', goLight ? 'light' : 'dark');
                applyIcon();
            });
        })();

        // ===== FILTERS =====
        function applyFilters() {
            const params = [];
            const p = document.getElementById('filterProcessor')?.value || '';
            const e = document.getElementById('filterEndUser')?.value || '';
            const s = document.getElementById('filterSupplier')?.value || '';
            const n = document.getElementById('filterPRNumber')?.value || '';
            const c = document.getElementById('filterCategory')?.value || '';
            if (p) params.push('processor=' + encodeURIComponent(p));
            if (e) params.push('end_user=' + encodeURIComponent(e));
            if (s) params.push('supplier=' + encodeURIComponent(s));
            if (n) params.push('pr_number=' + encodeURIComponent(n));
            if (c) params.push('category=' + encodeURIComponent(c));
            window.location.href = 'index.php' + (params.length ? '?' + params.join('&') : '');
        }

        function resetFilters() { window.location.href = 'index.php'; }
    </script>
</body>

</html>