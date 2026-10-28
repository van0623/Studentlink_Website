
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
    "SELECT id, full_name
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

/* Mark all notifications as read */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');
    }

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

/* Fetch notifications for the logged-in user */
$notifications = [];

$stmt = $conn->prepare(
    "SELECT id, user_id, message, link, is_read, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC"
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
                <?php $unread = (int) $n['is_read'] === 0; ?>

                <li class="note<?= $unread ? ' unread' : '' ?>">
                    <span class="dot" aria-hidden="true"></span>

                    <div>
                        <p><?= nl2br(e($n['message'])) ?></p>

                        <time datetime="<?= e(date('c', strtotime($n['created_at']))) ?>">
                            <?= e(date('M j, Y \a\t g:i A', strtotime($n['created_at']))) ?>
                        </time>

                        <?php if (!empty($n['link'])): ?>
                            <p>
                                <a href="<?= e($n['link']) ?>">
                                    View details
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($unread): ?>
                        <span class="sr-only">Unread</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
