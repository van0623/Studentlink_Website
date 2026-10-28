
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/* =========================================
   ADMIN ACCESS CHECK
========================================= */

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($sessionUserId <= 0) {
    header('Location: ../login.php');
    exit;
}

$authStmt = $conn->prepare("
    SELECT id, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$authStmt) {
    http_response_code(500);
    exit('Unable to verify your account.');
}

$authStmt->bind_param('i', $sessionUserId);
$authStmt->execute();
$currentAccount = $authStmt->get_result()->fetch_assoc();
$authStmt->close();

if (
    !$currentAccount ||
    strtolower($currentAccount['role']) !== 'admin' ||
    strcasecmp($currentAccount['status'], 'Active') !== 0
) {
    header('Location: ../login.php');
    exit;
}

/* =========================================
   CONTENT TYPES
========================================= */

$types = [
    'Resource' => 'Resources',
    'Group'    => 'Groups',
    'Service'  => 'Services',
    'Item'     => 'Items'
];

/*
 * These mappings are fixed and controlled by the application.
 * Never accept table or column names directly from the user.
 */
$contentTables = [
    'Resource' => [
        'table' => 'resources',
        'title' => 'title'
    ],
    'Group' => [
        'table' => 'study_groups',
        'title' => 'name'
    ],
    'Service' => [
        'table' => 'services',
        'title' => 'title'
    ],
    'Item' => [
        'table' => 'items',
        'title' => 'name'
    ]
];

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

$title = 'Content Management';
$active = 'content';

$errors = [];
$success = '';

/* =========================================
   FILTERS
========================================= */

$type = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');

if ($type !== '' && !array_key_exists($type, $types)) {
    $type = '';
}

/* =========================================
   POST ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $errors[] = 'Invalid request. Please refresh the page and try again.';

    } else {

        $action = $_POST['action'] ?? '';
        $contentId = (int) ($_POST['content_id'] ?? 0);
        $contentType = $_POST['content_type'] ?? '';

        if (
            $contentId <= 0 ||
            !isset($contentTables[$contentType])
        ) {
            $errors[] = 'Invalid content information.';

        } else {

            $table = $contentTables[$contentType]['table'];
            $titleColumn = $contentTables[$contentType]['title'];

            /* =====================================
               UPDATE CONTENT TITLE
            ===================================== */

            if ($action === 'update') {

                $newTitle = trim($_POST['content_title'] ?? '');

                if ($newTitle === '') {
                    $errors[] = 'Content title cannot be empty.';
                } elseif (strlen($newTitle) > 255) {
                    $errors[] = 'Content title must not exceed 255 characters.';
                }

                if (empty($errors)) {

                    $updateStmt = $conn->prepare("
                        UPDATE `$table`
                        SET `$titleColumn` = ?
                        WHERE id = ?
                    ");

                    if (!$updateStmt) {
                        error_log('Content update preparation failed: ' . $conn->error);
                        $errors[] = 'Unable to prepare the content update.';
                    } else {

                        $updateStmt->bind_param('si', $newTitle, $contentId);

                        if ($updateStmt->execute()) {
                            $updateStmt->close();

                            header('Location: content.php?success=updated');
                            exit;
                        }

                        error_log('Content update failed: ' . $updateStmt->error);
                        $errors[] = 'Unable to update this content.';
                        $updateStmt->close();
                    }
                }
            }

            /* =====================================
               SOFT DELETE / RESTORE CONTENT
            ===================================== */

            elseif ($action === 'change_status') {

                $newStatus = $_POST['new_status'] ?? '';

                if (!in_array($newStatus, ['Active', 'Removed'], true)) {
                    $errors[] = 'Invalid content status.';
                }

                if (empty($errors)) {

                    $statusStmt = $conn->prepare("
                        UPDATE `$table`
                        SET status = ?
                        WHERE id = ?
                    ");

                    if (!$statusStmt) {
                        error_log('Content status preparation failed: ' . $conn->error);
                        $errors[] = 'Unable to prepare the status update.';
                    } else {

                        $statusStmt->bind_param('si', $newStatus, $contentId);

                        if ($statusStmt->execute()) {
                            $statusStmt->close();

                            $message = $newStatus === 'Removed'
                                ? 'removed'
                                : 'restored';

                            header('Location: content.php?success=' . $message);
                            exit;
                        }

                        error_log('Content status update failed: ' . $statusStmt->error);
                        $errors[] = 'Unable to update the content status. Check the status values allowed by your database.';
                        $statusStmt->close();
                    }
                }
            }

            else {
                $errors[] = 'Invalid content action.';
            }
        }
    }
}

