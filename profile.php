<?php
/**
 * profile.php
 * User profile page for PSA Procurement Monitoring System
 */

session_start();
if (!isset($_SESSION['pms_user'])) {
    header('Location: login.php');
    exit;
}

// ── GSD Access Check ──────────────────────────────────────────────────────────
$is_gsd_user = (
    ($_SESSION['pms_department'] ?? '') === 'GSD' ||
    ($_SESSION['pms_role']       ?? '') === 'super_admin'
);

require_once 'config/database.php';

$current_user = $_SESSION['pms_user'];
$success_msg  = '';
$error_msg    = '';

// ── Ensure users table has all needed columns ────────────────────────────────
foreach ([
    "full_name  VARCHAR(150) DEFAULT NULL",
    "email      VARCHAR(200) DEFAULT NULL",
    "phone      VARCHAR(50)  DEFAULT NULL",
    "timezone   VARCHAR(100) DEFAULT 'Asia/Manila'",
    "google_id  VARCHAR(100) DEFAULT NULL",
    "created_at DATETIME     DEFAULT CURRENT_TIMESTAMP",
    "last_login DATETIME     DEFAULT NULL",
] as $col) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS $col"); } catch (Exception $e) {}
}

// ── Update last_login on profile visit ──────────────────────────────────────
try {
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE username = ?")->execute([$current_user]);
} catch (Exception $e) {}

// ── Fetch user ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$current_user]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Handle profile update ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_profile') {
        if (!$is_gsd_user) {
            $error_msg = 'Access denied. Only GSD Department staff may edit profile information.';
        } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $phone     = trim($_POST['phone']     ?? '');
        $timezone  = trim($_POST['timezone']  ?? 'Asia/Manila');

        try {
            $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=?, timezone=? WHERE username=?")
                ->execute([$full_name, $email, $phone, $timezone, $current_user]);
            $success_msg = 'Profile updated successfully.';
            // Refresh user data
            $stmt->execute([$current_user]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error_msg = 'Error updating profile: ' . $e->getMessage();
        }
        }
    }

    if ($_POST['action'] === 'change_password') {
        $current_pw = $_POST['current_password'] ?? '';
        $new_pw     = $_POST['new_password']     ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (!password_verify($current_pw, $user['password'])) {
            $error_msg = 'Current password is incorrect.';
        } elseif (strlen($new_pw) < 6) {
            $error_msg = 'New password must be at least 6 characters.';
        } elseif ($new_pw !== $confirm_pw) {
            $error_msg = 'New passwords do not match.';
        } else {
            $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE username=?")->execute([$hashed, $current_user]);
            $success_msg = 'Password changed successfully.';
        }
    }
}

// ── PR stats for this user ───────────────────────────────────────────────────
$pr_total     = 0;
$pr_paid      = 0;
$pr_pending   = 0;
$pr_cancelled = 0;
$first_access = $user['created_at'] ?? null;
$last_access  = $user['last_login']  ?? null;

try {
    $pr_total     = (int)$pdo->query("SELECT COUNT(*) FROM purchase_requests")->fetchColumn();
    $pr_paid      = (int)$pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE paid_date IS NOT NULL")->fetchColumn();
    $pr_pending   = (int)$pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE paid_date IS NULL AND status != 'CANCELLED'")->fetchColumn();
    $pr_cancelled = (int)$pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'CANCELLED'")->fetchColumn();
} catch (Exception $e) {}

$avatar_letter = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1));
$display_name  = $user['full_name'] ?: $user['username'];

