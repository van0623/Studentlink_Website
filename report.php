<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Require login */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Verify current account */
$userStmt = $conn->prepare("
    SELECT id, full_name, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$userStmt) {
    exit('Unable to verify your account.');
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (
    !$currentUser ||
    $currentUser['status'] !== 'Active' ||
    !in_array($currentUser['role'], ['student', 'admin'], true)
) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Supported report types */
$allowedTypes = [
    'User',
    'Resource',
    'Group',
    'Service',
    'Item'
];

/* Get target information */
$type = trim($_POST['target_type'] ?? $_GET['type'] ?? '');

$targetId = filter_var(
    $_POST['target_id'] ?? $_GET['id'] ?? null,
    FILTER_VALIDATE_INT
);

$target = null;
$targetOwnerId = null;
$error = '';

$success = (
    isset($_GET['submitted']) &&
    $_GET['submitted'] === '1'
);

$reason = trim($_POST['reason'] ?? '');
$details = trim($_POST['details'] ?? '');

$title = 'Report';
$active = '';
$css = ['report'];

/*
|--------------------------------------------------------------------------
| FIND THE REPORTED TARGET
|--------------------------------------------------------------------------
*/

if (
    in_array($type, $allowedTypes, true) &&
    $targetId !== false &&
    $targetId !== null &&
    $targetId > 0
) {
    switch ($type) {

        case 'User':
            $targetSql = "
                SELECT
                    id,
                    full_name AS target_name,
                    id AS owner_id
                FROM users
                WHERE id = ?
                LIMIT 1
            ";
            break;

        case 'Resource':
            $targetSql = "
                SELECT
                    id,
                    title AS target_name,
                    user_id AS owner_id
                FROM resources
                WHERE id = ?
                  AND status = 'Active'
                LIMIT 1
            ";
            break;

        case 'Group':
            $targetSql = "
                SELECT
                    id,
                    name AS target_name,
                    creator_id AS owner_id
                FROM study_groups
                WHERE id = ?
                  AND status = 'Active'
                LIMIT 1
            ";
            break;

        case 'Service':
            $targetSql = "
                SELECT
                    id,
                    title AS target_name,
                    user_id AS owner_id
                FROM services
                WHERE id = ?
                  AND status = 'Active'
                LIMIT 1
            ";
            break;

        case 'Item':
            $targetSql = "
                SELECT
                    id,
                    name AS target_name,
                    user_id AS owner_id
                FROM items
                WHERE id = ?
                  AND status = 'Active'
                LIMIT 1
            ";
            break;

        default:
            $targetSql = '';
            break;
    }

    if ($targetSql !== '') {
        $targetStmt = $conn->prepare($targetSql);

        if (!$targetStmt) {
            error_log('Report target query error: ' . $conn->error);
            $error = 'Unable to check the selected report target.';
        } else {
            $targetStmt->bind_param('i', $targetId);
            $targetStmt->execute();

            $target = $targetStmt->get_result()->fetch_assoc();

            $targetStmt->close();

            if ($target) {
                $targetOwnerId = (int) $target['owner_id'];
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| VALIDATE TARGET
|--------------------------------------------------------------------------
*/

if ($error === '') {

    if (
        !in_array($type, $allowedTypes, true) ||
        $targetId === false ||
        $targetId === null ||
        $targetId < 1
    ) {
        $error = 'The selected report target is invalid.';

    } elseif (!$target) {
        $error = 'The reported user or content could not be found or is no longer available.';

    } elseif (
        $targetOwnerId !== null &&
        $targetOwnerId === $userId
    ) {
        $error = 'You cannot report your own content or account.';
    }
}

/*
|--------------------------------------------------------------------------
| REPORT REASONS
|--------------------------------------------------------------------------
*/

$reasons = [
    'Inappropriate content',
    'Spam or misleading information',
    'Harassment or bullying',
    'Fraud or scam',
    'Copyright violation',
    'Fake or misleading profile',
    'Other'
];

/*
|--------------------------------------------------------------------------
| SUBMIT REPORT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !$success &&
    $error === ''
) {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $error = 'Your session token is invalid. Please refresh and try again.';

    } elseif (!in_array($reason, $reasons, true)) {
        $error = 'Please select a valid reason for your report.';

    } elseif ($details === '') {
        $error = 'Please explain why you are reporting this.';

    } elseif (mb_strlen($details) > 2000) {
        $error = 'Your explanation must not exceed 2,000 characters.';

    } else {

        /*
         * Check ownership again before inserting.
         * This prevents users from submitting reports
         * against their own accounts or content.
         */
        if ($targetOwnerId === $userId) {
            $error = 'You cannot report your own content or account.';

        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO reports (
                    reporter_id,
                    target_type,
                    target_id,
                    reason,
                    details,
                    status
                )
                VALUES (?, ?, ?, ?, ?, 'Pending')
            ");

            if (!$insertStmt) {
                error_log('Report insert preparation failed: ' . $conn->error);
                $error = 'Unable to submit your report right now. Please try again.';

            } else {
                $insertStmt->bind_param(
                    'isiss',
                    $userId,
                    $type,
                    $targetId,
                    $reason,
                    $details
                );

                if ($insertStmt->execute()) {
                    $insertStmt->close();

                    header(
                        'Location: report.php?type=' .
                        urlencode($type) .
                        '&id=' .
                        $targetId .
                        '&submitted=1'
                    );
                    exit;
                }

                error_log('Report submission failed: ' . $insertStmt->error);
                $insertStmt->close();

                $error = 'Unable to submit your report right now. Please try again.';
            }
        }
    }
}


require_once __DIR__ . '/includes/header.php';
?>

<main class="report-page">

    <section class="report-container">

        <div class="report-heading">
            <span class="report-eyebrow">STUDENTLINK SAFETY</span>

            <h1>Report <span>Content.</span></h1>

            <p>
                Help us keep StudentLink safe and respectful.
                Reports are reviewed by the administration.
            </p>
        </div>

        <?php if ($success): ?>

            <div class="report-success" role="status">
                <h2>Report submitted</h2>

                <p>
                    Thank you for helping keep StudentLink safe.
                    Our administration will review your report.
                </p>

                <a href="resources.php" class="report-back-btn">
                    Back to Resources
                </a>
            </div>

        <?php elseif (
            $error !== '' &&
            (
                !$target ||
                $targetOwnerId === $userId ||
                !in_array($type, $allowedTypes, true)
            )
        ): ?>

            <div class="report-error" role="alert">
                <?= e($error) ?>
            </div>

            <a href="dashboard.php" class="report-back-btn">
                Back to Dashboard
            </a>

        <?php else: ?>

            <?php if ($error !== ''): ?>
                <div class="report-error" role="alert">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <div class="report-target">
                <span>YOU ARE REPORTING</span>

                <h2><?= e($target['target_name']) ?></h2>

                <p><?= e($type) ?></p>
            </div>

            <form method="post" class="report-form">

                <input
                    type="hidden"
                    name="target_type"
                    value="<?= e($type) ?>"
                >

                <input
                    type="hidden"
                    name="target_id"
                    value="<?= (int) $targetId ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <div class="report-field">
                    <label for="reason">Reason for reporting</label>

                    <select id="reason" name="reason" required>
                        <option value="">Select a reason</option>

                        <?php foreach ($reasons as $reportReason): ?>
                            <option
                                value="<?= e($reportReason) ?>"
                                <?= $reason === $reportReason ? 'selected' : '' ?>
                            >
                                <?= e($reportReason) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="report-field">
                    <label for="details">Additional details</label>

                    <textarea
                        id="details"
                        name="details"
                        rows="5"
                        maxlength="2000"
                        placeholder="Explain what happened or why this content should be reviewed..."
                        required
                    ><?= e($details) ?></textarea>

                    <small>
                        Provide enough information to help the admin
                        understand the issue. Maximum 2,000 characters.
                    </small>
                </div>

                <div class="report-notice">
                    Please submit honest and accurate reports.
                    False or abusive reports may be subject to review.
                </div>

                <div class="report-actions">
                    <a
                        href="javascript:history.back()"
                        class="report-cancel-btn"
                    >
                        Cancel
                    </a>

                    <button type="submit" class="report-submit-btn">
                        Submit Report
                    </button>
                </div>

            </form>

        <?php endif; ?>

    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
