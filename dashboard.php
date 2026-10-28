
<?php
session_start();

require_once __DIR__ . '/db_connect.php';

/* =========================================
   CHECK LOGIN
========================================= */

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* =========================================
   GET STUDENT INFORMATION FROM DATABASE
========================================= */

$stmt = $conn->prepare("
    SELECT id, full_name, email, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    error_log("Dashboard query preparation failed: " . $conn->error);
    exit('Unable to load your account. Please try again.');
}

$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

/* =========================================
   CHECK ACCOUNT STATUS
========================================= */

if (!$user || $user['status'] !== 'Active') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParams = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    session_destroy();

    header('Location: login.php');
    exit;
}

/* =========================================
   PREPARE STUDENT INFORMATION
========================================= */

$user['name'] = $user['full_name'];


if ($user['role'] !== 'student' && $user['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

/* =========================================
   SAVE STUDENT INFORMATION IN SESSION
========================================= */

$_SESSION['user'] = $user['email'];
$_SESSION['full_name'] = $user['full_name'];

$studentName = htmlspecialchars(
    $user['full_name'],
    ENT_QUOTES,
    'UTF-8'
);

/* =========================================
   PAGE SETTINGS
========================================= */

$title = 'Dashboard';
$active = 'dashboard';
$css = ['dashboard'];
$bare = false;

/* =========================================
   HEADER
========================================= */

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="css/dashboard.css">

<main class="wrap">

    <section class="hero">
        <small>Welcome back, <?= $studentName ?>!</small>

        <h1>Connect, share, and learn with fellow students.</h1>

        <p>
            Discover academic resources, connect with classmates,
            and explore opportunities within your student community.
        </p>
    </section>

    <section class="tiles" aria-label="Dashboard sections">

        <article class="tile">
            <h2>Academic Resources</h2>
            <p>Notes and reviewers shared by your classmates.</p>
            <a href="resources.php">Browse resources</a>
        </article>

        <article class="tile">
            <h2>Peer Services</h2>
            <p>Find students who can help with a task or skill.</p>
            <a href="services.php">Find services</a>
        </article>

        <article class="tile">
            <h2>Study Groups</h2>
            <p>Study and collaborate with students in your subjects.</p>
            <a href="groups.php">Find groups</a>
        </article>

        <article class="tile">
            <h2>Borrow and Share</h2>
            <p>Discover books, calculators, and other useful items.</p>
            <a href="items.php">Browse items</a>
        </article>

    </section>

    <!-- Recent Activity and Quick Links -->
    <section class="lower">

        <div class="info">
            <h2>Recent Activity</h2>

            <ul class="feed">
                <li>Welcome to StudentLink, <?= $studentName ?>!</li>
                <li>Explore academic resources shared by students.</li>
                <li>Connect with classmates and join study groups.</li>
            </ul>
        </div>

        <div class="info">
            <h2>Quick Links</h2>

            <ul class="links">
                <li>
                    <a href="resource-upload.php">Upload a resource</a>
                </li>
                <li>
                    <a href="service-offer.php">Offer a service</a>
                </li>
                <li>
                    <a href="group-create.php">Create a group</a>
                </li>
                <li>
                    <a href="item-post.php">Post an item</a>
                </li>
                <li>
                    <a href="activity.php">My activity</a>
                </li>
                <li>
                    <a href="transactions.php">My transactions</a>
                </li>
            </ul>
        </div>

    </section>

</main>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
