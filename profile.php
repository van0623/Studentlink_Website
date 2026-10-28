
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Fetch the logged-in student's profile */
$stmt = $conn->prepare("
    SELECT
        id,
        full_name,
        email,
        course,
        year_level,
        about,
        skills,
        photo_path,
        role,
        status,
        created_at
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

/* Update the name used by the shared header */
$_SESSION['full_name'] = $profile['full_name'];

/* Prepare skills */
$skills = [];

if (!empty($profile['skills'])) {
    $skills = array_values(array_filter(
        array_map('trim', explode(',', $profile['skills']))
    ));
}

/* Profile completion percentage */
$completionFields = [
    $profile['full_name'] ?? '',
    $profile['email'] ?? '',
    $profile['course'] ?? '',
    $profile['year_level'] ?? '',
    $profile['about'] ?? '',
    $profile['skills'] ?? '',
    $profile['photo_path'] ?? ''
];

$completedFields = 0;

foreach ($completionFields as $field) {
    if (trim((string) $field) !== '') {
        $completedFields++;
    }
}

$completion = (int) round(
    ($completedFields / count($completionFields)) * 100
);

/* Count records belonging to this student */
function studentProfileCount(mysqli $conn, string $sql, int $userId): int
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($result['total'] ?? 0);
}

$counts = [
    'Resources shared' => studentProfileCount(
        $conn,
        "SELECT COUNT(*) AS total FROM resources
         WHERE user_id = ? AND status = 'Active'",
        $userId
    ),

    'Services offered' => studentProfileCount(
        $conn,
        "SELECT COUNT(*) AS total FROM services
         WHERE user_id = ? AND status = 'Active'",
        $userId
    ),

    'Items listed' => studentProfileCount(
        $conn,
        "SELECT COUNT(*) AS total FROM items
         WHERE user_id = ? AND status = 'Active'",
        $userId
    ),

    'Groups created' => studentProfileCount(
        $conn,
        "SELECT COUNT(*) AS total FROM study_groups
         WHERE creator_id = ? AND status = 'Active'",
        $userId
    ),

    'Groups joined' => studentProfileCount(
        $conn,
        "SELECT COUNT(*) AS total FROM group_members gm
         INNER JOIN study_groups sg ON sg.id = gm.group_id
         WHERE gm.user_id = ? AND sg.status = 'Active'",
        $userId
    )
];

/* Format account creation date */
$memberSince = 'Not available';

if (!empty($profile['created_at'])) {
    $timestamp = strtotime($profile['created_at']);

    if ($timestamp !== false) {
        $memberSince = date('F Y', $timestamp);
    }
}

/* Page settings */
$title = 'My Profile';
$active = 'profile';
$css = ['profile'];

$userName = $profile['full_name'] ?: 'Student';
$course = $profile['course'] ?: 'Course not added';
$yearLevel = $profile['year_level'] ?: 'Year level not added';
$about = $profile['about'] ?: 'You have not written an introduction yet. Tell other students a little about yourself.';
$photo = trim((string) ($profile['photo_path'] ?? ''));

include __DIR__ . '/includes/header.php';
?>

