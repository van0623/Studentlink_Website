<?php
session_start();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Only logged-in users can open this page */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$user = [
    'name' => $_SESSION['full_name'] ?? 'Student'   // header.php shows the avatar initials
];

$id = (int) ($_GET['id'] ?? 0);

/* Load the group, its member count, and whether this user is already in it */
$sql = "SELECT
            g.id,
            g.name,
            g.description,
            g.course,
            g.year_level,
            g.max_members,
            s.name AS subject,
            u.full_name AS created_by,
            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS members,
            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id AND user_id = ?) AS is_member
        FROM study_groups g
        JOIN subjects s ON s.id = g.subject_id
        JOIN users u    ON u.id = g.creator_id
        WHERE g.id = ?
          AND g.status = 'Active'";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $userId, $id);
$stmt->execute();
$g = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* Unknown or removed group: go back to the list */
if (!$g) {
    header('Location: groups.php');
    exit;
}

/* Membership information */
$members  = (int) $g['members'];
$maximum  = max(1, (int) $g['max_members']);
$isMember = (int) $g['is_member'] > 0;
$full     = $members >= $maximum;

/* Join the group. The query checks again that a seat is still free. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isMember && !$full) {
    $join = $conn->prepare(
        "INSERT IGNORE INTO group_members (group_id, user_id)
         SELECT g.id, ?
         FROM study_groups g
         WHERE g.id = ?
           AND g.status = 'Active'
           AND (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) < g.max_members"
    );
    $join->bind_param('ii', $userId, $id);
    $join->execute();
    $joined = $join->affected_rows > 0;
    $join->close();

    header('Location: group-view.php?id=' . $id . ($joined ? '&joined=1' : '&full=1'));
    exit;
}
$percentage = min(100, round($members / $maximum * 100));

$title = 'Study Group';
$active = 'groups';
$css = ['group-view'];

require_once __DIR__ . '/includes/header.php';
?>

<main class="wrap">

    <!-- Back navigation -->
    <a class="back" href="groups.php" aria-label="Back to groups">&larr; Back to groups</a>

    <!-- Notification messages -->
    <?php if (isset($_GET['joined'])): ?>
        <div class="notice" role="status">You joined this group.</div>
    <?php elseif (isset($_GET['full'])): ?>
        <div class="notice error" role="alert">Sorry, this group just filled up.</div>
    <?php endif; ?>

    <section class="detail" aria-label="Study group details">

        <!-- Group information -->
        <article class="stack">

            <header class="info lead">
                <h1><?= e($g['name']) ?></h1>
                <p><?= e($g['subject']) ?></p>
            </header>

            <section class="info">
                <h2>Description</h2>
                <p><?= e($g['description'] ?: 'No description yet.') ?></p>
            </section>

            <section class="info">
                <h2><?= $members ?> of <?= $maximum ?> members</h2>

                <div class="meter" role="progressbar" aria-label="Group capacity"
                     aria-valuemin="0" aria-valuemax="<?= $maximum ?>"
                     aria-valuenow="<?= min($members, $maximum) ?>">
                    <i style="width: <?= $percentage ?>%"></i>
                </div>

                <p style="margin-top: 8px;">
                    Course: <?= e($g['course'] ?: 'Not set') ?>
                    &bull;
                    Year level: <?= e($g['year_level'] ?: 'Not set') ?>
                </p>
            </section>

            <section class="info">
                <h2>Created by</h2>
                <p><?= e($g['created_by']) ?></p>
            </section>

            <!-- Join group -->
            <form class="actions" method="post" aria-label="Group membership">
                <?php if ($isMember): ?>
                    <button type="button" class="btn btn-primary" disabled>You are in this group</button>
                <?php elseif ($full): ?>
                    <button type="button" class="btn btn-primary" disabled>Group is full</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary">Join group</button>
                <?php endif; ?>
            </form>

        </article>

        <!-- Group chat and schedule panel (one wrapper so the text stays together) -->
        <aside class="panel" aria-label="Group chat and schedule">
            <div>
                <h2>Group chat and schedule</h2>
                <p>Group chat and schedule information will appear here.</p>
            </div>
        </aside>

    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>