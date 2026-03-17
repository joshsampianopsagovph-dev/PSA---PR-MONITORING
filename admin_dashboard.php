<?php
/**
 * admin_dashboard.php
 * ─────────────────────────────────────────────────────────────────
 * Super Admin only — landing page after login/signup.
 * Shows: live stats, recent logins, new accounts, hourly chart.
 * Regular users are redirected to index.php automatically.
 * ─────────────────────────────────────────────────────────────────
 */
session_start();
if (!isset($_SESSION['pms_user'])) {
    header('Location: login.php'); exit;
}
// Non-super-admins go to the regular dashboard
if (($_SESSION['pms_role'] ?? '') !== 'super_admin') {
    header('Location: index.php'); exit;
}
require_once 'config/database.php';
$current_admin = $_SESSION['pms_user'];

// ── Ensure tables/columns ────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    status ENUM('success','failed') DEFAULT 'success',
    logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX(logged_at), INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
foreach (['full_name VARCHAR(150) DEFAULT NULL','email VARCHAR(200) DEFAULT NULL',
          'is_active TINYINT(1) DEFAULT 1','last_login DATETIME DEFAULT NULL',
          'department VARCHAR(50) DEFAULT NULL','google_id VARCHAR(100) DEFAULT NULL'] as $col) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS $col"); } catch (Exception $e) {}
}

// ── AJAX refresh endpoint ────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'logins_today'   => (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE()")->fetchColumn(),
        'success_today'  => (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE() AND status='success'")->fetchColumn(),
        'failed_today'   => (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE() AND status='failed'")->fetchColumn(),
        'new_today'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'total_users'    => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'active_1h'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn(),
    ]); exit;
}

