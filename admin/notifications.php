
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/* =========================================
   VERIFY ADMIN LOGIN
========================================= */

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$authStmt = $conn->prepare("
    SELECT id, full_name, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$authStmt) {
    exit('Unable to verify administrator account.');
}

$authStmt->bind_param('i', $adminId);
$authStmt->execute();

$adminAccount = $authStmt->get_result()->fetch_assoc();
$authStmt->close();

if (
    !$adminAccount ||
    strtolower((string) $adminAccount['role']) !== 'admin' ||
    strcasecmp((string) $adminAccount['status'], 'Active') !== 0
) {
    session_unset();
    session_destroy();

    header('Location: ../login.php');
    exit;
}

/* =========================================
   CSRF TOKEN
========================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================
   PAGE SETTINGS
========================================= */

$title = 'Notifications';
$active = 'notifications';

$error = '';
$success = '';

/* =========================================
   VALIDATE NOTIFICATION SECTION
========================================= */

function validNotificationSection($section)
{
    return in_array($section, ['students', 'admins'], true)
        ? $section
        : 'students';
}

$selectedSection = validNotificationSection(
    $_GET['section'] ?? 'students'
);

/* =========================================
   HANDLE STUDENT NOTIFICATION ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    $selectedSection = validNotificationSection(
        $_POST['section'] ?? 'students'
    );

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {

        $error = 'Invalid session token. Please refresh the page.';

    } elseif ($selectedSection === 'admins') {

        $error = 'Admin activity notifications are read-only.';

    } else {

        $action = $_POST['action'] ?? '';
        $notificationId = (int) ($_POST['notification_id'] ?? 0);

        $targetRole = 'student';

        /* MARK ONE AS READ */

        if ($action === 'mark_read' && $notificationId > 0) {

            $stmt = $conn->prepare("
                UPDATE notifications n
                INNER JOIN users u ON u.id = n.user_id
                SET n.is_read = 1
                WHERE n.id = ?
                  AND LOWER(u.role) = ?
            ");

            if ($stmt) {

                $stmt->bind_param('is', $notificationId, $targetRole);

                if ($stmt->execute()) {

                    $success = $stmt->affected_rows > 0
                        ? 'Student notification marked as read.'
                        : 'Notification was already read or could not be found.';

                } else {

                    $error = 'Unable to update notification.';
                }

                $stmt->close();

            } else {

                $error = 'Unable to prepare notification update.';
            }

        }

        /* MARK ALL AS READ */

        elseif ($action === 'mark_all_read') {

            $stmt = $conn->prepare("
                UPDATE notifications n
                INNER JOIN users u ON u.id = n.user_id
                SET n.is_read = 1
                WHERE LOWER(u.role) = ?
                  AND n.is_read = 0
            ");

            if ($stmt) {

                $stmt->bind_param('s', $targetRole);

                if ($stmt->execute()) {

                    $success = 'All unread student notifications have been marked as read.';

                } else {

                    $error = 'Unable to update student notifications.';
                }

                $stmt->close();

            } else {

                $error = 'Unable to prepare notification update.';
            }

        }

        /* DELETE ONE NOTIFICATION */

        elseif ($action === 'delete' && $notificationId > 0) {

            $stmt = $conn->prepare("
                DELETE n
                FROM notifications n
                INNER JOIN users u ON u.id = n.user_id
                WHERE n.id = ?
                  AND LOWER(u.role) = ?
            ");

            if ($stmt) {

                $stmt->bind_param('is', $notificationId, $targetRole);

                if ($stmt->execute()) {

                    $success = $stmt->affected_rows > 0
                        ? 'Student notification deleted successfully.'
                        : 'Notification could not be found.';

                } else {

                    $error = 'Unable to delete notification.';
                }

                $stmt->close();

            } else {

                $error = 'Unable to prepare notification deletion.';
            }

        }

        else {

            $error = 'Invalid notification action.';
        }
    }
}

/* =========================================
   STUDENT NOTIFICATION STATISTICS
========================================= */

function getStudentNotificationStats(mysqli $conn)
{
    $stats = [
        'total' => 0,
        'unread' => 0,
        'read' => 0
    ];

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(n.is_read = 0), 0) AS unread,
            COALESCE(SUM(n.is_read = 1), 0) AS read_count
        FROM notifications n
        INNER JOIN users u ON u.id = n.user_id
        WHERE LOWER(u.role) = 'student'
    ");

    if (!$stmt) {
        error_log('Student notification statistics error: ' . $conn->error);
        return $stats;
    }

    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();

    if ($result) {
        $stats['total'] = (int) $result['total'];
        $stats['unread'] = (int) $result['unread'];
        $stats['read'] = (int) $result['read_count'];
    }

    $stmt->close();

    return $stats;
}

