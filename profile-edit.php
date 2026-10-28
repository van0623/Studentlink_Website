
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Only logged-in users can edit their profile */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = '';

/* Create a CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Fetch the current user's real profile data */
$stmt = $conn->prepare("
    SELECT id, full_name, email, course, year_level,
           about, skills, photo_path, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile || $profile['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}

/* Prepare profile values for the form */
$user = [
    'name'    => $profile['full_name'] ?? '',
    'email'   => $profile['email'] ?? '',
    'program' => $profile['course'] ?? '',
    'year'    => $profile['year_level'] ?? '',
    'about'   => $profile['about'] ?? '',
    'skills'  => !empty($profile['skills'])
        ? array_filter(array_map('trim', explode(',', $profile['skills'])))
        : [],
    'photo'   => $profile['photo_path'] ?? ''
];

/* Save profile changes */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Your session verification failed. Please refresh the page and try again.';
    }

    $name = trim($_POST['name'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $about = trim($_POST['about'] ?? '');
    $skillsInput = trim($_POST['skills'] ?? '');

    /* Validate required fields */
    if ($name === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Full name must not exceed 100 characters.';
    }

    if ($program === '') {
        $errors[] = 'Course or program is required.';
    } elseif (mb_strlen($program) > 255) {
        $errors[] = 'Course or program is too long.';
    }

    if ($year === '') {
        $errors[] = 'Year level is required.';
    } elseif (mb_strlen($year) > 50) {
        $errors[] = 'Year level must not exceed 50 characters.';
    }

    /* Limit optional text fields */
    if (mb_strlen($about) > 5000) {
        $errors[] = 'About me must not exceed 5,000 characters.';
    }

    if (mb_strlen($skillsInput) > 2000) {
        $errors[] = 'Skills and interests must not exceed 2,000 characters.';
    }

    /* Validate the uploaded photo, if provided */
    $newPhotoPath = $user['photo'];
    $uploadedPhotoPath = null;

    if (
        isset($_FILES['photo']) &&
        $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        $photo = $_FILES['photo'];

        if ($photo['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The photo upload failed. Please try again.';
        } elseif ($photo['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The profile photo must be 5 MB or smaller.';
        } else {
            $imageInfo = @getimagesize($photo['tmp_name']);

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($photo['tmp_name']);

            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];

            if (
                !$imageInfo ||
                !isset($allowedTypes[$mimeType])
            ) {
                $errors[] = 'Please upload a valid JPG, PNG, or WEBP image.';
            } else {
                $uploadDirectory = __DIR__ . '/uploads/profiles/';

                if (
                    !is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true) &&
                    !is_dir($uploadDirectory)
                ) {
                    $errors[] = 'The profile photo folder could not be created.';
                } elseif (!is_writable($uploadDirectory)) {
                    $errors[] = 'The profile photo folder is not writable.';
                } else {
                    $fileName = bin2hex(random_bytes(16))
                        . '.'
                        . $allowedTypes[$mimeType];

                    $destination = $uploadDirectory . $fileName;

                    if (!move_uploaded_file($photo['tmp_name'], $destination)) {
                        $errors[] = 'Unable to save the uploaded photo.';
                    } else {
                        $uploadedPhotoPath = 'uploads/profiles/' . $fileName;
                        $newPhotoPath = $uploadedPhotoPath;
                    }
                }
            }
        }
    }

    /* Update the database only when validation succeeds */
    if (!$errors) {
        $skills = implode(', ', array_filter(
            array_map('trim', explode(',', $skillsInput)),
            function ($skill) {
                return $skill !== '';
            }
        ));

        $stmt = $conn->prepare("
            UPDATE users
            SET full_name = ?,
                course = ?,
                year_level = ?,
                about = ?,
                skills = ?,
                photo_path = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            'ssssssi',
            $name,
            $program,
            $year,
            $about,
            $skills,
            $newPhotoPath,
            $userId
        );

        if ($stmt->execute()) {
            $stmt->close();

            /* Refresh session information for the shared header */
            $_SESSION['full_name'] = $name;
            $_SESSION['user'] = $name;

            header('Location: profile.php?message=updated');
            exit;
        } else {
            $errors[] = 'Unable to update your profile. Please try again.';
            $stmt->close();

            /* Remove the new upload if the database update failed */
            if ($uploadedPhotoPath !== null) {
                $newFile = __DIR__ . '/' . $uploadedPhotoPath;

                if (is_file($newFile)) {
                    unlink($newFile);
                }
            }
        }
    }
}

/* Page settings */
$title = 'Edit profile';
$active = 'profile';
$css = ['profile-edit'];

include __DIR__ . '/includes/header.php';

/* Keep entered values when validation fails */
$formName = $_POST['name'] ?? $user['name'];
$formProgram = $_POST['program'] ?? $user['program'];
$formYear = $_POST['year'] ?? $user['year'];
$formAbout = $_POST['about'] ?? $user['about'];
$formSkills = $_POST['skills'] ?? implode(', ', $user['skills']);
?>

<main class="wrap profile-edit-page">

    <a class="back" href="profile.php" aria-label="Back to profile">
        &larr; Back to profile
    </a>

    <section class="edit-intro">
        <div>
            <p class="eyebrow">YOUR ACCOUNT</p>
            <h1>Edit your profile</h1>
            <p>Keep your information up to date so other students can get to know you.</p>
        </div>
    </section>

    <form
        class="form-card"
        method="post"
        enctype="multipart/form-data"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <?php if (!empty($errors)): ?>
            <div class="form-errors" role="alert">
                <strong>Please check the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="edit-section">
            <div class="section-heading">
                <span class="section-number">01</span>
                <div>
                    <h2>Profile photo</h2>
                    <p>Use a clear photo so classmates can recognize you.</p>
                </div>
            </div>

            <div class="photo-row">
                <?php if (!empty($user['photo'])): ?>
                    <img
                        class="avatar-lg profile-preview"
                        src="<?= e($user['photo']) ?>"
                        alt="Current profile photo"
                    >
                <?php else: ?>
                    <div class="avatar-lg">
                        <?= e(initials($user['name'])) ?>
                    </div>
                <?php endif; ?>

                <div class="field photo-field">
                    <label for="photo">Upload a new photo</label>
                    <input
                        id="photo"
                        name="photo"
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    >
                    <small>JPG, PNG, or WEBP. Maximum file size: 5 MB.</small>
                </div>
            </div>
        </section>

        <section class="edit-section">
            <div class="section-heading">
                <span class="section-number">02</span>
                <div>
                    <h2>Personal information</h2>
                    <p>Make sure your basic information is correct.</p>
                </div>
            </div>

            <div class="field">
                <label for="name">Full name <span>*</span></label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    maxlength="100"
                    value="<?= e($formName) ?>"
                    placeholder="Enter your full name"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Email address</label>
                <input
                    id="email"
                    type="email"
                    value="<?= e($user['email']) ?>"
                    readonly
                    aria-describedby="email-note"
                >
                <small id="email-note">Your email address cannot be changed here.</small>
            </div>

            <div class="row">
                <div class="field">
                    <label for="program">Course or program <span>*</span></label>
                    <input
                        id="program"
                        name="program"
                        type="text"
                        maxlength="255"
                        value="<?= e($formProgram) ?>"
                        placeholder="e.g. BS Information Technology"
                        required
                    >
                </div>

                <div class="field">
                    <label for="year">Year level <span>*</span></label>
                    <input
                        id="year"
                        name="year"
                        type="text"
                        maxlength="50"
                        value="<?= e($formYear) ?>"
                        placeholder="e.g. 3rd Year"
                        required
                    >
                </div>
            </div>
        </section>

        <section class="edit-section">
            <div class="section-heading">
                <span class="section-number">03</span>
                <div>
                    <h2>About you</h2>
                    <p>Tell other students a little about yourself.</p>
                </div>
            </div>

            <div class="field">
                <label for="about">About me</label>
                <textarea
                    id="about"
                    name="about"
                    rows="5"
                    maxlength="5000"
                    placeholder="Write a short introduction about yourself..."
                ><?= e($formAbout) ?></textarea>
                <small>Share your interests, goals, or what you are currently learning.</small>
            </div>

            <div class="field">
                <label for="skills">Skills and interests</label>
                <input
                    id="skills"
                    name="skills"
                    type="text"
                    maxlength="2000"
                    value="<?= e($formSkills) ?>"
                    placeholder="e.g. HTML, CSS, PHP, Graphic Design"
                >
                <small>Separate each skill or interest with a comma.</small>
            </div>
        </section>

        <div class="actions">
            <a class="btn btn-quiet" href="profile.php">Cancel</a>
            <button class="btn btn-primary" type="submit">
                Save changes
            </button>
        </div>
    </form>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
