<?php
$host = "localhost";
$dbname = "pr_tracker";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=localhost;port=4306;dbname=pr_tracker", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>