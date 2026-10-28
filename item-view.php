
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Item Details';
$active = 'items';
$css = ['item-view'];

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$item = null;
$errorMessage = '';
$requestError = '';

/* Generate CSRF token */
$csrfToken = $_SESSION['csrf_token'] ?? '';

if ($csrfToken === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}

/* Load item details */
if (!$itemId || $itemId < 1) {
    $errorMessage = 'Invalid item selected.';
} else {
    $sql = "
        SELECT
            i.id,
            i.user_id,
            i.name,
            i.description,
            i.transaction_type,
            i.item_condition,
            i.price,
            i.image_path,
            i.status,
            i.created_at,
            c.name AS category,
            u.full_name AS owner_name,
            u.course,
            u.year_level,
            u.photo_path AS owner_photo
        FROM items i
        INNER JOIN categories c ON c.id = i.category_id
        INNER JOIN users u ON u.id = i.user_id
        WHERE i.id = ?
          AND c.kind = 'item'
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param('i', $itemId);
        $stmt->execute();

        $result = $stmt->get_result();
        $item = $result->fetch_assoc();

        $stmt->close();

        if (!$item) {
            $errorMessage = 'This item could not be found.';
        } elseif (
            $item['status'] !== 'Active' &&
            (int) $item['user_id'] !== $currentUserId
        ) {
            $item = null;
            $errorMessage = 'This item is no longer available.';
        }
    } else {
        error_log('Item view query failed: ' . $conn->error);
        $errorMessage = 'Something went wrong while loading this item.';
    }
}

/* Handle item request submission */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['request_item'])
) {
    $postedItemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
    $postedToken = $_POST['csrf_token'] ?? '';

    if ($currentUserId < 1) {
        header('Location: login.php');
        exit;
    }

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $requestError = 'Your session has expired. Please refresh the page and try again.';
    } elseif (!$postedItemId || $postedItemId !== (int) $itemId) {
        $requestError = 'Invalid item request.';
    } elseif (!$item) {
        $requestError = 'This item could not be found.';
    } elseif ($item['status'] !== 'Active') {
        $requestError = 'This item is no longer available.';
    } elseif ((int) $item['user_id'] === $currentUserId) {
        $requestError = 'You cannot request your own item.';
    } else {
        $ownerId = (int) $item['user_id'];

        /* Check whether this student has already requested this item */
        $checkStmt = $conn->prepare("
            SELECT id, status
            FROM item_requests
            WHERE item_id = ?
              AND requester_id = ?
            LIMIT 1
        ");

        if (!$checkStmt) {
            error_log('Item request check failed: ' . $conn->error);
            $requestError = 'Unable to process your request right now.';
        } else {
            $checkStmt->bind_param('ii', $itemId, $currentUserId);
            $checkStmt->execute();

            $existingRequest = $checkStmt->get_result()->fetch_assoc();

            $checkStmt->close();

            if ($existingRequest) {
                if ($existingRequest['status'] === 'Pending') {
                    $requestError = 'You have already requested this item. Your request is still pending.';
                } else {
                    $requestError = 'You have already submitted a request for this item. Current status: '
                        . $existingRequest['status'] . '.';
                }
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO item_requests (
                        item_id,
                        requester_id,
                        owner_id,
                        status
                    )
                    VALUES (?, ?, ?, 'Pending')
                ");

                if (!$insertStmt) {
                    error_log('Item request insert preparation failed: ' . $conn->error);
                    $requestError = 'Unable to submit your request right now.';
                } else {
                    $insertStmt->bind_param(
                        'iii',
                        $itemId,
                        $currentUserId,
                        $ownerId
                    );

                    if ($insertStmt->execute()) {
                        $insertStmt->close();

                        header(
                            'Location: item-view.php?id='
                            . (int) $itemId
                            . '&request_sent=1'
                        );
                        exit;
                    }

                    /* A duplicate may occur if two submissions happen together */
                    if ($insertStmt->errno === 1062) {
                        $requestError = 'You have already submitted a request for this item.';
                    } else {
                        error_log('Item request insert failed: ' . $insertStmt->error);
                        $requestError = 'Something went wrong while submitting your request.';
                    }

                    $insertStmt->close();
                }
            }
        }
    }
}

$isOwner = (
    $item !== null &&
    $currentUserId > 0 &&
    $currentUserId === (int) $item['user_id']
);

/* Check whether the current student already requested this item */
$existingRequestStatus = null;

