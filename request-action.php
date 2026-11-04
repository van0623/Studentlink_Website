
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

    /* Get the requester and item details */
    $checkSql = "
        SELECT
            ir.id,
            ir.status,
            ir.requester_id,
            ir.item_id AS listing_id,
            i.name AS listing_title
        FROM item_requests ir
        LEFT JOIN items i
            ON i.id = ir.item_id
        WHERE ir.id = ?
          AND ir.owner_id = ?
        LIMIT 1
    ";

} else {
    $table = 'service_requests';
    $ownerColumn = 'provider_id';

    /* Get the requester and service details */
    $checkSql = "
        SELECT
            sr.id,
            sr.status,
            sr.requester_id,
            sr.service_id AS listing_id,
            s.title AS listing_title
        FROM service_requests sr
        LEFT JOIN services s
            ON s.id = sr.service_id
        WHERE sr.id = ?
          AND sr.provider_id = ?
        LIMIT 1
    ";
}

/* Verify that this user owns/provides the requested listing */
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

/* Send a notification to the student who submitted the request */
try {

    $requesterId = (int) $request['requester_id'];
    $listingId = (int) $request['listing_id'];

    $listingTitle = trim((string) ($request['listing_title'] ?? ''));

    if ($listingTitle === '') {
        $listingTitle = $requestType === 'item_request'
            ? 'your requested item'
            : 'your requested service';
    }

    /* Generate the correct listing link */
    if ($requestType === 'item_request') {
        $listingLink = 'item-view.php?id=' . $listingId;
    } else {
        $listingLink = 'service-view.php?id=' . $listingId;
    }

    /* Create the notification message */
    if ($action === 'accept') {

        $notificationMessage =
            'Your request for "' . $listingTitle .
            '" has been accepted.';

    } elseif ($action === 'reject') {

        $notificationMessage =
            'Your request for "' . $listingTitle .
            '" has been rejected.';

    } else {

        $notificationMessage =
            'The acceptance of your request for "' . $listingTitle .
            '" was undone. Your request is now Pending.';
    }

    /* Do not notify the current user about their own action */
    if ($requesterId > 0 && $requesterId !== $userId) {

        createNotification(
            $conn,
            $requesterId,
            $notificationMessage,
            $listingLink,
            $userId
        );
    }

} catch (Throwable $notificationError) {

    /* Notification failure should not undo a successful request update */
    error_log(
        'Request action notification error: ' .
        $notificationError->getMessage()
    );
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