/* =========================================
   SUCCESS MESSAGES
========================================= */

$successMessages = [
    'updated'  => 'Content updated successfully.',
    'removed'  => 'Content removed successfully.',
    'restored' => 'Content restored successfully.'
];

$successKey = $_GET['success'] ?? '';

if (isset($successMessages[$successKey])) {
    $success = $successMessages[$successKey];
}

/* =========================================
   EDIT CONTENT
========================================= */

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editType = trim($_GET['edit_type'] ?? '');

$editContent = null;

if ($editId > 0 && isset($contentTables[$editType])) {

    $table = $contentTables[$editType]['table'];
    $titleColumn = $contentTables[$editType]['title'];

    $editStmt = $conn->prepare("
        SELECT id, `$titleColumn` AS title, status
        FROM `$table`
        WHERE id = ?
        LIMIT 1
    ");

    if ($editStmt) {

        $editStmt->bind_param('i', $editId);
        $editStmt->execute();

        $editContent = $editStmt->get_result()->fetch_assoc();

        $editStmt->close();

    } else {
        error_log('Content edit query failed: ' . $conn->error);
        $errors[] = 'Unable to load the selected content.';
    }

    if (!$editContent) {
        $errors[] = 'Content not found.';
    }
}

/* =========================================
   FETCH REAL-TIME CONTENT
========================================= */

$admin_content = [];

$sql = "
    SELECT *
    FROM (
        SELECT
            r.id,
            r.title AS title,
            'Resource' AS type,
            COALESCE(NULLIF(u.full_name, ''), 'Unknown user') AS owner,
            r.created_at AS content_date,
            r.status
        FROM resources r
        LEFT JOIN users u ON u.id = r.user_id

        UNION ALL

        SELECT
            g.id,
            g.name AS title,
            'Group' AS type,
            COALESCE(NULLIF(u.full_name, ''), 'Unknown user') AS owner,
            g.created_at AS content_date,
            g.status
        FROM study_groups g
        LEFT JOIN users u ON u.id = g.creator_id

        UNION ALL

        SELECT
            s.id,
            s.title AS title,
            'Service' AS type,
            COALESCE(NULLIF(u.full_name, ''), 'Unknown user') AS owner,
            s.created_at AS content_date,
            s.status
        FROM services s
        LEFT JOIN users u ON u.id = s.user_id

        UNION ALL

        SELECT
            i.id,
            i.name AS title,
            'Item' AS type,
            COALESCE(NULLIF(u.full_name, ''), 'Unknown user') AS owner,
            i.created_at AS content_date,
            i.status
        FROM items i
        LEFT JOIN users u ON u.id = i.user_id

    ) AS content

    WHERE
        (? = '' OR content.type = ?)
        AND (
            ? = ''
            OR content.title LIKE ?
            OR content.owner LIKE ?
            OR content.type LIKE ?
        )

    ORDER BY content.content_date DESC, content.id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Admin content query preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load content right now.');
}

$searchTerm = '%' . $search . '%';

$stmt->bind_param(
    'ssssss',
    $type,
    $type,
    $search,
    $searchTerm,
    $searchTerm,
    $searchTerm
);

if (!$stmt->execute()) {
    error_log('Admin content query execution failed: ' . $stmt->error);
    $stmt->close();

    http_response_code(500);
    exit('Unable to load content right now.');
}

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $admin_content[] = $row;
}

$stmt->close();

/* =========================================
   HEADER
========================================= */

include __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="content.css">

<header class="page-head">
    <div>
        <h1>Content</h1>
        <p>View and manage resources, groups, services, and items.</p>
    </div>
</header>

<?php if ($success !== ''): ?>
    <div class="alert success" role="status">
        <?= e($success) ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert error" role="alert">
        <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php flash('Content removed.'); ?>

<!-- =====================================
     EDIT CONTENT FORM
===================================== -->

