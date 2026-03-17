<?php
require 'config/database.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ?");
    $stmt->execute([$id]);
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pr) {
        echo json_encode($pr);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'PR not found']);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'ID parameter required']);
}
?>