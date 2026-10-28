
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/* Verify admin */
if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$profileStmt = $conn->prepare("
    SELECT id, full_name, email, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$profileStmt) {
    exit('Unable to load administrator information.');
}

$profileStmt->bind_param('i', $adminId);
$profileStmt->execute();

$adminProfile = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

if (
    !$adminProfile ||
    $adminProfile['status'] !== 'Active' ||
    strtolower($adminProfile['role']) !== 'admin'
) {
    session_unset();
    session_destroy();

    header('Location: ../login.php');
    exit;
}

$_SESSION['full_name'] = $adminProfile['full_name'];
$_SESSION['user'] = $adminProfile['full_name'];

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Notification preferences */
$options = [
    'reports' => 'New reports',
    'registrations' => 'New user registrations',
    'content' => 'Content reports',
    'transactions' => 'New transactions and requests',
    'new_content' => 'New content submissions'
];

$settings = [
    'notify_reports' => 1,
    'notify_registrations' => 1,
    'notify_content' => 1,
    'notify_transactions' => 1,
    'notify_new_content' => 1
];

$error = '';
$success = isset($_GET['saved']) && $_GET['saved'] === '1';

/* Load notification preferences */
$settingsStmt = $conn->prepare("
    SELECT
        notify_reports,
        notify_registrations,
        notify_content,
        notify_transactions,
        notify_new_content
    FROM admin_notification_settings
    WHERE admin_id = ?
    LIMIT 1
");

if (!$settingsStmt) {
    error_log('Settings query error: ' . $conn->error);
    $error = 'Unable to load notification settings.';
} else {
    $settingsStmt->bind_param('i', $adminId);
    $settingsStmt->execute();

    $savedSettings = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();

    if ($savedSettings) {
        $settings = array_merge($settings, $savedSettings);
    }
}

/* Save notification preferences */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $error = 'Your session token is invalid. Please refresh the page.';

    } else {

        $selectedNotifications = $_POST['notify'] ?? [];

        if (!is_array($selectedNotifications)) {
            $selectedNotifications = [];
        }

        $selectedNotifications = array_filter(
            $selectedNotifications,
            function ($value) use ($options) {
                return is_string($value) &&
                    array_key_exists($value, $options);
            }
        );

        $notifyReports = in_array('reports', $selectedNotifications, true) ? 1 : 0;
        $notifyRegistrations = in_array('registrations', $selectedNotifications, true) ? 1 : 0;
        $notifyContent = in_array('content', $selectedNotifications, true) ? 1 : 0;
        $notifyTransactions = in_array('transactions', $selectedNotifications, true) ? 1 : 0;
        $notifyNewContent = in_array('new_content', $selectedNotifications, true) ? 1 : 0;

        $saveStmt = $conn->prepare("
            INSERT INTO admin_notification_settings (
                admin_id,
                notify_reports,
                notify_registrations,
                notify_content,
                notify_transactions,
                notify_new_content
            )
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                notify_reports = VALUES(notify_reports),
                notify_registrations = VALUES(notify_registrations),
                notify_content = VALUES(notify_content),
                notify_transactions = VALUES(notify_transactions),
                notify_new_content = VALUES(notify_new_content)
        ");

        if (!$saveStmt) {
            error_log('Settings save error: ' . $conn->error);
            $error = 'Unable to save your settings. Please try again.';

        } else {

            $saveStmt->bind_param(
                'iiiiii',
                $adminId,
                $notifyReports,
                $notifyRegistrations,
                $notifyContent,
                $notifyTransactions,
                $notifyNewContent
            );

            if ($saveStmt->execute()) {
                $saveStmt->close();

                header('Location: settings.php?saved=1');
                exit;
            }

            error_log('Settings execution error: ' . $saveStmt->error);
            $saveStmt->close();

            $error = 'Unable to save your settings. Please try again.';
        }

        $settings = [
            'notify_reports' => $notifyReports,
            'notify_registrations' => $notifyRegistrations,
            'notify_content' => $notifyContent,
            'notify_transactions' => $notifyTransactions,
            'notify_new_content' => $notifyNewContent
        ];
    }
}

/* Dashboard statistics */
$pendingReports = 0;
$contentReports = 0;
$totalUsers = 0;

$reportCountQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM reports
    WHERE status = 'Pending'
      AND archived_at IS NULL
");

if ($reportCountQuery) {
    $reportCount = $reportCountQuery->fetch_assoc();
    $pendingReports = (int) $reportCount['total'];
} else {
    error_log('Pending reports count error: ' . $conn->error);
}

$contentCountQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM reports
    WHERE status = 'Pending'
      AND archived_at IS NULL
      AND target_type IN ('Resource', 'Group', 'Service', 'Item')
");

if ($contentCountQuery) {
    $contentCount = $contentCountQuery->fetch_assoc();
    $contentReports = (int) $contentCount['total'];
} else {
    error_log('Content reports count error: ' . $conn->error);
}

