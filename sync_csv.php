<?php
/**
 * sync_csv.php
 *
 * Receives a POST multipart upload of the Procurement Processing Time CSV file,
 * parses it, and upserts every data row into the `procurement_tracking` MySQL table.
 *
 * Expected CSV layout:
 *   Row 0  = group header row   (skipped)
 *   Row 1  = column header row  (skipped — we use positional mapping)
 *   Row 2+ = data rows
 *
 * Column map (0-based):
 *   0  pr_number              1  particulars
 *   2  end_user               3  mode_of_procurement
 *   4  receipt_date_event     5  receipt_date_received
 *   6  receipt_expected       7  receipt_days_delayed
 *   8  proc_pr_numbering      9  proc_date_endorsed
 *  10  proc_date_earmarked   11  proc_duration
 *  12  proc_days_delayed     13  rfq_preparation
 *  14  rfq_approved          15  rfq_duration
 *  16  rfq_days_delayed      17  apq_date_posting
 *  18  apq_preparation       19  apq_return_date
 *  20  apq_approved          21  apq_duration
 *  22  apq_days_delayed      23  noa_preparation
 *  24  noa_approved          25  noa_date_acknowledged
 *  26  noa_duration          27  noa_days_delayed
 *  28  po_issuance_ors       29  po_smd_to_bd
 *  30  po_for_caf            31  po_cafed
 *  32  po_routed_hope        33  po_approved
 *  34  po_date_acknowledged  35  po_duration
 *  36  po_days_delayed       37  total_duration
 *  38  total_days_delayed
 *
 * Returns JSON { success, message, data: { inserted, updated, skipped } }
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require 'config/database.php';

$response = ['success' => false, 'message' => '', 'data' => []];

// ── Validate upload ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'POST request required.';
    echo json_encode($response);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by PHP extension.',
    ];
    $code = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $response['message'] = $uploadErrors[$code] ?? "Upload error code $code.";
    echo json_encode($response);
    exit;
}

$file = $_FILES['csv_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$maxBytes = 20 * 1024 * 1024; // 20 MB limit

if (!in_array($ext, ['csv'])) {
    $response['message'] = 'Only .csv files are accepted.';
    echo json_encode($response);
    exit;
}
if ($file['size'] > $maxBytes) {
    $response['message'] = 'File is too large (max 20 MB).';
    echo json_encode($response);
    exit;
}

// ── Parse CSV ─────────────────────────────────────────────────────────────────
function parseCSV(string $filePath): array
{
    $rows = [];

    // Open file in read mode
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new RuntimeException('Could not open CSV file for reading.');
    }

    // Detect encoding and convert if needed
    while (($row = fgetcsv($handle)) !== false) {
        // Ensure row has enough columns (pad with empty strings if needed)
        while (count($row) < 39) {
            $row[] = '';
        }
        $rows[] = $row;
    }

    fclose($handle);

    if (count($rows) < 3) {
        throw new RuntimeException('CSV has fewer than 3 rows. Expected: Row 1 = group header, Row 2 = column header, Row 3+ = data.');
    }

    return $rows;
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
            synced_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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

// ── Parse & upsert ────────────────────────────────────────────────────────────
try {
    $t0 = microtime(true);
    $allRows = parseCSV($file['tmp_name']);
    $parse_ms = round((microtime(true) - $t0) * 1000);

    // ── Positional column map (0-based) ───────────────────────────────────────
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

    $fields = array_keys($COL);
    $columns = implode(', ', $fields);
    $placeholders = implode(', ', array_map(fn($f) => ":$f", $fields));
    $updates = implode(', ', array_map(
        fn($f) => "$f = VALUES($f)",
        array_filter($fields, fn($f) => $f !== 'pr_number')
    )) . ', synced_at = NOW()';

    $stmt = $pdo->prepare("INSERT INTO procurement_tracking ($columns) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates");
    $checkStmt = $pdo->prepare('SELECT id FROM procurement_tracking WHERE pr_number = ? LIMIT 1');

    $inserted = $updated = $skipped = 0;

    $pdo->beginTransaction();

    // Skip rows 0 (group header) and 1 (column header) → start at index 2
    for ($i = 2; $i < count($allRows); $i++) {
        $row = $allRows[$i];

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
        . "(parse: {$parse_ms}ms)";
    $response['data'] = [
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'parse_time_ms' => $parse_ms,
    ];

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    $response['success'] = false;
    $response['message'] = 'Sync error: ' . $e->getMessage();
}

echo json_encode($response);
exit;
