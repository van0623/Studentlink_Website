<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/* =====================================================
   VERIFY ADMIN ACCOUNT
===================================================== */

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$adminStmt = $conn->prepare("
    SELECT id, full_name, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$adminStmt) {
    exit('Unable to verify your account.');
}

$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();

$admin = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (
    !$admin ||
    $admin['role'] !== 'admin' ||
    $admin['status'] !== 'Active'
) {
    session_unset();
    session_destroy();

    header('Location: ../login.php');
    exit;
}

$_SESSION['full_name'] = $admin['full_name'];
$_SESSION['user'] = $admin['full_name'];

/* =====================================================
   CSRF TOKEN
===================================================== */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =====================================================
   SETTINGS
===================================================== */

$allowedStatuses = [
    'Pending',
    'Under review',
    'Resolved',
    'Dismissed'
];

$error = '';
$success = '';

/* =====================================================
   HELPER: REDIRECT BACK TO REPORT LIST
===================================================== */

function redirectToReports($message)
{
    $params = [
        'message' => $message
    ];

    $view = $_POST['return_view'] ?? 'active';
    $status = $_POST['return_status'] ?? '';
    $search = $_POST['return_search'] ?? '';

    if (!in_array($view, ['active', 'archived'], true)) {
        $view = 'active';
    }

    $params['view'] = $view;

    if ($status !== '') {
        $params['status'] = $status;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    header('Location: reports.php?' . http_build_query($params));
    exit;
}

/* =====================================================
   PROCESS ADMIN ACTIONS
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    $reportId = filter_var(
        $_POST['report_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $action = $_POST['action'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $error = 'Your session token is invalid. Please refresh the page.';

    } elseif (!$reportId || $reportId < 1) {
        $error = 'Invalid report selected.';

    } else {

        /*
         * UPDATE STATUS AND ADMIN NOTES
         */

        if ($action === 'update') {

            $newStatus = trim($_POST['status'] ?? '');
            $adminNotes = trim($_POST['admin_notes'] ?? '');

            if (!in_array($newStatus, $allowedStatuses, true)) {
                $error = 'Please select a valid report status.';

            } elseif (mb_strlen($adminNotes) > 5000) {
                $error = 'Admin notes must not exceed 5,000 characters.';

            } else {

                $updateStmt = $conn->prepare("
                    UPDATE reports
                    SET
                        status = ?,
                        admin_notes = ?,
                        handled_by = ?
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$updateStmt) {
                    error_log('Report update preparation failed: ' . $conn->error);
                    $error = 'Unable to update the report right now.';

                } else {

                    $updateStmt->bind_param(
                        'ssii',
                        $newStatus,
                        $adminNotes,
                        $adminId,
                        $reportId
                    );

                    if ($updateStmt->execute()) {
                        $updateStmt->close();
                        redirectToReports('updated');
                    }

                    error_log('Report update failed: ' . $updateStmt->error);
                    $updateStmt->close();

                    $error = 'Unable to update the report.';
                }
            }

        /*
         * ARCHIVE REPORT
         */

        } elseif ($action === 'archive') {

            $archiveStmt = $conn->prepare("
                UPDATE reports
                SET
                    archived_at = NOW(),
                    handled_by = ?
                WHERE id = ?
                  AND archived_at IS NULL
                LIMIT 1
            ");

            if (!$archiveStmt) {
                error_log('Report archive preparation failed: ' . $conn->error);
                $error = 'Unable to archive the report.';

            } else {

                $archiveStmt->bind_param('ii', $adminId, $reportId);

                if ($archiveStmt->execute()) {
                    $archiveStmt->close();
                    redirectToReports('archived');
                }

                error_log('Report archive failed: ' . $archiveStmt->error);
                $archiveStmt->close();

                $error = 'Unable to archive the report.';
            }

        /*
         * RESTORE ARCHIVED REPORT
         */

        } elseif ($action === 'restore') {

            $restoreStmt = $conn->prepare("
                UPDATE reports
                SET archived_at = NULL
                WHERE id = ?
                  AND archived_at IS NOT NULL
                LIMIT 1
            ");

            if (!$restoreStmt) {
                error_log('Report restore preparation failed: ' . $conn->error);
                $error = 'Unable to restore the report.';

            } else {

                $restoreStmt->bind_param('i', $reportId);

                if ($restoreStmt->execute()) {
                    $restoreStmt->close();
                    redirectToReports('restored');
                }

                error_log('Report restore failed: ' . $restoreStmt->error);
                $restoreStmt->close();

                $error = 'Unable to restore the report.';
            }

        } else {
            $error = 'Invalid report action.';
        }
    }
}

/* =====================================================
   SUCCESS MESSAGES
===================================================== */

$successMessages = [
    'updated' => 'Report updated successfully.',
    'archived' => 'Report archived successfully.',
    'restored' => 'Report restored successfully.'
];

$messageKey = $_GET['message'] ?? '';

if (isset($successMessages[$messageKey])) {
    $success = $successMessages[$messageKey];
}

/* =====================================================
   VIEW FILTER: ACTIVE OR ARCHIVED
===================================================== */

$view = $_GET['view'] ?? 'active';

if (!in_array($view, ['active', 'archived'], true)) {
    $view = 'active';
}

/* =====================================================
   STATUS FILTER
===================================================== */

$filterStatus = trim($_GET['status'] ?? '');

if (
    $filterStatus !== '' &&
    !in_array($filterStatus, $allowedStatuses, true)
) {
    $filterStatus = '';
}

/* =====================================================
   SEARCH
===================================================== */

$search = trim($_GET['search'] ?? '');

/* =====================================================
   REPORT COUNTS
   Counts exclude archived reports.
===================================================== */

$counts = [
    'total' => 0,
    'Pending' => 0,
    'Under review' => 0,
    'Resolved' => 0,
    'Dismissed' => 0,
    'archived' => 0
];

$countResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'Pending'), 0) AS pending,
        COALESCE(SUM(status = 'Under review'), 0) AS under_review,
        COALESCE(SUM(status = 'Resolved'), 0) AS resolved,
        COALESCE(SUM(status = 'Dismissed'), 0) AS dismissed
    FROM reports
    WHERE archived_at IS NULL