$userCountQuery = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
");

if ($userCountQuery) {
    $userCount = $userCountQuery->fetch_assoc();
    $totalUsers = (int) $userCount['total'];
} else {
    error_log('Registered users count error: ' . $conn->error);
}

require_once __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="settings.css">

<main class="admin-settings-page">

    <header class="page-head">
        <div>
            <span class="settings-eyebrow">ADMINISTRATION</span>
            <h1>Settings</h1>
            <p>Manage your administrator profile and notification preferences.</p>
        </div>
    </header>

    <?php if ($success): ?>
        <div class="settings-alert settings-alert-success" role="status">
            Your notification settings have been saved successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="settings-alert settings-alert-success" role="status">
            Your profile information has been updated successfully.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="settings-alert settings-alert-error" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <section class="settings-stats">

        <article class="settings-stat-card">
            <span class="settings-stat-label">Pending reports</span>
            <strong><?= number_format($pendingReports) ?></strong>
            <small>Reports awaiting review</small>
        </article>

        <article class="settings-stat-card">
            <span class="settings-stat-label">Content reports</span>
            <strong><?= number_format($contentReports) ?></strong>
            <small>Reports about shared content</small>
        </article>

        <article class="settings-stat-card">
            <span class="settings-stat-label">Registered users</span>
            <strong><?= number_format($totalUsers) ?></strong>
            <small>Total user accounts</small>
        </article>

    </section>

    <!-- Admin profile -->
    <section class="info settings-section">

        <div class="settings-section-heading">
            <div>
                <span class="settings-eyebrow">ACCOUNT INFORMATION</span>
                <h2>Admin profile</h2>
                <p>Your administrator account details.</p>
            </div>

            <div class="settings-profile-icon">
                <?= e(
                    mb_strtoupper(
                        mb_substr($adminProfile['full_name'] ?: 'A', 0, 1)
                    )
                ) ?>
            </div>
        </div>

        <dl class="kv">

            <dt>Name</dt>
            <dd><?= e($adminProfile['full_name']) ?></dd>

            <dt>Email</dt>
            <dd><?= e($adminProfile['email']) ?></dd>

            <dt>Role</dt>
            <dd>
                <span class="settings-role">
                    <?= e(ucfirst($adminProfile['role'])) ?>
                </span>
            </dd>

            <dt>Account status</dt>
            <dd>
                <span class="settings-status">
                    <?= e($adminProfile['status']) ?>
                </span>
            </dd>

        </dl>

        <div class="actions">
            <a class="btn btn-primary" href="settings-edit.php">
                Edit profile
            </a>

            <a class="btn btn-ghost" href="settings-edit.php#password">
                Change password
            </a>
        </div>

    </section>

    <!-- Notification preferences -->
    <form class="info settings-section" method="POST" action="">

        <div class="settings-section-heading">
            <div>
                <span class="settings-eyebrow">PREFERENCES</span>
                <h2>Notifications</h2>
                <p>Choose which types of activity you want to monitor.</p>
            </div>

            <div class="settings-notification-icon">
                <i class="fa-solid fa-bell"></i>
            </div>
        </div>

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <div class="checks">

            <?php foreach ($options as $key => $label): ?>

                <?php
                $column = [
                    'reports' => 'notify_reports',
                    'registrations' => 'notify_registrations',
                    'content' => 'notify_content',
                    'transactions' => 'notify_transactions',
                    'new_content' => 'notify_new_content'
                ][$key];
                ?>

                <label class="settings-check">

                    <input
                        type="checkbox"
                        name="notify[]"
                        value="<?= e($key) ?>"
                        <?= !empty($settings[$column]) ? 'checked' : '' ?>
                    >

                    <span class="settings-check-content">

                        <strong><?= e($label) ?></strong>

                        <?php if ($key === 'reports'): ?>
                            <small>
                                Monitor newly submitted reports.
                                <?= number_format($pendingReports) ?> currently pending.
                            </small>

                        <?php elseif ($key === 'registrations'): ?>
                            <small>
                                Monitor new user registrations.
                                <?= number_format($totalUsers) ?> accounts currently registered.
                            </small>

                        <?php elseif ($key === 'content'): ?>
                            <small>
                                Monitor reports about resources, groups, services, and items.
                                <?= number_format($contentReports) ?> currently pending.
                            </small>

                        <?php elseif ($key === 'transactions'): ?>
                            <small>
                                Monitor new item and service transactions or requests.
                            </small>

                        <?php elseif ($key === 'new_content'): ?>
                            <small>
                                Monitor newly submitted resources, groups, items, and services.
                            </small>

                        <?php endif; ?>

                    </span>

                </label>

            <?php endforeach; ?>

        </div>

        <div class="actions">
            <button type="submit" class="btn btn-primary">
                Save notification settings
            </button>
        </div>

    </form>

</main>

<?php
$conn->close();
require_once __DIR__ . '/../includes/admin-footer.php';
?>
