<?php
/**
 * sidebar.php — PSA Responsive Sidebar Navigation
 * Single include for all pages. Auto-detects current page for active states.
 *
 * Usage in every page:
 *   <?php include 'sidebar.php'; ?>
 *
 * Wrap your main content with:
 *   <div class="psa-content"> ... </div>
 *
 * For index.php filters, the selects call applyFilters() / resetFilters()
 * which are defined in index.php itself.
 */

$_psa_page = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$_psa_user = $_SESSION['pms_user'] ?? '';
$_psa_initial = strtoupper(substr($_psa_user ?: 'U', 0, 1));

// Filter vars — only used on index.php, safe defaults on other pages
$_psa_f_proc = $f_processor ?? '';
$_psa_f_eu = $f_end_user ?? '';
$_psa_f_cat = $f_category ?? '';
$_psa_procs = $processor_stats ?? [];
$_psa_users = $all_end_users ?? [];
$_psa_cats = $category_stats ?? [];

$_psa_show_filters = ($_psa_page === 'index.php');

$_psa_nav = [
    ['href' => 'index.php', 'icon' => 'speedometer2', 'label' => 'Dashboard'],
    ['href' => 'table.php', 'icon' => 'grid-3x3-gap', 'label' => 'Procurement Monitoring'],
    ['href' => 'procurement_tracking.php', 'icon' => 'clipboard2-data', 'label' => 'Procurement Processing Time'],
];
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap"
    rel="stylesheet">

