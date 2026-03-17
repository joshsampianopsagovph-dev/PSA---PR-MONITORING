<?php
/**
 * sync_action.php
 *
 * Web-accessible endpoint to:
 * 1. Sync Google Sheet data into the database
 * 2. Handle add/edit form submissions from modals
 * 3. Sync PR Status Tracking sheet
 *
 * Returns JSON: { success: bool, message: string, data: {...} }
 */

header('Content-Type: application/json');

require 'config/database.php';

$response = ['success' => false, 'message' => '', 'data' => []];

// ===== HANDLE ADD/EDIT FROM MODAL FORMS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        if ($action === 'add') {
            // Add new PR
            $pr_number = $_POST['pr_number'] ?? '';
            $title = $_POST['title'] ?? '';
            $processor = $_POST['processor'] ?? '';
            $supplier = $_POST['supplier'] ?? '';
            $end_user = $_POST['end_user'] ?? '';
            $status = $_POST['status'] ?? '';
            $date_endorsement = $_POST['date_endorsement'] ?? null;
            $cancelled = $_POST['cancelled'] ?? null;
            $paid_date = $_POST['paid_date'] ?? null;
            $change_schedule = $_POST['change_schedule'] ?? null;
            $remarks = $_POST['remarks'] ?? null;

            // Validate required fields
            if (!$pr_number || !$title || !$processor || !$status) {
                throw new Exception('PR Number, Title, Processor, and Status are required');
            }

            // Convert empty strings to null for date fields
            $date_endorsement = !empty($date_endorsement) ? $date_endorsement : null;
            $cancelled = !empty($cancelled) ? $cancelled : null;
            $paid_date = !empty($paid_date) ? $paid_date : null;
            $change_schedule = !empty($change_schedule) ? $change_schedule : null;
            $remarks = !empty($remarks) ? $remarks : null;

            $stmt = $pdo->prepare(
                "INSERT INTO purchase_requests 
                (pr_number, title, processor, supplier, end_user, status, date_endorsement, paid_date, cancelled, change_schedule, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $pr_number,
                $title,
                $processor,
                $supplier,
                $end_user,
                $status,
                $date_endorsement,
                $paid_date,
                $cancelled,
                $change_schedule,
                $remarks
            ]);

            $response['success'] = true;
            $response['message'] = 'PR added successfully';

        } elseif ($action === 'edit') {
            // Edit existing PR
            $id = $_POST['id'] ?? null;
            $processor = $_POST['processor'] ?? '';
            $supplier = $_POST['supplier'] ?? '';
            $end_user = $_POST['end_user'] ?? '';
            $status = $_POST['status'] ?? '';
            $date_endorsement = $_POST['date_endorsement'] ?? null;
            $cancelled = $_POST['cancelled'] ?? null;
            $paid_date = $_POST['paid_date'] ?? null;
            $change_schedule = $_POST['change_schedule'] ?? null;
            $remarks = $_POST['remarks'] ?? null;

            if (!$id) {
                throw new Exception('ID is required for edit');
            }

            // Convert empty strings to null for date fields
            $date_endorsement = !empty($date_endorsement) ? $date_endorsement : null;
            $cancelled = !empty($cancelled) ? $cancelled : null;
            $paid_date = !empty($paid_date) ? $paid_date : null;
            $change_schedule = !empty($change_schedule) ? $change_schedule : null;
            $remarks = !empty($remarks) ? $remarks : null;

            $stmt = $pdo->prepare(
                "UPDATE purchase_requests 
                SET processor = ?, supplier = ?, end_user = ?, status = ?, 
                    date_endorsement = ?, paid_date = ?, cancelled = ?, change_schedule = ?, remarks = ?
                WHERE id = ?"
            );
            $stmt->execute([
                $processor,
                $supplier,
                $end_user,
                $status,
                $date_endorsement,
                $paid_date,
                $cancelled,
                $change_schedule,
                $remarks,
                $id
            ]);

            $response['success'] = true;
            $response['message'] = 'PR updated successfully';
        }

        echo json_encode($response);
        exit;

    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = 'Error: ' . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

