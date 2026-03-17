<?php
/**
 * signup.php
 * New account registration — PSA 2026 Procurement Monitoring System
 *
 * Super Admin flagging rule:
 *   If the username ENDS WITH the secret suffix (ADMIN_SUFFIX),
 *   the account is automatically assigned role = 'super_admin'
 *   and the user is redirected to admin_login.php.
 *
 *   Example: suffix = "_sa2026"
 *     "LaurenzGSD_sa2026"  → super_admin ✅
 *     "LaurenzGSD"         → regular user ✅
 *
 *   Change ADMIN_SUFFIX below to whatever you want.
 */

session_start();

if (isset($_SESSION['pms_user'])) {
    header('Location: index.php');
    exit;
}

// ── SECRET SUFFIX — change this to anything you want ────────────────────────
define('ADMIN_SUFFIX', '_GSD');
// ─────────────────────────────────────────────────────────────────────────────

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';

    $username   = trim($_POST['username']        ?? '');
    $full_name  = trim($_POST['full_name']        ?? '');
    $email      = trim($_POST['email']            ?? '');
    $password   = $_POST['password']              ?? '';
    $confirm_pw = $_POST['confirm_password']      ?? '';

    // ── Validation ────────────────────────────────────────────────────────────
    if ($username === '' || $password === '' || $confirm_pw === '') {
        $error = 'Username and password are required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{3,60}$/', $username)) {
        $error = 'Username must be 3–60 characters (letters, numbers, _ - . only).';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm_pw) {
        $error = 'Passwords do not match.';
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
                    department VARCHAR(100) DEFAULT NULL,
                    is_active  TINYINT(1)   DEFAULT 1,
                    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
                    last_login DATETIME     DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Check duplicate
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            if ($chk->fetch()) {
                $error = 'That username is already taken. Please choose another.';
            } else {
                // ── Detect super_admin by suffix ─────────────────────────────
                $role = 'user';
                $suffix_len = strlen(ADMIN_SUFFIX);
                if ($suffix_len > 0 && substr($username, -$suffix_len) === ADMIN_SUFFIX) {
                    $role = 'gsd_user';
                }

                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (username, full_name, email, password, role) VALUES (?,?,?,?,?)")
                    ->execute([$username, $full_name ?: null, $email ?: null, $hashed, $role]);

                header('Location: login.php?ref=signup_success');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Registration error: ' . $e->getMessage();
        }
    }
}

