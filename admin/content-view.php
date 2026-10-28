
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

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
    exit('Unable to verify your account.');
}

$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();

$adminResult = $adminStmt->get_result();
$admin = $adminResult->fetch_assoc();

$adminStmt->close();

if (
    !$admin ||
    strtolower($admin['role']) !== 'admin' ||
    strtolower($admin['status']) !== 'active'
) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| GET CONTENT ID AND TYPE
|--------------------------------------------------------------------------
*/

$contentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$contentType = trim($_GET['type'] ?? '');

$allowedTypes = [
    'Resource',
    'Group',
    'Item',
    'Service'
];

if (
    !$contentId ||
    $contentId <= 0 ||
    !in_array($contentType, $allowedTypes, true)
) {
    header('Location: content.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CONTENT TABLE CONFIGURATION
|--------------------------------------------------------------------------
*/

$contentTables = [
    'Resource' => [
        'table' => 'resources',
        'title' => 'title',
        'owner' => 'user_id',
        'description' => 'description',
        'date' => 'created_at'
    ],

    'Group' => [
        'table' => 'study_groups',
        'title' => 'name',
        'owner' => 'creator_id',
        'description' => 'description',
        'date' => 'created_at'
    ],

    'Item' => [
        'table' => 'items',
        'title' => 'name',
        'owner' => 'user_id',
        'description' => 'description',
        'date' => 'created_at'
    ],

    'Service' => [
        'table' => 'services',
        'title' => 'title',
        'owner' => 'user_id',
        'description' => 'description',
        'date' => 'created_at'
    ]
];

$config = $contentTables[$contentType];

/*
|--------------------------------------------------------------------------
| HANDLE REMOVE AND RESTORE ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');
    }

    if (!in_array($action, ['remove', 'restore'], true)) {
        header(
            'Location: content-view.php?id=' .
            $contentId . '&type=' . urlencode($contentType)
        );
        exit;
    }

    $newStatus = ($action === 'remove') ? 'Removed' : 'Active';
    $currentStatus = ($action === 'remove') ? 'Active' : 'Removed';

    $table = $config['table'];

    $updateStmt = $conn->prepare("
        UPDATE {$table}
        SET status = ?
        WHERE id = ?
        AND status = ?
        LIMIT 1
    ");

    if (!$updateStmt) {
        error_log('Content status update error: ' . $conn->error);
        exit('Unable to update content status.');
    }

    $updateStmt->bind_param(
        'sis',
        $newStatus,
        $contentId,
        $currentStatus
    );

    if (!$updateStmt->execute()) {
        error_log('Content update execution error: ' . $updateStmt->error);
        $updateStmt->close();
        exit('Unable to update content status. Please try again.');
    }

    $affectedRows = $updateStmt->affected_rows;
    $updateStmt->close();

    if ($affectedRows > 0) {
        $_SESSION['content_message'] = (
            $action === 'remove'
        ) ? 'Content removed successfully.' : 'Content restored successfully.';
    } else {
        $_SESSION['content_message'] = (
            $action === 'remove'
        ) ? 'Content was already removed or could not be found.' :
            'Content was already active or could not be found.';
    }

    header(
        'Location: content-view.php?id=' .
        $contentId . '&type=' . urlencode($contentType)
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| FETCH CONTENT DETAILS
|--------------------------------------------------------------------------
*/

$table = $config['table'];
$titleColumn = $config['title'];
$ownerColumn = $config['owner'];
$descriptionColumn = $config['description'];
$dateColumn = $config['date'];

$sql = "
    SELECT
        c.id,
        c.{$titleColumn} AS title,
        c.{$descriptionColumn} AS description,
        c.status,
        c.{$dateColumn} AS content_date,
        c.{$ownerColumn} AS owner_id,
        COALESCE(NULLIF(u.full_name, ''), 'Unknown user') AS owner,
        u.email AS owner_email
    FROM {$table} c
    LEFT JOIN users u ON u.id = c.{$ownerColumn}
    WHERE c.id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Content details query error: ' . $conn->error);
    exit('Unable to load content details.');
}

$stmt->bind_param('i', $contentId);
$stmt->execute();

$result = $stmt->get_result();
$content = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| STOP IF CONTENT DOES NOT EXIST
|--------------------------------------------------------------------------
*/

if (!$content) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
}

/*
|--------------------------------------------------------------------------
| OPTIONAL DETAILS BY CONTENT TYPE
|--------------------------------------------------------------------------
*/

$extraDetails = [];

