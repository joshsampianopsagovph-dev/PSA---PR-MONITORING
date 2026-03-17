<?php
/**
 * admin.php — Super Admin Panel
 * PSA 2026 Procurement Monitoring System
 *
 * Access: role must be 'super_admin'
 * Shows: all registered accounts + all login activity logs
 */

session_start();
if (!isset($_SESSION['pms_user'])) {
    header('Location: login.php'); exit;
}
if (($_SESSION['pms_role'] ?? '') !== 'super_admin') {
    header('Location: index.php'); exit;
}

require_once 'config/database.php';

// ── Ensure all needed columns + login_logs table exist ───────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS login_logs (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT DEFAULT NULL,
        username   VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45)  DEFAULT NULL,
        user_agent TEXT         DEFAULT NULL,
        status     ENUM('success','failed') DEFAULT 'success',
        logged_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(username),
        INDEX(logged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
foreach ([
    "full_name  VARCHAR(150) DEFAULT NULL",
    "email      VARCHAR(200) DEFAULT NULL",
    "phone      VARCHAR(50)  DEFAULT NULL",
    "google_id  VARCHAR(100) DEFAULT NULL",
    "last_login DATETIME     DEFAULT NULL",
    "is_active  TINYINT(1)   DEFAULT 1",
    "department VARCHAR(50)  DEFAULT 'OTHER'",
] as $col) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS $col"); } catch (Exception $e) {}
}

// ── Handle actions ───────────────────────────────────────────────────────────
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act    = $_POST['act']     ?? '';
    $uid    = (int)($_POST['uid'] ?? 0);
    $target = $_POST['target_user'] ?? '';

    if ($act === 'toggle_active' && $uid) {
        $cur = (int)$pdo->query("SELECT is_active FROM users WHERE id=$uid LIMIT 1")->fetchColumn();
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([!$cur, $uid]);
        $action_msg = $cur ? "Account disabled." : "Account enabled.";
    }
    if ($act === 'change_role' && $uid) {
        $new_role = $_POST['new_role'] ?? 'user';
        if (in_array($new_role, ['user','admin','super_admin'])) {
            $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$new_role, $uid]);
            $action_msg = "Role updated to $new_role.";
        }
    }
    if ($act === 'delete_user' && $uid) {
        $pdo->prepare("DELETE FROM login_logs WHERE username=(SELECT username FROM users WHERE id=?)")->execute([$uid]);
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        $action_msg = "User deleted.";
    }
    if ($act === 'clear_logs') {
        $pdo->exec("TRUNCATE TABLE login_logs");
        $action_msg = "Login logs cleared.";
    }
    // Redirect to avoid re-POST
    $_SESSION['admin_msg'] = $action_msg;
    header('Location: admin.php'); exit;
}
if (!empty($_SESSION['admin_msg'])) {
    $action_msg = $_SESSION['admin_msg'];
    unset($_SESSION['admin_msg']);
}

// ── Search & filter ──────────────────────────────────────────────────────────
$search    = trim($_GET['q']      ?? '');
$role_f    = trim($_GET['role']   ?? '');
$status_f  = trim($_GET['status'] ?? '');
$log_user  = trim($_GET['log_user'] ?? '');
$log_page  = max(1, (int)($_GET['lp'] ?? 1));
$log_limit = 20;
$log_offset = ($log_page - 1) * $log_limit;

