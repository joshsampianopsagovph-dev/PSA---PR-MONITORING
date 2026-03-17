<?php
/**
 * admin_login.php
 * Super Admin exclusive login portal — PSA 2026 Procurement Monitoring System
 */

session_start();

if (isset($_SESSION['pms_user'])) {
    $role = $_SESSION['pms_role'] ?? '';
    header('Location: ' . ($role === 'super_admin' ? 'admin.php' : 'index.php'));
    exit;
}

// Show a notice if redirected from the regular login page
$redirected = isset($_GET['ref']) && $_GET['ref'] === 'redirect';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']     ?? '';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            status ENUM('success','failed') DEFAULT 'success',
            logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(username), INDEX(logged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    if ($username === '' || $password === '') {
        $error = 'All fields are required.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // ── Accept any account with role = 'super_admin' ─────────────────
            if ($user && password_verify($password, $user['password']) && ($user['role'] ?? '') === 'super_admin') {
                $pdo->prepare("INSERT INTO login_logs (user_id, username, ip_address, user_agent, status) VALUES (?,?,?,?,'success')")
                    ->execute([$user['id'], $username, $ip, $ua]);
                try { $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]); } catch(Exception $e){}

                session_regenerate_id(true);
                $_SESSION['pms_user']       = $user['username'];
                $_SESSION['pms_user_id']    = $user['id'];
                $_SESSION['pms_role']       = 'super_admin';
                $_SESSION['pms_department'] = $user['department'] ?? 'OTHER';
                header('Location: admin.php');
                exit;
            } else {
                $uid = ($user && ($user['role'] ?? '') === 'super_admin') ? $user['id'] : null;
                $pdo->prepare("INSERT INTO login_logs (user_id, username, ip_address, user_agent, status) VALUES (?,?,?,?,'failed')")
                    ->execute([$uid, $username, $ip, $ua]);
                $error = 'Access denied. Invalid credentials or insufficient privileges.';
            }
        } catch (Exception $e) {
            $error = 'System error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal — PSA Procurement Monitoring 2026</title>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Outfit:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --bg:#05080f; --surface:#0a0e1a; --surface2:#0f1424; --surface3:#161c2e;
            --border:rgba(255,255,255,0.07); --border2:rgba(255,255,255,0.13);
            --purple:#a855f7; --purple2:#7c3aed; --purple-dim:rgba(168,85,247,0.15);
            --red:#f43f5e; --text:#e2e8f0; --muted:#64748b; --muted2:#94a3b8;
        }
        html,body {
            min-height:100vh; font-family:'Outfit',sans-serif;
            background:var(--bg); color:var(--text);
            display:flex; align-items:center; justify-content:center;
            overflow:hidden;
        }
        body::before {
            content:''; position:fixed; inset:0; z-index:0;
            background-image:
                linear-gradient(rgba(168,85,247,0.04) 1px,transparent 1px),
                linear-gradient(90deg,rgba(168,85,247,0.04) 1px,transparent 1px);
            background-size:40px 40px;
        }
        .blob{position:fixed;border-radius:50%;pointer-events:none;z-index:0;filter:blur(80px);}
        .blob-1{width:500px;height:500px;background:rgba(124,58,237,0.12);top:-150px;right:-100px;}
        .blob-2{width:400px;height:400px;background:rgba(168,85,247,0.07);bottom:-100px;left:-80px;}
        .blob-3{width:200px;height:200px;background:rgba(244,63,94,0.06);bottom:30%;right:20%;}
        .scanline{position:fixed;inset:0;z-index:0;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(0,0,0,0.03) 0px,rgba(0,0,0,0.03) 1px,transparent 1px,transparent 4px);}
        .page{position:relative;z-index:1;width:100%;max-width:1000px;padding:1.5rem;animation:fadeUp 0.5s cubic-bezier(.22,1,.36,1) both;}
        @keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        .card{width:100%;display:flex;border-radius:16px;overflow:hidden;border:1px solid var(--border2);box-shadow:0 0 0 1px rgba(168,85,247,0.1),0 32px 80px rgba(0,0,0,0.7),inset 0 1px 0 rgba(255,255,255,0.05);}

        /* LEFT */
        .panel-left{flex:0 0 42%;background:var(--surface);padding:2.75rem 2.5rem;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;border-right:1px solid var(--border);}
        .panel-left::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--purple2),var(--purple),transparent);}
        .panel-left::after{content:'';position:absolute;inset:0;z-index:0;opacity:0.03;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='56' height='100'%3E%3Cpath d='M28 66L0 50V16L28 0l28 16v34z' fill='none' stroke='white' stroke-width='1'/%3E%3C/svg%3E");background-size:56px 100px;}
        .panel-left-inner{position:relative;z-index:1;height:100%;display:flex;flex-direction:column;justify-content:space-between;}
        .admin-brand{display:flex;align-items:center;gap:0.85rem;margin-bottom:2rem;}
        .brand-shield{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#4c1d95,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;flex-shrink:0;box-shadow:0 0 20px rgba(124,58,237,0.4);}
        .brand-text{line-height:1.3;}
        .brand-name{font-weight:800;font-size:0.9rem;color:var(--text);}
        .brand-sub{font-size:0.62rem;color:var(--muted);font-weight:500;letter-spacing:0.05em;text-transform:uppercase;}
        .access-badge{display:inline-flex;align-items:center;gap:0.4rem;background:rgba(168,85,247,0.1);border:1px solid rgba(168,85,247,0.25);color:var(--purple);border-radius:6px;padding:5px 11px;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:1.25rem;}
        .access-badge .dot{width:6px;height:6px;border-radius:50%;background:var(--purple);box-shadow:0 0 6px var(--purple);animation:blink 2s infinite;}
        @keyframes blink{0%,100%{opacity:1}50%{opacity:0.3}}
        .left-headline{font-family:'Outfit',sans-serif;font-weight:900;font-size:2rem;line-height:1.15;color:var(--text);margin-bottom:0.85rem;}
        .left-headline .hl{color:var(--purple);}
        .left-sub{font-size:0.82rem;color:var(--muted2);line-height:1.7;margin-bottom:1.75rem;}
        .info-rows{display:flex;flex-direction:column;gap:0.6rem;}
        .info-row{display:flex;align-items:center;gap:0.7rem;font-size:0.78rem;color:var(--muted2);}
        .info-row i{color:var(--purple);font-size:0.85rem;width:14px;text-align:center;}
        .warning-box{margin-top:1.5rem;padding:0.75rem 1rem;background:rgba(244,63,94,0.07);border:1px solid rgba(244,63,94,0.2);border-radius:8px;font-size:0.72rem;color:#fb7185;line-height:1.6;}
        .warning-box strong{color:var(--red);}
        .left-footer{margin-top:auto;padding-top:1.5rem;font-family:'JetBrains Mono',monospace;font-size:0.62rem;color:var(--muted);border-top:1px solid var(--border);}
        .left-footer .version{color:rgba(168,85,247,0.5);}

        /* RIGHT */
        .panel-right{flex:1;background:var(--surface2);padding:2.75rem 2.75rem 2.25rem;display:flex;flex-direction:column;justify-content:center;position:relative;}
        .panel-right::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--purple),var(--purple2));}
        .form-eyebrow{font-family:'JetBrains Mono',monospace;font-size:0.65rem;font-weight:600;color:var(--purple);letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.5rem;display:flex;align-items:center;gap:0.5rem;}
        .form-eyebrow::before{content:'';width:18px;height:1px;background:var(--purple);}
        .form-heading{font-family:'Outfit',sans-serif;font-weight:900;font-size:1.65rem;color:var(--text);margin-bottom:0.3rem;line-height:1.2;}
        .form-sub{font-size:0.82rem;color:var(--muted);margin-bottom:2rem;}

        /* ── Redirect notice banner ── */
        .notice-redirect {
            display:flex; align-items:center; gap:0.65rem;
            background:rgba(168,85,247,0.08);
            border:1px solid rgba(168,85,247,0.28);
            border-radius:8px; padding:0.7rem 1rem;
            font-size:0.8rem; color:#c4b5fd;
            margin-bottom:1.25rem; line-height:1.5;
        }
        .notice-redirect i { font-size:1rem; flex-shrink:0; color:var(--purple); }

        .alert-error{display:flex;align-items:flex-start;gap:0.6rem;background:rgba(244,63,94,0.08);border:1px solid rgba(244,63,94,0.25);border-radius:8px;padding:0.75rem 1rem;font-size:0.8rem;font-weight:500;color:#fb7185;margin-bottom:1.25rem;}
        .alert-error i{flex-shrink:0;margin-top:1px;}
        .field{margin-bottom:1.1rem;}
        .field label{display:flex;align-items:center;gap:0.4rem;font-family:'JetBrains Mono',monospace;font-size:0.65rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.4rem;}
        .field label i{font-size:0.75rem;color:var(--purple);}
        .input-wrap{position:relative;}
        .input-wrap input{width:100%;padding:0.72rem 2.8rem;background:var(--surface3);border:1.5px solid var(--border2);border-radius:9px;color:var(--text);font-family:'Outfit',sans-serif;font-size:0.88rem;outline:none;transition:border-color 0.2s,box-shadow 0.2s,background 0.2s;}
        .input-wrap input::placeholder{color:var(--muted);}
        .input-wrap input:focus{border-color:var(--purple);background:#111827;box-shadow:0 0 0 3px rgba(168,85,247,0.12);}
        .input-wrap .iicon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:0.88rem;pointer-events:none;transition:color 0.2s;}
        .input-wrap:focus-within .iicon{color:var(--purple);}
        .btn-pw{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:0.85rem;padding:4px;transition:color 0.2s;}
        .btn-pw:hover{color:var(--purple);}
        .btn-submit{width:100%;padding:0.85rem;border:none;border-radius:10px;background:linear-gradient(135deg,#4c1d95,#7c3aed,#a855f7);color:#fff;font-family:'Outfit',sans-serif;font-size:0.9rem;font-weight:800;letter-spacing:0.06em;cursor:pointer;transition:opacity 0.2s,transform 0.15s,box-shadow 0.2s;box-shadow:0 4px 20px rgba(124,58,237,0.4);display:flex;align-items:center;justify-content:center;gap:0.5rem;position:relative;overflow:hidden;margin-top:1.5rem;}
        .btn-submit::before{content:'';position:absolute;top:0;left:-100%;width:200%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.08),transparent);transition:left 0.6s;}
        .btn-submit:hover::before{left:100%;}
        .btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 28px rgba(124,58,237,0.5);}
        .btn-submit:active{transform:translateY(0);}
        .btn-submit:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
        .btn-spinner{display:none;width:15px;height:15px;border:2px solid rgba(255,255,255,0.35);border-top-color:#fff;border-radius:50%;animation:spin 0.7s linear infinite;}
        @keyframes spin{to{transform:rotate(360deg)}}
        .form-footer{margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:0.5rem;font-size:0.75rem;color:var(--muted);}
        .form-footer .status-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 6px #22c55e;animation:pulse 2s infinite;flex-shrink:0;}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:0.4}}
        .back-link{display:inline-flex;align-items:center;gap:0.4rem;font-size:0.78rem;color:var(--muted);text-decoration:none;margin-left:auto;transition:color 0.2s;}
        .back-link:hover{color:var(--muted2);}
        @media(max-width:680px){.panel-left{display:none;}.panel-right{padding:2.5rem 1.75rem;}}
    </style>
</head>
<body>
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>
<div class="scanline"></div>

<div class="page">
    <div class="card">

        <!-- LEFT INFO PANEL -->
        <div class="panel-left">
            <div class="panel-left-inner">
                <div>
                    <div class="admin-brand">
                        <div class="brand-shield"><i class="bi bi-shield-fill-check"></i></div>
                        <div class="brand-text">
                            <div class="brand-name">PSA Super Admin Portal</div>
                            <div class="brand-sub">Procurement Monitoring</div>
                        </div>
                    </div>
                    <div class="access-badge"><span class="dot"></span> Restricted Access</div>
                    <div class="left-headline">Super Admin<br><span class="hl">Command Center</span></div>
                    <p class="left-sub">This portal is exclusively for authorized Super Administrators. All access attempts are logged and monitored.</p>
                    <div class="info-rows">
                        <div class="info-row"><i class="bi bi-people-fill"></i> Full user account management</div>
                        <div class="info-row"><i class="bi bi-clock-history"></i> Real-time login activity logs</div>
                        <div class="info-row"><i class="bi bi-toggles"></i> Enable / disable accounts</div>
                        <div class="info-row"><i class="bi bi-shield-fill"></i> Role assignment controls</div>
                    </div>
                    <div class="warning-box">
                        <strong>⚠ Warning:</strong> Unauthorized access attempts are recorded including IP address and device information.
                    </div>
                </div>
                <div class="left-footer">
                    PSA &nbsp;·&nbsp; Procurement Monitoring
                    <br><span class="version">// SUPER_ADMIN_PORTAL v2.0</span>
                </div>
            </div>
        </div>

        <!-- RIGHT FORM PANEL -->
        <div class="panel-right">
            <div class="form-eyebrow">Secure Authentication</div>
            <h1 class="form-heading">Admin Sign In</h1>
            <p class="form-sub">Super Administrator credentials required.</p>

            <?php if ($redirected): ?>
            <div class="notice-redirect">
                <i class="bi bi-info-circle-fill"></i>
                <span>Your account has <strong>Super Admin</strong> privileges. Please sign in here to access the Admin Panel.</span>
            </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
            <div class="alert-error">
                <i class="bi bi-shield-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="adminLoginForm" autocomplete="off">
                <input type="text"     name="_trap_user" style="display:none;" tabindex="-1" aria-hidden="true" autocomplete="username">
                <input type="password" name="_trap_pass" style="display:none;" tabindex="-1" aria-hidden="true" autocomplete="current-password">

                <div class="field">
                    <label for="username"><i class="bi bi-person-badge-fill"></i> Admin Username</label>
                    <div class="input-wrap">
                        <i class="bi bi-person-fill iicon"></i>
                        <input type="text" id="username" name="username"
                            placeholder="Enter admin username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                            autocomplete="off" required>
                    </div>
                </div>
                <div class="field">
                    <label for="password"><i class="bi bi-key-fill"></i> Admin Password</label>
                    <div class="input-wrap">
                        <i class="bi bi-lock-fill iicon"></i>
                        <input type="password" id="password" name="password"
                            placeholder="Enter admin password"
                            autocomplete="new-password" required>
                        <button type="button" class="btn-pw" id="togglePw">
                            <i class="bi bi-eye" id="pwIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="loginBtn">
                    <span id="btnText"><i class="bi bi-shield-lock-fill"></i>&nbsp; Authenticate</span>
                    <div class="btn-spinner" id="btnSpinner"></div>
                </button>
            </form>

            <div class="form-footer">
                <span class="status-dot"></span>
                <span>Session encrypted &nbsp;·&nbsp; Activity logged</span>
                <a href="login.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to User Login</a>
            </div>
        </div>

    </div>
</div>

<script>
    document.getElementById('togglePw').addEventListener('click',function(){
        var pw=document.getElementById('password'),icon=document.getElementById('pwIcon');
        pw.type=pw.type==='password'?'text':'password';
        icon.className=pw.type==='password'?'bi bi-eye':'bi bi-eye-slash';
    });
    document.getElementById('adminLoginForm').addEventListener('submit',function(){
        document.getElementById('btnText').style.display='none';
        document.getElementById('btnSpinner').style.display='block';
        document.getElementById('loginBtn').disabled=true;
    });
    <?php if(empty($_POST['username'])): ?>document.getElementById('username').focus();
    <?php else: ?>document.getElementById('password').focus();<?php endif; ?>
</script>
</body>
</html>