// ===== HANDLE PR STATUS TRACKING SHEET SYNC (No Database Storage) =====
if (!empty($_GET['sync_type']) && $_GET['sync_type'] === 'pr_status_tracking') {
    try {
        $pr_status_sheet_url = 'https://docs.google.com/spreadsheets/d/1TP5cu3kwKE_h_jwe5UZ8XLZCDBlKKlWkHTyg7Vqi6-o/edit?usp=sharing';

        // Robustly extract sheet ID and build clean CSV export URL
        preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $pr_status_sheet_url, $idMatch);
        $sheet_id = $idMatch[1] ?? '';
        $sheet_csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv&gid=0";

        $csv = @file_get_contents($sheet_csv_url);
        if ($csv === false) {
            throw new Exception('Failed to fetch CSV from Google Sheets. Ensure the sheet is publicly shared.');
        }

        $lines = array_filter(array_map('trim', explode("\n", $csv)));
        if (!is_array($lines) || count($lines) < 2) {
            throw new Exception('No data rows found in the Google Sheet.');
        }

        $rows = array_map('str_getcsv', $lines);

        // ===== HEADER-AWARE COLUMN MAPPING =====
        $header_row = array_map('trim', $rows[0] ?? []);
        $col = [];
        foreach ($header_row as $i => $h) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $h));
            $col[$key] = $i;
        }
        $idx_end_user = $col['enduser'] ?? $col['enduser'] ?? $col['user'] ?? 0;
        $idx_pr = $col['prnumber'] ?? $col['prnumber'] ?? $col['prno'] ?? $col['pr'] ?? 1;
        $idx_posted = $col['posted'] ?? 2;
        $idx_canvas = $col['canvas'] ?? 3;
        $idx_category = $col['category'] ?? $col['cat'] ?? 4;

        $inserted = 0;
        $processing = 0;

        for ($r = 1; $r < count($rows); $r++) {
            if (!isset($rows[$r]) || !is_array($rows[$r])) {
                continue;
            }
            $row = $rows[$r];

            // Check if row is empty
            $allEmpty = true;
            foreach ($row as $c) {
                if (trim((string) $c) !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }

            // Parse values using header-mapped indices; normalize category to uppercase
            $posted_val = in_array(strtolower(trim($row[$idx_posted] ?? '')), ['true', '1', 'yes', 'y']) ? 'Yes' : 'No';
            $canvas_val = in_array(strtolower(trim($row[$idx_canvas] ?? '')), ['true', '1', 'yes', 'y']) ? 'Yes' : 'No';
            $category = strtoupper(trim($row[$idx_category] ?? ''));
            $pr_number = trim($row[$idx_pr] ?? '');

            if ($pr_number !== '') {
                $processing++;
                $inserted++;
            }
        }

        $response['success'] = true;
        $response['message'] = "Sheet synced successfully! Processed: $processing rows from Google Sheet (Posted, Canvas, Category data).";
        $response['data'] = ['processed' => $processing];

        echo json_encode($response);
        exit;

    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = 'Sync error: ' . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

// ===== HANDLE GOOGLE SHEETS SYNC (original code) =====

try {
    $google_sheet_url = 'https://docs.google.com/spreadsheets/d/1EDnM03nL8qv9QOU2TZyGQi5ofuxKkaBqPtnORoRi4uY/edit?gid=213392969#gid=213392969';

    // build CSV export URL
    $sheet_csv_url = preg_replace('/\/edit.*$/', '/export?format=csv', $google_sheet_url);

    // try to preserve gid if present
    $parts = parse_url($google_sheet_url);
    if (isset($parts['query'])) {
        parse_str($parts['query'], $qs);
        if (isset($qs['gid'])) {
            $sheet_csv_url .= '&gid=' . urlencode($qs['gid']);
        }
    }

    $csv = @file_get_contents($sheet_csv_url);
    if ($csv === false) {
        throw new Exception('Failed to fetch CSV from Google Sheets. Ensure the sheet is publicly shared.');
    }

    $lines = array_filter(array_map('trim', explode("\n", $csv)));
    if (!is_array($lines) || count($lines) < 2) {
        throw new Exception('No data rows found in the Google Sheet.');
    }

    $rows = array_map('str_getcsv', $lines);
    if (!isset($rows[0]) || !is_array($rows[0])) {
        throw new Exception('Invalid CSV header row.');
    }

    $headers = array_map('trim', $rows[0]);

    // Header normalization and mapping
    $normalizeHeader = function ($h) {
        $h = trim(strtolower($h));
        $h = preg_replace('/[^a-z0-9]/', '', $h);
        return $h;
    };

    $headerMap = [
        'pr' => 'pr_number',
        'prnumber' => 'pr_number',
        'prno' => 'pr_number',
        'title' => 'title',
        'processor' => 'processor',
        'supplier' => 'supplier',
        'enduser' => 'end_user',
        'enduserdepartment' => 'end_user',
        'status' => 'status',
        'dateendorsement' => 'date_endorsement',
        'dateofendorsement' => 'date_endorsement',
        'endorsementdate' => 'date_endorsement',
        'cancelled' => 'cancelled',
        'cancel' => 'cancelled',
        'paid' => 'paid_date',
        'paiddate' => 'paid_date',
        'changeofscheduledeliverydate' => 'change_schedule',
        'changeofschedule' => 'change_schedule',
        'changeschedule' => 'change_schedule',
        'deliverydate' => 'change_schedule',
        'scheduledelivery' => 'change_schedule',
        'remarks' => 'remarks',
        'remark' => 'remarks',
        'notes' => 'remarks'
    ];

    $mapHeader = function ($h) use ($normalizeHeader, $headerMap) {
        $n = $normalizeHeader($h);
        return $headerMap[$n] ?? null;
    };

    // Prepare upsert statements
    $selectStmt = $pdo->prepare('SELECT id FROM purchase_requests WHERE pr_number = ? LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO purchase_requests (pr_number, title, processor, supplier, end_user, status, date_endorsement, paid_date, cancelled, change_schedule, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $updateStmt = $pdo->prepare('UPDATE purchase_requests SET title = ?, processor = ?, supplier = ?, end_user = ?, status = ?, date_endorsement = ?, paid_date = ?, cancelled = ?, change_schedule = ?, remarks = ? WHERE id = ?');

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    $pdo->beginTransaction();

    for ($r = 1; $r < count($rows); $r++) {
        if (!isset($rows[$r]) || !is_array($rows[$r])) {
            continue;
        }
        $row = $rows[$r];

        $allEmpty = true;
        foreach ($row as $c) {
            if (trim((string) $c) !== '') {
                $allEmpty = false;
                break;
            }
        }
        if ($allEmpty) {
            $skipped++;
            continue;
        }

        $item = [
            'pr_number' => '',
            'title' => '',
            'processor' => '',
            'supplier' => '',
            'end_user' => '',
            'status' => '',
            'date_endorsement' => null,
            'paid_date' => null,
            'cancelled' => null,
            'change_schedule' => null,
            'remarks' => ''
        ];

        foreach ($headers as $ci => $h) {
            $key = $mapHeader($h);
            if ($key !== null && isset($row[$ci])) {
                $item[$key] = trim($row[$ci]);
            }
        }

        // Fallback: use first column as PR# if not mapped
        if (trim($item['pr_number']) === '' && isset($row[0])) {
            $firstCol = trim($row[0]);
            // only use it if it looks like a valid PR (has dashes and numbers, not text)
            if (preg_match('/\d{2}-\d{2}-\d{3,6}/', $firstCol)) {
                $item['pr_number'] = $firstCol;
            }
        }

        // Skip if no valid PR number
        if (trim($item['pr_number']) === '' || !preg_match('/\d{2}-\d{2}-\d{3,6}/', trim($item['pr_number']))) {
            $skipped++;
            continue;
        }

        // Normalize date fields
        $tryDate = function ($v) {
            $v = trim((string) $v);
            if ($v === '' || $v === '0000-00-00')
                return null;
            $ts = strtotime($v);
            if ($ts === false)
                return $v;
            return date('Y-m-d', $ts);
        };
        $item['date_endorsement'] = $tryDate($item['date_endorsement']);
        $item['paid_date'] = $tryDate($item['paid_date']);
        $item['cancelled'] = $tryDate($item['cancelled']);
        $item['change_schedule'] = $tryDate($item['change_schedule']);

        // Upsert by pr_number
        $selectStmt->execute([$item['pr_number']]);
        $existingId = $selectStmt->fetchColumn();

        if ($existingId) {
            $updateStmt->execute([
                $item['title'],
                $item['processor'],
                $item['supplier'],
                $item['end_user'],
                $item['status'],
                $item['date_endorsement'],
                $item['paid_date'],
                $item['cancelled'],
                $item['change_schedule'],
                $item['remarks'],
                $existingId
            ]);
            $updated++;
        } else {
            $insertStmt->execute([
                $item['pr_number'],
                $item['title'],
                $item['processor'],
                $item['supplier'],
                $item['end_user'],
                $item['status'],
                $item['date_endorsement'],
                $item['paid_date'],
                $item['cancelled'],
                $item['change_schedule'],
                $item['remarks']
            ]);
            $inserted++;
        }
    }

    $pdo->commit();

    $response['success'] = true;
    $response['message'] = "Sync complete! Inserted: $inserted, Updated: $updated, Skipped: $skipped.";
    $response['data'] = ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['success'] = false;
    $response['message'] = 'Sync error: ' . $e->getMessage();
}

echo json_encode($response);
exit;