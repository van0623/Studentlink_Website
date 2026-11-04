
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
    header('Location: groups.php');
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
    header('Location: groups.php?message=invalid_token');
    exit;
}

/* =========================================
   VALIDATE GROUP ID
========================================= */

$groupId = filter_var(
    $_POST['group_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$groupId || $groupId < 1) {
    header('Location: groups.php?message=delete_failed');
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
    error_log('Group delete user query error: ' . $conn->error);
    $conn->close();

    header('Location: groups.php?message=delete_failed');
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

    header('Location: groups.php?message=delete_failed');
    exit;
}

$isAdmin = $currentUser['role'] === 'admin';

/* =========================================
   DELETE GROUP
========================================= */

try {
    $conn->begin_transaction();

    /*
     * STUDENT:
     * Can only delete their own group.
     *
     * ADMIN:
     * Can delete any group.
     */

    if ($isAdmin) {
        $check = $conn->prepare(
            "SELECT id
             FROM study_groups
             WHERE id = ?
             FOR UPDATE"
        );

        if (!$check) {
            throw new Exception(
                'Group verification failed: ' . $conn->error
            );
        }

        $check->bind_param('i', $groupId);

    } else {
        $check = $conn->prepare(
            "SELECT id
             FROM study_groups
             WHERE id = ?
               AND creator_id = ?
             FOR UPDATE"
        );

        if (!$check) {
            throw new Exception(
                'Group verification failed: ' . $conn->error
            );
        }

        $check->bind_param('ii', $groupId, $userId);
    }

    $check->execute();

    $group = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$group) {
        throw new Exception(
            'Group does not exist or you are not allowed to delete it.'
        );
    }

    /* =========================================
       DELETE GROUP MEMBERS
    ========================================= */

    $members = $conn->prepare(
        "DELETE FROM group_members
         WHERE group_id = ?"
    );

    if (!$members) {
        throw new Exception(
            'Group membership query failed: ' . $conn->error
        );
    }

    $members->bind_param('i', $groupId);
    $members->execute();
    $members->close();

    /* =========================================
       DELETE GROUP
    ========================================= */

    if ($isAdmin) {
        $delete = $conn->prepare(
            "DELETE FROM study_groups
             WHERE id = ?"
        );

        if (!$delete) {
            throw new Exception(
                'Group deletion query failed: ' . $conn->error
            );
        }

        $delete->bind_param('i', $groupId);

    } else {
        $delete = $conn->prepare(
            "DELETE FROM study_groups
             WHERE id = ?
               AND creator_id = ?"
        );

        if (!$delete) {
            throw new Exception(
                'Group deletion query failed: ' . $conn->error
            );
        }

        $delete->bind_param('ii', $groupId, $userId);
    }

    $delete->execute();

    $deleted = $delete->affected_rows === 1;
    $delete->close();

    if (!$deleted) {
        throw new Exception('Group deletion was unsuccessful.');
    }

    /* =========================================
       COMMIT TRANSACTION
    ========================================= */

    $conn->commit();
    $conn->close();

    header('Location: groups.php?message=deleted');
    exit;

} catch (Throwable $e) {

    /* Roll back if deletion fails */
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // Ignore rollback errors.
    }

    error_log(
        'Group deletion error: ' . $e->getMessage()
    );

    if ($conn instanceof mysqli) {
        $conn->close();
    }

    header('Location: groups.php?message=delete_failed');
    exit;
}