$timezones = ['Asia/Manila','Asia/Singapore','Asia/Tokyo','Asia/Seoul','Asia/Shanghai',
              'America/New_York','America/Los_Angeles','America/Chicago',
              'Europe/London','Europe/Paris','Australia/Sydney','UTC'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — PSA Procurement Monitoring</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sora:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        :root {
            --bg:       #0f1117;
            --surface:  #1a1d27;
            --surface2: #22263a;
            --border:   rgba(255,255,255,0.08);
            --blue:     #3b82f6;
            --blue-dim: rgba(59,130,246,0.15);
            --teal:     #06b6d4;
            --text:     #e2e8f0;
            --muted:    #64748b;
            --green:    #22c55e;
            --red:      #ef4444;
        }

        html, body {
            min-height: 100%;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        /* ── TOP NAV ── */
        .topnav {
            position: sticky; top: 0; z-index: 100;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center;
            padding: 0 2rem;
            height: 56px;
            gap: 1rem;
        }
        .topnav-back {
            display: flex; align-items: center; gap: 0.5rem;
            color: var(--muted); text-decoration: none;
            font-size: 0.85rem; font-weight: 500;
            transition: color 0.2s;
        }
        .topnav-back:hover { color: var(--text); }
        .topnav-title {
            font-family: 'Sora', sans-serif;
            font-size: 1rem; font-weight: 700;
            color: var(--text);
            margin-left: 0.5rem;
        }
        .topnav-spacer { flex: 1; }
        .topnav-user {
            display: flex; align-items: center; gap: 0.6rem;
            font-size: 0.8rem; color: var(--muted);
        }
        .nav-avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: linear-gradient(135deg, #06b6d4, #3b82f6);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; color: #fff;
        }

        /* ── LAYOUT ── */
        .page-wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
        }

        /* ── PROFILE HEADER ── */
        .profile-header {
            display: flex; align-items: center; gap: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
        }
        .avatar-lg {
            width: 72px; height: 72px; border-radius: 50%; flex-shrink: 0;
            background: linear-gradient(135deg, #06b6d4, #3b82f6);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Sora', sans-serif;
            font-size: 1.8rem; font-weight: 800; color: #fff;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.3);
        }
        .profile-meta h2 {
            font-family: 'Sora', sans-serif;
            font-size: 1.3rem; font-weight: 800;
            color: var(--text); margin-bottom: 0.2rem;
        }
        .profile-meta .role-badge {
            display: inline-flex; align-items: center; gap: 0.3rem;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em;
            background: var(--blue-dim); color: var(--blue);
            padding: 3px 10px; border-radius: 20px;
            border: 1px solid rgba(59,130,246,0.3);
        }
        .profile-meta .dept-badge {
            display: inline-flex; align-items: center; gap: 0.35rem;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em;
            background: rgba(6,214,160,0.12); color: #06d6a0;
            padding: 3px 11px; border-radius: 20px;
            border: 1px solid rgba(6,214,160,0.28);
            margin-top: 0.3rem;
        }
        .profile-meta .username-line {
            font-size: 0.82rem; color: var(--muted); margin-top: 0.3rem;
        }
        .header-spacer { flex: 1; }
        .btn-msg {
            display: flex; align-items: center; gap: 0.45rem;
            background: var(--surface2); border: 1px solid var(--border);
            color: var(--text); border-radius: 8px;
            padding: 0.5rem 1rem; font-size: 0.83rem; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-msg:hover { background: #2d3348; border-color: rgba(255,255,255,0.15); }

        /* ── GRID ── */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 900px) { .profile-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 580px) { .profile-grid { grid-template-columns: 1fr; } }

        /* ── SECTION CARD ── */
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .section-title {
            font-size: 0.78rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.08em;
            color: var(--text);
            padding: 1rem 1.25rem 0.75rem;
            border-bottom: 1px solid var(--border);
        }
        .section-body { padding: 0; }

        /* ── DETAIL ROWS ── */
        .detail-row {
            display: flex; flex-direction: column;
            padding: 0.9rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label {
            font-size: 0.72rem; font-weight: 700;
            color: var(--muted); text-transform: uppercase;
            letter-spacing: 0.05em; margin-bottom: 0.25rem;
        }
        .detail-value {
            font-size: 0.88rem; color: var(--text); font-weight: 500;
        }
        .detail-value a { color: var(--blue); text-decoration: none; }
        .detail-value a:hover { text-decoration: underline; }
        .detail-value .sub-hint {
            font-size: 0.72rem; color: var(--muted);
            display: block; margin-top: 0.1rem;
        }
        .edit-link {
            font-size: 0.75rem; color: var(--blue);
            text-decoration: none; font-weight: 600;
            display: block; text-align: right;
            padding: 0.5rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .edit-link:hover { text-decoration: underline; }

        /* ── LIST LINKS ── */
        .list-link {
            display: block;
            padding: 0.75rem 1.25rem;
            font-size: 0.85rem; color: var(--blue);
            text-decoration: none; font-weight: 500;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
        }
        .list-link:last-child { border-bottom: none; }
        .list-link:hover { background: rgba(59,130,246,0.06); }

        /* ── STAT TILES ── */
        .stat-tiles {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .stat-tile {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
        }
        .stat-tile:nth-child(even) { border-right: none; }
        .stat-tile:nth-last-child(-n+2) { border-bottom: none; }
        .stat-num {
            font-family: 'Sora', sans-serif;
            font-size: 1.6rem; font-weight: 800;
            line-height: 1; margin-bottom: 0.25rem;
        }
        .stat-lbl { font-size: 0.72rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }

        /* ── ALERTS ── */
        .alert {
            display: flex; align-items: center; gap: 0.6rem;
            border-radius: 8px; padding: 0.65rem 1rem;
            font-size: 0.82rem; font-weight: 500;
            margin-bottom: 1.25rem;
        }
        .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
        .alert-error   { background: rgba(239,68,68,0.1);  border: 1px solid rgba(239,68,68,0.3);  color: #f87171; }

        /* ── EDIT MODAL ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0; z-index: 9999;
            background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.2s;
        }
        .modal-overlay.open { display: flex; }
        .modal-overlay.visible { opacity: 1; }
        .modal-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.75rem 2rem 1.5rem;
            width: 100%; max-width: 460px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.5);
            animation: popIn 0.2s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes popIn {
            from { transform: scale(0.94) translateY(8px); opacity: 0; }
            to   { transform: scale(1) translateY(0); opacity: 1; }
        }
        .modal-title {
            font-family: 'Sora', sans-serif;
            font-size: 1.05rem; font-weight: 800;
            color: var(--text); margin-bottom: 1.25rem;
        }
        .mfield { margin-bottom: 1rem; }
        .mfield label {
            display: block; font-size: 0.72rem; font-weight: 700;
            color: var(--muted); text-transform: uppercase;
            letter-spacing: 0.04em; margin-bottom: 0.35rem;
        }
        .mfield input, .mfield select {
            width: 100%; padding: 0.65rem 0.9rem;
            background: var(--surface2); border: 1.5px solid var(--border);
            border-radius: 8px; color: var(--text);
            font-family: 'Inter', sans-serif; font-size: 0.88rem;
            outline: none; transition: border-color 0.2s;
        }
        .mfield input:focus, .mfield select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .mfield input::placeholder { color: var(--muted); }
        .modal-actions { display: flex; gap: 0.75rem; margin-top: 1.25rem; }
        .btn-save {
            flex: 1; padding: 0.72rem;
            background: linear-gradient(135deg, #1155b8, #3b82f6);
            border: none; border-radius: 8px;
            color: #fff; font-weight: 700; font-size: 0.88rem;
            cursor: pointer; transition: opacity 0.15s;
        }
        .btn-save:hover { opacity: 0.9; }
        .btn-cancel {
            padding: 0.72rem 1.2rem;
            background: var(--surface2); border: 1px solid var(--border);
            border-radius: 8px; color: var(--muted);
            font-size: 0.88rem; font-weight: 600; cursor: pointer;
            transition: background 0.15s;
        }
        .btn-cancel:hover { background: #2d3348; }

        /* ── RESET BTN ── */
        .btn-reset {
            display: flex; align-items: center; gap: 0.5rem;
            background: var(--surface2); border: 1px solid var(--border);
            color: var(--muted); border-radius: 8px;
            padding: 0.5rem 1rem; font-size: 0.83rem; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: background 0.2s;
        }
        .btn-reset:hover { background: #2d3348; color: var(--text); }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
    <a href="index.php" class="topnav-back">
        <i class="bi bi-arrow-left"></i> Dashboard
    </a>
    <span class="topnav-title">Profile</span>
    <div class="topnav-spacer"></div>
    <div class="topnav-user">
        <div class="nav-avatar"><?= $avatar_letter ?></div>
        <span><?= htmlspecialchars($current_user) ?></span>
        <span style="background:rgba(6,214,160,0.12);color:#06d6a0;padding:2px 10px;border-radius:10px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;border:1px solid rgba(6,214,160,0.25);">
            <i class="bi bi-building-fill" style="font-size:0.65rem;"></i>
            <?= htmlspecialchars($user['department'] ?? ($_SESSION['pms_department'] ?? 'OTHER')) ?>
        </span>
    </div>
    <a href="logout.php" class="topnav-back" style="margin-left:1rem;color:#f87171;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#f87171'">
        <i class="bi bi-box-arrow-right"></i> Logout
    </a>
</nav>

<div class="page-wrap">

    <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-error"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- Profile header row -->
    <div class="profile-header">
        <div class="avatar-lg"><?= $avatar_letter ?></div>
        <div class="profile-meta">
            <h2><?= htmlspecialchars($display_name) ?></h2>
            <?php
            $dept_code = $user['department'] ?? ($_SESSION['pms_department'] ?? 'OTHER');
            $dept_names = [
                'GSD'    => 'General Services Division',
                'ADMIN'  => 'Administrative Division',
                'FIN'    => 'Finance Division',
                'HRMD'   => 'Human Resource Management Division',
                'RSSO'   => 'Regional Statistical Services Office',
                'ICT'    => 'Information and Communications Technology',
                'LEGAL'  => 'Legal Division',
                'SUPPLY' => 'Supply and Property Management',
                'BAC'    => 'Bids and Awards Committee',
                'PROC'   => 'Procurement Unit',
                'OTHER'  => 'Other / Unassigned',
            ];
            $dept_full = $dept_names[$dept_code] ?? $dept_code;
            ?>
            <span class="dept-badge">
                <i class="bi bi-building-fill"></i>
                <?= htmlspecialchars($dept_code) ?> — <?= htmlspecialchars($dept_full) ?>
            </span>
            <div class="username-line">@<?= htmlspecialchars($user['username']) ?></div>
        </div>
        <div class="header-spacer"></div>
        <a href="#" class="btn-msg" onclick="openEditModal(); return false;">
            <i class="bi bi-pencil-fill"></i> Edit profile
        </a>
        <a href="index.php" class="btn-reset">
            <i class="bi bi-house-fill"></i> Back to Dashboard
        </a>
    </div>

    <!-- 3-column grid -->
    <div class="profile-grid">

        <!-- COL 1: User Details -->
        <div>
            <div class="section-card">
                <div class="section-title">User Details</div>
                <?php if ($is_gsd_user): ?>
                <a href="#" class="edit-link" onclick="openEditModal(); return false;">Edit profile</a>
                <?php else: ?>
                <span class="edit-link" style="color:#f59e0b;cursor:default;" title="View-only: Only GSD staff may edit"><i class="bi bi-lock-fill" style="font-size:0.75rem;"></i> View Only</span>
                <?php endif; ?>
                <div class="section-body">
                    <div class="detail-row">
                        <div class="detail-label">Full Name</div>
                        <div class="detail-value"><?= htmlspecialchars($user['full_name'] ?: '—') ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email address</div>
                        <div class="detail-value">
                            <?php if ($user['email']): ?>
                                <a href="mailto:<?= htmlspecialchars($user['email']) ?>"><?= htmlspecialchars($user['email']) ?></a>
                            <?php else: ?>
                                <span style="color:var(--muted);">Not set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value"><?= htmlspecialchars($user['phone'] ?? '—') ?: '—' ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Timezone</div>
                        <div class="detail-value"><?= htmlspecialchars($user['timezone'] ?? 'Asia/Manila') ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Username</div>
                        <div class="detail-value">@<?= htmlspecialchars($user['username']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Privacy -->
            <div class="section-card" style="margin-top:1.5rem;">
                <div class="section-title">Privacy and policies</div>
                <div class="section-body">
                    <a href="#" class="list-link">Data retention summary</a>
                    <a href="#" class="list-link">Change password</a>
                </div>
            </div>
        </div>

        <!-- COL 2: PR Stats -->
        <div>
            <div class="section-card">
                <div class="section-title" style="display:flex;align-items:center;justify-content:space-between;">
                    PR Overview
                    <span id="prRefreshDot" style="width:7px;height:7px;border-radius:50%;background:var(--green);display:inline-block;" title="Live"></span>
                </div>
                <div class="stat-tiles">
                    <div class="stat-tile">
                        <div class="stat-num" style="color:var(--blue);" id="pr-total"><?= number_format($pr_total) ?></div>
                        <div class="stat-lbl">Total PRs</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-num" style="color:var(--green);" id="pr-paid"><?= number_format($pr_paid) ?></div>
                        <div class="stat-lbl">Paid</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-num" style="color:#f59e0b;" id="pr-pending"><?= number_format($pr_pending) ?></div>
                        <div class="stat-lbl">On-going</div>
                    </div>
                    <div class="stat-tile">
                        <div class="stat-num" style="color:var(--red);" id="pr-cancelled"><?= number_format($pr_cancelled) ?></div>
                        <div class="stat-lbl">Cancelled</div>
                    </div>
                </div>
            </div>

            <!-- Miscellaneous -->
            <div class="section-card" style="margin-top:1.5rem;">
                <div class="section-title">Miscellaneous</div>
                <div class="section-body">
                    <a href="index.php" class="list-link"><i class="bi bi-grid-fill" style="margin-right:0.4rem;"></i>Dashboard</a>
                    <a href="table.php" class="list-link"><i class="bi bi-table" style="margin-right:0.4rem;"></i>PR Table View</a>
                    <a href="logout.php" class="list-link" style="color:#f87171;"><i class="bi bi-box-arrow-right" style="margin-right:0.4rem;"></i>Log out</a>
                </div>
            </div>
        </div>

        <!-- COL 3: Reports / Login Activity -->
        <div>
            <div class="section-card">
                <div class="section-title">Reports</div>
                <div class="section-body">
                    <a href="index.php" class="list-link">Dashboard overview</a>
                    <a href="table.php" class="list-link">PR records</a>
                </div>
            </div>

            <div class="section-card" style="margin-top:1.5rem;">
                <div class="section-title">Login activity</div>
                <div class="section-body">
                    <div class="detail-row">
                        <div class="detail-label">Account created</div>
                        <div class="detail-value">
                            <?php if ($user['created_at']): ?>
                                <?= date('l, d F Y, g:i A', strtotime($user['created_at'])) ?>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Last access to site</div>
                        <div class="detail-value">
                            <?php if ($user['last_login']): ?>
                                <?= date('l, d F Y, g:i A', strtotime($user['last_login'])) ?>
                                <span class="sub-hint">(just now)</span>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>


        </div>

    </div><!-- /.profile-grid -->
</div><!-- /.page-wrap -->

<!-- ── EDIT PROFILE MODAL ── -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-person-fill" style="margin-right:0.5rem;color:var(--blue);"></i>Edit Profile</div>
        <?php if (!$is_gsd_user): ?>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:0.7rem 1rem;margin-bottom:1rem;font-size:0.83rem;color:#92400e;display:flex;align-items:center;gap:0.5rem;">
            <i class="bi bi-lock-fill" style="color:#d97706;"></i>
            <span><strong>View-Only:</strong> Your department (<?= htmlspecialchars($_SESSION['pms_department'] ?? 'OTHER') ?>) does not have permission to edit profile details. Only GSD Department staff may make changes.</span>
        </div>
        <?php endif; ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_profile">
            <div class="mfield">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" placeholder="Your full name" <?= !$is_gsd_user ? 'readonly disabled style="opacity:0.6;cursor:not-allowed;"' : '' ?>>
            </div>
            <div class="mfield">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your@email.com" <?= !$is_gsd_user ? 'readonly disabled style="opacity:0.6;cursor:not-allowed;"' : '' ?>>
            </div>
            <div class="mfield">
                <label>Phone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" <?= !$is_gsd_user ? 'readonly disabled style="opacity:0.6;cursor:not-allowed;"' : '' ?>>
            </div>
            <div class="mfield">
                <label>Timezone</label>
                <select name="timezone" <?= !$is_gsd_user ? 'disabled style="opacity:0.6;cursor:not-allowed;"' : '' ?>>
                    <?php foreach ($timezones as $tz): ?>
                    <option value="<?= $tz ?>" <?= ($user['timezone'] ?? 'Asia/Manila') === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <?php if ($is_gsd_user): ?>
                <button type="submit" class="btn-save"><i class="bi bi-check-lg"></i> Save changes</button>
                <?php else: ?>
                <button type="button" disabled class="btn-save" style="opacity:0.45;cursor:not-allowed;"><i class="bi bi-lock-fill"></i> GSD Only</button>
                <?php endif; ?>
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal() {
        var m = document.getElementById('editModal');
        m.classList.add('open');
        setTimeout(function() { m.classList.add('visible'); }, 10);
    }
    function closeEditModal() {
        var m = document.getElementById('editModal');
        m.classList.remove('visible');
        setTimeout(function() { m.classList.remove('open'); }, 200);
    }
    // ESC key closes modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEditModal();
    });
</script>
<script>
    // ── Live PR stats auto-refresh every 10 seconds ─────────────────────────
    function fetchPrStats() {
        fetch('profile_stats.php')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d) return;
                var pulse = document.getElementById('prRefreshDot');

                function animateVal(id, newVal) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    var current = parseInt(el.textContent.replace(/,/g,'')) || 0;
                    if (current === newVal) return;
                    el.style.transition = 'opacity 0.25s';
                    el.style.opacity = '0.3';
                    setTimeout(function() {
                        el.textContent = newVal.toLocaleString();
                        el.style.opacity = '1';
                    }, 250);
                }

                animateVal('pr-total',     d.total);
                animateVal('pr-paid',      d.paid);
                animateVal('pr-pending',   d.pending);
                animateVal('pr-cancelled', d.cancelled);

                // blink the green dot
                if (pulse) {
                    pulse.style.background = '#fff';
                    setTimeout(function() { pulse.style.background = 'var(--green)'; }, 300);
                }
            })
            .catch(function() {
                var pulse = document.getElementById('prRefreshDot');
                if (pulse) pulse.style.background = '#ef4444';
            });
    }

    // Initial fetch + repeat every 10s
    fetchPrStats();
    setInterval(fetchPrStats, 10000);
</script>
</body>
</html>