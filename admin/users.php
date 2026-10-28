
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/* =========================================
   ADMIN ACCESS CHECK
========================================= */

$adminId = (int) ($_SESSION['user_id'] ?? 0);

if ($adminId <= 0) {
    header('Location: ../login.php');
    exit;
}

$adminStmt = $conn->prepare("
    SELECT id, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$adminStmt) {
    exit('Unable to verify your account.');
}

$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();
$adminAccount = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (
    !$adminAccount ||
    strtolower($adminAccount['role']) !== 'admin' ||
    strcasecmp($adminAccount['status'], 'Active') !== 0
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


$success = '';
$error = '';

/* =========================================
   POST ACTIONS
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        /* ---------------------------------
           CREATE OR UPDATE USER
        --------------------------------- */

        if ($action === 'save_user') {

            $userId = (int) ($_POST['user_id'] ?? 0);

            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $course = trim($_POST['course'] ?? '');
            $yearLevel = trim($_POST['year_level'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($fullName === '' || $email === '') {
                $error = 'Full name and email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (strlen($fullName) > 150) {
                $error = 'Full name is too long.';
            } elseif (strlen($email) > 255) {
                $error = 'Email address is too long.';
            } else {

                /* Check duplicate email. */
                if ($userId > 0) {
                    $duplicateStmt = $conn->prepare("
                        SELECT id
                        FROM users
                        WHERE email = ?
                        AND id != ?
                        LIMIT 1
                    ");

                    $duplicateStmt->bind_param('si', $email, $userId);
                } else {
                    $duplicateStmt = $conn->prepare("
                        SELECT id
                        FROM users
                        WHERE email = ?
                        LIMIT 1
                    ");

                    $duplicateStmt->bind_param('s', $email);
                }

                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                $emailExists = $duplicateResult->num_rows > 0;
                $duplicateStmt->close();

                if ($emailExists) {
                    $error = 'That email address is already registered.';
                } elseif ($userId > 0) {

                    /* Make sure the selected account is a student. */
                    $checkStmt = $conn->prepare("
                        SELECT id
                        FROM users
                        WHERE id = ?
                        AND LOWER(role) = 'student'
                        LIMIT 1
                    ");

                    $checkStmt->bind_param('i', $userId);
                    $checkStmt->execute();

                    $studentExists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();

                    if (!$studentExists) {
                        $error = 'Student account not found.';
                    } else {

                        $updateStmt = $conn->prepare("
                            UPDATE users
                            SET full_name = ?,
                                email = ?,
                                course = ?,
                                year_level = ?
                            WHERE id = ?
                            AND LOWER(role) = 'student'
                        ");

                        $updateStmt->bind_param(
                            'ssssi',
                            $fullName,
                            $email,
                            $course,
                            $yearLevel,
                            $userId
                        );

                        if ($updateStmt->execute()) {

                            /* Update password only when provided. */
                            if ($password !== '') {

                                if (strlen($password) < 8) {
                                    $error = 'Password must contain at least 8 characters.';
                                } else {
                                    $passwordHash = password_hash(
                                        $password,
                                        PASSWORD_DEFAULT
                                    );

                                    $passwordStmt = $conn->prepare("
                                        UPDATE users
                                        SET password_hash = ?
                                        WHERE id = ?
                                        AND LOWER(role) = 'student'
                                    ");

                                    $passwordStmt->bind_param(
                                        'si',
                                        $passwordHash,
                                        $userId
                                    );

                                    if (!$passwordStmt->execute()) {
                                        $error = 'Unable to update the password.';
                                    }

                                    $passwordStmt->close();
                                }
                            }

                            if ($error === '') {
                                header('Location: users.php?success=updated');
                                exit;
                            }

                        } else {
                            $error = 'Unable to update the student account.';
                        }

                        $updateStmt->close();
                    }

                } else {

                    /* CREATE NEW STUDENT */

                    if ($password === '') {
                        $error = 'A password is required for a new account.';
                    } elseif (strlen($password) < 8) {
                        $error = 'Password must contain at least 8 characters.';
                    } else {

                        $passwordHash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $role = 'student';
                        $status = 'Active';

                        $insertStmt = $conn->prepare("
                            INSERT INTO users (
                                full_name,
                                email,
                                password_hash,
                                course,
                                year_level,
                                role,
                                status
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");

                        $insertStmt->bind_param(
                            'sssssss',
                            $fullName,
                            $email,
                            $passwordHash,
                            $course,
                            $yearLevel,
                            $role,
                            $status
                        );

                        if ($insertStmt->execute()) {
                            $insertStmt->close();

                            header('Location: users.php?success=created');
                            exit;
                        } else {
                            $error = 'Unable to create the student account.';
                        }

                        $insertStmt->close();
                    }
                }
            }
        }

        /* ---------------------------------
           SUSPEND OR REACTIVATE USER
        --------------------------------- */

        elseif ($action === 'change_status') {

            $userId = (int) ($_POST['user_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';

            if (!in_array($newStatus, ['Active', 'Suspended'], true)) {
                $error = 'Invalid account status.';
            } else {

                $statusStmt = $conn->prepare("
                    UPDATE users
                    SET status = ?
                    WHERE id = ?
                    AND LOWER(role) = 'student'
                ");

                $statusStmt->bind_param(
                    'si',
                    $newStatus,
                    $userId
                );

                if ($statusStmt->execute()) {

                    if ($statusStmt->affected_rows > 0) {
                        $statusStmt->close();

                        header(
                            'Location: users.php?success=' .
                            ($newStatus === 'Suspended' ? 'suspended' : 'restored')
                        );
                        exit;
                    } else {
                        $error = 'No account was updated. Check the selected student.';
                    }

                } else {
                    $error = 'Unable to change the account status.';
                }

                $statusStmt->close();
            }
        }
    }
}

/* =========================================
   SUCCESS MESSAGES
========================================= */

if (isset($_GET['success'])) {
    $messages = [
        'created'   => 'Student account created successfully.',
        'updated'   => 'Student account updated successfully.',
        'suspended' => 'Student account suspended successfully.',
        'restored'  => 'Student account reactivated successfully.'
    ];

    $success = $messages[$_GET['success']] ?? '';
}

/* =========================================
   SEARCH AND FILTERS
========================================= */

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$courseFilter = $_GET['course'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

$allowedStatuses = ['', 'Active', 'Suspended'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($sort, ['newest', 'oldest', 'name'], true)) {
    $sort = 'newest';
}

/* =========================================
   CREATE / EDIT FORM DATA
========================================= */

$formAction = $_GET['action'] ?? '';
$editId = (int) ($_GET['id'] ?? 0);

$editingUser = null;

if ($formAction === 'edit' && $editId > 0) {

    $editStmt = $conn->prepare("
        SELECT id, full_name, email, course, year_level
        FROM users
        WHERE id = ?
        AND LOWER(role) = 'student'
        LIMIT 1
    ");

    $editStmt->bind_param('i', $editId);
    $editStmt->execute();

    $editingUser = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();

    if (!$editingUser) {
        $error = 'Student account not found.';
        $formAction = '';
    }
}

/* =========================================
   COURSE FILTER OPTIONS
========================================= */

$courses = [];

$courseResult = $conn->query("
    SELECT DISTINCT course
    FROM users
    WHERE LOWER(role) = 'student'
    AND course IS NOT NULL
    AND TRIM(course) != ''
    ORDER BY course ASC
");

if ($courseResult) {
    while ($courseRow = $courseResult->fetch_assoc()) {
        $courses[] = $courseRow['course'];
    }
}

/* =========================================
   LOAD STUDENT ACCOUNTS
========================================= */

$admin_users = [];

$sql = "
    SELECT
        id,
        full_name,
        email,
        COALESCE(course, 'Not specified') AS course,
        COALESCE(year_level, 'Not specified') AS year_level,
        photo_path,
        status
    FROM users
    WHERE LOWER(role) = 'student'
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= "
        AND (
            full_name LIKE ?
            OR email LIKE ?
            OR course LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;

    $types .= 'sss';
}

if ($statusFilter !== '') {
    $sql .= " AND status = ? ";

    $params[] = $statusFilter;
    $types .= 's';
}

if ($courseFilter !== '') {
    $sql .= " AND course = ? ";

    $params[] = $courseFilter;
    $types .= 's';
}

switch ($sort) {
    case 'oldest':
        $sql .= " ORDER BY id ASC";
        break;

    case 'name':
        $sql .= " ORDER BY full_name ASC";
        break;

    default:
        $sql .= " ORDER BY id DESC";
        break;
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Admin users query preparation failed: ' . $conn->error);
    exit('Unable to load student accounts.');
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    error_log('Admin users query failed: ' . $stmt->error);
    exit('Unable to load student accounts.');
}

$result = $stmt->get_result();

while ($user = $result->fetch_assoc()) {
    $admin_users[] = $user;
}

$stmt->close();

/* =========================================
   COUNTS
========================================= */

$counts = [
    'total' => 0,
    'active' => 0,
    'suspended' => 0
];

$countResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = 'Suspended' THEN 1 ELSE 0 END) AS suspended
    FROM users
    WHERE LOWER(role) = 'student'
");

if ($countResult) {
    $countData = $countResult->fetch_assoc();

    $counts['total'] = (int) $countData['total'];
    $counts['active'] = (int) $countData['active'];
    $counts['suspended'] = (int) $countData['suspended'];
}

/* =========================================
   HEADER
========================================= */

include __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="users.css">

<header class="page-head">
    <div>
        <span class="eyebrow">ACCOUNT MANAGEMENT</span>
        <h1>Manage users</h1>
        <p>Manage student accounts and their access to StudentLink.</p>
    </div>

    <a href="users.php?action=create" class="btn btn-primary">
        + Add student
    </a>
</header>

<?php if ($success !== ''): ?>
    <div class="alert success">
        <?= e($success) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- USER STATISTICS -->

<section class="user-stats">

    <div class="stat-card">
        <span>Total students</span>
        <strong><?= $counts['total'] ?></strong>
    </div>

    <div class="stat-card">
        <span>Active accounts</span>
        <strong><?= $counts['active'] ?></strong>
    </div>

    <div class="stat-card">
        <span>Suspended accounts</span>
        <strong><?= $counts['suspended'] ?></strong>
    </div>

</section>

<!-- CREATE / EDIT FORM -->

<?php if (in_array($formAction, ['create', 'edit'], true)): ?>

<section class="user-form-card">

    <div class="form-heading">
        <div>
            <h2>
                <?= $formAction === 'edit' ? 'Edit student' : 'Create student account' ?>
            </h2>

            <p>
                <?= $formAction === 'edit'
                    ? 'Update the selected student information.'
                    : 'Enter the information for the new student account.' ?>
            </p>
        </div>

        <a href="users.php" class="btn btn-secondary">Cancel</a>
    </div>

    <form method="post" action="users.php?action=<?= e($formAction) ?><?= $formAction === 'edit' ? '&id=' . $editId : '' ?>">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_user">

        <?php if ($formAction === 'edit'): ?>
            <input type="hidden" name="user_id" value="<?= $editId ?>">
        <?php endif; ?>

        <div class="form-grid">

            <div class="form-group">
                <label for="full_name">Full name</label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="150"
                    value="<?= e($editingUser['full_name'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="email">Email address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="255"
                    value="<?= e($editingUser['email'] ?? '') ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="course">Course</label>

                <input
                    type="text"
                    id="course"
                    name="course"
                    value="<?= e($editingUser['course'] ?? '') ?>"
                    placeholder="Example: BSIT"
                >
            </div>

            <div class="form-group">
                <label for="year_level">Year level</label>

                <select id="year_level" name="year_level">
                    <option value="">Select year level</option>

                    <?php
                    $yearOptions = [
                        '1st Year',
                        '2nd Year',
                        '3rd Year',
                        '4th Year',
                        '5th Year'
                    ];

                    $selectedYear = $editingUser['year_level'] ?? '';

                    foreach ($yearOptions as $year):
                    ?>
                        <option
                            value="<?= e($year) ?>"
                            <?= $selectedYear === $year ? 'selected' : '' ?>
                        >
                            <?= e($year) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group full-width">
                <label for="password">
                    Password
                    <?php if ($formAction === 'edit'): ?>
                        <span class="optional">(Leave blank to keep current password)</span>
                    <?php endif; ?>
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    <?= $formAction === 'create' ? 'required' : '' ?>
                    autocomplete="new-password"
                    placeholder="<?= $formAction === 'edit' ? 'Leave blank to keep current password' : 'At least 8 characters' ?>"
                >
            </div>

        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <?= $formAction === 'edit' ? 'Save changes' : 'Create account' ?>
            </button>

            <a href="users.php" class="btn btn-secondary">Cancel</a>
        </div>

    </form>

</section>

<?php endif; ?>

<!-- SEARCH AND FILTERS -->

<section class="filter-card">

    <form method="get" action="users.php" class="filter-form">

        <div class="filter-search">
            <label for="q">Search students</label>

            <input
                type="search"
                id="q"
                name="q"
                placeholder="Search by name, email, or course"
                value="<?= e($search) ?>"
            >
        </div>

        <div class="filter-field">
            <label for="status">Account status</label>

            <select id="status" name="status">
                <option value="">All statuses</option>
                <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>
                    Active
                </option>
                <option value="Suspended" <?= $statusFilter === 'Suspended' ? 'selected' : '' ?>>
                    Suspended
                </option>
            </select>
        </div>

        <div class="filter-field">
            <label for="course_filter">Course</label>

            <select id="course_filter" name="course">
                <option value="">All courses</option>

                <?php foreach ($courses as $course): ?>
                    <option
                        value="<?= e($course) ?>"
                        <?= $courseFilter === $course ? 'selected' : '' ?>
                    >
                        <?= e($course) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </div>

        <div class="filter-field">
            <label for="sort">Sort by</label>

            <select id="sort" name="sort">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>
                    Newest first
                </option>

                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>
                    Oldest first
                </option>

                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>
                    Name A-Z
                </option>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply filters</button>
            <a href="users.php" class="btn btn-secondary">Reset</a>
        </div>

    </form>

</section>

<!-- USERS TABLE -->

<section class="table-card">

    <div class="table-heading">
        <div>
            <h2>Student accounts</h2>
            <p><?= count($admin_users) ?> account(s) found</p>
        </div>
    </div>

    <div class="table-scroll">

        <table>

            <thead>
                <tr>
                    <th>Student</th>
                    <th>Email</th>
                    <th>Course</th>
                    <th>Year level</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($admin_users as $u): ?>

                    <?php
                    $photo = !empty($u['photo_path'])
                        ? '../' . ltrim($u['photo_path'], '/')
                        : '';
                    ?>

                    <tr>

                        <td>
                            <div class="student-cell">

                                <div class="student-avatar">
                                    <?php if ($photo !== ''): ?>
                                        <img
                                            src="<?= e($photo) ?>"
                                            alt="Student profile photo"
                                        >
                                    <?php else: ?>
                                        <?= e(initials($u['full_name'])) ?>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <strong><?= e($u['full_name']) ?></strong>
                                    <small>#<?= (int) $u['id'] ?></small>
                                </div>

                            </div>
                        </td>

                        <td><?= e($u['email']) ?></td>

                        <td><?= e($u['course']) ?></td>

                        <td><?= e($u['year_level']) ?></td>

                        <td>
                            <span class="status <?= e(status_class($u['status'])) ?>">
                                <?= e($u['status']) ?>
                            </span>
                        </td>

                        <td>
                            <div class="action-buttons">

                                <a
                                    href="user-view.php?id=<?= (int) $u['id'] ?>"
                                    class="btn btn-small btn-secondary"
                                >
                                    View
                                </a>

                                <a
                                    href="users.php?action=edit&id=<?= (int) $u['id'] ?>"
                                    class="btn btn-small btn-edit"
                                >
                                    Edit
                                </a>

                                <form
                                    method="post"
                                    action="users.php"
                                    onsubmit="return confirm('Are you sure you want to <?= $u['status'] === 'Active' ? 'suspend' : 'reactivate' ?> this account?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken) ?>"
                                    >

                                    <input type="hidden" name="action" value="change_status">

                                    <input
                                        type="hidden"
                                        name="user_id"
                                        value="<?= (int) $u['id'] ?>"
                                    >

                                    <?php if ($u['status'] === 'Active'): ?>

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="Suspended"
                                        >

                                        <button type="submit" class="btn btn-small btn-danger">
                                            Suspend
                                        </button>

                                    <?php else: ?>

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="Active"
                                        >

                                        <button type="submit" class="btn btn-small btn-restore">
                                            Reactivate
                                        </button>

                                    <?php endif; ?>

                                </form>

                            </div>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (empty($admin_users)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            <?php if ($search !== '' || $statusFilter !== '' || $courseFilter !== ''): ?>
                                No student accounts match your filters.
                            <?php else: ?>
                                No student accounts found.
                            <?php endif; ?>
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
