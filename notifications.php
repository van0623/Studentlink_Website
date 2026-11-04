
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Check login */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Check that the user is active */
$userStmt = $conn->prepare(
    "SELECT id, full_name, role
     FROM users
     WHERE id = ? AND status = 'Active'
     LIMIT 1"
);

$userStmt->bind_param('i', $userId);
$userStmt->execute();

$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* Create CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Process notification actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    /* Mark all notifications as read */
    if ($action === 'read_all') {

        $stmt = $conn->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE user_id = ? AND is_read = 0"
        );

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        header('Location: notifications.php?message=read');
        exit;
    }

    /* Open one notification and mark it as read */
    if ($action === 'open') {

        $notificationId = filter_var(
            $_POST['notification_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$notificationId || $notificationId < 1) {
            header('Location: notifications.php');
            exit;
        }

        $stmt = $conn->prepare(
            "SELECT link
             FROM notifications
             WHERE id = ? AND user_id = ?
             LIMIT 1"
        );

        $stmt->bind_param('ii', $notificationId, $userId);
        $stmt->execute();

        $notification = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($notification) {

            $update = $conn->prepare(
                "UPDATE notifications
                 SET is_read = 1
                 WHERE id = ? AND user_id = ?"
            );

            $update->bind_param('ii', $notificationId, $userId);
            $update->execute();
            $update->close();

            /*
             * Only allow local relative links.
             */
            $link = trim($notification['link'] ?? '');

            if (
                $link !== '' &&
                !preg_match('/^(https?:)?\/\//i', $link) &&
                !str_starts_with($link, '/') &&
                !str_starts_with($link, '\\') &&
                !str_contains($link, "\0")
            ) {
                header('Location: ' . $link);
                exit;
            }
        }

        header('Location: notifications.php');
        exit;
    }
}

/* Fetch notifications with the actor's profile information */
$notifications = [];

$stmt = $conn->prepare(
    "SELECT
        n.id,
        n.user_id,
        n.actor_id,
        n.message,
        n.link,
        n.is_read,
        n.created_at,
        u.full_name AS actor_name,
        u.photo_path AS actor_photo
     FROM notifications n
     LEFT JOIN users u ON n.actor_id = u.id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC, n.id DESC"
);

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

$stmt->close();

/* Count unread notifications */
$unreadCount = 0;

foreach ($notifications as $notification) {
    if ((int) $notification['is_read'] === 0) {
        $unreadCount++;
    }
}

/* Page settings */
$title = 'Notifications';
$active = 'notifications';
$css = ['notifications'];

include __DIR__ . '/includes/header.php';
?>

<main class="wrap narrow">

    <header class="page-head head-row">

        <div>
            <h1>Notifications</h1>

            <p class="meta">
                <?php if ($unreadCount > 0): ?>
                    You have <?= $unreadCount ?>
                    unread notification<?= $unreadCount === 1 ? '' : 's' ?>.
                <?php else: ?>
                    You're all caught up.
                <?php endif; ?>
            </p>
        </div>

        <form method="post" action="notifications.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="read_all"
            >

            <button
                type="submit"
                class="btn btn-ghost"
                <?= $unreadCount === 0 ? 'disabled' : '' ?>
            >
                Mark all as read
            </button>

        </form>

    </header>

    <?php if (($_GET['message'] ?? '') === 'read'): ?>
        <p class="meta" role="status">
            All notifications have been marked as read.
        </p>
    <?php endif; ?>

    <ul class="notes">

        <?php if (empty($notifications)): ?>

            <li class="note">
                <div>
                    <p>You don't have any notifications yet.</p>

                    <small class="meta">
                        New notifications will appear here.
                    </small>
                </div>
            </li>

        <?php else: ?>

            <?php foreach ($notifications as $n): ?>

                <?php
                $unread = (int) $n['is_read'] === 0;

                $actorName = !empty($n['actor_name'])
                    ? $n['actor_name']
                    : 'StudentLink';

                $actorPhoto = trim($n['actor_photo'] ?? '');

                $avatarInitials = initials($actorName);

                $createdAt = strtotime($n['created_at']);
                ?>

                <li class="note<?= $unread ? ' unread' : '' ?>">

                    <div class="notification-avatar">

                        <?php if ($actorPhoto !== ''): ?>

                            <img
                                src="<?= e($actorPhoto) ?>"
                                alt="<?= e($actorName) ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >

                            <span class="avatar-fallback" style="display:none;">
                                <?= e($avatarInitials) ?>
                            </span>

                        <?php else: ?>

                            <span class="avatar-fallback">
                                <?= e($avatarInitials) ?>
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="notification-content">

                        <p class="notification-message">
                            <strong><?= e($actorName) ?></strong>

                            <?= nl2br(e($n['message'])) ?>
                        </p>

                        <time datetime="<?= e(date('c', $createdAt)) ?>">
                            <?= e(date('M j, Y \a\t g:i A', $createdAt)) ?>
                        </time>

                        <form method="post" action="notifications.php">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($_SESSION['csrf_token']) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="open"
                            >

                            <input
                                type="hidden"
                                name="notification_id"
                                value="<?= (int) $n['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="notification-link"
                            >
                                View details
                            </button>

                        </form>

                    </div>

                    <?php if ($unread): ?>
                        <span
                            class="notification-unread-dot"
                            aria-label="Unread notification"
                        ></span>
                    <?php endif; ?>

                </li>

            <?php endforeach; ?>

        <?php endif; ?>

    </ul>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
