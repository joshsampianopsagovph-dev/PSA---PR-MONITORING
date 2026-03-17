<?php
/**
 * sync_google_sheet.php
 *
 * Fetches the Procurement Processing Time data directly from Google Sheets
 * (CSV export) and upserts every row into the `procurement_tracking` table.
 *
 * Prerequisites:
 *   • The Google Sheet MUST be shared as "Anyone with the link → Viewer"
 *   • Sheet ID: 1KeLQOITncXP8G5WxVDbusAf5reFNhKrL
 *   • allow_url_fopen = On  (or use cURL fallback below)
 *
 * Expected sheet layout:
 *   Row 1  = Group header row   (skipped)
 *   Row 2  = Column header row  (skipped — positional mapping used)
 *   Row 3+ = Data rows
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require 'config/database.php';

$response = ['success' => false, 'message' => '', 'data' => []];

// ── Google Sheet config ───────────────────────────────────────────────────────
$sheet_id = '1KeLQOITncXP8G5WxVDbusAf5reFNhKrL';
$csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv&gid=0";

// ── Fetch CSV ─────────────────────────────────────────────────────────────────
$t0 = microtime(true);
$raw = false;

// Try file_get_contents first (works on most Linux/Apache servers)
if (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0 (compatible; PSA-Sync/1.0)\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $raw = @file_get_contents($csv_url, false, $ctx);
}

// cURL fallback (if allow_url_fopen is off)
if ($raw === false && function_exists('curl_init')) {
    $ch = curl_init($csv_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PSA-Sync/1.0)',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
}

$fetch_ms = round((microtime(true) - $t0) * 1000);

// Validate response
if (empty($raw) || trim($raw) === '') {
    $response['message'] = 'Could not fetch data from Google Sheets. '
        . 'Make sure the sheet is shared as "Anyone with the link → Viewer" '
        . 'and allow_url_fopen is enabled in php.ini.';
    echo json_encode($response);
    exit;
}

if (substr(ltrim($raw), 0, 1) === '<') {
    $response['message'] = 'Google Sheets returned an HTML page (access denied or login required). '
        . 'Share the sheet as "Anyone with the link → Viewer" and try again.';
    echo json_encode($response);
    exit;
}

// ── Parse CSV ─────────────────────────────────────────────────────────────────
$raw = str_replace(["\r\n", "\r"], "\n", $raw);
$lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));

if (count($lines) < 3) {
    $response['message'] = 'Sheet has fewer than 3 rows (2 header rows + data). Nothing to sync.';
    echo json_encode($response);
    exit;
}

// Rows 0 & 1 = headers → data starts at index 2
$allRows = [];
for ($i = 2; $i < count($lines); $i++) {
    $r = str_getcsv($lines[$i]);
    while (count($r) < 39)
        $r[] = '';
    $allRows[] = $r;
}

// ── Auto-create / ensure table exists ────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS procurement_tracking (
            id                      INT AUTO_INCREMENT PRIMARY KEY,
            pr_number               VARCHAR(100) NOT NULL,
            particulars             TEXT         DEFAULT NULL,
            end_user                VARCHAR(255) DEFAULT NULL,
            mode_of_procurement     VARCHAR(255) DEFAULT NULL,
            receipt_date_event      VARCHAR(100) DEFAULT NULL,
            receipt_date_received   VARCHAR(100) DEFAULT NULL,
            receipt_expected        VARCHAR(100) DEFAULT NULL,
            receipt_days_delayed    VARCHAR(50)  DEFAULT NULL,
            proc_pr_numbering       VARCHAR(100) DEFAULT NULL,
            proc_date_endorsed      VARCHAR(100) DEFAULT NULL,
            proc_date_earmarked     VARCHAR(100) DEFAULT NULL,
            proc_duration           VARCHAR(50)  DEFAULT NULL,
            proc_days_delayed       VARCHAR(50)  DEFAULT NULL,
            rfq_preparation         VARCHAR(100) DEFAULT NULL,
            rfq_approved            VARCHAR(100) DEFAULT NULL,
            rfq_duration            VARCHAR(50)  DEFAULT NULL,
            rfq_days_delayed        VARCHAR(50)  DEFAULT NULL,
            apq_date_posting        VARCHAR(100) DEFAULT NULL,
            apq_preparation         VARCHAR(100) DEFAULT NULL,
            apq_return_date         VARCHAR(100) DEFAULT NULL,
            apq_approved            VARCHAR(100) DEFAULT NULL,
            apq_duration            VARCHAR(50)  DEFAULT NULL,
            apq_days_delayed        VARCHAR(50)  DEFAULT NULL,
            noa_preparation         VARCHAR(100) DEFAULT NULL,
            noa_approved            VARCHAR(100) DEFAULT NULL,
            noa_date_acknowledged   VARCHAR(100) DEFAULT NULL,
            noa_duration            VARCHAR(50)  DEFAULT NULL,
            noa_days_delayed        VARCHAR(50)  DEFAULT NULL,
            po_issuance_ors         VARCHAR(100) DEFAULT NULL,
            po_smd_to_bd            VARCHAR(100) DEFAULT NULL,
            po_for_caf              VARCHAR(100) DEFAULT NULL,
            po_cafed                VARCHAR(100) DEFAULT NULL,
            po_routed_hope          VARCHAR(100) DEFAULT NULL,
            po_approved             VARCHAR(100) DEFAULT NULL,
            po_date_acknowledged    VARCHAR(100) DEFAULT NULL,
            po_duration             VARCHAR(50)  DEFAULT NULL,
            po_days_delayed         VARCHAR(50)  DEFAULT NULL,
            total_duration          VARCHAR(50)  DEFAULT NULL,
            total_days_delayed      VARCHAR(50)  DEFAULT NULL,
            synced_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pr_number (pr_number),
            INDEX idx_end_user      (end_user(100)),
            INDEX idx_total_days    (total_days_delayed(20))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    $response['message'] = 'Table creation error: ' . $e->getMessage();
    echo json_encode($response);
    exit;
}

// ── Positional column map (0-based) ──────────────────────────────────────────
$COL = [
    'pr_number' => 0,
    'particulars' => 1,
    'end_user' => 2,
    'mode_of_procurement' => 3,
    'receipt_date_event' => 4,
    'receipt_date_received' => 5,
    'receipt_expected' => 6,
    'receipt_days_delayed' => 7,
    'proc_pr_numbering' => 8,
    'proc_date_endorsed' => 9,
    'proc_date_earmarked' => 10,
    'proc_duration' => 11,
    'proc_days_delayed' => 12,
    'rfq_preparation' => 13,
    'rfq_approved' => 14,
    'rfq_duration' => 15,
    'rfq_days_delayed' => 16,
    'apq_date_posting' => 17,
    'apq_preparation' => 18,
    'apq_return_date' => 19,
    'apq_approved' => 20,
    'apq_duration' => 21,
    'apq_days_delayed' => 22,
    'noa_preparation' => 23,
    'noa_approved' => 24,
    'noa_date_acknowledged' => 25,
    'noa_duration' => 26,
    'noa_days_delayed' => 27,
    'po_issuance_ors' => 28,
    'po_smd_to_bd' => 29,
    'po_for_caf' => 30,
    'po_cafed' => 31,
    'po_routed_hope' => 32,
    'po_approved' => 33,
    'po_date_acknowledged' => 34,
    'po_duration' => 35,
    'po_days_delayed' => 36,
    'total_duration' => 37,
    'total_days_delayed' => 38,
];

// ── Build prepared statements ─────────────────────────────────────────────────
$fields = array_keys($COL);
$columns = implode(', ', $fields);
$placeholders = implode(', ', array_map(fn($f) => ":$f", $fields));
$updates = implode(', ', array_map(
    fn($f) => "$f = VALUES($f)",
    array_filter($fields, fn($f) => $f !== 'pr_number')
)) . ', synced_at = NOW()';

$stmt = $pdo->prepare(
    "INSERT INTO procurement_tracking ($columns) VALUES ($placeholders)
     ON DUPLICATE KEY UPDATE $updates"
);
$checkStmt = $pdo->prepare('SELECT id FROM procurement_tracking WHERE pr_number = ? LIMIT 1');

// ── Upsert loop ───────────────────────────────────────────────────────────────
$inserted = $updated = $skipped = 0;

try {
    $pdo->beginTransaction();

    foreach ($allRows as $row) {
        // Skip fully blank rows
        $nonEmpty = array_filter(array_map('trim', $row), fn($v) => $v !== '');
        if (empty($nonEmpty)) {
            $skipped++;
            continue;
        }

        $pr = trim($row[$COL['pr_number']] ?? '');
        if ($pr === '') {
            $skipped++;
            continue;
        }

        $params = [];
        foreach ($COL as $field => $colIdx) {
            $val = trim((string) ($row[$colIdx] ?? ''));
            $params[":$field"] = $val !== '' ? $val : null;
        }

        $checkStmt->execute([$pr]);
        $existed = $checkStmt->fetchColumn();
        $stmt->execute($params);
        $existed ? $updated++ : $inserted++;
    }

    $pdo->commit();

    $response['success'] = true;
    $response['message'] = "✓ Sync complete! {$inserted} inserted, {$updated} updated, {$skipped} skipped. "
        . "(fetch: {$fetch_ms}ms)";
    $response['data'] = [
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'fetch_time_ms' => $fetch_ms,
    ];

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    $response['success'] = false;
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
exit;