<style>
    /* ════════════════════════════════════════════════
   PSA SIDEBAR — VARIABLES
════════════════════════════════════════════════ */
    :root {
        --psa-w: 252px;
        --psa-topbar-h: 56px;
        --psa-bg: #ffffff;
        --psa-border: #e4e9f2;
        --psa-shadow: 0 0 0 1px rgba(0, 0, 0, .05), 4px 0 28px rgba(0, 0, 0, .08);
        --psa-blue: #1a3c8f;
        --psa-muted: #6b7280;
        --psa-tagline: #9ca3af;
        --psa-ease: cubic-bezier(.4, 0, .2, 1);
        --psa-t: 270ms;
    }

    /* ── Dark theme overrides ── */
    body.dark-theme {
        --psa-bg: #0d1b2a;
        --psa-border: #1e3a5f;
        --psa-blue: #90caf9;
        --psa-muted: #90caf9;
        --psa-tagline: #4a7eaa;
        --psa-shadow: 0 0 0 1px rgba(0, 0, 0, .2), 4px 0 28px rgba(0, 0, 0, .4);
    }

    /* ── Light theme overrides ── */
    body.light-theme {
        --psa-bg: #ffffff;
        --psa-border: #e4e9f2;
        --psa-blue: #1a3c8f;
        --psa-muted: #6b7280;
        --psa-tagline: #9ca3af;
        --psa-shadow: 0 0 0 1px rgba(0, 0, 0, .05), 4px 0 28px rgba(0, 0, 0, .08);
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    /* ════════════════════════════════════════════════
   LAYOUT — sidebar + content
════════════════════════════════════════════════ */
    .dashboard-wrapper,
    .psa-layout {
        display: flex;
        min-height: 100vh;
        width: 100%;
    }

    /* The sidebar itself */
    .psa-sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        width: var(--psa-w);
        background: var(--psa-bg);
        box-shadow: var(--psa-shadow);
        display: flex;
        flex-direction: column;
        z-index: 900;
        overflow-y: auto;
        overflow-x: hidden;
        transition: transform var(--psa-t) var(--psa-ease),
            box-shadow var(--psa-t) var(--psa-ease);
        scrollbar-width: thin;
        scrollbar-color: #dde2ea transparent;
        font-family: 'DM Sans', system-ui, sans-serif;
    }

    /* Main content areas push right of sidebar */
    .dashboard-main,
    .tv-main,
    .pt-main,
    .psa-content {
        margin-left: var(--psa-w);
        flex: 1;
        min-width: 0;
        transition: margin-left var(--psa-t) var(--psa-ease);
    }

    /* ── Accent stripe ─────────────────────────────── */
    .psa-stripe {
        height: 5px;
        background: linear-gradient(90deg, #1a3c8f 0%, #1a73c9 55%, #0da8ce 100%);
        flex-shrink: 0;
    }

    /* ── Close button ─────────────────────────────── */
    .psa-close-btn {
        display: none;
        align-self: flex-end;
        margin: .65rem .65rem 0;
        background: none;
        border: 1px solid var(--psa-border);
        border-radius: 8px;
        color: var(--psa-muted);
        cursor: pointer;
        padding: .3rem .55rem;
        font-size: .9rem;
        line-height: 1;
        transition: background var(--psa-t) var(--psa-ease);
    }

    .psa-close-btn:hover {
        background: #f3f4f6;
        color: #111;
    }

    /* ── Brand block ──────────────────────────────── */
    .psa-brand {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .55rem;
        padding: 1.4rem 1rem 1.2rem;
        border-bottom: 1px solid var(--psa-border);
    }

    .psa-logo-ring {
        width: 76px;
        height: 76px;
        border-radius: 50%;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(26, 60, 143, .13), 0 4px 16px rgba(0, 0, 0, .13);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 4px;
        transition: box-shadow var(--psa-t) var(--psa-ease);
    }

    .psa-logo-ring:hover {
        box-shadow: 0 0 0 4px rgba(26, 60, 143, .26), 0 6px 22px rgba(0, 0, 0, .18);
    }

    .psa-logo-ring img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: contain;
    }

    .psa-logo-fallback {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1a3c8f, #1787c9);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.65rem;
    }

    .psa-org-name {
        font-size: .8rem;
        font-weight: 800;
        color: var(--psa-blue);
        text-transform: uppercase;
        letter-spacing: .08em;
        text-align: center;
        line-height: 1.3;
    }

    .psa-org-tagline {
        font-size: .65rem;
        color: var(--psa-tagline);
        font-style: italic;
        letter-spacing: .04em;
    }

    /* ── Nav ──────────────────────────────────────── */
    .psa-nav {
        display: flex;
        flex-direction: column;
        gap: .42rem;
        padding: 1rem .8rem .6rem;
    }

    .psa-nav-link {
        display: flex;
        align-items: center;
        gap: .7rem;
        padding: .68rem 1rem;
        border-radius: 10px;
        text-decoration: none;
        font-size: .82rem;
        font-weight: 700;
        color: #fff;
        position: relative;
        overflow: hidden;
        font-family: 'DM Sans', sans-serif;
        transition: filter var(--psa-t) var(--psa-ease),
            transform var(--psa-t) var(--psa-ease),
            box-shadow var(--psa-t) var(--psa-ease);
        animation: psaSlideIn 340ms var(--psa-ease) both;
    }

    @keyframes psaSlideIn {
        from {
            opacity: 0;
            transform: translateX(-12px);
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .psa-nav-link[data-i="0"] {
        animation-delay: 60ms;
    }

    .psa-nav-link[data-i="1"] {
        animation-delay: 110ms;
    }

    .psa-nav-link[data-i="2"] {
        animation-delay: 160ms;
    }

    .psa-nav-link::after {
        content: '';
        position: absolute;
        inset: 0;
        background: rgba(255, 255, 255, 0);
        transition: background var(--psa-t) var(--psa-ease);
    }

    .psa-nav-link:hover::after {
        background: rgba(255, 255, 255, .08);
    }

    .psa-nav-link:hover {
        filter: brightness(1.1);
        transform: translateX(3px);
    }

    .psa-nav-link:active {
        transform: translateX(1px) scale(.985);
    }

    .psa-nav-link[data-i="0"] {
        background: linear-gradient(130deg, #1e50cc, #2563eb);
        box-shadow: 0 2px 10px rgba(37, 99, 235, .3);
    }

    .psa-nav-link[data-i="1"] {
        background: linear-gradient(130deg, #1a5db8, #1d72e8);
        box-shadow: 0 2px 10px rgba(29, 114, 232, .3);
    }

    .psa-nav-link[data-i="2"] {
        background: linear-gradient(130deg, #0580a4, #0da8ce);
        box-shadow: 0 2px 10px rgba(13, 168, 206, .3);
    }

    .psa-nav-link.is-active {
        filter: brightness(1.1);
        transform: translateX(4px);
    }

    .psa-nav-link.is-active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 18%;
        bottom: 18%;
        width: 3px;
        border-radius: 0 3px 3px 0;
        background: rgba(255, 255, 255, .85);
    }

    .psa-nav-link[data-i="0"].is-active {
        box-shadow: 0 5px 20px rgba(37, 99, 235, .45);
    }

    .psa-nav-link[data-i="1"].is-active {
        box-shadow: 0 5px 20px rgba(29, 114, 232, .45);
    }

    .psa-nav-link[data-i="2"].is-active {
        box-shadow: 0 5px 20px rgba(13, 168, 206, .45);
    }

    .psa-nav-icon {
        font-size: 1rem;
        flex-shrink: 0;
        opacity: .9;
    }

    .psa-nav-label {
        line-height: 1.25;
    }

    /* ── Filters (index.php only) ─────────────────── */
    .psa-filters {
        padding: .5rem .85rem .6rem;
        border-top: 1px solid var(--psa-border);
    }

    .psa-section-label {
        font-size: .63rem;
        font-weight: 700;
        color: var(--psa-muted);
        text-transform: uppercase;
        letter-spacing: .09em;
        margin: .55rem 0 .35rem;
        display: flex;
        align-items: center;
        gap: .35rem;
    }

    .psa-filter-group {
        margin-bottom: .5rem;
    }

    .psa-filter-label {
        display: block;
        font-size: .66rem;
        font-weight: 600;
        color: var(--psa-muted);
        text-transform: uppercase;
        letter-spacing: .07em;
        margin-bottom: .28rem;
    }

    .psa-select-wrap {
        position: relative;
    }

    .psa-select {
        width: 100%;
        padding: .46rem .7rem;
        padding-right: 1.9rem;
        background: #f9fafb;
        border: 1px solid var(--psa-border);
        border-radius: 8px;
        color: #374151;
        font-size: .77rem;
        font-family: 'DM Sans', sans-serif;
        appearance: none;
        -webkit-appearance: none;
        cursor: pointer;
        outline: none;
        transition: border-color var(--psa-t) var(--psa-ease),
            box-shadow var(--psa-t) var(--psa-ease);
    }

    .psa-select:hover {
        border-color: #9ca3af;
    }

    .psa-select:focus {
        border-color: var(--psa-blue);
        box-shadow: 0 0 0 3px rgba(26, 60, 143, .1);
    }

    .psa-select-icon {
        position: absolute;
        right: .65rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--psa-muted);
        font-size: .7rem;
        pointer-events: none;
    }

    .psa-btn-reset {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
        width: 100%;
        padding: .48rem;
        margin-top: .4rem;
        background: rgba(220, 38, 38, .06);
        border: 1px solid rgba(220, 38, 38, .18);
        border-radius: 8px;
        color: #b91c1c;
        font-size: .75rem;
        font-weight: 700;
        font-family: 'DM Sans', sans-serif;
        cursor: pointer;
        transition: background var(--psa-t) var(--psa-ease);
    }

    .psa-btn-reset:hover {
        background: rgba(220, 38, 38, .13);
    }

    /* ── Spacer ───────────────────────────────────── */
    .psa-sb-spacer {
        flex: 1;
        min-height: .5rem;
    }

    /* ── Footer ───────────────────────────────────── */
    .psa-footer {
        padding: .7rem .8rem .9rem;
        border-top: 1px solid var(--psa-border);
        display: flex;
        flex-direction: column;
        gap: .4rem;
    }

    .psa-profile-link {
        display: flex;
        align-items: center;
        gap: .55rem;
        padding: .5rem .72rem;
        border-radius: 9px;
        background: rgba(26, 60, 143, .06);
        border: 1px solid rgba(26, 60, 143, .13);
        color: var(--psa-blue);
        text-decoration: none;
        font-size: .77rem;
        font-weight: 600;
        font-family: 'DM Sans', sans-serif;
        transition: background var(--psa-t) var(--psa-ease);
    }

    .psa-profile-link:hover {
        background: rgba(26, 60, 143, .12);
    }

    .psa-avatar {
        width: 27px;
        height: 27px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1787c9, #1e50cc);
        color: #fff;
        font-size: .72rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .psa-profile-name {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .psa-logout-link {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .46rem .72rem;
        border-radius: 9px;
        background: rgba(220, 38, 38, .05);
        border: 1px solid rgba(220, 38, 38, .13);
        color: #b91c1c;
        text-decoration: none;
        font-size: .76rem;
        font-weight: 600;
        font-family: 'DM Sans', sans-serif;
        transition: background var(--psa-t) var(--psa-ease);
    }

    .psa-logout-link:hover {
        background: rgba(220, 38, 38, .12);
    }

    /* ════════════════════════════════════════════════
   MOBILE TOP-BAR
════════════════════════════════════════════════ */
    .psa-topbar {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: var(--psa-topbar-h);
        background: #fff;
        border-bottom: 1px solid var(--psa-border);
        box-shadow: 0 1px 10px rgba(0, 0, 0, .07);
        align-items: center;
        gap: .75rem;
        padding: 0 1rem;
        z-index: 950;
        font-family: 'DM Sans', sans-serif;
    }

    .psa-topbar-brand {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-size: .82rem;
        font-weight: 700;
        color: var(--psa-blue);
        letter-spacing: .05em;
        text-transform: uppercase;
    }

    .psa-topbar-img {
        width: 29px;
        height: 29px;
        border-radius: 50%;
        object-fit: contain;
        background: #f3f4f6;
        padding: 2px;
    }

    .psa-topbar-fallback {
        width: 29px;
        height: 29px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1a3c8f, #1787c9);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: .75rem;
    }

    .psa-ham {
        background: none;
        border: none;
        cursor: pointer;
        padding: 5px;
        display: flex;
        flex-direction: column;
        gap: 5px;
        flex-shrink: 0;
    }

    .psa-ham span {
        display: block;
        width: 22px;
        height: 2px;
        background: var(--psa-blue);
        border-radius: 2px;
        transition: transform var(--psa-t) var(--psa-ease),
            opacity var(--psa-t) var(--psa-ease);
        transform-origin: center;
    }

    .psa-ham.is-open span:nth-child(1) {
        transform: translateY(7px) rotate(45deg);
    }

    .psa-ham.is-open span:nth-child(2) {
        opacity: 0;
        transform: scaleX(0);
    }

    .psa-ham.is-open span:nth-child(3) {
        transform: translateY(-7px) rotate(-45deg);
    }

    /* Overlay */
    .psa-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .45);
        z-index: 890;
        opacity: 0;
        backdrop-filter: blur(3px);
        transition: opacity var(--psa-t) var(--psa-ease);
    }

    .psa-overlay.is-visible {
        display: block;
        opacity: 1;
    }

    /* ════════════════════════════════════════════════
   RESPONSIVE
════════════════════════════════════════════════ */
    @media (max-width: 920px) {
        .psa-topbar {
            display: flex;
        }

        .psa-close-btn {
            display: flex;
        }

        .psa-sidebar {
            transform: translateX(calc(-1 * var(--psa-w)));
            box-shadow: none;
        }

        .psa-sidebar.is-open {
            transform: translateX(0);
            box-shadow: var(--psa-shadow), 8px 0 40px rgba(0, 0, 0, .18);
        }

        .dashboard-main,
        .tv-main,
        .pt-main,
        .psa-content {
            margin-left: 0 !important;
            padding-top: calc(var(--psa-topbar-h) + 1rem);
        }
    }

    @media (max-width: 480px) {
        :root {
            --psa-w: min(280px, 90vw);
        }
    }

    /* ════════════════════════════════════════════════
   DARK THEME — Sidebar inner elements
════════════════════════════════════════════════ */
    body.dark-theme .psa-sidebar {
        background: #0d1b2a;
    }

    body.dark-theme .psa-org-name {
        color: #90caf9;
    }

    body.dark-theme .psa-org-tagline {
        color: #4a7eaa;
    }

    body.dark-theme .psa-logo-ring {
        background: #0f1f3d;
        box-shadow: 0 0 0 3px rgba(144, 202, 249, .18), 0 4px 16px rgba(0, 0, 0, .4);
    }

    body.dark-theme .psa-close-btn {
        border-color: #1e3a5f;
        color: #90caf9;
    }

    body.dark-theme .psa-close-btn:hover {
        background: #1e3a5f;
        color: #e8f0fe;
    }

    body.dark-theme .psa-brand {
        border-bottom-color: #1e3a5f;
    }

    body.dark-theme .psa-filters {
        border-top-color: #1e3a5f;
    }

    body.dark-theme .psa-section-label {
        color: #4a7eaa;
    }

    body.dark-theme .psa-filter-label {
        color: #4a7eaa;
    }

    body.dark-theme .psa-select {
        background: #0f1f3d;
        border-color: #1e3a5f;
        color: #e8f0fe;
    }

    body.dark-theme .psa-select:hover {
        border-color: #2a5080;
    }

    body.dark-theme .psa-select:focus {
        border-color: #42a5f5;
        box-shadow: 0 0 0 3px rgba(66, 165, 245, .15);
    }

    body.dark-theme .psa-select-icon {
        color: #4a7eaa;
    }

    body.dark-theme .psa-btn-reset {
        background: rgba(239, 68, 68, .08);
        border-color: rgba(239, 68, 68, .22);
        color: #ef9a9a;
    }

    body.dark-theme .psa-btn-reset:hover {
        background: rgba(239, 68, 68, .18);
    }

    body.dark-theme .psa-footer {
        border-top-color: #1e3a5f;
    }

    body.dark-theme .psa-profile-link {
        background: rgba(66, 165, 245, .08);
        border-color: rgba(66, 165, 245, .2);
        color: #90caf9;
    }

    body.dark-theme .psa-profile-link:hover {
        background: rgba(66, 165, 245, .16);
    }

    body.dark-theme .psa-logout-link {
        background: rgba(239, 68, 68, .06);
        border-color: rgba(239, 68, 68, .18);
        color: #ef9a9a;
    }

    body.dark-theme .psa-logout-link:hover {
        background: rgba(239, 68, 68, .14);
    }

    /* Dark topbar */
    body.dark-theme .psa-topbar {
        background: #0d1b2a;
        border-bottom-color: #1e3a5f;
        box-shadow: 0 1px 10px rgba(0, 0, 0, .3);
    }

    body.dark-theme .psa-topbar-brand {
        color: #90caf9;
    }

    body.dark-theme .psa-ham span {
        background: #90caf9;
    }

    /* ════════════════════════════════════════════════
   LIGHT THEME — Sidebar (explicit resets for clean override)
════════════════════════════════════════════════ */
    body.light-theme .psa-sidebar {
        background: #ffffff;
    }

    body.light-theme .psa-org-name {
        color: #1a3c8f;
    }

    body.light-theme .psa-topbar {
        background: #fff;
        border-bottom-color: #e4e9f2;
    }

    body.light-theme .psa-topbar-brand {
        color: #1a3c8f;
    }

    body.light-theme .psa-ham span {
        background: #1a3c8f;
    }

    body.light-theme .psa-select {
        background: #f9fafb;
        border-color: #e4e9f2;
        color: #374151;
    }

    body.light-theme .psa-profile-link {
        background: rgba(26, 60, 143, .06);
        border-color: rgba(26, 60, 143, .13);
        color: #1a3c8f;
    }

    body.light-theme .psa-logout-link {
        background: rgba(220, 38, 38, .05);
        border-color: rgba(220, 38, 38, .13);
        color: #b91c1c;
    }
</style>

<!-- ── Mobile top-bar ──────────────────────────────── -->
<header class="psa-topbar">
    <button class="psa-ham" id="psaHam" aria-label="Open menu" aria-expanded="false" aria-controls="psaSidebar">
        <span></span><span></span><span></span>
    </button>
    <div class="psa-topbar-brand">
        <img src="psa.png" alt="PSA" class="psa-topbar-img"
            onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <span class="psa-topbar-fallback" style="display:none"><i class="bi bi-globe-asia-australia"></i></span>
        <span>PSA · Procurement</span>
    </div>
</header>

<!-- ── Overlay ─────────────────────────────────────── -->
<div class="psa-overlay" id="psaOverlay"></div>

<!-- ── Sidebar ─────────────────────────────────────── -->
<aside class="psa-sidebar" id="psaSidebar" aria-label="Main navigation">

    <div class="psa-stripe"></div>

    <button class="psa-close-btn" id="psaClose" aria-label="Close menu">
        <i class="bi bi-x-lg"></i>
    </button>

    <!-- Brand -->
    <div class="psa-brand">
        <div class="psa-logo-ring">
            <img src="psa.png" alt="Philippine Statistics Authority"
                onerror="this.parentElement.innerHTML='<div class=\'psa-logo-fallback\'><i class=\'bi bi-globe-asia-australia\'></i></div>'">
        </div>
        <p class="psa-org-name">Philippine Statistics<br>Authority</p>
        <p class="psa-org-tagline">Solid · Responsive · World-class</p>
    </div>

    <!-- Nav links -->
    <nav class="psa-nav" aria-label="Main menu">
        <?php foreach ($_psa_nav as $i => $item):
            $active = match ($i) {
                0 => $_psa_page === 'index.php',
                1 => $_psa_page === 'table.php',
                2 => $_psa_page === 'procurement_tracking.php',
                default => false,
            };
            ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" class="psa-nav-link<?= $active ? ' is-active' : '' ?>"
                data-i="<?= $i ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-<?= $item['icon'] ?> psa-nav-icon" aria-hidden="true"></i>
                <span class="psa-nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($_psa_show_filters): ?>
        <!-- Filters — only rendered on index.php -->
        <div class="psa-filters">
            <p class="psa-section-label"><i class="bi bi-funnel"></i> Filters</p>

            <div class="psa-filter-group">
                <label class="psa-filter-label" for="filterProcessor">Processor</label>
                <div class="psa-select-wrap">
                    <select class="psa-select" id="filterProcessor" onchange="applyFilters()">
                        <option value="">All Processors</option>
                        <?php foreach ($_psa_procs as $p): ?>
                            <option value="<?= htmlspecialchars($p['processor']) ?>" <?= ($_psa_f_proc === $p['processor']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(substr($p['processor'], 0, 32)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="bi bi-chevron-down psa-select-icon"></i>
                </div>
            </div>

            <div class="psa-filter-group">
                <label class="psa-filter-label" for="filterEndUser">End User</label>
                <div class="psa-select-wrap">
                    <select class="psa-select" id="filterEndUser" onchange="applyFilters()">
                        <option value="">All End Users</option>
                        <?php foreach ($_psa_users as $eu): ?>
                            <option value="<?= htmlspecialchars($eu) ?>" <?= ($_psa_f_eu === $eu) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($eu) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="bi bi-chevron-down psa-select-icon"></i>
                </div>
            </div>

            <div class="psa-filter-group">
                <label class="psa-filter-label" for="filterCategory">Category</label>
                <div class="psa-select-wrap">
                    <select class="psa-select" id="filterCategory" onchange="applyFilters()">
                        <option value="">All Categories</option>
                        <?php foreach ($_psa_cats as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['category']) ?>" <?= ($_psa_f_cat === $cat['category']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(substr($cat['category'], 0, 32)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="bi bi-chevron-down psa-select-icon"></i>
                </div>
            </div>

            <button class="psa-btn-reset" onclick="resetFilters()">
                <i class="bi bi-arrow-counterclockwise"></i> Reset Filters
            </button>
        </div>
    <?php endif; ?>

    <div class="psa-sb-spacer"></div>

    <!-- Footer -->
    <footer class="psa-footer">
        <?php if ($_psa_user): ?>
            <a href="profile.php" class="psa-profile-link">
                <span class="psa-avatar"><?= htmlspecialchars($_psa_initial) ?></span>
                <span class="psa-profile-name"><?= htmlspecialchars($_psa_user) ?></span>
                <i class="bi bi-chevron-right" style="font-size:.62rem;opacity:.45;margin-left:auto"></i>
            </a>
        <?php endif; ?>
        <a href="logout.php" class="psa-logout-link">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </footer>

</aside>

<script>
    (function () {
        'use strict';
        var sidebar = document.getElementById('psaSidebar');
        var ham = document.getElementById('psaHam');
        var overlay = document.getElementById('psaOverlay');
        var closeBtn = document.getElementById('psaClose');

        function open() {
            sidebar.classList.add('is-open');
            ham.classList.add('is-open');
            overlay.classList.add('is-visible');
            ham.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            sidebar.classList.remove('is-open');
            ham.classList.remove('is-open');
            overlay.classList.remove('is-visible');
            ham.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        ham.addEventListener('click', function () {
            sidebar.classList.contains('is-open') ? close() : open();
        });
        closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        sidebar.querySelectorAll('.psa-nav-link').forEach(function (a) {
            a.addEventListener('click', close);
        });
    }());
</script>