/* =========================================
   ADMIN ACTIVITY STATISTICS
   COUNT GROUPED ACTIVITIES, NOT RECIPIENT ROWS
========================================= */

function getAdminActivityStats(mysqli $conn)
{
    $stats = [
        'total' => 0,
        'unread' => 0,
        'read' => 0
    ];

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(activity.is_read = 0), 0) AS unread,
            COALESCE(SUM(activity.is_read = 1), 0) AS read_count
        FROM (
            SELECT
                n.actor_id,
                n.message,
                n.link,
                DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i:%s') AS activity_time,
                MAX(n.is_read) AS is_read
            FROM notifications n
            INNER JOIN users u ON u.id = n.user_id
            WHERE LOWER(u.role) = 'admin'
            GROUP BY
                n.actor_id,
                n.message,
                n.link,
                DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i:%s')
        ) AS activity
    ");

    if (!$stmt) {
        error_log('Admin activity statistics error: ' . $conn->error);
        return $stats;
    }

    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();

    if ($result) {
        $stats['total'] = (int) $result['total'];
        $stats['unread'] = (int) $result['unread'];
        $stats['read'] = (int) $result['read_count'];
    }

    $stmt->close();

    return $stats;
}

/* =========================================
   LOAD STUDENT NOTIFICATIONS
   KEEP INDIVIDUAL NOTIFICATION RECORDS
========================================= */