<?php if ($editContent): ?>

    <section class="table-card">

        <h2>Edit Content</h2>

        <form method="post" action="content.php" class="edit-content-form">

            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="content_id" value="<?= (int) $editContent['id'] ?>">
            <input type="hidden" name="content_type" value="<?= e($editType) ?>">

            <div class="field">
                <label for="content_title">Content Title</label>

                <input
                    type="text"
                    id="content_title"
                    name="content_title"
                    maxlength="255"
                    value="<?= e($editContent['title']) ?>"
                    required
                >
            </div>

            <p>
                Type: <?= e($editType) ?>
                |
                Status: <?= e($editContent['status']) ?>
            </p>

            <button type="submit" class="btn btn-primary">
                Save Changes
            </button>

            <a href="content.php" class="btn">
                Cancel
            </a>

        </form>

    </section>

<?php endif; ?>

<!-- =====================================
     CONTENT TYPE FILTERS
===================================== -->

<nav class="tabs" aria-label="Content type">

    <a href="content.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>"
       <?= $type === '' ? 'aria-current="true"' : '' ?>>
        All
    </a>

    <?php foreach ($types as $key => $label): ?>

        <a
            href="?type=<?= urlencode($key) ?>&q=<?= urlencode($search) ?>"
            <?= $type === $key ? 'aria-current="true"' : '' ?>
        >
            <?= e($label) ?>
        </a>

    <?php endforeach; ?>

</nav>

<!-- =====================================
     SEARCH
===================================== -->

<form class="search" method="get" role="search">

    <input
        type="hidden"
        name="type"
        value="<?= e($type) ?>"
    >

    <input
        type="search"
        name="q"
        placeholder="Search content"
        value="<?= e($search) ?>"
        aria-label="Search content"
    >

    <button class="btn btn-primary" type="submit">
        Search
    </button>

    <?php if ($search !== '' || $type !== ''): ?>
        <a class="btn" href="content.php">Clear</a>
    <?php endif; ?>

</form>

<!-- =====================================
     CONTENT TABLE
===================================== -->

<div class="table-card">

    <table>

        <thead>
            <tr>
                <th>Content</th>
                <th>Owner</th>
                <th>Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>

            <?php foreach ($admin_content as $c): ?>

                <?php
                $isRemoved = strcasecmp($c['status'], 'Removed') === 0;
                ?>

                <tr>

                    <td>
                        <strong><?= e($c['title']) ?></strong>
                        <small><?= e($c['type']) ?></small>
                    </td>

                    <td>
                        <?= e($c['owner']) ?>
                    </td>

                    <td>
                        <?= !empty($c['content_date'])
                            ? e(fmt_date($c['content_date']))
                            : 'Not available' ?>
                    </td>

                    <td>
                        <span class="status <?= e(status_class($c['status'])) ?>">
                            <?= e($c['status']) ?>
                        </span>
                    </td>

                    <td>

                        <div class="content-actions">

                            <!-- VIEW -->
                            <a
                                class="btn btn-primary"
                                href="content-view.php?id=<?= (int) $c['id'] ?>&type=<?= urlencode($c['type']) ?>"
                            >
                                View
                            </a>

                            <!-- EDIT -->
                            <a
                                class="btn"
                                href="content.php?edit=<?= (int) $c['id'] ?>&edit_type=<?= urlencode($c['type']) ?>"
                            >
                                Edit
                            </a>

                            <!-- REMOVE / RESTORE -->
                            <form
                                method="post"
                                action="content.php"
                                onsubmit="return confirm('<?= $isRemoved ? 'Restore this content?' : 'Are you sure you want to remove this content?' ?>');"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="change_status"
                                >

                                <input
                                    type="hidden"
                                    name="content_id"
                                    value="<?= (int) $c['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="content_type"
                                    value="<?= e($c['type']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="new_status"
                                    value="<?= $isRemoved ? 'Active' : 'Removed' ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn <?= $isRemoved ? 'btn-restore' : 'btn-danger' ?>"
                                >
                                    <?= $isRemoved ? 'Restore' : 'Remove' ?>
                                </button>

                            </form>

                        </div>

                    </td>

                </tr>

            <?php endforeach; ?>

            <?php if (empty($admin_content)): ?>

                <tr>
                    <td colspan="5" class="empty">
                        Nothing here yet.
                    </td>
                </tr>

            <?php endif; ?>

        </tbody>

    </table>

</div>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
