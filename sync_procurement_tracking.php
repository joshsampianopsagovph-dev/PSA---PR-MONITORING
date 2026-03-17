<?php
/**
 * sync_procurement_tracking.php
 *
 * Receives a POST multipart upload of the Procurement Processing Time .xlsx file,
 * parses it using PHP's built-in ZipArchive + SimpleXML (no Composer needed),
 * and upserts every data row into the `procurement_tracking` MySQL table.
 *
 * Expected sheet layout:
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

if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by PHP extension.',
    ];
    $code = $_FILES['xlsx_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $response['message'] = $uploadErrors[$code] ?? "Upload error code $code.";
    echo json_encode($response);
    exit;
}

$file = $_FILES['xlsx_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$maxBytes = 20 * 1024 * 1024; // 20 MB limit

if (!in_array($ext, ['xlsx', 'xls'])) {
    $response['message'] = 'Only .xlsx or .xls files are accepted.';
    echo json_encode($response);
    exit;
}
if ($file['size'] > $maxBytes) {
    $response['message'] = 'File is too large (max 20 MB).';
    echo json_encode($response);
    exit;
}

// ── Parse XLSX using ZipArchive + SimpleXML ───────────────────────────────────
function parseXlsx(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive PHP extension is not enabled. Please enable zip in php.ini.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Could not open the file as a ZIP/XLSX archive. Make sure it is a valid .xlsx file.');
    }

    // ── 1. Load shared strings ────────────────────────────────────────────────
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        libxml_use_internal_errors(true);
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                // Rich text <r><t> or plain <t>
                if (isset($si->r)) {
                    $text = '';
                    foreach ($si->r as $r)
                        $text .= (string) ($r->t ?? '');
                } else {
                    $text = (string) ($si->t ?? '');
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // ── 2. Load styles (for date detection) ──────────────────────────────────
    // Excel stores dates as serial numbers; we detect them via numFmtId.
    $dateFmtIds = [];
    $stylesXml = $zip->getFromName('xl/styles.xml');
    if ($stylesXml !== false) {
        libxml_use_internal_errors(true);
        $styles = simplexml_load_string($stylesXml);
        if ($styles) {
            // Built-in date format IDs: 14–17, 22, 164+ (custom)
            $builtinDateFmts = range(14, 17);
            $builtinDateFmts[] = 22;

            // Collect custom numFmt IDs that look like date patterns
            $customDateFmtIds = [];
            foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
                $id = (int) ($fmt['numFmtId'] ?? 0);
                $pattern = strtolower((string) ($fmt['formatCode'] ?? ''));
                if (preg_match('/[ymd]/', $pattern) && !preg_match('/[hms]/', $pattern)) {
                    $customDateFmtIds[] = $id;
                }
            }

            foreach ($styles->cellXfs->xf ?? [] as $xf) {
                $fmtId = (int) ($xf['numFmtId'] ?? 0);
                $dateFmtIds[] = in_array($fmtId, $builtinDateFmts) || in_array($fmtId, $customDateFmtIds);
            }
        }
    }

    // ── 3. Find first sheet ───────────────────────────────────────────────────
    $sheetName = 'xl/worksheets/sheet1.xml';

    // Check workbook for first sheet reference
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbXml !== false) {
        libxml_use_internal_errors(true);
        $wb = simplexml_load_string($wbXml);
        if ($wb) {
            $ns = $wb->getNamespaces(true);
            $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            // We just use sheet1.xml as the default — most files have it
        }
    }

    // ── 4. Load sheet data ────────────────────────────────────────────────────
    $sheetXml = $zip->getFromName($sheetName);
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Could not find sheet1.xml inside the XLSX. The file may be corrupted or use a non-standard format.');
    }

    libxml_use_internal_errors(true);
    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('Failed to parse sheet XML: ' . libxml_get_last_error()->message ?? 'unknown error');
    }

    // ── 5. Convert column letter(s) to 0-based integer ───────────────────────
    $colLetterToIndex = function (string $letters): int {
        $idx = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $idx = $idx * 26 + (ord($ch) - 64);
        }
        return $idx - 1; // 0-based
    };

    // Excel serial date → formatted string
    $serialToDate = function (float $serial): string {
        // Excel epoch: Jan 1 1900; but Excel wrongly treats 1900 as leap year, so subtract 2
        $unix = ($serial - 25569) * 86400;
        return date('Y-m-d', (int) $unix);
    };

    // ── 6. Build rows array ───────────────────────────────────────────────────
    $rows = [];
    foreach ($sheet->sheetData->row ?? [] as $rowEl) {
        $rowData = [];
        $lastCol = -1;

        foreach ($rowEl->c as $cell) {
            // Parse cell reference "A1", "BC23", etc.
            $ref = (string) ($cell['r'] ?? '');
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            $colLetters = $m[1] ?? 'A';
            $colIdx = $colLetterToIndex($colLetters);

            // Fill sparse gaps with empty strings
            while ($lastCol < $colIdx - 1) {
                $rowData[] = '';
                $lastCol++;
            }
            $lastCol = $colIdx;

            $type = (string) ($cell['t'] ?? '');     // s=shared string, inlineStr, b=bool, n/''=number
            $sIdx = (string) ($cell['s'] ?? '');     // style index for date detection
            $value = (string) ($cell->v ?? '');

            if ($type === 's') {
                // Shared string lookup
                $value = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } elseif ($type === 'b') {
                $value = $value ? 'TRUE' : 'FALSE';
            } elseif ($type === '' || $type === 'n') {
                // Numeric — check if it's a date style
                if ($sIdx !== '' && isset($dateFmtIds[(int) $sIdx]) && $dateFmtIds[(int) $sIdx] && is_numeric($value) && (float) $value > 0) {
                    $value = $serialToDate((float) $value);
                }
            }

            $rowData[] = $value;
        }

        $rows[] = $rowData;
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
    $allRows = parseXlsx($file['tmp_name']);

    if (count($allRows) < 3) {
        throw new Exception('File has fewer than 3 rows. Nothing to sync. Make sure Row 1 is the group header, Row 2 is the column header, and data starts from Row 3.');
    }

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

        // Pad to 39 columns
        while (count($row) < 39)
            $row[] = '';

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
    $response['message'] = "Sync complete! ✓ {$inserted} inserted, ✎ {$updated} updated, — {$skipped} skipped.";
    $response['data'] = ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    $response['success'] = false;
    $response['message'] = 'Sync error: ' . $e->getMessage();
}

echo json_encode($response);
exit;