// ── Users ────────────────────────────────────────────────────────────────────
$where = []; $params = [];
if ($search) { $where[] = "(username LIKE ? OR full_name LIKE ? OR email LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($role_f)   { $where[] = "role = ?";      $params[] = $role_f; }
if ($status_f !== '') { $where[] = "is_active = ?"; $params[] = (int)$status_f; }
$wsql  = $where ? "WHERE " . implode(" AND ", $where) : "";
$users = $pdo->prepare("SELECT * FROM users $wsql ORDER BY created_at DESC");
$users->execute($params);
$users = $users->fetchAll(PDO::FETCH_ASSOC);

// ── Login logs ───────────────────────────────────────────────────────────────
$lwhere = []; $lparams = [];
if ($log_user) { $lwhere[] = "username LIKE ?"; $lparams[] = "%$log_user%"; }
$lwsql = $lwhere ? "WHERE " . implode(" AND ", $lwhere) : "";
$log_total = (int)$pdo->prepare("SELECT COUNT(*) FROM login_logs $lwsql")->execute($lparams) ? $pdo->prepare("SELECT COUNT(*) FROM login_logs $lwsql")->execute($lparams) : 0;
$lstmt = $pdo->prepare("SELECT COUNT(*) FROM login_logs $lwsql"); $lstmt->execute($lparams); $log_total = (int)$lstmt->fetchColumn();
$log_pages = max(1, ceil($log_total / $log_limit));
$lstmt2 = $pdo->prepare("SELECT * FROM login_logs $lwsql ORDER BY logged_at DESC LIMIT $log_limit OFFSET $log_offset");
$lstmt2->execute($lparams);
$logs = $lstmt2->fetchAll(PDO::FETCH_ASSOC);

// ── Summary stats ────────────────────────────────────────────────────────────
$total_users   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$active_users  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$today_signups = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$today_logins  = (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE() AND status='success'")->fetchColumn();
$failed_logins = (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE() AND status='failed'")->fetchColumn();
$total_logs    = (int)$pdo->query("SELECT COUNT(*) FROM login_logs")->fetchColumn();

$current_admin = $_SESSION['pms_user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin — PSA Procurement 2026</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
  --bg:#080b14;
  --surface:#0e1221;
  --surface2:#141828;
  --surface3:#1c2235;
  --border:rgba(255,255,255,0.07);
  --border2:rgba(255,255,255,0.12);
  --blue:#3b82f6;
  --blue2:#1d4ed8;
  --teal:#06d6a0;
  --yellow:#fbbf24;
  --red:#f43f5e;
  --purple:#a855f7;
  --text:#e2e8f0;
  --muted:#64748b;
  --muted2:#94a3b8;
}
html,body{min-height:100%;font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);}

/* ── SCANLINE TEXTURE ── */
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,rgba(0,0,0,0.03) 0px,rgba(0,0,0,0.03) 1px,transparent 1px,transparent 3px);pointer-events:none;z-index:0;}

/* ── SIDEBAR ── */
.sidebar{
  position:fixed;left:0;top:0;bottom:0;width:220px;
  background:var(--surface);border-right:1px solid var(--border);
  display:flex;flex-direction:column;z-index:100;
  padding:0 0 1rem;
}
.sidebar-logo{
  padding:1.4rem 1.25rem 1rem;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:0.7rem;
}
.logo-icon{
  width:34px;height:34px;border-radius:8px;
  background:linear-gradient(135deg,#1d4ed8,#06d6a0);
  display:flex;align-items:center;justify-content:center;
  font-size:14px;font-weight:900;color:#fff;
  font-family:'JetBrains Mono',monospace;
}
.logo-text{font-family:'Outfit',sans-serif;font-weight:800;font-size:0.85rem;line-height:1.2;color:var(--text);}
.logo-text span{display:block;font-size:0.65rem;color:var(--muted);font-weight:500;letter-spacing:0.05em;}

.nav-section{padding:1rem 0.75rem 0.25rem;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);}
.nav-link{
  display:flex;align-items:center;gap:0.65rem;
  padding:0.6rem 1.25rem;margin:1px 0.5rem;
  border-radius:8px;color:var(--muted2);
  font-size:0.83rem;font-weight:500;text-decoration:none;
  transition:all 0.15s;cursor:pointer;background:none;border:none;width:calc(100% - 1rem);text-align:left;
}
.nav-link:hover{background:var(--surface3);color:var(--text);}
.nav-link.active{background:rgba(59,130,246,0.15);color:var(--blue);border:1px solid rgba(59,130,246,0.2);}
.nav-link i{font-size:1rem;width:18px;text-align:center;}

.sidebar-footer{margin-top:auto;padding:0.75rem 1.25rem;border-top:1px solid var(--border);}
.admin-chip{display:flex;align-items:center;gap:0.6rem;}
.admin-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#a855f7);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0;}
.admin-info{font-size:0.75rem;line-height:1.3;}
.admin-name{font-weight:700;color:var(--text);}
.admin-role{color:var(--purple);font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;}

/* ── MAIN ── */
.main{margin-left:220px;min-height:100vh;position:relative;z-index:1;}

