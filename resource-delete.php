
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

/* =========================================
   REQUIRE LOGIN
========================================= */

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* =========================================
   POST REQUESTS ONLY
========================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: resources.php');
    exit;
}

/* =========================================
   VALIDATE CSRF TOKEN
========================================= */

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

/* =========================================
   VALIDATE RESOURCE ID
========================================= */

$resourceId = filter_input(
    INPUT_POST,
    'resource_id',
    FILTER_VALIDATE_INT
);

if (!$resourceId || $resourceId < 1) {
    header('Location: resources.php?message=delete_failed');
    exit;
}

/* =========================================
   VERIFY ACCOUNT STATUS AND ROLE
========================================= */

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
    !in_array($currentUser['role'], ['student', 'admin'], true)
) {
    $conn->close();

    header('Location: resources.php?message=delete_failed');
    exit;
}

/* =========================================
   SOFT DELETE RESOURCE
   Only the resource owner can delete it.
========================================= */

try {
    $stmt = $conn->prepare(
        "UPDATE resources
         SET status = 'Removed'
         WHERE id = ?
           AND user_id = ?
           AND status = 'Active'"
    );

    if (!$stmt) {
        throw new Exception(
            'Resource delete query preparation failed: ' . $conn->error
        );
    }

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
