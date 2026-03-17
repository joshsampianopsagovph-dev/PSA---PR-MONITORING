<?php
/**
 * create_admin.php
 * ─────────────────────────────────────────────────────────────
 * Run this ONCE in your browser to create your first admin user.
 * Then DELETE this file immediately for security.
 *
 * Usage: http://localhost:8080/create_admin.php
 * ─────────────────────────────────────────────────────────────
 */

require_once 'config/database.php';

// ── SET YOUR DESIRED CREDENTIALS HERE ────────────────────────
$new_username  = 'LaurenzGSD';       // <-- change this
$new_password  = 'Admin@1234';       // <-- change this (min 6 chars)
$new_full_name = 'LaurenzAdmin';    // <-- change this
$new_role      = 'super_admin';      // super_admin has access to admin.php
// ─────────────────────────────────────────────────────────────

try {
    // Create table if needed
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            username   VARCHAR(100) NOT NULL UNIQUE,
            full_name  VARCHAR(150) DEFAULT NULL,
            email      VARCHAR(200) DEFAULT NULL,
            password   VARCHAR(255) NOT NULL DEFAULT '',
            google_id  VARCHAR(100) DEFAULT NULL,
            role       VARCHAR(50)  DEFAULT 'user',
            created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Check if username already exists
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $check->execute([$new_username]);
    $existing = $check->fetch();

    if ($existing) {
        // Update the password if user already exists
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET password = ?, role = ?, full_name = ? WHERE username = ?");
        $upd->execute([$hashed, $new_role, $new_full_name, $new_username]);
        echo "<div style='font-family:sans-serif;padding:2rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;max-width:480px;margin:3rem auto;'>
                <h2 style='color:#166534;'>✅ Password Updated</h2>
                <p>Username: <strong>" . htmlspecialchars($new_username) . "</strong></p>
                <p>Password updated successfully.</p>
                <p style='margin-top:1rem;'><a href='login.php' style='color:#1155b8;font-weight:600;'>→ Go to Login</a></p>
                <p style='color:#dc2626;margin-top:1rem;font-size:0.85rem;'>⚠️ Delete this file now: <code>create_admin.php</code></p>
              </div>";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO users (username, full_name, password, role) VALUES (?, ?, ?, ?)");
        $ins->execute([$new_username, $new_full_name, $hashed, $new_role]);
        echo "<div style='font-family:sans-serif;padding:2rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;max-width:480px;margin:3rem auto;'>
                <h2 style='color:#166534;'>✅ Admin Account Created!</h2>
                <p>Username: <strong>" . htmlspecialchars($new_username) . "</strong></p>
                <p>Password: <strong>" . htmlspecialchars($new_password) . "</strong></p>
                <p>Role: <strong>" . htmlspecialchars($new_role) . "</strong></p>
                <p style='margin-top:1rem;'><a href='login.php' style='color:#1155b8;font-weight:600;'>→ Go to Login</a></p>
                <p style='color:#dc2626;margin-top:1rem;font-size:0.85rem;'>⚠️ Delete this file now: <code>create_admin.php</code></p>
              </div>";
    }
} catch (Exception $e) {
    echo "<div style='font-family:sans-serif;padding:2rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;max-width:480px;margin:3rem auto;'>
            <h2 style='color:#b91c1c;'>❌ Error</h2>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
          </div>";
}