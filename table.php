<?php
require 'config/database.php';

// ===== PAGINATION =====
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;


// ===== SEARCH =====
$search_query = isset($_GET['search_query']) ? trim($_GET['search_query']) : '';
$show_overdue = isset($_GET['overdue']) ? true : false;
$bucket = isset($_GET['bucket']) ? strtolower(trim((string) $_GET['bucket'])) : '';

$params = [];
$base_conditions = [];

// ===== HANDLE OVERDUE FILTER =====
if ($show_overdue) {
    $base_conditions[] = "paid_date IS NULL AND status != 'CANCELLED' AND date_endorsement IS NOT NULL";
}

// ===== UNIFIED SEARCH (searches both PR# and Processor) =====
if ($search_query !== '') {
    $base_conditions[] = "(pr_number LIKE :search_query OR processor LIKE :search_query)";
    $params['search_query'] = "%$search_query%";
}

// Build WHERE clause for charts / summary counts (base filters)
$base_where = "";
if (!empty($base_conditions)) {
    $base_where = "WHERE " . implode(" AND ", $base_conditions);
}

// Build WHERE clause for table (base filters + bucket drilldown)
$table_conditions = $base_conditions;
if ($bucket !== '') {
    if ($bucket === 'overdue') {
        $table_conditions[] = "paid_date IS NULL AND status != 'CANCELLED' AND date_endorsement IS NOT NULL AND DATEDIFF(CURDATE(), date_endorsement) > 3";
    } elseif ($bucket === 'pending') {
        $table_conditions[] = "paid_date IS NULL AND status != 'CANCELLED' AND (date_endorsement IS NULL OR DATEDIFF(CURDATE(), date_endorsement) <= 3)";
    } elseif ($bucket === 'paid') {
        $table_conditions[] = "paid_date IS NOT NULL";
    } elseif ($bucket === 'cancelled') {
        $table_conditions[] = "status = 'CANCELLED'";
    }
}

$table_where = "";
if (!empty($table_conditions)) {
    $table_where = "WHERE " . implode(" AND ", $table_conditions);
}

// ===== SORTING =====
$allowed_sort_columns = ['id', 'pr_number', 'title', 'processor', 'supplier', 'end_user', 'status', 'date_endorsement'];
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sort_columns) ? $_GET['sort'] : 'pr_number';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) ? strtoupper($_GET['order']) : 'ASC';


// ===== COUNT TOTAL ROWS (WITH SEARCH APPLIED) =====
$stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_requests $table_where");
$stmt->execute($params);
$total_prs = $stmt->fetchColumn();
$total_pages = ceil($total_prs / $items_per_page);