// ── Stats ────────────────────────────────────────────────────────
$s = [
    'logins_today'  => (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE()")->fetchColumn(),
    'success_today' => (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE() AND status='success'")->fetchColumn(),
    'failed_today'  => (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE DATE(logged_at)=CURDATE() AND status='failed'")->fetchColumn(),
    'new_today'     => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
    'new_week'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'total_users'   => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'total_logs'    => (int)$pdo->query("SELECT COUNT(*) FROM login_logs")->fetchColumn(),
    'total_failed'  => (int)$pdo->query("SELECT COUNT(*) FROM login_logs WHERE status='failed'")->fetchColumn(),
    'active_1h'     => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn(),
    'disabled'      => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=0")->fetchColumn(),
];

// ── Hourly chart data (last 24h) ─────────────────────────────────
$raw = $pdo->query("
    SELECT HOUR(logged_at) hr, status, COUNT(*) cnt
    FROM login_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY HOUR(logged_at), status
")->fetchAll(PDO::FETCH_ASSOC);
$ok_h = array_fill(0,24,0); $fail_h = array_fill(0,24,0);
foreach ($raw as $r) {
    if ($r['status']==='success') $ok_h[(int)$r['hr']]   = (int)$r['cnt'];
    else                          $fail_h[(int)$r['hr']] = (int)$r['cnt'];
}
$now_h = (int)date('H');
$clabels=[]; $cok=[]; $cfail=[];
for ($i=23;$i>=0;$i--) {
    $h = ($now_h-$i+24)%24;
    $clabels[] = sprintf('%02d:00',$h);
    $cok[]     = $ok_h[$h];
    $cfail[]   = $fail_h[$h];
}

// ── Recent logins (paginated) ────────────────────────────────────
$PER_LOG = 20; $PER_ACC = 15;
$lq=$_GET['lq']??''; $lstatus=$_GET['lstatus']??''; $ldate=$_GET['ldate']??'';
$lpage = max(1,(int)($_GET['lp']??1));
$lw=[]; $lp_arr=[];
if($lq)      { $lw[]="username LIKE ?";    $lp_arr[]="%$lq%"; }
if($lstatus) { $lw[]="status=?";           $lp_arr[]=$lstatus; }
if($ldate)   { $lw[]="DATE(logged_at)=?";  $lp_arr[]=$ldate; }
$lsql = $lw ? "WHERE ".implode(" AND ",$lw) : "";
$lc   = $pdo->prepare("SELECT COUNT(*) FROM login_logs $lsql"); $lc->execute($lp_arr);
$ltotal = (int)$lc->fetchColumn(); $lpages = max(1,ceil($ltotal/$PER_LOG));
$loff   = ($lpage-1)*$PER_LOG;
$ls     = $pdo->prepare("SELECT * FROM login_logs $lsql ORDER BY logged_at DESC LIMIT $PER_LOG OFFSET $loff");
$ls->execute($lp_arr); $logs = $ls->fetchAll(PDO::FETCH_ASSOC);

// ── New accounts (paginated) ─────────────────────────────────────
$nq=$_GET['nq']??''; $ndate=$_GET['ndate']??''; $ndept=$_GET['ndept']??'';
$npage = max(1,(int)($_GET['np']??1));
$nw=[]; $np_arr=[];
if($nq)    { $nw[]="(username LIKE ? OR full_name LIKE ? OR email LIKE ?)"; $np_arr=array_merge($np_arr,["%$nq%","%$nq%","%$nq%"]); }
if($ndate) { $nw[]="DATE(created_at)=?"; $np_arr[]=$ndate; }
if($ndept) { $nw[]="department=?";       $np_arr[]=$ndept; }
$nsql = $nw ? "WHERE ".implode(" AND ",$nw) : "";
$nc   = $pdo->prepare("SELECT COUNT(*) FROM users $nsql"); $nc->execute($np_arr);
$ntotal = (int)$nc->fetchColumn(); $npages = max(1,ceil($ntotal/$PER_ACC));
$noff   = ($npage-1)*$PER_ACC;
$nst    = $pdo->prepare("SELECT * FROM users $nsql ORDER BY created_at DESC LIMIT $PER_ACC OFFSET $noff");
$nst->execute($np_arr); $accounts = $nst->fetchAll(PDO::FETCH_ASSOC);

// Dept list for filter dropdown
$dept_rows = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// ── Helpers ──────────────────────────────────────────────────────
function ua_info(string $ua): array {
    $b='Unknown'; $ic='bi-globe';
    if(str_contains($ua,'Chrome')&&!str_contains($ua,'Edg')){ $b='Chrome'; $ic='bi-browser-chrome';}
    elseif(str_contains($ua,'Firefox')){ $b='Firefox'; $ic='bi-browser-firefox';}
    elseif(str_contains($ua,'Safari')&&!str_contains($ua,'Chrome')){ $b='Safari'; $ic='bi-browser-safari';}
    elseif(str_contains($ua,'Edg')){ $b='Edge'; $ic='bi-browser-edge';}
    elseif(str_contains($ua,'Opera')){ $b='Opera'; $ic='bi-globe';}
    $o='';
    if(str_contains($ua,'Windows'))     $o='Windows';
    elseif(str_contains($ua,'Mac'))     $o='macOS';
    elseif(str_contains($ua,'Android')) $o='Android';
    elseif(str_contains($ua,'iPhone')||str_contains($ua,'iPad')) $o='iOS';
    elseif(str_contains($ua,'Linux'))   $o='Linux';
    return ['browser'=>$b,'os'=>$o,'icon'=>$ic];
}
function ago(string $dt): string {
    $s=time()-strtotime($dt);
    if($s<60)    return $s.'s ago';
    if($s<3600)  return floor($s/60).'m ago';
    if($s<86400) return floor($s/3600).'h ago';
    return floor($s/86400).'d ago';
}
function purl(array $x=[]): string { return '?'.http_build_query(array_merge($_GET,$x)); }
function pager(int $page, int $pages, string $param): void {
    if($pages<=1) return;
    echo '<div class="pager">';
    if($page>1) echo '<a href="'.purl([$param=>$page-1]).'" class="pgbtn"><i class="bi bi-chevron-left"></i></a>';
    $s=max(1,$page-2); $e=min($pages,$page+2);
    if($s>1) echo '<a href="'.purl([$param=>1]).'" class="pgbtn">1</a><span class="pgdot">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="'.purl([$param=>$i]).'" class="pgbtn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$pages) echo '<span class="pgdot">…</span><a href="'.purl([$param=>$pages]).'" class="pgbtn">'.$pages.'</a>';
    if($page<$pages) echo '<a href="'.purl([$param=>$page+1]).'" class="pgbtn"><i class="bi bi-chevron-right"></i></a>';
    echo '<span class="pg-info">'.$page.'/'.$pages.'</span></div>';
}

$AVC = ['#3b82f6','#06d6a0','#a855f7','#f59e0b','#f43f5e','#06b6d4','#ec4899','#14b8a6','#f97316','#84cc16'];
$DC  = ['GSD'=>'#3b82f6','ADMIN'=>'#06d6a0','FIN'=>'#fbbf24','HRMD'=>'#a855f7','RSSO'=>'#f43f5e','ICT'=>'#06b6d4','LEGAL'=>'#ec4899','SUPPLY'=>'#14b8a6','BAC'=>'#f97316','PROC'=>'#84cc16','OTHER'=>'#64748b'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin Dashboard — PSA 2026</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
    --bg:#080b14;--sur:#0e1221;--sur2:#141828;--sur3:#1c2235;
    --bd:rgba(255,255,255,.07);--bd2:rgba(255,255,255,.13);
    --blue:#3b82f6;--teal:#06d6a0;--yellow:#fbbf24;
    --red:#f43f5e;--purple:#a855f7;--orange:#f97316;
    --text:#e2e8f0;--muted:#64748b;--muted2:#94a3b8;
}
html,body{min-height:100%;font-family:'Outfit',sans-serif;background:var(--bg);color:var(--text);}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,rgba(0,0,0,.035) 0,rgba(0,0,0,.035) 1px,transparent 1px,transparent 3px);pointer-events:none;}

/* ── SIDEBAR ── */
.sidebar{position:fixed;left:0;top:0;bottom:0;width:235px;background:var(--sur);border-right:1px solid var(--bd);display:flex;flex-direction:column;z-index:100;}
.sb-logo{padding:1.3rem 1.25rem 1rem;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:.7rem;}
.logo-icon{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#1d4ed8,#06d6a0);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:#fff;font-family:'JetBrains Mono',monospace;flex-shrink:0;}
.logo-txt{font-weight:800;font-size:.86rem;line-height:1.2;color:var(--text);}
.logo-txt span{display:block;font-size:.62rem;color:var(--muted);font-weight:500;}
.ng{padding:.9rem .75rem .25rem;font-size:.59rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);}
.nl{display:flex;align-items:center;gap:.65rem;padding:.58rem 1.1rem;margin:1px .5rem;border-radius:8px;color:var(--muted2);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .15s;}
.nl:hover{background:var(--sur3);color:var(--text);}
.nl.on{background:rgba(59,130,246,.14);color:var(--blue);border:1px solid rgba(59,130,246,.22);}
.nl i{font-size:.95rem;width:18px;text-align:center;flex-shrink:0;}
.nlb{margin-left:auto;font-size:.6rem;font-weight:800;padding:1px 7px;border-radius:8px;}
.sb-foot{margin-top:auto;padding:.75rem 1.25rem;border-top:1px solid var(--bd);display:flex;align-items:center;gap:.6rem;}
.sb-av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#a855f7);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0;}
.sb-info{font-size:.74rem;line-height:1.3;min-width:0;}
.sb-name{font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-role{color:var(--purple);font-size:.61rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}

/* ── TOPBAR ── */
.main{margin-left:235px;min-height:100vh;position:relative;z-index:1;}
.topbar{height:56px;background:var(--sur);border-bottom:1px solid var(--bd);display:flex;align-items:center;padding:0 1.75rem;gap:.8rem;position:sticky;top:0;z-index:50;}
.tb-dot{width:8px;height:8px;border-radius:50%;background:var(--teal);flex-shrink:0;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(6,214,160,.5);}70%{opacity:.7;box-shadow:0 0 0 5px rgba(6,214,160,0);}}
.tb-title{font-weight:900;font-size:1.05rem;}
.tb-pill{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:3px 10px;border-radius:20px;}
.tp-live{background:rgba(6,214,160,.12);color:var(--teal);border:1px solid rgba(6,214,160,.22);}
.tp-sa{background:rgba(168,85,247,.12);color:var(--purple);border:1px solid rgba(168,85,247,.22);}
.tb-sp{flex:1;}
.tb-clk{font-family:'JetBrains Mono',monospace;font-size:.76rem;color:var(--muted);background:var(--sur2);padding:4px 11px;border-radius:6px;border:1px solid var(--bd);}
.tb-btn{display:flex;align-items:center;gap:.4rem;font-size:.79rem;color:var(--muted2);text-decoration:none;padding:5px 12px;border-radius:7px;border:1px solid var(--bd);background:none;cursor:pointer;font-family:'Outfit',sans-serif;transition:all .15s;}
.tb-btn:hover{background:var(--sur3);color:var(--text);}
.content{padding:1.75rem;}

/* ── WELCOME BANNER ── */
.welcome{background:linear-gradient(135deg,rgba(59,130,246,.15),rgba(6,214,160,.08));border:1px solid rgba(59,130,246,.22);border-radius:14px;padding:1.25rem 1.5rem;margin-bottom:1.75rem;display:flex;align-items:center;gap:1rem;}
.w-av{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#06d6a0);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;color:#fff;flex-shrink:0;}
.w-text h2{font-size:1.05rem;font-weight:800;color:var(--text);}
.w-text p{font-size:.8rem;color:var(--muted2);margin-top:.15rem;}
.w-sp{flex:1;}
.w-time{font-family:'JetBrains Mono',monospace;font-size:.75rem;color:var(--muted);text-align:right;}

/* ── STAT GRID ── */
.sgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.75rem;}
.sc{background:var(--sur);border:1px solid var(--bd);border-radius:13px;padding:1.1rem 1.25rem;position:relative;overflow:hidden;transition:border-color .2s,transform .15s;cursor:default;}
.sc:hover{border-color:var(--bd2);transform:translateY(-2px);}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--ac);}
.sc-ic{font-size:1.5rem;margin-bottom:.5rem;display:block;}
.sc-num{font-family:'JetBrains Mono',monospace;font-size:2rem;font-weight:700;line-height:1;margin-bottom:.2rem;}
.sc-lbl{font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.05em;}
.sc-sub{font-size:.72rem;color:var(--muted);margin-top:.35rem;line-height:1.5;}

