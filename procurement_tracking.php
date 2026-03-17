<?php
// ═══════════════════════════════════════════════════════════════
//  procurement_tracking.php  —  PSA Procurement Timeline Viewer
//  Reads from MySQL (synced from Google Sheets)
//  AUTO-CREATES the table on first load if it doesn't exist
// ═══════════════════════════════════════════════════════════════

require 'config/database.php';

// ── AUTO-CREATE TABLE (safe: IF NOT EXISTS, runs in <1ms after first time) ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `procurement_tracking` (
            `id`                      INT AUTO_INCREMENT PRIMARY KEY,
            `pr_number`               VARCHAR(100) NOT NULL,
            `particulars`             TEXT         DEFAULT NULL,
            `end_user`                VARCHAR(255) DEFAULT NULL,
            `mode_of_procurement`     VARCHAR(255) DEFAULT NULL,
            `receipt_date_event`      VARCHAR(100) DEFAULT NULL,
            `receipt_date_received`   VARCHAR(100) DEFAULT NULL,
            `receipt_expected`        VARCHAR(100) DEFAULT NULL,
            `receipt_days_delayed`    VARCHAR(50)  DEFAULT NULL,
            `proc_pr_numbering`       VARCHAR(100) DEFAULT NULL,
            `proc_date_endorsed`      VARCHAR(100) DEFAULT NULL,
            `proc_date_earmarked`     VARCHAR(100) DEFAULT NULL,
            `proc_duration`           VARCHAR(50)  DEFAULT NULL,
            `proc_days_delayed`       VARCHAR(50)  DEFAULT NULL,
            `rfq_preparation`         VARCHAR(100) DEFAULT NULL,
            `rfq_approved`            VARCHAR(100) DEFAULT NULL,
            `rfq_duration`            VARCHAR(50)  DEFAULT NULL,
            `rfq_days_delayed`        VARCHAR(50)  DEFAULT NULL,
            `apq_date_posting`        VARCHAR(100) DEFAULT NULL,
            `apq_preparation`         VARCHAR(100) DEFAULT NULL,
            `apq_return_date`         VARCHAR(100) DEFAULT NULL,
            `apq_approved`            VARCHAR(100) DEFAULT NULL,
            `apq_duration`            VARCHAR(50)  DEFAULT NULL,
            `apq_days_delayed`        VARCHAR(50)  DEFAULT NULL,
            `noa_preparation`         VARCHAR(100) DEFAULT NULL,
            `noa_approved`            VARCHAR(100) DEFAULT NULL,
            `noa_date_acknowledged`   VARCHAR(100) DEFAULT NULL,
            `noa_duration`            VARCHAR(50)  DEFAULT NULL,
            `noa_days_delayed`        VARCHAR(50)  DEFAULT NULL,
            `po_issuance_ors`         VARCHAR(100) DEFAULT NULL,
            `po_smd_to_bd`            VARCHAR(100) DEFAULT NULL,
            `po_for_caf`              VARCHAR(100) DEFAULT NULL,
            `po_cafed`                VARCHAR(100) DEFAULT NULL,
            `po_routed_hope`          VARCHAR(100) DEFAULT NULL,
            `po_approved`             VARCHAR(100) DEFAULT NULL,
            `po_date_acknowledged`    VARCHAR(100) DEFAULT NULL,
            `po_duration`             VARCHAR(50)  DEFAULT NULL,
            `po_days_delayed`         VARCHAR(50)  DEFAULT NULL,
            `total_duration`          VARCHAR(50)  DEFAULT NULL,
            `total_days_delayed`      VARCHAR(50)  DEFAULT NULL,
            `synced_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                   ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_pr_number`  (`pr_number`),
            INDEX      `idx_end_user`  (`end_user`(100)),
            INDEX      `idx_total_days`(`total_days_delayed`(20))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    http_response_code(500);
    die('<div style="font-family:sans-serif;padding:2rem;color:#7f0000;background:#fee2e2;border-radius:8px;margin:2rem;">
        <strong>Database setup error:</strong> ' . htmlspecialchars($e->getMessage()) . '
        <br><br>Check your <code>config/database.php</code> credentials.
    </div>');
}

$rows = [];
$fetch_error = null;
$fetch_time = null;

try {
    $stmt = $pdo->query("
        SELECT 
            pr_number, particulars, end_user, mode_of_procurement,
            receipt_date_event, receipt_date_received, receipt_expected, receipt_days_delayed,
            proc_pr_numbering, proc_date_endorsed, proc_date_earmarked, proc_duration, proc_days_delayed,
            rfq_preparation, rfq_approved, rfq_duration, rfq_days_delayed,
            apq_date_posting, apq_preparation, apq_return_date, apq_approved, apq_duration, apq_days_delayed,
            noa_preparation, noa_approved, noa_date_acknowledged, noa_duration, noa_days_delayed,
            po_issuance_ors, po_smd_to_bd, po_for_caf, po_cafed, po_routed_hope, po_approved, 
            po_date_acknowledged, po_duration, po_days_delayed, total_duration, total_days_delayed
        FROM procurement_tracking
        ORDER BY pr_number ASC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $rows[] = $row;
    }
} catch (Exception $e) {
    $fetch_error = 'Database error: ' . $e->getMessage();
}

// ── Summary stats ────────────────────────────────────────────────
$total_records = count($rows);
$delayed_count = 0;
$on_time_count = 0;
$incomplete_count = 0;
foreach ($rows as $r) {
    $td = trim($r[38] ?? '');
    if ($td === '' || strtoupper($td) === 'N/A')
        $incomplete_count++;
    elseif ((float) $td > 0)
        $delayed_count++;
    else
        $on_time_count++;
}

// ── Search ───────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $rows = array_values(array_filter(
        $rows,
        fn($r) =>
        stripos($r[0] ?? '', $search) !== false ||
        stripos($r[1] ?? '', $search) !== false ||
        stripos($r[2] ?? '', $search) !== false
    ));
}

// ── Pagination ───────────────────────────────────────────────────
$per_page_raw = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 15;
$per_page = in_array($per_page_raw, [10, 15, 25, 50, 100]) ? $per_page_raw : 15;
$total = count($rows);
$total_pages = ($per_page > 0) ? max(1, (int) ceil($total / $per_page)) : 1;
$page = max(1, min((int) ($_GET['page'] ?? 1), $total_pages));
$offset = ($page - 1) * $per_page;
$paged = array_slice($rows, $offset, $per_page);

// ── Helpers ──────────────────────────────────────────────────────
function pUrl(array $extra = []): string
{
    return '?' . http_build_query(array_merge($_GET, $extra));
}

function dCell(string $v): string
{
    $v = trim($v);
    if ($v === '' || strtoupper($v) === 'N/A')
        return '<td class="c-na">–</td>';
    $n = (float) $v;
    if ($n < 0)
        return '<td class="c-early">' . htmlspecialchars($v) . '</td>';
    if ($n === 0.0)
        return '<td class="c-ok">' . htmlspecialchars($v) . '</td>';
    return '<td class="c-late">' . htmlspecialchars($v) . '</td>';
}

function dtCell(string $v): string
{
    $v = trim($v);
    if ($v === '')
        return '<td class="c-empty">—</td>';
    return '<td class="c-date">' . htmlspecialchars($v) . '</td>';
}

