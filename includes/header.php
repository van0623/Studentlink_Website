
<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../db_connect.php';

$title = $title ?? 'StudentLink';
$css = $css ?? [];
$active = $active ?? '';
$bare = $bare ?? false;

$headerName = $_SESSION['full_name']
    ?? $_SESSION['user']
    ?? 'Student';

$headerPhoto = '';
$unreadNotificationCount = 0;

/* GET USER INFORMATION AND UNREAD NOTIFICATION COUNT */
if (!empty($_SESSION['user_id'])) {

    $headerUserId = (int) $_SESSION['user_id'];

    // Get profile information
    $photoStmt = $conn->prepare("
        SELECT full_name, photo_path
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if ($photoStmt) {
        $photoStmt->bind_param('i', $headerUserId);
        $photoStmt->execute();

        $photoResult = $photoStmt->get_result();
        $headerUser = $photoResult->fetch_assoc();

        if ($headerUser) {
            $headerName = $headerUser['full_name'] ?: $headerName;

            if (!empty($headerUser['photo_path'])) {
                $headerPhoto = $headerUser['photo_path'];
            }
        }

        $photoStmt->close();
    }

    // Count unread notifications
    $notificationStmt = $conn->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ?
        AND is_read = 0
    ");

    if ($notificationStmt) {
        $notificationStmt->bind_param('i', $headerUserId);
        $notificationStmt->execute();

        $notificationResult = $notificationStmt->get_result();
        $notificationRow = $notificationResult->fetch_assoc();

        $unreadNotificationCount = (int) (
            $notificationRow['unread_count'] ?? 0
        );

        $notificationStmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#161714">

    <title><?= e($title) ?> | StudentLink</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700;800;900&family=Source+Sans+3:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <?php foreach ($css as $stylesheet): ?>
        <link
            rel="stylesheet"
            href="css/<?= e($stylesheet) ?>.css"
        >
    <?php endforeach; ?>

    <style>

        :root {
            --nav-bg: #161714;
            --nav-panel: #1e1f1a;
            --nav-hover: #25261f;
            --nav-line: #302f27;
            --nav-line-light: #403f35;

            --nav-stone: #f3f0e8;
            --nav-muted: #a3a394;

            --nav-orange: #d94f16;
            --nav-orange-light: #ff7540;
            --nav-orange-soft: rgba(217, 79, 22, 0.12);

            --nav-olive: #85876d;

            --nav-head: 'Big Shoulders Display',
                'Arial Narrow', Impact, sans-serif;

            --nav-body: 'Source Sans 3',
                system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        .nav,
        .nav * {
            box-sizing: border-box;
        }

        .nav {
            position: sticky;
            top: 0;
            z-index: 1000;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;

            width: 100%;
            min-height: 78px;
            margin: 0;
            padding: 14px max(24px, calc((100% - 1440px) / 2));

            color: var(--nav-stone);
            background: rgba(22, 23, 20, 0.96);
            border: 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);

            font-family: var(--nav-body);

            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .nav a {
            text-decoration: none;
            transition:
                color 0.2s ease,
                background 0.2s ease,
                border-color 0.2s ease;
        }

        /* LOGO */

        .nav .logo {
            display: inline-flex;
            align-items: center;
            flex-shrink: 0;

            color: var(--nav-stone);
            font-family: var(--nav-head);
            font-size: 31px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .nav .logo::before {
            width: 9px;
            height: 27px;
            margin-right: 10px;

            background: var(--nav-orange);
            border-radius: 3px;

            content: "";
            transform: skew(-12deg);
        }

        .nav .logo:hover {
            color: var(--nav-orange-light);
        }

        /* MAIN NAVIGATION LINKS */

        .nav .nav-links {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            gap: 5px;

            min-width: 0;
            margin: 0;
            padding: 0;

            list-style: none;
        }

        .nav .nav-links > a,
        .nav .activity-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;

            min-height: 42px;
            padding: 9px 13px;

            color: var(--nav-muted);
            background: transparent;
            border: 1px solid transparent;
            border-radius: 10px;

            font-family: var(--nav-body);
            font-size: 14px;
            font-weight: 700;
            line-height: 1.3;
            white-space: nowrap;
            cursor: pointer;
        }

        .nav .nav-links > a:hover,
        .nav .activity-trigger:hover {
            color: var(--nav-stone);
            background: var(--nav-panel);
        }

        .nav .nav-links > a[aria-current="page"],
        .nav .activity-trigger[aria-expanded="true"],
        .nav .activity-trigger.menu-active {
            color: var(--nav-orange-light);
            background: var(--nav-orange-soft);
            border-color: rgba(217, 79, 22, 0.25);
        }

        /* MY ACTIVITY DROPDOWN */

        .nav .activity-menu {
            position: relative;
            flex-shrink: 0;
        }

        .nav .activity-trigger {
            appearance: none;
            -webkit-appearance: none;
        }

        .nav .activity-trigger .activity-chevron {
            display: inline-block;
            width: 7px;
            height: 7px;

            border-right: 1.5px solid currentColor;
            border-bottom: 1.5px solid currentColor;

            transform: rotate(45deg) translateY(-2px);
            transition: transform 0.2s ease;
        }

        .nav .activity-trigger[aria-expanded="true"] .activity-chevron {
            transform: rotate(225deg) translate(-1px, -1px);
        }

        .nav .activity-dropdown {
            position: absolute;
            top: calc(100% + 13px);
            left: 0;
            z-index: 1100;

            width: 260px;
            max-width: calc(100vw - 24px);
            padding: 7px;

            background: #1e1f1a;
            border: 1px solid var(--nav-line-light);
            border-radius: 15px;

            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.38);

            opacity: 0;
            visibility: hidden;
            pointer-events: none;

            transform: translateY(-6px);
            transition:
                opacity 0.18s ease,
                transform 0.18s ease,
                visibility 0.18s ease;
        }

        .nav .activity-menu.is-open .activity-dropdown {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: translateY(0);
        }

        .nav .dropdown-heading {
            margin: 0;
            padding: 10px 12px 9px;

            color: #7d7e72;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.6px;
            text-transform: uppercase;
        }

        .nav .activity-dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;

            min-width: 0;
            padding: 12px;

            color: var(--nav-muted);
            background: transparent;
            border: 1px solid transparent;
            border-radius: 10px;
        }

        .nav .activity-dropdown a:hover {
            color: var(--nav-stone);
            background: var(--nav-hover);
        }

        .nav .activity-dropdown a[aria-current="page"] {
            color: var(--nav-orange-light);
            background: var(--nav-orange-soft);
            border-color: rgba(217, 79, 22, 0.2);
        }

        .nav .dropdown-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;

            width: 38px;
            height: 38px;

            color: var(--nav-orange-light);
            background: var(--nav-orange-soft);
            border: 1px solid rgba(217, 79, 22, 0.14);
            border-radius: 11px;

            font-size: 17px;
        }

        .nav .dropdown-copy {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }

        .nav .dropdown-title {
            color: inherit;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.3;
        }

        .nav .dropdown-description {
            color: var(--nav-muted);
            font-size: 11px;
            font-weight: 400;
            line-height: 1.4;
        }

        .nav .dropdown-divider {
            height: 1px;
            margin: 5px 8px;
            background: var(--nav-line);
            border: 0;
        }

        /* RIGHT-SIDE TOOLS */

        .nav .nav-tools {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;

            flex-shrink: 0;
            margin: 0;
            padding: 0;
            border: 0;
        }

        .nav .icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;

            width: 41px;
            height: 41px;
            padding: 0;

            color: var(--nav-stone);
            background: var(--nav-panel);
            border: 1px solid var(--nav-line);
            border-radius: 12px;

            font-family: var(--nav-body);
            font-size: 17px;
            line-height: 1;
        }

        /* NOTIFICATION COUNT BADGE */

        .nav .notification-link {
            position: relative;
            overflow: visible;
        }

        .nav .notification-badge {
            position: absolute;
            top: -7px;
            right: -7px;
            z-index: 5;

            display: flex;
            align-items: center;
            justify-content: center;

            min-width: 19px;
            height: 19px;
            padding: 0 5px;

            color: #ffffff;
            background: #d94f16;
            border: 2px solid #161714;
            border-radius: 20px;

            font-family: var(--nav-body);
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
            white-space: nowrap;

            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
        }

        .nav .icon-btn:hover,
        .nav .icon-btn[aria-current="page"] {
            color: var(--nav-orange-light);
            background: var(--nav-orange-soft);
            border-color: rgba(217, 79, 22, 0.4);
        }

        /* PROFILE AVATAR */

        .nav .avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;

            width: 41px;
            height: 41px;

            color: var(--nav-stone);
            background: var(--nav-olive);
            border: 2px solid rgba(243, 240, 232, 0.12);
            border-radius: 13px;

            font-size: 14px;
            font-weight: 800;
            text-transform: uppercase;
            overflow: hidden;
            padding: 0;
        }

        .nav .avatar:hover {
            color: #ffffff;
            background: var(--nav-orange);
        }

        .nav .avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
        }

        /* LOGOUT */

        .nav .logout {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;

            min-height: 41px;
            padding: 8px 13px;

            color: var(--nav-muted);
            background: transparent;
            border: 1px solid var(--nav-line);
            border-radius: 10px;

            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .nav .logout:hover {
            color: var(--nav-stone);
            background: var(--nav-panel);
            border-color: var(--nav-line-light);
        }

        /* KEYBOARD FOCUS */

        .nav a:focus-visible,
        .nav button:focus-visible {
            outline: 2px solid var(--nav-orange-light);
            outline-offset: 3px;
        }

        /* TABLET NAVIGATION */

        @media (max-width: 1150px) {
            .nav {
                flex-wrap: wrap;
                gap: 12px 18px;
                padding: 14px 24px;
            }

            .nav .logo {
                margin-right: auto;
            }

            .nav .nav-tools {
                margin-left: auto;
            }

            .nav .nav-links {
                order: 3;
                justify-content: flex-start;

                flex: 0 0 100%;
                width: 100%;
                margin: 0;
                padding-top: 8px;

                overflow-x: auto;
                overflow-y: visible;
                overscroll-behavior-x: contain;
                scrollbar-width: thin;
                scrollbar-color: var(--nav-line-light) transparent;
            }

            .nav .nav-links > a,
            .nav .activity-menu {
                flex-shrink: 0;
            }

            .nav .activity-dropdown {
                top: calc(100% + 8px);
            }
        }

        /* MOBILE NAVIGATION */

        @media (max-width: 560px) {
            .nav {
                gap: 10px;
                padding: 12px;
            }

            .nav .logo {
                font-size: 24px;
            }

            .nav .logo::before {
                width: 7px;
                height: 23px;
                margin-right: 7px;
            }

            .nav .nav-tools {
                gap: 5px;
            }

            .nav .icon-btn,
            .nav .avatar {
                width: 34px;
                height: 34px;
                border-radius: 10px;
                font-size: 14px;
            }

            .nav .logout {
                min-height: 34px;
                padding: 5px 8px;
                font-size: 11px;
            }

            .nav .nav-links {
                gap: 3px;
                padding-top: 5px;
            }

            .nav .nav-links > a,
            .nav .activity-trigger {
                min-height: 35px;
                padding: 7px 10px;
                border-radius: 8px;
                font-size: 12px;
            }

            .nav .activity-trigger {
                gap: 6px;
            }

            .nav .activity-trigger .activity-chevron {
                width: 6px;
                height: 6px;
            }

            .nav .activity-dropdown {
                position: fixed;
                top: auto;
                right: 12px;
                left: 12px;

                width: auto;
                max-width: none;
                margin-top: 8px;
            }
        }

        /* REDUCED MOTION */

        @media (prefers-reduced-motion: reduce) {
            .nav *,
            .nav *::before,
            .nav *::after {
                transition-duration: 0.01ms !important;
            }
        }

    </style>
</head>

<body>

<?php if (!$bare): ?>

<nav class="nav" aria-label="Main navigation">

    <a class="logo" href="dashboard.php">StudentLink</a>

    <div class="nav-links">

        <?php
        $navigation = [
            'dashboard' => 'Dashboard',
            'resources' => 'Resources',
            'groups'    => 'Groups',
            'items'     => 'Items',
            'services'  => 'Services'
        ];

        foreach ($navigation as $key => $label):
        ?>
            <a
                href="<?= e($key) ?>.php"
                <?= $active === $key ? 'aria-current="page"' : '' ?>
            >
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>

        <!-- ACTIVITY AND TRANSACTIONS DROPDOWN -->

        <div class="activity-menu" id="activity-menu">

            <button
                type="button"
                class="activity-trigger <?= in_array($active, ['activity', 'transactions'], true) ? 'menu-active' : '' ?>"
                id="activity-menu-button"
                aria-expanded="false"
                aria-controls="activity-dropdown"
            >
                My Activity
                <span class="activity-chevron" aria-hidden="true"></span>
            </button>

            <div
                class="activity-dropdown"
                id="activity-dropdown"
                aria-label="Activity navigation"
                inert
            >
                <p class="dropdown-heading">Your activity</p>

                <a
                    href="activity.php"
                    <?= $active === 'activity' ? 'aria-current="page"' : '' ?>
                >
                    <span class="dropdown-icon" aria-hidden="true">&#128221;</span>

                    <span class="dropdown-copy">
                        <span class="dropdown-title">My Activity</span>
                        <span class="dropdown-description">
                            View your activity history
                        </span>
                    </span>
                </a>

                <hr class="dropdown-divider">

                <a
                    href="transactions.php"
                    <?= $active === 'transactions' ? 'aria-current="page"' : '' ?>
                >
                    <span class="dropdown-icon" aria-hidden="true">&#8644;</span>

                    <span class="dropdown-copy">
                        <span class="dropdown-title">My Transactions</span>
                        <span class="dropdown-description">
                            Track requests and their status
                        </span>
                    </span>
                </a>
            </div>

        </div>

    </div>

    <div class="nav-tools">

        <!-- NOTIFICATIONS -->

        <a
            class="icon-btn notification-link"
            href="notifications.php"
            aria-label="<?= $unreadNotificationCount > 0
                ? 'Notifications, ' . $unreadNotificationCount . ' unread'
                : 'Notifications' ?>"
            title="Notifications"
            <?= $active === 'notifications' ? 'aria-current="page"' : '' ?>
        >
            &#128276;

            <?php if ($unreadNotificationCount > 0): ?>
                <span class="notification-badge">
                    <?= $unreadNotificationCount > 99
                        ? '99+'
                        : $unreadNotificationCount ?>
                </span>
            <?php endif; ?>
        </a>

        <!-- MESSAGES -->

        <a
            class="icon-btn"
            href="messages.php"
            aria-label="Messages"
            title="Messages"
            <?= $active === 'messages' ? 'aria-current="page"' : '' ?>
        >
            &#128172;
        </a>

        <!-- PROFILE -->

        <a
            class="avatar"
            href="profile.php"
            aria-label="Your profile"
            title="<?= e($headerName) ?>"
        >
            <?php if ($headerPhoto !== ''): ?>
                <img
                    src="<?= e($headerPhoto) ?>"
                    alt="Your profile photo"
                >
            <?php else: ?>
                <?= e(initials($headerName)) ?>
            <?php endif; ?>
        </a>

        <!-- LOGOUT -->

        <a class="logout" href="logout.php">Log out</a>

    </div>

</nav>

<script>
(function () {
    'use strict';

    const menu = document.getElementById('activity-menu');
    const button = document.getElementById('activity-menu-button');
    const dropdown = document.getElementById('activity-dropdown');

    if (!menu || !button || !dropdown) {
        return;
    }

    function openMenu() {
        menu.classList.add('is-open');
        button.setAttribute('aria-expanded', 'true');
        dropdown.inert = false;
    }

    function closeMenu(returnFocus = false) {
        menu.classList.remove('is-open');
        button.setAttribute('aria-expanded', 'false');
        dropdown.inert = true;

        if (returnFocus) {
            button.focus();
        }
    }

    button.addEventListener('click', function () {
        if (menu.classList.contains('is-open')) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menu.classList.contains('is-open')) {
            closeMenu(true);
        }
    });

    dropdown.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            closeMenu();
        }
    });
})();
</script>

<?php endif; ?>
</body>
</html>
