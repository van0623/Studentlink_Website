
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Only logged-in users can delete their services */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/* Deletion must use POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: services.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Validate service ID */
$serviceId = filter_input(
    INPUT_POST,
    'service_id',
    FILTER_VALIDATE_INT
);

if (!$serviceId || $serviceId < 1) {
    header('Location: services.php?error=invalid_service');
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
    header('Location: services.php?error=invalid_token');
    exit;
}

try {
    /*
     * Soft delete:
     * Only update an active service belonging to this user.
     */
    $stmt = $conn->prepare(
        "UPDATE services
         SET status = 'Removed'
         WHERE id = ?
           AND user_id = ?
           AND status = 'Active'"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare service deletion.');
    }

    $stmt->bind_param('ii', $serviceId, $userId);
    $stmt->execute();

    $deleted = $stmt->affected_rows === 1;

    $stmt->close();
    $conn->close();

    if ($deleted) {
        header('Location: services.php?deleted=1');
        exit;
    }

    /*
     * No row was changed.
     * The service may not exist, may already be removed,
     * or may belong to another user.
     */
    header('Location: services.php?error=delete_failed');
    exit;

} catch (Throwable $e) {
    error_log('Service deletion error: ' . $e->getMessage());

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    header('Location: services.php?error=delete_failed');
    exit;
}
?>
