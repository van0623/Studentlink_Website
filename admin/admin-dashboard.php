
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/functions.php';

/*
|--------------------------------------------------------------------------
| Admin access protection
|--------------------------------------------------------------------------
*/
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

if (
    $role !== 'admin' ||
    (empty($_SESSION['user_id']) && empty($_SESSION['admin_id']))
) {
    header('Location: ../login.php');
    exit;
}


function slAdminTableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");

    return $result && $result->num_rows > 0;
}

function slAdminColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    if (!slAdminTableExists($conn, $table)) {
        return false;
    }

    $table = str_replace('`', '', $table);
    $column = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result && $result->num_rows > 0;
}

function slAdminCount(mysqli $conn, string $table): int
{
    if (!slAdminTableExists($conn, $table)) {
        return 0;
    }

    $result = $conn->query("SELECT COUNT(*) AS total FROM `{$table}`");

    if (!$result) {
        return 0;
    }

    return (int) ($result->fetch_assoc()['total'] ?? 0);
}

function slAdminStatusCount(
    mysqli $conn,
    string $table,
    string $status
): int {
    if (
        !slAdminTableExists($conn, $table) ||
        !slAdminColumnExists($conn, $table, 'status')
    ) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM `{$table}`
         WHERE LOWER(`status`) = LOWER(?)"
    );

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('s', $status);
    $stmt->execute();

    $result = $stmt->get_result();
    $total = (int) ($result->fetch_assoc()['total'] ?? 0);

    $stmt->close();

    return $total;
}

function slAdminFirstColumn(
    mysqli $conn,
    string $table,
    array $candidates
): ?string {
    foreach ($candidates as $column) {
        if (slAdminColumnExists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Admin account
|--------------------------------------------------------------------------
*/

$admin = [
    'name' => $_SESSION['full_name']
        ?? $_SESSION['name']
        ?? $_SESSION['username']
        ?? 'Administrator'
];

if (
    !empty($_SESSION['user_id']) &&
    slAdminTableExists($conn, 'users') &&
    slAdminColumnExists($conn, 'users', 'id') &&
    slAdminColumnExists($conn, 'users', 'full_name')
) {
    $userId = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT full_name
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();

        $result = $stmt->get_result();
        $account = $result->fetch_assoc();

        if (!empty($account['full_name'])) {
            $admin['name'] = $account['full_name'];
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$totalUsers = slAdminCount($conn, 'users');
$activeUsers = slAdminStatusCount($conn, 'users', 'Active');

$totalItems = slAdminCount($conn, 'items');
$totalServices = slAdminCount($conn, 'services');
$totalResources = slAdminCount($conn, 'resources');
$totalGroups = slAdminCount($conn, 'groups');

$publishedItems = slAdminStatusCount($conn, 'items', 'Available');
$publishedServices = slAdminStatusCount($conn, 'services', 'Available');
$publishedResources = slAdminStatusCount($conn, 'resources', 'Published');

$pendingItemRequests = slAdminStatusCount(
    $conn,
    'item_requests',
    'Pending'
);

$pendingServiceRequests = slAdminStatusCount(
    $conn,
    'service_requests',
    'Pending'
);

$totalPendingRequests = $pendingItemRequests + $pendingServiceRequests;

/*
|--------------------------------------------------------------------------
| Recent registrations
|--------------------------------------------------------------------------
*/

$recentUsers = [];

if (
    slAdminTableExists($conn, 'users') &&
    slAdminColumnExists($conn, 'users', 'id')
) {
    $nameColumn = slAdminFirstColumn(
        $conn,
        'users',
        ['full_name', 'name', 'username', 'email']
    );

    $dateColumn = slAdminFirstColumn(
        $conn,
        'users',
        ['created_at', 'registered_at', 'date_created']
    );

    $emailColumn = slAdminFirstColumn(
        $conn,
        'users',
        ['email']
    );

    $statusColumn = slAdminFirstColumn(
        $conn,
        'users',
        ['status', 'account_status']
    );

    if ($nameColumn && $dateColumn) {
        $select = [
            "`id` AS user_id",
            "`{$nameColumn}` AS display_name",
            "`{$dateColumn}` AS registered_date"
        ];

        if ($emailColumn) {
            $select[] = "`{$emailColumn}` AS email";
        } else {
            $select[] = "'' AS email";
        }

        if ($statusColumn) {
            $select[] = "`{$statusColumn}` AS account_status";
        } else {
            $select[] = "'' AS account_status";
        }

        $sql = "SELECT " . implode(', ', $select) . "
                FROM users
                ORDER BY `{$dateColumn}` DESC
                LIMIT 6";

        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $recentUsers[] = $row;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Recent content
|--------------------------------------------------------------------------
| Reads recent records from available listing/content tables.
*/

$recentContent = [];

$contentTables = [
    'items' => [
        'label' => 'Item',
        'title_columns' => ['name', 'title', 'item_name'],
    ],
    'services' => [
        'label' => 'Service',
        'title_columns' => ['title', 'name', 'service_name'],
    ],
    'resources' => [
        'label' => 'Resource',
        'title_columns' => ['title', 'name', 'resource_name'],
    ],
    'groups' => [
        'label' => 'Group',
        'title_columns' => ['name', 'title', 'group_name'],
    ],
];

foreach ($contentTables as $table => $config) {
    if (
        !slAdminTableExists($conn, $table) ||
        !slAdminColumnExists($conn, $table, 'id')
    ) {
        continue;
    }

    $titleColumn = slAdminFirstColumn(
        $conn,
        $table,
        $config['title_columns']
    );

    $dateColumn = slAdminFirstColumn(
        $conn,
        $table,
        ['created_at', 'date_created', 'posted_at']
    );

    $statusColumn = slAdminFirstColumn(
        $conn,
        $table,
        ['status']
    );

    if (!$titleColumn || !$dateColumn) {
        continue;
    }

    $statusSelect = $statusColumn
        ? "`{$statusColumn}` AS content_status"
        : "'' AS content_status";

    $sql = "SELECT
                `id` AS content_id,
                `{$titleColumn}` AS content_title,
                `{$dateColumn}` AS content_date,
                {$statusSelect}
            FROM `{$table}`
            ORDER BY `{$dateColumn}` DESC
            LIMIT 4";

    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['content_type'] = $config['label'];
            $row['source_table'] = $table;
            $recentContent[] = $row;
        }
    }
}

usort($recentContent, function ($a, $b) {
    return strtotime((string) ($b['content_date'] ?? ''))
        <=> strtotime((string) ($a['content_date'] ?? ''));
});

$recentContent = array_slice($recentContent, 0, 6);

/*
|--------------------------------------------------------------------------
| Recent activity feed
|--------------------------------------------------------------------------
*/

$recentActivity = [];

foreach ($recentUsers as $user) {
    $recentActivity[] = [
        'date' => $user['registered_date'] ?? '',
        'title' => 'New user registered',
        'description' => $user['display_name'] ?? 'New account',
        'type' => 'user'
    ];
}

foreach ($recentContent as $content) {
    $recentActivity[] = [
        'date' => $content['content_date'] ?? '',
        'title' => $content['content_type'] . ' added',
        'description' => $content['content_title'] ?? 'New content',
        'type' => 'content'
    ];
}

usort($recentActivity, function ($a, $b) {
    return strtotime((string) ($b['date'] ?? ''))
        <=> strtotime((string) ($a['date'] ?? ''));
});

$recentActivity = array_slice($recentActivity, 0, 8);

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$title = 'Dashboard';
$active = 'dashboard';
$css = ['admin-dashboard'];

include __DIR__ . '/../includes/admin-header.php';
?>

<link rel="stylesheet" href="admin-dashboard.css?v=<?= time() ?>">

<header class="dashboard-heading">
    <div>
        <span class="eyebrow">ADMINISTRATION</span>
        <h1>Welcome, <?= e($admin['name']) ?>!</h1>
        <p>Here's an overview of what's happening on StudentLink.</p>
    </div>

    <div class="dashboard-heading-actions">
        <a class="dashboard-button dashboard-button-primary" href="users.php">
            Manage users
        </a>
    </div>
</header>

<!-- PLATFORM OVERVIEW -->
<section class="dashboard-section">
    <div class="section-heading">
        <div>
            <h2>Platform overview</h2>
            <p>Current totals from your database.</p>
        </div>
    </div>

    <div class="dashboard-stats">
        <article class="dashboard-stat stat-users">
            <div class="stat-top">
                <span class="stat-label">Total users</span>
                <span class="stat-icon">U</span>
            </div>
            <strong class="stat-number"><?= number_format($totalUsers) ?></strong>
            <span class="stat-note"><?= number_format($activeUsers) ?> active accounts</span>
            <a href="users.php">View users <span>→</span></a>
        </article>

        <article class="dashboard-stat stat-items">
            <div class="stat-top">
                <span class="stat-label">Items</span>
                <span class="stat-icon">I</span>
            </div>
            <strong class="stat-number"><?= number_format($totalItems) ?></strong>
            <span class="stat-note">Items listed on the platform</span>
            <a href="content.php">Manage content <span>→</span></a>
        </article>

        <article class="dashboard-stat stat-services">
            <div class="stat-top">
                <span class="stat-label">Services</span>
                <span class="stat-icon">S</span>
            </div>
            <strong class="stat-number"><?= number_format($totalServices) ?></strong>
            <span class="stat-note">Services listed on the platform</span>
            <a href="content.php">Manage content <span>→</span></a>
        </article>

        <article class="dashboard-stat stat-resources">
            <div class="stat-top">
                <span class="stat-label">Resources</span>
                <span class="stat-icon">R</span>
            </div>
            <strong class="stat-number"><?= number_format($totalResources) ?></strong>
            <span class="stat-note">Shared academic resources</span>
            <a href="content.php">Manage content <span>→</span></a>
        </article>

        <article class="dashboard-stat stat-groups">
            <div class="stat-top">
                <span class="stat-label">Groups</span>
                <span class="stat-icon">G</span>
            </div>
            <strong class="stat-number"><?= number_format($totalGroups) ?></strong>
            <span class="stat-note">Student groups created</span>
            <a href="content.php">Manage content <span>→</span></a>
        </article>

        <article class="dashboard-stat stat-pending">
            <div class="stat-top">
                <span class="stat-label">Pending requests</span>
                <span class="stat-icon">!</span>
            </div>
            <strong class="stat-number"><?= number_format($totalPendingRequests) ?></strong>
            <span class="stat-note">
                <?= number_format($pendingItemRequests) ?> item ·
                <?= number_format($pendingServiceRequests) ?> service
            </span>
            <a href="../transactions.php">View transactions <span>→</span></a>
        </article>
    </div>
</section>


<section class="dashboard-lower-grid">

    <!-- RECENT ACTIVITY -->
    <article class="dashboard-panel activity-panel">
        <div class="panel-heading">
            <div>
                <h2>Recent activity</h2>
                <p>Recent registrations and content additions.</p>
            </div>
        </div>

        <?php if (empty($recentActivity)): ?>
            <div class="dashboard-empty">
                <span class="empty-symbol">A</span>
                <strong>No recent activity</strong>
                <p>Activity will appear here when records are added.</p>
            </div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($recentActivity as $activity): ?>
                    <div class="activity-item">
                        <span class="activity-dot <?= $activity['type'] === 'user' ? 'dot-user' : 'dot-content' ?>"></span>

                        <div class="activity-copy">
                            <strong><?= e($activity['title']) ?></strong>
                            <p><?= e($activity['description']) ?></p>
                            <time>
                                <?php
                                $activityDate = $activity['date'] ?? '';
                                echo $activityDate && strtotime($activityDate)
                                    ? e(date('M j, Y · g:i A', strtotime($activityDate)))
                                    : 'Date unavailable';
                                ?>
                            </time>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <!-- RECENTLY ADDED CONTENT -->
    <article class="dashboard-panel">
        <div class="panel-heading">
            <div>
                <h2>Recently added content</h2>
                <p>Latest items, services, resources, and groups.</p>
            </div>
            <a href="content.php">View all</a>
        </div>

        <?php if (empty($recentContent)): ?>
            <div class="dashboard-empty">
                <span class="empty-symbol">C</span>
                <strong>No recent content found</strong>
                <p>Content appears here when records are added.</p>
            </div>
        <?php else: ?>
            <div class="recent-content-grid">
                <?php foreach ($recentContent as $content): ?>
                    <article class="content-card">
                        <div class="content-card-top">
                            <span class="content-type">
                                <?= e($content['content_type']) ?>
                            </span>
                            <span class="content-status">
                                <?= e($content['content_status'] ?: 'No status') ?>
                            </span>
                        </div>

                        <h3><?= e($content['content_title'] ?: 'Untitled') ?></h3>

                        <p>
                            Added
                            <?php
                            $contentDate = $content['content_date'] ?? '';
                            echo $contentDate && strtotime($contentDate)
                                ? e(date('M j, Y', strtotime($contentDate)))
                                : 'date unavailable';
                            ?>
                        </p>

                        <a href="content.php">Manage content <span>→</span></a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

</section>


<!-- SECOND ROW: REGISTRATIONS + QUICK ACTIONS -->
<section class="dashboard-lower-grid">

    <!-- RECENT REGISTRATIONS -->
    <article class="dashboard-panel">
        <div class="panel-heading">
            <div>
                <h2>Recent registrations</h2>
                <p>Latest accounts registered on StudentLink.</p>
            </div>
            <a href="users.php">View all</a>
        </div>

        <?php if (empty($recentUsers)): ?>
            <div class="dashboard-empty">
                <span class="empty-symbol">U</span>
                <strong>No registration data available</strong>
                <p>New accounts will appear here when registration dates are available.</p>
            </div>
        <?php else: ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Status</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUsers as $user): ?>
                            <tr>
                                <td>
                                    <div class="table-user">
                                        <span class="table-avatar">
                                            <?= e(initials($user['display_name'] ?? 'U')) ?>
                                        </span>
                                        <div>
                                            <strong>
                                                <?= e($user['display_name'] ?? 'Unknown user') ?>
                                            </strong>

                                            <?php if (!empty($user['email'])): ?>
                                                <small><?= e($user['email']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="status-pill">
                                        <?= e($user['account_status'] ?: 'Unknown') ?>
                                    </span>
                                </td>

                                <td class="date-cell">
                                    <?php
                                    $registeredDate = $user['registered_date'] ?? '';
                                    echo $registeredDate && strtotime($registeredDate)
                                        ? e(date('M j, Y', strtotime($registeredDate)))
                                        : '—';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <!-- QUICK ACTIONS -->
    <article class="dashboard-panel">
        <div class="panel-heading">
            <div>
                <h2>Quick actions</h2>
                <p>Go directly to the areas you manage most.</p>
            </div>
        </div>

        <div class="quick-actions">
            <a href="users.php" class="quick-action">
                <span class="quick-action-icon">U</span>
                <span>
                    <strong>Manage users</strong>
                    <small>Review and manage accounts</small>
                </span>
                <span class="quick-arrow">→</span>
            </a>

            <a href="content.php" class="quick-action">
                <span class="quick-action-icon">C</span>
                <span>
                    <strong>Manage content</strong>
                    <small>Review items and student posts</small>
                </span>
                <span class="quick-arrow">→</span>
            </a>

            <a href="reports.php" class="quick-action">
                <span class="quick-action-icon">R</span>
                <span>
                    <strong>Reports</strong>
                    <small>Review platform reports</small>
                </span>
                <span class="quick-arrow">→</span>
            </a>

            <a href="settings.php" class="quick-action">
                <span class="quick-action-icon">S</span>
                <span>
                    <strong>Admin settings</strong>
                    <small>Manage your admin preferences</small>
                </span>
                <span class="quick-arrow">→</span>
            </a>
        </div>
    </article>

</section>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>