if (!$notFound) {

    if ($contentType === 'Resource') {

        $detailStmt = $conn->prepare("
            SELECT
                resource_type,
                file_name,
                file_type,
                file_size,
                subject_id
            FROM resources
            WHERE id = ?
            LIMIT 1
        ");

        if ($detailStmt) {
            $detailStmt->bind_param('i', $contentId);
            $detailStmt->execute();

            $extraDetails = $detailStmt->get_result()->fetch_assoc() ?? [];

            $detailStmt->close();
        }

        if (!empty($extraDetails['subject_id'])) {

            $subjectId = (int) $extraDetails['subject_id'];

            $subjectStmt = $conn->prepare("
                SELECT name
                FROM subjects
                WHERE id = ?
                LIMIT 1
            ");

            if ($subjectStmt) {
                $subjectStmt->bind_param('i', $subjectId);
                $subjectStmt->execute();

                $subject = $subjectStmt->get_result()->fetch_assoc();

                $extraDetails['subject'] = $subject['name'] ?? 'Not available';

                $subjectStmt->close();
            }
        }

    } elseif ($contentType === 'Group') {

        $detailStmt = $conn->prepare("
            SELECT
                g.course,
                g.year_level,
                g.max_members,
                g.subject_id,
                (
                    SELECT COUNT(*)
                    FROM group_members gm
                    WHERE gm.group_id = g.id
                ) AS member_count
            FROM study_groups g
            WHERE g.id = ?
            LIMIT 1
        ");

        if ($detailStmt) {
            $detailStmt->bind_param('i', $contentId);
            $detailStmt->execute();

            $extraDetails = $detailStmt->get_result()->fetch_assoc() ?? [];

            $detailStmt->close();
        }

        if (!empty($extraDetails['subject_id'])) {

            $subjectId = (int) $extraDetails['subject_id'];

            $subjectStmt = $conn->prepare("
                SELECT name
                FROM subjects
                WHERE id = ?
                LIMIT 1
            ");

            if ($subjectStmt) {
                $subjectStmt->bind_param('i', $subjectId);
                $subjectStmt->execute();

                $subject = $subjectStmt->get_result()->fetch_assoc();

                $extraDetails['subject'] = $subject['name'] ?? 'Not available';

                $subjectStmt->close();
            }
        }

    } elseif ($contentType === 'Item') {

        $detailStmt = $conn->prepare("
            SELECT
                transaction_type,
                item_condition,
                price,
                category_id
            FROM items
            WHERE id = ?
            LIMIT 1
        ");

        if ($detailStmt) {
            $detailStmt->bind_param('i', $contentId);
            $detailStmt->execute();

            $extraDetails = $detailStmt->get_result()->fetch_assoc() ?? [];

            $detailStmt->close();
        }

    } elseif ($contentType === 'Service') {

        $detailStmt = $conn->prepare("
            SELECT
                skills,
                category_id
            FROM services
            WHERE id = ?
            LIMIT 1
        ");

        if ($detailStmt) {
            $detailStmt->bind_param('i', $contentId);
            $detailStmt->execute();

            $extraDetails = $detailStmt->get_result()->fetch_assoc() ?? [];

            $detailStmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| FORMAT DISPLAY VALUES
|--------------------------------------------------------------------------
*/

$statusClass = '';
$formattedDate = 'Not available';

if (!$notFound && !empty($content)) {


    $statusClass = strtolower($content['status']) === 'active'
        ? 'active'
        : 'removed';


    if (!empty($content['content_date'])) {
        $timestamp = strtotime($content['content_date']);

        if ($timestamp !== false) {
            $formattedDate = date('F d, Y h:i A', $timestamp);
        }
    }
}

include __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="content-view.css">

<main class="content-view-page">

    <header class="page-head">
        <div>
            <span class="page-subtitle">Content management</span>
            <h1>Content details</h1>
            <p>View and manage content submitted by students.</p>
        </div>

        <a href="content.php" class="btn btn-secondary">
            Back to content
        </a>
    </header>

    <?php if (!empty($_SESSION['content_message'])): ?>

        <div class="content-alert">
            <?= e($_SESSION['content_message']) ?>
        </div>

        <?php unset($_SESSION['content_message']); ?>

    <?php endif; ?>

    <?php if ($notFound): ?>

        <section class="info">
            <h2>Content not found</h2>
            <p>
                The content you are trying to view does not exist
                or may have been deleted.
            </p>

            <a href="content.php" class="btn btn-primary">
                Return to content
            </a>
        </section>

    <?php else: ?>

        <section class="content-view-grid">

            <div class="info content-main-info">

                <div class="content-heading">

                    <div>
                        <span class="content-type">
                            <?= e($contentType) ?>
                        </span>

                        <h2><?= e($content['title']) ?></h2>
                    </div>

                    <span class="status <?= e($statusClass) ?>">
                        <?= e($content['status']) ?>
                    </span>

                </div>

                <div class="content-divider"></div>

                <h3>Description</h3>

                <p class="content-description">
                    <?= !empty($content['description'])
                        ? nl2br(e($content['description']))
                        : 'No description provided.' ?>
                </p>

            </div>

            <div class="info">

                <h2>Content information</h2>

                <dl class="kv">

                    <dt>Content ID</dt>
                    <dd>#<?= (int) $content['id'] ?></dd>

                    <dt>Content type</dt>
                    <dd><?= e($contentType) ?></dd>

                    <dt>Date posted</dt>
                    <dd><?= e($formattedDate) ?></dd>

                    <?php if ($contentType === 'Resource'): ?>

                        <dt>Resource type</dt>
                        <dd><?= e($extraDetails['resource_type'] ?? 'Not available') ?></dd>

                        <dt>Subject</dt>
                        <dd><?= e($extraDetails['subject'] ?? 'Not available') ?></dd>

                        <dt>File name</dt>
                        <dd><?= e($extraDetails['file_name'] ?? 'Not available') ?></dd>

                        <dt>File size</dt>
                        <dd>
                            <?php
                            $fileSize = (int) ($extraDetails['file_size'] ?? 0);

                            echo $fileSize > 0
                                ? e(number_format($fileSize / 1024, 2) . ' KB')
                                : 'Not available';
                            ?>
                        </dd>

                    <?php elseif ($contentType === 'Group'): ?>

                        <dt>Subject</dt>
                        <dd><?= e($extraDetails['subject'] ?? 'Not available') ?></dd>

                        <dt>Course</dt>
                        <dd><?= e($extraDetails['course'] ?? 'Not specified') ?></dd>

                        <dt>Year level</dt>
                        <dd><?= e($extraDetails['year_level'] ?? 'Not specified') ?></dd>

                        <dt>Members</dt>
                        <dd>
                            <?= (int) ($extraDetails['member_count'] ?? 0) ?>
                            /
                            <?= (int) ($extraDetails['max_members'] ?? 0) ?>
                        </dd>

                    <?php elseif ($contentType === 'Item'): ?>

                        <dt>Transaction type</dt>
                        <dd><?= e($extraDetails['transaction_type'] ?? 'Not available') ?></dd>

                        <dt>Condition</dt>
                        <dd><?= e($extraDetails['item_condition'] ?? 'Not available') ?></dd>

                        <dt>Price</dt>
                        <dd>
                            <?php
                            $price = $extraDetails['price'] ?? null;

                            echo $price !== null
                                ? '₱' . e(number_format((float) $price, 2))
                                : 'Not specified';
                            ?>
                        </dd>

                    <?php elseif ($contentType === 'Service'): ?>

                        <dt>Skills</dt>
                        <dd><?= e($extraDetails['skills'] ?? 'Not specified') ?></dd>

                    <?php endif; ?>

                </dl>

            </div>

            <div class="info owner-info">

                <h2>Content owner</h2>

                <dl class="kv">

                    <dt>Name</dt>
                    <dd><?= e($content['owner']) ?></dd>

                    <dt>Email</dt>
                    <dd><?= e($content['owner_email'] ?? 'Not available') ?></dd>

                    <dt>User ID</dt>
                    <dd>
                        <?= !empty($content['owner_id'])
                            ? '#' . (int) $content['owner_id']
                            : 'Not available' ?>
                    </dd>

                </dl>

            </div>

            <div class="info content-action-info">

                <h2>Content actions</h2>

                <p>
                    <?php if (strtolower($content['status']) === 'active'): ?>

                        This content is currently visible as active content.
                        You can remove it if it violates the platform rules.

                    <?php else: ?>

                        This content has been removed.
                        You can restore it to make it active again.

                    <?php endif; ?>
                </p>

                <form method="POST" class="actions">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <?php if (strtolower($content['status']) === 'active'): ?>

                        <button
                            type="submit"
                            name="action"
                            value="remove"
                            class="btn btn-danger"
                            onclick="return confirm('Are you sure you want to remove this content?');"
                        >
                            Remove content
                        </button>

                    <?php elseif (strtolower($content['status']) === 'removed'): ?>

                        <button
                            type="submit"
                            name="action"
                            value="restore"
                            class="btn btn-success"
                            onclick="return confirm('Are you sure you want to restore this content?');"
                        >
                            Restore content
                        </button>

                    <?php endif; ?>

                    <a href="content.php" class="btn btn-secondary">
                        Back to content
                    </a>

                </form>

            </div>

        </section>

    <?php endif; ?>

</main>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