function pCell(string $v, string $cls = ''): string
{
    $v = trim($v);
    if ($v === '')
        return '<td class="c-empty">—</td>';
    return '<td' . ($cls ? " class=\"$cls\"" : '') . '>' . htmlspecialchars($v) . '</td>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Procurement Processing Time — PSA</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Barlow+Condensed:wght@500;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="dashboard-styles.css" rel="stylesheet">
    <style>
        /* ── Layout ── */
        .pt-main {
            margin-left: 250px;
            flex: 1;
            padding: 1.25rem 1.25rem 2rem;
            width: calc(100% - 250px);
            min-width: 0;
            box-sizing: border-box;
        }

        @media (max-width: 768px) {
            .pt-main {
                margin-left: 220px;
                padding: 1rem;
                width: calc(100% - 220px);
            }
        }

        @media (max-width: 480px) {
            .pt-main {
                margin-left: 0;
                width: 100%;
                padding: .75rem;
            }
        }

        /* ── Stat cards ── */
        .pt-stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 1100px) {
            .pt-stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 560px) {
            .pt-stats-row {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* ── Clock ── */
        .pt-clock-time {
            color: #e2e8f0;
        }

        .pt-clock-date {
            color: rgba(255, 255, 255, .45);
        }

        body.light-theme .pt-clock-time {
            color: #1f2937 !important;
        }

        body.light-theme .pt-clock-date {
            color: #6b7280 !important;
        }

        /* ── Empty state banner ── */
        .pt-empty-banner {
            background: linear-gradient(135deg, #e8f4fd 0%, #dbeafe 100%);
            border: 1.5px solid #93c5fd;
            border-radius: 12px;
            padding: 2rem 1.5rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .pt-empty-banner i {
            font-size: 2.5rem;
            color: #2563eb;
            display: block;
            margin-bottom: .75rem;
        }

        .pt-empty-banner h3 {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: .5rem;
        }

        .pt-empty-banner p {
            font-size: .88rem;
            color: #374151;
            margin-bottom: 1.1rem;
        }

        /* ── Toolbar ── */
        .pt-toolbar {
            display: flex;
            gap: .65rem;
            margin-bottom: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .pt-search-wrap {
            position: relative;
            flex: 1;
            min-width: 220px;
            max-width: 360px;
        }

        .pt-search-wrap i {
            position: absolute;
            left: .75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: .85rem;
            pointer-events: none;
        }

        .pt-search-wrap input {
            width: 100%;
            padding: .55rem .75rem .55rem 2.15rem;
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            font-size: .85rem;
            color: var(--text-dark);
            background: #f0f7ff;
            font-family: 'Nunito', sans-serif;
            font-weight: 600;
            outline: none;
            transition: all .25s;
        }

        .pt-search-wrap input::placeholder {
            color: var(--text-muted);
            font-weight: 400;
        }

        .pt-search-wrap input:focus {
            border-color: var(--blue-mid);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(33, 150, 243, .15);
        }

        /* Shared button style */
        .pt-btn {
            padding: .55rem 1.1rem;
            border: none;
            border-radius: 8px;
            font-family: 'Nunito', sans-serif;
            font-size: .85rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            transition: all .25s;
            text-decoration: none;
            white-space: nowrap;
        }

        .pt-btn-primary {
            background: linear-gradient(135deg, var(--blue-mid) 0%, var(--blue-primary) 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(21, 101, 192, .25);
        }

        .pt-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(21, 101, 192, .35);
            color: #fff;
        }

        .pt-btn-success {
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(46, 125, 50, .25);
        }

        .pt-btn-success:hover {
            background: linear-gradient(135deg, #388e3c 0%, #2e7d32 100%) !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(46, 125, 50, .35);
            color: #fff !important;
        }

        .pt-btn-outline {
            background: #f0f7ff;
            color: var(--text-muted);
            border: 1.5px solid var(--border-light) !important;
        }

        .pt-btn-outline:hover {
            background: #fee2e2;
            border-color: #fca5a5 !important;
            color: #b91c1c;
        }

        /* Per-page select */
        .pt-per-page {
            padding: .55rem .75rem;
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            font-size: .85rem;
            font-weight: 600;
            color: var(--text-dark);
            background: #f0f7ff;
            font-family: 'Nunito', sans-serif;
            cursor: pointer;
            outline: none;
            transition: all .25s;
        }

        .pt-per-page:focus {
            border-color: var(--blue-mid);
            background: #fff;
        }

        .pt-rec-count {
            margin-left: auto;
            font-size: .8rem;
            color: var(--text-muted);
            font-weight: 700;
            white-space: nowrap;
        }

        /* ── Table card ── */
        .pt-table-card {
            background: var(--card-bg);
            border: 1.5px solid var(--border-light);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: box-shadow .25s;
        }

        .pt-table-card:hover {
            box-shadow: var(--shadow-md);
        }

        .pt-table-scroll {
            overflow-x: auto;
            max-height: calc(100vh - 220px);
            overflow-y: auto;
        }

        .pt-table-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .pt-table-scroll::-webkit-scrollbar-track {
            background: #e8f0fe;
            border-radius: 10px;
        }

        .pt-table-scroll::-webkit-scrollbar-thumb {
            background: #90caf9;
            border-radius: 10px;
        }

        .pt-table-scroll::-webkit-scrollbar-thumb:hover {
            background: #42a5f5;
        }

        /* ── Table ── */
        .pt-table {
            border-collapse: collapse;
            font-size: .72rem;
            min-width: 100%;
            width: max-content;
            font-family: 'Nunito', sans-serif;
        }

        .pt-table thead tr.gr th {
            position: sticky;
            top: 0;
            z-index: 3;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            padding: .5rem .6rem;
            text-align: center;
            color: #fff;
            white-space: nowrap;
            border-right: 1px solid rgba(255, 255, 255, .15);
            border-bottom: 2px solid rgba(0, 0, 0, .18);
        }

        .pt-table thead tr.cr th {
            position: sticky;
            top: 31px;
            z-index: 2;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: .67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: .4rem .6rem;
            color: #fff;
            opacity: .92;
            white-space: nowrap;
            border-right: 1px solid rgba(255, 255, 255, .15);
            border-bottom: 2px solid rgba(0, 0, 0, .2);
        }

        .gh-base {
            background: var(--blue-primary) !important;
            z-index: 5 !important;
        }

        .gh-receipt {
            background: var(--tile-green) !important;
        }

        .gh-proc {
            background: var(--tile-purple) !important;
        }

        .gh-rfq {
            background: #0277bd !important;
        }

        .gh-apq {
            background: var(--tile-orange) !important;
        }

        .gh-noa {
            background: var(--tile-teal) !important;
        }

        .gh-po {
            background: var(--tile-red) !important;
        }

        .gh-sum {
            background: var(--tile-grey) !important;
        }

        .ch-base {
            background: #1a3a6e !important;
            z-index: 4 !important;
        }

        .ch-receipt {
            background: #1b5e20 !important;
        }

        .ch-proc {
            background: #4a148c !important;
        }

        .ch-rfq {
            background: #01579b !important;
        }

        .ch-apq {
            background: #bf360c !important;
        }

        .ch-noa {
            background: #004d40 !important;
        }

        .ch-po {
            background: #7f0000 !important;
        }

        .ch-sum {
            background: #37474f !important;
        }

        .pt-table tbody td {
            padding: .4rem .6rem;
            border-right: 1px solid #e3f0ff;
            border-bottom: 1px solid #e3f0ff;
            color: var(--text-dark);
            vertical-align: middle;
            white-space: nowrap;
            text-align: center;
            transition: background .12s;
        }

        .pt-table tbody tr:hover td {
            background: #e8f4fd !important;
        }

        .pt-table tbody tr:nth-child(even) td {
            background: #f5f9ff;
        }

        .col-pr {
            position: sticky;
            left: 0;
            z-index: 1;
            background: #dbeafe !important;
            font-family: 'Barlow Condensed', sans-serif;
            font-weight: 700;
            font-size: .82rem;
            color: var(--blue-primary);
            border-right: 2px solid #93c5fd !important;
            white-space: nowrap;
            text-align: center;
        }

        .pt-table tbody tr:hover .col-pr {
            background: #bfdbfe !important;
        }

        .pt-table tbody tr:nth-child(even) .col-pr {
            background: #dbeafe !important;
        }

        .th-pr-sticky {
            position: sticky !important;
            left: 0 !important;
            z-index: 6 !important;
            border-right: 2px solid rgba(255, 255, 255, .3) !important;
        }

        .col-part {
            min-width: 220px;
            max-width: 280px;
            white-space: normal !important;
            line-height: 1.35;
            font-size: .75rem;
            font-weight: 600;
            text-align: left !important;
        }

        .c-na {
            color: #b0bec5;
            text-align: center;
            font-size: .72rem;
        }

        .c-empty {
            color: #cfd8dc;
            text-align: center;
        }

        .c-date {
            font-size: .74rem;
            color: var(--text-muted);
        }

        .c-num {
            text-align: right;
            color: var(--text-muted);
            font-weight: 700;
        }

        .c-ok {
            text-align: center;
            color: #1b5e20;
            font-weight: 700;
        }

        .c-late {
            text-align: center;
            color: #b71c1c;
            font-weight: 700;
        }

        .c-early {
            text-align: center;
            color: #01579b;
            font-weight: 700;
        }

        .c-dur-total {
            font-weight: 800;
            color: var(--blue-mid);
            text-align: right;
            background: #e3f0ff !important;
        }

        .c-sum-ok {
            text-align: center;
            color: #1b5e20;
            font-weight: 700;
        }

        .c-sum-late {
            text-align: center;
            color: #b71c1c;
            font-weight: 700;
        }

        .c-sum-pend {
            text-align: center;
            color: #b0bec5;
            font-size: .72rem;
        }

        /* ── Pagination bar ── */
        .pt-pag-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .9rem 1.3rem;
            border-top: 1.5px solid var(--border-light);
            background: #f5f9ff;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .pt-pag-info {
            font-size: .8rem;
            color: var(--text-muted);
            font-weight: 700;
            font-family: 'Nunito', sans-serif;
        }

        .pt-pag-links {
            display: flex;
            gap: .3rem;
            align-items: center;
        }

        .pt-pag-links a,
        .pt-pag-links span {
            padding: .3rem .65rem;
            border-radius: 6px;
            font-size: .78rem;
            font-weight: 800;
            border: 1.5px solid var(--border-light);
            text-decoration: none;
            color: var(--blue-mid);
            font-family: 'Nunito', sans-serif;
            transition: all .2s;
        }

        .pt-pag-links a:hover {
            background: #dbeafe;
            border-color: var(--blue-mid);
            transform: translateY(-1px);
        }

        .pt-pag-links span.on {
            background: linear-gradient(135deg, var(--blue-mid) 0%, var(--blue-primary) 100%);
            color: #fff;
            border-color: var(--blue-mid);
            box-shadow: 0 2px 8px rgba(21, 101, 192, .25);
        }

        .pt-pag-links span.off {
            color: #cfd8dc;
            cursor: default;
        }

        /* ── Error alert ── */
        .pt-err {
            background: rgba(198, 40, 40, .08);
            border: 1.5px solid rgba(198, 40, 40, .3);
            border-radius: 10px;
            padding: .9rem 1.2rem;
            color: #7f0000;
            font-weight: 700;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: .6rem;
            font-size: .88rem;
            font-family: 'Nunito', sans-serif;
        }

        .pt-empty-row td {
            text-align: center;
            padding: 4rem 1rem;
            color: var(--text-muted);
            font-family: 'Nunito', sans-serif;
            font-weight: 600;
        }

        .pt-empty-row .big-i {
            font-size: 2.5rem;
            display: block;
            margin-bottom: .75rem;
            opacity: .3;
        }

        /* ── Modal backdrop (shared) ── */
        .pt-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(3px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .pt-modal-backdrop.open {
            display: flex;
        }

        .pt-modal {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.28);
            width: 100%;
            max-width: 480px;
            padding: 2rem;
            font-family: 'Nunito', sans-serif;
            animation: ptModalIn .25s cubic-bezier(.34, 1.56, .64, 1) both;
        }

        @keyframes ptModalIn {
            from {
                opacity: 0;
                transform: scale(.92) translateY(20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .pt-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.4rem;
        }

        .pt-modal-title {
            font-size: 1.05rem;
            font-weight: 900;
            color: #1e3a5f;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .pt-modal-title i {
            font-size: 1.2rem;
        }

        .pt-modal-close {
            background: none;
            border: none;
            font-size: 1.3rem;
            color: #9ca3af;
            cursor: pointer;
            line-height: 1;
            padding: .2rem;
            border-radius: 6px;
            transition: all .2s;
        }

        .pt-modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        .pt-modal-actions {
            display: flex;
            gap: .65rem;
            margin-top: 1.4rem;
        }

        .pt-modal-btn-cancel {
            flex: 1;
            padding: .6rem;
            background: #f3f4f6;
            color: #6b7280;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-family: 'Nunito', sans-serif;
            font-weight: 800;
            font-size: .85rem;
            cursor: pointer;
            transition: all .2s;
        }

        .pt-modal-btn-cancel:hover {
            background: #e5e7eb;
            color: #374151;
        }

        .pt-modal-btn-submit {
            flex: 2;
            padding: .6rem;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: 'Nunito', sans-serif;
            font-weight: 800;
            font-size: .85rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            transition: all .25s;
            box-shadow: 0 4px 12px rgba(5, 150, 105, .25);
        }

        .pt-modal-btn-submit:hover {
            box-shadow: 0 6px 16px rgba(5, 150, 105, .35);
            transform: translateY(-1px);
        }

        .pt-modal-btn-submit:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        /* Upload modal specific */
        .pt-dropzone {
            border: 2.5px dashed #93c5fd;
            border-radius: 12px;
            padding: 2rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all .25s;
            background: #f0f7ff;
            position: relative;
        }

        .pt-dropzone:hover,
        .pt-dropzone.dragover {
            border-color: #2563eb;
            background: #dbeafe;
        }

        .pt-dropzone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .pt-dropzone-icon {
            font-size: 2.5rem;
            color: #3b82f6;
            margin-bottom: .6rem;
            display: block;
        }

        .pt-dropzone-label {
            font-size: .9rem;
            font-weight: 700;
            color: #1e3a5f;
            display: block;
            margin-bottom: .3rem;
        }

        .pt-dropzone-hint {
            font-size: .75rem;
            color: #6b7280;
            font-weight: 600;
        }

        .pt-file-selected {
            display: none;
            align-items: center;
            gap: .6rem;
            margin-top: 1rem;
            padding: .65rem .9rem;
            background: #dcfce7;
            border: 1.5px solid #86efac;
            border-radius: 8px;
            font-size: .82rem;
            font-weight: 700;
            color: #14532d;
        }

        .pt-file-selected.show {
            display: flex;
        }

        .pt-file-selected i {
            font-size: 1rem;
            color: #16a34a;
            flex-shrink: 0;
        }

        .pt-file-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pt-progress-wrap {
            display: none;
            margin-top: 1rem;
        }

        .pt-progress-wrap.show {
            display: block;
        }

        .pt-progress-label {
            font-size: .78rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .4rem;
            display: flex;
            justify-content: space-between;
        }

        .pt-progress-bar-bg {
            height: 8px;
            background: #e5e7eb;
            border-radius: 99px;
            overflow: hidden;
        }

        .pt-progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #059669, #10b981);
            border-radius: 99px;
            width: 0%;
            transition: width .3s ease;
        }

        @keyframes ptSpin {
            to {
                transform: rotate(360deg);
            }
        }

        .pt-spin {
            animation: ptSpin .8s linear infinite;
            display: inline-block;
        }

        /* ── ADD PR MODAL ── */
        .pt-add-modal {
            max-width: 860px !important;
            width: 96vw !important;
            max-height: 92vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            padding: 0 !important;
        }

        .pt-add-modal-head {
            padding: 1.4rem 1.75rem 1.1rem;
            border-bottom: 1.5px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .pt-add-modal-title {
            font-size: 1.05rem;
            font-weight: 900;
            color: #1e3a5f;
            display: flex;
            align-items: center;
            gap: .55rem;
        }

        .pt-add-modal-title i {
            color: #2e7d32;
            font-size: 1.2rem;
        }

        .pt-add-modal-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.4rem 1.75rem;
        }

        .pt-add-modal-body::-webkit-scrollbar {
            width: 5px;
        }

        .pt-add-modal-body::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .pt-add-modal-body::-webkit-scrollbar-thumb {
            background: #90caf9;
            border-radius: 10px;
        }

        .pt-add-modal-footer {
            padding: 1rem 1.75rem;
            border-top: 1.5px solid #e5e7eb;
            display: flex;
            gap: .75rem;
            align-items: center;
            flex-shrink: 0;
            background: #f8fafc;
        }

        /* Section dividers */
        .pt-add-section {
            margin-bottom: 1.25rem;
        }

        .pt-add-section-title {
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--blue-mid);
            border-bottom: 1.5px solid #e5e7eb;
            padding-bottom: .4rem;
            margin-bottom: .9rem;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        /* Form grid */
        .pt-add-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem 1rem;
        }

        .pt-add-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .75rem 1rem;
        }

        .pt-add-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: .75rem 1rem;
        }

        .pt-add-grid-5 {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: .75rem 1rem;
        }

        @media (max-width: 680px) {

            .pt-add-grid-4,
            .pt-add-grid-5 {
                grid-template-columns: 1fr 1fr;
            }

            .pt-add-grid-3 {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 440px) {

            .pt-add-grid-2,
            .pt-add-grid-3,
            .pt-add-grid-4,
            .pt-add-grid-5 {
                grid-template-columns: 1fr;
            }
        }

        /* Field */
        .pt-add-field {
            display: flex;
            flex-direction: column;
            gap: .22rem;
        }

        .pt-add-label {
            font-size: .65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--text-muted);
        }

        .pt-add-input {
            padding: .45rem .65rem;
            border: 1.5px solid var(--border-light);
            border-radius: 7px;
            font-family: 'Nunito', sans-serif;
            font-size: .83rem;
            font-weight: 600;
            color: var(--text-dark);
            background: #f8faff;
            outline: none;
            transition: all .2s;
            width: 100%;
        }

        .pt-add-input:focus {
            border-color: var(--blue-mid);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(33, 150, 243, .12);
        }

        .pt-add-input.required-field {
            border-color: #93c5fd;
        }

        .pt-add-input.required-field:focus {
            border-color: #2563eb;
        }

        textarea.pt-add-input {
            resize: vertical;
            min-height: 56px;
        }

        /* Dark theme for add modal */
        body.dark-theme .pt-add-modal {
            background: #0f1f3d;
        }

        body.dark-theme .pt-add-modal-head {
            border-bottom-color: #1e3a5f;
        }

        body.dark-theme .pt-add-modal-title {
            color: #90caf9;
        }

        body.dark-theme .pt-add-modal-footer {
            background: #0d1b2a;
            border-top-color: #1e3a5f;
        }

        body.dark-theme .pt-add-section-title {
            color: #90caf9;
            border-bottom-color: #1e3a5f;
        }

        body.dark-theme .pt-add-label {
            color: #90caf9;
        }

        body.dark-theme .pt-add-input {
            background: #1e3a5f;
            border-color: #2a5080;
            color: #e8f0fe;
        }

        body.dark-theme .pt-add-input:focus {
            background: #254a70;
            border-color: #42a5f5;
        }

        body.dark-theme .pt-add-modal-body::-webkit-scrollbar-track {
            background: #0f1f3d;
        }

        body.dark-theme .pt-add-modal-body {
            background: #0f1f3d;
        }

        /* ── Detail View Modal ── */
        .pt-detail-modal {
            max-width: 820px !important;
            width: 95vw !important;
        }

        .pt-detail-body {
            padding: 1.25rem 1.5rem;
            overflow-y: auto;
            max-height: calc(85vh - 130px);
        }

        .pt-detail-header-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: .85rem;
            margin-bottom: .85rem;
        }

        .pt-detail-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .85rem;
            margin-bottom: .85rem;
        }

        .pt-detail-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .85rem;
            margin-bottom: .85rem;
        }

        .pt-detail-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: .85rem;
            margin-bottom: .85rem;
        }

        .pt-detail-field {
            display: flex;
            flex-direction: column;
            gap: .25rem;
        }

        .pt-detail-label {
            font-size: .68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--text-muted);
        }

        .pt-detail-value {
            font-size: .9rem;
            font-weight: 600;
            color: var(--text-dark);
            padding: .45rem .65rem;
            background: var(--bg-card);
            border: 1.5px solid var(--border-light);
            border-radius: 7px;
            min-height: 2rem;
            line-height: 1.4;
        }

        .pt-detail-pr-num {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--blue-mid);
            border-color: var(--blue-mid);
        }

        .pt-detail-section-label {
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--blue-mid);
            border-bottom: 1.5px solid var(--border-light);
            padding-bottom: .4rem;
            margin: 1rem 0 .75rem;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .pt-detail-num {
            color: #0e7490 !important;
            font-weight: 700 !important;
        }

        .dd-early {}

        .dd-ok {}

        .dd-late {}

        .pt-detail-total-dur {
            font-size: 1rem !important;
            font-weight: 800 !important;
        }

        .pt-detail-total-delay {
            font-size: 1rem !important;
            font-weight: 800 !important;
        }

        .pt-row-clickable:hover td {
            background-color: rgba(37, 99, 235, .06) !important;
        }

        body.light-theme .pt-detail-value {
            background: #f9fafb;
            border-color: #e5e7eb;
            color: #1f2937;
        }

        body.light-theme .pt-detail-section-label {
            border-bottom-color: #e5e7eb;
        }

        .pt-detail-input {
            width: 100%;
            border: 1.5px solid var(--border-light);
            outline: none;
            font-family: 'Nunito', sans-serif;
            cursor: default;
            transition: border-color .2s, background .2s, box-shadow .2s;
        }

        .pt-detail-input[readonly] {
            cursor: default;
            background: var(--bg-card);
        }

        .pt-input-active {
            cursor: text !important;
            background: #fff !important;
            border-color: var(--blue-mid) !important;
            box-shadow: 0 0 0 3px rgba(33, 150, 243, .15) !important;
        }

        body.light-theme .pt-input-active {
            background: #fff !important;
        }

        .pt-btn-cancel-edit {
            background: #fee2e2 !important;
            border-color: #fca5a5 !important;
            color: #b91c1c !important;
        }

        .pt-btn-cancel-edit:hover {
            background: #fecaca !important;
        }

        /* ── Dark theme (shared) ── */
        body.dark-theme .pt-search-wrap input {
            background: #1e3a5f;
            border-color: #2a5080;
            color: #e8f0fe;
        }

        body.dark-theme .pt-per-page {
            background: #1e3a5f;
            border-color: #2a5080;
            color: #e8f0fe;
        }

        body.dark-theme .pt-btn-outline {
            background: #1e3a5f;
            border-color: #2a5080 !important;
            color: #90caf9;
        }

        body.dark-theme .pt-table-card {
            background: #0f1f3d;
            border-color: #1e3a5f;
        }

        body.dark-theme .pt-table tbody td {
            border-color: #1e3a5f;
            color: #e8f0fe;
        }

        body.dark-theme .pt-table tbody tr:hover td {
            background: #1a3060 !important;
        }

        body.dark-theme .pt-table tbody tr:nth-child(even) td {
            background: #0d1f3a;
        }

        body.dark-theme .col-pr {
            background: #0f2040 !important;
            color: #90caf9;
            border-right-color: #2a5080 !important;
        }

        body.dark-theme .pt-table tbody tr:hover .col-pr {
            background: #1a3060 !important;
        }

        body.dark-theme .pt-table tbody tr:nth-child(even) .col-pr {
            background: #0d1f3a !important;
        }

        body.dark-theme .c-date {
            color: #90caf9;
        }

        body.dark-theme .c-num {
            color: #90caf9;
        }

        body.dark-theme .c-na {
            color: #546e7a;
        }

        body.dark-theme .c-empty {
            color: #37474f;
        }

        body.dark-theme .c-dur-total {
            background: #0d2d4a !important;
            color: #42a5f5;
        }

        body.dark-theme .c-ok {
            color: #a5d6a7;
        }

        body.dark-theme .c-late {
            color: #ef9a9a;
        }

        body.dark-theme .c-early {
            color: #90caf9;
        }

        body.dark-theme .pt-pag-bar {
            background: #0d1b2a;
            border-top-color: #1e3a5f;
        }

        body.dark-theme .pt-pag-info {
            color: #90caf9;
        }

        body.dark-theme .pt-pag-links a,
        body.dark-theme .pt-pag-links span {
            border-color: #2a5080;
            color: #90caf9;
        }

        body.dark-theme .pt-pag-links a:hover {
            background: #1e3a5f;
            border-color: #42a5f5;
        }

        body.dark-theme .pt-err {
            background: rgba(198, 40, 40, .15);
            border-color: rgba(198, 40, 40, .4);
            color: #ef9a9a;
        }

        body.dark-theme .pt-empty-banner {
            background: linear-gradient(135deg, #0f2040 0%, #1e3a5f 100%);
            border-color: #2a5080;
        }

        body.dark-theme .pt-empty-banner h3 {
            color: #90caf9;
        }

        body.dark-theme .pt-empty-banner p {
            color: #cbd5e1;
        }

        body.dark-theme .pt-modal {
            background: #0f1f3d;
            color: #e8f0fe;
        }

        body.dark-theme .pt-modal-title {
            color: #90caf9;
        }

        body.dark-theme .pt-dropzone {
            background: #1e3a5f;
            border-color: #2a5080;
        }

        body.dark-theme .pt-dropzone:hover,
        body.dark-theme .pt-dropzone.dragover {
            background: #254a70;
            border-color: #42a5f5;
        }

        body.dark-theme .pt-dropzone-label {
            color: #e8f0fe;
        }

        body.dark-theme .pt-dropzone-hint {
            color: #90caf9;
        }

        body.dark-theme .pt-modal-btn-cancel {
            background: #1e3a5f;
            border-color: #2a5080;
            color: #90caf9;
        }

        body.dark-theme .pt-modal-btn-cancel:hover {
            background: #254a70;
        }

        /* Light theme */
        body.light-theme .pt-search-wrap input {
            background: #f9fafb;
            border-color: #e5e7eb;
            color: #1f2937;
        }

        body.light-theme .pt-per-page {
            background: #f9fafb;
            border-color: #e5e7eb;
            color: #1f2937;
        }

        body.light-theme .pt-btn-outline {
            background: #f9fafb;
            border-color: #e5e7eb !important;
            color: #6b7280;
        }

        body.light-theme .pt-table-card {
            background: #fff;
            border-color: #e5e7eb;
        }

        body.light-theme .pt-table tbody td {
            border-color: #e5e7eb;
            color: #1f2937;
        }

        body.light-theme .pt-table tbody tr:hover td {
            background: #eff6ff !important;
        }

        body.light-theme .pt-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        body.light-theme .col-pr {
            background: #dbeafe !important;
            color: #1e3a5f !important;
            border-right-color: #93c5fd !important;
        }

        body.light-theme .pt-table tbody tr:hover .col-pr {
            background: #bfdbfe !important;
        }

        body.light-theme .pt-table tbody tr:nth-child(even) .col-pr {
            background: #e0ecff !important;
        }

        body.light-theme .col-part {
            color: #1f2937;
            text-align: left !important;
        }

        body.light-theme .c-date {
            color: #374151;
        }

        body.light-theme .c-num {
            color: #374151;
        }

        body.light-theme .c-na {
            color: #9ca3af;
        }

        body.light-theme .c-empty {
            color: #d1d5db;
        }

        body.light-theme .c-dur-total {
            background: #dbeafe !important;
            color: #1d4ed8;
        }

        body.light-theme .gh-base {
            background: #1565c0 !important;
        }

        body.light-theme .gh-receipt {
            background: #2e7d32 !important;
        }

        body.light-theme .gh-proc {
            background: #6a1b9a !important;
        }

        body.light-theme .gh-rfq {
            background: #0277bd !important;
        }

        body.light-theme .gh-apq {
            background: #e65100 !important;
        }

        body.light-theme .gh-noa {
            background: #00695c !important;
        }

        body.light-theme .gh-po {
            background: #c62828 !important;
        }

        body.light-theme .gh-sum {
            background: #455a64 !important;
        }

        body.light-theme .pt-table thead tr.cr th {
            opacity: 1;
        }

        body.light-theme .ch-base {
            background: #dbeafe !important;
            color: #1f2937 !important;
        }

        body.light-theme .ch-receipt {
            background: #dcfce7 !important;
            color: #1f2937 !important;
        }

        body.light-theme .ch-proc {
            background: #f3e8ff !important;
            color: #1f2937 !important;
        }

        body.light-theme .ch-rfq {
            background: #dbeafe !important;
            color: #1f2937 !important;
        }

        body.light-theme .ch-apq {
            background: #ffedd5 !important;
            color: #1f2937 !important;
        }

        body.light-theme .ch-noa {
            background: #ccfbf1 !important;
            color: #1f2937 !important;
        }

        body.light-theme .ch-po {
            background: #fee2e2 !important;
            color: #1f2937 !important;
        }

        body.light-theme .ch-sum {
            background: #f1f5f9 !important;
            color: #1f2937 !important;
        }

        body.light-theme .pt-pag-bar {
            background: #f9fafb;
            border-top-color: #e5e7eb;
        }

        body.light-theme .pt-pag-info {
            color: #374151;
        }

        body.light-theme .pt-pag-links a,
        body.light-theme .pt-pag-links span {
            border-color: #e5e7eb;
            color: #2563eb;
        }

        body.light-theme .pt-pag-links a:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        body.light-theme .pt-rec-count {
            color: #374151;
        }

        body.light-theme .pt-add-section-title {
            border-bottom-color: #e5e7eb;
        }

        body.light-theme .pt-add-input {
            background: #f9fafb;
            border-color: #e5e7eb;
            color: #1f2937;
        }

        body.light-theme .pt-add-input:focus {
            background: #fff;
        }

        body.light-theme .pt-add-modal-footer {
            background: #f9fafb;
            border-top-color: #e5e7eb;
        }
    </style>
</head>

<body>
    <div class="dashboard-wrapper">

        <?php include 'sidebar.php'; ?>

        <!-- MAIN -->
        <main class="pt-main">

            <!-- HEADER -->
            <div class="dashboard-header-section">
                <div class="header-title-area">
                    <h1><i class="bi bi-clipboard2-data-fill" style="margin-right:.45rem;"></i>Procurement Processing
                        Time</h1>
                    <p>Philippine Statistics Authority &mdash; Procurement Processing Time Sheet</p>
                </div>
                <div class="header-actions">
                    <div
                        style="display:flex;flex-direction:column;align-items:flex-end;justify-content:center;line-height:1.3;margin-right:.4rem;">
                        <span id="ptClock" class="pt-clock-time"
                            style="font-size:1.1rem;font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:.04em;">--:--:--</span>
                        <span id="ptDate" class="pt-clock-date" style="font-size:.7rem;white-space:nowrap;">--- --,
                            ----</span>
                    </div>
                    <button class="btn-header-action theme-toggle" id="ptThemeToggle" title="Toggle theme"
                        style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;">
                        <i class="bi bi-sun-fill"></i>
                    </button>
                </div>
            </div>

            <?php if ($fetch_error): ?>
                <div class="pt-err">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size:1.15rem;flex-shrink:0;"></i>
                    <span><?= $fetch_error ?></span>
                </div>
            <?php endif; ?>

            <?php if ($total_records === 0 && !$fetch_error): ?>
                <div class="pt-empty-banner">
                    <i class="bi bi-cloud-upload-fill"></i>
                    <h3>No Data Yet</h3>
                    <p>Upload your Procurement Processing Time <strong>.xlsx</strong> file, or add a PR manually.</p>
                    <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;">
                        <button class="pt-btn pt-btn-success" onclick="openAddPRModal()">
                            <i class="bi bi-plus-circle"></i> Add PR Manually
                        </button>
                        <button class="pt-btn pt-btn-primary"
                            onclick="document.getElementById('ptUploadModal').classList.add('open')">
                            <i class="bi bi-cloud-upload"></i> Upload .xlsx File
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($total_records > 0): ?>

                <!-- TOOLBAR -->
                <form method="GET" action="" id="ptForm" style="margin-bottom:.65rem;">
                    <div class="pt-toolbar">
                        <div class="pt-search-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                                placeholder="Search PR No., Particulars, End-User… (/)">
                        </div>
                        <button type="submit" class="pt-btn pt-btn-primary">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <?php if ($search): ?>
                            <a href="?" class="pt-btn pt-btn-outline">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        <?php endif; ?>
                        <select name="per_page" class="pt-per-page" onchange="document.getElementById('ptForm').submit()">
                            <?php foreach ([10, 15, 25, 50, 100] as $n): ?>
                                <option value="<?= $n ?>" <?= $per_page === $n ? 'selected' : '' ?>><?= $n ?> rows</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="pt-btn pt-btn-success" onclick="openAddPRModal()"
                            style="margin-left:auto;">
                            <i class="bi bi-plus-circle"></i> Add PR
                        </button>
                    </div>
                </form>

                <!-- TABLE CARD -->
                <div class="pt-table-card">
                    <div class="pt-table-scroll">
                        <table class="pt-table">
                            <thead>
                                <tr class="gr">
                                    <th rowspan="2" class="gh-base th-pr-sticky">PR Number</th>
                                    <th rowspan="2" class="gh-base">Particulars</th>
                                    <th rowspan="2" class="gh-base">End-User</th>
                                    <th rowspan="2" class="gh-base">Mode of Procurement</th>
                                    <th colspan="4" class="gh-receipt">PR Receipt</th>
                                    <th colspan="5" class="gh-proc">PR Processing</th>
                                    <th colspan="4" class="gh-rfq">RFQ</th>
                                    <th colspan="6" class="gh-apq">Posting &amp; APQ</th>
                                    <th colspan="5" class="gh-noa">NOA</th>
                                    <th colspan="9" class="gh-po">PO</th>
                                    <th colspan="2" class="gh-sum">Summary</th>
                                </tr>
                                <tr class="cr">
                                    <th class="ch-receipt">Date of Event / Expected Delivery</th>
                                    <th class="ch-receipt">Date of Receipt</th>
                                    <th class="ch-receipt">Expected Receipt</th>
                                    <th class="ch-receipt">Days Delayed</th>
                                    <th class="ch-proc">PR Numbering</th>
                                    <th class="ch-proc">Date Endorsed to BD</th>
                                    <th class="ch-proc">Date Earmarked</th>
                                    <th class="ch-proc">Duration</th>
                                    <th class="ch-proc">Days Delayed</th>
                                    <th class="ch-rfq">Preparation of RFQ</th>
                                    <th class="ch-rfq">Approved RFQ</th>
                                    <th class="ch-rfq">Duration</th>
                                    <th class="ch-rfq">Days Delayed</th>
                                    <th class="ch-apq">Date of Posting</th>
                                    <th class="ch-apq">Preparation of APQ</th>
                                    <th class="ch-apq">Return Date of APQ</th>
                                    <th class="ch-apq">Approved APQ</th>
                                    <th class="ch-apq">Duration</th>
                                    <th class="ch-apq">Days Delayed</th>
                                    <th class="ch-noa">NOA Preparation</th>
                                    <th class="ch-noa">Approved NOA</th>
                                    <th class="ch-noa">Date Acknowledged</th>
                                    <th class="ch-noa">Duration</th>
                                    <th class="ch-noa">Days Delayed</th>
                                    <th class="ch-po">For Issuance of ORS</th>
                                    <th class="ch-po">SMD to BD</th>
                                    <th class="ch-po">For CAF</th>
                                    <th class="ch-po">CAFed</th>
                                    <th class="ch-po">Date Routed to HoPE</th>
                                    <th class="ch-po">Approved PO</th>
                                    <th class="ch-po">Date Acknowledged</th>
                                    <th class="ch-po">Duration</th>
                                    <th class="ch-po">Days Delayed</th>
                                    <th class="ch-sum">Total Duration</th>
                                    <th class="ch-sum">Total Days Delayed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paged)): ?>
                                    <tr class="pt-empty-row">
                                        <td colspan="39"><i class="bi bi-inbox big-i"></i>No records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($paged as $r): ?>
                                        <?php
                                        $totalDelay = trim($r[38] ?? '');
                                        $sumClass = 'c-sum-pend';
                                        $sumHtml = '—';
                                        if ($totalDelay !== '' && strtoupper($totalDelay) !== 'N/A') {
                                            $tv = (float) $totalDelay;
                                            $sumClass = $tv <= 0 ? 'c-sum-ok' : 'c-sum-late';
                                            $sumHtml = htmlspecialchars($totalDelay);
                                        }
                                        ?>
                                        <tr class="pt-row-clickable" style="cursor:pointer;" onclick="openPTDetail(this)"
                                            data-pr="<?= htmlspecialchars($r[0] ?? '', ENT_QUOTES) ?>"
                                            data-particulars="<?= htmlspecialchars($r[1] ?? '', ENT_QUOTES) ?>"
                                            data-end-user="<?= htmlspecialchars($r[2] ?? '', ENT_QUOTES) ?>"
                                            data-mode="<?= htmlspecialchars($r[3] ?? '', ENT_QUOTES) ?>"
                                            data-receipt-event="<?= htmlspecialchars($r[4] ?? '', ENT_QUOTES) ?>"
                                            data-receipt-received="<?= htmlspecialchars($r[5] ?? '', ENT_QUOTES) ?>"
                                            data-receipt-expected="<?= htmlspecialchars($r[6] ?? '', ENT_QUOTES) ?>"
                                            data-receipt-delayed="<?= htmlspecialchars($r[7] ?? '', ENT_QUOTES) ?>"
                                            data-proc-numbering="<?= htmlspecialchars($r[8] ?? '', ENT_QUOTES) ?>"
                                            data-proc-endorsed="<?= htmlspecialchars($r[9] ?? '', ENT_QUOTES) ?>"
                                            data-proc-earmarked="<?= htmlspecialchars($r[10] ?? '', ENT_QUOTES) ?>"
                                            data-proc-duration="<?= htmlspecialchars($r[11] ?? '', ENT_QUOTES) ?>"
                                            data-proc-delayed="<?= htmlspecialchars($r[12] ?? '', ENT_QUOTES) ?>"
                                            data-rfq-preparation="<?= htmlspecialchars($r[13] ?? '', ENT_QUOTES) ?>"
                                            data-rfq-approved="<?= htmlspecialchars($r[14] ?? '', ENT_QUOTES) ?>"
                                            data-rfq-duration="<?= htmlspecialchars($r[15] ?? '', ENT_QUOTES) ?>"
                                            data-rfq-delayed="<?= htmlspecialchars($r[16] ?? '', ENT_QUOTES) ?>"
                                            data-apq-posting="<?= htmlspecialchars($r[17] ?? '', ENT_QUOTES) ?>"
                                            data-apq-preparation="<?= htmlspecialchars($r[18] ?? '', ENT_QUOTES) ?>"
                                            data-apq-return="<?= htmlspecialchars($r[19] ?? '', ENT_QUOTES) ?>"
                                            data-apq-approved="<?= htmlspecialchars($r[20] ?? '', ENT_QUOTES) ?>"
                                            data-apq-duration="<?= htmlspecialchars($r[21] ?? '', ENT_QUOTES) ?>"
                                            data-apq-delayed="<?= htmlspecialchars($r[22] ?? '', ENT_QUOTES) ?>"
                                            data-noa-preparation="<?= htmlspecialchars($r[23] ?? '', ENT_QUOTES) ?>"
                                            data-noa-approved="<?= htmlspecialchars($r[24] ?? '', ENT_QUOTES) ?>"
                                            data-noa-acknowledged="<?= htmlspecialchars($r[25] ?? '', ENT_QUOTES) ?>"
                                            data-noa-duration="<?= htmlspecialchars($r[26] ?? '', ENT_QUOTES) ?>"
                                            data-noa-delayed="<?= htmlspecialchars($r[27] ?? '', ENT_QUOTES) ?>"
                                            data-po-ors="<?= htmlspecialchars($r[28] ?? '', ENT_QUOTES) ?>"
                                            data-po-smd="<?= htmlspecialchars($r[29] ?? '', ENT_QUOTES) ?>"
                                            data-po-caf="<?= htmlspecialchars($r[30] ?? '', ENT_QUOTES) ?>"
                                            data-po-cafed="<?= htmlspecialchars($r[31] ?? '', ENT_QUOTES) ?>"
                                            data-po-hope="<?= htmlspecialchars($r[32] ?? '', ENT_QUOTES) ?>"
                                            data-po-approved="<?= htmlspecialchars($r[33] ?? '', ENT_QUOTES) ?>"
                                            data-po-acknowledged="<?= htmlspecialchars($r[34] ?? '', ENT_QUOTES) ?>"
                                            data-po-duration="<?= htmlspecialchars($r[35] ?? '', ENT_QUOTES) ?>"
                                            data-po-delayed="<?= htmlspecialchars($r[36] ?? '', ENT_QUOTES) ?>"
                                            data-total-duration="<?= htmlspecialchars($r[37] ?? '', ENT_QUOTES) ?>"
                                            data-total-delayed="<?= htmlspecialchars($r[38] ?? '', ENT_QUOTES) ?>">
                                            <td class="col-pr"><?= htmlspecialchars($r[0] ?? '') ?></td>
                                            <td class="col-part"><?= htmlspecialchars($r[1] ?? '') ?></td>
                                            <?= pCell($r[2] ?? '') ?>
                                            <?= pCell($r[3] ?? '') ?>
                                            <?= dtCell($r[4] ?? '') ?>
                                            <?= dtCell($r[5] ?? '') ?>
                                            <?= dtCell($r[6] ?? '') ?>
                                            <?= dCell($r[7] ?? '') ?>
                                            <?= dtCell($r[8] ?? '') ?>
                                            <?= dtCell($r[9] ?? '') ?>
                                            <?= dtCell($r[10] ?? '') ?>
                                            <?= pCell($r[11] ?? '', 'c-num') ?>
                                            <?= dCell($r[12] ?? '') ?>
                                            <?= dtCell($r[13] ?? '') ?>
                                            <?= dtCell($r[14] ?? '') ?>
                                            <?= pCell($r[15] ?? '', 'c-num') ?>
                                            <?= dCell($r[16] ?? '') ?>
                                            <?= dtCell($r[17] ?? '') ?>
                                            <?= dtCell($r[18] ?? '') ?>
                                            <?= dtCell($r[19] ?? '') ?>
                                            <?= dtCell($r[20] ?? '') ?>
                                            <?= pCell($r[21] ?? '', 'c-num') ?>
                                            <?= dCell($r[22] ?? '') ?>
                                            <?= dtCell($r[23] ?? '') ?>
                                            <?= dtCell($r[24] ?? '') ?>
                                            <?= dtCell($r[25] ?? '') ?>
                                            <?= pCell($r[26] ?? '', 'c-num') ?>
                                            <?= dCell($r[27] ?? '') ?>
                                            <?= dtCell($r[28] ?? '') ?>
                                            <?= dtCell($r[29] ?? '') ?>
                                            <?= dtCell($r[30] ?? '') ?>
                                            <?= dtCell($r[31] ?? '') ?>
                                            <?= dtCell($r[32] ?? '') ?>
                                            <?= dtCell($r[33] ?? '') ?>
                                            <?= dtCell($r[34] ?? '') ?>
                                            <?= pCell($r[35] ?? '', 'c-num') ?>
                                            <?= dCell($r[36] ?? '') ?>
                                            <td class="c-dur-total"><?= htmlspecialchars(trim($r[37] ?? '') ?: '—') ?></td>
                                            <td class="<?= $sumClass ?>"><?= $sumHtml ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINATION -->
                    <?php if ($total > 0): ?>
                        <div class="pt-pag-bar">
                            <span class="pt-pag-info">
                                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total)) ?>
                                of <?= number_format($total) ?> records
                            </span>
                            <div class="pt-pag-links">
                                <?php if ($page > 1): ?>
                                    <a href="<?= pUrl(['page' => 1]) ?>"><i class="bi bi-chevron-bar-left"></i></a>
                                    <a href="<?= pUrl(['page' => $page - 1]) ?>"><i class="bi bi-chevron-left"></i></a>
                                <?php else: ?>
                                    <span class="off"><i class="bi bi-chevron-bar-left"></i></span>
                                    <span class="off"><i class="bi bi-chevron-left"></i></span>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                    <?php if ($p === $page): ?><span class="on"><?= $p ?></span>
                                    <?php else: ?><a href="<?= pUrl(['page' => $p]) ?>"><?= $p ?></a><?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="<?= pUrl(['page' => $page + 1]) ?>"><i class="bi bi-chevron-right"></i></a>
                                    <a href="<?= pUrl(['page' => $total_pages]) ?>"><i class="bi bi-chevron-bar-right"></i></a>
                                <?php else: ?>
                                    <span class="off"><i class="bi bi-chevron-right"></i></span>
                                    <span class="off"><i class="bi bi-chevron-bar-right"></i></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- /pt-table-card -->

            <?php endif; ?>

            <!-- UPLOAD BUTTON (below table) -->
            <div style="display:flex;justify-content:flex-end;margin-top:1rem;padding:0 1rem 1rem;">
                <button class="btn-header-action pt-btn pt-btn-success" id="ptUploadBtn" title="Upload .xlsx file"
                    style="gap:.4rem;font-size:.85rem;background:linear-gradient(135deg,#0277bd 0%,#01579b 100%) !important;box-shadow:0 4px 12px rgba(2,119,189,.25) !important;"
                    onclick="document.getElementById('ptUploadModal').classList.add('open')">
                    <i class="bi bi-cloud-upload"></i> Upload Data
                </button>
            </div>

        </main>
    </div><!-- /dashboard-wrapper -->

    <!-- ══════════════════════════════════════════════
         ADD PR MODAL
    ══════════════════════════════════════════════ -->
    <div class="pt-modal-backdrop" id="ptAddPRModal">
        <div class="pt-modal pt-add-modal">

            <!-- Header -->
            <div class="pt-add-modal-head">
                <div class="pt-add-modal-title">
                    <i class="bi bi-plus-circle-fill"></i>
                    Add New Procurement Record
                </div>
                <button class="pt-modal-close" onclick="closeAddPRModal()">&times;</button>
            </div>

            <!-- Scrollable body -->
            <div class="pt-add-modal-body" id="ptAddPRBody">

                <!-- Base Info -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-info-circle"></i> Basic Information</div>
                    <div class="pt-add-grid-2">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-pr-number">PR Number <span
                                    style="color:#dc2626;">*</span></label>
                            <input class="pt-add-input required-field" id="add-pr-number" name="pr_number" type="text"
                                placeholder="e.g. 26-01-0001" required>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-mode">Mode of Procurement</label>
                            <input class="pt-add-input" id="add-mode" name="mode_of_procurement" type="text"
                                placeholder="e.g. Shopping, SVP…">
                        </div>
                        <div class="pt-add-field" style="grid-column: span 2;">
                            <label class="pt-add-label" for="add-particulars">Particulars</label>
                            <textarea class="pt-add-input" id="add-particulars" name="particulars"
                                placeholder="Description of goods/services…"></textarea>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-end-user">End User</label>
                            <input class="pt-add-input" id="add-end-user" name="end_user" type="text"
                                placeholder="e.g. FAS-HRD">
                        </div>
                    </div>
                </div>

                <!-- PR Receipt -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-inbox"></i> PR Receipt</div>
                    <div class="pt-add-grid-4">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-receipt-event">Date of Event / Expected
                                Delivery</label>
                            <input class="pt-add-input" id="add-receipt-event" name="receipt_date_event" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-receipt-received">Date of Receipt</label>
                            <input class="pt-add-input" id="add-receipt-received" name="receipt_date_received"
                                type="text" placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-receipt-expected">Expected Receipt</label>
                            <input class="pt-add-input" id="add-receipt-expected" name="receipt_expected" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-receipt-delayed">Days Delayed</label>
                            <input class="pt-add-input" id="add-receipt-delayed" name="receipt_days_delayed" type="text"
                                placeholder="—">
                        </div>
                    </div>
                </div>

                <!-- PR Processing -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-gear"></i> PR Processing</div>
                    <div class="pt-add-grid-5">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-proc-numbering">PR Numbering</label>
                            <input class="pt-add-input" id="add-proc-numbering" name="proc_pr_numbering" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-proc-endorsed">Date Endorsed to BD</label>
                            <input class="pt-add-input" id="add-proc-endorsed" name="proc_date_endorsed" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-proc-earmarked">Date Earmarked</label>
                            <input class="pt-add-input" id="add-proc-earmarked" name="proc_date_earmarked" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-proc-duration">Duration</label>
                            <input class="pt-add-input" id="add-proc-duration" name="proc_duration" type="text"
                                placeholder="—">
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-proc-delayed">Days Delayed</label>
                            <input class="pt-add-input" id="add-proc-delayed" name="proc_days_delayed" type="text"
                                placeholder="—">
                        </div>
                    </div>
                </div>

                <!-- RFQ -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-file-text"></i> RFQ</div>
                    <div class="pt-add-grid-4">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-rfq-preparation">Preparation of RFQ</label>
                            <input class="pt-add-input" id="add-rfq-preparation" name="rfq_preparation" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-rfq-approved">Approved RFQ</label>
                            <input class="pt-add-input" id="add-rfq-approved" name="rfq_approved" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-rfq-duration">Duration</label>
                            <input class="pt-add-input" id="add-rfq-duration" name="rfq_duration" type="text"
                                placeholder="—">
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-rfq-delayed">Days Delayed</label>
                            <input class="pt-add-input" id="add-rfq-delayed" name="rfq_days_delayed" type="text"
                                placeholder="—">
                        </div>
                    </div>
                </div>

                <!-- Posting & APQ -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-megaphone"></i> Posting &amp; APQ</div>
                    <div class="pt-add-grid-3">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-apq-posting">Date of Posting</label>
                            <input class="pt-add-input" id="add-apq-posting" name="apq_date_posting" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-apq-preparation">Preparation of APQ</label>
                            <input class="pt-add-input" id="add-apq-preparation" name="apq_preparation" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-apq-return">Return Date of APQ</label>
                            <input class="pt-add-input" id="add-apq-return" name="apq_return_date" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-apq-approved">Approved APQ</label>
                            <input class="pt-add-input" id="add-apq-approved" name="apq_approved" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-apq-duration">Duration</label>
                            <input class="pt-add-input" id="add-apq-duration" name="apq_duration" type="text"
                                placeholder="—">
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-apq-delayed">Days Delayed</label>
                            <input class="pt-add-input" id="add-apq-delayed" name="apq_days_delayed" type="text"
                                placeholder="—">
                        </div>
                    </div>
                </div>

                <!-- NOA -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-envelope-check"></i> NOA</div>
                    <div class="pt-add-grid-5">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-noa-preparation">NOA Preparation</label>
                            <input class="pt-add-input" id="add-noa-preparation" name="noa_preparation" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-noa-approved">Approved NOA</label>
                            <input class="pt-add-input" id="add-noa-approved" name="noa_approved" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-noa-acknowledged">Date Acknowledged</label>
                            <input class="pt-add-input" id="add-noa-acknowledged" name="noa_date_acknowledged"
                                type="text" placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-noa-duration">Duration</label>
                            <input class="pt-add-input" id="add-noa-duration" name="noa_duration" type="text"
                                placeholder="—">
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-noa-delayed">Days Delayed</label>
                            <input class="pt-add-input" id="add-noa-delayed" name="noa_days_delayed" type="text"
                                placeholder="—">
                        </div>
                    </div>
                </div>

                <!-- PO -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-receipt"></i> Purchase Order (PO)</div>
                    <div class="pt-add-grid-3">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-ors">For Issuance of ORS</label>
                            <input class="pt-add-input" id="add-po-ors" name="po_issuance_ors" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-smd">SMD to BD</label>
                            <input class="pt-add-input" id="add-po-smd" name="po_smd_to_bd" type="text" placeholder="—"
                                data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-caf">For CAF</label>
                            <input class="pt-add-input" id="add-po-caf" name="po_for_caf" type="text" placeholder="—"
                                data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-cafed">CAFed</label>
                            <input class="pt-add-input" id="add-po-cafed" name="po_cafed" type="text" placeholder="—"
                                data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-hope">Date Routed to HoPE</label>
                            <input class="pt-add-input" id="add-po-hope" name="po_routed_hope" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-approved">Approved PO</label>
                            <input class="pt-add-input" id="add-po-approved" name="po_approved" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-acknowledged">Date Acknowledged</label>
                            <input class="pt-add-input" id="add-po-acknowledged" name="po_date_acknowledged" type="text"
                                placeholder="—" data-datepicker>
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-duration">Duration</label>
                            <input class="pt-add-input" id="add-po-duration" name="po_duration" type="text"
                                placeholder="—">
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-po-delayed">Days Delayed</label>
                            <input class="pt-add-input" id="add-po-delayed" name="po_days_delayed" type="text"
                                placeholder="—">
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="pt-add-section">
                    <div class="pt-add-section-title"><i class="bi bi-bar-chart"></i> Summary</div>
                    <div class="pt-add-grid-2">
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-total-duration">Total Duration</label>
                            <input class="pt-add-input" id="add-total-duration" name="total_duration" type="text"
                                placeholder="—">
                        </div>
                        <div class="pt-add-field">
                            <label class="pt-add-label" for="add-total-delayed">Total Days Delayed</label>
                            <input class="pt-add-input" id="add-total-delayed" name="total_days_delayed" type="text"
                                placeholder="—">
                        </div>
                    </div>
                </div>

                <!-- Result feedback -->
                <div id="ptAddResult"
                    style="display:none;padding:.7rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;margin-top:.5rem;">
                </div>

            </div><!-- /body -->

            <!-- Footer -->
            <div class="pt-add-modal-footer">
                <button class="pt-modal-btn-cancel" onclick="closeAddPRModal()" style="flex:1;">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button class="pt-modal-btn-submit" id="ptAddSubmitBtn" onclick="submitAddPR()" style="flex:2.5;">
                    <i class="bi bi-plus-circle-fill"></i> Add Procurement Record
                </button>
            </div>
        </div>
    </div>

    <!-- ── UPLOAD MODAL ── -->
    <div class="pt-modal-backdrop" id="ptUploadModal">
        <div class="pt-modal">
            <div class="pt-modal-head">
                <div class="pt-modal-title"><i class="bi bi-cloud-upload-fill" style="color:#059669;"></i> Upload
                    Procurement Data</div>
                <button class="pt-modal-close" onclick="closeUploadModal()">&times;</button>
            </div>
            <div class="pt-dropzone" id="ptDropzone">
                <input type="file" id="ptFileInput" accept=".xlsx,.xls,.csv" onchange="handleFileSelect(this)">
                <i class="bi bi-file-earmark-spreadsheet pt-dropzone-icon"></i>
                <span class="pt-dropzone-label">Drop your spreadsheet here</span>
                <span class="pt-dropzone-hint">CSV or Excel .xlsx/.xls — max 20 MB</span>
            </div>
            <div class="pt-file-selected" id="ptFileSelected">
                <i class="bi bi-file-earmark-check-fill"></i>
                <span class="pt-file-name" id="ptFileName">—</span>
                <span class="pt-file-size" id="ptFileSize"></span>
            </div>
            <div class="pt-progress-wrap" id="ptProgressWrap">
                <div class="pt-progress-label">
                    <span id="ptProgressLabel">Uploading…</span>
                    <span id="ptProgressPct">0%</span>
                </div>
                <div class="pt-progress-bar-bg">
                    <div class="pt-progress-bar-fill" id="ptProgressFill"></div>
                </div>
            </div>
            <div class="pt-modal-actions">
                <button class="pt-modal-btn-cancel" onclick="closeUploadModal()">Cancel</button>
                <button class="pt-modal-btn-submit" id="ptSubmitBtn" onclick="submitUpload()" disabled>
                    <i class="bi bi-cloud-upload"></i> Upload &amp; Sync
                </button>
            </div>
            <div id="ptUploadResult"
                style="display:none;margin-top:1rem;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;font-weight:600;">
            </div>
        </div>
    </div>

    <!-- ── DETAIL VIEW MODAL ── -->
    <div class="pt-modal-backdrop" id="ptDetailModal">
        <div class="pt-modal pt-detail-modal">
            <div class="pt-modal-head">
                <div class="pt-modal-title"><i class="bi bi-clipboard2-data-fill"></i> <span id="ptDetailTitle">PR
                        Details</span></div>
                <button class="pt-modal-close" onclick="closePTDetail()">&times;</button>
            </div>
            <div class="pt-detail-body">
                <input type="hidden" id="dd-pr-hidden" name="pr_number">
                <div class="pt-detail-header-row">
                    <div class="pt-detail-field">
                        <label class="pt-detail-label">PR NUMBER</label>
                        <div class="pt-detail-value pt-detail-pr-num" id="dd-pr">—</div>
                    </div>
                    <div class="pt-detail-field">
                        <label class="pt-detail-label" for="dd-particulars">PARTICULARS</label>
                        <input class="pt-detail-value pt-detail-input" id="dd-particulars" name="particulars"
                            type="text" placeholder="—" readonly>
                    </div>
                </div>
                <div class="pt-detail-grid-2">
                    <div class="pt-detail-field">
                        <label class="pt-detail-label" for="dd-end-user">END USER</label>
                        <input class="pt-detail-value pt-detail-input" id="dd-end-user" name="end_user" type="text"
                            placeholder="—" readonly>
                    </div>
                    <div class="pt-detail-field">
                        <label class="pt-detail-label" for="dd-mode">MODE OF PROCUREMENT</label>
                        <input class="pt-detail-value pt-detail-input" id="dd-mode" name="mode_of_procurement"
                            type="text" placeholder="—" readonly>
                    </div>
                </div>
                <div class="pt-detail-section-label"><i class="bi bi-inbox"></i> PR Receipt</div>
                <div class="pt-detail-grid-4">
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-receipt-event">DATE OF EVENT /
                            EXPECTED DELIVERY</label><input class="pt-detail-value pt-detail-input"
                            id="dd-receipt-event" name="receipt_date_event" type="text" placeholder="—" readonly
                            data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-receipt-received">DATE OF
                            RECEIPT</label><input class="pt-detail-value pt-detail-input" id="dd-receipt-received"
                            name="receipt_date_received" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-receipt-expected">EXPECTED
                            RECEIPT</label><input class="pt-detail-value pt-detail-input" id="dd-receipt-expected"
                            name="receipt_expected" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-receipt-delayed">DAYS
                            DELAYED</label><input class="pt-detail-value pt-detail-input pt-detail-delay"
                            id="dd-receipt-delayed" name="receipt_days_delayed" type="text" placeholder="—" readonly>
                    </div>
                </div>
                <div class="pt-detail-section-label"><i class="bi bi-gear"></i> PR Processing</div>
                <div class="pt-detail-grid-3">
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-proc-numbering">PR
                            NUMBERING</label><input class="pt-detail-value pt-detail-input" id="dd-proc-numbering"
                            name="proc_pr_numbering" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-proc-endorsed">DATE ENDORSED TO
                            BD</label><input class="pt-detail-value pt-detail-input" id="dd-proc-endorsed"
                            name="proc_date_endorsed" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-proc-earmarked">DATE
                            EARMARKED</label><input class="pt-detail-value pt-detail-input" id="dd-proc-earmarked"
                            name="proc_date_earmarked" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label"
                            for="dd-proc-duration">DURATION</label><input
                            class="pt-detail-value pt-detail-input pt-detail-num" id="dd-proc-duration"
                            name="proc_duration" type="text" placeholder="—" readonly></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-proc-delayed">DAYS
                            DELAYED</label><input class="pt-detail-value pt-detail-input pt-detail-delay"
                            id="dd-proc-delayed" name="proc_days_delayed" type="text" placeholder="—" readonly></div>
                </div>
                <div class="pt-detail-section-label"><i class="bi bi-file-text"></i> RFQ</div>
                <div class="pt-detail-grid-4">
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-rfq-preparation">PREPARATION OF
                            RFQ</label><input class="pt-detail-value pt-detail-input" id="dd-rfq-preparation"
                            name="rfq_preparation" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-rfq-approved">APPROVED
                            RFQ</label><input class="pt-detail-value pt-detail-input" id="dd-rfq-approved"
                            name="rfq_approved" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label"
                            for="dd-rfq-duration">DURATION</label><input
                            class="pt-detail-value pt-detail-input pt-detail-num" id="dd-rfq-duration"
                            name="rfq_duration" type="text" placeholder="—" readonly></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-rfq-delayed">DAYS
                            DELAYED</label><input class="pt-detail-value pt-detail-input pt-detail-delay"
                            id="dd-rfq-delayed" name="rfq_days_delayed" type="text" placeholder="—" readonly></div>
                </div>
                <div class="pt-detail-section-label"><i class="bi bi-megaphone"></i> Posting &amp; APQ</div>
                <div class="pt-detail-grid-3">
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-apq-posting">DATE OF
                            POSTING</label><input class="pt-detail-value pt-detail-input" id="dd-apq-posting"
                            name="apq_date_posting" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-apq-preparation">PREPARATION OF
                            APQ</label><input class="pt-detail-value pt-detail-input" id="dd-apq-preparation"
                            name="apq_preparation" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-apq-return">RETURN DATE OF
                            APQ</label><input class="pt-detail-value pt-detail-input" id="dd-apq-return"
                            name="apq_return_date" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-apq-approved">APPROVED
                            APQ</label><input class="pt-detail-value pt-detail-input" id="dd-apq-approved"
                            name="apq_approved" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label"
                            for="dd-apq-duration">DURATION</label><input
                            class="pt-detail-value pt-detail-input pt-detail-num" id="dd-apq-duration"
                            name="apq_duration" type="text" placeholder="—" readonly></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-apq-delayed">DAYS
                            DELAYED</label><input class="pt-detail-value pt-detail-input pt-detail-delay"
                            id="dd-apq-delayed" name="apq_days_delayed" type="text" placeholder="—" readonly></div>
                </div>
                <div class="pt-detail-section-label"><i class="bi bi-envelope-check"></i> NOA</div>
                <div class="pt-detail-grid-3">
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-noa-preparation">NOA
                            PREPARATION</label><input class="pt-detail-value pt-detail-input" id="dd-noa-preparation"
                            name="noa_preparation" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-noa-approved">APPROVED
                            NOA</label><input class="pt-detail-value pt-detail-input" id="dd-noa-approved"
                            name="noa_approved" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-noa-acknowledged">DATE
                            ACKNOWLEDGED</label><input class="pt-detail-value pt-detail-input" id="dd-noa-acknowledged"
                            name="noa_date_acknowledged" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label"
                            for="dd-noa-duration">DURATION</label><input
                            class="pt-detail-value pt-detail-input pt-detail-num" id="dd-noa-duration"
                            name="noa_duration" type="text" placeholder="—" readonly></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-noa-delayed">DAYS
                            DELAYED</label><input class="pt-detail-value pt-detail-input pt-detail-delay"
                            id="dd-noa-delayed" name="noa_days_delayed" type="text" placeholder="—" readonly></div>
                </div>
                <div class="pt-detail-section-label"><i class="bi bi-receipt"></i> Purchase Order (PO)</div>
                <div class="pt-detail-grid-3">
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-ors">FOR ISSUANCE OF
                            ORS</label><input class="pt-detail-value pt-detail-input" id="dd-po-ors"
                            name="po_issuance_ors" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-smd">SMD TO BD</label><input
                            class="pt-detail-value pt-detail-input" id="dd-po-smd" name="po_smd_to_bd" type="text"
                            placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-caf">FOR CAF</label><input
                            class="pt-detail-value pt-detail-input" id="dd-po-caf" name="po_for_caf" type="text"
                            placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-cafed">CAFED</label><input
                            class="pt-detail-value pt-detail-input" id="dd-po-cafed" name="po_cafed" type="text"
                            placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-hope">DATE ROUTED TO
                            HOPE</label><input class="pt-detail-value pt-detail-input" id="dd-po-hope"
                            name="po_routed_hope" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-approved">APPROVED
                            PO</label><input class="pt-detail-value pt-detail-input" id="dd-po-approved"
                            name="po_approved" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-acknowledged">DATE
                            ACKNOWLEDGED</label><input class="pt-detail-value pt-detail-input" id="dd-po-acknowledged"
                            name="po_date_acknowledged" type="text" placeholder="—" readonly data-datepicker></div>
                    <div class="pt-detail-field"><label class="pt-detail-label"
                            for="dd-po-duration">DURATION</label><input
                            class="pt-detail-value pt-detail-input pt-detail-num" id="dd-po-duration" name="po_duration"
                            type="text" placeholder="—" readonly></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-po-delayed">DAYS
                            DELAYED</label><input class="pt-detail-value pt-detail-input pt-detail-delay"
                            id="dd-po-delayed" name="po_days_delayed" type="text" placeholder="—" readonly></div>
                </div>
                <div class="pt-detail-section-label"><i class="bi bi-bar-chart"></i> Summary</div>
                <div class="pt-detail-grid-2">
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-total-duration">TOTAL
                            DURATION</label><input
                            class="pt-detail-value pt-detail-input pt-detail-num pt-detail-total-dur"
                            id="dd-total-duration" name="total_duration" type="text" placeholder="—" readonly></div>
                    <div class="pt-detail-field"><label class="pt-detail-label" for="dd-total-delayed">TOTAL DAYS
                            DELAYED</label><input
                            class="pt-detail-value pt-detail-input pt-detail-delay pt-detail-total-delay"
                            id="dd-total-delayed" name="total_days_delayed" type="text" placeholder="—" readonly></div>
                </div>
                <div id="ptDetailSaveResult"
                    style="display:none;margin-top:.75rem;padding:.6rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;">
                </div>
            </div>
            <div class="pt-modal-actions" style="justify-content:space-between;gap:.75rem;">
                <button class="pt-modal-btn-cancel" onclick="closePTDetail()"><i class="bi bi-x-circle"></i>
                    Close</button>
                <div style="display:flex;gap:.65rem;align-items:center;">
                    <button class="pt-btn pt-btn-outline" id="ptDetailEditBtn" onclick="togglePTEdit()"
                        style="padding:.5rem 1.1rem;font-size:.85rem;"><i class="bi bi-pencil-square"></i> Edit</button>
                    <button class="pt-btn pt-btn-success" id="ptDetailSaveBtn" onclick="savePTDetail()"
                        style="display:none;padding:.5rem 1.1rem;font-size:.85rem;"><i
                            class="bi bi-check-circle-fill"></i> Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        /* ── Flatpickr theme integration ── */
        .flatpickr-calendar {
            font-family: 'Nunito', sans-serif;
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .35);
            border: 1px solid rgba(255, 255, 255, .08);
            background: #1e293b;
            color: #e2e8f0;
        }

        body.light-theme .flatpickr-calendar {
            background: #fff;
            color: #1e293b;
            border-color: #e2e8f0;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .12);
        }

        .flatpickr-months {
            background: #0f172a;
            border-radius: 10px 10px 0 0;
            padding: 4px 0;
        }

        body.light-theme .flatpickr-months {
            background: #1d4ed8;
        }

        .flatpickr-month,
        .flatpickr-current-month,
        .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year {
            color: #f1f5f9 !important;
            fill: #f1f5f9 !important;
        }

        .flatpickr-prev-month svg,
        .flatpickr-next-month svg {
            fill: #f1f5f9 !important;
        }

        .flatpickr-prev-month:hover svg,
        .flatpickr-next-month:hover svg {
            fill: #60a5fa !important;
        }

        .flatpickr-weekday {
            color: #64748b;
            font-weight: 700;
            font-size: .72rem;
        }

        body.light-theme .flatpickr-weekday {
            color: #94a3b8;
        }

        .flatpickr-day {
            color: #cbd5e1;
            border-radius: 6px;
        }

        body.light-theme .flatpickr-day {
            color: #1e293b;
        }

        .flatpickr-day:hover {
            background: #1d4ed8;
            color: #fff;
            border-color: transparent;
        }

        .flatpickr-day.selected,
        .flatpickr-day.selected:hover {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            font-weight: 700;
        }

        .flatpickr-day.today {
            border-color: #3b82f6;
            color: #60a5fa;
            font-weight: 700;
        }

        body.light-theme .flatpickr-day.today {
            color: #1d4ed8;
            border-color: #1d4ed8;
        }

        .flatpickr-day.flatpickr-disabled,
        .flatpickr-day.prevMonthDay,
        .flatpickr-day.nextMonthDay {
            color: #334155 !important;
            opacity: .45;
        }

        body.light-theme .flatpickr-day.flatpickr-disabled,
        body.light-theme .flatpickr-day.prevMonthDay,
        body.light-theme .flatpickr-day.nextMonthDay {
            color: #cbd5e1 !important;
        }

        .flatpickr-monthDropdown-months {
            background: #1e293b !important;
        }

        body.light-theme .flatpickr-monthDropdown-months {
            background: #fff !important;
            color: #1e293b !important;
        }

        /* Calendar icon wrapper */
        .pt-date-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .pt-date-wrap .pt-cal-icon {
            position: absolute;
            right: 9px;
            pointer-events: none;
            color: #64748b;
            font-size: .85rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .pt-date-wrap input {
            padding-right: 2rem !important;
        }

        /* When in edit mode, show cursor pointer on date fields */
        .pt-input-active[data-datepicker] {
            cursor: pointer !important;
        }
    </style>
    <script>
        // ── Flatpickr date picker setup ──────────────────────────────
        // Format used in DB: "17-Jan-2025"
        const DATE_FORMAT = 'j-M-Y'; // Flatpickr format for "17-Jan-2025"

        // Parse the DB string format into a Date for Flatpickr defaultDate
        function parseDbDate(str) {
            if (!str || str.trim() === '' || str.trim() === '—') return null;
            const months = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };
            const parts = str.trim().split('-');
            if (parts.length !== 3) return null;
            const d = parseInt(parts[0]), m = months[parts[1]], y = parseInt(parts[2]);
            if (isNaN(d) || m === undefined || isNaN(y)) return null;
            return new Date(y, m, d);
        }

        // All date picker instances keyed by element id
        const dpInstances = {};

        function initDatePickers() {
            document.querySelectorAll('[data-datepicker]').forEach(el => {
                if (dpInstances[el.id]) return; // already inited

                // Wrap in a relative container for the icon
                if (!el.closest('.pt-date-wrap')) {
                    const wrap = document.createElement('div');
                    wrap.className = 'pt-date-wrap';
                    el.parentNode.insertBefore(wrap, el);
                    wrap.appendChild(el);
                    const icon = document.createElement('i');
                    icon.className = 'bi bi-calendar3 pt-cal-icon';
                    wrap.appendChild(icon);
                }

                const fp = flatpickr(el, {
                    dateFormat: DATE_FORMAT,
                    allowInput: true,
                    disableMobile: true,
                    clickOpens: false, // we control open manually
                    parseDate(dateStr) { return parseDbDate(dateStr); },
                    onReady(_, __, instance) {
                        // Open calendar when clicking the icon or when field is active (edit mode)
                        const icon = instance.element.parentElement.querySelector('.pt-cal-icon');
                        if (icon) {
                            icon.style.pointerEvents = 'auto';
                            icon.style.cursor = 'pointer';
                            icon.addEventListener('click', () => {
                                if (!instance.element.readOnly) instance.toggle();
                            });
                        }
                        instance.element.addEventListener('click', () => {
                            if (!instance.element.readOnly) instance.open();
                        });
                    },
                });
                dpInstances[el.id] = fp;
            });
        }

        // Re-open capability: when edit mode toggles, update clickOpens state
        const _origApplyEditMode = window.applyEditMode;

        document.addEventListener('DOMContentLoaded', () => {
            initDatePickers();
        });
    </script>
    <script>
        // ── Live clock ──────────────────────────────────────────────
        (function () {
            const c = document.getElementById('ptClock'), d = document.getElementById('ptDate');
            const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            function tick() {
                const n = new Date(); let h = n.getHours(), m = n.getMinutes(), s = n.getSeconds();
                const ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
                c.textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0') + ' ' + ap;
                d.textContent = days[n.getDay()] + ', ' + months[n.getMonth()] + ' ' + n.getDate() + ' ' + n.getFullYear();
            }
            tick(); setInterval(tick, 1000);
        })();

        // ── Theme toggle ─────────────────────────────────────────────
        (function () {
            const body = document.body;
            const saved = localStorage.getItem('pr_tracker_theme') || 'dark';
            body.classList.add(saved === 'light' ? 'light-theme' : 'dark-theme');
            const btn = document.getElementById('ptThemeToggle');
            const icon = btn.querySelector('i');
            const applyIcon = () => {
                const isLight = body.classList.contains('light-theme');
                icon.className = isLight ? 'bi bi-moon-fill' : 'bi bi-sun-fill';
                btn.title = isLight ? 'Switch to dark mode' : 'Switch to light mode';
            };
            applyIcon();
            btn.addEventListener('click', () => {
                const goLight = body.classList.contains('dark-theme');
                body.classList.remove('dark-theme', 'light-theme');
                body.classList.add(goLight ? 'light-theme' : 'dark-theme');
                localStorage.setItem('pr_tracker_theme', goLight ? 'light' : 'dark');
                applyIcon();
            });
        })();

        // ══════════════════════════════════════════════
        //  ADD PR MODAL
        // ══════════════════════════════════════════════
        function openAddPRModal() {
            // Reset form
            document.querySelectorAll('#ptAddPRBody .pt-add-input').forEach(i => {
                if (i.hasAttribute('data-datepicker') && dpInstances[i.id]) {
                    dpInstances[i.id].clear();
                } else {
                    i.value = '';
                }
            });
            document.getElementById('ptAddResult').style.display = 'none';
            const btn = document.getElementById('ptAddSubmitBtn');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-circle-fill"></i> Add Procurement Record';
            document.getElementById('ptAddPRModal').classList.add('open');
            // scroll to top
            document.getElementById('ptAddPRBody').scrollTop = 0;
            // Init date pickers (safe to call multiple times)
            setTimeout(() => { initDatePickers(); document.getElementById('add-pr-number').focus(); }, 120);
        }

        function closeAddPRModal() {
            document.getElementById('ptAddPRModal').classList.remove('open');
        }

        document.getElementById('ptAddPRModal').addEventListener('click', function (e) {
            if (e.target === this) closeAddPRModal();
        });

        function submitAddPR() {
            const prNum = document.getElementById('add-pr-number').value.trim();
            if (!prNum) {
                const inp = document.getElementById('add-pr-number');
                inp.focus();
                inp.style.borderColor = '#dc2626';
                inp.style.boxShadow = '0 0 0 3px rgba(220,38,38,.15)';
                setTimeout(() => { inp.style.borderColor = ''; inp.style.boxShadow = ''; }, 2000);
                return;
            }

            const btn = document.getElementById('ptAddSubmitBtn');
            const result = document.getElementById('ptAddResult');
            btn.disabled = true;
            btn.innerHTML = '<span class="pt-spin bi bi-arrow-clockwise"></span> Saving…';

            const fd = new FormData();
            fd.append('action', 'add');
            document.querySelectorAll('#ptAddPRBody .pt-add-input[name]').forEach(inp => {
                fd.append(inp.name, inp.value.trim());
            });

            fetch('procurement_tracking_action.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    result.style.display = 'block';
                    if (data.success) {
                        result.style.cssText = 'display:block;background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:.7rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;';
                        result.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>' + data.message;
                        btn.innerHTML = '<i class="bi bi-check-circle"></i> Added!';
                        setTimeout(() => { closeAddPRModal(); location.reload(); }, 1500);
                    } else {
                        result.style.cssText = 'display:block;background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;padding:.7rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;';
                        result.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + data.message;
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-plus-circle-fill"></i> Add Procurement Record';
                    }
                })
                .catch(() => {
                    result.style.cssText = 'display:block;background:#fee2e2;color:#7f1d1d;padding:.7rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;';
                    result.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>Network error. Please try again.';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-plus-circle-fill"></i> Add Procurement Record';
                });
        }

        // ── Upload modal ─────────────────────────────────────────────
        let selectedFile = null;
        function closeUploadModal() {
            document.getElementById('ptUploadModal').classList.remove('open');
            selectedFile = null;
            document.getElementById('ptFileInput').value = '';
            document.getElementById('ptFileSelected').classList.remove('show');
            document.getElementById('ptProgressWrap').classList.remove('show');
            document.getElementById('ptProgressFill').style.width = '0%';
            document.getElementById('ptSubmitBtn').disabled = true;
            document.getElementById('ptUploadResult').style.display = 'none';
        }
        document.getElementById('ptUploadModal').addEventListener('click', function (e) { if (e.target === this) closeUploadModal(); });
        const dz = document.getElementById('ptDropzone');
        dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
        dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
        dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('dragover'); const f = e.dataTransfer.files[0]; if (f) applyFile(f); });
        function handleFileSelect(input) { if (input.files[0]) applyFile(input.files[0]); }
        function applyFile(f) {
            selectedFile = f;
            document.getElementById('ptFileName').textContent = f.name;
            document.getElementById('ptFileSize').textContent = (f.size / 1024 / 1024).toFixed(2) + ' MB';
            document.getElementById('ptFileSelected').classList.add('show');
            document.getElementById('ptSubmitBtn').disabled = false;
            document.getElementById('ptUploadResult').style.display = 'none';
        }
        function submitUpload() {
            if (!selectedFile) return;
            const btn = document.getElementById('ptSubmitBtn');
            const pw = document.getElementById('ptProgressWrap');
            const fill = document.getElementById('ptProgressFill');
            const pLabel = document.getElementById('ptProgressLabel');
            const pPct = document.getElementById('ptProgressPct');
            const result = document.getElementById('ptUploadResult');
            btn.disabled = true; btn.innerHTML = '<span class="pt-spin bi bi-arrow-clockwise"></span> Uploading…';
            pw.classList.add('show'); result.style.display = 'none';
            const fd = new FormData();
            const fileExt = selectedFile.name.split('.').pop().toLowerCase();
            const isCSV = fileExt === 'csv';
            fd.append(isCSV ? 'csv_file' : 'xlsx_file', selectedFile);
            const xhr = new XMLHttpRequest();
            const endpoint = isCSV ? 'sync_csv.php' : 'sync_procurement_tracking.php';
            xhr.open('POST', endpoint);
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) { const pct = Math.round((e.loaded / e.total) * 85); fill.style.width = pct + '%'; pPct.textContent = pct + '%'; pLabel.textContent = 'Uploading file…'; }
            };
            xhr.onload = () => {
                fill.style.width = '100%'; pPct.textContent = '100%'; pLabel.textContent = 'Processing…';
                try {
                    const data = JSON.parse(xhr.responseText); result.style.display = 'block';
                    if (data.success) { result.style.cssText = 'display:block;background:#dcfce7;border:1px solid #86efac;color:#14532d;margin-top:1rem;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;font-weight:600;'; result.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>' + data.message; btn.innerHTML = '<i class="bi bi-check-circle"></i> Done!'; setTimeout(() => { closeUploadModal(); location.reload(); }, 1800); }
                    else { result.style.cssText = 'display:block;background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;margin-top:1rem;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;font-weight:600;'; result.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + data.message; btn.disabled = false; btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Upload & Sync'; }
                } catch (e) { result.style.cssText = 'display:block;background:#fee2e2;color:#7f1d1d;margin-top:1rem;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;font-weight:600;'; result.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>Unexpected server response.'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Upload & Sync'; }
            };
            xhr.onerror = () => { result.style.cssText = 'display:block;background:#fee2e2;color:#7f1d1d;margin-top:1rem;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;font-weight:600;'; result.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>Network error. Please try again.'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Upload & Sync'; };
            xhr.send(fd);
        }

        // Press "/" to focus search, Escape to close modals
        document.addEventListener('keydown', e => {
            if (e.key === '/' && document.activeElement.tagName !== 'INPUT') { e.preventDefault(); document.querySelector('.pt-search-wrap input')?.focus(); }
            if (e.key === 'Escape') { closePTDetail(); closeAddPRModal(); }
        });

        // ── Detail Modal ─────────────────────────────────────────────
        let ptEditMode = false;
        function v(val) { return (val && val.trim() !== '') ? val.trim() : ''; }
        function setDelay(id, val) {
            const el = document.getElementById(id); if (!el) return;
            val = val ? val.trim() : ''; el.value = val;
            el.classList.remove('dd-early', 'dd-ok', 'dd-late');
            if (!val || val.toUpperCase() === 'N/A') return;
            const n = parseFloat(val); if (isNaN(n)) return;
            if (n < 0) el.classList.add('dd-early'); else if (n === 0) el.classList.add('dd-ok'); else el.classList.add('dd-late');
        }
        function openPTDetail(row) {
            const d = row.dataset;
            document.getElementById('ptDetailTitle').textContent = 'PR Details \u2014 ' + (d.pr || '');
            document.getElementById('dd-pr').textContent = d.pr || '\u2014';
            document.getElementById('dd-pr-hidden').value = d.pr || '';
            const map = { 'dd-particulars': d.particulars, 'dd-end-user': d.endUser, 'dd-mode': d.mode, 'dd-receipt-event': d.receiptEvent, 'dd-receipt-received': d.receiptReceived, 'dd-receipt-expected': d.receiptExpected, 'dd-proc-numbering': d.procNumbering, 'dd-proc-endorsed': d.procEndorsed, 'dd-proc-earmarked': d.procEarmarked, 'dd-proc-duration': d.procDuration, 'dd-rfq-preparation': d.rfqPreparation, 'dd-rfq-approved': d.rfqApproved, 'dd-rfq-duration': d.rfqDuration, 'dd-apq-posting': d.apqPosting, 'dd-apq-preparation': d.apqPreparation, 'dd-apq-return': d.apqReturn, 'dd-apq-approved': d.apqApproved, 'dd-apq-duration': d.apqDuration, 'dd-noa-preparation': d.noaPreparation, 'dd-noa-approved': d.noaApproved, 'dd-noa-acknowledged': d.noaAcknowledged, 'dd-noa-duration': d.noaDuration, 'dd-po-ors': d.poOrs, 'dd-po-smd': d.poSmd, 'dd-po-caf': d.poCaf, 'dd-po-cafed': d.poCafed, 'dd-po-hope': d.poHope, 'dd-po-approved': d.poApproved, 'dd-po-acknowledged': d.poAcknowledged, 'dd-po-duration': d.poDuration, 'dd-total-duration': d.totalDuration };
            for (const [id, val] of Object.entries(map)) {
                const el = document.getElementById(id);
                if (!el) continue;
                const cleanVal = v(val);
                if (el.hasAttribute('data-datepicker') && dpInstances[id]) {
                    // Use Flatpickr API to set date so internal state stays in sync
                    const parsed = parseDbDate(cleanVal);
                    dpInstances[id].setDate(parsed || null, false);
                    if (!parsed) el.value = cleanVal; // keep raw text if unparseable
                } else {
                    el.value = cleanVal;
                }
            }
            setDelay('dd-receipt-delayed', d.receiptDelayed); setDelay('dd-proc-delayed', d.procDelayed); setDelay('dd-rfq-delayed', d.rfqDelayed); setDelay('dd-apq-delayed', d.apqDelayed); setDelay('dd-noa-delayed', d.noaDelayed); setDelay('dd-po-delayed', d.poDelayed); setDelay('dd-total-delayed', d.totalDelayed);
            ptEditMode = false; applyEditMode(false);
            document.getElementById('ptDetailSaveResult').style.display = 'none';
            document.getElementById('ptDetailModal').classList.add('open');
        }
        function applyEditMode(on) {
            ptEditMode = on;
            document.querySelectorAll('.pt-detail-input').forEach(inp => {
                inp.readOnly = !on;
                inp.classList.toggle('pt-input-active', on);
                // For date pickers: close any open calendar when going read-only
                if (!on && inp.hasAttribute('data-datepicker') && dpInstances[inp.id]) {
                    dpInstances[inp.id].close();
                }
            });
            const editBtn = document.getElementById('ptDetailEditBtn'); const saveBtn = document.getElementById('ptDetailSaveBtn');
            if (on) { editBtn.innerHTML = '<i class="bi bi-x-lg"></i> Cancel'; editBtn.classList.add('pt-btn-cancel-edit'); saveBtn.style.display = ''; }
            else { editBtn.innerHTML = '<i class="bi bi-pencil-square"></i> Edit'; editBtn.classList.remove('pt-btn-cancel-edit'); saveBtn.style.display = 'none'; }
        }
        function togglePTEdit() { applyEditMode(!ptEditMode); if (!ptEditMode) document.getElementById('ptDetailSaveResult').style.display = 'none'; }
        function savePTDetail() {
            const saveBtn = document.getElementById('ptDetailSaveBtn'); const result = document.getElementById('ptDetailSaveResult');
            saveBtn.disabled = true; saveBtn.innerHTML = '<span class="pt-spin bi bi-arrow-clockwise"></span> Saving\u2026';
            const fd = new FormData(); fd.append('action', 'update'); fd.append('pr_number', document.getElementById('dd-pr-hidden').value);
            document.querySelectorAll('.pt-detail-input[name]').forEach(inp => fd.append(inp.name, inp.value));
            fetch('procurement_tracking_action.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(data => {
                    result.style.display = 'block';
                    if (data.success) { result.style.cssText = 'display:block;background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:.6rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;margin-top:.75rem;'; result.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>' + data.message; applyEditMode(false);['dd-receipt-delayed', 'dd-proc-delayed', 'dd-rfq-delayed', 'dd-apq-delayed', 'dd-noa-delayed', 'dd-po-delayed', 'dd-total-delayed'].forEach(id => { const el = document.getElementById(id); if (el) setDelay(id, el.value); }); const prNum = document.getElementById('dd-pr-hidden').value; document.querySelectorAll('.pt-row-clickable').forEach(r => { if (r.dataset.pr === prNum) { document.querySelectorAll('.pt-detail-input[name]').forEach(inp => { const key = inp.name.replace(/_([a-z])/g, (_, c) => c.toUpperCase()); r.dataset[key] = inp.value; }); } }); }
                    else { result.style.cssText = 'display:block;background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;padding:.6rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;margin-top:.75rem;'; result.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + data.message; }
                }).catch(() => { result.style.cssText = 'display:block;background:#fee2e2;color:#7f1d1d;padding:.6rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;margin-top:.75rem;'; result.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>Network error. Please try again.'; })
                .finally(() => { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Save Changes'; });
        }
        function closePTDetail() { document.getElementById('ptDetailModal').classList.remove('open'); applyEditMode(false); }
        document.getElementById('ptDetailModal').addEventListener('click', function (e) { if (e.target === this) closePTDetail(); });
    </script>
</body>

</html>