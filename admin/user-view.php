
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

$adminStmt = $conn->prepare("
    SELECT id, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$adminStmt) {
    error_log('Admin verification error: ' . $conn->error);
    http_response_code(500);
    exit('Unable to verify administrator account.');
}

$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();

$currentAdmin = $adminStmt->get_result()->fetch_assoc();

$adminStmt->close();

if (
    !$currentAdmin ||
    strtolower($currentAdmin['role']) !== 'admin' ||
    strtolower($currentAdmin['status']) !== 'active'
) {
    session_unset();
    session_destroy();

    header('Location: ../login.php');
    exit;
}

/* =========================================
   GET SELECTED STUDENT ID
========================================= */

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$userId || $userId <= 0) {
    header('Location: users.php');
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
   HANDLE ACCOUNT STATUS CHANGES
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'suspend') {

        $updateStmt = $conn->prepare("
            UPDATE users
            SET status = 'Suspended'
            WHERE id = ?
              AND LOWER(role) = 'student'
              AND LOWER(status) = 'active'
        ");

    } elseif ($action === 'activate') {

        $updateStmt = $conn->prepare("
            UPDATE users
            SET status = 'Active'
            WHERE id = ?
              AND LOWER(role) = 'student'
              AND LOWER(status) = 'suspended'
        ");

    } else {
        header('Location: user-view.php?id=' . $userId);
        exit;
    }

    if (!$updateStmt) {
        error_log('Account status update preparation error: ' . $conn->error);
        http_response_code(500);
        exit('Unable to process this request right now.');
    }

    $updateStmt->bind_param('i', $userId);

    if (!$updateStmt->execute()) {
        error_log('Account status update error: ' . $updateStmt->error);
        $updateStmt->close();

        http_response_code(500);
        exit('Unable to update this account right now.');
    }

    $updateStmt->close();

    header('Location: user-view.php?id=' . $userId . '&updated=1');
    exit;
}

/* =========================================
   FETCH STUDENT INFORMATION AND ACTIVITY
========================================= */

$stmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name AS name,
        u.email,

        COALESCE(NULLIF(u.course, ''), 'Not specified') AS course,

        COALESCE(NULLIF(u.year_level, ''), 'Not specified') AS year,

        u.status,
        u.created_at AS joined,

        /* Active uploaded resources */
        (
            SELECT COUNT(*)
            FROM resources r
            WHERE r.user_id = u.id
              AND r.status = 'Active'
        ) AS resources,

        /* Memberships in active study groups */
        (
            SELECT COUNT(*)
            FROM group_members gm
            INNER JOIN study_groups sg
                ON sg.id = gm.group_id
            WHERE gm.user_id = u.id
              AND sg.status = 'Active'
        ) AS groups,

        /* Active item listings */
        (
            SELECT COUNT(*)
            FROM items i
            WHERE i.user_id = u.id
              AND i.status = 'Active'
        ) AS items,

        /* Active service listings */
        (
            SELECT COUNT(*)
            FROM services s
            WHERE s.user_id = u.id
              AND s.status = 'Active'
        ) AS services

    FROM users u

    WHERE u.id = ?
      AND LOWER(u.role) = 'student'

    LIMIT 1
");

if (!$stmt) {
    error_log('Student details query error: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load user details right now.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$u = $result->fetch_assoc();

$stmt->close();

/* Return if the selected student does not exist */
if (!$u) {
    header('Location: users.php?not_found=1');
    exit;
}


require_once __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="user-view.css">


<main class="admin-user-view">

    <!-- Page heading -->
    <header class="page-head">
        <div>
            <h1>User details</h1>
            <p>View student account information and activity.</p>
        </div>
    </header>

    <!-- Update notification -->
    <?php if (isset($_GET['updated'])): ?>
        <div class="user-view-alert" role="status">
            Account status updated successfully.
        </div>
    <?php endif; ?>

    <!-- Student profile -->
    <section class="info who-card">

        <span class="avatar-lg">
            <?= e(initials($u['name'])) ?>
        </span>

        <div class="user-view-profile-info">
            <h2><?= e($u['name']) ?></h2>

            <p>
                <?= e($u['course']) ?>
                &bull;
                <?= e($u['year']) ?>
            </p>
        </div>

        <span class="status <?= e(status_class($u['status'])) ?>">
            <?= e($u['status']) ?>
        </span>

    </section>

    <!-- Account information -->
    <section class="info user-view-section">

        <h2>Account information</h2>

        <dl class="kv">

            <dt>Email</dt>
            <dd><?= e($u['email']) ?></dd>

            <dt>Course</dt>
            <dd><?= e($u['course']) ?></dd>

            <dt>Year level</dt>
            <dd><?= e($u['year']) ?></dd>

            <dt>Account status</dt>
            <dd><?= e($u['status']) ?></dd>

            <dt>Date joined</dt>
            <dd>
                <?= !empty($u['joined'])
                    ? e(fmt_date($u['joined']))
                    : 'Not available' ?>
            </dd>

        </dl>

    </section>

    <!-- Student activity -->
    <section class="info user-view-section">

        <div class="user-view-section-heading">
            <div>
                <h2>Activity</h2>
                <p>Current activity recorded in the StudentLink database.</p>
            </div>
        </div>

        <div class="user-activity-grid">

            <article class="user-activity-card">
                <span class="user-activity-icon">
                    <i class="fa-solid fa-file-lines"></i>
                </span>

                <div>
                    <strong><?= number_format((int) $u['resources']) ?></strong>
                    <p>Resources uploaded</p>
                </div>
            </article>

            <article class="user-activity-card">
                <span class="user-activity-icon">
                    <i class="fa-solid fa-users"></i>
                </span>

                <div>
                    <strong><?= number_format((int) $u['groups']) ?></strong>
                    <p>Groups joined</p>
                </div>
            </article>

            <article class="user-activity-card">
                <span class="user-activity-icon">
                    <i class="fa-solid fa-box"></i>
                </span>

                <div>
                    <strong><?= number_format((int) $u['items']) ?></strong>
                    <p>Items posted</p>
                </div>
            </article>

            <article class="user-activity-card">
                <span class="user-activity-icon">
                    <i class="fa-solid fa-handshake"></i>
                </span>

                <div>
                    <strong><?= number_format((int) $u['services']) ?></strong>
                    <p>Services offered</p>
                </div>
            </article>

        </div>

    </section>

    <!-- Account actions -->
    <form class="actions user-view-actions" method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <a class="btn btn-primary" href="users.php">
            Back to users
        </a>

        <?php if (strtolower($u['status']) === 'active'): ?>

            <button
                class="btn btn-danger"
                type="submit"
                name="action"
                value="suspend"
                onclick="return confirm('Are you sure you want to suspend this student account?')"
            >
                <i class="fa-solid fa-ban"></i>
                Suspend account
            </button>

        <?php elseif (strtolower($u['status']) === 'suspended'): ?>

            <button
                class="btn btn-primary"
                type="submit"
                name="action"
                value="activate"
                onclick="return confirm('Are you sure you want to reactivate this student account?')"
            >
                <i class="fa-solid fa-user-check"></i>
                Activate account
            </button>

        <?php endif; ?>

    </form>

</main>

<?php
require_once __DIR__ . '/../includes/admin-footer.php';
$conn->close();
?>