// ===== FETCH PAGINATED PRS =====
$stmt = $pdo->prepare("
    SELECT * FROM purchase_requests
    $table_where
    ORDER BY $sort $order
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $val) {
    $stmt->bindValue(":$key", $val, PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$prs = $stmt->fetchAll();

// Helper function to generate sortable header links
function sortLink($column, $label)
{
    global $sort, $order, $search_query, $show_overdue, $bucket;

    $current_order = ($sort === $column) ? $order : 'ASC';
    $next_order = ($current_order === 'ASC') ? 'DESC' : 'ASC';

    $url_params = "sort=$column&order=$next_order";
    if ($search_query !== '')
        $url_params .= "&search_query=" . urlencode($search_query);
    if ($show_overdue)
        $url_params .= "&overdue=true";
    if ($bucket !== '')
        $url_params .= "&bucket=" . urlencode($bucket);

    $icon = '';
    if ($sort === $column) {
        $icon = ($order === 'ASC') ? ' <span style="margin-left: 0.5rem; color: #5c5f61; font-weight: bold;">▲</span>' : ' <span style="margin-left: 0.5rem; color: #5c5f61; font-weight: bold;">▼</span>';
    }

    $tooltip = 'Sort by ' . $label . ' (' . ($sort === $column ? 'Current: ' . $order : 'Not sorted') . ')';
    return '<a href="?' . $url_params . '" title="' . $tooltip . '" style="text-decoration: none; color: inherit; cursor: pointer; display: inline-flex; align-items: center;">' . $label . $icon . '</a>';
}

// Helper function to generate pagination URLs with sort parameters
function pageLink($page_num)
{
    global $sort, $order, $search_query, $show_overdue, $bucket;

    $url_params = "page=$page_num&sort=$sort&order=$order";
    if ($search_query !== '')
        $url_params .= "&search_query=" . urlencode($search_query);
    if ($show_overdue)
        $url_params .= "&overdue=true";
    if ($bucket !== '')
        $url_params .= "&bucket=" . urlencode($bucket);

    return $url_params;
}

// ===== GET PROCESSOR SUMMARY (if search is active and matches processor) =====
$processor_summary = null;

if ($search_query !== '') {
    $stmt = $pdo->prepare("
        SELECT processor, COUNT(*) as pr_count, 
        SUM(CASE WHEN paid_date IS NOT NULL THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled_count
        FROM purchase_requests 
        WHERE processor LIKE :query OR pr_number LIKE :query
        GROUP BY processor
    ");
    $stmt->execute(['query' => '%' . $search_query . '%']);
    $processor_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== GET ALL PR NUMBERS FOR DROPDOWN =====
$stmt = $pdo->prepare("SELECT DISTINCT pr_number FROM purchase_requests ORDER BY pr_number DESC");
$stmt->execute();
$all_pr_numbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ===== GET OVERALL BADGE COUNTS (ALWAYS SHOW) =====
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_prs,
        SUM(CASE WHEN paid_date IS NOT NULL THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN paid_date IS NULL AND status != 'CANCELLED' THEN 1 ELSE 0 END) as pending_count
    FROM purchase_requests
");
$stmt->execute();
$overall_counts = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== OVERDUE COUNT (FROM ALL RECORDS OR FILTERED) =====
$overdue_count = 0;
$today = date("Y-m-d");

$stmt = $pdo->prepare("
    SELECT id, date_endorsement, paid_date, status 
    FROM purchase_requests 
    WHERE paid_date IS NULL 
    AND status != 'CANCELLED'
    AND date_endorsement IS NOT NULL
");
$stmt->execute();
$all_pending_prs = $stmt->fetchAll();

foreach ($all_pending_prs as $pr) {
    $diff = (strtotime($today) - strtotime($pr['date_endorsement'])) / 86400;
    if ($diff > 3) {
        $overdue_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PR Table — PSA</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Barlow+Condensed:wght@500;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js@10/public/assets/styles/choices.min.css">
    <script src="https://cdn.jsdelivr.net/npm/choices.js@10/public/assets/scripts/choices.min.js"></script>
    <link href="dashboard-styles.css" rel="stylesheet">
    <style>
        /* ── Layout ── */
        .tv-main {
            margin-left: 250px;
            flex: 1;
            padding: 1.75rem 2rem 3rem;
            width: calc(100% - 250px);
            min-width: 0;
        }

        @media (max-width: 768px) {
            .tv-main {
                margin-left: 220px;
                padding: 1.25rem;
                width: calc(100% - 220px);
            }
        }

        @media (max-width: 480px) {
            .tv-main {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
        }

        /* ── Toolbar ── */
        .tv-toolbar {
            display: flex;
            gap: 0.65rem;
            margin-bottom: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .tv-search-wrap {
            position: relative;
            flex: 1;
            min-width: 220px;
            max-width: 360px;
        }

        .tv-search-wrap i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.85rem;
            pointer-events: none;
        }

        .tv-search-wrap input {
            width: 100%;
            padding: 0.55rem 0.75rem 0.55rem 2.15rem;
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            font-size: 0.85rem;
            color: var(--text-dark);
            background: #f0f7ff;
            font-family: 'Nunito', sans-serif;
            font-weight: 600;
            outline: none;
            transition: all 0.25s;
        }

        .tv-search-wrap input::placeholder {
            color: var(--text-muted);
            font-weight: 400;
        }

        .tv-search-wrap input:focus {
            border-color: var(--blue-mid);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(33, 150, 243, .15);
        }

        .tv-btn {
            padding: 0.55rem 1.1rem;
            border: none;
            border-radius: 8px;
            font-family: 'Nunito', sans-serif;
            font-size: 0.85rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.25s;
            text-decoration: none;
            white-space: nowrap;
        }

        .tv-btn-primary {
            background: linear-gradient(135deg, var(--blue-mid) 0%, var(--blue-primary) 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(21, 101, 192, .25);
        }

        .tv-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(21, 101, 192, .35);
            color: #fff;
        }

        .tv-btn-success {
            background: linear-gradient(135deg, #2e7d32 0%, #1b5e20 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(46, 125, 50, .25);
        }

        .tv-btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(46, 125, 50, .35);
            color: #fff;
        }

        .tv-btn-danger {
            background: linear-gradient(135deg, #c62828 0%, #7f0000 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(198, 40, 40, .25);
        }

        .tv-btn-danger:hover {
            transform: translateY(-1px);
            color: #fff;
        }

        .tv-btn-outline {
            background: #f0f7ff;
            color: var(--text-muted);
            border: 1.5px solid var(--border-light) !important;
        }

        .tv-btn-outline:hover {
            background: #fee2e2;
            border-color: #fca5a5 !important;
            color: #b91c1c;
        }

        .tv-filter-chips {
            display: flex;
            gap: 0.4rem;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 0.85rem;
        }

        .tv-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.75rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 800;
            font-family: 'Barlow Condensed', sans-serif;
            letter-spacing: 0.4px;
            text-decoration: none;
            border: 1.5px solid transparent;
            transition: all 0.2s;
            cursor: pointer;
        }

        .tv-chip-all {
            background: #dbeafe;
            color: #1e40af;
            border-color: #93c5fd;
        }

        .tv-chip-overdue {
            background: #fee2e2;
            color: #7f1d1d;
            border-color: #fca5a5;
        }

        .tv-chip-pending {
            background: #fef9c3;
            color: #78350f;
            border-color: #fde68a;
        }

        .tv-chip-paid {
            background: #dcfce7;
            color: #14532d;
            border-color: #86efac;
        }

        .tv-chip-cancel {
            background: #f3f4f6;
            color: #374151;
            border-color: #d1d5db;
        }

        .tv-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, .1);
        }

        .tv-chip.active {
            box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
            font-weight: 900;
        }

        /* ── Alert ── */
        .tv-alert {
            border-radius: 10px;
            border: 1.5px solid;
            padding: 0.7rem 1rem;
            margin-bottom: 0.85rem;
            font-weight: 600;
            font-size: 0.85rem;
            font-family: 'Nunito', sans-serif;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tv-alert-info {
            background: rgba(21, 101, 192, .08);
            border-color: rgba(21, 101, 192, .3);
            color: #0d47a1;
        }

        .tv-alert-warn {
            background: rgba(255, 152, 0, .08);
            border-color: rgba(255, 152, 0, .3);
            color: #e65100;
        }

        /* ── Table card ── */
        .tv-table-card {
            background: var(--card-bg);
            border: 1.5px solid var(--border-light);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: box-shadow 0.25s;
        }

        .tv-table-card:hover {
            box-shadow: var(--shadow-md);
        }

        .tv-table-scroll {
            overflow-x: auto;
            max-height: 65vh;
            overflow-y: auto;
        }

        .tv-table-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .tv-table-scroll::-webkit-scrollbar-track {
            background: #e8f0fe;
            border-radius: 10px;
        }

        .tv-table-scroll::-webkit-scrollbar-thumb {
            background: #90caf9;
            border-radius: 10px;
        }

        .tv-table-scroll::-webkit-scrollbar-thumb:hover {
            background: #42a5f5;
        }

        /* ── Table ── */
        .tv-table {
            border-collapse: collapse;
            font-size: 0.8rem;
            width: 100%;
            min-width: 1100px;
            font-family: 'Nunito', sans-serif;
        }

        .tv-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--blue-primary);
            color: #fff;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.65rem 0.85rem;
            white-space: nowrap;
            border-right: 1px solid rgba(255, 255, 255, .15);
            border-bottom: 2px solid rgba(0, 0, 0, .2);
        }

        .tv-table thead th a {
            color: #fff !important;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .tv-table thead th a:hover {
            opacity: 0.85;
        }

        .tv-table tbody td {
            padding: 0.5rem 0.85rem;
            border-right: 1px solid #e3f0ff;
            border-bottom: 1px solid #e3f0ff;
            color: var(--text-dark);
            vertical-align: middle;
            transition: background 0.12s;
        }

        .tv-table tbody tr {
            cursor: pointer;
        }

        .tv-table tbody tr:hover td {
            background: #e8f4fd !important;
        }

        .tv-table tbody tr:nth-child(even) td {
            background: #f5f9ff;
        }

        /* Row status colors */
        .tv-row-cancelled td {
            background: rgba(198, 40, 40, .06) !important;
        }

        .tv-row-cancelled:hover td {
            background: rgba(198, 40, 40, .12) !important;
        }

        .tv-row-paid td {
            background: rgba(46, 125, 50, .06) !important;
        }

        .tv-row-paid:hover td {
            background: rgba(46, 125, 50, .12) !important;
        }

        .tv-row-overdue td {
            background: rgba(255, 152, 0, .07) !important;
        }

        .tv-row-overdue:hover td {
            background: rgba(255, 152, 0, .14) !important;
        }

        /* PR# cell */
        .tv-pr-link {
            color: var(--blue-mid);
            font-weight: 800;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            text-decoration: underline dotted;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 6px;
            border-radius: 5px;
            transition: background .15s;
        }

        .tv-pr-link:hover {
            background: #dbeafe;
        }

        /* Status badge */
        .tv-status {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.65rem;
            border-radius: 99px;
            font-size: 0.7rem;
            font-weight: 800;
            font-family: 'Barlow Condensed', sans-serif;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .tv-status-cancelled {
            background: rgba(198, 40, 40, .12);
            color: #7f0000;
            border: 1px solid rgba(198, 40, 40, .25);
        }

        .tv-status-paid {
            background: rgba(46, 125, 50, .12);
            color: #1b5e20;
            border: 1px solid rgba(46, 125, 50, .25);
        }

        .tv-status-pending {
            background: rgba(255, 152, 0, .12);
            color: #e65100;
            border: 1px solid rgba(255, 152, 0, .25);
        }

        .tv-status-overdue {
            background: rgba(198, 40, 40, .10);
            color: #b71c1c;
            border: 1px solid rgba(198, 40, 40, .22);
        }

        .tv-status-default {
            background: #f0f7ff;
            color: var(--blue-mid);
            border: 1px solid #bbdefb;
        }

        /* ── Pagination ── */
        .tv-pag-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.9rem 1.3rem;
            border-top: 1.5px solid var(--border-light);
            background: #f5f9ff;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tv-pag-info {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 700;
            font-family: 'Nunito', sans-serif;
        }

        .tv-pag-links {
            display: flex;
            gap: 0.3rem;
            align-items: center;
        }

        .tv-pag-links a,
        .tv-pag-links span {
            padding: 0.3rem 0.65rem;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 800;
            border: 1.5px solid var(--border-light);
            text-decoration: none;
            color: var(--blue-mid);
            font-family: 'Nunito', sans-serif;
            transition: all 0.2s;
        }

        .tv-pag-links a:hover {
            background: #dbeafe;
            border-color: var(--blue-mid);
            transform: translateY(-1px);
        }

        .tv-pag-links span.on {
            background: linear-gradient(135deg, var(--blue-mid) 0%, var(--blue-primary) 100%);
            color: #fff;
            border-color: var(--blue-mid);
            box-shadow: 0 2px 8px rgba(21, 101, 192, .25);
        }

        .tv-pag-links span.off {
            color: #cfd8dc;
            cursor: default;
        }

        /* ── Footer ── */
        .tv-foot {
            margin-top: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 1rem;
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 600;
            font-family: 'Nunito', sans-serif;
        }

        .tv-live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--tile-green);
            display: inline-block;
            box-shadow: 0 0 6px var(--tile-green);
            animation: tvPulse 2s ease-in-out infinite;
        }

        @keyframes tvPulse {

            0%,
            100% {
                opacity: 1;
                box-shadow: 0 0 6px #2e7d32;
            }

            50% {
                opacity: .4;
                box-shadow: 0 0 2px #2e7d32;
            }
        }

        /* ── Clock ── */
        .tv-clock-time {
            color: #e2e8f0;
        }

        .tv-clock-date {
            color: rgba(255, 255, 255, .45);
        }

        body.light-theme .tv-clock-time {
            color: #1f2937 !important;
        }

        body.light-theme .tv-clock-date {
            color: #6b7280 !important;
        }

        /* ── Dark theme ── */
        body.dark-theme {
            --text-muted: #ffffff;
        }

        body.dark-theme .tv-search-wrap input {
            background: #1e3a5f;
            border-color: #2a5080;
            color: #e8f0fe;
        }

        body.dark-theme .tv-search-wrap input:focus {
            background: #254a70;
            border-color: #42a5f5;
        }

        body.dark-theme .tv-btn-outline {
            background: #1e3a5f;
            border-color: #2a5080 !important;
            color: #90caf9;
        }

        body.dark-theme .tv-table-card {
            background: #0f1f3d;
            border-color: #1e3a5f;
        }

        body.dark-theme .tv-table thead th {
            background: #0d2a50;
        }

        body.dark-theme .tv-table tbody td {
            border-color: #1e3a5f;
            color: #ffffff;
        }

        body.dark-theme .tv-table tbody tr:hover td {
            background: #1a3060 !important;
        }

        body.dark-theme .tv-table tbody tr:nth-child(even) td {
            background: #0d1f3a;
        }

        body.dark-theme .tv-row-cancelled td {
            background: rgba(198, 40, 40, .12) !important;
            color: #ffffff !important;
        }

        body.dark-theme .tv-row-paid td {
            background: rgba(46, 125, 50, .12) !important;
            color: #ffffff !important;
        }

        body.dark-theme .tv-row-overdue td {
            background: rgba(255, 152, 0, .10) !important;
            color: #ffffff !important;
        }

        body.dark-theme .tv-table tbody tr td {
            color: #ffffff;
        }

        body.dark-theme .tv-pag-bar {
            background: #0d1b2a;
            border-top-color: #1e3a5f;
        }

        body.dark-theme .tv-pag-info {
            color: #90caf9;
        }

        body.dark-theme .tv-pag-links a,
        body.dark-theme .tv-pag-links span {
            border-color: #2a5080;
            color: #90caf9;
        }

        body.dark-theme .tv-pag-links a:hover {
            background: #1e3a5f;
            border-color: #42a5f5;
        }

        body.dark-theme .tv-foot {
            color: #90caf9;
        }

        body.dark-theme .tv-alert-info {
            background: rgba(21, 101, 192, .15);
            border-color: rgba(21, 101, 192, .4);
            color: #90caf9;
        }

        body.dark-theme .tv-alert-warn {
            background: rgba(255, 152, 0, .12);
            border-color: rgba(255, 152, 0, .3);
            color: #ffcc80;
        }

        body.dark-theme .tv-chip-all {
            background: #0d2a50;
            color: #90caf9;
            border-color: #2a5080;
        }

        body.dark-theme .tv-chip-overdue {
            background: #2d1515;
            color: #ef9a9a;
            border-color: #7f1d1d;
        }

        body.dark-theme .tv-chip-pending {
            background: #2d2000;
            color: #ffd54f;
            border-color: #f57f17;
        }

        body.dark-theme .tv-chip-paid {
            background: #0d2d0d;
            color: #a5d6a7;
            border-color: #1b5e20;
        }

        body.dark-theme .tv-chip-cancel {
            background: #1c2128;
            color: #9ca3af;
            border-color: #374151;
        }

        body.dark-theme .tv-pr-link {
            color: #90caf9;
        }

        body.dark-theme .tv-pr-link:hover {
            background: #1e3a5f;
        }

        /* ── Light theme ── */
        body.light-theme .tv-search-wrap input {
            background: #f9fafb;
            border-color: #e5e7eb;
            color: #1f2937;
        }

        body.light-theme .tv-table-card {
            background: #fff;
            border-color: #e5e7eb;
        }

        body.light-theme .tv-table tbody td {
            border-color: #e5e7eb;
            color: #1f2937;
        }

        body.light-theme .tv-table tbody tr:hover td {
            background: #eff6ff !important;
        }

        body.light-theme .tv-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        body.light-theme .tv-pag-bar {
            background: #f9fafb;
            border-top-color: #e5e7eb;
        }

        /* ── Modals ── */
        .tv-modal .modal-content {
            border: none;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .18);
        }

        .tv-modal .modal-header {
            background: linear-gradient(135deg, var(--blue-primary) 0%, var(--blue-mid) 100%);
            color: #fff;
            border: none;
            padding: 1.25rem 1.75rem;
        }

        .tv-modal .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .tv-modal .modal-title {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .tv-modal .modal-body {
            background: #f8fafc;
            padding: 1.5rem;
        }

        .tv-modal .modal-footer {
            background: #f3f4f6;
            border-top: 1px solid #e5e7eb;
            padding: .75rem 1.5rem;
        }

        .tv-modal label {
            font-size: .8rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .4px;
            font-family: 'Nunito', sans-serif;
        }

        .tv-modal .form-control,
        .tv-modal .form-select {
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            font-family: 'Nunito', sans-serif;
            font-size: .85rem;
            background: #f0f7ff;
            color: var(--text-dark);
            transition: all .25s;
        }

        .tv-modal .form-control:focus,
        .tv-modal .form-select:focus {
            border-color: var(--blue-mid);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(33, 150, 243, .15);
        }

        /* ── Dark theme: Modal stays light for readability ── */
        body.dark-theme .tv-modal .modal-content {
            background: #ffffff;
        }

        body.dark-theme .tv-modal .modal-body {
            background: #f8fafc;
        }

        body.dark-theme .tv-modal .modal-footer {
            background: #f3f4f6;
            border-top: 1px solid #e5e7eb;
        }

        body.dark-theme .tv-modal label {
            color: #000000 !important;
        }

        body.dark-theme .tv-modal .form-control,
        body.dark-theme .tv-modal .form-select {
            background: #f0f7ff !important;
            color: #111827 !important;
            border-color: #cbd5e1 !important;
        }

        body.dark-theme .tv-modal .form-control:focus,
        body.dark-theme .tv-modal .form-select:focus {
            background: #ffffff !important;
            color: #111827 !important;
        }

        body.dark-theme .tv-modal .form-control::placeholder {
            color: #000000 !important;
        }

        body.dark-theme .tv-modal p,
        body.dark-theme .tv-modal h6,
        body.dark-theme .tv-modal small {
            color: #111827;
        }

        body.dark-theme .tv-modal .text-muted {
            color: #000000 !important;
        }

        body.dark-theme .tv-modal .choices__inner,
        body.dark-theme .tv-modal .choices__input,
        body.dark-theme .tv-modal .choices[data-type*=select-one] .choices__inner {
            background: #f0f7ff !important;
            border-color: #cbd5e1 !important;
            color: #111827 !important;
        }

        body.dark-theme .tv-modal .choices__list--single .choices__item {
            color: #111827 !important;
        }

        body.dark-theme .tv-modal .choices__list--dropdown,
        body.dark-theme .tv-modal .choices__list[aria-expanded] {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
        }

        body.dark-theme .tv-modal .choices__list--dropdown .choices__item,
        body.dark-theme .tv-modal .choices__list[aria-expanded] .choices__item {
            color: #111827 !important;
            background: #ffffff !important;
        }

        body.dark-theme .tv-modal .choices__list--dropdown .choices__item--selectable.is-highlighted,
        body.dark-theme .tv-modal .choices__list[aria-expanded] .choices__item--selectable.is-highlighted {
            background: #dbeafe !important;
            color: #1e40af !important;
        }

        body.dark-theme .tv-modal .choices__list--dropdown .choices__input,
        body.dark-theme .tv-modal .choices__list[aria-expanded] .choices__input {
            background: #f0f7ff !important;
            color: #111827 !important;
            border-bottom: 1px solid #cbd5e1 !important;
        }

        body.dark-theme .tv-modal .choices__placeholder {
            color: #9ca3af !important;
        }
    </style>
</head>

<body>
    <div class="dashboard-wrapper">

        <?php include 'sidebar.php'; ?>

        <!-- MAIN -->
        <main class="tv-main">

            <!-- HEADER -->
            <div class="dashboard-header-section">
                <div class="header-title-area">
                    <h1><i class="bi bi-table" style="margin-right:.45rem;"></i>Procurement Monitoring</h1>
                    <p>Philippine Statistics Authority &mdash; Purchase Request Monitoring</p>
                </div>
                <div class="header-actions">
                    <div
                        style="display:flex;flex-direction:column;align-items:flex-end;justify-content:center;line-height:1.3;margin-right:.4rem;">
                        <span id="tvClock" class="tv-clock-time"
                            style="font-size:1.1rem;font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:.04em;">--:--:--</span>
                        <span id="tvDate" class="tv-clock-date" style="font-size:.7rem;white-space:nowrap;">--- --,
                            ----</span>
                    </div>
                    <button class="btn-header-action theme-toggle" id="tvThemeToggle" title="Toggle theme"
                        style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;">
                        <i class="bi bi-sun-fill"></i>
                    </button>
                </div>
            </div>

            <?php
            $disp_total = $overall_counts['total_prs'] ?? 0;
            $disp_paid = $overall_counts['paid_count'] ?? 0;
            $disp_cancelled = $overall_counts['cancelled_count'] ?? 0;
            $disp_pending = $overall_counts['pending_count'] ?? 0;
            ?>

            <!-- TOOLBAR -->
            <form method="GET" id="tvSearchForm" style="margin-bottom:.65rem;">
                <div class="tv-toolbar">
                    <div class="tv-search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search_query" value="<?= htmlspecialchars($search_query) ?>"
                            placeholder="Search PR # or Processor…" list="searchList" autocomplete="off">
                        <datalist id="searchList">
                            <?php foreach ($all_pr_numbers as $pn): ?>
                                <option value="<?= htmlspecialchars($pn) ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>
                    <button type="submit" class="tv-btn tv-btn-primary"><i class="bi bi-search"></i> Search</button>
                    <?php if ($search_query): ?>
                        <a href="?" class="tv-btn tv-btn-outline"><i class="bi bi-x-circle"></i> Clear</a>
                    <?php endif; ?>
                    <button type="button" class="tv-btn tv-btn-success ms-auto" data-bs-toggle="modal"
                        data-bs-target="#addPRModal">
                        <i class="bi bi-plus-circle"></i> Add PR
                    </button>
                </div>
            </form>

            <!-- FILTER CHIPS -->
            <div class="tv-filter-chips">
                <span
                    style="font-size:.75rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;font-family:'Barlow Condensed',sans-serif;">Filter:</span>
                <a href="?" class="tv-chip tv-chip-all <?= !$bucket && !$show_overdue ? 'active' : '' ?>">
                    <i class="bi bi-grid"></i> All (<?= number_format($disp_total) ?>)
                </a>
                <a href="?bucket=overdue" class="tv-chip tv-chip-overdue <?= $bucket === 'overdue' ? 'active' : '' ?>">
                    <i class="bi bi-clock-history"></i> Overdue (<?= number_format($overdue_count) ?>)
                </a>
                <a href="?bucket=pending" class="tv-chip tv-chip-pending <?= $bucket === 'pending' ? 'active' : '' ?>">
                    <i class="bi bi-hourglass-split"></i> On-Going (<?= number_format($disp_pending) ?>)
                </a>
                <a href="?bucket=paid" class="tv-chip tv-chip-paid <?= $bucket === 'paid' ? 'active' : '' ?>">
                    <i class="bi bi-check2-circle"></i> Paid (<?= number_format($disp_paid) ?>)
                </a>
                <a href="?bucket=cancelled"
                    class="tv-chip tv-chip-cancel <?= $bucket === 'cancelled' ? 'active' : '' ?>">
                    <i class="bi bi-x-circle"></i> Cancelled (<?= number_format($disp_cancelled) ?>)
                </a>
            </div>

            <?php if ($search_query !== '' && !empty($processor_summary)): ?>
                <div class="tv-alert tv-alert-info">
                    <i class="bi bi-info-circle-fill"></i>
                    Showing results for: <strong><?= htmlspecialchars($search_query) ?></strong>
                </div>
            <?php elseif ($search_query !== '' && empty($processor_summary)): ?>
                <div class="tv-alert tv-alert-warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    No results found for &ldquo;<?= htmlspecialchars($search_query) ?>&rdquo;.
                </div>
            <?php endif; ?>

            <!-- TABLE CARD -->
            <div class="tv-table-card">
                <div class="tv-table-scroll">
                    <table class="tv-table">
                        <thead>
                            <tr>
                                <th><?= sortLink('pr_number', 'PR #') ?></th>
                                <th><?= sortLink('title', 'Title') ?></th>
                                <th><?= sortLink('processor', 'Processor') ?></th>
                                <th><?= sortLink('supplier', 'Supplier') ?></th>
                                <th><?= sortLink('end_user', 'End User') ?></th>
                                <th><?= sortLink('status', 'Status') ?></th>
                                <th><?= sortLink('date_endorsement', 'Date Endorsed') ?></th>
                                <th><?= sortLink('cancelled', 'Cancelled') ?></th>
                                <th><?= sortLink('paid_date', 'Paid Date') ?></th>
                                <th><?= sortLink('change_schedule', 'Change Schedule') ?></th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($prs) > 0): ?>
                                <?php foreach ($prs as $pr): ?>
                                    <?php
                                    $rowClass = '';
                                    $statusLabel = '';
                                    $statusClass = 'tv-status-default';
                                    $today = date('Y-m-d');
                                    $diff = !empty($pr['date_endorsement'])
                                        ? (strtotime($today) - strtotime($pr['date_endorsement'])) / 86400
                                        : 0;

                                    if ($pr['status'] === 'CANCELLED') {
                                        $rowClass = 'tv-row-cancelled';
                                        $statusClass = 'tv-status-cancelled';
                                    } elseif (!empty($pr['paid_date'])) {
                                        $rowClass = 'tv-row-paid';
                                        $statusClass = 'tv-status-paid';
                                    } elseif (!empty($pr['date_endorsement']) && $diff > 3) {
                                        $rowClass = 'tv-row-overdue';
                                        $statusClass = 'tv-status-overdue';
                                    } else {
                                        $statusClass = 'tv-status-pending';
                                    }
                                    $statusLabel = $pr['status'] ?: '—';
                                    ?>
                                    <tr class="<?= $rowClass ?>" onclick="openEditFromRow(event, <?= $pr['id'] ?>)">
                                        <td>
                                            <span class="tv-pr-link" data-history-trigger="true"
                                                onclick="event.stopPropagation(); openHistoryModal(<?= $pr['id'] ?>, '<?= htmlspecialchars($pr['pr_number']) ?>')"
                                                title="View Transaction History">
                                                <?= htmlspecialchars($pr['pr_number']) ?>
                                                <i class="bi bi-clock-history" style="font-size:.6rem;opacity:.7;"></i>
                                            </span>
                                        </td>
                                        <td style="max-width:240px;white-space:normal;line-height:1.35;">
                                            <?= htmlspecialchars($pr['title']) ?>
                                        </td>
                                        <td><?= htmlspecialchars(str_replace(['"', "'"], '', $pr['processor'])) ?></td>
                                        <td><?= htmlspecialchars($pr['supplier']) ?></td>
                                        <td><?= htmlspecialchars(str_replace(['"', "'"], '', $pr['end_user'])) ?></td>
                                        <td><?= htmlspecialchars($statusLabel) ?></td>
                                        <td style="color:var(--text-muted);font-size:.78rem;">
                                            <?= htmlspecialchars($pr['date_endorsement'] ?? '') ?>
                                        </td>
                                        <td style="color:var(--text-muted);font-size:.78rem;">
                                            <?= htmlspecialchars($pr['cancelled'] ?? '') ?>
                                        </td>
                                        <td style="color:var(--text-muted);font-size:.78rem;">
                                            <?= htmlspecialchars($pr['paid_date'] ?? '') ?>
                                        </td>
                                        <td style="color:var(--text-muted);font-size:.78rem;">
                                            <?= htmlspecialchars($pr['change_schedule'] ?? '') ?>
                                        </td>
                                        <td
                                            style="max-width:180px;white-space:normal;font-size:.78rem;color:var(--text-muted);">
                                            <?= htmlspecialchars($pr['remarks'] ?? '') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11"
                                        style="text-align:center;padding:4rem 1rem;color:var(--text-muted);font-family:'Nunito',sans-serif;font-weight:600;">
                                        <i class="bi bi-inbox"
                                            style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3;"></i>
                                        No results found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION -->
                <div class="tv-pag-bar">
                    <span class="tv-pag-info">
                        Showing
                        <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $items_per_page, $total_prs)) ?>
                        of <?= number_format($total_prs) ?> records
                    </span>
                    <div class="tv-pag-links">
                        <?php if ($current_page > 1): ?>
                            <a href="?<?= pageLink(1) ?>"><i class="bi bi-chevron-bar-left"></i></a>
                            <a href="?<?= pageLink($current_page - 1) ?>"><i class="bi bi-chevron-left"></i></a>
                        <?php else: ?>
                            <span class="off"><i class="bi bi-chevron-bar-left"></i></span>
                            <span class="off"><i class="bi bi-chevron-left"></i></span>
                        <?php endif; ?>
                        <?php for ($p = max(1, $current_page - 2); $p <= min($total_pages, $current_page + 2); $p++): ?>
                            <?php if ($p === $current_page): ?><span class="on"><?= $p ?></span>
                            <?php else: ?><a href="?<?= pageLink($p) ?>"><?= $p ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?<?= pageLink($current_page + 1) ?>"><i class="bi bi-chevron-right"></i></a>
                            <a href="?<?= pageLink($total_pages) ?>"><i class="bi bi-chevron-bar-right"></i></a>
                        <?php else: ?>
                            <span class="off"><i class="bi bi-chevron-right"></i></span>
                            <span class="off"><i class="bi bi-chevron-bar-right"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /tv-table-card -->
        </main>
    </div><!-- /dashboard-wrapper -->

    <!-- Add PR Modal -->
    <div class="modal fade tv-modal" id="addPRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addPRModalLabel"><i class="bi bi-plus-circle"></i> Add Purchase Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addPRForm" method="post" action="pr_action.php">
                        <input type="hidden" name="action" value="add">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">PR Number</label>
                                <input type="text" name="pr_number" class="form-control" required
                                    placeholder="e.g., 26-01-0001">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Title</label>
                                <input type="text" name="title" class="form-control" required
                                    placeholder="Enter PR Title">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Processor</label>
                                <select name="processor" class="form-select js-searchable-select" required>
                                    <option value="">Select Processor</option>
                                    <option value="BERNADETTE DE CASTRO">BERNADETTE DE CASTRO</option>
                                    <option value="CHESTER ARANDA">CHESTER ARANDA</option>
                                    <option value="DARRYL IVAN BERNARDO">DARRYL IVAN BERNARDO</option>
                                    <option value="JANICE MEDRANO">JANICE MEDRANO</option>
                                    <option value="JEREMIAH CANLAS">JEREMIAH CANLAS</option>
                                    <option value="JOSHUA MHIR AVINANTE">JOSHUA MHIR AVINANTE</option>
                                    <option value="MA. CHRISTINA MILLAN">MA. CHRISTINA MILLAN</option>
                                    <option value="MARYCAR MASILANG">MARYCAR MASILANG</option>
                                    <option value="NORVEN ABEJUELA">NORVEN ABEJUELA</option>
                                    <option value="RHEYMART BANGCOYO">RHEYMART BANGCOYO</option>
                                    <option value="RYNE CHRISTIAN CRUZ">RYNE CHRISTIAN CRUZ</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Supplier</label>
                                <input type="text" name="supplier" class="form-control"
                                    placeholder="Enter Supplier Name">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">End User</label>
                                <select name="end_user" class="form-select js-searchable-select">
                                    <option value="">Select End User</option>
                                    <option value="CBSS-PCD">CBSS-PCD</option>
                                    <option value="CPS-OANS">CPS-OANS</option>
                                    <option value="CRS-VSD">CRS-VSD</option>
                                    <option value="CTCO-CBSS">CTCO-CBSS</option>
                                    <option value="EIAD-MAS">EIAD-MAS</option>
                                    <option value="ESSS-CSD">ESSS-CSD</option>
                                    <option value="ESSS-FSD">ESSS-FSD</option>
                                    <option value="ESSS-ISD">ESSS-ISD</option>
                                    <option value="ESSS-LPSD">ESSS-LPSD</option>
                                    <option value="ESSS-PSD">ESSS-PSD</option>
                                    <option value="ESSS-SSD">ESSS-SSD</option>
                                    <option value="ESSS-TSD">ESSS-TSD</option>
                                    <option value="FAS-BD">FAS-BD</option>
                                    <option value="FAS-CSD">FAS-CSD</option>
                                    <option value="FAS-HRD">FAS-HRD</option>
                                    <option value="FMCMS-FGD">FMCMS-FGD</option>
                                    <option value="ITDS-KMCD">ITDS-KMCD</option>
                                    <option value="ITDS-SOID">ITDS-SOID</option>
                                    <option value="MAS-EIAD">MAS-EIAD</option>
                                    <option value="MAS-ENRAD">MAS-ENRAD</option>
                                    <option value="MAS-PAD">MAS-PAD</option>
                                    <option value="MAS-SEAD">MAS-SEAD</option>
                                    <option value="NCS-AFCD">NCS-AFCD</option>
                                    <option value="NCS-CPCD">NCS-CPCD</option>
                                    <option value="NCS-PHCD">NCS-PHCD</option>
                                    <option value="NCS-SICD">NCS-SICD</option>
                                    <option value="ONS-CAD">ONS-CAD</option>
                                    <option value="ONS-FMD">ONS-FMD</option>
                                    <option value="ONS-ICU">ONS-ICU</option>
                                    <option value="ONS-PMS">ONS-PMS</option>
                                    <option value="PMS-MED">PMS-MED</option>
                                    <option value="PMS-PPCD">PMS-PPCD</option>
                                    <option value="PRO">PRO</option>
                                    <option value="PRO-UCDMS">PRO-UCDMS</option>
                                    <option value="SISS-ISMD">SISS-ISMD</option>
                                    <option value="SS-SCD">SS-SCD</option>
                                    <option value="SS-SSD">SS-SSD</option>
                                    <option value="SSSS-DHSD">SSSS-DHSD</option>
                                    <option value="SSSS-IESD">SSSS-IESD</option>
                                    <option value="SSSS-LDRSSD">SSSS-LDRSSD</option>
                                    <option value="SSSS-LSSD">SSSS-LSSD</option>
                                    <option value="SSSS-PHDSD">SSSS-PHDSD</option>
                                    <option value="SSSS-SDSD">SSSS-SDSD</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Status</label>
                                <input type="text" name="status" class="form-control" placeholder="Enter Status">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Date Endorsement</label>
                                <input type="date" name="date_endorsement" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Cancelled Date</label>
                                <input type="date" name="cancelled" class="form-control">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Paid Date</label>
                                <input type="date" name="paid_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Change Schedule/Delivery Date</label>
                                <input type="date" name="change_schedule" class="form-control">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Remarks</label>
                            <input type="text" name="remarks" class="form-control" placeholder="Optional remarks">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitPrForm('addPRForm')">
                        <i class="bi bi-check-circle"></i> Add PR
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit PR Modal -->
    <div class="modal fade tv-modal" id="editPRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editPRModalLabel"><i class="bi bi-pencil"></i> Edit Purchase Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="editModalBody">
                    <p class="text-muted">Loading...</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitPrForm('editPRForm')">
                        <i class="bi bi-check-circle"></i> Update PR
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize searchable selects using Choices.js
        function initSearchableSelects(rootElement) {
            const context = rootElement || document;
            if (!window.Choices) return;

            const selects = context.querySelectorAll('select.js-searchable-select');
            selects.forEach((select) => {
                if (select.dataset.choicesInitialized === 'true') return;

                new Choices(select, {
                    searchEnabled: true,
                    itemSelectText: '',
                    shouldSort: false,
                    allowHTML: false,
                    position: 'bottom'
                });

                select.dataset.choicesInitialized = 'true';
            });
        }

        // Wrapper: only open edit modal if click did NOT come from the PR# history link
        function openEditFromRow(event, prId) {
            // If the clicked element (or any ancestor up to the TR) has the history trigger attr, abort
            if (event.target.closest('[data-history-trigger]')) return;
            // Manually show the edit modal
            const editModal = new bootstrap.Modal(document.getElementById('editPRModal'));
            editModal.show();
            loadEditModal(prId);
        }

        // Load edit modal with PR data
        function loadEditModal(prId) {
            fetch('get_pr.php?id=' + prId)
                .then(response => response.json())
                .then(data => {
                    const form = `
                        <form id="editPRForm" method="post" action="pr_action.php">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="${data.id}">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">PR Number</label>
                                    <p class="form-control-plaintext text-muted">${escapeHtml(data.pr_number)}</p>
                                    <input type="hidden" name="pr_number" value="${escapeHtml(data.pr_number)}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Title</label>
                                    <p class="form-control-plaintext text-muted">${escapeHtml(data.title)}</p>
                                    <input type="hidden" name="title" value="${escapeHtml(data.title)}">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Processor</label>
                                    <select name="processor" class="form-select js-searchable-select" required>
                                        <option value="">Select Processor</option>
                                        <option value="BERNADETTE DE CASTRO" ${data.processor === 'BERNADETTE DE CASTRO' ? 'selected' : ''}>BERNADETTE DE CASTRO</option>
                                        <option value="CHESTER ARANDA" ${data.processor === 'CHESTER ARANDA' ? 'selected' : ''}>CHESTER ARANDA</option>
                                        <option value="DARRYL IVAN BERNARDO" ${data.processor === 'DARRYL IVAN BERNARDO' ? 'selected' : ''}>DARRYL IVAN BERNARDO</option>
                                        <option value="JANICE MEDRANO" ${data.processor === 'JANICE MEDRANO' ? 'selected' : ''}>JANICE MEDRANO</option>
                                        <option value="JEREMIAH CANLAS" ${data.processor === 'JEREMIAH CANLAS' ? 'selected' : ''}>JEREMIAH CANLAS</option>
                                        <option value="JOSHUA MHIR AVINANTE" ${data.processor === 'JOSHUA MHIR AVINANTE' ? 'selected' : ''}>JOSHUA MHIR AVINANTE</option>
                                        <option value="MA. CHRISTINA MILLAN" ${data.processor === 'MA. CHRISTINA MILLAN' ? 'selected' : ''}>MA. CHRISTINA MILLAN</option>
                                        <option value="MARYCAR MASILANG" ${data.processor === 'MARYCAR MASILANG' ? 'selected' : ''}>MARYCAR MASILANG</option>
                                        <option value="NORVEN ABEJUELA" ${data.processor === 'NORVEN ABEJUELA' ? 'selected' : ''}>NORVEN ABEJUELA</option>
                                        <option value="RHEYMART BANGCOYO" ${data.processor === 'RHEYMART BANGCOYO' ? 'selected' : ''}>RHEYMART BANGCOYO</option>
                                        <option value="RYNE CHRISTIAN CRUZ" ${data.processor === 'RYNE CHRISTIAN CRUZ' ? 'selected' : ''}>RYNE CHRISTIAN CRUZ</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Supplier</label>
                                    <input type="text" name="supplier" class="form-control" value="${escapeHtml(data.supplier || '')}">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">End User</label>
                                    <select name="end_user" class="form-select js-searchable-select">
                                        <option value="">Select End User</option>
                                        <option value="CBSS-PCD" ${data.end_user === 'CBSS-PCD' ? 'selected' : ''}>CBSS-PCD</option>
                                        <option value="CPS-OANS" ${data.end_user === 'CPS-OANS' ? 'selected' : ''}>CPS-OANS</option>
                                        <option value="CRS-VSD" ${data.end_user === 'CRS-VSD' ? 'selected' : ''}>CRS-VSD</option>
                                        <option value="CTCO-CBSS" ${data.end_user === 'CTCO-CBSS' ? 'selected' : ''}>CTCO-CBSS</option>
                                        <option value="EIAD-MAS" ${data.end_user === 'EIAD-MAS' ? 'selected' : ''}>EIAD-MAS</option>
                                        <option value="ESSS-CSD" ${data.end_user === 'ESSS-CSD' ? 'selected' : ''}>ESSS-CSD</option>
                                        <option value="ESSS-FSD" ${data.end_user === 'ESSS-FSD' ? 'selected' : ''}>ESSS-FSD</option>
                                        <option value="ESSS-ISD" ${data.end_user === 'ESSS-ISD' ? 'selected' : ''}>ESSS-ISD</option>
                                        <option value="ESSS-LPSD" ${data.end_user === 'ESSS-LPSD' ? 'selected' : ''}>ESSS-LPSD</option>
                                        <option value="ESSS-PSD" ${data.end_user === 'ESSS-PSD' ? 'selected' : ''}>ESSS-PSD</option>
                                        <option value="ESSS-SSD" ${data.end_user === 'ESSS-SSD' ? 'selected' : ''}>ESSS-SSD</option>
                                        <option value="ESSS-TSD" ${data.end_user === 'ESSS-TSD' ? 'selected' : ''}>ESSS-TSD</option>
                                        <option value="FAS-BD" ${data.end_user === 'FAS-BD' ? 'selected' : ''}>FAS-BD</option>
                                        <option value="FAS-CSD" ${data.end_user === 'FAS-CSD' ? 'selected' : ''}>FAS-CSD</option>
                                        <option value="FAS-HRD" ${data.end_user === 'FAS-HRD' ? 'selected' : ''}>FAS-HRD</option>
                                        <option value="FMCMS-FGD" ${data.end_user === 'FMCMS-FGD' ? 'selected' : ''}>FMCMS-FGD</option>
                                        <option value="ITDS-KMCD" ${data.end_user === 'ITDS-KMCD' ? 'selected' : ''}>ITDS-KMCD</option>
                                        <option value="ITDS-SOID" ${data.end_user === 'ITDS-SOID' ? 'selected' : ''}>ITDS-SOID</option>
                                        <option value="MAS-EIAD" ${data.end_user === 'MAS-EIAD' ? 'selected' : ''}>MAS-EIAD</option>
                                        <option value="MAS-ENRAD" ${data.end_user === 'MAS-ENRAD' ? 'selected' : ''}>MAS-ENRAD</option>
                                        <option value="MAS-PAD" ${data.end_user === 'MAS-PAD' ? 'selected' : ''}>MAS-PAD</option>
                                        <option value="MAS-SEAD" ${data.end_user === 'MAS-SEAD' ? 'selected' : ''}>MAS-SEAD</option>
                                        <option value="NCS-AFCD" ${data.end_user === 'NCS-AFCD' ? 'selected' : ''}>NCS-AFCD</option>
                                        <option value="NCS-CPCD" ${data.end_user === 'NCS-CPCD' ? 'selected' : ''}>NCS-CPCD</option>
                                        <option value="NCS-PHCD" ${data.end_user === 'NCS-PHCD' ? 'selected' : ''}>NCS-PHCD</option>
                                        <option value="NCS-SICD" ${data.end_user === 'NCS-SICD' ? 'selected' : ''}>NCS-SICD</option>
                                        <option value="ONS-CAD" ${data.end_user === 'ONS-CAD' ? 'selected' : ''}>ONS-CAD</option>
                                        <option value="ONS-FMD" ${data.end_user === 'ONS-FMD' ? 'selected' : ''}>ONS-FMD</option>
                                        <option value="ONS-ICU" ${data.end_user === 'ONS-ICU' ? 'selected' : ''}>ONS-ICU</option>
                                        <option value="ONS-PMS" ${data.end_user === 'ONS-PMS' ? 'selected' : ''}>ONS-PMS</option>
                                        <option value="PMS-MED" ${data.end_user === 'PMS-MED' ? 'selected' : ''}>PMS-MED</option>
                                        <option value="PMS-PPCD" ${data.end_user === 'PMS-PPCD' ? 'selected' : ''}>PMS-PPCD</option>
                                        <option value="PRO" ${data.end_user === 'PRO' ? 'selected' : ''}>PRO</option>
                                        <option value="PRO-UCDMS" ${data.end_user === 'PRO-UCDMS' ? 'selected' : ''}>PRO-UCDMS</option>
                                        <option value="SISS-ISMD" ${data.end_user === 'SISS-ISMD' ? 'selected' : ''}>SISS-ISMD</option>
                                        <option value="SS-SCD" ${data.end_user === 'SS-SCD' ? 'selected' : ''}>SS-SCD</option>
                                        <option value="SS-SSD" ${data.end_user === 'SS-SSD' ? 'selected' : ''}>SS-SSD</option>
                                        <option value="SSSS-DHSD" ${data.end_user === 'SSSS-DHSD' ? 'selected' : ''}>SSSS-DHSD</option>
                                        <option value="SSSS-IESD" ${data.end_user === 'SSSS-IESD' ? 'selected' : ''}>SSSS-IESD</option>
                                        <option value="SSSS-LDRSSD" ${data.end_user === 'SSSS-LDRSSD' ? 'selected' : ''}>SSSS-LDRSSD</option>
                                        <option value="SSSS-LSSD" ${data.end_user === 'SSSS-LSSD' ? 'selected' : ''}>SSSS-LSSD</option>
                                        <option value="SSSS-PHDSD" ${data.end_user === 'SSSS-PHDSD' ? 'selected' : ''}>SSSS-PHDSD</option>
                                        <option value="SSSS-SDSD" ${data.end_user === 'SSSS-SDSD' ? 'selected' : ''}>SSSS-SDSD</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Status</label>
                                    <input type="text" name="status" class="form-control" value="${data.status || ''}">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Date Endorsement</label>
                                    <input type="date" name="date_endorsement" class="form-control" value="${data.date_endorsement || ''}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Cancelled Date</label>
                                    <input type="date" name="cancelled" class="form-control" value="${data.cancelled || ''}">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Paid Date</label>
                                    <input type="date" name="paid_date" class="form-control" value="${data.paid_date || ''}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Change Schedule/Delivery Date</label>
                                    <input type="date" name="change_schedule" class="form-control" value="${data.change_schedule || ''}">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Remarks</label>
                                <input type="text" name="remarks" class="form-control" value="${escapeHtml(data.remarks || '')}">
                            </div>
                        </form>
                    `;
                    const editBody = document.getElementById('editModalBody');
                    editBody.innerHTML = form;
                    initSearchableSelects(editBody);
                })
                .catch(error => {
                    document.getElementById('editModalBody').innerHTML = '<p class="text-danger">Error loading PR data: ' + error.message + '</p>';
                    console.error('Error:', error);
                });
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Central helper to submit PR forms via AJAX
        function submitPrForm(formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            const formData = new FormData(form);

            fetch('pr_action.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const modalElement = form.closest('.modal');
                        if (modalElement) {
                            const modal = bootstrap.Modal.getInstance(modalElement);
                            if (modal) modal.hide();
                        }
                        setTimeout(() => { location.reload(); }, 500);
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error submitting form');
                });
        }

        // Prevent default submit (e.g., Enter key) and route through AJAX helper
        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (form.id === 'addPRForm' || form.id === 'editPRForm') {
                e.preventDefault();
                submitPrForm(form.id);
            }
        }, true);

        // Save scroll position before any navigation
        window.addEventListener('beforeunload', function () {
            localStorage.setItem('scrollPos', window.scrollY);
        });

        // Restore scroll position after page load
        window.addEventListener('load', function () {
            const scrollPos = localStorage.getItem('scrollPos');
            if (scrollPos !== null) {
                window.scrollTo(0, parseInt(scrollPos));
            }
            localStorage.removeItem('scrollPos');
            initSearchableSelects(document);
        });

        // ===== AUTO-SYNC EVERY 5 MINUTES =====
        const AUTO_SYNC_INTERVAL = 5 * 60 * 1000;
        let autoSyncCountdown = AUTO_SYNC_INTERVAL / 1000;
        let countdownTimer = null;

        (function createAutoSyncIndicator() {
            const indicator = document.createElement('div');
            indicator.id = 'autoSyncIndicator';
            indicator.style.cssText = [
                'position:fixed', 'bottom:1.25rem', 'right:1.25rem', 'z-index:9999',
                'background:rgba(0,0,0,0.55)', 'backdrop-filter:blur(6px)',
                'border:1px solid rgba(255,255,255,0.12)', 'border-radius:8px',
                'padding:0.45rem 0.85rem', 'font-size:0.75rem', 'color:#a1a1aa',
                'display:flex', 'align-items:center', 'gap:0.5rem', 'user-select:none'
            ].join(';');
            indicator.innerHTML =
                '<span id="autoSyncDot" style="width:7px;height:7px;border-radius:50%;background:#06b6d4;display:inline-block;"></span>' +
                '<span id="autoSyncLabel">Next sync in <strong id="autoSyncCountdown">5:00</strong></span>';
            document.body.appendChild(indicator);
        })();

        function fmtCD(s) { return Math.floor(s / 60) + ':' + (s % 60).toString().padStart(2, '0'); }

        function runAutoSync() {
            const dot = document.getElementById('autoSyncDot');
            const lbl = document.getElementById('autoSyncLabel');
            if (dot) dot.style.background = '#fbbf24';
            if (lbl) lbl.innerHTML = '<em>Syncing\u2026</em>';
            fetch('sync_action.php')
                .then(r => r.json())
                .then(data => {
                    if (dot) dot.style.background = data.success ? '#22c55e' : '#ef4444';
                    if (lbl) lbl.textContent = data.success ? 'Synced just now' : 'Sync failed';
                    setTimeout(() => { location.reload(); }, 1200);
                })
                .catch(() => {
                    if (dot) dot.style.background = '#ef4444';
                    if (lbl) lbl.textContent = 'Sync failed';
                });
        }

        function startCountdown() {
            autoSyncCountdown = AUTO_SYNC_INTERVAL / 1000;
            clearInterval(countdownTimer);
            countdownTimer = setInterval(() => {
                autoSyncCountdown--;
                const el = document.getElementById('autoSyncCountdown');
                if (el) el.textContent = fmtCD(autoSyncCountdown);
                if (autoSyncCountdown <= 0) clearInterval(countdownTimer);
            }, 1000);
        }

        startCountdown();
        setInterval(runAutoSync, AUTO_SYNC_INTERVAL);
    </script>
    <!-- ===== TRANSACTION HISTORY MODAL ===== -->
    <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content"
                style="border-radius:14px; overflow:hidden; border:none; box-shadow:0 20px 60px rgba(0,0,0,0.18);">

                <!-- Header -->
                <div class="modal-header"
                    style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%); color:#fff; border:none; padding:1.25rem 1.75rem;">
                    <div style="flex:1;">
                        <div style="display:flex; align-items:center; gap:.65rem; margin-bottom:.4rem;">
                            <i class="bi bi-clock-history" style="font-size:1.2rem;"></i>
                            <h5 class="modal-title mb-0" style="font-weight:700; font-size:1.05rem;">
                                Transaction History &mdash;
                                <span id="historyPRNumber"
                                    style="font-family:monospace; background:rgba(255,255,255,0.18); padding:2px 10px; border-radius:5px; font-size:1rem;"></span>
                            </h5>
                        </div>
                        <div id="historyPRMeta"
                            style="font-size:0.78rem; opacity:.85; display:flex; gap:1.25rem; flex-wrap:wrap;"></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-3" data-bs-dismiss="modal"></button>
                </div>

                <!-- Stat Strip -->
                <div id="historyStatStrip"
                    style="background:#f0f4ff; border-bottom:1px solid #dbeafe; padding:.55rem 1.75rem; display:flex; gap:1rem; flex-wrap:wrap; font-size:.78rem; min-height:38px; align-items:center;">
                </div>

                <!-- Body -->
                <div class="modal-body" style="padding:1.5rem; background:#f8fafc;">
                    <div id="historyLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" style="width:2.5rem;height:2.5rem;"></div>
                        <p class="mt-2 text-muted small">Loading history…</p>
                    </div>
                    <div id="historyTimeline" style="display:none;"></div>
                    <div id="historyEmpty" style="display:none;" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox"
                            style="font-size:2.5rem; opacity:.35; display:block; margin-bottom:.75rem;"></i>
                        <strong>No history recorded yet</strong>
                        <p class="small mt-1 mb-0">History is logged automatically when a PR is created or edited.</p>
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer"
                    style="background:#f3f4f6; border-top:1px solid #e5e7eb; padding:.75rem 1.5rem; justify-content:space-between;">
                    <small class="text-muted" id="historyLastRefreshed"></small>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm" onclick="refreshHistory()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                        <button class="btn btn-outline-primary btn-sm" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- History Modal Styles -->
    <style>
        .history-timeline {
            position: relative;
            padding-left: 2.25rem;
        }

        .history-timeline::before {
            content: '';
            position: absolute;
            left: .9rem;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: linear-gradient(to bottom, #2563eb 0%, #e5e7eb 100%);
            border-radius: 2px;
        }

        .history-entry {
            position: relative;
            margin-bottom: 1rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: .9rem 1.1rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            transition: box-shadow .2s, transform .15s;
        }

        .history-entry:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.09);
            transform: translateY(-1px);
        }

        .history-dot {
            position: absolute;
            left: -1.7rem;
            top: 1rem;
            width: 13px;
            height: 13px;
            border-radius: 50%;
            border: 2px solid #fff;
            background: #2563eb;
            box-shadow: 0 0 0 2px #2563eb;
        }

        .dot-created {
            background: #10b981 !important;
            box-shadow: 0 0 0 2px #10b981 !important;
        }

        .dot-edited {
            background: #2563eb !important;
            box-shadow: 0 0 0 2px #2563eb !important;
        }

        .dot-status {
            background: #f59e0b !important;
            box-shadow: 0 0 0 2px #f59e0b !important;
        }

        .dot-cancelled {
            background: #ef4444 !important;
            box-shadow: 0 0 0 2px #ef4444 !important;
        }

        .dot-paid {
            background: #10b981 !important;
            box-shadow: 0 0 0 2px #10b981 !important;
        }

        .h-action-badge {
            display: inline-block;
            font-size: .68rem;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 6px;
        }

        .hb-created {
            background: #dcfce7;
            color: #15803d;
        }

        .hb-edited {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .hb-status {
            background: #fef3c7;
            color: #b45309;
        }

        .hb-cancelled {
            background: #fee2e2;
            color: #dc2626;
        }

        .hb-paid {
            background: #dcfce7;
            color: #15803d;
        }

        .h-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: .4rem .65rem;
            font-size: .79rem;
            margin-top: .55rem;
        }

        .h-meta-item {
            display: flex;
            flex-direction: column;
        }

        .h-meta-lbl {
            font-size: .66rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .h-meta-val {
            color: #1f2937;
            font-weight: 500;
            word-break: break-word;
        }

        .change-pill {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: .74rem;
            margin-top: .4rem;
            flex-wrap: wrap;
        }

        .change-pill .ov {
            color: #dc2626;
            text-decoration: line-through;
        }

        .change-pill .nv {
            color: #15803d;
            font-weight: 600;
        }

        .stat-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: .75rem;
            font-weight: 600;
            color: #374151;
        }
    </style>

    <script>
        // ===== TRANSACTION HISTORY MODAL =====
        let _historyPrId = null;

        function openHistoryModal(prId, prNumber) {
            _historyPrId = prId;
            document.getElementById('historyPRNumber').textContent = prNumber;
            document.getElementById('historyPRMeta').innerHTML = '';
            document.getElementById('historyStatStrip').innerHTML = '';
            document.getElementById('historyTimeline').style.display = 'none';
            document.getElementById('historyTimeline').innerHTML = '';
            document.getElementById('historyEmpty').style.display = 'none';
            document.getElementById('historyLoading').style.display = 'block';
            document.getElementById('historyLastRefreshed').textContent = '';
            new bootstrap.Modal(document.getElementById('historyModal')).show();
            fetchHistory(prId);
        }

        function refreshHistory() { if (_historyPrId) fetchHistory(_historyPrId); }

        function fetchHistory(prId) {
            document.getElementById('historyLoading').style.display = 'block';
            document.getElementById('historyTimeline').style.display = 'none';
            document.getElementById('historyEmpty').style.display = 'none';

            fetch('pr_history.php?pr_id=' + prId)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('historyLoading').style.display = 'none';
                    if (!data.success) { document.getElementById('historyEmpty').style.display = 'block'; return; }

                    // PR meta in header
                    const pr = data.pr || {};
                    const metaItems = [
                        { icon: 'bi-person-fill', label: 'Processor', val: pr.processor || '—' },
                        { icon: 'bi-building', label: 'Supplier', val: pr.supplier || '—' },
                        { icon: 'bi-people-fill', label: 'End User', val: pr.end_user || '—' },
                        { icon: 'bi-tag-fill', label: 'Status', val: pr.status || '—' },
                    ];
                    document.getElementById('historyPRMeta').innerHTML = metaItems
                        .map(m => `<span style="display:flex;align-items:center;gap:.3rem;"><i class="bi ${m.icon}"></i><b>${m.label}:</b>&nbsp;${escapeHtml(m.val)}</span>`)
                        .join('');

                    // Stat strip
                    const history = data.history || [];
                    const counts = {};
                    history.forEach(h => { const k = (h.action || '').toUpperCase(); counts[k] = (counts[k] || 0) + 1; });
                    const dotColors = { CREATED: '#10b981', EDITED: '#2563eb', 'STATUS CHANGE': '#f59e0b', CANCELLED: '#ef4444', PAID: '#10b981' };
                    let strip = `<span class="stat-chip"><i class="bi bi-list-ul" style="color:#2563eb;"></i>Total: ${history.length}</span>`;
                    Object.entries(counts).forEach(([k, v]) => {
                        strip += ` <span class="stat-chip"><i class="bi bi-circle-fill" style="color:${dotColors[k] || '#6b7280'};font-size:.5rem;"></i>${k}: ${v}</span>`;
                    });
                    document.getElementById('historyStatStrip').innerHTML = strip;

                    if (!history.length) { document.getElementById('historyEmpty').style.display = 'block'; return; }

                    const tl = document.getElementById('historyTimeline');
                    tl.innerHTML = '<div class="history-timeline">' + history.map(buildEntry).join('') + '</div>';
                    tl.style.display = 'block';
                    document.getElementById('historyLastRefreshed').textContent = 'Refreshed: ' + new Date().toLocaleTimeString();
                })
                .catch(() => {
                    document.getElementById('historyLoading').style.display = 'none';
                    document.getElementById('historyEmpty').style.display = 'block';
                });
        }

        function buildEntry(h) {
            const au = (h.action || '').toUpperCase();
            const dotMap = { CREATED: 'dot-created', EDITED: 'dot-edited', 'STATUS CHANGE': 'dot-status', CANCELLED: 'dot-cancelled', PAID: 'dot-paid' };
            const badgeMap = { CREATED: 'hb-created', EDITED: 'hb-edited', 'STATUS CHANGE': 'hb-status', CANCELLED: 'hb-cancelled', PAID: 'hb-paid' };
            const iconMap = { CREATED: 'plus-circle-fill', EDITED: 'pencil-fill', 'STATUS CHANGE': 'arrow-repeat', CANCELLED: 'x-circle-fill', PAID: 'check-circle-fill' };
            const dot = dotMap[au] || 'dot-edited';
            const badge = badgeMap[au] || 'hb-edited';
            const icon = iconMap[au] || 'pencil-fill';

            const dt = h.created_at ? new Date(h.created_at.replace(' ', 'T')) : null;
            const dateStr = dt ? dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
            const timeStr = dt ? dt.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';

            let changePill = '';
            if (h.field_changed && (h.old_value || h.new_value)) {
                changePill = `<div class="change-pill">
                    <i class="bi bi-pencil" style="font-size:.65rem;color:#6b7280;"></i>
                    <strong>${escapeHtml(h.field_changed)}:</strong>
                    <span class="ov">${escapeHtml(h.old_value || '(empty)')}</span>
                    <i class="bi bi-arrow-right" style="font-size:.62rem;color:#9ca3af;"></i>
                    <span class="nv">${escapeHtml(h.new_value || '(empty)')}</span>
                </div>`;
            }

            return `<div class="history-entry">
                <div class="history-dot ${dot}"></div>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.4rem;">
                    <span class="h-action-badge ${badge}"><i class="bi bi-${icon} me-1" style="font-size:.62rem;"></i>${escapeHtml(h.action)}</span>
                    <span style="font-size:.72rem;color:#6b7280;"><i class="bi bi-calendar3 me-1"></i>${dateStr}&ensp;<i class="bi bi-clock me-1"></i>${timeStr}</span>
                </div>
                <div class="h-meta-grid">
                    <div class="h-meta-item"><span class="h-meta-lbl"><i class="bi bi-person me-1"></i>Edited By</span><span class="h-meta-val">${escapeHtml(h.changed_by || '—')}</span></div>
                    <div class="h-meta-item"><span class="h-meta-lbl"><i class="bi bi-send me-1"></i>Destination</span><span class="h-meta-val">${escapeHtml(h.destination || '—')}</span></div>
                    <div class="h-meta-item"><span class="h-meta-lbl"><i class="bi bi-person-check me-1"></i>Recipient</span><span class="h-meta-val">${escapeHtml(h.recipient || '—')}</span></div>
                    ${h.notes ? `<div class="h-meta-item" style="grid-column:1/-1;"><span class="h-meta-lbl"><i class="bi bi-chat-left-text me-1"></i>Notes</span><span class="h-meta-val">${escapeHtml(h.notes)}</span></div>` : ''}
                </div>
                ${changePill}
            </div>`;
        }
    </script>
    <script>
        // ── Live clock ──────────────────────────────────────────────
        (function () {
            const c = document.getElementById('tvClock');
            const d = document.getElementById('tvDate');
            const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            function tick() {
                const n = new Date();
                let h = n.getHours(), m = n.getMinutes(), s = n.getSeconds();
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
            const btn = document.getElementById('tvThemeToggle');
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
    </script>
</body>

</html>