/* ── TOPBAR ── */
.topbar{
  height:56px;background:var(--surface);border-bottom:1px solid var(--border);
  display:flex;align-items:center;padding:0 1.75rem;gap:1rem;
  position:sticky;top:0;z-index:50;
}
.topbar-title{font-family:'Outfit',sans-serif;font-weight:800;font-size:1rem;color:var(--text);}
.topbar-badge{font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;background:rgba(168,85,247,0.15);color:var(--purple);padding:3px 9px;border-radius:20px;border:1px solid rgba(168,85,247,0.25);}
.topbar-spacer{flex:1;}
.topbar-time{font-family:'JetBrains Mono',monospace;font-size:0.78rem;color:var(--muted);background:var(--surface2);padding:4px 10px;border-radius:6px;border:1px solid var(--border);}
.topbar-back{display:flex;align-items:center;gap:0.4rem;font-size:0.8rem;color:var(--muted2);text-decoration:none;padding:5px 12px;border-radius:7px;border:1px solid var(--border);transition:all 0.15s;}
.topbar-back:hover{background:var(--surface3);color:var(--text);}

/* ── CONTENT ── */
.content{padding:1.75rem;}

/* ── ALERT ── */
.alert-bar{display:flex;align-items:center;gap:0.6rem;background:rgba(6,214,160,0.1);border:1px solid rgba(6,214,160,0.25);color:var(--teal);border-radius:8px;padding:0.65rem 1rem;font-size:0.83rem;font-weight:500;margin-bottom:1.25rem;}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.75rem;}
.stat-card{
  background:var(--surface);border:1px solid var(--border);border-radius:12px;
  padding:1.1rem 1.25rem;position:relative;overflow:hidden;
  transition:border-color 0.2s,transform 0.15s;
}
.stat-card:hover{border-color:var(--border2);transform:translateY(-2px);}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--accent-color);}
.stat-card-icon{font-size:1.3rem;margin-bottom:0.5rem;display:block;}
.stat-num{font-family:'JetBrains Mono',monospace;font-size:1.8rem;font-weight:700;line-height:1;color:var(--text);margin-bottom:0.2rem;}
.stat-label{font-size:0.72rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.05em;}

/* ── SECTION HEADER ── */
.section-hdr{display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;}
.section-hdr h2{font-family:'Outfit',sans-serif;font-weight:800;font-size:1rem;color:var(--text);}
.section-hdr .count-pill{font-size:0.72rem;font-weight:700;background:var(--surface3);color:var(--muted2);padding:2px 9px;border-radius:10px;}
.section-hdr-spacer{flex:1;}

/* ── SEARCH BAR ── */
.search-bar{display:flex;align-items:center;gap:0.6rem;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:0 0.85rem;height:36px;min-width:220px;}
.search-bar i{color:var(--muted);font-size:0.9rem;}
.search-bar input{background:none;border:none;outline:none;color:var(--text);font-size:0.82rem;font-family:'Outfit',sans-serif;width:160px;}
.search-bar input::placeholder{color:var(--muted);}
.filter-select{background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted2);font-size:0.8rem;padding:6px 10px;font-family:'Outfit',sans-serif;outline:none;cursor:pointer;}
.filter-select:focus{border-color:var(--blue);}
.btn-sm{display:inline-flex;align-items:center;gap:0.35rem;padding:6px 12px;border-radius:7px;font-size:0.78rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--surface3);color:var(--muted2);font-family:'Outfit',sans-serif;text-decoration:none;transition:all 0.15s;}
.btn-sm:hover{background:var(--surface2);color:var(--text);}
.btn-danger{border-color:rgba(244,63,94,0.3);color:var(--red);background:rgba(244,63,94,0.08);}
.btn-danger:hover{background:rgba(244,63,94,0.18);color:#fff;}

/* ── TABLE ── */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.75rem;}
table{width:100%;border-collapse:collapse;}
thead tr{background:var(--surface2);border-bottom:1px solid var(--border);}
thead th{padding:0.7rem 1rem;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);text-align:left;white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--border);transition:background 0.12s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:rgba(255,255,255,0.025);}
td{padding:0.75rem 1rem;font-size:0.83rem;vertical-align:middle;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:0.25rem;padding:3px 9px;border-radius:20px;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;white-space:nowrap;}
.badge-sa{background:rgba(168,85,247,0.15);color:var(--purple);border:1px solid rgba(168,85,247,0.25);}
.badge-admin{background:rgba(59,130,246,0.15);color:var(--blue);border:1px solid rgba(59,130,246,0.2);}
.badge-user{background:rgba(100,116,139,0.15);color:var(--muted2);border:1px solid rgba(100,116,139,0.2);}
.badge-on{background:rgba(6,214,160,0.12);color:var(--teal);border:1px solid rgba(6,214,160,0.2);}
.badge-off{background:rgba(244,63,94,0.1);color:var(--red);border:1px solid rgba(244,63,94,0.15);}
.badge-success{background:rgba(6,214,160,0.12);color:var(--teal);border:1px solid rgba(6,214,160,0.2);}
.badge-failed{background:rgba(244,63,94,0.1);color:var(--red);border:1px solid rgba(244,63,94,0.15);}
.badge-new{background:rgba(251,191,36,0.12);color:var(--yellow);border:1px solid rgba(251,191,36,0.2);animation:pulse-new 2s infinite;}
@keyframes pulse-new{0%,100%{opacity:1;}50%{opacity:0.6;}}

