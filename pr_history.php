<?php
/**
 * pr_history.php
 * API endpoint — returns the transaction history for a given PR.
 *
 * GET  ?pr_id=123   →  JSON { success, pr, history[] }
 */

require 'config/database.php';

header('Content-Type: application/json');

// Auto-create table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS pr_history (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        pr_id         INT          NOT NULL,
        pr_number     VARCHAR(50)  NOT NULL,
        action        VARCHAR(100) NOT NULL,
        changed_by    VARCHAR(150) NOT NULL,
        field_changed VARCHAR(100) DEFAULT NULL,
        old_value     TEXT         DEFAULT NULL,
        new_value     TEXT         DEFAULT NULL,
        destination   VARCHAR(150) DEFAULT NULL,
        recipient     VARCHAR(150) DEFAULT NULL,
        notes         TEXT         DEFAULT NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pr_id (pr_id),
        INDEX idx_pr_number (pr_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if (!isset($_GET['pr_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'pr_id parameter required']);
    exit;
}

$pr_id = (int) $_GET['pr_id'];

// Fetch current PR snapshot (for modal header)
$prStmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = :id LIMIT 1");
$prStmt->execute([':id' => $pr_id]);
$pr = $prStmt->fetch(PDO::FETCH_ASSOC);

// Fetch history rows newest-first
$hStmt = $pdo->prepare("
    SELECT id, pr_number, action, changed_by, field_changed,
           old_value, new_value, destination, recipient, notes, created_at
    FROM   pr_history
    WHERE  pr_id = :pr_id
    ORDER  BY created_at DESC
");
$hStmt->execute([':pr_id' => $pr_id]);
$history = $hStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'pr' => $pr ?: null,
    'history' => $history,
]);