/* ── CHART CARD ── */
.chart-card{background:var(--sur);border:1px solid var(--bd);border-radius:13px;overflow:hidden;margin-bottom:1.75rem;}
.cc-head{padding:1rem 1.25rem;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:.75rem;}
.cc-head h3{font-weight:800;font-size:.95rem;}
.cc-sp{flex:1;}
.cc-auto{font-size:.68rem;color:var(--muted);font-family:'JetBrains Mono',monospace;}
.rbtn{display:flex;align-items:center;gap:.35rem;font-size:.75rem;font-weight:600;padding:5px 12px;border-radius:7px;border:1px solid var(--bd);background:var(--sur3);color:var(--muted2);cursor:pointer;font-family:'Outfit',sans-serif;transition:all .15s;}
.rbtn:hover{background:var(--sur2);color:var(--text);}

/* ── TWO PANELS ── */
.panels{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
@media(max-width:1100px){.panels{grid-template-columns:1fr;}}
.panel{background:var(--sur);border:1px solid var(--bd);border-radius:13px;overflow:hidden;display:flex;flex-direction:column;}
.ph{padding:.9rem 1.2rem;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:.6rem;}
.ph h3{font-weight:800;font-size:.9rem;}
.ph-sp{flex:1;}
.ph-cnt{font-size:.72rem;color:var(--muted);background:var(--sur3);padding:2px 9px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-weight:700;}
.va{font-size:.73rem;font-weight:600;color:var(--blue);text-decoration:none;padding:4px 11px;border-radius:7px;border:1px solid rgba(59,130,246,.2);background:rgba(59,130,246,.07);transition:all .15s;}
.va:hover{background:rgba(59,130,246,.18);}

/* ── FILTER BAR ── */
.fbar{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;padding:.65rem 1.1rem;border-bottom:1px solid var(--bd);background:var(--sur2);}
.fsbar{display:flex;align-items:center;gap:.4rem;background:var(--sur3);border:1px solid var(--bd);border-radius:7px;padding:0 .7rem;height:31px;}
.fsbar i{color:var(--muted);font-size:.8rem;}
.fsbar input{background:none;border:none;outline:none;color:var(--text);font-size:.77rem;font-family:'Outfit',sans-serif;width:120px;}
.fsbar input::placeholder{color:var(--muted);}
.fsel{background:var(--sur3);border:1px solid var(--bd);border-radius:7px;color:var(--muted2);font-size:.75rem;padding:0 7px;font-family:'Outfit',sans-serif;outline:none;cursor:pointer;height:31px;}
.fbtn{display:inline-flex;align-items:center;gap:.3rem;padding:0 10px;border-radius:7px;font-size:.75rem;font-weight:600;cursor:pointer;border:1px solid var(--bd);background:var(--sur3);color:var(--muted2);font-family:'Outfit',sans-serif;text-decoration:none;height:31px;transition:all .15s;}
.fbtn:hover{background:var(--sur2);color:var(--text);}
.frec{margin-left:auto;font-size:.68rem;color:var(--muted);font-family:'JetBrains Mono',monospace;white-space:nowrap;}

/* ── TABLE ── */
.tbl{width:100%;border-collapse:collapse;}
.tbl thead tr{background:var(--sur2);}
.tbl thead th{padding:.55rem .9rem;font-size:.61rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);white-space:nowrap;border-bottom:1px solid var(--bd);text-align:left;}
.tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .1s;}
.tbl tbody tr:last-child{border-bottom:none;}
.tbl tbody tr:hover{background:rgba(255,255,255,.025);}
.tbl td{padding:.6rem .9rem;font-size:.8rem;vertical-align:middle;}
.empty-r{text-align:center;padding:2.75rem !important;color:var(--muted);}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:.22rem;padding:2px 8px;border-radius:20px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
.b-ok{background:rgba(6,214,160,.12);color:var(--teal);border:1px solid rgba(6,214,160,.22);}
.b-fail{background:rgba(244,63,94,.1);color:var(--red);border:1px solid rgba(244,63,94,.18);}
.b-new{background:rgba(251,191,36,.12);color:var(--yellow);border:1px solid rgba(251,191,36,.22);}
.b-sa{background:rgba(168,85,247,.15);color:var(--purple);border:1px solid rgba(168,85,247,.28);}
.b-admin{background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.22);}
.b-user{background:rgba(100,116,139,.15);color:var(--muted2);border:1px solid rgba(100,116,139,.22);}