");

if ($countResult) {

    $countData = $countResult->fetch_assoc();

    $counts['total'] = (int) ($countData['total'] ?? 0);
    $counts['Pending'] = (int) ($countData['pending'] ?? 0);
    $counts['Under review'] = (int) ($countData['under_review'] ?? 0);
    $counts['Resolved'] = (int) ($countData['resolved'] ?? 0);
    $counts['Dismissed'] = (int) ($countData['dismissed'] ?? 0);

} else {
    error_log('Report count query failed: ' . $conn->error);
}

/* Archived count */
$archiveCountResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM reports
    WHERE archived_at IS NOT NULL
");

if ($archiveCountResult) {
    $archiveCountData = $archiveCountResult->fetch_assoc();
    $counts['archived'] = (int) ($archiveCountData['total'] ?? 0);
}

/* =====================================================
   RETRIEVE REPORTS
===================================================== */

$reports = [];

$reportSql = "
    SELECT
        r.id,
        r.reporter_id,
        r.target_type,
        r.target_id,
        r.reason,
        r.details,
        r.status,
        r.admin_notes,
        r.handled_by,
        r.created_at,
        r.archived_at,

        reporter.full_name AS reporter_name,
        handler.full_name AS handler_name,

        COALESCE(
            CASE r.target_type

                WHEN 'User' THEN (
                    SELECT u.full_name
                    FROM users u
                    WHERE u.id = r.target_id
                    LIMIT 1
                )

                WHEN 'Resource' THEN (
                    SELECT res.title
                    FROM resources res
                    WHERE res.id = r.target_id
                    LIMIT 1
                )

                WHEN 'Group' THEN (
                    SELECT sg.name
                    FROM study_groups sg
                    WHERE sg.id = r.target_id
                    LIMIT 1
                )

                WHEN 'Service' THEN (
                    SELECT s.title
                    FROM services s
                    WHERE s.id = r.target_id
                    LIMIT 1
                )

                WHEN 'Item' THEN (
                    SELECT i.name
                    FROM items i
                    WHERE i.id = r.target_id
                    LIMIT 1
                )

                ELSE NULL

            END,
            CONCAT(r.target_type, ' #', r.target_id)
        ) AS target_name

    FROM reports r

    LEFT JOIN users reporter
        ON reporter.id = r.reporter_id

    LEFT JOIN users handler
        ON handler.id = r.handled_by

    WHERE
        (
            (? = 'archived' AND r.archived_at IS NOT NULL)
            OR
            (? = 'active' AND r.archived_at IS NULL)
        )

        AND (? = '' OR r.status = ?)

        AND (
            ? = ''
            OR reporter.full_name LIKE CONCAT('%', ?, '%')
            OR r.reason LIKE CONCAT('%', ?, '%')
            OR r.details LIKE CONCAT('%', ?, '%')
            OR r.target_type LIKE CONCAT('%', ?, '%')
            OR CAST(r.target_id AS CHAR) LIKE CONCAT('%', ?, '%')
            OR CAST(r.id AS CHAR) LIKE CONCAT('%', ?, '%')
        )

    ORDER BY
        CASE r.status
            WHEN 'Pending' THEN 1
            WHEN 'Under review' THEN 2
            WHEN 'Resolved' THEN 3
            WHEN 'Dismissed' THEN 4
            ELSE 5
        END,
        r.created_at DESC