/* ── AVATAR ── */
.av{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0;}

/* ── USER ROW INFO ── */
.user-cell{display:flex;align-items:center;gap:0.6rem;}
.user-detail .uname{font-weight:700;font-size:0.85rem;color:var(--text);}
.user-detail .uemail{font-size:0.72rem;color:var(--muted);}

/* ── ACTION BUTTONS ── */
.action-row{display:flex;align-items:center;gap:0.4rem;}
.btn-icon{width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--surface3);color:var(--muted2);display:flex;align-items:center;justify-content:center;font-size:0.75rem;cursor:pointer;transition:all 0.15s;}
.btn-icon:hover{background:var(--surface2);color:var(--text);}
.btn-icon.del:hover{background:rgba(244,63,94,0.15);border-color:rgba(244,63,94,0.3);color:var(--red);}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;gap:0.4rem;padding:0.75rem 1rem;border-top:1px solid var(--border);}
.page-btn{min-width:30px;height:30px;border-radius:6px;border:1px solid var(--border);background:var(--surface3);color:var(--muted2);display:inline-flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.15s;font-family:'JetBrains Mono',monospace;}
.page-btn:hover{background:var(--surface2);color:var(--text);}
.page-btn.active{background:rgba(59,130,246,0.2);border-color:rgba(59,130,246,0.35);color:var(--blue);}
.page-info{font-size:0.75rem;color:var(--muted);margin-left:auto;font-family:'JetBrains Mono',monospace;}

/* ── TAB SWITCHER ── */
.tab-row{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:1.5rem;}
.tab{padding:0.65rem 1.25rem;font-size:0.83rem;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all 0.15s;}
.tab.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab:hover:not(.active){color:var(--muted2);}