/* ── USER CELL ── */
.av{border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;flex-shrink:0;}
.ucell{display:flex;align-items:center;gap:.55rem;}
.uname{font-weight:700;font-size:.8rem;color:var(--text);line-height:1.2;}
.usub{font-size:.67rem;color:var(--muted);line-height:1.2;}

/* ── PAGINATION ── */
.pager{display:flex;align-items:center;gap:.3rem;padding:.7rem 1rem;border-top:1px solid var(--bd);flex-wrap:wrap;}
.pgbtn{min-width:28px;height:28px;border-radius:6px;border:1px solid var(--bd);background:var(--sur3);color:var(--muted2);display:inline-flex;align-items:center;justify-content:center;font-size:.73rem;font-weight:600;text-decoration:none;transition:all .15s;font-family:'JetBrains Mono',monospace;}
.pgbtn:hover{background:var(--sur2);color:var(--text);}
.pgbtn.active{background:rgba(59,130,246,.2);border-color:rgba(59,130,246,.38);color:var(--blue);}
.pgdot{color:var(--muted);font-size:.75rem;padding:0 2px;}
.pg-info{font-size:.68rem;color:var(--muted);margin-left:auto;font-family:'JetBrains Mono',monospace;}

.mono{font-family:'JetBrains Mono',monospace;}
.new-pulse{animation:np 2s infinite;}
@keyframes np{0%,100%{opacity:1;}50%{opacity:.4;}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
    <div class="sb-logo">
        <div class="logo-icon">SA</div>
        <div class="logo-txt">Admin Panel<span>PSA Procurement 2026</span></div>
    </div>
    <div class="ng">Main</div>
    <a href="admin_dashboard.php" class="nl on"><i class="bi bi-speedometer2"></i> Dashboard <span class="nlb" style="background:rgba(6,214,160,.15);color:var(--teal);">HOME</span></a>
    <a href="admin.php"           class="nl"><i class="bi bi-pie-chart-fill"></i> Overview</a>
    <a href="admin_users.php"     class="nl"><i class="bi bi-people-fill"></i> Accounts</a>
    <a href="admin_logs.php"      class="nl"><i class="bi bi-clock-history"></i> Login Logs</a>
    <a href="admin_history.php"   class="nl"><i class="bi bi-journal-text"></i> History</a>
    <a href="admin_monitor.php"   class="nl"><i class="bi bi-activity"></i> Monitor <span class="nlb" style="background:rgba(59,130,246,.15);color:var(--blue);">LIVE</span></a>
    <a href="admin_activity.php"  class="nl"><i class="bi bi-graph-up"></i> PR Activity</a>
    <a href="admin_settings.php"  class="nl"><i class="bi bi-gear-fill"></i> Settings</a>
    <div class="ng">System</div>
    <a href="index.php"   class="nl"><i class="bi bi-grid-fill"></i> Main Dashboard</a>
    <a href="profile.php" class="nl"><i class="bi bi-person-circle"></i> My Profile</a>
    <a href="department_system.php" class="nl"><i class="bi bi-building"></i> Departments</a>
    <a href="logout.php"  class="nl" style="color:var(--red);"><i class="bi bi-box-arrow-right"></i> Logout</a>
    <div class="sb-foot">
        <div class="sb-av"><?= strtoupper(substr($current_admin,0,1)) ?></div>
        <div class="sb-info">
            <div class="sb-name"><?= htmlspecialchars($current_admin) ?></div>
            <div class="sb-role">Super Admin</div>
        </div>
    </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">
    <div class="topbar">
        <div class="tb-dot"></div>
        <span class="tb-title">Admin Dashboard</span>
        <span class="tb-pill tp-sa"><i class="bi bi-shield-fill-check"></i> Super Admin</span>
        <span class="tb-pill tp-live"><i class="bi bi-broadcast"></i> Live</span>
        <div class="tb-sp"></div>
        <span class="tb-clk mono" id="clk">--:--:--</span>
        <button class="tb-btn" onclick="doRefresh()"><i class="bi bi-arrow-clockwise" id="ricon"></i> Refresh</button>
        <a href="admin_users.php" class="tb-btn"><i class="bi bi-person-plus-fill"></i> Add User</a>
        <a href="logout.php" class="tb-btn" style="color:var(--red);border-color:rgba(244,63,94,.25);"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>

    <div class="content">

        <!-- WELCOME BANNER -->
        <div class="welcome">
            <div class="w-av"><?= strtoupper(substr($current_admin,0,1)) ?></div>
            <div class="w-text">
                <h2>Welcome back, <?= htmlspecialchars($current_admin) ?> 👋</h2>
                <p>Here's what's happening in the system right now — <?= date('l, F j, Y') ?></p>
            </div>
            <div class="w-sp"></div>
            <div class="w-time">
                <div id="wb-time" class="mono" style="font-size:.9rem;font-weight:700;color:var(--text);">--:--:--</div>
                <div style="font-size:.68rem;color:var(--muted);margin-top:.15rem;">Philippine Time</div>
            </div>
        </div>

        <!-- STAT CARDS -->
        <div class="sgrid">
            <div class="sc" style="--ac:var(--blue);">
                <span class="sc-ic" style="color:var(--blue);"><i class="bi bi-box-arrow-in-right"></i></span>
                <div class="sc-num" id="st-logins" style="color:var(--blue);"><?= $s['logins_today'] ?></div>
                <div class="sc-lbl">Logins Today</div>
                <div class="sc-sub">
                    <span style="color:var(--teal);font-weight:700;"><?= $s['success_today'] ?> ✓</span> &nbsp;
                    <span style="color:var(--red);font-weight:700;"><?= $s['failed_today'] ?> ✗ failed</span>
                </div>
            </div>
            <div class="sc" style="--ac:var(--teal);">
                <span class="sc-ic" style="color:var(--teal);"><i class="bi bi-person-plus-fill"></i></span>
                <div class="sc-num" id="st-new" style="color:var(--teal);"><?= $s['new_today'] ?></div>
                <div class="sc-lbl">New Accounts Today</div>
                <div class="sc-sub">This week: <b style="color:var(--yellow);">+<?= $s['new_week'] ?></b> &nbsp;·&nbsp; Total: <b><?= number_format($s['total_users']) ?></b></div>
            </div>
            <div class="sc" style="--ac:var(--red);">
                <span class="sc-ic" style="color:var(--red);"><i class="bi bi-shield-exclamation"></i></span>
                <div class="sc-num" id="st-failed" style="color:var(--red);"><?= $s['failed_today'] ?></div>
                <div class="sc-lbl">Failed Attempts Today</div>
                <div class="sc-sub">All-time: <b><?= number_format($s['total_failed']) ?></b> &nbsp;·&nbsp; Disabled: <b style="color:var(--red);"><?= $s['disabled'] ?></b></div>
            </div>
            <div class="sc" style="--ac:var(--purple);">
                <span class="sc-ic" style="color:var(--purple);"><i class="bi bi-people-fill"></i></span>
                <div class="sc-num" id="st-active" style="color:var(--purple);"><?= $s['active_1h'] ?></div>
                <div class="sc-lbl">Active Last Hour</div>
                <div class="sc-sub">Total users: <b><?= number_format($s['total_users']) ?></b> &nbsp;·&nbsp; Logs: <b><?= number_format($s['total_logs']) ?></b></div>
            </div>
        </div>

        <!-- HOURLY ACTIVITY CHART -->
        <div class="chart-card">
            <div class="cc-head">
                <i class="bi bi-bar-chart-line-fill" style="color:var(--blue);font-size:1.1rem;"></i>
                <h3>Login Activity — Last 24 Hours</h3>
                <div class="cc-sp"></div>
                <span class="cc-auto">Auto-refreshes in <span id="countdown" class="mono" style="color:var(--teal);font-weight:700;">30</span>s</span>
                &nbsp;&nbsp;
                <button class="rbtn" onclick="doRefresh()"><i class="bi bi-arrow-clockwise" id="ricon2"></i> Refresh</button>
            </div>
            <div style="padding:1.1rem 1.25rem 1.25rem;">
                <canvas id="actChart" height="85"></canvas>
            </div>
        </div>

        <!-- TWO PANELS -->
        <div class="panels">

            <!-- LEFT: Recent Logins -->
            <div class="panel">
                <div class="ph">
                    <i class="bi bi-clock-history" style="color:var(--purple);font-size:1.05rem;"></i>
                    <h3>Recent Logins</h3>
                    <div class="ph-sp"></div>
                    <span class="ph-cnt"><?= number_format($ltotal) ?></span>
                    &nbsp;
                    <a href="admin_logs.php" class="va">Full Log →</a>
                </div>

                <form method="GET" class="fbar">
                    <div class="fsbar"><i class="bi bi-search"></i><input name="lq" placeholder="Username..." value="<?= htmlspecialchars($lq) ?>"></div>
                    <select name="lstatus" class="fsel" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="success" <?= $lstatus==='success'?'selected':'' ?>>✅ Success</option>
                        <option value="failed"  <?= $lstatus==='failed' ?'selected':'' ?>>❌ Failed</option>
                    </select>
                    <input type="date" name="ldate" class="fsel" value="<?= htmlspecialchars($ldate) ?>" onchange="this.form.submit()" style="width:115px;">
                    <button type="submit" class="fbtn"><i class="bi bi-funnel-fill"></i></button>
                    <a href="?" class="fbtn" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                    <span class="frec"><?= $lpage ?>/<?= $lpages ?></span>
                </form>

                <div style="flex:1;overflow:auto;">
                <table class="tbl">
                    <thead><tr>
                        <th>User</th><th>Status</th><th>IP Address</th><th>Device</th><th>Time</th>
                    </tr></thead>
                    <tbody>
                    <?php if(empty($logs)): ?>
                        <tr><td class="empty-r" colspan="5">
                            <i class="bi bi-inbox" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.4;"></i>
                            No login records found.
                        </td></tr>
                    <?php else: foreach($logs as $i=>$log):
                        $ok  = $log['status']==='success';
                        $ua  = ua_info($log['user_agent']??'');
                    ?>
                    <tr>
                        <td>
                            <div class="ucell">
                                <div class="av" style="width:27px;height:27px;font-size:10px;background:<?= $ok?'#06d6a0':'#f43f5e' ?>;"><?= strtoupper(substr($log['username'],0,1)) ?></div>
                                <div>
                                    <div class="uname"><?= htmlspecialchars($log['username']) ?></div>
                                    <div class="usub mono" style="font-size:.61rem;">UID:<?= $log['user_id']??'—' ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $ok?'b-ok':'b-fail' ?>">
                                <i class="bi bi-<?= $ok?'check-circle-fill':'x-circle-fill' ?>"></i>
                                <?= $ok?'Success':'Failed' ?>
                            </span>
                        </td>
                        <td class="mono" style="font-size:.69rem;color:var(--muted2);"><?= htmlspecialchars($log['ip_address']??'—') ?></td>
                        <td>
                            <div style="font-size:.72rem;color:var(--muted2);">
                                <i class="bi <?= $ua['icon'] ?>" style="font-size:.75rem;"></i>
                                <?= htmlspecialchars($ua['browser']) ?>
                            </div>
                            <div style="font-size:.64rem;color:var(--muted);"><?= htmlspecialchars($ua['os']) ?></div>
                        </td>
                        <td>
                            <div style="font-size:.72rem;font-weight:600;color:var(--muted2);"><?= ago($log['logged_at']) ?></div>
                            <div class="mono" style="font-size:.61rem;color:var(--muted);"><?= date('M d  H:i',strtotime($log['logged_at'])) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
                <?php pager($lpage, $lpages, 'lp'); ?>
            </div>

            <!-- RIGHT: New Accounts -->
            <div class="panel">
                <div class="ph">
                    <i class="bi bi-person-plus-fill" style="color:var(--teal);font-size:1.05rem;"></i>
                    <h3>New Accounts</h3>
                    <div class="ph-sp"></div>
                    <span class="ph-cnt"><?= number_format($ntotal) ?></span>
                    &nbsp;
                    <a href="admin_users.php" class="va">Manage →</a>
                </div>

                <form method="GET" class="fbar">
                    <div class="fsbar"><i class="bi bi-search"></i><input name="nq" placeholder="Name / email..." value="<?= htmlspecialchars($nq) ?>"></div>
                    <select name="ndept" class="fsel" onchange="this.form.submit()" style="width:90px;">
                        <option value="">All Depts</option>
                        <?php foreach($dept_rows as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $ndept===$d?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="ndate" class="fsel" value="<?= htmlspecialchars($ndate) ?>" onchange="this.form.submit()" style="width:115px;">
                    <button type="submit" class="fbtn"><i class="bi bi-funnel-fill"></i></button>
                    <a href="?" class="fbtn" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                    <span class="frec"><?= $npage ?>/<?= $npages ?></span>
                </form>

                <div style="flex:1;overflow:auto;">
                <table class="tbl">
                    <thead><tr>
                        <th>User</th><th>Dept</th><th>Role</th><th>Joined</th>
                    </tr></thead>
                    <tbody>
                    <?php if(empty($accounts)): ?>
                        <tr><td class="empty-r" colspan="4">
                            <i class="bi bi-inbox" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.4;"></i>
                            No accounts found.
                        </td></tr>
                    <?php else: foreach($accounts as $i=>$u):
                        $is_new = isset($u['created_at']) && date('Y-m-d',strtotime($u['created_at']))===date('Y-m-d');
                        $dept   = $u['department'] ?? 'OTHER';
                        $dc     = $DC[$dept] ?? '#64748b';
                        $role   = $u['role'] ?? 'user';
                        $rb     = ['super_admin'=>'b-sa','admin'=>'b-admin','user'=>'b-user'];
                        $rl     = ['super_admin'=>'Super Admin','admin'=>'Admin','user'=>'User'];
                    ?>
                    <tr>
                        <td>
                            <div class="ucell">
                                <div class="av" style="width:28px;height:28px;font-size:11px;background:<?= $AVC[$i%count($AVC)] ?>;"><?= strtoupper(substr($u['full_name']?:$u['username'],0,1)) ?></div>
                                <div>
                                    <div class="uname">
                                        <?= htmlspecialchars($u['username']) ?>
                                        <?php if($is_new): ?><span class="badge b-new new-pulse" style="font-size:.56rem;margin-left:2px;">NEW</span><?php endif; ?>
                                    </div>
                                    <div class="usub"><?= htmlspecialchars($u['full_name']??'')?:'—' ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:.62rem;font-weight:800;font-family:'JetBrains Mono',monospace;background:<?= $dc ?>1a;color:<?= $dc ?>;border:1px solid <?= $dc ?>40;">
                                <?= htmlspecialchars($dept) ?>
                            </span>
                        </td>
                        <td><span class="badge <?= $rb[$role]??'b-user' ?>"><?= $rl[$role]??'User' ?></span></td>
                        <td>
                            <div style="font-size:.72rem;font-weight:600;color:var(--muted2);"><?= $u['created_at']?ago($u['created_at']):'—' ?></div>
                            <div class="mono" style="font-size:.61rem;color:var(--muted);"><?= $u['created_at']?date('M d, Y',strtotime($u['created_at'])):'—' ?></div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
                <?php pager($npage, $npages, 'np'); ?>
            </div>

        </div><!-- /panels -->
    </div><!-- /content -->
</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Clock ─────────────────────────────────────────────────────────────────────
(function tick(){
    var t = new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('clk').textContent    = t;
    document.getElementById('wb-time').textContent = t;
    setTimeout(tick,1000);
})();

// ── Chart ─────────────────────────────────────────────────────────────────────
var actChart = new Chart(document.getElementById('actChart').getContext('2d'), {
    type:'bar',
    data:{
        labels: <?= json_encode($clabels) ?>,
        datasets:[
            {
                label:'Successful',
                data: <?= json_encode($cok) ?>,
                backgroundColor:'rgba(6,214,160,.5)',
                borderColor:'#06d6a0',
                borderWidth:1.5,
                borderRadius:4,
            },
            {
                label:'Failed',
                data: <?= json_encode($cfail) ?>,
                backgroundColor:'rgba(244,63,94,.4)',
                borderColor:'#f43f5e',
                borderWidth:1.5,
                borderRadius:4,
            }
        ]
    },
    options:{
        responsive:true,
        interaction:{mode:'index',intersect:false},
        plugins:{
            legend:{labels:{color:'#94a3b8',font:{family:'Outfit',size:12},boxWidth:10,padding:16}},
            tooltip:{backgroundColor:'#0e1221',borderColor:'rgba(255,255,255,.1)',borderWidth:1,
                     titleColor:'#e2e8f0',bodyColor:'#94a3b8',padding:10,cornerRadius:8}
        },
        scales:{
            x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#64748b',font:{family:'JetBrains Mono',size:9},maxTicksLimit:12}},
            y:{beginAtZero:true,grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#64748b',font:{family:'JetBrains Mono',size:10},stepSize:1}}
        }
    }
});

// ── Auto-refresh (stats only, no full reload) ─────────────────────────────────
var cd = 30;
var cdEl = document.getElementById('countdown');
setInterval(function(){
    cd--;
    if(cdEl) cdEl.textContent = cd;
    if(cd <= 0){ doRefresh(); cd = 30; }
}, 1000);

function doRefresh(){
    var ic1 = document.getElementById('ricon');
    var ic2 = document.getElementById('ricon2');
    [ic1,ic2].forEach(function(ic){ if(ic){ ic.style.animation='spin .6s linear infinite'; }});
    fetch('<?= $_SERVER['PHP_SELF'] ?>?ajax=1')
        .then(function(r){ return r.json(); })
        .then(function(d){
            document.getElementById('st-logins').textContent = d.logins_today;
            document.getElementById('st-new').textContent    = d.new_today;
            document.getElementById('st-failed').textContent = d.failed_today;
            document.getElementById('st-active').textContent = d.active_1h;
            [ic1,ic2].forEach(function(ic){ if(ic) ic.style.animation=''; });
        })
        .catch(function(){
            [ic1,ic2].forEach(function(ic){ if(ic) ic.style.animation=''; });
        });
}
</script>
<style>
@keyframes spin{ to{ transform:rotate(360deg); } }
</style>
</body>
</html>