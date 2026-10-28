<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../db_connect.php';

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($sessionUserId <= 0) {
    header('Location: ../login.php');
    exit;
}

$authStmt = $conn->prepare(
    "SELECT id, full_name, email, role, status, photo_path
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$authStmt) {
    error_log('Admin authentication query preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to verify your account right now. Please try again later.');
}

$authStmt->bind_param('i', $sessionUserId);
$authStmt->execute();
$authResult = $authStmt->get_result();
$verifiedAccount = $authResult->fetch_assoc();
$authStmt->close();

if (
    !$verifiedAccount ||
    strtolower((string) ($verifiedAccount['role'] ?? '')) !== 'admin' ||
    strcasecmp((string) ($verifiedAccount['status'] ?? ''), 'Active') !== 0
) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParams = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    session_destroy();
    header('Location: ../login.php');
    exit;
}

if (!function_exists('fmt_date')) {
    function fmt_date($date)
    {
        if (empty($date)) {
            return 'Not available';
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return 'Not available';
        }

        return date('M d, Y', $timestamp);
    }
}

if (!function_exists('status_class')) {
    function status_class($status)
    {
        $status = strtolower(trim((string) $status));

        switch ($status) {
            case 'active':
                return 'active';

            case 'pending':
                return 'pending';

            case 'suspended':
                return 'suspended';

            case 'inactive':
                return 'inactive';

            case 'removed':
                return 'removed';

            case 'accepted':
                return 'accepted';

            case 'declined':
                return 'declined';

            case 'complete':
                return 'complete';

            case 'cancelled':
                return 'cancelled';

            default:
                return 'inactive';
        }
    }
}

$admin = [
    'id'         => (int) $verifiedAccount['id'],
    'name'       => $verifiedAccount['full_name'] ?: $verifiedAccount['email'],
    'email'      => $verifiedAccount['email'],
    'photo_path' => $verifiedAccount['photo_path'] ?? ''
];

$title  = $title  ?? 'Admin';
$css    = $css    ?? [];
$active = $active ?? '';

