<?php
/**
 * get_tracking.php
 * Fetch accurate tracking information for a specific PR
 * Returns JSON with tracking data from pr_status_tracking table
 * Correlates with purchase_requests table for complete information
 */

require 'config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'tracking' => [], 'message' => ''];

try {
    if (!isset($_GET['pr_number'])) {
        throw new Exception('PR Number parameter required');
    }

    $pr_number = $_GET['pr_number'];

    // Ensure tracking table exists with necessary columns
    try {
        $checkTable = "SHOW COLUMNS FROM pr_status_tracking";
        $stmt = $pdo->query($checkTable);
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }

        // Add missing columns if they don't exist
        if (!in_array('edited_by', $columns)) {
            $pdo->exec("ALTER TABLE pr_status_tracking ADD COLUMN edited_by VARCHAR(255) DEFAULT NULL");
        }
        if (!in_array('times_edited', $columns)) {
            $pdo->exec("ALTER TABLE pr_status_tracking ADD COLUMN times_edited INT DEFAULT 0");
        }
        if (!in_array('created_by', $columns)) {
            $pdo->exec("ALTER TABLE pr_status_tracking ADD COLUMN created_by VARCHAR(255) DEFAULT NULL");
        }
    } catch (Exception $e) {
        // Table might not exist yet, fallback to purchase_requests only
    }

    // First, fetch PR main data from purchase_requests
    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE pr_number = ? LIMIT 1");
    $stmt->execute([$pr_number]);
    $pr_main = $stmt->fetch(PDO::FETCH_ASSOC);

    // Then fetch tracking data if it exists
    $tracking_data = null;
    $posted = 'No';
    $canvas = 'No';
    $category = 'N/A';
    $created_by_user = 'System';
    $times_edited = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT 
                pr_number,
                end_user,
                posted,
                canvas,
                category,
                created_at,
                updated_at,
                edited_by,
                created_by,
                times_edited
            FROM pr_status_tracking
            WHERE pr_number = ?
            LIMIT 1
        ");
        $stmt->execute([$pr_number]);
        $tracking_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist, continue with PR data only
    }

    // Determine which dates to use and extract tracking info
    $date_created = 'N/A';
    $date_modified = 'N/A';

    if ($tracking_data) {
        // Use tracking table data for accurate dates
        $date_created = $tracking_data['created_at']
            ? date('M d, Y H:i', strtotime($tracking_data['created_at']))
            : 'N/A';
        $date_modified = $tracking_data['updated_at']
            ? date('M d, Y H:i', strtotime($tracking_data['updated_at']))
            : ($date_created !== 'N/A' ? $date_created : 'N/A');

        $posted = $tracking_data['posted'] ? 'Yes' : 'No';
        $canvas = $tracking_data['canvas'] ? 'Yes' : 'No';
        $category = $tracking_data['category'] ?? 'N/A';
        $created_by_user = $tracking_data['created_by'] ?? 'System';
        $times_edited = $tracking_data['times_edited'] ?? 0;
    } elseif ($pr_main) {
        // Fallback to purchase_requests table timestamps if available
        if ($pr_main['created_at'] ?? null) {
            $date_created = date('M d, Y H:i', strtotime($pr_main['created_at']));
        }
        if ($pr_main['updated_at'] ?? null) {
            $date_modified = date('M d, Y H:i', strtotime($pr_main['updated_at']));
        } else {
            $date_modified = $date_created;
        }
    }

    if ($pr_main || $tracking_data) {
        $response['success'] = true;
        $response['tracking'] = [
            'pr_number' => $pr_number,
            'end_user' => $tracking_data['end_user'] ?? $pr_main['end_user'] ?? 'N/A',
            'category' => $category,
            'posted' => $posted,
            'canvas' => $canvas,
            'date_created' => $date_created,
            'date_modified' => $date_modified,
            'edited_by' => $created_by_user,
            'times_edited' => $times_edited
        ];
    } else {
        // No data found for this PR
        $response['success'] = true;
        $response['tracking'] = [
            'pr_number' => $pr_number,
            'end_user' => 'N/A',
            'category' => 'N/A',
            'posted' => 'No',
            'canvas' => 'No',
            'date_created' => 'N/A',
            'date_modified' => 'N/A',
            'edited_by' => 'Unknown',
            'times_edited' => 0
        ];
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>