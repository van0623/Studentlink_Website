
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

/* Verify the current account */
$userStmt = $conn->prepare(
    "SELECT role, status
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$userStmt->bind_param('i', $userId);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$currentUser || $currentUser['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($currentUser['role'] !== 'student') {
    header('Location: admin/dashboard.php');
    exit;
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Get resource ID */
$resourceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resourceId = filter_input(
        INPUT_POST,
        'resource_id',
        FILTER_VALIDATE_INT
    );
}

if (!$resourceId || $resourceId < 1) {
    header('Location: resources.php?message=edit_failed');
    exit;
}

/* Load only an active resource owned by this user */
$stmt = $conn->prepare(
    "SELECT
        id,
        user_id,
        subject_id,
        title,
        resource_type,
        description,
        file_name,
        file_type,
        file_size
     FROM resources
     WHERE id = ?
       AND user_id = ?
       AND status = 'Active'
     LIMIT 1"
);

$stmt->bind_param('ii', $resourceId, $userId);
$stmt->execute();

$resource = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resource) {
    header('Location: resources.php?message=edit_failed');
    exit;
}

/* Load subjects */
$subjects = [];

$subjectResult = $conn->query(
    "SELECT id, name
     FROM subjects
     ORDER BY name ASC"
);

while ($subject = $subjectResult->fetch_assoc()) {
    $subjects[] = $subject;
}

/* Form values */
$titleValue = $resource['title'];
$resourceType = $resource['resource_type'] ?? '';
$description = $resource['description'] ?? '';
$subjectId = (int) $resource['subject_id'];

$errors = [];

/* =========================================
   HANDLE FORM SUBMISSION
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        header('Location: resources.php?message=invalid_token');
        exit;
    }

    $titleValue = trim($_POST['title'] ?? '');
    $resourceType = trim($_POST['resource_type'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $subjectId = filter_var(
        $_POST['subject_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    /* Validate title */
    if ($titleValue === '') {
        $errors[] = 'Resource title is required.';
    } elseif (mb_strlen($titleValue) > 255) {
        $errors[] = 'Resource title must not exceed 255 characters.';
    }

    /* Validate resource type */
    if ($resourceType === '') {
        $errors[] = 'Resource type is required.';
    } elseif (mb_strlen($resourceType) > 100) {
        $errors[] = 'Resource type must not exceed 100 characters.';
    }

    /* Validate description */
    if (mb_strlen($description) > 10000) {
        $errors[] = 'Description must not exceed 10000 characters.';
    }

    /* Validate subject */
    $validSubject = false;

    if ($subjectId !== false && $subjectId > 0) {
        $subjectCheck = $conn->prepare(
            "SELECT id
             FROM subjects
             WHERE id = ?
             LIMIT 1"
        );

        $subjectCheck->bind_param('i', $subjectId);
        $subjectCheck->execute();

        $validSubject = (bool) $subjectCheck
            ->get_result()
            ->fetch_assoc();

        $subjectCheck->close();
    }

    if (!$validSubject) {
        $errors[] = 'Please select a valid subject.';
    }

    /* Save changes */
    if (empty($errors)) {
        try {
            $update = $conn->prepare(
                "UPDATE resources
                 SET title = ?,
                     subject_id = ?,
                     resource_type = ?,
                     description = ?
                 WHERE id = ?
                   AND user_id = ?
                   AND status = 'Active'"
            );

            $update->bind_param(
                'sissii',
                $titleValue,
                $subjectId,
                $resourceType,
                $description,
                $resourceId,
                $userId
            );

            $update->execute();

            if ($update->affected_rows < 0) {
                throw new RuntimeException(
                    'The resource could not be updated.'
                );
            }

            $update->close();
            $conn->close();

            header('Location: resources.php?message=updated');
            exit;

        } catch (Throwable $e) {
            error_log('Resource edit error: ' . $e->getMessage());
            $errors[] = 'Unable to save changes. Please try again.';
        }
    }
}

/* Page settings */
$title = 'Edit Resource';
$active = 'resources';
$css = ['resources'];

require_once __DIR__ . '/includes/header.php';
?>

<main class="resources-page">

    <section class="resources-intro">
        <div class="resources-intro-content">
            <span class="resources-eyebrow">MANAGE YOUR RESOURCE</span>
            <h1>Edit <span>Resource.</span></h1>
            <p>Update your resource information below.</p>
        </div>
    </section>

    <section class="resources-library">

        <div class="resources-section-heading">
            <div>
                <span class="resources-eyebrow">RESOURCE DETAILS</span>
                <h2>Edit resource information</h2>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="notice error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form
            class="resource-edit-form"
            method="post"
            action="resource-edit.php?id=<?= (int) $resourceId ?>"
        >

            <input
                type="hidden"
                name="resource_id"
                value="<?= (int) $resourceId ?>"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
            >

            <div class="field">
                <label for="title">Resource title</label>
                <input
                    type="text"
                    id="title"
                    name="title"
                    maxlength="255"
                    value="<?= htmlspecialchars($titleValue, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
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
                            <?= htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="resource_type">Resource type</label>
                <input
                    type="text"
                    id="resource_type"
                    name="resource_type"
                    maxlength="100"
                    value="<?= htmlspecialchars($resourceType, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea
                    id="description"
                    name="description"
                    rows="5"
                    maxlength="10000"
                ><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="field">
                <label>Current uploaded file</label>
                <p>
                    <?= htmlspecialchars(
                        $resource['file_name'] ?? 'Uploaded file',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
                <p>
                    File type:
                    <?= htmlspecialchars(
                        $resource['file_type'] ?? 'Unknown',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>
                <p>
                    The uploaded file will remain unchanged when you save.
                </p>
            </div>

            <div class="actions">
                <a
                    class="btn btn-quiet"
                    href="resource-view.php?id=<?= (int) $resourceId ?>"
                >
                    Cancel
                </a>

                <button class="btn btn-primary" type="submit">
                    Save Changes
                </button>
            </div>

        </form>

    </section>

</main>

<?php
require_once __DIR__ . '/includes/footer.php';

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