function getStudentNotifications(mysqli $conn)
{
    $notifications = [];

    $stmt = $conn->prepare("
        SELECT
            n.id,
            n.user_id,
            n.actor_id,
            n.message,
            n.link,
            n.is_read,
            n.created_at,
            u.full_name AS recipient_name,
            actor.full_name AS actor_name
        FROM notifications n
        INNER JOIN users u ON u.id = n.user_id
        LEFT JOIN users actor ON actor.id = n.actor_id
        WHERE LOWER(u.role) = 'student'
        ORDER BY n.created_at DESC, n.id DESC
    ");

    if (!$stmt) {
        error_log('Student notification loading error: ' . $conn->error);
        return $notifications;
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }

    $stmt->close();

    return $notifications;
}

/* =========================================
   LOAD GROUPED ADMIN ACTIVITIES
========================================= */

function getAdminActivities(mysqli $conn)
{
    $activities = [];

    $stmt = $conn->prepare("
        SELECT
            MIN(n.id) AS id,
            n.actor_id,
            n.message,
            n.link,
            DATE_FORMAT(
                n.created_at,
                '%Y-%m-%d %H:%i:%s'
            ) AS created_at,

            MAX(n.is_read) AS is_read,

            COUNT(*) AS recipient_count,

            GROUP_CONCAT(
                DISTINCT COALESCE(
                    NULLIF(u.full_name, ''),
                    'Unknown administrator'
                )
                ORDER BY u.full_name
                SEPARATOR ', '
            ) AS recipient_names,

            actor.full_name AS actor_name

        FROM notifications n

        INNER JOIN users u
            ON u.id = n.user_id

        LEFT JOIN users actor
            ON actor.id = n.actor_id

        WHERE LOWER(u.role) = 'admin'

        GROUP BY
            n.actor_id,
            n.message,
            n.link,
            DATE_FORMAT(
                n.created_at,
                '%Y-%m-%d %H:%i:%s'
            ),
            actor.full_name

        ORDER BY
            MAX(n.created_at) DESC,
            MIN(n.id) DESC
    ");

    if (!$stmt) {
        error_log('Admin activity loading error: ' . $conn->error);
        return $activities;
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }

    $stmt->close();

    return $activities;
}

/* =========================================
   LOAD NOTIFICATION DATA
========================================= */

$studentStats = getStudentNotificationStats($conn);
$adminStats = getAdminActivityStats($conn);

$studentNotifications = getStudentNotifications($conn);
$adminNotifications = getAdminActivities($conn);

/* =========================================
   FORMAT NOTIFICATION DATE
========================================= */

function notificationDate($date)
{
    if (empty($date)) {
        return 'Date unavailable';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return 'Date unavailable';
    }

    return date('M d, Y \a\t h:i A', $timestamp);
}

/* =========================================
   VALIDATE NOTIFICATION LINK
========================================= */

function notificationLink($link)
{
    if (empty($link)) {
        return '';
    }

    $link = trim((string) $link);

    if (
        preg_match('/^[a-z][a-z0-9+.-]*:/i', $link) ||
        str_starts_with($link, '//') ||
        str_contains($link, '\\') ||
        str_contains($link, "\0")
    ) {
        return '';
    }

    return $link;
}

/* =========================================
   DISPLAY NOTIFICATION LIST
========================================= */

function renderNotificationList(
    array $notifications,
    string $section,
    string $csrfToken
) {
    ?>

    <?php if (empty($notifications)): ?>

        <div class="notification-empty">

            <div class="notification-empty-icon">
                <i class="fa-regular fa-bell-slash"></i>
            </div>

            <h3>No notifications yet</h3>

            <p>Notifications for this section will appear here.</p>

        </div>

    <?php else: ?>

        <div class="notification-list">

            <?php foreach ($notifications as $notification): ?>

                <?php
                $isRead = (int) $notification['is_read'] === 1;

                $safeLink = notificationLink(
                    $notification['link'] ?? ''
                );
                ?>

                <article class="notification-item <?= $isRead ? 'is-read' : 'is-unread' ?>">

                    <div class="notification-item-icon">

                        <i class="<?= $isRead
                            ? 'fa-regular fa-envelope-open'
                            : 'fa-solid fa-bell' ?>">
                        </i>

                    </div>

                    <div class="notification-item-content">

                        <div class="notification-item-top">

                            <span class="notification-status <?= $isRead ? 'read' : 'unread' ?>">
                                <?= $isRead ? 'Read' : 'Unread' ?>
                            </span>

                            <time>
                                <?= e(notificationDate($notification['created_at'])) ?>
                            </time>

                        </div>

                        <p class="notification-message">
                            <?= e($notification['message']) ?>
                        </p>

                        <?php if ($section === 'students'): ?>

                            <div class="notification-recipient">

                                <i class="fa-solid fa-user"></i>

                                <span>
                                    Student:
                                    <?= e($notification['recipient_name'] ?? 'Unknown account') ?>
                                </span>

                            </div>

                            <?php if (!empty($notification['actor_name'])): ?>

                                <div class="notification-actor">

                                    <i class="fa-solid fa-user-pen"></i>

                                    <span>
                                        Performed by:
                                        <?= e($notification['actor_name']) ?>
                                    </span>

                                </div>

                            <?php endif; ?>

                        <?php else: ?>

                            <div class="notification-recipient">

                                <i class="fa-solid fa-users"></i>

                                <span>
                                    Received by:
                                    <?= e($notification['recipient_names'] ?? 'Unknown administrators') ?>
                                </span>

                            </div>

                            <?php if (!empty($notification['recipient_count'])): ?>

                                <div class="notification-actor">

                                    <i class="fa-solid fa-user-group"></i>

                                    <span>
                                        Delivered to
                                        <?= (int) $notification['recipient_count'] ?>
                                        administrator account(s)
                                    </span>

                                </div>

                            <?php endif; ?>

                        <?php endif; ?>

                        <div class="notification-item-actions">

                            <?php if ($section === 'students'): ?>

                                <?php if ($safeLink !== ''): ?>

                                    <a
                                        class="notification-open-link"
                                        href="<?= e($safeLink) ?>"
                                    >
                                        View details
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>

                                <?php endif; ?>

                                <?php if (!$isRead): ?>

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="mark_read"
                                        >

                                        <input
                                            type="hidden"
                                            name="section"
                                            value="students"
                                        >

                                        <input
                                            type="hidden"
                                            name="notification_id"
                                            value="<?= (int) $notification['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="notification-text-button"
                                        >
                                            Mark as read
                                        </button>

                                    </form>

                                <?php endif; ?>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Are you sure you want to delete this student notification?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="section"
                                        value="students"
                                    >

                                    <input
                                        type="hidden"
                                        name="notification_id"
                                        value="<?= (int) $notification['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="notification-delete-button"
                                    >
                                        <i class="fa-regular fa-trash-can"></i>
                                        Delete
                                    </button>

                                </form>

                            <?php else: ?>

                                <div class="notification-readonly-label">

                                    <i class="fa-solid fa-lock"></i>

                                    <span>
                                        Read-only activity record
                                    </span>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <?php
}

/* =========================================
   HEADER
========================================= */

require_once __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="notifications.css">

<main class="admin-notifications-page">

    <!-- PAGE HEADING -->

    <header class="notification-page-heading">

        <div>

            <span class="notification-eyebrow">
                ADMINISTRATION
            </span>

            <h1>Notifications</h1>

            <p>
                Manage student notifications and monitor administrator activities.
            </p>

        </div>

    </header>

    <!-- ALERTS -->

    <?php if ($success !== ''): ?>

        <div class="notification-alert success" role="status">

            <i class="fa-solid fa-circle-check"></i>

            <?= e($success) ?>

        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="notification-alert error" role="alert">

            <i class="fa-solid fa-circle-exclamation"></i>

            <?= e($error) ?>

        </div>

    <?php endif; ?>

    <!-- OVERALL STATISTICS -->

    <section class="notification-stats">

        <article class="notification-stat-card">

            <div class="notification-stat-icon">
                <i class="fa-solid fa-users"></i>
            </div>

            <div>
                <span>Student notifications</span>
                <strong>
                    <?= number_format($studentStats['total']) ?>
                </strong>
            </div>

        </article>

        <article class="notification-stat-card">

            <div class="notification-stat-icon unread-icon">
                <i class="fa-solid fa-envelope"></i>
            </div>

            <div>
                <span>Unread student notifications</span>
                <strong>
                    <?= number_format($studentStats['unread']) ?>
                </strong>
            </div>

        </article>

        <article class="notification-stat-card">

            <div class="notification-stat-icon read-icon">
                <i class="fa-solid fa-shield-halved"></i>
            </div>

            <div>
                <span>Admin activities</span>
                <strong>
                    <?= number_format($adminStats['total']) ?>
                </strong>
            </div>

        </article>

    </section>

    <!-- SECTION SELECTOR -->

    <section class="notification-selector">

        <div class="notification-selector-heading">

            <span class="notification-eyebrow">
                NOTIFICATION CENTER
            </span>

            <h2>Notification Management</h2>

            <p>
                Select which notifications you want to view.
            </p>

        </div>

        <form method="GET" class="notification-dropdown-form">

            <label for="notification-section">
                Select notification section
            </label>

            <select
                name="section"
                id="notification-section"
                onchange="this.form.submit()"
            >

                <option
                    value="students"
                    <?= $selectedSection === 'students' ? 'selected' : '' ?>
                >
                    Student Notifications
                </option>

                <option
                    value="admins"
                    <?= $selectedSection === 'admins' ? 'selected' : '' ?>
                >
                    Admin Activities
                </option>

            </select>

        </form>

    </section>

    <!-- =====================================
         STUDENT NOTIFICATIONS
    ====================================== -->

    <?php if ($selectedSection === 'students'): ?>

        <section class="notification-panel">

            <div class="notification-panel-heading">

                <div>

                    <h2>
                        <i class="fa-solid fa-users"></i>
                        Student Notifications
                    </h2>

                    <p>
                        View and manage notifications sent to all student accounts.
                    </p>

                </div>

                <span class="notification-total">
                    <?= number_format($studentStats['total']) ?> total
                </span>

            </div>

            <div class="notification-section-summary">

                <div>
                    <span>Total</span>
                    <strong>
                        <?= number_format($studentStats['total']) ?>
                    </strong>
                </div>

                <div>
                    <span>Unread</span>
                    <strong>
                        <?= number_format($studentStats['unread']) ?>
                    </strong>
                </div>

                <div>
                    <span>Read</span>
                    <strong>
                        <?= number_format($studentStats['read']) ?>
                    </strong>
                </div>

            </div>

            <?php if ($studentStats['unread'] > 0): ?>

                <form method="POST" class="mark-all-form">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="mark_all_read"
                    >

                    <input
                        type="hidden"
                        name="section"
                        value="students"
                    >

                    <button
                        type="submit"
                        class="notification-button"
                    >
                        <i class="fa-solid fa-check-double"></i>
                        Mark all as read
                    </button>

                </form>

            <?php endif; ?>

            <?php
            renderNotificationList(
                $studentNotifications,
                'students',
                $csrfToken
            );
            ?>

        </section>

    <?php else: ?>

        <!-- =====================================
             ADMIN ACTIVITIES
        ====================================== -->

        <section class="notification-panel">

            <div class="notification-panel-heading">

                <div>

                    <h2>
                        <i class="fa-solid fa-shield-halved"></i>
                        Admin Activities
                    </h2>

                    <p>
                        View grouped notifications delivered to administrator accounts.
                        These records are read-only.
                    </p>

                </div>

                <span class="notification-total">
                    <?= number_format($adminStats['total']) ?> activities
                </span>

            </div>

            <div class="notification-section-summary">

                <div>
                    <span>Total activities</span>
                    <strong>
                        <?= number_format($adminStats['total']) ?>
                    </strong>
                </div>

                <div>
                    <span>Unread</span>
                    <strong>
                        <?= number_format($adminStats['unread']) ?>
                    </strong>
                </div>

                <div>
                    <span>Read</span>
                    <strong>
                        <?= number_format($adminStats['read']) ?>
                    </strong>
                </div>

            </div>

            <?php
            renderNotificationList(
                $adminNotifications,
                'admins',
                $csrfToken
            );
            ?>

        </section>

    <?php endif; ?>

</main>

<?php
require_once __DIR__ . '/../includes/admin-footer.php';
?>
