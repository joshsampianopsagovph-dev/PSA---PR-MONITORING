<?php
/**
 * pr_action.php
 *
 * Web-accessible endpoint to:
 * - Handle add/edit form submissions from modals
 * - Auto-log transaction history to pr_history table
 *
 * Returns JSON: { success: bool, message: string, data: {...} }
 */

header('Content-Type: application/json');

require 'config/database.php';

$response = ['success' => false, 'message' => '', 'data' => []];

// ── Helper: auto-create history table & insert one row ────────────────────
function logHistory(PDO $pdo, array $args): void
{
    // Create table if it doesn't exist yet (runs once, fast after that)
    static $ready = false;
    if (!$ready) {
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
        $ready = true;
    }

    $stmt = $pdo->prepare("
        INSERT INTO pr_history
            (pr_id, pr_number, action, changed_by, field_changed, old_value, new_value, destination, recipient, notes)
        VALUES
            (:pr_id, :pr_number, :action, :changed_by, :field_changed, :old_value, :new_value, :destination, :recipient, :notes)
    ");
    $stmt->execute([
        ':pr_id' => (int) ($args['pr_id'] ?? 0),
        ':pr_number' => $args['pr_number'] ?? '',
        ':action' => $args['action'] ?? 'EDITED',
        ':changed_by' => $args['changed_by'] ?? 'Unknown',
        ':field_changed' => $args['field_changed'] ?? null,
        ':old_value' => $args['old_value'] ?? null,
        ':new_value' => $args['new_value'] ?? null,
        ':destination' => $args['destination'] ?? null,
        ':recipient' => $args['recipient'] ?? null,
        ':notes' => $args['notes'] ?? null,
    ]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
        throw new Exception('Invalid request.');
    }

    $action = $_POST['action'];

    if ($action === 'add') {
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

        if (!$pr_number || !$title || !$processor || !$status) {
            throw new Exception('PR Number, Title, Processor, and Status are required');
        }

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

        $newId = (int) $pdo->lastInsertId();

        // ── Log: PR Created ──────────────────────────────────────────────
        logHistory($pdo, [
            'pr_id' => $newId,
            'pr_number' => $pr_number,
            'action' => 'CREATED',
            'changed_by' => $processor,
            'destination' => $end_user ?: null,
            'recipient' => $supplier ?: null,
            'notes' => 'PR created. Status: ' . $status,
        ]);

        $response['success'] = true;
        $response['message'] = 'PR added successfully';

    } elseif ($action === 'edit') {
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

        $date_endorsement = !empty($date_endorsement) ? $date_endorsement : null;
        $cancelled = !empty($cancelled) ? $cancelled : null;
        $paid_date = !empty($paid_date) ? $paid_date : null;
        $change_schedule = !empty($change_schedule) ? $change_schedule : null;
        $remarks = !empty($remarks) ? $remarks : null;

        // ── Fetch OLD values before updating ─────────────────────────────
        $oldStmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? LIMIT 1");
        $oldStmt->execute([$id]);
        $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);

        if (!$oldData)
            throw new Exception('PR not found.');

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

        // ── Detect changed fields and log each one ────────────────────────
        $newValues = [
            'processor' => $processor,
            'supplier' => $supplier,
            'end_user' => $end_user,
            'status' => $status,
            'date_endorsement' => $date_endorsement,
            'paid_date' => $paid_date,
            'cancelled' => $cancelled,
            'change_schedule' => $change_schedule,
            'remarks' => $remarks,
        ];

        $anyLogged = false;
        foreach ($newValues as $field => $newVal) {
            $oldVal = $oldData[$field] ?? '';
            if (trim((string) $oldVal) === trim((string) $newVal))
                continue; // no change

            // Determine action label
            $actionLabel = 'EDITED';
            if ($field === 'status')
                $actionLabel = 'STATUS CHANGE';
            if ($field === 'paid_date' && !empty($newVal))
                $actionLabel = 'PAID';
            if ($field === 'cancelled' && !empty($newVal))
                $actionLabel = 'CANCELLED';

            logHistory($pdo, [
                'pr_id' => (int) $id,
                'pr_number' => $oldData['pr_number'],
                'action' => $actionLabel,
                'changed_by' => $processor ?: ($oldData['processor'] ?? 'Unknown'),
                'field_changed' => strtoupper(str_replace('_', ' ', $field)),
                'old_value' => $oldVal !== '' && $oldVal !== null ? $oldVal : null,
                'new_value' => $newVal !== '' && $newVal !== null ? $newVal : null,
                'destination' => $end_user ?: ($oldData['end_user'] ?? null),
                'recipient' => $supplier ?: ($oldData['supplier'] ?? null),
                'notes' => null,
            ]);
            $anyLogged = true;
        }

        // If nothing specific changed (e.g. only whitespace diff), log generic edit
        if (!$anyLogged) {
            logHistory($pdo, [
                'pr_id' => (int) $id,
                'pr_number' => $oldData['pr_number'],
                'action' => 'EDITED',
                'changed_by' => $processor ?: ($oldData['processor'] ?? 'Unknown'),
                'destination' => $end_user ?: ($oldData['end_user'] ?? null),
                'recipient' => $supplier ?: ($oldData['supplier'] ?? null),
                'notes' => 'PR record opened and saved (no field changes detected).',
            ]);
        }

        $response['success'] = true;
        $response['message'] = 'PR updated successfully';

    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit;