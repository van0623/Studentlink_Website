
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

/* Check current user */
$userStmt = $conn->prepare(
    "SELECT id, full_name
     FROM users
     WHERE id = ? AND status = 'Active'
     LIMIT 1"
);
$userStmt->bind_param('i', $userId);
$userStmt->execute();

$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/*
 * Count records.
 * This helper now supports multiple query parameters.
 */
function countUserRecords(
    mysqli $conn,
    string $sql,
    string $types,
    array $params
): int {
    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

/* Activity counts */
$activity = [
    'counts' => [
        'Resources shared' => countUserRecords(
            $conn,
            "SELECT COUNT(*) AS total
             FROM resources
             WHERE user_id = ? AND status = 'Active'",
            'i',
            [$userId]
        ),

        'Services offered' => countUserRecords(
            $conn,
            "SELECT COUNT(*) AS total
             FROM services
             WHERE user_id = ? AND status = 'Active'",
            'i',
            [$userId]
        ),

        'Items posted' => countUserRecords(
            $conn,
            "SELECT COUNT(*) AS total
             FROM items
             WHERE user_id = ? AND status = 'Active'",
            'i',
            [$userId]
        ),

        'Groups created' => countUserRecords(
            $conn,
            "SELECT COUNT(*) AS total
             FROM study_groups
             WHERE creator_id = ? AND status = 'Active'",
            'i',
            [$userId]
        ),

        'Groups joined' => countUserRecords(
            $conn,
            "SELECT COUNT(*) AS total
             FROM group_members gm
             INNER JOIN study_groups sg ON sg.id = gm.group_id
             WHERE gm.user_id = ?
               AND sg.status = 'Active'
               AND sg.creator_id != ?",
            'ii',
            [$userId, $userId]
        )
    ],
    'recent' => []
];

/* Recent resources */
$stmt = $conn->prepare(
    "SELECT title AS activity_name, created_at
     FROM resources
     WHERE user_id = ? AND status = 'Active'
     ORDER BY created_at DESC
     LIMIT 10"
);
$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $activity['recent'][] = [
        'text' => 'Shared a resource: ' . $row['activity_name'],
        'date' => $row['created_at']
    ];
}

$stmt->close();

/* Recent services */
$stmt = $conn->prepare(
    "SELECT title AS activity_name, created_at
     FROM services
     WHERE user_id = ? AND status = 'Active'
     ORDER BY created_at DESC
     LIMIT 10"
);
$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $activity['recent'][] = [
        'text' => 'Offered a service: ' . $row['activity_name'],
        'date' => $row['created_at']
    ];
}

$stmt->close();

/* Recent items */
$stmt = $conn->prepare(
    "SELECT name AS activity_name, created_at
     FROM items
     WHERE user_id = ? AND status = 'Active'
     ORDER BY created_at DESC
     LIMIT 10"
);
$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $activity['recent'][] = [
        'text' => 'Posted an item: ' . $row['activity_name'],
        'date' => $row['created_at']
    ];
}

$stmt->close();

/* Recent groups created */
$stmt = $conn->prepare(
    "SELECT name AS activity_name, created_at
     FROM study_groups
     WHERE creator_id = ? AND status = 'Active'
     ORDER BY created_at DESC
     LIMIT 10"
);
$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $activity['recent'][] = [
        'text' => 'Created a study group: ' . $row['activity_name'],
        'date' => $row['created_at']
    ];
}

$stmt->close();

/* Sort recent activity: newest first */
usort($activity['recent'], function ($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
});

/* Keep only the latest 15 records */
$activity['recent'] = array_slice($activity['recent'], 0, 15);

/* Page settings */
$title = 'My activity';
$active = 'activity';
$css = ['activity'];

include __DIR__ . '/includes/header.php';
?>

<main class="wrap">
    <header class="page-head">
        <h1>My activity</h1>
        <p>See what you have shared and joined on StudentLink.</p>
    </header>

    <section class="stats">
        <?php foreach ($activity['counts'] as $label => $count): ?>
            <div class="stat">
                <span><?= e($label) ?></span>
                <b><?= (int) $count ?></b>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="info" style="padding:26px 28px">
        <h2 style="font-family:var(--f-head);font-size:1.5rem;margin-bottom:18px">
            Recent activity
        </h2>

        <ul class="timeline">
            <?php if (empty($activity['recent'])): ?>
                <li>
                    You haven't shared any resources, offered services,
                    posted items, or created study groups yet.
                </li>
            <?php else: ?>
                <?php foreach ($activity['recent'] as $line): ?>
                    <li>
                        <strong><?= e($line['text']) ?></strong>

                        <small class="meta">
                            <?= e(date(
                                'M j, Y \a\t g:i A',
                                strtotime($line['date'])
                            )) ?>
                        </small>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
