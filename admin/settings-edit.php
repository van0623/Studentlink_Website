
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/* =========================================
   VERIFY ADMIN ACCOUNT
========================================= */

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id, full_name, email, password_hash, role, status, photo_path
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $adminId);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (
    !$admin ||
    strtolower($admin['role']) !== 'admin' ||
    $admin['status'] !== 'Active'
) {
    session_unset();
    session_destroy();

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
   DEFAULT VALUES
========================================= */

$errors = [];

$name = $admin['full_name'];
$email = $admin['email'];

$currentPhoto = $admin['photo_path'] ?? '';
$success = false;

/* =========================================
   HANDLE FORM SUBMISSION
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $postedToken = $_POST['csrf_token'] ?? '';

    /* -------------------------------------
       CSRF VALIDATION
    ------------------------------------- */

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $errors[] = 'Your session has expired. Please refresh the page and try again.';
    }

    /* -------------------------------------
       NAME VALIDATION
    ------------------------------------- */

    if ($name === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($name) < 2) {
        $errors[] = 'Full name must contain at least 2 characters.';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Full name must not exceed 100 characters.';
    } elseif (!preg_match("/^[\p{L}\p{M}\s.'-]+$/u", $name)) {
        $errors[] = 'Full name contains invalid characters.';
    }

    /* -------------------------------------
       EMAIL VALIDATION
    ------------------------------------- */

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (strlen($email) > 150) {
        $errors[] = 'Email address must not exceed 150 characters.';
    }

    /* -------------------------------------
       CHECK DUPLICATE EMAIL
    ------------------------------------- */

    if (
        $email !== '' &&
        filter_var($email, FILTER_VALIDATE_EMAIL) &&
        strlen($email) <= 150
    ) {
        $emailStmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");

        $emailStmt->bind_param("si", $email, $adminId);
        $emailStmt->execute();

        $emailResult = $emailStmt->get_result();

        if ($emailResult->num_rows > 0) {
            $errors[] = 'This email address is already registered to another account.';
        }

        $emailStmt->close();
    }

    /* -------------------------------------
       PASSWORD VALIDATION
    ------------------------------------- */

    $changingPassword = (
        $currentPassword !== '' ||
        $newPassword !== '' ||
        $confirmPassword !== ''
    );

    if ($changingPassword) {

        if ($currentPassword === '') {
            $errors[] = 'Current password is required to change your password.';
        } elseif (!password_verify(
            $currentPassword,
            $admin['password_hash']
        )) {
            $errors[] = 'Your current password is incorrect.';
        }

        if ($newPassword === '') {
            $errors[] = 'Please enter your new password.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must contain at least 8 characters.';
        }

        if ($confirmPassword === '') {
            $errors[] = 'Please confirm your new password.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation password do not match.';
        }

        if (
            $newPassword !== '' &&
            $currentPassword !== '' &&
            password_verify($currentPassword, $admin['password_hash']) &&
            $newPassword === $currentPassword
        ) {
            $errors[] = 'Your new password must be different from your current password.';
        }
    }

    /* =====================================
       PROFILE PHOTO VALIDATION
    ===================================== */

    $newPhotoPath = $currentPhoto;
    $uploadedPhotoPath = '';

    if (
        isset($_FILES['profile_photo']) &&
        $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        $photo = $_FILES['profile_photo'];

        if ($photo['error'] !== UPLOAD_ERR_OK) {

            if ($photo['error'] === UPLOAD_ERR_INI_SIZE ||
                $photo['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'Profile photo must not exceed 5 MB.';
            } else {
                $errors[] = 'Unable to upload your profile photo. Please try again.';
            }

        } elseif ($photo['size'] > 5 * 1024 * 1024) {

            $errors[] = 'Profile photo must not exceed 5 MB.';

        } elseif (!is_uploaded_file($photo['tmp_name'])) {

            $errors[] = 'Invalid profile photo upload.';

        } else {

            $imageInfo = @getimagesize($photo['tmp_name']);
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($photo['tmp_name']);

            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (
                !$imageInfo ||
                !isset($allowedTypes[$mimeType]) ||
                $imageInfo['mime'] !== $mimeType
            ) {
                $errors[] = 'Only JPG, JPEG, PNG, and WEBP images are allowed.';
            } else {

                $uploadDirectory = __DIR__ . '/../uploads/profiles/';

                if (
                    !is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true)
                ) {
                    $errors[] = 'Unable to create the profile photo folder.';
                } else {

                    $fileName = bin2hex(random_bytes(16))
                        . '.'
                        . $allowedTypes[$mimeType];

                    $destination = $uploadDirectory . $fileName;

                    if (move_uploaded_file(
                        $photo['tmp_name'],
                        $destination
                    )) {
                        $uploadedPhotoPath = 'uploads/profiles/' . $fileName;
                        $newPhotoPath = $uploadedPhotoPath;
                    } else {
                        $errors[] = 'Unable to save your profile photo.';
                    }
                }
            }
        }
    }

    /* =====================================
       SAVE CHANGES
    ===================================== */

    if (empty($errors)) {

        if ($changingPassword) {

            $newPasswordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $updateStmt = $conn->prepare("
                UPDATE users
                SET
                    full_name = ?,
                    email = ?,
                    password_hash = ?,
                    photo_path = ?
                WHERE id = ?
            ");

            $updateStmt->bind_param(
                "ssssi",
                $name,
                $email,
                $newPasswordHash,
                $newPhotoPath,
                $adminId
            );

        } else {

            $updateStmt = $conn->prepare("
                UPDATE users
                SET
                    full_name = ?,
                    email = ?,
                    photo_path = ?
                WHERE id = ?
            ");

            $updateStmt->bind_param(
                "sssi",
                $name,
                $email,
                $newPhotoPath,
                $adminId
            );
        }

        if ($updateStmt->execute()) {

            $updateStmt->close();

            /* Delete old photo after successful database update */
            if (
                $uploadedPhotoPath !== '' &&
                !empty($currentPhoto) &&
                $currentPhoto !== $uploadedPhotoPath &&
                strpos($currentPhoto, 'uploads/profiles/') === 0
            ) {
                $oldPhotoFile = __DIR__ . '/../' . $currentPhoto;

                if (is_file($oldPhotoFile)) {
                    unlink($oldPhotoFile);
                }
            }

            /* Update session information */
            $_SESSION['full_name'] = $name;
            $_SESSION['user'] = $name;

            header('Location: settings.php?updated=1');
            exit;

        } else {

            error_log('Admin profile update error: ' . $updateStmt->error);

            $errors[] = 'Unable to update your profile. Please try again.';

            $updateStmt->close();

            /* Remove newly uploaded file if database update failed */
            if ($uploadedPhotoPath !== '') {
                $newFile = __DIR__ . '/../' . $uploadedPhotoPath;

                if (is_file($newFile)) {
                    unlink($newFile);
                }
            }
        }

    } else {

        /* Remove uploaded file if other form validation failed */
        if ($uploadedPhotoPath !== '') {
            $newFile = __DIR__ . '/../' . $uploadedPhotoPath;

            if (is_file($newFile)) {
                unlink($newFile);
            }
        }
    }
}

/* =========================================
   PROFILE PHOTO DISPLAY
========================================= */

$photoUrl = '';

if (!empty($currentPhoto)) {
    $photoUrl = '../' . ltrim($currentPhoto, '/');
}

$initials = '';

$nameParts = preg_split(
    '/\s+/',
    trim($name),
    -1,
    PREG_SPLIT_NO_EMPTY
);

foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}