$menu = [
    'admin-dashboard' => 'Dashboard',
    'users'           => 'Users',
    'create-admin'    => 'Create Admin',
    'content'         => 'Content',
    'reports'         => 'Reports'
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0c0d0a">
<title><?= e($title) ?> | Studentlink Admin</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800;900&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">

<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="../css/<?= e($c) ?>.css">
<?php endforeach; ?>

<style>
    :root {
        --bg: #0c0d0a;
        --bg-soft: #11120f;
        --panel: #151611;
        --panel-hover: #1b1c16;
        --panel-light: #23241d;
        --panel-deep: #0f100c;

        --line: #292a22;
        --line-light: #393a2f;

        --stone: #f3f0e8;
        --text: #e8e5dc;
        --muted: #c0c0b2;
        --faint: #aaa99a;

        --orange: #d94f16;
        --orange-light: #ff895c;
        --orange-soft: rgba(217, 79, 22, 0.14);

        --olive: #85876d;
        --olive-light: #c9cbb0;

        --black: #070806;
        --white: #ffffff;

        --radius: 20px;
        --shadow: 0 14px 35px rgba(0, 0, 0, 0.4);

        --f-head: 'Big Shoulders Display', 'Arial Narrow', Impact, sans-serif;
        --f-body: 'Source Sans 3', system-ui, -apple-system, 'Segoe UI', sans-serif;
    }

    *, *::before, *::after { box-sizing: border-box; }

    html,
    body {
        background-color: #0c0d0a !important;
    }

    body {
        margin: 0;
        min-height: 100vh;
        color: var(--text);
        background-image:
            radial-gradient(ellipse at 85% 0%, rgba(133, 135, 109, 0.07), transparent 36%),
            radial-gradient(ellipse at 8% 100%, rgba(217, 79, 22, 0.05), transparent 40%);
        background-repeat: no-repeat;
        background-attachment: fixed;
        font-family: var(--f-body);
        font-size: 16px;
        line-height: 1.6;
        color-scheme: dark;
        -webkit-font-smoothing: antialiased;
    }

    a { text-decoration: none; }


    /* ---------- TOP BAR ---------- */

    .admin-bar {
        position: sticky;
        top: 0;
        z-index: 1000;
        display: flex;
        align-items: center;
        gap: 14px;
        min-height: 72px;
        padding: 12px 28px;
        background: rgba(7, 8, 6, 0.92);
        border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .admin-bar .logo {
        display: inline-flex;
        align-items: center;
        flex-shrink: 0;
        color: var(--stone);
        font-family: var(--f-head);
        font-size: 30px;
        font-weight: 900;
        line-height: 1;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .admin-bar .logo::before {
        width: 9px;
        height: 26px;
        margin-right: 10px;
        background: var(--orange);
        border-radius: 3px;
        content: "";
        transform: skew(-12deg);
    }

    .admin-bar .logo:hover { color: var(--orange-light); }

    .role-chip {
        padding: 6px 12px;
        color: var(--orange-light);
        background: var(--orange-soft);
        border: 1px solid rgba(217, 79, 22, 0.3);
        border-radius: 8px;
        font-size: 10.5px;
        font-weight: 800;
        letter-spacing: 1.6px;
        line-height: 1.3;
        text-transform: uppercase;
    }

    .admin-bar .who {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-left: auto;
        min-width: 0;
    }

    .admin-bar .who a {
        display: inline-flex;
        align-items: center;
        min-height: 40px;
        padding: 8px 14px;
        color: var(--muted);
        background: transparent;
        border: 1px solid var(--line);
        border-radius: 10px;
        font-size: 13px;
        font-weight: 700;
        white-space: nowrap;
        transition: color 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .admin-bar .who a:hover {
        color: var(--stone);
        background: var(--panel);
        border-color: var(--line-light);
    }

    .admin-bar .who .avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        color: var(--stone);
        background: var(--olive);
        border: 2px solid rgba(243, 240, 232, 0.12);
        border-radius: 13px;
        font-size: 14px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .admin-bar .who strong {
        max-width: 190px;
        overflow: hidden;
        color: var(--stone);
        font-size: 14px;
        font-weight: 800;
        text-overflow: ellipsis;
        white-space: nowrap;
    }


    /* ---------- LAYOUT: sidebar + content ---------- */

    .admin {
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        align-items: start;
        gap: 28px;
        width: min(1640px, 100%);
        margin: 0 auto;
        padding: 28px 28px 0;
    }

    .side {
        position: sticky;
        top: 100px;
        overflow: hidden;
        padding: 16px 12px 12px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: var(--radius);
    }

    .side::before {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: var(--orange);
        content: "";
    }

    .side nav {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .side nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 44px;
        padding: 10px 14px;
        color: var(--muted);
        background: transparent;
        border: 1px solid transparent;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        transition: color 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .side nav a::before {
        width: 6px;
        height: 6px;
        flex-shrink: 0;
        background: var(--line-light);
        border-radius: 50%;
        content: "";
        transition: background 0.2s ease;
    }

    .side nav a:hover {
        color: var(--stone);
        background: var(--panel-hover);
    }

    .side nav a:hover::before { background: var(--olive-light); }

    .side nav a[aria-current="page"] {
        color: var(--orange-light);
        background: var(--orange-soft);
        border-color: rgba(217, 79, 22, 0.25);
    }

    .side nav a[aria-current="page"]::before { background: var(--orange-light); }

    .side hr {
        width: 100%;
        height: 0;
        margin: 8px 0;
        border: 0;
        border-top: 1px solid var(--line);
    }

    .main {
        min-width: 0;
        padding-bottom: 70px;
        color: var(--text);
    }

    /* Dashboard blocks fill the content area instead of centering themselves */
    .main > .dashboard-heading,
    .main > .dashboard-section,
    .main > .dashboard-lower-grid {
        width: 100%;
        margin-right: 0;
        margin-left: 0;
    }

    .main > .dashboard-heading { margin-top: 0; }


    /* ---------- RESPONSIVE ---------- */

    @media (max-width: 960px) {
        .admin {
            grid-template-columns: minmax(0, 1fr);
            gap: 16px;
            padding: 16px 16px 0;
        }

        .side {
            position: static;
            padding: 12px 8px 8px;
        }

        .side nav {
            flex-direction: row;
            gap: 4px;
            overflow-x: auto;
            overscroll-behavior-x: contain;
            scrollbar-width: none;
        }

        .side nav::-webkit-scrollbar { display: none; }

        .side nav a {
            flex-shrink: 0;
            white-space: nowrap;
        }

        .side nav a::before { display: none; }

        .side hr {
            flex-shrink: 0;
            width: 1px;
            height: auto;
            margin: 6px 6px;
            border-top: 0;
            border-left: 1px solid var(--line);
        }

        .admin-bar { padding: 10px 16px; }
    }

    @media (max-width: 560px) {
        .admin-bar { gap: 10px; min-height: 64px; }
        .admin-bar .logo { font-size: 24px; }
        .admin-bar .logo::before { width: 7px; height: 22px; margin-right: 7px; }

        .admin-bar .who { gap: 6px; }
        .admin-bar .who strong,
        .admin-bar .who a:first-child { display: none; }

        .admin-bar .who a { min-height: 36px; padding: 6px 10px; font-size: 12px; }
        .admin-bar .who .avatar { width: 36px; height: 36px; border-radius: 11px; }

        .role-chip { padding: 5px 9px; font-size: 9.5px; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: 0.01ms !important;
            transition-duration: 0.01ms !important;
        }
    }

    /* ---------- FORCE DARK CONTENT AREA ---------- */
    .main {
        background: var(--bg) !important;
        color: var(--text) !important;
    }

    .main .dashboard-heading h1,
    .main .section-heading h2,
    .main .panel-heading h2 {
        color: var(--stone) !important;
    }

    .main .dashboard-heading p,
    .main .section-heading p,
    .main .panel-heading p {
        color: var(--muted) !important;
    }

    .main .dashboard-heading .eyebrow {
        color: #d0d2b8 !important;
        background: rgba(133, 135, 109, 0.10) !important;
        border-color: rgba(133, 135, 109, 0.20) !important;
    }

    .main .dashboard-heading {
        border-bottom-color: var(--line) !important;
    }

    .main .stat-icon,
    .main .quick-action-icon {
        color: var(--olive-light) !important;
        background: rgba(133, 135, 109, 0.10) !important;
        border: 1px solid rgba(133, 135, 109, 0.18) !important;
    }

    .main .text-link {
        color: #f0eee6 !important;
        border-color: var(--line-light) !important;
    }
    /* ---------- OVERRIDES FOR CONFLICTING STYLES ---------- */

    /* Table header row */
    .main .dashboard-table thead,
    .main .dashboard-table thead tr,
    .main .dashboard-table th {
        background: transparent !important;
        color: var(--olive-light) !important;
        border: 0 !important;
        border-radius: 0 !important;
    }

    .main .dashboard-table td {
        background: transparent !important;
        color: var(--text) !important;
        border-top: 1px solid var(--line) !important;
    }

    .main .dashboard-table tbody tr {
        background: transparent !important;
    }

    /* Activity timeline lines and dots */
    .main .activity-item:not(:last-child)::after {
        background: var(--line-light) !important;
    }

    .main .activity-dot {
        border-color: var(--panel) !important;
    }

    /* Recently added content: single stacked list, not a card grid */
    .main .recent-content-grid {
        display: block !important;
        overflow: hidden;
        padding: 0 !important;
        background: var(--panel) !important;
        border: 1px solid var(--line) !important;
        border-radius: 14px !important;
        box-shadow: var(--shadow) !important;
    }

    .main .content-card {
        position: relative;
        display: grid !important;
        grid-template-columns: 180px minmax(0, 1fr) auto auto !important;
        align-items: center;
        gap: 18px;
        width: auto !important;
        height: auto !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 16px 20px !important;
        background: transparent !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        color: var(--text) !important;
        transform: none !important;
    }

    .main .content-card + .content-card {
        border-top: 1px solid var(--line) !important;
    }

    .main .content-card:hover {
        background: var(--panel-hover) !important;
    }

    .main .content-card h3 {
        margin: 0;
        color: var(--stone) !important;
        font-size: 22px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .main .content-card > p {
        margin: 0;
        color: #b8b8aa !important;
        font-size: 12px;
        white-space: nowrap;
    }

    .main .content-card .content-type {
        color: var(--olive-light) !important;
        background: rgba(133, 135, 109, 0.10) !important;
        border: 1px solid rgba(133, 135, 109, 0.19) !important;
    }

    .main .content-card .content-status {
        color: #b8b8aa !important;
        background: transparent !important;
    }

    .main .content-card > a {
        position: static;
        width: auto !important;
        height: auto !important;
        color: #f0eee6 !important;
        background: transparent !important;
        border: 1px solid var(--line-light) !important;
        border-radius: 8px !important;
        white-space: nowrap;
    }

    .main .content-card > a:hover {
        color: #ff956e !important;
        background: var(--orange-soft) !important;
    }

    @media (max-width: 900px) {
        .main .content-card {
            grid-template-columns: minmax(0, 1fr) auto !important;
            grid-template-areas:
                "top link"
                "title link"
                "date link" !important;
            gap: 6px 16px;
        }
        .main .content-card h3 { white-space: normal; }
    }

    @media (max-width: 560px) {
        .main .content-card {
            grid-template-columns: minmax(0, 1fr) !important;
            grid-template-areas:
                "top"
                "title"
                "date"
                "link" !important;
        }
        .main .content-card > a { justify-self: start; }
    }

    .admin-bar .who .avatar {
        overflow: hidden;
    }

    .admin-bar .who .avatar img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: inherit;
    }

    .admin-bar .who .avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        padding: 0;
        overflow: hidden;
        color: var(--stone);
        background: var(--olive);
        border: 2px solid rgba(243, 240, 232, 0.12);
        border-radius: 13px;
        font-size: 14px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .admin-bar .who .avatar img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: inherit;
    }
</style>
</head>
<body>
<header class="admin-bar">
    <a class="logo" href="admin-dashboard.php">Studentlink</a>
    <span class="role-chip">Admin</span>

    <div class="who">
    <a href="/Studenlink_Website/studentlink/dashboard.php"
       target="_blank"
       rel="noopener">
        View site
    </a>

    <a
        class="avatar"
        href="settings.php"
        aria-label="Your profile"
        title="<?= e($admin['name']) ?>"
    >
        <?php if (!empty($admin['photo_path'])): ?>
            <img
                src="<?= e('../' . ltrim($admin['photo_path'], '/')) ?>"
                alt="Admin profile photo"
            >
        <?php else: ?>
            <?= e(initials($admin['name'])) ?>
        <?php endif; ?>
    </a>

    <strong><?= e($admin['name']) ?></strong>

    <a href="../logout.php">Log out</a>
</div>
</header>

<div class="admin">
    <aside class="side">
        <nav aria-label="Admin">
            <?php foreach ($menu as $k => $label): ?>
                <a href="<?= e($k) ?>.php"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
            <hr>
            <a href="settings.php"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>>Settings</a>
        </nav>
    </aside>

    <main class="main">