/* ── MODAL ── */
.modal-ov{display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.7);backdrop-filter:blur(6px);align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s;}
.modal-ov.open{display:flex;}
.modal-ov.vis{opacity:1;}
.modal-box{background:var(--surface);border:1px solid var(--border2);border-radius:14px;padding:1.75rem;width:100%;max-width:400px;box-shadow:0 32px 80px rgba(0,0,0,0.6);animation:popIn 0.2s cubic-bezier(.22,1,.36,1) both;}
@keyframes popIn{from{transform:scale(0.93) translateY(10px);opacity:0;}to{transform:scale(1) translateY(0);opacity:1;}}
.modal-title{font-weight:800;font-size:1rem;color:var(--text);margin-bottom:1.25rem;display:flex;align-items:center;gap:0.5rem;}
.mfield{margin-bottom:0.9rem;}
.mfield label{display:block;font-size:0.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.3rem;}
.mfield select{width:100%;padding:0.6rem 0.8rem;background:var(--surface2);border:1.5px solid var(--border);border-radius:8px;color:var(--text);font-family:'Outfit',sans-serif;font-size:0.88rem;outline:none;cursor:pointer;}
.mfield select:focus{border-color:var(--blue);}
.modal-actions{display:flex;gap:0.6rem;margin-top:1.25rem;}
.btn-primary{flex:1;padding:0.65rem;background:linear-gradient(135deg,#1d4ed8,#3b82f6);border:none;border-radius:8px;color:#fff;font-weight:700;font-size:0.85rem;cursor:pointer;font-family:'Outfit',sans-serif;transition:opacity 0.15s;}
.btn-primary:hover{opacity:0.9;}
.btn-cancel-m{padding:0.65rem 1.1rem;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted2);font-size:0.85rem;font-weight:600;cursor:pointer;font-family:'Outfit',sans-serif;}

/* ── IP / AGENT truncate ── */
.mono{font-family:'JetBrains Mono',monospace;font-size:0.75rem;}
.truncate{max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon">SA</div>
        <div class="logo-text">Admin Panel<span>PSA Procurement 2026</span></div>
    </div>
    <div class="nav-section">Navigation</div>
    <a href="#section-users" class="nav-link active" onclick="showTab('users')"><i class="bi bi-people-fill"></i> Users</a>
    <a href="#section-logs"  class="nav-link"        onclick="showTab('logs')"><i class="bi bi-clock-history"></i> Login Logs</a>
    <div class="nav-section">System</div>
    <a href="index.php" class="nav-link"><i class="bi bi-grid-fill"></i> Dashboard</a>
    <a href="profile.php" class="nav-link"><i class="bi bi-person-circle"></i> My Profile</a>
    <a href="logout.php"  class="nav-link" style="color:#f43f5e;"><i class="bi bi-box-arrow-right"></i> Logout</a>
    <div class="sidebar-footer">
        <div class="admin-chip">
            <div class="admin-av"><?= strtoupper(substr($current_admin,0,1)) ?></div>
            <div class="admin-info">
                <div class="admin-name"><?= htmlspecialchars($current_admin) ?></div>
                <div class="admin-role">Super Admin</div>
            </div>
        </div>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <!-- TOPBAR -->
    <div class="topbar">
        <span class="topbar-title">User Management</span>
        <span class="topbar-badge">Super Admin</span>
        <div class="topbar-spacer"></div>
        <span class="topbar-time" id="adminClock">--:--:--</span>
        <a href="index.php" class="topbar-back"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>

    <div class="content">

        <?php if ($action_msg): ?>
        <div class="alert-bar"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($action_msg) ?></div>
        <?php endif; ?>

        <!-- STAT CARDS -->
        <div class="stats-row">
            <div class="stat-card" style="--accent-color:#3b82f6;">
                <span class="stat-card-icon" style="color:#3b82f6;"><i class="bi bi-people-fill"></i></span>
                <div class="stat-num"><?= $total_users ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card" style="--accent-color:#06d6a0;">
                <span class="stat-card-icon" style="color:#06d6a0;"><i class="bi bi-person-check-fill"></i></span>
                <div class="stat-num"><?= $active_users ?></div>
                <div class="stat-label">Active Accounts</div>
            </div>
            <div class="stat-card" style="--accent-color:#fbbf24;">
                <span class="stat-card-icon" style="color:#fbbf24;"><i class="bi bi-person-plus-fill"></i></span>
                <div class="stat-num"><?= $today_signups ?></div>
                <div class="stat-label">New Today</div>
            </div>
            <div class="stat-card" style="--accent-color:#a855f7;">
                <span class="stat-card-icon" style="color:#a855f7;"><i class="bi bi-box-arrow-in-right"></i></span>
                <div class="stat-num"><?= $today_logins ?></div>
                <div class="stat-label">Logins Today</div>
            </div>
            <div class="stat-card" style="--accent-color:#f43f5e;">
                <span class="stat-card-icon" style="color:#f43f5e;"><i class="bi bi-shield-exclamation"></i></span>
                <div class="stat-num"><?= $failed_logins ?></div>
                <div class="stat-label">Failed Today</div>
            </div>
        </div>

        <!-- TABS -->
        <div class="tab-row">
            <div class="tab active" id="tab-users" onclick="showTab('users')"><i class="bi bi-people-fill"></i>&nbsp; All Accounts <span style="background:rgba(59,130,246,0.2);color:var(--blue);padding:1px 7px;border-radius:8px;font-size:0.68rem;margin-left:4px;"><?= count($users) ?></span></div>
            <div class="tab" id="tab-logs"  onclick="showTab('logs')"><i class="bi bi-clock-history"></i>&nbsp; Login Activity <span style="background:rgba(168,85,247,0.2);color:var(--purple);padding:1px 7px;border-radius:8px;font-size:0.68rem;margin-left:4px;"><?= $total_logs ?></span></div>
        </div>

        <!-- ═══════════════ USERS TAB ═══════════════ -->
        <div id="section-users">
            <form method="GET" action="">
                <input type="hidden" name="tab" value="users">
                <div class="section-hdr">
                    <h2><i class="bi bi-people-fill" style="color:var(--blue);margin-right:0.3rem;"></i> Registered Accounts</h2>
                    <div class="search-bar">
                        <i class="bi bi-search"></i>
                        <input type="text" name="q" placeholder="Search name / email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <select name="role" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <option value="user"        <?= $role_f==='user'        ?'selected':'' ?>>User</option>
                        <option value="admin"       <?= $role_f==='admin'       ?'selected':'' ?>>Admin</option>
                        <option value="super_admin" <?= $role_f==='super_admin' ?'selected':'' ?>>Super Admin</option>
                    </select>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="1" <?= $status_f==='1'?'selected':'' ?>>Active</option>
                        <option value="0" <?= $status_f==='0'?'selected':'' ?>>Disabled</option>
                    </select>
                    <button type="submit" class="btn-sm"><i class="bi bi-funnel-fill"></i> Filter</button>
                    <a href="admin.php" class="btn-sm"><i class="bi bi-x-circle"></i> Clear</a>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted);">No accounts found.</td></tr>
                    <?php else: ?>
                    <?php
                    $colors = ['#3b82f6','#06d6a0','#a855f7','#f59e0b','#f43f5e','#06b6d4'];
                    foreach ($users as $i => $u):
                        $col = $colors[$i % count($colors)];
                        $letter = strtoupper(substr($u['full_name'] ?: $u['username'], 0, 1));
                        // Mark new if signed up today
                        $is_new = isset($u['created_at']) && date('Y-m-d', strtotime($u['created_at'])) === date('Y-m-d');
                        $is_active = (int)($u['is_active'] ?? 1);
                    ?>
                        <tr>
                            <td class="mono" style="color:var(--muted);"><?= str_pad($i+1, 2, '0', STR_PAD_LEFT) ?></td>
                            <td>
                                <div class="user-cell">
                                    <div class="av" style="background:<?= $col ?>;"><?= $letter ?></div>
                                    <div class="user-detail">
                                        <div class="uname">
                                            <?= htmlspecialchars($u['username']) ?>
                                            <?php if ($is_new): ?><span class="badge badge-new" style="margin-left:4px;">NEW</span><?php endif; ?>
                                        </div>
                                        <div class="uemail"><?= htmlspecialchars($u['full_name'] ?? '') ?><?= ($u['email'] ?? '') ? ' · '.$u['email'] : '' ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php
                                $rbadge = ['super_admin'=>'badge-sa','admin'=>'badge-admin','user'=>'badge-user'];
                                $rclass = $rbadge[$u['role'] ?? 'user'] ?? 'badge-user';
                                $rlabel = ['super_admin'=>'Super Admin','admin'=>'Admin','user'=>'User'];
                                ?>
                                <span class="badge <?= $rclass ?>"><?= $rlabel[$u['role'] ?? 'user'] ?? 'User' ?></span>
                            </td>
                            <td>
                                <?php
                                $dept = $u['department'] ?? 'OTHER';
                                $is_viewer = ($dept !== 'GSD' && ($u['role'] ?? 'user') !== 'super_admin');
                                $dept_colors = [
                                    'GSD'=>'#22c55e','ADMIN'=>'#3b82f6','FIN'=>'#f59e0b','HRMD'=>'#8b5cf6',
                                    'RSSO'=>'#06b6d4','ICT'=>'#10b981','LEGAL'=>'#ec4899','SUPPLY'=>'#f97316',
                                    'BAC'=>'#a78bfa','PROC'=>'#e11d48','OTHER'=>'#94a3b8',
                                ];
                                $dc = $dept_colors[$dept] ?? '#94a3b8';
                                ?>
                                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                                    <span style="font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:10px;background:<?= $dc ?>22;color:<?= $dc ?>;border:1px solid <?= $dc ?>44;">
                                        <?= htmlspecialchars($dept) ?>
                                    </span>
                                    <?php if ($is_viewer): ?>
                                    <span style="font-size:0.68rem;font-weight:700;padding:2px 7px;border-radius:10px;background:rgba(251,191,36,0.15);color:#f59e0b;border:1px solid rgba(251,191,36,0.35);" title="This user can only view data — cannot edit">
                                        <i class="bi bi-eye-fill"></i> Viewer
                                    </span>
                                    <?php else: ?>
                                    <span style="font-size:0.68rem;font-weight:700;padding:2px 7px;border-radius:10px;background:rgba(34,197,94,0.12);color:#22c55e;border:1px solid rgba(34,197,94,0.3);" title="This user can edit and manage data">
                                        <i class="bi bi-pencil-fill"></i> Editor
                                    </span>
                                    <?php endif; ?>
                                </div>
                            <td>
                                <span class="badge <?= $is_active ? 'badge-on' : 'badge-off' ?>">
                                    <i class="bi bi-<?= $is_active ? 'circle-fill' : 'circle' ?>"></i>
                                    <?= $is_active ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td class="mono" style="font-size:0.75rem;color:var(--muted2);">
                                <?= $u['created_at'] ? date('M d, Y', strtotime($u['created_at'])) : '—' ?>
                            </td>
                            <td class="mono" style="font-size:0.75rem;color:var(--muted2);">
                                <?= !empty($u['last_login']) ? date('M d, Y H:i', strtotime($u['last_login'])) : '<span style="color:var(--muted);">Never</span>' ?>
                            </td>
                            <td>
                                <div class="action-row">
                                    <!-- Change role -->
                                    <button class="btn-icon" title="Change Role" onclick="openRoleModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>', '<?= $u['role'] ?>')">
                                        <i class="bi bi-shield-fill"></i>
                                    </button>
                                    <!-- Toggle active -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $is_active ? 'Disable' : 'Enable' ?> this account?');">
                                        <input type="hidden" name="act" value="toggle_active">
                                        <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn-icon" title="<?= $is_active ? 'Disable' : 'Enable' ?> Account">
                                            <i class="bi bi-<?= $is_active ? 'person-dash-fill' : 'person-check-fill' ?>"></i>
                                        </button>
                                    </form>
                                    <!-- Delete -->
                                    <?php if ($u['username'] !== $current_admin): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete @<?= $u['username'] ?>? This cannot be undone.');">
                                        <input type="hidden" name="act" value="delete_user">
                                        <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn-icon del" title="Delete User">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div><!-- /section-users -->

        <!-- ═══════════════ LOGS TAB ═══════════════ -->
        <div id="section-logs" style="display:none;">
            <div class="section-hdr">
                <h2><i class="bi bi-clock-history" style="color:var(--purple);margin-right:0.3rem;"></i> Login Activity</h2>
                <form method="GET" style="display:flex;gap:0.5rem;align-items:center;">
                    <input type="hidden" name="tab" value="logs">
                    <div class="search-bar">
                        <i class="bi bi-search"></i>
                        <input type="text" name="log_user" placeholder="Filter by username..." value="<?= htmlspecialchars($log_user) ?>">
                    </div>
                    <button type="submit" class="btn-sm"><i class="bi bi-funnel-fill"></i> Filter</button>
                    <a href="admin.php?tab=logs" class="btn-sm"><i class="bi bi-x-circle"></i> Clear</a>
                </form>
                <div class="section-hdr-spacer"></div>
                <form method="POST" onsubmit="return confirm('Clear ALL login logs?');">
                    <input type="hidden" name="act" value="clear_logs">
                    <button type="submit" class="btn-sm btn-danger"><i class="bi bi-trash3-fill"></i> Clear All Logs</button>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Status</th>
                            <th>IP Address</th>
                            <th>Browser / Device</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted);">
                            No login logs yet.<br>
                            <span style="font-size:0.75rem;">Logs are recorded automatically on every login attempt.</span>
                        </td></tr>
                    <?php else: ?>
                    <?php foreach ($logs as $i => $log): ?>
                        <tr>
                            <td class="mono" style="color:var(--muted);"><?= $log_offset + $i + 1 ?></td>
                            <td>
                                <div class="user-cell">
                                    <div class="av" style="background:<?= $log['status']==='success'?'#06d6a0':'#f43f5e' ?>;width:24px;height:24px;font-size:9px;">
                                        <?= strtoupper(substr($log['username'],0,1)) ?>
                                    </div>
                                    <span style="font-weight:600;font-size:0.83rem;"><?= htmlspecialchars($log['username']) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $log['status']==='success'?'badge-success':'badge-failed' ?>">
                                    <i class="bi bi-<?= $log['status']==='success'?'check-circle-fill':'x-circle-fill' ?>"></i>
                                    <?= ucfirst($log['status']) ?>
                                </span>
                            </td>
                            <td class="mono" style="color:var(--muted2);"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                            <td>
                                <span class="truncate mono" style="display:block;color:var(--muted);" title="<?= htmlspecialchars($log['user_agent'] ?? '') ?>">
                                    <?php
                                    $ua = $log['user_agent'] ?? '';
                                    // Simple UA parser for display
                                    $browser = 'Unknown';
                                    if (str_contains($ua,'Chrome') && !str_contains($ua,'Edg'))   $browser = '🌐 Chrome';
                                    elseif (str_contains($ua,'Firefox'))  $browser = '🦊 Firefox';
                                    elseif (str_contains($ua,'Safari') && !str_contains($ua,'Chrome')) $browser = '🧭 Safari';
                                    elseif (str_contains($ua,'Edg'))      $browser = '🌐 Edge';
                                    elseif (str_contains($ua,'Opera'))    $browser = '🔴 Opera';
                                    $os = '';
                                    if (str_contains($ua,'Windows'))      $os = 'Windows';
                                    elseif (str_contains($ua,'Mac'))      $os = 'macOS';
                                    elseif (str_contains($ua,'Linux'))    $os = 'Linux';
                                    elseif (str_contains($ua,'Android'))  $os = 'Android';
                                    elseif (str_contains($ua,'iPhone')||str_contains($ua,'iPad')) $os = 'iOS';
                                    echo htmlspecialchars("$browser" . ($os ? " · $os" : ''));
                                    ?>
                                </span>
                            </td>
                            <td class="mono" style="font-size:0.75rem;color:var(--muted2);">
                                <?= date('M d, Y  H:i:s', strtotime($log['logged_at'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($log_pages > 1): ?>
                <div class="pagination">
                    <?php for ($p=1;$p<=$log_pages;$p++): ?>
                    <a href="?tab=logs&lp=<?=$p?>&log_user=<?=urlencode($log_user)?>"
                       class="page-btn <?=$p===$log_page?'active':''?>"><?=$p?></a>
                    <?php endfor; ?>
                    <span class="page-info"><?= $log_total ?> total entries</span>
                </div>
                <?php endif; ?>
            </div>
        </div><!-- /section-logs -->

    </div><!-- /content -->
</div><!-- /main -->

<!-- CHANGE ROLE MODAL -->
<div class="modal-ov" id="roleModal" onclick="if(event.target===this)closeRoleModal()">
    <div class="modal-box">
        <div class="modal-title"><i class="bi bi-shield-fill" style="color:var(--blue);"></i> Change Role</div>
        <p id="roleModalDesc" style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem;"></p>
        <form method="POST" id="roleForm">
            <input type="hidden" name="act" value="change_role">
            <input type="hidden" name="uid" id="roleUid">
            <div class="mfield">
                <label>New Role</label>
                <select name="new_role" id="roleSelect">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super Admin</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Save Role</button>
                <button type="button" class="btn-cancel-m" onclick="closeRoleModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Clock ──
function tick() {
    var now = new Date();
    document.getElementById('adminClock').textContent =
        now.toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
tick(); setInterval(tick, 1000);

// ── Tab switcher ──
function showTab(tab) {
    document.getElementById('section-users').style.display = tab==='users' ? '' : 'none';
    document.getElementById('section-logs').style.display  = tab==='logs'  ? '' : 'none';
    document.getElementById('tab-users').classList.toggle('active', tab==='users');
    document.getElementById('tab-logs').classList.toggle('active',  tab==='logs');
}
// On load, check URL param
(function(){
    var p = new URLSearchParams(window.location.search);
    if (p.get('tab')==='logs') showTab('logs');
})();

// ── Role modal ──
function openRoleModal(uid, uname, role) {
    var m = document.getElementById('roleModal');
    document.getElementById('roleUid').value = uid;
    document.getElementById('roleSelect').value = role;
    document.getElementById('roleModalDesc').textContent = 'Change role for @' + uname;
    m.classList.add('open');
    setTimeout(function(){ m.classList.add('vis'); }, 10);
}
function closeRoleModal() {
    var m = document.getElementById('roleModal');
    m.classList.remove('vis');
    setTimeout(function(){ m.classList.remove('open'); }, 200);
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeRoleModal(); });

// ── Auto-refresh stats every 30s ──
setInterval(function() { location.reload(); }, 30000);
</script>
</body>
</html>