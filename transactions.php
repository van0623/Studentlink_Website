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

/* Verify current account */
$userStmt = $conn->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND status = 'Active'
    LIMIT 1
");

if (!$userStmt) {
    error_log('User verification error: ' . $conn->error);
    exit('Unable to load transactions.');
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* Generate CSRF token */
if (empty($_SESSION['request_action_csrf'])) {
    $_SESSION['request_action_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['request_action_csrf'];

/* Status filter */
$status = trim($_GET['status'] ?? '');

$allowedStatuses = [
    '',
    'Pending',
    'Accepted',
    'Rejected',
    'Complete',
    'Completed'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/* Fetch item requests, service requests, and existing transactions */
$sql = "
    SELECT *
    FROM (
        /* Item requests */
        SELECT
            ir.id AS record_id,
            'item_request' AS source_table,
            ir.requester_id,
            ir.owner_id,
            ir.item_id,
            NULL AS service_id,
            'Item Request' AS type,
            ir.status,
            ir.created_at,
            requester.full_name AS requester_name,
            owner.full_name AS owner_name,
            i.name AS item_name,
            NULL AS service_title
        FROM item_requests ir
        LEFT JOIN users requester
            ON requester.id = ir.requester_id
        LEFT JOIN users owner
            ON owner.id = ir.owner_id
        LEFT JOIN items i
            ON i.id = ir.item_id
        WHERE ir.requester_id = ?
           OR ir.owner_id = ?

        UNION ALL

        /* Service requests */
        SELECT
            sr.id AS record_id,
            'service_request' AS source_table,
            sr.requester_id,
            sr.provider_id AS owner_id,
            NULL AS item_id,
            sr.service_id,
            'Service Request' AS type,
            sr.status,
            sr.created_at,
            requester.full_name AS requester_name,
            provider.full_name AS owner_name,
            NULL AS item_name,
            s.title AS service_title
        FROM service_requests sr
        LEFT JOIN users requester
            ON requester.id = sr.requester_id
        LEFT JOIN users provider
            ON provider.id = sr.provider_id
        LEFT JOIN services s
            ON s.id = sr.service_id
        WHERE sr.requester_id = ?
           OR sr.provider_id = ?

        UNION ALL

        /* Existing transactions */
        SELECT
            t.id AS record_id,
            'transaction' AS source_table,
            t.requester_id,
            t.owner_id,
            t.item_id,
            t.service_id,
            COALESCE(t.type, 'Transaction') AS type,
            t.status,
            t.created_at,
            requester.full_name AS requester_name,
            owner.full_name AS owner_name,
            i.name AS item_name,
            s.title AS service_title
        FROM transactions t
        LEFT JOIN users requester
            ON requester.id = t.requester_id
        LEFT JOIN users owner
            ON owner.id = t.owner_id
        LEFT JOIN items i
            ON i.id = t.item_id
        LEFT JOIN services s
            ON s.id = t.service_id
        WHERE t.requester_id = ?
           OR t.owner_id = ?
    ) AS all_requests
    WHERE (? = '' OR LOWER(status) = LOWER(?))
    ORDER BY created_at DESC, record_id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Transactions query error: ' . $conn->error);
    exit(
        'Unable to load transactions. Please check the structure of your '
        . 'item_requests, service_requests, and transactions tables.'
    );
}

$stmt->bind_param(
    'iiiiiiss',
    $userId,
    $userId,
    $userId,
    $userId,
    $userId,
    $userId,
    $status,
    $status
);

$stmt->execute();
$result = $stmt->get_result();

$transactions = [];

while ($row = $result->fetch_assoc()) {
    /* Listing title and link */
    if (!empty($row['item_id'])) {
        $row['title'] = $row['item_name'] ?? 'Item no longer available';
        $row['view_url'] = 'item-view.php?id=' . (int) $row['item_id'];
    } elseif (!empty($row['service_id'])) {
        $row['title'] = $row['service_title'] ?? 'Service no longer available';
        $row['view_url'] = 'service-view.php?id=' . (int) $row['service_id'];
    } else {
        $row['title'] = $row['type'] ?? 'Transaction';
        $row['view_url'] = '';
    }

    /* Identify sent and received requests */
    if ((int) $row['requester_id'] === $userId) {
        $row['direction'] = 'sent';
        $row['role_label'] = 'Requested from';
        $row['other_person'] = $row['owner_name'] ?? 'Unavailable';
    } else {
        $row['direction'] = 'received';
        $row['role_label'] = 'Requested by';
        $row['other_person'] = $row['requester_name'] ?? 'Unavailable';
    }

    $currentStatus = strtolower((string) ($row['status'] ?? ''));

    /* Only incoming item/service requests can be managed */
    $row['can_manage'] =
        $row['direction'] === 'received'
        && in_array(
            $row['source_table'],
            ['item_request', 'service_request'],
            true
        );

    $row['can_accept_reject'] =
        $row['can_manage'] && $currentStatus === 'pending';

    $row['can_undo_accept'] =
        $row['can_manage'] && $currentStatus === 'accepted';

    $transactions[] = $row;
}

$stmt->close();

/* Page settings */
$title = 'My Transactions';
$active = 'transactions';
$css = ['transactions'];

include __DIR__ . '/includes/header.php';
?>

<main class="wrap">

    <header class="page-head">
        <h1>My Transactions</h1>
        <p>
            Track your requests, respond to incoming requests, and check their status.
        </p>
    </header>

    <?php
    $resultMessages = [
        'accepted' => 'Request accepted successfully.',
        'rejected' => 'Request rejected successfully.',
        'undo_accepted' => 'Acceptance undone. The request is now Pending.',
        'invalid' => 'The request could not be processed.',
        'unauthorized' => 'You are not allowed to manage that request.',
        'csrf' => 'Your session token was invalid. Please try again.',
        'not_pending' => 'This request has already been processed or changed.',
        'invalid_transition' => 'This request cannot be changed from its current status.',
        'error' => 'Something went wrong. Please try again.'
    ];

    $resultKey = (string) ($_GET['result'] ?? '');
    ?>

    <?php if (isset($resultMessages[$resultKey])): ?>
        <p class="notice" role="status">
            <?= e($resultMessages[$resultKey]) ?>
        </p>
    <?php endif; ?>

    <nav class="tabs" aria-label="Filter transactions by status">
        <?php
        $filters = [
            '' => 'All',
            'Pending' => 'Pending',
            'Accepted' => 'Accepted',
            'Rejected' => 'Rejected',
            'Complete' => 'Complete'
        ];
        ?>

        <?php foreach ($filters as $key => $label): ?>
            <a
                href="transactions.php?status=<?= urlencode($key) ?>"
                <?= $status === $key ? 'aria-current="true"' : '' ?>
            >
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (empty($transactions)): ?>

        <p class="empty">
            <?php if ($status !== ''): ?>
                No <?= e(strtolower($status)) ?> requests or transactions found.
            <?php else: ?>
                You don't have any transactions yet. Your item and service
                requests will appear here.
            <?php endif; ?>
        </p>

    <?php else: ?>

        <div class="stack-list">

            <?php foreach ($transactions as $t): ?>
                <?php
                $statusClass = strtolower(
                    preg_replace(
                        '/[^a-zA-Z0-9_-]/',
                        '',
                        (string) ($t['status'] ?? '')
                    )
                );
                ?>

                <article class="card txn">

                    <div>
                        <h3><?= e($t['title']) ?></h3>

                        <dl>
                            <div>
                                <dt>Type</dt>
                                <dd><?= e($t['type']) ?></dd>
                            </div>

                            <div>
                                <dt>Direction</dt>
                                <dd>
                                    <?= $t['direction'] === 'sent'
                                        ? 'Request Sent'
                                        : 'Request Received' ?>
                                </dd>
                            </div>

                            <div>
                                <dt><?= e($t['role_label']) ?></dt>
                                <dd><?= e($t['other_person']) ?></dd>
                            </div>

                            <div>
                                <dt>Status</dt>
                                <dd>
                                    <span class="badge <?= e($statusClass) ?>">
                                        <?= e($t['status']) ?>
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt>Date</dt>
                                <dd>
                                    <?= e(date(
                                        'M j, Y',
                                        strtotime($t['created_at'])
                                    )) ?>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="txn-actions">

                        <?php if (!empty($t['view_url'])): ?>
                            <a
                                class="btn btn-primary"
                                href="<?= e($t['view_url']) ?>"
                            >
                                View Listing
                            </a>
                        <?php endif; ?>

                        <?php if ($t['can_accept_reject']): ?>

                            <form
                                method="POST"
                                action="request-action.php"
                                class="request-action-form"
                                onsubmit="return confirm('Accept this request?');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_type"
                                    value="<?= e($t['source_table']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int) $t['record_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="accept"
                                >

                                <button type="submit" class="btn btn-primary">
                                    Accept
                                </button>
                            </form>

                            <form
                                method="POST"
                                action="request-action.php"
                                class="request-action-form"
                                onsubmit="return confirm('Reject this request?');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_type"
                                    value="<?= e($t['source_table']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int) $t['record_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="reject"
                                >

                                <button type="submit" class="btn btn-secondary">
                                    Reject
                                </button>
                            </form>

                        <?php elseif ($t['can_undo_accept']): ?>

                            <form
                                method="POST"
                                action="request-action.php"
                                class="request-action-form"
                                onsubmit="return confirm('Undo acceptance and return this request to Pending?');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_type"
                                    value="<?= e($t['source_table']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="request_id"
                                    value="<?= (int) $t['record_id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="undo_accept"
                                >

                                <button type="submit" class="btn btn-secondary">
                                    Undo Accept
                                </button>
                            </form>

                        <?php endif; ?>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

<?php
$conn->close();
include __DIR__ . '/includes/footer.php';
?>