<main class="profile-page">

    <div class="profile-heading">
        <div>
            <span class="profile-eyebrow">STUDENTLINK ACCOUNT</span>
            <h1>My Profile</h1>
            <p>Manage your information and see your activity in the community.</p>
        </div>

        <a class="profile-edit-main" href="profile-edit.php">
            <span aria-hidden="true">✎</span>
            Edit Profile
        </a>
    </div>

    <!-- Profile overview -->
    <section class="profile-hero">

        <div class="profile-identity">
            <?php if ($photo !== ''): ?>
                <img
                    class="profile-avatar"
                    src="<?= e($photo) ?>"
                    alt="<?= e($userName) ?>'s profile photo"
                >
            <?php else: ?>
                <div class="profile-avatar profile-avatar-initials">
                    <?= e(initials($userName)) ?>
                </div>
            <?php endif; ?>

            <div class="profile-identity-details">
                <div class="profile-name-line">
                    <h2><?= e($userName) ?></h2>
                    <span class="profile-status">
                        <span class="status-dot"></span>
                        <?= e($profile['status']) ?>
                    </span>
                </div>

                <p class="profile-course">
                    <?= e($course) ?>
                    <?php if (!empty($profile['year_level'])): ?>
                        <span class="profile-separator">·</span>
                        <?= e($yearLevel) ?>
                    <?php endif; ?>
                </p>

                <p class="profile-email"><?= e($profile['email']) ?></p>

                <div class="profile-member">
                    <span aria-hidden="true">▦</span>
                    Member since <?= e($memberSince) ?>
                </div>
            </div>
        </div>

        <div class="profile-completion">
            <div class="completion-top">
                <div>
                    <span class="completion-label">PROFILE COMPLETION</span>
                    <strong><?= $completion ?>%</strong>
                </div>

                <span class="completion-icon" aria-hidden="true">✓</span>
            </div>

            <div
                class="completion-track"
                role="progressbar"
                aria-label="Profile completion"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?= $completion ?>"
            >
                <div class="completion-fill" style="width: <?= $completion ?>%"></div>
            </div>

            <p>
                <?php if ($completion === 100): ?>
                    Your profile is complete. Nice work!
                <?php else: ?>
                    Complete your details to help other students get to know you.
                <?php endif; ?>
            </p>

            <?php if ($completion < 100): ?>
                <a href="profile-edit.php" class="completion-link">
                    Complete your profile <span aria-hidden="true">→</span>
                </a>
            <?php endif; ?>
        </div>

    </section>

    <!-- Activity statistics -->
    <section class="profile-section">
        <div class="section-heading">
            <div>
                <span class="profile-eyebrow">YOUR CONTRIBUTIONS</span>
                <h2>Community Activity</h2>
            </div>
            <p>Your current activity on StudentLink</p>
        </div>

        <div class="profile-stats">
            <?php
            $statIcons = [
                'Resources shared' => '▤',
                'Services offered' => '✳',
                'Items listed' => '◇',
                'Groups created' => '◎',
                'Groups joined' => '↗'
            ];
            ?>

            <?php foreach ($counts as $label => $number): ?>
                <article class="profile-stat">
                    <div class="stat-icon">
                        <?= e($statIcons[$label] ?? '•') ?>
                    </div>

                    <div class="stat-number"><?= (int) $number ?></div>
                    <div class="stat-label"><?= e($label) ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Profile information -->
    <section class="profile-details-grid">

        <div class="profile-panel">
            <div class="panel-heading">
                <div>
                    <span class="profile-eyebrow">GET TO KNOW ME</span>
                    <h2>About Me</h2>
                </div>
                <span class="panel-heading-icon" aria-hidden="true">✎</span>
            </div>

            <p class="profile-about-text"><?= nl2br(e($about)) ?></p>
        </div>

        <div class="profile-panel">
            <div class="panel-heading">
                <div>
                    <span class="profile-eyebrow">WHAT I CAN DO</span>
                    <h2>Skills and Interests</h2>
                </div>
                <span class="panel-heading-icon" aria-hidden="true">✳</span>
            </div>

            <?php if (!empty($skills)): ?>
                <div class="profile-skills">
                    <?php foreach ($skills as $skill): ?>
                        <span class="profile-skill"><?= e($skill) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="profile-empty">
                    <p>You have not added any skills yet.</p>
                    <a href="profile-edit.php">Add your skills →</a>
                </div>
            <?php endif; ?>
        </div>

    </section>

    <!-- Student information -->
    <section class="profile-panel profile-information">
        <div class="panel-heading">
            <div>
                <span class="profile-eyebrow">PERSONAL DETAILS</span>
                <h2>Student Information</h2>
            </div>

            <a class="profile-text-link" href="profile-edit.php">
                Update details →
            </a>
        </div>

        <div class="information-grid">
            <div class="information-item">
                <span class="information-icon" aria-hidden="true">@</span>
                <div>
                    <span class="information-label">Email Address</span>
                    <p><?= e($profile['email'] ?: 'Not provided') ?></p>
                </div>
            </div>

            <div class="information-item">
                <span class="information-icon" aria-hidden="true">▤</span>
                <div>
                    <span class="information-label">Course / Program</span>
                    <p><?= e($course) ?></p>
                </div>
            </div>

            <div class="information-item">
                <span class="information-icon" aria-hidden="true">▦</span>
                <div>
                    <span class="information-label">Year Level</span>
                    <p><?= e($yearLevel) ?></p>
                </div>
            </div>

            <div class="information-item">
                <span class="information-icon" aria-hidden="true">◷</span>
                <div>
                    <span class="information-label">Joined StudentLink</span>
                    <p><?= e($memberSince) ?></p>
                </div>
            </div>

            <div class="information-item">
                <span class="information-icon" aria-hidden="true">✓</span>
                <div>
                    <span class="information-label">Account Status</span>
                    <p><?= e($profile['status']) ?></p>
                </div>
            </div>

            <div class="information-item">
                <span class="information-icon" aria-hidden="true">⌂</span>
                <div>
                    <span class="information-label">Account Type</span>
                    <p><?= e(ucfirst($profile['role'] ?: 'Student')) ?></p>
                </div>
            </div>
        </div>
    </section>

    <div class="profile-bottom-action">
        <div>
            <h2>Keep your profile up to date</h2>
            <p>Update your course, skills, and introduction whenever something changes.</p>
        </div>

        <a class="profile-edit-main" href="profile-edit.php">
            Edit Profile <span aria-hidden="true">→</span>
        </a>
    </div>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
