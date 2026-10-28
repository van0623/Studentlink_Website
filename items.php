
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Page settings */
$title = 'Items';
$active = 'items';
$css = ['items'];

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$messagePage = file_exists(__DIR__ . '/messages.php')
    ? 'messages.php'
    : 'message.php';

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Message the item owner */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'message_owner'
) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $itemId = filter_var(
        $_POST['item_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($currentUserId <= 0) {
        header('Location: login.php');
        exit;
    }

    if (
        $postedToken === ''
        || !hash_equals($csrfToken, $postedToken)
    ) {
        header('Location: items.php?error=invalid_token');
        exit;
    }

    if (!$itemId || $itemId <= 0) {
        header('Location: items.php?error=message_failed');
        exit;
    }

    try {
        /* Get the item owner and item name */
        $ownerStmt = $conn->prepare("
            SELECT
                i.user_id,
                i.name
            FROM items i
            INNER JOIN users u
                ON u.id = i.user_id
            INNER JOIN categories c
                ON c.id = i.category_id
            WHERE i.id = ?
              AND i.status = 'Active'
              AND c.kind = 'item'
            LIMIT 1
        ");

        if (!$ownerStmt) {
            throw new RuntimeException('Unable to prepare item query.');
        }

        $ownerStmt->bind_param('i', $itemId);
        $ownerStmt->execute();

        $ownerResult = $ownerStmt->get_result();
        $item = $ownerResult->fetch_assoc();

        $ownerStmt->close();

        if (!$item) {
            header('Location: items.php?error=owner_unavailable');
            exit;
        }

        $recipientId = (int) $item['user_id'];
        $itemName = (string) $item['name'];
        $itemUrl = 'item-view.php?id=' . $itemId;

        /* Prevent messaging yourself */
        if ($recipientId === $currentUserId) {
            header('Location: items.php?error=own_item');
            exit;
        }

        /* Find an existing private conversation */
        $conversationStmt = $conn->prepare("
            SELECT c.id
            FROM conversations c
            INNER JOIN conversation_members mine
                ON mine.conversation_id = c.id
               AND mine.user_id = ?
            INNER JOIN conversation_members theirs
                ON theirs.conversation_id = c.id
               AND theirs.user_id = ?
            WHERE (
                (c.user_one_id = ? AND c.user_two_id = ?)
                OR
                (c.user_one_id = ? AND c.user_two_id = ?)
            )
            AND NOT EXISTS (
                SELECT 1
                FROM conversation_members extra
                WHERE extra.conversation_id = c.id
                  AND extra.user_id <> ?
                  AND extra.user_id <> ?
            )
            LIMIT 1
        ");

        if (!$conversationStmt) {
            throw new RuntimeException('Unable to prepare conversation query.');
        }

        $conversationStmt->bind_param(
            'iiiiiiii',
            $currentUserId,
            $recipientId,
            $currentUserId,
            $recipientId,
            $recipientId,
            $currentUserId,
            $currentUserId,
            $recipientId
        );

        $conversationStmt->execute();

        $conversationResult = $conversationStmt->get_result();
        $conversation = $conversationResult->fetch_assoc();

        $conversationStmt->close();

        if ($conversation) {
            $conversationId = (int) $conversation['id'];
        } else {
            /* Create a conversation if none exists */
            $conn->begin_transaction();

            try {
                $checkStmt = $conn->prepare("
                    SELECT c.id
                    FROM conversations c
                    INNER JOIN conversation_members mine
                        ON mine.conversation_id = c.id
                       AND mine.user_id = ?
                    INNER JOIN conversation_members theirs
                        ON theirs.conversation_id = c.id
                       AND theirs.user_id = ?
                    WHERE (
                        (c.user_one_id = ? AND c.user_two_id = ?)
                        OR
                        (c.user_one_id = ? AND c.user_two_id = ?)
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM conversation_members extra
                        WHERE extra.conversation_id = c.id
                          AND extra.user_id <> ?
                          AND extra.user_id <> ?
                    )
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$checkStmt) {
                    throw new RuntimeException('Unable to check conversation.');
                }

                $checkStmt->bind_param(
                    'iiiiiiii',
                    $currentUserId,
                    $recipientId,
                    $currentUserId,
                    $recipientId,
                    $recipientId,
                    $currentUserId,
                    $currentUserId,
                    $recipientId
                );

                $checkStmt->execute();

                $checkResult = $checkStmt->get_result();
                $existingConversation = $checkResult->fetch_assoc();

                $checkStmt->close();

                if ($existingConversation) {
                    $conversationId = (int) $existingConversation['id'];
                } else {
                    /* Save the conversation */
                    $createStmt = $conn->prepare("
                        INSERT INTO conversations (
                            user_one_id,
                            user_two_id
                        )
                        VALUES (?, ?)
                    ");

                    if (!$createStmt) {
                        throw new RuntimeException('Unable to create conversation.');
                    }

                    $createStmt->bind_param(
                        'ii',
                        $currentUserId,
                        $recipientId
                    );

                    $createStmt->execute();

                    $conversationId = (int) $conn->insert_id;

                    $createStmt->close();

                    if ($conversationId <= 0) {
                        throw new RuntimeException('Conversation creation failed.');
                    }

                    /* Add both users to the conversation */
                    $memberStmt = $conn->prepare("
                        INSERT INTO conversation_members (
                            conversation_id,
                            user_id
                        )
                        VALUES (?, ?)
                    ");

                    if (!$memberStmt) {
                        throw new RuntimeException('Unable to add conversation members.');
                    }

                    $memberId = $currentUserId;

                    $memberStmt->bind_param(
                        'ii',
                        $conversationId,
                        $memberId
                    );

                    $memberStmt->execute();

                    $memberId = $recipientId;
                    $memberStmt->execute();

                    $memberStmt->close();
                }

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        /* Link this item to the conversation */
        $contextStmt = $conn->prepare("
            INSERT INTO conversation_contexts (
                conversation_id,
                source_type,
                source_id,
                source_title,
                source_url,
                started_by
            )
            VALUES (?, 'item', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                source_title = VALUES(source_title),
                source_url = VALUES(source_url)
        ");

        if (!$contextStmt) {
            throw new RuntimeException('Unable to prepare item context.');
        }

        $contextStmt->bind_param(
            'iissi',
            $conversationId,
            $itemId,
            $itemName,
            $itemUrl,
            $currentUserId
        );

        $contextStmt->execute();
        $contextStmt->close();

        /* Open the conversation */
        header(
            'Location: '
            . $messagePage
            . '?chat='
            . $conversationId
        );
        exit;

    } catch (Throwable $e) {
        error_log('Items messaging error: ' . $e->getMessage());

        header('Location: items.php?error=message_failed');
        exit;
    }
}

/* Search and filter values */
$search = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$transactionFilter = trim($_GET['transaction'] ?? '');
$conditionFilter = trim($_GET['condition'] ?? '');

/* Filter options */
$categories_item = [];
$transaction_types = ['Borrow', 'Lend', 'Sell'];
$conditions = ['New', 'Good', 'Used'];

$rows = [];
$loadError = false;

/* Load categories and items */
try {
    $categoryQuery = $conn->query("
        SELECT id, name
        FROM categories
        WHERE kind = 'item'
        ORDER BY name ASC
    ");

    while ($category = $categoryQuery->fetch_assoc()) {
        $categories_item[] = $category;
    }

    $sql = "SELECT
                i.id,
                i.user_id,
                i.name,
                i.description AS `desc`,
                i.price,
                i.transaction_type AS transaction,
                i.item_condition AS `condition`,
                i.image_path,
                i.created_at,
                c.name AS category,
                u.full_name AS `by`
            FROM items i
            INNER JOIN categories c
                ON c.id = i.category_id
            INNER JOIN users u
                ON u.id = i.user_id
            WHERE i.status = 'Active'
              AND c.kind = 'item'";

    $params = [];

    /* Search items */
    if ($search !== '') {
        $sql .= " AND (
                    i.name LIKE ?
                    OR i.description LIKE ?
                    OR c.name LIKE ?
                  )";

        $keyword = '%' . $search . '%';

        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
    }

    /* Filter category */
    if (
        $categoryFilter !== ''
        && ctype_digit($categoryFilter)
    ) {
        $sql .= " AND i.category_id = ?";
        $params[] = (int) $categoryFilter;
    }

    /* Filter transaction type */
    if (in_array($transactionFilter, $transaction_types, true)) {
        $sql .= " AND i.transaction_type = ?";
        $params[] = $transactionFilter;
    }

    /* Filter condition */
    if (in_array($conditionFilter, $conditions, true)) {
        $sql .= " AND i.item_condition = ?";
        $params[] = $conditionFilter;
    }

    $sql .= " ORDER BY i.id DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare items query.');
    }

    if (!empty($params)) {
        $types = '';
        $bindValues = [];

        foreach ($params as $key => $param) {
            $types .= is_int($param) ? 'i' : 's';
            $bindValues[$key] = &$params[$key];
        }

        $stmt->bind_param($types, ...$bindValues);
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $rows[] = $item;
    }

    $stmt->close();

} catch (Throwable $e) {
    error_log('Items page error: ' . $e->getMessage());
    $loadError = true;
}

/* Display page */
include __DIR__ . '/includes/header.php';
?>

<main class="items-page">

    <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>

        <p class="success-message">
            Item removed successfully.
        </p>

    <?php elseif (isset($_GET['error'])): ?>

        <p class="error-message">
            <?php
            $errorCode = (string) $_GET['error'];

            switch ($errorCode) {
                case 'own_item':
                    echo 'You cannot message yourself about your own item.';
                    break;

                case 'owner_unavailable':
                    echo 'This item is no longer available.';
                    break;

                case 'invalid_token':
                    echo 'Your session token is invalid. Please try again.';
                    break;

                case 'message_failed':
                    echo 'Unable to open a conversation right now. Please try again.';
                    break;

                default:
                    echo 'Unable to complete your request. Please try again.';
                    break;
            }
            ?>
        </p>

    <?php endif; ?>

    <header class="items-intro">
        <div class="items-intro-content">
            <span class="items-eyebrow">Student Marketplace</span>

            <h1>Share <span>items</span></h1>

            <p>
                Find, borrow, lend, or sell useful school items
                from fellow students.
            </p>
        </div>

        <a class="item-post-btn" href="item-post.php">
            <span class="post-icon">+</span>
            Post an item
        </a>
    </header>

    <?php flash('Item posted. You will be notified when someone asks for it.'); ?>

    <section class="items-toolbar">
        <form class="items-filter-form" method="get" role="search">

            <label class="item-search">
                <span class="search-icon" aria-hidden="true">&#9906;</span>

                <input
                    type="search"
                    name="q"
                    placeholder="Search books, calculators, equipment"
                    value="<?= e($search) ?>"
                    aria-label="Search items"
                >
            </label>

            <div class="item-filter">
                <select name="category" aria-label="Filter by category">
                    <option value="">All categories</option>

                    <?php foreach ($categories_item as $category): ?>
                        <option
                            value="<?= (int) $category['id'] ?>"
                            <?= $categoryFilter === (string) $category['id'] ? 'selected' : '' ?>
                        >
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="item-filter">
                <select name="transaction" aria-label="Filter by transaction type">
                    <option value="">Any transaction</option>

                    <?php foreach ($transaction_types as $type): ?>
                        <option
                            value="<?= e($type) ?>"
                            <?= $transactionFilter === $type ? 'selected' : '' ?>
                        >
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="item-filter">
                <select name="condition" aria-label="Filter by item condition">
                    <option value="">Any condition</option>

                    <?php foreach ($conditions as $condition): ?>
                        <option
                            value="<?= e($condition) ?>"
                            <?= $conditionFilter === $condition ? 'selected' : '' ?>
                        >
                            <?= e($condition) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="item-search-btn">
                Search
            </button>

            <a class="item-clear-btn" href="items.php">
                Clear
            </a>

        </form>
    </section>

    <section class="items-library">

        <div class="items-section-heading">
            <div>
                <span class="items-eyebrow">Item library</span>
                <h2>Browse items</h2>
                <p>Borrow, lend, or buy from students on campus.</p>
            </div>

            <div class="items-count">
                <strong><?= count($rows) ?></strong>

                <span>
                    item<?= count($rows) === 1 ? '' : 's' ?> found
                </span>
            </div>
        </div>

        <?php if ($loadError): ?>

            <div class="items-empty-state">
                <div class="empty-state-icon" aria-hidden="true">&#9671;</div>

                <h3>Unable to load items</h3>

                <p>
                    Unable to load items from the database.
                    Please check your database table and column names.
                </p>
            </div>

        <?php elseif (empty($rows)): ?>

            <div class="items-empty-state">
                <div class="empty-state-icon" aria-hidden="true">&#9671;</div>

                <h3>Nothing matches</h3>

                <p>
                    No active items match your search.
                    Try clearing a filter or post a new item.
                </p>

                <a class="item-search-btn" href="item-post.php">
                    Post an item
                </a>
            </div>

        <?php else: ?>

            <div class="items-grid">

                <?php foreach ($rows as $i): ?>

                    <?php
                    $typeClass = 'is-' . strtolower($i['transaction']);

                    $itemInitials = mb_strtoupper(
                        mb_substr(trim($i['name']), 0, 2)
                    );

                    $isOwner = (
                        $currentUserId > 0
                        && $currentUserId === (int) $i['user_id']
                    );
                    ?>

                    <article class="item-card">

                        <div class="item-card-top">
                            <div class="item-icon" aria-hidden="true">
                                <?= e($itemInitials) ?>
                            </div>

                            <span class="item-category">
                                <?= e($i['category']) ?>
                            </span>
                        </div>

                        <div class="item-card-content">
                            <h3><?= e($i['name']) ?></h3>

                            <p class="item-description">
                                <?= e($i['desc'] ?: 'No description has been added yet.') ?>
                            </p>
                        </div>

                        <div class="item-card-meta">
                            <span class="item-condition">
                                <span aria-hidden="true">&#9679;</span>
                                Condition: <?= e($i['condition']) ?>
                            </span>

                            <span class="item-by">
                                Posted by <?= e($i['by']) ?>
                            </span>
                        </div>

                        <div class="item-card-footer">

                            <div class="item-deal">
                                <span class="item-type <?= e($typeClass) ?>">
                                    <?= e($i['transaction']) ?>
                                </span>

                                <?php if ($i['transaction'] === 'Sell'): ?>
                                    <span class="item-price">
                                        &#8369;<?= number_format((float) $i['price'], 2) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <a
                                class="item-view-btn"
                                href="item-view.php?id=<?= (int) $i['id'] ?>"
                            >
                                View item <span aria-hidden="true">&rarr;</span>
                            </a>

                        </div>

                        <?php if (!$isOwner && $currentUserId > 0): ?>

                            
                            <div class="item-message-actions">

                                <form method="post" action="items.php">
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="message_owner"
                                    >

                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?= (int) $i['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken) ?>"
                                    >

                                    <button class="item-view-btn" type="submit">
                                        Message Owner
                                        <span aria-hidden="true">&#8594;</span>
                                    </button>
                                </form>

                                <!-- Report item -->
                                <div class="item-report-actions">
                                    <a
                                        href="report.php?type=Item&id=<?= (int) $i['id'] ?>"
                                        class="item-report-btn"
                                    >
                                        Report Item
                                        <span aria-hidden="true">&#8594;</span>
                                    </a>
                                </div>

                            </div>

                        <?php endif; ?>

                        <?php if ($isOwner): ?>

                            <div class="item-owner-actions">

                                <span class="item-owner-label">
                                    Your post
                                </span>

                                <div class="item-owner-buttons">

                                    <a
                                        class="item-edit-btn"
                                        href="item-edit.php?id=<?= (int) $i['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        class="item-delete-form"
                                        action="item-delete.php"
                                        method="post"
                                        onsubmit="return confirm('Are you sure you want to remove this item?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="item_id"
                                            value="<?= (int) $i['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <button
                                            class="item-delete-btn"
                                            type="submit"
                                        >
                                            Delete
                                        </button>
                                    </form>

                                </div>
                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
