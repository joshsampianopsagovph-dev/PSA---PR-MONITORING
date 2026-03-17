<?php
/**
 * login.php
 * Standard user login — PSA 2026 Procurement Monitoring System
 */

session_start();

if (isset($_SESSION['pms_user'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Show success message after signup redirect
if (isset($_GET['ref']) && $_GET['ref'] === 'signup_success') {
    $success = 'Account created successfully! Please log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_logs (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT DEFAULT NULL,
            username   VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45)  DEFAULT NULL,
            user_agent TEXT         DEFAULT NULL,
            status     ENUM('success','failed') DEFAULT 'success',
            logged_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(username), INDEX(logged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        try {
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

            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            if ($user && password_verify($password, $user['password'])) {

                // ── Super admin: silently redirect to the correct portal ──────
                if (($user['role'] ?? '') === 'super_admin') {
                    header('Location: admin_login.php?ref=redirect');
                    exit;
                }

                // ── Regular user / admin: log in normally ────────────────────
                $pdo->prepare("INSERT INTO login_logs (user_id, username, ip_address, user_agent, status) VALUES (?,?,?,?,'success')")
                    ->execute([$user['id'], $username, $ip, $ua]);
                try {
                    $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                } catch (Exception $e) {
                }

                session_regenerate_id(true);
                $_SESSION['pms_user'] = $user['username'];
                $_SESSION['pms_user_id'] = $user['id'];
                $_SESSION['pms_role'] = $user['role'] ?? 'user';
                $_SESSION['pms_department'] = $user['department'] ?? 'OTHER';
                header('Location: index.php');
                exit;

            } else {
                $uid = $user ? $user['id'] : null;
                $pdo->prepare("INSERT INTO login_logs (user_id, username, ip_address, user_agent, status) VALUES (?,?,?,?,'failed')")
                    ->execute([$uid, $username, $ip, $ua]);
                $error = 'Invalid username or password.';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — PSA Procurement Monitoring 2026</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sora:wght@700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --navy: #0b1d4e;
            --blue: #1155b8;
            --blue-mid: #1580C4;
            --teal: #00a8c6;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-400: #94a3b8;
            --gray-600: #475569;
            --gray-800: #1e293b;
        }

        html,
        body {
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: #b8c8e0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: radial-gradient(rgba(17, 85, 184, 0.18) 1.5px, transparent 1.5px);
            background-size: 30px 30px;
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(17, 85, 184, 0.14) 0%, transparent 70%);
            top: -150px;
            left: -150px;
            pointer-events: none;
            z-index: 0;
        }

        .blob2 {
            position: fixed;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0, 168, 198, 0.12) 0%, transparent 70%);
            bottom: -120px;
            right: -100px;
            pointer-events: none;
            z-index: 0;
        }

        .page {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 920px;
            padding: 1.5rem;
            animation: fadeUp 0.6s cubic-bezier(.22, 1, .36, 1) both;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(24px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .card {
            display: flex;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(11, 29, 78, 0.28), 0 2px 0 rgba(255, 255, 255, 0.5) inset;
            min-height: 500px;
        }

        .panel-left {
            flex: 0 0 44%;
            background: linear-gradient(155deg, #0b1d4e 0%, #1155b8 50%, #00a8c6 100%);
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .panel-left::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.055);
            bottom: -80px;
            right: -70px;
            pointer-events: none;
        }

        .panel-left::after {
            content: '';
            position: absolute;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.04);
            top: -50px;
            left: -40px;
            pointer-events: none;
        }

        .dc {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            pointer-events: none;
        }

        .dc1 {
            width: 80px;
            height: 80px;
            top: 32%;
            right: -16px;
        }

        .dc2 {
            width: 48px;
            height: 48px;
            top: 18%;
            left: 12px;
        }

        .dc3 {
            width: 36px;
            height: 36px;
            bottom: 24%;
            left: 28px;
            background: rgba(255, 255, 255, 0.1);
        }

        .left-brand {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .left-brand img {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: contain;
            background: #fff;
            padding: 4px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
        }

        .left-brand-text {
            font-size: 0.65rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            line-height: 1.4;
        }

        .left-body {
            position: relative;
            z-index: 2;
        }

        .left-headline {
            font-family: 'Sora', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: #fff;
            line-height: 1.18;
            margin-bottom: 0.9rem;
        }

        .left-sub {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.72);
            line-height: 1.65;
        }

        .illustration {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            padding: 1.5rem 0;
        }

        .illus-wrap {
            position: relative;
            width: 170px;
            height: 130px;
        }

        .ic {
            position: absolute;
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.13);
            border: 1px solid rgba(255, 255, 255, 0.22);
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .ic1 {
            width: 90px;
            height: 72px;
            top: 0;
            left: 8px;
            background: rgba(255, 255, 255, 0.17);
        }

        .ic2 {
            width: 72px;
            height: 58px;
            top: 22px;
            right: 0;
            background: rgba(0, 168, 198, 0.28);
        }

        .ic3 {
            width: 100px;
            height: 44px;
            bottom: 0;
            left: 0;
            background: rgba(255, 255, 255, 0.10);
        }

        .ic i {
            font-size: 1.7rem;
            color: rgba(255, 255, 255, 0.88);
        }

        .ic2 i {
            font-size: 1.35rem;
        }

        .ic3 i {
            font-size: 1.1rem;
        }

        .left-footer {
            position: relative;
            z-index: 2;
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.38);
            letter-spacing: 0.04em;
        }

        .panel-right {
            flex: 1;
            background: #fff;
            padding: 3rem 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-heading {
            font-family: 'Sora', sans-serif;
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--gray-800);
            margin-bottom: 0.3rem;
        }

        .form-sub {
            font-size: 0.85rem;
            color: var(--gray-400);
            margin-bottom: 1.8rem;
        }

        .alert-error {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 0.65rem 1rem;
            font-size: 0.82rem;
            font-weight: 500;
            color: #b91c1c;
            margin-bottom: 1.2rem;
        }

        .field {
            margin-bottom: 1.05rem;
        }

        .field label {
            display: block;
            font-size: 0.76rem;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 0.38rem;
            letter-spacing: 0.025em;
            text-transform: uppercase;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap .icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-wrap input {
            width: 100%;
            padding: 0.7rem 2.5rem;
            border: 1.5px solid var(--gray-200);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 0.88rem;
            color: var(--gray-800);
            background: var(--gray-100);
            outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
        }

        .input-wrap input::placeholder {
            color: var(--gray-400);
        }

        .input-wrap input:focus {
            border-color: var(--blue);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(17, 85, 184, 0.11);
        }

        .input-wrap:focus-within .icon {
            color: var(--blue);
        }

        .btn-toggle-pw {
            position: absolute;
            right: 11px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-400);
            font-size: 0.88rem;
            padding: 4px;
            transition: color 0.2s;
        }

        .btn-toggle-pw:hover {
            color: var(--blue);
        }

        .btn-login {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 11px;
            background: linear-gradient(135deg, #0b1d4e 0%, #1155b8 55%, #1580C4 100%);
            color: #fff;
            font-family: 'Sora', sans-serif;
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 18px rgba(17, 85, 184, 0.38);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
            margin-top: 1.4rem;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 200%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(17, 85, 184, 0.48);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-spinner {
            display: none;
            width: 15px;
            height: 15px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        .status-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.4rem;
            font-size: 0.75rem;
            color: var(--gray-400);
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 6px #22c55e;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0.4
            }
        }

        .admin-portal-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.1rem;
            padding: 0.65rem 1rem;
            border: 1px dashed rgba(124, 58, 237, 0.4);
            border-radius: 10px;
            background: rgba(124, 58, 237, 0.05);
            text-decoration: none;
            color: #6d28d9;
            font-size: 0.78rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .admin-portal-link:hover {
            background: rgba(124, 58, 237, 0.1);
            border-color: rgba(124, 58, 237, 0.6);
            color: #5b21b6;
        }

        .admin-portal-link i {
            font-size: 0.88rem;
        }

        @media(max-width:620px) {
            .panel-left {
                display: none;
            }

            .panel-right {
                padding: 2.5rem 1.75rem;
            }
        }
    </style>
</head>

<body>
    <div class="blob2"></div>
    <div class="page">
        <div class="card">

            <!-- LEFT -->
            <div class="panel-left">
                <div class="dc dc1"></div>
                <div class="dc dc2"></div>
                <div class="dc dc3"></div>
                <div class="left-brand">
                    <img src="psa.png" alt="PSA" onerror="this.style.display='none'">
                    <div class="left-brand-text">Philippine<br>Statistics Authority</div>
                </div>
                <div class="left-body">
                    <div class="left-headline">Procurement<br>Monitoring<br>System</div>
                    <p class="left-sub">Welcome back. Please login to your account to access the dashboard.</p>
                    <div class="illustration">
                        <div class="illus-wrap">
                            <div class="ic ic1"><i class="bi bi-bar-chart-fill"></i></div>
                            <div class="ic ic2"><i class="bi bi-file-earmark-text-fill"></i></div>
                            <div class="ic ic3"><i class="bi bi-clipboard2-check-fill"></i></div>
                        </div>
                    </div>
                </div>
                <div class="left-footer">Philippine Statistics Authority &nbsp;·&nbsp; <?= date('Y') ?></div>
            </div>

            <!-- RIGHT -->
            <div class="panel-right">
                <h1 class="form-heading">Welcome Back</h1>
                <p class="form-sub">Sign in to your account.</p>

                <?php if ($success !== ''): ?>
                    <div
                        style="display:flex;align-items:center;gap:0.6rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:0.65rem 1rem;font-size:0.82rem;font-weight:500;color:#166534;margin-bottom:1.2rem;">
                        <i class="bi bi-check-circle-fill"></i>
                        <span><?= htmlspecialchars($success) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert-error">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" autocomplete="off">
                    <input type="text" name="_trap_user" style="display:none;" tabindex="-1" aria-hidden="true"
                        autocomplete="username">
                    <input type="password" name="_trap_pass" style="display:none;" tabindex="-1" aria-hidden="true"
                        autocomplete="current-password">

                    <div class="field">
                        <label for="username">Username</label>
                        <div class="input-wrap">
                            <i class="bi bi-person icon"></i>
                            <input type="text" id="username" name="username" placeholder="Enter your username"
                                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <i class="bi bi-lock icon"></i>
                            <input type="password" id="password" name="password" placeholder="Enter your password"
                                autocomplete="new-password" required>
                            <button type="button" class="btn-toggle-pw" id="togglePw">
                                <i class="bi bi-eye" id="pwIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login" id="loginBtn">
                        <span id="btnText"><i class="bi bi-box-arrow-in-right"></i>&nbsp; Log in</span>
                        <div class="btn-spinner" id="btnSpinner"></div>
                    </button>
                </form>

                <a href="admin_login.php" class="admin-portal-link">
                    <i class="bi bi-shield-lock-fill"></i>
                    Super Admin? &nbsp;<strong>Access the Admin Portal →</strong>
                </a>

                <div class="status-row">
                    <span class="status-dot"></span>
                    System Online &nbsp;·&nbsp; Authorized Personnel Only
                </div>

                <div style="text-align:center;margin-top:1rem;font-size:0.82rem;color:var(--gray-400);">
                    Don't have an account?
                    <a href="signup.php"
                        style="color:var(--blue);font-weight:600;text-decoration:none;margin-left:0.2rem;">Create your
                        account</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('togglePw').addEventListener('click', function () {
            var pw = document.getElementById('password'), icon = document.getElementById('pwIcon');
            pw.type = pw.type === 'password' ? 'text' : 'password';
            icon.className = pw.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
        });
        document.getElementById('loginForm').addEventListener('submit', function () {
            document.getElementById('btnText').style.display = 'none';
            document.getElementById('btnSpinner').style.display = 'block';
            document.getElementById('loginBtn').disabled = true;
        });
    <?php if (empty($_POST['username'])): ?>document.getElementById('username').focus();
    <?php else: ?>document.getElementById('password').focus(); <?php endif; ?>
    </script>
</body>

</html>