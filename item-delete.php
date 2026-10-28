
<?php
session_start();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Require Login
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Allow POST Requests Only
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: items.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate Item ID
|--------------------------------------------------------------------------
*/
$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);

if (!$itemId || $itemId < 1) {
    header('Location: items.php?error=invalid_item');
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate CSRF Token
|--------------------------------------------------------------------------
*/
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    header('Location: items.php?error=invalid_token');
    exit;
}

/*
|--------------------------------------------------------------------------
| Soft Delete Item
|--------------------------------------------------------------------------
| Only the owner can remove their own active item.
| The item remains in the database with status = 'Removed'.
|--------------------------------------------------------------------------
*/
try {
    $stmt = $conn->prepare(
        "UPDATE items
        SET status = 'Removed'
        WHERE id = ?
        AND user_id = ?
        AND status = 'Active'"
    );

    $stmt->bind_param('ii', $itemId, $userId);
    $stmt->execute();

    $deleted = $stmt->affected_rows === 1;

    $stmt->close();
    $conn->close();

    if ($deleted) {
        header('Location: items.php?deleted=1');
        exit;
    }

    // The item may not exist, may already be removed,
    // or may belong to another user.
    header('Location: items.php?error=delete_failed');
    exit;

} catch (Throwable $e) {
    error_log('Item deletion failed: ' . $e->getMessage());

    if (isset($stmt)) {
        $stmt->close();
    }

    if (isset($conn)) {
        $conn->close();
    }

    header('Location: items.php?error=delete_failed');
    exit;
}