";

$reportStmt = $conn->prepare($reportSql);

if (!$reportStmt) {

    error_log('Report retrieval preparation failed: ' . $conn->error);
    $error = 'Unable to load reports. Please check the database query.';

} else {

    $reportStmt->bind_param(
        'sssssssssss',
        $view,
        $view,
        $filterStatus,
        $filterStatus,
        $search,
        $search,
        $search,
        $search,
        $search,
        $search,
        $search
    );

    if ($reportStmt->execute()) {

        $reportResult = $reportStmt->get_result();

        while ($row = $reportResult->fetch_assoc()) {
            $reports[] = $row;
        }

    } else {

        error_log('Report retrieval failed: ' . $reportStmt->error);
        $error = 'Unable to retrieve reports.';
    }

    $reportStmt->close();
}

/* =====================================================
   STATUS CSS CLASS
===================================================== */

function reportStatusClass($status)
{
    switch ($status) {
        case 'Pending':
            return 'status-pending';

        case 'Under review':
            return 'status-review';

        case 'Resolved':
            return 'status-resolved';

        case 'Dismissed':
            return 'status-dismissed';

        default:
            return 'status-pending';
    }
}

require_once __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="reports.css">

<main class="admin-reports-page">

    <div class="reports-container">

        <!-- PAGE HEADER -->
        <section class="reports-heading">

            <div>
                <span class="reports-eyebrow">
                    STUDENTLINK ADMINISTRATION
                </span>

                <h1>Reports <span>Management.</span></h1>

                <p>
                    Review submitted reports, investigate concerns,
                    and manage report statuses.
                </p>
            </div>

        </section>

        <!-- ALERTS -->
        <?php if ($success !== ''): ?>
            <div class="reports-alert reports-alert-success" role="status">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="reports-alert reports-alert-error" role="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- REPORT STATISTICS -->
        <section class="reports-stats">

            <a href="reports.php" class="report-stat-card">
                <span class="stat-label">Active Reports</span>
                <strong><?= $counts['total'] ?></strong>
                <small>Reports not yet archived</small>
            </a>

            <a href="reports.php?status=Pending"
               class="report-stat-card stat-pending">
                <span class="stat-label">Pending</span>
                <strong><?= $counts['Pending'] ?></strong>
                <small>Waiting for review</small>
            </a>

            <a href="reports.php?status=Under%20review"
               class="report-stat-card stat-review">
                <span class="stat-label">Under Review</span>
                <strong><?= $counts['Under review'] ?></strong>
                <small>Currently being handled</small>
            </a>

            <a href="reports.php?status=Resolved"
               class="report-stat-card stat-resolved">
                <span class="stat-label">Resolved</span>
                <strong><?= $counts['Resolved'] ?></strong>
                <small>Successfully addressed</small>
            </a>

            <a href="reports.php?status=Dismissed"
               class="report-stat-card stat-dismissed">
                <span class="stat-label">Dismissed</span>
                <strong><?= $counts['Dismissed'] ?></strong>
                <small>Reports dismissed</small>
            </a>

        </section>

        <!-- ACTIVE / ARCHIVED NAVIGATION -->
        <section class="reports-toolbar">

            <div>
                <h2>
                    <?= $view === 'archived' ? 'Archived Reports' : 'Submitted Reports' ?>
                </h2>

                <p>
                    <?= $view === 'archived'
                        ? 'View reports that have been archived.'
                        : 'Review and manage reports submitted by StudentLink users.' ?>
                </p>
            </div>

            <div class="reports-filter-form">

                <a
                    href="reports.php"
                    class="reports-reset-btn"
                >
                    Active Reports
                </a>

                <a
                    href="reports.php?view=archived"
                    class="reports-reset-btn"
                >
                    Archived (<?= $counts['archived'] ?>)
                </a>

            </div>

        </section>

        <!-- FILTERS -->
        <section class="reports-toolbar">

            <form method="get" class="reports-filter-form">

                <input
                    type="hidden"
                    name="view"
                    value="<?= e($view) ?>"
                >

                <input
                    type="search"
                    name="search"
                    placeholder="Search reports..."
                    value="<?= e($search) ?>"
                >

                <select name="status">
                    <option value="">All statuses</option>

                    <?php foreach ($allowedStatuses as $status): ?>
                        <option
                            value="<?= e($status) ?>"
                            <?= $filterStatus === $status ? 'selected' : '' ?>
                        >
                            <?= e($status) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <button type="submit" class="reports-filter-btn">
                    Search
                </button>

                <a
                    href="<?= $view === 'archived' ? 'reports.php?view=archived' : 'reports.php' ?>"
                    class="reports-reset-btn"
                >
                    Reset
                </a>

            </form>

        </section>

        <!-- REPORT LIST -->
        <section class="reports-list">

            <?php if (empty($reports)): ?>

                <div class="reports-empty">

                    <div class="empty-icon">!</div>

                    <h3>No reports found</h3>

                    <p>
                        <?php if ($search !== '' || $filterStatus !== ''): ?>
                            No reports match your current search or filter.
                        <?php elseif ($view === 'archived'): ?>
                            There are currently no archived reports.
                        <?php else: ?>
                            There are currently no submitted reports.
                        <?php endif; ?>
                    </p>

                </div>

            <?php else: ?>

                <div class="reports-table-wrapper">

                    <table class="reports-table">

                        <thead>
                            <tr>
                                <th>Report</th>
                                <th>Reporter</th>
                                <th>Reported Target</th>
                                <th>Reason</th>
                                <th>Date Submitted</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($reports as $report): ?>

                                <tr>

                                    <td>
                                        <strong class="report-number">
                                            #<?= (int) $report['id'] ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= e($report['reporter_name'] ?: 'Unknown User') ?>
                                        </strong>

                                        <small>
                                            User ID: <?= (int) $report['reporter_id'] ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= e($report['target_name']) ?>
                                        </strong>

                                        <small>
                                            <?= e($report['target_type']) ?>
                                            · ID <?= (int) $report['target_id'] ?>
                                        </small>
                                    </td>

                                    <td>
                                        <span class="report-reason">
                                            <?= e($report['reason']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="report-date">
                                            <?= e(date('M d, Y', strtotime($report['created_at']))) ?>
                                        </span>

                                        <small>
                                            <?= e(date('h:i A', strtotime($report['created_at']))) ?>
                                        </small>
                                    </td>

                                    <td>
                                        <span class="report-status <?= e(reportStatusClass($report['status'])) ?>">
                                            <?= e($report['status']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <button
                                            type="button"
                                            class="report-view-btn"
                                            onclick="document.getElementById('report-modal-<?= (int) $report['id'] ?>').showModal()"
                                        >
                                            View Details
                                        </button>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <!-- REPORT DETAIL MODALS -->
                <?php foreach ($reports as $report): ?>

                    <dialog
                        class="report-modal"
                        id="report-modal-<?= (int) $report['id'] ?>"
                    >

                        <div class="report-modal-header">

                            <div>
                                <span class="reports-eyebrow">
                                    REPORT DETAILS
                                </span>

                                <h2>
                                    Report #<?= (int) $report['id'] ?>
                                </h2>
                            </div>

                            <button
                                type="button"
                                class="report-modal-close"
                                onclick="this.closest('dialog').close()"
                                aria-label="Close"
                            >
                                &times;
                            </button>

                        </div>

                        <div class="report-modal-body">

                            <div class="report-detail-grid">

                                <div class="report-detail">
                                    <span>Reporter</span>
                                    <strong>
                                        <?= e($report['reporter_name'] ?: 'Unknown User') ?>
                                    </strong>
                                </div>

                                <div class="report-detail">
                                    <span>Reported Type</span>
                                    <strong>
                                        <?= e($report['target_type']) ?>
                                    </strong>
                                </div>

                                <div class="report-detail">
                                    <span>Reported Target</span>
                                    <strong>
                                        <?= e($report['target_name']) ?>
                                    </strong>
                                </div>

                                <div class="report-detail">
                                    <span>Target ID</span>
                                    <strong>
                                        <?= (int) $report['target_id'] ?>
                                    </strong>
                                </div>

                                <div class="report-detail">
                                    <span>Reason</span>
                                    <strong>
                                        <?= e($report['reason']) ?>
                                    </strong>
                                </div>

                                <div class="report-detail">
                                    <span>Submitted</span>
                                    <strong>
                                        <?= e(date('M d, Y h:i A', strtotime($report['created_at']))) ?>
                                    </strong>
                                </div>

                                <div class="report-detail">
                                    <span>Current Status</span>
                                    <strong>
                                        <?= e($report['status']) ?>
                                    </strong>
                                </div>

                                <div class="report-detail">
                                    <span>Handled By</span>
                                    <strong>
                                        <?= e($report['handler_name'] ?: 'Not yet assigned') ?>
                                    </strong>
                                </div>

                                <?php if (!empty($report['archived_at'])): ?>
                                    <div class="report-detail">
                                        <span>Archived On</span>
                                        <strong>
                                            <?= e(date('M d, Y h:i A', strtotime($report['archived_at']))) ?>
                                        </strong>
                                    </div>
                                <?php endif; ?>

                            </div>

                            <div class="report-description">
                                <span>Additional Details</span>

                                <p>
                                    <?= nl2br(e($report['details'] ?: 'No additional details provided.')) ?>
                                </p>
                            </div>

                            <!-- UPDATE STATUS AND ADMIN NOTES -->
                            <form method="post" class="report-status-form">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="report_id"
                                    value="<?= (int) $report['id'] ?>"
                                >

                                <input type="hidden" name="action" value="update">

                                <input
                                    type="hidden"
                                    name="return_view"
                                    value="<?= e($view) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_status"
                                    value="<?= e($filterStatus) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="return_search"
                                    value="<?= e($search) ?>"
                                >

                                <label for="status-<?= (int) $report['id'] ?>">
                                    Update Report Status
                                </label>

                                <div class="report-status-controls">

                                    <select
                                        id="status-<?= (int) $report['id'] ?>"
                                        name="status"
                                        required
                                        <?= $view === 'archived' ? 'disabled' : '' ?>
                                    >

                                        <?php foreach ($allowedStatuses as $status): ?>

                                            <option
                                                value="<?= e($status) ?>"
                                                <?= $report['status'] === $status ? 'selected' : '' ?>
                                            >
                                                <?= e($status) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <?php if ($view === 'active'): ?>
                                        <button type="submit" class="report-save-btn">
                                            Save Changes
                                        </button>
                                    <?php endif; ?>

                                </div>

                                <label for="admin-notes-<?= (int) $report['id'] ?>">
                                    Private Admin Notes
                                </label>

                                <textarea
                                    id="admin-notes-<?= (int) $report['id'] ?>"
                                    name="admin_notes"
                                    rows="4"
                                    maxlength="5000"
                                    placeholder="Write internal notes about this report..."
                                    <?= $view === 'archived' ? 'readonly' : '' ?>
                                ><?= e($report['admin_notes'] ?? '') ?></textarea>

                                <?php if ($view === 'active'): ?>
                                    <small>
                                        These notes are only visible to administrators.
                                        Students cannot see them.
                                    </small>
                                <?php endif; ?>

                            </form>

                            <!-- ARCHIVE OR RESTORE -->
                            <div class="report-status-form">

                                <?php if ($view === 'active'): ?>

                                    <form method="post"
                                          onsubmit="return confirm('Are you sure you want to archive this report?');">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="report_id"
                                            value="<?= (int) $report['id'] ?>"
                                        >

                                        <input type="hidden" name="action" value="archive">

                                        <input type="hidden" name="return_view" value="active">

                                        <input
                                            type="hidden"
                                            name="return_status"
                                            value="<?= e($filterStatus) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="return_search"
                                            value="<?= e($search) ?>"
                                        >

                                        <button type="submit" class="reports-reset-btn">
                                            Archive Report
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <form method="post"
                                          onsubmit="return confirm('Restore this report to the active reports list?');">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="report_id"
                                            value="<?= (int) $report['id'] ?>"
                                        >

                                        <input type="hidden" name="action" value="restore">

                                        <input type="hidden" name="return_view" value="archived">

                                        <input
                                            type="hidden"
                                            name="return_status"
                                            value="<?= e($filterStatus) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="return_search"
                                            value="<?= e($search) ?>"
                                        >

                                        <button type="submit" class="report-save-btn">
                                            Restore Report
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </div>

                    </dialog>

                <?php endforeach; ?>

            <?php endif; ?>

        </section>

    </div>

</main>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>