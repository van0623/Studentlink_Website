
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/* =========================================
   ADMIN ACCESS CHECK
========================================= */

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($sessionUserId <= 0) {
    header('Location: ../login.php');
    exit;
}

$authStmt = $conn->prepare("
    SELECT id, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$authStmt) {
    error_log('Admin permission check failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to verify your account.');
}

$authStmt->bind_param('i', $sessionUserId);
$authStmt->execute();
$currentAccount = $authStmt->get_result()->fetch_assoc();
$authStmt->close();

if (
    !$currentAccount ||
    strtolower($currentAccount['role']) !== 'admin' ||
    strcasecmp($currentAccount['status'], 'Active') !== 0
) {
    header('Location: ../login.php');
    exit;
}

/* =========================================
   CSRF TOKEN
========================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================
   PAGE SETTINGS
========================================= */

$title = 'Admin Account Management';
$active = 'create-admin';

$errors = [];
$success = '';

$fullName = '';
$email = '';

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editAdmin = null;

/* =========================================
   FORM SUBMISSION
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        /* =====================================
           CREATE ADMIN
        ===================================== */

        if ($action === 'create') {

            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($fullName === '') {
                $errors[] = 'Please enter the admin full name.';
            } elseif (strlen($fullName) > 100) {
                $errors[] = 'Full name must not exceed 100 characters.';
            }

            if ($email === '') {
                $errors[] = 'Please enter an email address.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (strlen($email) > 150) {
                $errors[] = 'Email must not exceed 150 characters.';
            }

            if ($password === '') {
                $errors[] = 'Please enter a password.';
            } elseif (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }

            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }

            if (empty($errors)) {

                $checkStmt = $conn->prepare("
                    SELECT id FROM users
                    WHERE email = ?
                    LIMIT 1
                ");

                if (!$checkStmt) {
                    $errors[] = 'Unable to verify the email address.';
                } else {
                    $checkStmt->bind_param('s', $email);
                    $checkStmt->execute();
                    $checkStmt->store_result();

                    if ($checkStmt->num_rows > 0) {
                        $errors[] = 'This email is already registered.';
                    }

                    $checkStmt->close();
                }
            }

            if (empty($errors)) {

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $emptyCourse = '';
                $emptyYearLevel = '';
                $emptyAbout = '';
                $emptySkills = '';
                $photoPath = null;

                $role = 'admin';
                $status = 'Active';

                $insertStmt = $conn->prepare("
                    INSERT INTO users (
                        full_name,
                        email,
                        password_hash,
                        course,
                        year_level,
                        about,
                        skills,
                        photo_path,
                        role,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$insertStmt) {
                    error_log('Admin creation query failed: ' . $conn->error);
                    $errors[] = 'Unable to create the admin account.';
                } else {

                    $insertStmt->bind_param(
                        'ssssssssss',
                        $fullName,
                        $email,
                        $passwordHash,
                        $emptyCourse,
                        $emptyYearLevel,
                        $emptyAbout,
                        $emptySkills,
                        $photoPath,
                        $role,
                        $status
                    );

                    if ($insertStmt->execute()) {
                        $insertStmt->close();

                        header('Location: create-admin.php?success=created');
                        exit;
                    } else {
                        if ($insertStmt->errno === 1062) {
                            $errors[] = 'This email is already registered.';
                        } else {
                            error_log('Admin creation failed: ' . $insertStmt->error);
                            $errors[] = 'Could not create the admin account.';
                        }
                    }

                    $insertStmt->close();
                }
            }
        }

        /* =====================================
           UPDATE ADMIN
        ===================================== */

        elseif ($action === 'update') {

            $adminId = (int) ($_POST['admin_id'] ?? 0);

            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($adminId <= 0) {
                $errors[] = 'Invalid administrator account.';
            }

            if ($fullName === '') {
                $errors[] = 'Please enter the admin full name.';
            } elseif (strlen($fullName) > 100) {
                $errors[] = 'Full name must not exceed 100 characters.';
            }

            if ($email === '') {
                $errors[] = 'Please enter an email address.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (strlen($email) > 150) {
                $errors[] = 'Email must not exceed 150 characters.';
            }

            if ($password !== '' && strlen($password) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            }

            /* Check that the account exists and is an admin. */
            if (empty($errors)) {

                $checkAdmin = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE id = ? AND role = 'admin'
                    LIMIT 1
                ");

                if (!$checkAdmin) {
                    $errors[] = 'Unable to verify the administrator account.';
                } else {
                    $checkAdmin->bind_param('i', $adminId);
                    $checkAdmin->execute();
                    $checkAdmin->store_result();

                    if ($checkAdmin->num_rows === 0) {
                        $errors[] = 'Administrator account not found.';
                    }

                    $checkAdmin->close();
                }
            }

            /* Check duplicate email. */
            if (empty($errors)) {

                $emailStmt = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ? AND id != ?
                    LIMIT 1
                ");

                if (!$emailStmt) {
                    $errors[] = 'Unable to verify the email address.';
                } else {
                    $emailStmt->bind_param('si', $email, $adminId);
                    $emailStmt->execute();
                    $emailStmt->store_result();

                    if ($emailStmt->num_rows > 0) {
                        $errors[] = 'This email is already used by another account.';
                    }

                    $emailStmt->close();
                }
            }

            if (empty($errors)) {

                if ($password !== '') {

                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    $updateStmt = $conn->prepare("
                        UPDATE users
                        SET full_name = ?, email = ?, password_hash = ?
                        WHERE id = ? AND role = 'admin'
                    ");

                    if ($updateStmt) {
                        $updateStmt->bind_param(
                            'sssi',
                            $fullName,
                            $email,
                            $passwordHash,
                            $adminId
                        );
                    }

                } else {

                    $updateStmt = $conn->prepare("
                        UPDATE users
                        SET full_name = ?, email = ?
                        WHERE id = ? AND role = 'admin'
                    ");

                    if ($updateStmt) {
                        $updateStmt->bind_param(
                            'ssi',
                            $fullName,
                            $email,
                            $adminId
                        );
                    }
                }

                if (!$updateStmt) {
                    $errors[] = 'Unable to prepare the account update.';
                } elseif ($updateStmt->execute()) {
                    $updateStmt->close();

                    header('Location: create-admin.php?success=updated');
                    exit;
                } else {
                    error_log('Admin update failed: ' . $updateStmt->error);
                    $errors[] = 'Could not update the administrator account.';
                    $updateStmt->close();
                }
            }

            $editId = $adminId;
        }

        /* =====================================
           SOFT DELETE / RESTORE ADMIN
        ===================================== */

        elseif ($action === 'change_status') {

            $adminId = (int) ($_POST['admin_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';

            if ($adminId <= 0) {
                $errors[] = 'Invalid administrator account.';
            } elseif ($adminId === $sessionUserId && $newStatus === 'Suspended') {
                $errors[] = 'You cannot suspend your own administrator account.';
            } elseif (!in_array($newStatus, ['Active', 'Suspended'], true)) {
                $errors[] = 'Invalid account status.';
            }

            if (empty($errors)) {

                $statusStmt = $conn->prepare("
                    UPDATE users
                    SET status = ?
                    WHERE id = ? AND role = 'admin'
                ");

                if (!$statusStmt) {
                    $errors[] = 'Unable to prepare the status update.';
                } else {

                    $statusStmt->bind_param('si', $newStatus, $adminId);

                    if ($statusStmt->execute() && $statusStmt->affected_rows >= 0) {
                        $statusStmt->close();

                        $message = $newStatus === 'Suspended'
                            ? 'suspended'
                            : 'restored';

                        header('Location: create-admin.php?success=' . $message);
                        exit;
                    } else {
                        $errors[] = 'Could not update the administrator status.';
                        $statusStmt->close();
                    }
                }
            }
        }

        else {
            $errors[] = 'Invalid action.';
        }
    }
}

/* =========================================
   SUCCESS MESSAGES
========================================= */

$successMessages = [
    'created' => 'Admin account created successfully.',
    'updated' => 'Admin account updated successfully.',
    'suspended' => 'Admin account suspended successfully.',
    'restored' => 'Admin account restored successfully.'
];

$successKey = $_GET['success'] ?? '';

if (isset($successMessages[$successKey])) {
    $success = $successMessages[$successKey];
}

/* =========================================
   GET ADMIN FOR EDITING
========================================= */

if ($editId > 0) {

    $editStmt = $conn->prepare("
        SELECT id, full_name, email, status
        FROM users
        WHERE id = ? AND role = 'admin'
        LIMIT 1
    ");

    if ($editStmt) {
        $editStmt->bind_param('i', $editId);
        $editStmt->execute();

        $editAdmin = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    }

    if ($editAdmin) {
        $fullName = $fullName !== '' ? $fullName : $editAdmin['full_name'];
        $email = $email !== '' ? $email : $editAdmin['email'];
    } elseif (empty($errors)) {
        $errors[] = 'Administrator account not found.';
    }
}

/* =========================================
   GET ALL ADMIN ACCOUNTS
========================================= */

$admins = [];

$adminStmt = $conn->prepare("
    SELECT id, full_name, email, status
    FROM users
    WHERE role = 'admin'
    ORDER BY id DESC
");

if ($adminStmt) {
    $adminStmt->execute();
    $adminResult = $adminStmt->get_result();

    while ($row = $adminResult->fetch_assoc()) {
        $admins[] = $row;
    }

    $adminStmt->close();
} else {
    error_log('Admin list query failed: ' . $conn->error);
    $errors[] = 'Unable to load administrator accounts.';
}

/* =========================================
   HEADER
========================================= */

include __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="create-admin.css">

<header class="page-head">
    <div>
        <span class="eyebrow">ADMINISTRATION</span>
        <h1>Admin Account Management</h1>
        <p>Create, view, update, and manage administrator accounts.</p>
    </div>
</header>

<?php if ($success !== ''): ?>
    <div class="create-admin-message success" role="status">
        <?= e($success) ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="create-admin-message error" role="alert">
        <?php foreach ($errors as $error): ?>
            <p><?= e($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- =====================================
     CREATE / UPDATE FORM
===================================== -->

<section class="panel-card create-admin-panel">

    <div class="create-admin-heading">
        <div class="create-admin-icon">
            <?= $editAdmin ? '✎' : '+' ?>
        </div>

        <div>
            <h2>
                <?= $editAdmin ? 'Edit Administrator' : 'New Administrator' ?>
            </h2>

            <p>
                <?= $editAdmin
                    ? 'Update the administrator account information.'
                    : 'Enter the information to create a new administrator.' ?>
            </p>
        </div>
    </div>

    <div class="create-admin-note">
        <strong>Admin access</strong>
        <p>
            Administrator accounts have access to the StudentLink admin dashboard.
            Only create accounts for trusted users.
        </p>
    </div>

    <form method="post" action="create-admin.php<?= $editAdmin ? '?edit=' . (int) $editAdmin['id'] : '' ?>" class="create-admin-form">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <input
            type="hidden"
            name="action"
            value="<?= $editAdmin ? 'update' : 'create' ?>"
        >

        <?php if ($editAdmin): ?>
            <input
                type="hidden"
                name="admin_id"
                value="<?= (int) $editAdmin['id'] ?>"
            >
        <?php endif; ?>

        <div class="form-grid">

            <div class="field">
                <label for="full_name">Admin Full Name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="100"
                    autocomplete="name"
                    value="<?= e($fullName) ?>"
                    placeholder="Enter full name"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="150"
                    autocomplete="email"
                    value="<?= e($email) ?>"
                    placeholder="admin@example.com"
                    required
                >
            </div>

            <div class="field">
                <label for="password">
                    <?= $editAdmin ? 'New Password (Optional)' : 'Password' ?>
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="<?= $editAdmin ? 'Leave blank to keep current password' : 'At least 8 characters' ?>"
                    <?= $editAdmin ? '' : 'required' ?>
                >
            </div>

            <?php if (!$editAdmin): ?>
                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="Enter the password again"
                        required
                    >
                </div>
            <?php endif; ?>

        </div>

        <div class="create-admin-actions">

            <button type="submit" class="btn-primary">
                <?= $editAdmin ? 'Save Changes' : 'Create Admin Account' ?>
            </button>

            <?php if ($editAdmin): ?>
                <a class="btn-ghost" href="create-admin.php">
                    Cancel Editing
                </a>
            <?php else: ?>
                <a class="btn-ghost" href="dashboard.php">
                    Cancel
                </a>
            <?php endif; ?>

        </div>

    </form>
</section>

<!-- =====================================
     ADMIN ACCOUNT LIST
===================================== -->

<section class="panel-card admin-list-panel">

    <div class="admin-list-heading">
        <div>
            <span class="eyebrow">ACCOUNT RECORDS</span>
            <h2>Administrator Accounts</h2>
            <p>Manage the administrator accounts registered in StudentLink.</p>
        </div>

        <span class="admin-count">
            <?= count($admins) ?> Accounts
        </span>
    </div>

    <?php if (empty($admins)): ?>

        <div class="empty-admins">
            <h3>No administrator accounts found</h3>
            <p>Create an administrator account using the form above.</p>
        </div>

    <?php else: ?>

        <div class="admin-table-wrap">

            <table class="admin-table">

                <thead>
                    <tr>
                        <th>Administrator</th>
                        <th>Email Address</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($admins as $admin): ?>

                        <?php
                        $isCurrentAdmin = (int) $admin['id'] === $sessionUserId;
                        $isActive = strcasecmp($admin['status'], 'Active') === 0;
                        ?>

                        <tr>

                            <td>
                                <div class="admin-name">
                                    <span class="admin-avatar">
                                        <?= e(strtoupper(substr($admin['full_name'], 0, 1))) ?>
                                    </span>

                                    <div>
                                        <strong><?= e($admin['full_name']) ?></strong>

                                        <?php if ($isCurrentAdmin): ?>
                                            <small>Your account</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <td><?= e($admin['email']) ?></td>

                            <td>
                                <span class="status-badge <?= $isActive ? 'active' : 'suspended' ?>">
                                    <?= e($admin['status']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="admin-actions">

                                    <a
                                        href="create-admin.php?edit=<?= (int) $admin['id'] ?>"
                                        class="action-btn edit-btn"
                                    >
                                        Edit
                                    </a>

                                    <?php if ($isActive): ?>

                                        <?php if (!$isCurrentAdmin): ?>

                                            <form
                                                method="post"
                                                action="create-admin.php"
                                                onsubmit="return confirm('Are you sure you want to suspend this administrator?');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="change_status">
                                                <input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                                                <input type="hidden" name="new_status" value="Suspended">

                                                <button type="submit" class="action-btn delete-btn">
                                                    Suspend
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <span class="self-account-note">Current account</span>

                                        <?php endif; ?>

                                    <?php else: ?>

                                        <form
                                            method="post"
                                            action="create-admin.php"
                                            onsubmit="return confirm('Restore this administrator account?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                                            <input type="hidden" name="new_status" value="Active">

                                            <button type="submit" class="action-btn restore-btn">
                                                Restore
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</section>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
