<?php
/**
 * procurement_tracking_action.php
 *
 * Handles inline edits submitted from the Detail View Modal
 * in procurement_tracking.php.
 *
 * POST params:
 *   action      = 'update'
 *   pr_number   = the unique key
 *   + all 37 editable column values
 *
 * Returns JSON { success: bool, message: string }
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require 'config/database.php';

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'update') {
        throw new Exception('Invalid request.');
    }

    $pr = trim($_POST['pr_number'] ?? '');
    if ($pr === '')
        throw new Exception('PR Number is required.');

    // All editable text fields — stored as VARCHAR/TEXT so we accept any string
    $fields = [
        'particulars',
        'end_user',
        'mode_of_procurement',
        'receipt_date_event',
        'receipt_date_received',
        'receipt_expected',
        'receipt_days_delayed',
        'proc_pr_numbering',
        'proc_date_endorsed',
        'proc_date_earmarked',
        'proc_duration',
        'proc_days_delayed',
        'rfq_preparation',
        'rfq_approved',
        'rfq_duration',
        'rfq_days_delayed',
        'apq_date_posting',
        'apq_preparation',
        'apq_return_date',
        'apq_approved',
        'apq_duration',
        'apq_days_delayed',
        'noa_preparation',
        'noa_approved',
        'noa_date_acknowledged',
        'noa_duration',
        'noa_days_delayed',
        'po_issuance_ors',
        'po_smd_to_bd',
        'po_for_caf',
        'po_cafed',
        'po_routed_hope',
        'po_approved',
        'po_date_acknowledged',
        'po_duration',
        'po_days_delayed',
        'total_duration',
        'total_days_delayed',
    ];

    $setParts = [];
    $params = [];
    foreach ($fields as $f) {
        $val = isset($_POST[$f]) ? trim($_POST[$f]) : null;
        $setParts[] = "`$f` = ?";
        $params[] = ($val !== '' && $val !== null) ? $val : null;
    }
    $setParts[] = "`synced_at` = NOW()";
    $params[] = $pr; // for WHERE clause

    $sql = "UPDATE `procurement_tracking` SET " . implode(', ', $setParts) . " WHERE `pr_number` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        // Row might exist but values unchanged — that's still OK
        // Try a SELECT to confirm it exists
        $chk = $pdo->prepare("SELECT id FROM procurement_tracking WHERE pr_number = ? LIMIT 1");
        $chk->execute([$pr]);
        if (!$chk->fetchColumn()) {
            throw new Exception("PR '$pr' not found in the database.");
        }
    }

    $response['success'] = true;
    $response['message'] = 'Record updated successfully.';

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit;