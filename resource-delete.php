
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

/* POST requests only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: resources.php');
    exit;
}

/* Validate CSRF token */
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    header('Location: resources.php?message=invalid_token');
    exit;
}

/* Validate resource ID */
$resourceId = filter_input(
    INPUT_POST,
    'resource_id',
    FILTER_VALIDATE_INT
);

if (!$resourceId || $resourceId < 1) {
    header('Location: resources.php?message=delete_failed');
    exit;
}

/* Verify account status and role */
$userStmt = $conn->prepare(
    "SELECT role, status
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$userStmt) {
    error_log('Resource delete account query error: ' . $conn->error);
    $conn->close();

    header('Location: resources.php?message=delete_failed');
    exit;
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();

$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (
    !$currentUser ||
    $currentUser['status'] !== 'Active' ||
    $currentUser['role'] !== 'student'
) {
    $conn->close();

    header('Location: resources.php?message=delete_failed');
    exit;
}

/* =========================================
   SOFT DELETE RESOURCE
========================================= */

try {
    $stmt = $conn->prepare(
        "UPDATE resources
         SET status = 'Removed'
         WHERE id = ?
           AND user_id = ?
           AND status = 'Active'"
    );

    $stmt->bind_param('ii', $resourceId, $userId);
    $stmt->execute();

    $removed = $stmt->affected_rows === 1;

    $stmt->close();
    $conn->close();

    if ($removed) {
        header('Location: resources.php?message=deleted');
        exit;
    }

    header('Location: resources.php?message=delete_failed');
    exit;

} catch (Throwable $e) {
    error_log('Resource delete error: ' . $e->getMessage());

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    header('Location: resources.php?message=delete_failed');
    exit;
}