if (
    $item !== null &&
    $currentUserId > 0 &&
    !$isOwner
) {
    $statusStmt = $conn->prepare("
        SELECT status
        FROM item_requests
        WHERE item_id = ?
          AND requester_id = ?
        LIMIT 1
    ");

    if ($statusStmt) {
        $statusStmt->bind_param('ii', $itemId, $currentUserId);
        $statusStmt->execute();

        $statusResult = $statusStmt->get_result()->fetch_assoc();

        if ($statusResult) {
            $existingRequestStatus = $statusResult['status'];
        }

        $statusStmt->close();
    }
}

$transaction = $item['transaction_type'] ?? '';
$price = (float) ($item['price'] ?? 0);

include __DIR__ . '/includes/header.php';
?>

<main class="wrap item-view-page">

    <a class="back-link" href="items.php">
        &larr; Back to Items
    </a>

    <?php if (isset($_GET['posted']) && $_GET['posted'] === '1'): ?>
        <div class="item-notice item-notice-success">
            Your item has been posted successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="item-notice item-notice-success">
            Your item has been updated successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['request_sent']) && $_GET['request_sent'] === '1'): ?>
        <div class="item-notice item-notice-success">
            Your item request has been submitted successfully.
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>

        <section class="item-message">
            <h1>Item unavailable</h1>
            <p><?= e($errorMessage) ?></p>
            <a class="btn btn-primary" href="items.php">
                Browse Items
            </a>
        </section>

    <?php else: ?>

        <section class="item-detail-card">

            <div class="item-detail-image">
                <?php if (!empty($item['image_path'])): ?>
                    <img
                        src="<?= e($item['image_path']) ?>"
                        alt="<?= e($item['name']) ?>"
                    >
                <?php else: ?>
                    <div class="item-image-placeholder">
                        <span>
                            <?= e(strtoupper(substr($item['name'], 0, 1))) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="item-detail-content">

                <div class="item-detail-top">
                    <span class="item-category">
                        <?= e($item['category']) ?>
                    </span>

                    <span class="item-status <?= $item['status'] === 'Active' ? 'status-active' : 'status-removed' ?>">
                        <?= e($item['status']) ?>
                    </span>
                </div>

                <h1><?= e($item['name']) ?></h1>

                <p class="item-price">
                    <?php if ($transaction === 'Sell'): ?>
                        ₱<?= number_format($price, 2) ?>
                    <?php elseif ($transaction === 'Borrow'): ?>
                        For Borrowing
                    <?php elseif ($transaction === 'Lend'): ?>
                        Available to Lend
                    <?php else: ?>
                        Contact the owner
                    <?php endif; ?>
                </p>

                <div class="item-detail-tags">
                    <span class="item-tag">
                        <?= e($transaction) ?>
                    </span>

                    <span class="item-tag">
                        Condition: <?= e($item['item_condition']) ?>
                    </span>
                </div>

                <div class="item-description">
                    <h2>Description</h2>

                    <?php if (!empty($item['description'])): ?>
                        <p><?= nl2br(e($item['description'])) ?></p>
                    <?php else: ?>
                        <p class="muted-text">
                            No description was provided for this item.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="item-owner">
                    <div class="owner-avatar">
                        <?php if (!empty($item['owner_photo'])): ?>
                            <img
                                src="<?= e($item['owner_photo']) ?>"
                                alt="<?= e($item['owner_name']) ?>"
                            >
                        <?php else: ?>
                            <span>
                                <?= e(strtoupper(substr($item['owner_name'], 0, 1))) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="owner-info">
                        <span class="owner-label">Posted by</span>
                        <strong><?= e($item['owner_name']) ?></strong>

                        <?php if (!empty($item['course']) || !empty($item['year_level'])): ?>
                            <small>
                                <?= e(implode(' · ', array_filter([
                                    $item['course'] ?? '',
                                    $item['year_level'] ?? ''
                                ]))) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="item-date">
                    Posted on
                    <?= e(date('F j, Y', strtotime($item['created_at']))) ?>
                </p>

                <?php if ($requestError !== ''): ?>
                    <div class="item-notice">
                        <?= e($requestError) ?>
                    </div>
                <?php endif; ?>

                <?php if ($isOwner): ?>

                    <div class="item-view-owner-actions">
                        <span class="owner-note">You posted this item.</span>

                        <div class="item-view-buttons">
                            <?php if ($item['status'] === 'Active'): ?>
                                <a
                                    class="btn btn-primary"
                                    href="item-edit.php?id=<?= (int) $item['id'] ?>"
                                >
                                    Edit Item
                                </a>

                                <form
                                    action="item-delete.php"
                                    method="post"
                                    onsubmit="return confirm('Are you sure you want to remove this item?');"
                                >
                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?= (int) $item['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken) ?>"
                                    >

                                    <button class="btn btn-danger" type="submit">
                                        Delete Item
                                    </button>
                                </form>
                            <?php else: ?>
                                <p class="muted-text">
                                    This item has been removed and is no longer visible to other users.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($item['status'] === 'Active'): ?>

                    <div class="item-view-actions">

                        <?php if ($currentUserId < 1): ?>

                            <a class="btn btn-primary" href="login.php">
                                Log in to Request
                            </a>

                        <?php elseif ($existingRequestStatus !== null): ?>

                            <button
                                class="btn btn-primary"
                                type="button"
                                disabled
                            >
                                Request <?= e($existingRequestStatus) ?>
                            </button>

                            <p class="muted-text">
                                You have already submitted a request for this item.
                            </p>

                        <?php else: ?>

                            <form action="item-view.php?id=<?= (int) $item['id'] ?>" method="post">
                                <input
                                    type="hidden"
                                    name="item_id"
                                    value="<?= (int) $item['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <button
                                    class="btn btn-primary"
                                    type="submit"
                                    name="request_item"
                                    value="1"
                                    onclick="return confirm('Do you want to submit a request for this item?');"
                                >
                                    Request Item
                                </button>
                            </form>

                            <p class="muted-text">
                                Submit a request to the student who posted this item.
                            </p>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>
        </section>

    <?php endif; ?>

</main>

<?php
$conn->close();
?>
