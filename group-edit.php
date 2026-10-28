
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

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Validate group ID */
$groupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
}

if (!$groupId || $groupId < 1) {
    header('Location: groups.php?message=edit_failed');
    exit;
}

/* Load only a group created by this user */
$stmt = $conn->prepare(
    "SELECT
        id,
        creator_id,
        name,
        description,
        subject_id,
        year_level,
        max_members
     FROM study_groups
     WHERE id = ?
       AND creator_id = ?
       AND status = 'Active'
     LIMIT 1"
);

$stmt->bind_param('ii', $groupId, $userId);
$stmt->execute();

$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    header('Location: groups.php?message=edit_failed');
    exit;
}

/* Load subjects */
$subjects = [];

$subjectQuery = $conn->query(
    "SELECT id, name FROM subjects ORDER BY name ASC"
);

if ($subjectQuery) {
    while ($subject = $subjectQuery->fetch_assoc()) {
        $subjects[] = $subject;
    }
}

$errors = [];

/* Default form values */
$name = $group['name'];
$description = $group['description'] ?? '';
$subjectId = (int) $group['subject_id'];
$yearLevel = $group['year_level'] ?? '';
$maxMembers = (int) $group['max_members'];

/* =========================================
   HANDLE FORM SUBMISSION
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        header('Location: groups.php?message=invalid_token');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subjectId = filter_var(
        $_POST['subject_id'] ?? '',
        FILTER_VALIDATE_INT
    );
    $yearLevel = trim($_POST['year_level'] ?? '');
    $maxMembers = filter_var(
        $_POST['max_members'] ?? '',
        FILTER_VALIDATE_INT
    );

    /* Validate group name */
    if ($name === '') {
        $errors[] = 'Group name is required.';
    } elseif (mb_strlen($name) > 120) {
        $errors[] = 'Group name must not exceed 120 characters.';
    }

    /* Validate description */
    if (mb_strlen($description) > 5000) {
        $errors[] = 'Description must not exceed 5000 characters.';
    }

    /* Validate subject */
    $validSubject = false;

    if ($subjectId !== false && $subjectId > 0) {
        $subjectCheck = $conn->prepare(
            "SELECT id FROM subjects WHERE id = ? LIMIT 1"
        );

        $subjectCheck->bind_param('i', $subjectId);
        $subjectCheck->execute();

        $validSubject = (bool) $subjectCheck->get_result()->fetch_assoc();
        $subjectCheck->close();
    }

    if (!$validSubject) {
        $errors[] = 'Please select a valid subject.';
    }

    /* Validate year level */
    if (mb_strlen($yearLevel) > 50) {
        $errors[] = 'Year level must not exceed 50 characters.';
    }

    /* Validate maximum members */
    if (
        $maxMembers === false ||
        $maxMembers < 1 ||
        $maxMembers > 500
    ) {
        $errors[] = 'Maximum members must be between 1 and 500.';
    }

    /*
     * Prevent setting the group capacity below the
     * number of members who have already joined.
     */
    if ($maxMembers !== false && $maxMembers >= 1) {
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM group_members
             WHERE group_id = ?"
        );

        $countStmt->bind_param('i', $groupId);
        $countStmt->execute();

        $countRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();

        $currentMembers = (int) ($countRow['total'] ?? 0);

        if ($maxMembers < $currentMembers) {
            $errors[] =
                'Maximum members cannot be lower than the current number of group members (' .
                $currentMembers . ').';
        }
    }

    /* Update group */
    if (empty($errors)) {
        try {
            $update = $conn->prepare(
                "UPDATE study_groups
                 SET name = ?,
                     description = ?,
                     subject_id = ?,
                     year_level = ?,
                     max_members = ?
                 WHERE id = ?
                   AND creator_id = ?
                   AND status = 'Active'"
            );

            $update->bind_param(
                'ssissii',
                $name,
                $description,
                $subjectId,
                $yearLevel,
                $maxMembers,
                $groupId,
                $userId
            );

            $update->execute();
            $update->close();

            $conn->close();

            header('Location: groups.php?message=updated');
            exit;

        } catch (Throwable $e) {
            error_log('Edit study group error: ' . $e->getMessage());
            $errors[] = 'Unable to update the group. Please try again.';
        }
    }
}

/* Page settings */
$title = 'Edit Study Group';
$active = 'groups';
$css = ['groups'];

require_once __DIR__ . '/includes/header.php';
?>

<main class="groups-page">

    <header class="groups-intro">
        <div class="groups-intro-content">
            <span class="groups-eyebrow">Manage your group</span>
            <h1>Edit <span>study group</span></h1>
            <p>Update your group's information and member capacity.</p>
        </div>
    </header>

    <section class="groups-library">

        <div class="groups-section-heading">
            <div>
                <span class="groups-eyebrow">Group settings</span>
                <h2>Edit group information</h2>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="notice error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="group-edit.php?id=<?= (int) $groupId ?>"
            class="group-edit-form"
        >

            <input
                type="hidden"
                name="group_id"
                value="<?= (int) $groupId ?>"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <div class="field">
                <label for="name">Group name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="120"
                    value="<?= e($name) ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea
                    id="description"
                    name="description"
                    rows="5"
                    maxlength="5000"
                ><?= e($description) ?></textarea>
            </div>

            <div class="field">
                <label for="subject_id">Subject</label>
                <select id="subject_id" name="subject_id" required>
                    <option value="">Select a subject</option>

                    <?php foreach ($subjects as $subject): ?>
                        <option
                            value="<?= (int) $subject['id'] ?>"
                            <?= (string) $subjectId === (string) $subject['id'] ? 'selected' : '' ?>
                        >
                            <?= e($subject['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="year_level">Year level</label>
                <input
                    type="text"
                    id="year_level"
                    name="year_level"
                    maxlength="50"
                    value="<?= e($yearLevel) ?>"
                    placeholder="Example: 3rd Year"
                >
            </div>

            <div class="field">
                <label for="max_members">Maximum members</label>
                <input
                    type="number"
                    id="max_members"
                    name="max_members"
                    min="1"
                    max="500"
                    value="<?= (int) $maxMembers ?>"
                    required
                >
                <p>
                    The maximum cannot be lower than the current number
                    of members.
                </p>
            </div>

            <div class="actions">
                <a
                    class="btn btn-quiet"
                    href="group-view.php?id=<?= (int) $groupId ?>"
                >
                    Cancel
                </a>

                <button class="btn btn-primary" type="submit">
                    Save changes
                </button>
            </div>

        </form>

    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
