
<?php
session_start();

require_once __DIR__ . '/db_connect.php';

$errors = [];

$fullName = "";
$email = "";
$course = "";
$yearLevel = "";
$about = "";
$skills = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $course = trim($_POST["course"] ?? "");
    $yearLevel = trim($_POST["year_level"] ?? "");
    $about = trim($_POST["about"] ?? "");
    $skills = trim($_POST["skills"] ?? "");

    if ($fullName === "") {
        $errors[] = "Please enter your full name.";
    }

    if ($email === "") {
        $errors[] = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (strlen($fullName) > 100) {
        $errors[] = "Full name must not exceed 100 characters.";
    }

    if (strlen($email) > 150) {
        $errors[] = "Email must not exceed 150 characters.";
    }

    if ($password === "") {
        $errors[] = "Please enter a password.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (strlen($course) > 50) {
        $errors[] = "Course must not exceed 50 characters.";
    }

    if (strlen($yearLevel) > 20) {
        $errors[] = "Year level must not exceed 20 characters.";
    }

    if (empty($errors)) {
        $checkStmt = $conn->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );

        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $errors[] = "This email is already registered.";
        }

        $checkStmt->close();
    }

    $photoPath = null;

    if (
        isset($_FILES["photo"]) &&
        $_FILES["photo"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
            $errors[] = "There was a problem uploading your profile picture.";
        } else {
            $maxSize = 2 * 1024 * 1024;

            if ($_FILES["photo"]["size"] > $maxSize) {
                $errors[] = "Profile picture must be 2 MB or smaller.";
            } else {
                $imageInfo = getimagesize($_FILES["photo"]["tmp_name"]);

                if ($imageInfo === false) {
                    $errors[] = "Please upload a valid image.";
                } else {
                    $allowedTypes = [
                        "image/jpeg" => "jpg",
                        "image/png" => "png",
                        "image/webp" => "webp"
                    ];

                    $mimeType = $imageInfo["mime"] ?? "";

                    if (!isset($allowedTypes[$mimeType])) {
                        $errors[] = "Only JPG, PNG, and WEBP images are allowed.";
                    } else {
                        $uploadDir = __DIR__ . "/uploads/profile_pictures/";

                        if (
                            !is_dir($uploadDir) &&
                            !mkdir($uploadDir, 0755, true)
                        ) {
                            $errors[] = "Unable to create the profile picture folder.";
                        } else {
                            $fileName = bin2hex(random_bytes(16))
                                . "."
                                . $allowedTypes[$mimeType];

                            $destination = $uploadDir . $fileName;

                            if (
                                move_uploaded_file(
                                    $_FILES["photo"]["tmp_name"],
                                    $destination
                                )
                            ) {
                                $photoPath = "uploads/profile_pictures/" . $fileName;
                            } else {
                                $errors[] = "Failed to save your profile picture.";
                            }
                        }
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        /*
         * Public registration is for students only.
         * The role is assigned on the server and is never taken from form input.
         */
        $studentRole = "student";

        $stmt = $conn->prepare(
            "INSERT INTO users
            (full_name, email, password_hash, course, year_level, about, skills, photo_path, role)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            error_log("Registration query preparation failed: " . $conn->error);
            $errors[] = "Registration failed. Please try again.";
        } else {
            $stmt->bind_param(
                "sssssssss",
                $fullName,
                $email,
                $passwordHash,
                $course,
                $yearLevel,
                $about,
                $skills,
                $photoPath,
                $studentRole
            );
        }

        if ($stmt) {
            if ($stmt->execute()) {
                $newUserId = $conn->insert_id;

                session_regenerate_id(true);

                $_SESSION["user_id"] = $newUserId;
                $_SESSION["user"] = $email;
                $_SESSION["full_name"] = $fullName;
                $_SESSION["role"] = "student";

                $stmt->close();
                $conn->close();

                header("Location: dashboard.php");
                exit;
            } else {
                if ($stmt->errno === 1062) {
                    $errors[] = "This email is already registered.";
                } else {
                    error_log("Registration error: " . $stmt->error);
                    $errors[] = "Registration failed. Please try again.";
                }
            }

            $stmt->close();
        }
    }
}

function registerEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account | StudentLink</title>
  <link rel="stylesheet" href="css/register.css">
</head>

<body>

<main class="auth">

    <!-- LEFT SIDE: REGISTRATION FORM -->
    <section class="auth-form">

        <a href="index.php" class="logo">StudentLink</a>

        <h1>Create your account</h1>
        <p>Join StudentLink and connect with fellow students.</p>

        <?php if (!empty($errors)): ?>
            <div class="form-errors" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= registerEscape($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" enctype="multipart/form-data">

            <div class="field">
                <label for="full_name">Full Name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    placeholder="Enter your full name"
                    maxlength="100"
                    value="<?= registerEscape($fullName) ?>"
                    autocomplete="name"
                    required
                >
            </div>

            <div class="field">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email address"
                    maxlength="150"
                    value="<?= registerEscape($email) ?>"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="field">
                <label for="course">Course</label>
                <select id="course" name="course">
                    <option value="">Select your course</option>
                    <option value="BSIT" <?= $course === "BSIT" ? "selected" : "" ?>>BS Information Technology (BSIT)</option>
                    <option value="BSCS" <?= $course === "BSCS" ? "selected" : "" ?>>BS Computer Science (BSCS)</option>
                    <option value="BSIS" <?= $course === "BSIS" ? "selected" : "" ?>>BS Information Systems (BSIS)</option>
                    <option value="BSEMC" <?= $course === "BSEMC" ? "selected" : "" ?>>BS Entertainment and Multimedia Computing (BSEMC)</option>
                    <option value="Other" <?= $course === "Other" ? "selected" : "" ?>>Other</option>
                </select>
            </div>

            <div class="field">
                <label for="year_level">Year Level</label>
                <select id="year_level" name="year_level">
                    <option value="">Select your year level</option>
                    <option value="1st Year" <?= $yearLevel === "1st Year" ? "selected" : "" ?>>1st Year</option>
                    <option value="2nd Year" <?= $yearLevel === "2nd Year" ? "selected" : "" ?>>2nd Year</option>
                    <option value="3rd Year" <?= $yearLevel === "3rd Year" ? "selected" : "" ?>>3rd Year</option>
                    <option value="4th Year" <?= $yearLevel === "4th Year" ? "selected" : "" ?>>4th Year</option>
                    <option value="5th Year" <?= $yearLevel === "5th Year" ? "selected" : "" ?>>5th Year</option>
                </select>
            </div>

            <div class="field">
                <label for="about">About Me</label>
                <textarea
                    id="about"
                    name="about"
                    placeholder="Tell us a little about yourself..."
                    rows="4"
                ><?= registerEscape($about) ?></textarea>
            </div>

            <div class="field">
                <label for="skills">Skills</label>
                <input
                    type="text"
                    id="skills"
                    name="skills"
                    placeholder="e.g., HTML, CSS, JavaScript"
                    maxlength="255"
                    value="<?= registerEscape($skills) ?>"
                >
            </div>

            <div class="field">
                <label for="photo">Profile Picture</label>
                <input
                    type="file"
                    id="photo"
                    name="photo"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                >
                <small>Optional. JPG, PNG, or WEBP, up to 2 MB.</small>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Create a password (at least 8 characters)"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Re-enter your password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit" class="btn-primary">
                Create Account
            </button>

        </form>

        <p class="auth-switch">
            Already have an account?
            <a href="login.php">Log in</a>
        </p>

    </section>

    <!-- RIGHT SIDE: FOUR-PANEL MOSAIC -->
    <section class="mosaic" aria-label="About StudentLink">

        <div>
            <span class="panel-number">01 / CONNECT</span>
            <span class="panel-symbol">↗</span>
            <div>
                <span class="panel-title">FIND<br>YOUR<br>PEOPLE.</span>
                <span class="panel-description">
                    Build connections with students who share your interests.
                </span>
            </div>
        </div>

        <div>
            <span class="panel-number">02 / LEARN</span>
            <span class="panel-symbol">✳</span>
            <div>
                <span class="panel-title">GROW<br>TOGETHER.</span>
                <span class="panel-description">
                    Learn from your peers and exchange useful knowledge.
                </span>
            </div>
        </div>

        <div>
            <span class="panel-number">03 / SHARE</span>
            <span class="panel-symbol">＋</span>
            <div>
                <span class="panel-title">IDEAS<br>MATTER.</span>
                <span class="panel-description">
                    Share academic resources, skills, and opportunities.
                </span>
            </div>
        </div>

        <div>
            <span class="panel-number">04 / DISCOVER</span>
            <span class="panel-symbol">✦</span>
            <div>
                <span class="panel-title">YOUR<br>NEXT STEP.</span>
                <span class="panel-description">
                    Discover new possibilities throughout your student journey.
                </span>
            </div>
        </div>

    </section>

</main>

</body>
</html>
