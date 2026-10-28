
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

/* Only allow POST requests */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: groups.php');
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
    header('Location: groups.php?message=invalid_token');
    exit;
}

/* Validate group ID */
$groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);

if (!$groupId || $groupId < 1) {
    header('Location: groups.php?message=delete_failed');
    exit;
}

/* =========================================
   DELETE GROUP
========================================= */

try {
    $conn->begin_transaction();

    /*
     * Lock and verify the group.
     * Only the creator is allowed to delete it.
     */
    $check = $conn->prepare(
        "SELECT id
         FROM study_groups
         WHERE id = ?
           AND creator_id = ?
         FOR UPDATE"
    );

    $check->bind_param('ii', $groupId, $userId);
    $check->execute();

    $group = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$group) {
        $conn->rollback();
        $conn->close();

        header('Location: groups.php?message=delete_failed');
        exit;
    }

    /*
     * Delete memberships first to avoid foreign-key
     * problems from the group_members table.
     */
    $members = $conn->prepare(
        "DELETE FROM group_members
         WHERE group_id = ?"
    );

    $members->bind_param('i', $groupId);
    $members->execute();
    $members->close();

    /*
     * Delete the group only when it belongs to this user.
     */
    $delete = $conn->prepare(
        "DELETE FROM study_groups
         WHERE id = ?
           AND creator_id = ?"
    );

    $delete->bind_param('ii', $groupId, $userId);
    $delete->execute();

    $deleted = $delete->affected_rows === 1;
    $delete->close();

    if (!$deleted) {
        $conn->rollback();
        $conn->close();

        header('Location: groups.php?message=delete_failed');
        exit;
    }

    $conn->commit();
    $conn->close();

    header('Location: groups.php?message=deleted');
    exit;

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // The transaction may already be closed.
    }

    error_log('Delete study group error: ' . $e->getMessage());

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    header('Location: groups.php?message=delete_failed');
    exit;
}