$suffix_display = ADMIN_SUFFIX; // used in the hint on the page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — PSA Procurement Monitoring 2026</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sora:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --navy:#0b1d4e; --blue:#1155b8; --blue-mid:#1580C4; --teal:#00a8c6;
            --gray-100:#f1f5f9; --gray-200:#e2e8f0; --gray-300:#cbd5e1;
            --gray-400:#94a3b8; --gray-600:#475569; --gray-800:#1e293b;
            --purple:#7c3aed; --purple-light:#ede9fe;
        }
        html,body {
            min-height:100vh; font-family:'Inter',sans-serif;
            background:#b8c8e0;
            display:flex; align-items:center; justify-content:center;
            padding:1.5rem; overflow-x:hidden;
        }
        body::before {
            content:''; position:fixed; inset:0;
            background-image:radial-gradient(rgba(17,85,184,0.17) 1.5px,transparent 1.5px);
            background-size:30px 30px; pointer-events:none; z-index:0;
        }
        body::after {
            content:''; position:fixed; width:600px; height:600px; border-radius:50%;
            background:radial-gradient(circle,rgba(17,85,184,0.13) 0%,transparent 70%);
            top:-180px; left:-180px; pointer-events:none; z-index:0;
        }
        .blob2 {
            position:fixed; width:500px; height:500px; border-radius:50%;
            background:radial-gradient(circle,rgba(0,168,198,0.1) 0%,transparent 70%);
            bottom:-120px; right:-100px; pointer-events:none; z-index:0;
        }
        .page {
            position:relative; z-index:1; width:100%; max-width:980px;
            animation:fadeUp 0.6s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(22px)} to{opacity:1;transform:translateY(0)} }
        .card {
            display:flex; border-radius:20px; overflow:hidden;
            box-shadow:0 24px 70px rgba(11,29,78,0.26), 0 2px 0 rgba(255,255,255,0.5) inset;
        }

        /* LEFT */
        .panel-left {
            flex:0 0 40%;
            background:linear-gradient(155deg,#0b1d4e 0%,#1155b8 50%,#00a8c6 100%);
            padding:3rem 2.25rem;
            display:flex; flex-direction:column; justify-content:space-between;
            position:relative; overflow:hidden;
        }
        .panel-left::before {
            content:''; position:absolute; width:280px; height:280px; border-radius:50%;
            background:rgba(255,255,255,0.05); bottom:-70px; right:-60px; pointer-events:none;
        }
        .panel-left::after {
            content:''; position:absolute; width:160px; height:160px; border-radius:50%;
            background:rgba(255,255,255,0.04); top:-40px; left:-30px; pointer-events:none;
        }
        .dc{position:absolute;border-radius:50%;background:rgba(255,255,255,0.06);pointer-events:none;}
        .dc1{width:70px;height:70px;top:34%;right:-14px;}
        .dc2{width:42px;height:42px;top:20%;left:10px;}
        .dc3{width:32px;height:32px;bottom:26%;left:24px;background:rgba(255,255,255,0.1);}
        .left-brand{position:relative;z-index:2;display:flex;align-items:center;gap:0.7rem;}
        .left-brand img{width:42px;height:42px;border-radius:50%;object-fit:contain;background:#fff;padding:4px;box-shadow:0 2px 10px rgba(0,0,0,0.3);}
        .left-brand-text{font-size:0.62rem;font-weight:800;letter-spacing:0.1em;color:rgba(255,255,255,0.68);text-transform:uppercase;line-height:1.4;}
        .left-body{position:relative;z-index:2;}
        .left-headline{font-family:'Sora',sans-serif;font-size:1.7rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:0.8rem;}
        .left-sub{font-size:0.82rem;color:rgba(255,255,255,0.68);line-height:1.65;margin-bottom:1.5rem;}
        .perks{display:flex;flex-direction:column;gap:0.55rem;}
        .perk{display:flex;align-items:center;gap:0.6rem;font-size:0.78rem;color:rgba(255,255,255,0.75);}
        .perk i{font-size:0.9rem;color:#7dd3fc;flex-shrink:0;}

        /* Suffix hint box on left panel */
        .hint-box {
            margin-top:1.4rem; padding:0.8rem 1rem;
            background:rgba(168,85,247,0.15);
            border:1px solid rgba(168,85,247,0.3);
            border-radius:10px;
        }
        .hint-box-title {
            font-size:0.7rem; font-weight:800; color:#c4b5fd;
            text-transform:uppercase; letter-spacing:0.07em;
            margin-bottom:0.35rem;
            display:flex; align-items:center; gap:0.35rem;
        }
        .hint-box p { font-size:0.75rem; color:rgba(196,181,253,0.85); line-height:1.6; }
        .hint-box .suffix-pill {
            display:inline-block;
            background:rgba(168,85,247,0.25); border:1px solid rgba(168,85,247,0.4);
            color:#e9d5ff; border-radius:5px;
            padding:1px 7px; font-size:0.72rem; font-weight:700;
            font-family:monospace; letter-spacing:0.03em; margin:0 2px;
        }

        .left-footer{position:relative;z-index:2;font-size:0.62rem;color:rgba(255,255,255,0.35);letter-spacing:0.03em;}

        /* RIGHT */
        .panel-right {
            flex:1; background:#fff;
            padding:2.75rem 2.75rem 2.25rem;
            display:flex; flex-direction:column; justify-content:center;
        }
        .form-heading{font-family:'Sora',sans-serif;font-size:1.45rem;font-weight:800;color:var(--gray-800);margin-bottom:0.25rem;}
        .form-sub{font-size:0.83rem;color:var(--gray-400);margin-bottom:1.5rem;}

        .alert-error{display:flex;align-items:center;gap:0.55rem;background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:0.65rem 1rem;font-size:0.8rem;font-weight:500;color:#b91c1c;margin-bottom:1.1rem;}

        .fields-row{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;}
        @media(max-width:540px){.fields-row{grid-template-columns:1fr;}}

        .field{margin-bottom:0.85rem;}
        .field label{display:block;font-size:0.72rem;font-weight:700;color:var(--gray-600);margin-bottom:0.32rem;letter-spacing:0.025em;text-transform:uppercase;}

        .input-wrap{position:relative;}
        .input-wrap .icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.85rem;pointer-events:none;transition:color 0.2s;}
        .input-wrap input{width:100%;padding:0.68rem 1rem 0.68rem 2.4rem;border:1.5px solid var(--gray-200);border-radius:9px;font-family:'Inter',sans-serif;font-size:0.85rem;color:var(--gray-800);background:var(--gray-100);outline:none;transition:border-color 0.2s,background 0.2s,box-shadow 0.2s;}
        .input-wrap input::placeholder{color:var(--gray-400);}
        .input-wrap input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(17,85,184,0.1);}
        .input-wrap:focus-within .icon{color:var(--blue);}

        /* username field — live suffix detection */
        .input-wrap input.is-admin{border-color:var(--purple)!important;background:#faf5ff!important;box-shadow:0 0 0 3px rgba(124,58,237,0.1)!important;}
        .input-wrap .icon.is-admin-icon{color:var(--purple)!important;}
        .admin-detected-badge{
            display:none; align-items:center; gap:0.35rem;
            font-size:0.7rem; font-weight:700; color:var(--purple);
            margin-top:4px;
        }
        .admin-detected-badge.show{display:flex;}
        .admin-detected-badge i{font-size:0.8rem;}

        .btn-toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:0.85rem;padding:3px;transition:color 0.2s;}
        .btn-toggle-pw:hover{color:var(--blue);}

        /* Password strength */
        .pw-strength{height:3px;border-radius:2px;margin-top:5px;background:var(--gray-200);overflow:hidden;}
        .pw-strength-bar{height:100%;width:0;border-radius:2px;transition:width 0.3s,background 0.3s;}
        .pw-hint{font-size:0.68rem;color:var(--gray-400);margin-top:3px;}

        /* Submit */
        .btn-signup{width:100%;padding:0.8rem;border:none;border-radius:10px;background:linear-gradient(135deg,#0b1d4e 0%,#1155b8 55%,#1580C4 100%);color:#fff;font-family:'Sora',sans-serif;font-size:0.88rem;font-weight:700;letter-spacing:0.05em;cursor:pointer;transition:opacity 0.2s,transform 0.15s,box-shadow 0.2s;box-shadow:0 4px 18px rgba(17,85,184,0.35);display:flex;align-items:center;justify-content:center;gap:0.5rem;position:relative;overflow:hidden;margin-top:1.1rem;}
        .btn-signup::before{content:'';position:absolute;top:0;left:-100%;width:200%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.1),transparent);transition:left 0.5s;}
        .btn-signup:hover::before{left:100%;}
        .btn-signup:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(17,85,184,0.44);}
        .btn-signup:active{transform:translateY(0);}
        .btn-signup:disabled{opacity:0.7;cursor:not-allowed;transform:none;}
        .btn-signup.admin-mode{background:linear-gradient(135deg,#4c1d95,#7c3aed,#a855f7)!important;box-shadow:0 4px 18px rgba(124,58,237,0.4)!important;}
        .btn-spinner{display:none;width:14px;height:14px;border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;border-radius:50%;animation:spin 0.7s linear infinite;}
        @keyframes spin{to{transform:rotate(360deg)}}

        .login-link{text-align:center;margin-top:0.85rem;font-size:0.8rem;color:var(--gray-400);}
        .login-link a{color:var(--blue);font-weight:600;text-decoration:none;margin-left:0.2rem;}
        .login-link a:hover{text-decoration:underline;}

        @media(max-width:700px){.panel-left{display:none;}.panel-right{padding:2.25rem 1.5rem;}}
    </style>
</head>
<body>
<div class="blob2"></div>

<div class="page">
    <div class="card">

        <!-- LEFT PANEL -->
        <div class="panel-left">
            <div class="dc dc1"></div><div class="dc dc2"></div><div class="dc dc3"></div>

            <div class="left-brand">
                <img src="psa.png" alt="PSA" onerror="this.style.display='none'">
                <div class="left-brand-text">Philippine<br>Statistics Authority</div>
            </div>

            <div class="left-body">
                <div class="left-headline">Create Your<br>Account</div>
                <p class="left-sub">Join the Procurement Monitoring System to track and manage purchase requests.</p>

                <div class="perks">
                    <div class="perk"><i class="bi bi-bar-chart-fill"></i> Real-time PR dashboard</div>
                    <div class="perk"><i class="bi bi-table"></i> Full table view &amp; filtering</div>
                    <div class="perk"><i class="bi bi-bell-fill"></i> Overdue &amp; status alerts</div>
                    <div class="perk"><i class="bi bi-person-circle"></i> Personal profile &amp; stats</div>
                </div>

            </div>

            <div class="left-footer">Philippine Statistics Authority &nbsp;·&nbsp; <?= date('Y') ?></div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="panel-right">
            <h1 class="form-heading">Create Account</h1>
            <p class="form-sub">Fill in your details to get started.</p>

            <?php if ($error !== ''): ?>
            <div class="alert-error">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="signupForm" autocomplete="off">
                <input type="text"     name="_trap_user" style="display:none;" tabindex="-1" aria-hidden="true" autocomplete="username">
                <input type="password" name="_trap_pass" style="display:none;" tabindex="-1" aria-hidden="true" autocomplete="current-password">

                <!-- Name + Email -->
                <div class="fields-row">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <div class="input-wrap">
                            <i class="bi bi-person icon"></i>
                            <input type="text" id="full_name" name="full_name"
                                placeholder="Your full name"
                                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                                autocomplete="off">
                        </div>
                    </div>
                    <div class="field">
                        <label for="email">Email <span style="color:var(--gray-300);font-weight:400;">(optional)</span></label>
                        <div class="input-wrap">
                            <i class="bi bi-envelope icon"></i>
                            <input type="email" id="email" name="email"
                                placeholder="your@email.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                autocomplete="off">
                        </div>
                    </div>
                </div>

                <!-- Username with live suffix detection -->
                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <i class="bi bi-at icon" id="usernameIcon"></i>
                        <input type="text" id="username" name="username"
                            placeholder="Choose a username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                            autocomplete="off" required
                            oninput="detectAdminSuffix(this.value)">
                    </div>
                    <div class="admin-detected-badge" id="adminBadge">
                        <i class="bi bi-shield-fill-check"></i>
                        GSD suffix detected — this account will have full edit access to the system.
                    </div>
                </div>

                <!-- Password -->
                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <i class="bi bi-lock icon"></i>
                        <input type="password" id="password" name="password"
                            placeholder="Min. 6 characters"
                            autocomplete="new-password" required
                            oninput="checkStrength(this.value)">
                        <button type="button" class="btn-toggle-pw" onclick="toggleVis('password','pwIcon1')">
                            <i class="bi bi-eye" id="pwIcon1"></i>
                        </button>
                    </div>
                    <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
                    <div class="pw-hint" id="pwHint">Enter a password</div>
                </div>

                <!-- Confirm Password -->
                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrap">
                        <i class="bi bi-lock-fill icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password"
                            placeholder="Repeat your password"
                            autocomplete="new-password" required>
                        <button type="button" class="btn-toggle-pw" onclick="toggleVis('confirm_password','pwIcon2')">
                            <i class="bi bi-eye" id="pwIcon2"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-signup" id="signupBtn">
                    <span id="btnText"><i class="bi bi-person-plus-fill"></i>&nbsp; Create Account</span>
                    <div class="btn-spinner" id="btnSpinner"></div>
                </button>
            </form>

            <div class="login-link">
                Already have an account?
                <a href="login.php">Sign in here</a>
            </div>
        </div>
    </div>
</div>

<script>
    // Secret suffix (echoed from PHP — only the suffix shape, not how to misuse it)
    var ADMIN_SUFFIX = <?= json_encode(ADMIN_SUFFIX) ?>;

    // Live detection: highlight username field & show badge when suffix matches
    function detectAdminSuffix(val) {
        var inp    = document.getElementById('username');
        var icon   = document.getElementById('usernameIcon');
        var badge  = document.getElementById('adminBadge');
        var btn    = document.getElementById('signupBtn');
        var btnTxt = document.getElementById('btnText');

        var isAdmin = val.length > 0 && val.slice(-ADMIN_SUFFIX.length) === ADMIN_SUFFIX;

        inp.classList.toggle('is-admin', isAdmin);
        icon.classList.toggle('is-admin-icon', isAdmin);
        badge.classList.toggle('show', isAdmin);
        btn.classList.toggle('admin-mode', isAdmin);

        if (isAdmin) {
            btnTxt.innerHTML = '<i class="bi bi-shield-check-fill"></i>&nbsp; Create GSD Account';
        } else {
            btnTxt.innerHTML = '<i class="bi bi-person-plus-fill"></i>&nbsp; Create Account';
        }
    }

    // Password visibility toggle
    function toggleVis(inputId, iconId) {
        var inp  = document.getElementById(inputId);
        var icon = document.getElementById(iconId);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
    }

    // Password strength meter
    function checkStrength(val) {
        var bar  = document.getElementById('pwBar');
        var hint = document.getElementById('pwHint');
        var score = 0;
        if (val.length >= 6)  score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        var colors = ['','#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
        var labels = ['','Very weak','Weak','Fair','Strong','Very strong'];
        var widths = ['0%','20%','40%','60%','80%','100%'];
        bar.style.width      = val.length === 0 ? '0%'   : widths[score];
        bar.style.background = val.length === 0 ? ''     : colors[score];
        hint.textContent     = val.length === 0 ? 'Enter a password' : labels[score];
        hint.style.color     = val.length === 0 ? '#94a3b8' : colors[score];
    }

    // Loading spinner on submit
    document.getElementById('signupForm').addEventListener('submit', function () {
        document.getElementById('btnText').style.display    = 'none';
        document.getElementById('btnSpinner').style.display = 'block';
        document.getElementById('signupBtn').disabled = true;
    });

    // Run detection on page load in case of POST-back
    detectAdminSuffix(document.getElementById('username').value);
    document.getElementById('full_name').focus();
</script>
</body>
</html>