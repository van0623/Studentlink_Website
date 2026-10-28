<?php
session_start();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

$errors = [];

/* =============================
   CHECK LOGIN FORM
============================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    /* Validate required fields */
    if ($email === '') {
        $errors[] = 'Email address is required.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    /* Validate email */
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    /* Check account credentials */
    if (empty($errors)) {

        $sql = "SELECT id, full_name, email, password_hash, role, status
                FROM users
                WHERE email = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('Login query preparation failed: ' . $conn->error);
            $errors[] = 'Unable to sign in right now. Please try again.';
        } else {

            $stmt->bind_param('s', $email);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            $stmt->close();

            if (
                $user &&
                !empty($user['password_hash']) &&
                password_verify($password, $user['password_hash'])
            ) {

                if ($user['status'] !== 'Active') {

                    $errors[] = 'Your account is suspended. Please contact the administrator.';

                } else {

                    /* Start a fresh session */
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user'] = $user['email'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];

                    /* Redirect based on account role */
                    if ($user['role'] === 'admin') {
                        header('Location: admin/admin-dashboard.php');
                    } else {
                        header('Location: dashboard.php');
                    }

                    exit;
                }

            } else {
                $errors[] = 'Invalid email address or password.';
            }
        }
    }
}

/* =============================
   PAGE SETTINGS
============================= */
$title = 'Sign in';
$bare = true;

include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="css/login.css">

<main class="auth">

    <!-- LOGIN FORM -->
    <section class="auth-form">

        <h1>Sign in to StudentLink</h1>

        <p>Welcome back. Enter your details below.</p>

        <?php if (!empty($errors)): ?>
            <div class="form-errors" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>

            <div class="field">
                <label for="email">Email address</label>

                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="email"
                    value="<?= e($_POST['email'] ?? '') ?>"
                    placeholder="Enter your email address"
                    required
                >
            </div>

            <div class="field">
                <label for="password">Password</label>

                <div class="pw">
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Enter your password"
                        required
                    >

                    <button
                        type="button"
                        id="togglePassword"
                        aria-label="Show password"
                    >
                        Show
                    </button>
                </div>
            </div>

            <a class="forgot" href="#">
                Forgot your password?
            </a>

            <button
                type="submit"
                class="btn btn-dark"
                style="margin-top: 18px;"
            >
                Log in
            </button>

        </form>

        <div class="or">or</div>

        <a class="btn btn-ghost" href="#">
            Sign in with Google
        </a>

        <p class="switch">
            No account yet?
            <a href="register.php">Create an account</a>
        </p>

    </section>

    <!-- FOUR-PANEL MOSAIC -->
    <section class="mosaic" aria-label="Discover StudentLink">

        <!-- PANEL 01 -->
        <div class="mosaic-panel panel-connect">
            <span class="panel-number">01 / CONNECT</span>
            <span class="panel-symbol" aria-hidden="true">↗</span>

            <div class="panel-content">
                <h2 class="panel-title">
                    FIND YOUR<br>
                    PEOPLE.
                </h2>

                <p class="panel-description">
                    Build connections with students who share your interests.
                </p>
            </div>
        </div>

        <!-- PANEL 02 -->
        <div class="mosaic-panel panel-learn">
            <span class="panel-number">02 / LEARN</span>
            <span class="panel-symbol" aria-hidden="true">✳</span>

            <div class="panel-content">
                <h2 class="panel-title">
                    GROW<br>
                    TOGETHER.
                </h2>

                <p class="panel-description">
                    Learn from your peers and exchange useful knowledge.
                </p>
            </div>
        </div>

        <!-- PANEL 03 -->
        <div class="mosaic-panel panel-share">
            <span class="panel-number">03 / SHARE</span>
            <span class="panel-symbol" aria-hidden="true">＋</span>

            <div class="panel-content">
                <h2 class="panel-title">
                    IDEAS<br>
                    MATTER.
                </h2>

                <p class="panel-description">
                    Share academic resources, skills, and opportunities.
                </p>
            </div>
        </div>

        <!-- PANEL 04 -->
        <div class="mosaic-panel panel-discover">
            <span class="panel-number">04 / DISCOVER</span>
            <span class="panel-symbol" aria-hidden="true">✦</span>

            <div class="panel-content">
                <h2 class="panel-title">
                    YOUR<br>
                    NEXT STEP.
                </h2>

                <p class="panel-description">
                    Discover new possibilities throughout your student journey.
                </p>
            </div>
        </div>

    </section>

</main>

<!-- PASSWORD VISIBILITY TOGGLE -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const password = document.getElementById('password');
    const toggle = document.getElementById('togglePassword');

    if (password && toggle) {
        toggle.addEventListener('click', function () {
            const isHidden = password.type === 'password';

            password.type = isHidden ? 'text' : 'password';
            toggle.textContent = isHidden ? 'Hide' : 'Show';

            toggle.setAttribute(
                'aria-label',
                isHidden ? 'Hide password' : 'Show password'
            );
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