if ($initials === '') {
    $initials = 'AD';
}

include __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="setting-edit.css">

<main class="admin-settings-edit-page">

    <header class="page-head">
        <div>
            <span class="settings-eyebrow">ACCOUNT SETTINGS</span>
            <h1>Edit Profile</h1>
            <p>Update your administrator information and password.</p>
        </div>
    </header>

    <form
        class="info edit"
        method="POST"
        action=""
        enctype="multipart/form-data"
        novalidate
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <!-- Validation errors -->
        <?php if (!empty($errors)): ?>

            <div class="form-errors" role="alert">
                <strong>Please correct the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <!-- Profile Photo -->
        <section class="edit-section photo-section">

            <h2>Profile Photo</h2>

            <p class="meta">
                Upload a photo to personalize your administrator account.
            </p>

            <div class="photo-upload-area">

                <div class="photo-preview" id="photoPreview">

                    <?php if ($photoUrl !== ''): ?>

                        <img
                            src="<?= e($photoUrl) ?>"
                            alt="Administrator profile photo"
                            id="profilePreviewImage"
                        >

                    <?php else: ?>

                        <span id="profileInitials">
                            <?= e($initials) ?>
                        </span>

                    <?php endif; ?>

                </div>

                <div class="photo-upload-details">

                    <label for="profile_photo" class="photo-upload-btn">
                        Choose Photo
                    </label>

                    <input
                        type="file"
                        id="profile_photo"
                        name="profile_photo"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        hidden
                    >

                    <p class="photo-file-name" id="photoFileName">
                        No new photo selected
                    </p>

                    <small>
                        JPG, PNG, or WEBP. Maximum file size: 5 MB.
                    </small>

                </div>

            </div>

        </section>

        <!-- Personal Information -->
        <section class="edit-section">

            <h2>Personal Information</h2>

            <div class="field">
                <label for="name">Full Name</label>

                <input
                    id="name"
                    name="name"
                    type="text"
                    value="<?= e($name) ?>"
                    maxlength="100"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Email Address</label>

                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= e($email) ?>"
                    maxlength="150"
                    required
                >
            </div>

            <div class="field">
                <label for="role">Account Role</label>

                <input
                    id="role"
                    type="text"
                    value="Administrator"
                    disabled
                >
            </div>

        </section>

        <!-- Password Information -->
        <section class="edit-section">

            <h2 id="password">Change Password</h2>

            <p class="meta">
                Leave all password fields blank if you want to keep your current password.
            </p>

            <div class="field">
                <label for="cp">Current Password</label>

                <input
                    id="cp"
                    name="current_password"
                    type="password"
                    autocomplete="current-password"
                >
            </div>

            <div class="row">

                <div class="field">
                    <label for="np">New Password</label>

                    <input
                        id="np"
                        name="new_password"
                        type="password"
                        minlength="8"
                        autocomplete="new-password"
                    >

                    <small>At least 8 characters.</small>
                </div>

                <div class="field">
                    <label for="cf">Confirm New Password</label>

                    <input
                        id="cf"
                        name="confirm_password"
                        type="password"
                        minlength="8"
                        autocomplete="new-password"
                    >
                </div>

            </div>

        </section>

        <!-- Form Actions -->
        <div class="actions">

            <a class="btn btn-quiet" href="settings.php">
                Cancel
            </a>

            <button class="btn btn-primary" type="submit">
                Save Changes
            </button>

        </div>

    </form>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const photoInput = document.getElementById('profile_photo');
    const photoPreview = document.getElementById('photoPreview');
    const photoFileName = document.getElementById('photoFileName');

    if (!photoInput || !photoPreview || !photoFileName) {
        return;
    }

    photoInput.addEventListener('change', function () {

        const file = this.files[0];

        if (!file) {
            photoFileName.textContent = 'No new photo selected';
            return;
        }

        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!allowedTypes.includes(file.type)) {
            alert('Please choose a JPG, PNG, or WEBP image.');
            this.value = '';
            photoFileName.textContent = 'No new photo selected';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('Profile photo must not exceed 5 MB.');
            this.value = '';
            photoFileName.textContent = 'No new photo selected';
            return;
        }

        photoFileName.textContent = file.name;

        const reader = new FileReader();

        reader.onload = function (event) {

            photoPreview.innerHTML = '';

            const image = document.createElement('img');

            image.src = event.target.result;
            image.alt = 'New profile photo preview';
            image.id = 'profilePreviewImage';

            photoPreview.appendChild(image);
        };

        reader.readAsDataURL(file);
    });

});
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
