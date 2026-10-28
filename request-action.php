<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

/* Require login */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Accept actions only through POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: transactions.php');
    exit;
}

/* Validate CSRF token */
$sessionToken = $_SESSION['request_action_csrf'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken)
    || !is_string($submittedToken)
    || $sessionToken === ''
    || !hash_equals($sessionToken, $submittedToken)
) {
    header('Location: transactions.php?result=csrf');
    exit;
}

/* Validate form values */
$requestType = $_POST['request_type'] ?? '';
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (
    !in_array($requestType, ['item_request', 'service_request'], true)
    || !$requestId
    || $requestId < 1
    || !in_array($action, ['accept', 'reject', 'undo_accept'], true)
) {
    header('Location: transactions.php?result=invalid');
    exit;
}

/* Verify that the account is active */
$userStmt = $conn->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND status = 'Active'
    LIMIT 1
");

if (!$userStmt) {
    error_log('Request action user check error: ' . $conn->error);
    header('Location: transactions.php?result=error');
    exit;
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();

$activeUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$activeUser) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* Select the request table and authorized owner column */
if ($requestType === 'item_request') {
    $table = 'item_requests';
    $ownerColumn = 'owner_id';
} else {
    $table = 'service_requests';
    $ownerColumn = 'provider_id';
}

/* Verify that this user owns/provides the requested listing */
$checkSql = "
    SELECT id, status
    FROM {$table}
    WHERE id = ?
      AND {$ownerColumn} = ?
    LIMIT 1
";

$checkStmt = $conn->prepare($checkSql);

if (!$checkStmt) {
    error_log('Request ownership check error: ' . $conn->error);
    header('Location: transactions.php?result=error');
    exit;
}

$checkStmt->bind_param('ii', $requestId, $userId);
$checkStmt->execute();

$request = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$request) {
    header('Location: transactions.php?result=unauthorized');
    exit;
}

/* Check the current status before changing it */
$currentStatus = strtolower((string) $request['status']);

if ($action === 'undo_accept') {
    /* Only accepted requests can return to pending */
    if ($currentStatus !== 'accepted') {
        header('Location: transactions.php?result=invalid_transition');
        exit;
    }

    $newStatus = 'Pending';
    $requiredStatus = 'accepted';

} else {
    /* Accept and Reject are only allowed for pending requests */
    if ($currentStatus !== 'pending') {
        header('Location: transactions.php?result=not_pending');
        exit;
    }

    $newStatus = $action === 'accept' ? 'Accepted' : 'Rejected';
    $requiredStatus = 'pending';
}

/*
 * Update the request only if it still has the expected status.
 * This prevents overwriting a request that was already changed.
 */
$updateSql = "
    UPDATE {$table}
    SET status = ?
    WHERE id = ?
      AND {$ownerColumn} = ?
      AND LOWER(status) = ?
";

$updateStmt = $conn->prepare($updateSql);

if (!$updateStmt) {
    error_log('Request update preparation error: ' . $conn->error);
    header('Location: transactions.php?result=error');
    exit;
}

$updateStmt->bind_param(
    'siis',
    $newStatus,
    $requestId,
    $userId,
    $requiredStatus
);

$updateStmt->execute();
$updatedRows = $updateStmt->affected_rows;
$updateStmt->close();

if ($updatedRows !== 1) {
    header('Location: transactions.php?result=not_pending');
    exit;
}

/* Redirect back to My Transactions */
$conn->close();

if ($action === 'undo_accept') {
    $result = 'undo_accepted';
} elseif ($action === 'accept') {
    $result = 'accepted';
} else {
    $result = 'rejected';
}

header('Location: transactions.php?result=' . $result);
exit;
?>