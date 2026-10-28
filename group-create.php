
<?php
session_start();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Check if the user is logged in */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$errors = [];
$subjects = [];

/* Load subjects from the database */
$subjectQuery = $conn->query(
    "SELECT id, name FROM subjects ORDER BY name ASC"
);

if ($subjectQuery) {
    while ($subject = $subjectQuery->fetch_assoc()) {
        $subjects[] = $subject;
    }
} else {
    error_log('Load subjects error: ' . $conn->error);
    $errors[] = 'Unable to load subjects. Please try again.';
}

/* Process the form */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Check required fields */
    $errors = array_merge(
        $errors,
        missing([
            'name'    => 'Group name',
            'subject' => 'Subject',
            'year'    => 'Year level',
            'max'     => 'Maximum members'
        ])
    );

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subjectId = filter_var(
        $_POST['subject'] ?? '',
        FILTER_VALIDATE_INT
    );
    $yearLevel = trim($_POST['year'] ?? '');
    $maxMembers = filter_var(
        $_POST['max'] ?? '',
        FILTER_VALIDATE_INT
    );

    /* Validate group name */
    if ($name === '' && !in_array('Group name', $errors, true)) {
        $errors[] = 'Please enter a group name.';
    }

    /* Validate subject */
    $validSubject = false;

    foreach ($subjects as $subject) {
        if (
            $subjectId !== false &&
            $subjectId !== null &&
            (int) $subject['id'] === (int) $subjectId
        ) {
            $validSubject = true;
            break;
        }
    }

    if (!$validSubject) {
        $errors[] = 'Please select a valid subject.';
    }

    /* Validate year level */
    if ($yearLevel === '') {
        $errors[] = 'Please enter the year level.';
    }

    /* Validate maximum members */
    if ($maxMembers === false || $maxMembers < 2) {
        $errors[] = 'Maximum members must be at least 2.';
    }

    /* Save the group if there are no errors */
    if (empty($errors)) {

        try {
            $conn->begin_transaction();

            /* Insert the study group */
            $sql = "INSERT INTO study_groups
                        (
                            name,
                            description,
                            subject_id,
                            creator_id,
                            year_level,
                            max_members,
                            status
                        )
                    VALUES (?, ?, ?, ?, ?, ?, 'Active')";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                'ssiisi',
                $name,
                $description,
                $subjectId,
                $userId,
                $yearLevel,
                $maxMembers
            );

            $stmt->execute();
            $groupId = $conn->insert_id;
            $stmt->close();

            /* Add the creator as the first group member */
            $member = $conn->prepare(
                "INSERT INTO group_members (group_id, user_id)
                 VALUES (?, ?)"
            );

            $member->bind_param('ii', $groupId, $userId);
            $member->execute();
            $member->close();

            /* Save both database changes */
            $conn->commit();

            header('Location: groups.php?posted=1');
            exit;

        } catch (Throwable $e) {

            $conn->rollback();

            $errors[] = 'Unable to create the study group. Please try again.';

            error_log(
                'Create study group error: ' . $e->getMessage()
            );
        }
    }
}

/* Page settings */
$title = 'Create a study group';
$active = 'groups';
$css = ['group-create'];

include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="css/group-create.css">

<main class="wrap">

    <nav aria-label="Page navigation">
        <a
            class="back"
            href="groups.php"
            aria-label="Back to groups"
        >
            &larr; Back to groups
        </a>
    </nav>

    <section class="form-card">

        <header>
            <h1>Create a study group</h1>
            <p>
                Set the subject and size, and classmates can join.
            </p>
        </header>

        <?php show_errors($errors); ?>

        <form method="post">

            <!-- Group name -->
            <div class="field">
                <label for="name">Group name</label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    placeholder="Enter group name"
                    value="<?= e($_POST['name'] ?? '') ?>"
                    required
                >
            </div>

            <!-- Subject and year level -->
            <div class="row">

                <div class="field">
                    <label for="subject">Subject</label>

                    <select id="subject" name="subject" required>
                        <option value="">Select subject</option>

                        <?php foreach ($subjects as $subject): ?>
                            <option
                                value="<?= (int) $subject['id'] ?>"
                                <?= (string) ($_POST['subject'] ?? '') === (string) $subject['id'] ? 'selected' : '' ?>
                            >
                                <?= e($subject['name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field">
                    <label for="year">Year level</label>

                    <input
                        type="text"
                        id="year"
                        name="year"
                        placeholder="For example, 3rd Year"
                        value="<?= e($_POST['year'] ?? '') ?>"
                        required
                    >
                </div>

            </div>

            <!-- Group description -->
            <div class="field">
                <label for="description">Description</label>

                <textarea
                    id="description"
                    name="description"
                    placeholder="What will your study group focus on?"
                ><?= e($_POST['description'] ?? '') ?></textarea>
            </div>

            <!-- Maximum members -->
            <div class="field">
                <label for="max">Maximum members</label>

                <input
                    type="number"
                    id="max"
                    name="max"
                    min="2"
                    placeholder="For example, 10"
                    value="<?= e($_POST['max'] ?? '') ?>"
                    required
                >
            </div>

            <!-- Form buttons -->
            <div class="actions">

                <a class="btn btn-quiet" href="groups.php">
                    Cancel
                </a>

                <button type="submit" class="btn btn-primary">
                    Create group
                </button>

            </div>

        </form>

    </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
