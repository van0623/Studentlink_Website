
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* =========================================
   PAGE SETTINGS
========================================= */

$title = 'Edit Item';
$active = 'items';
$css = ['item-post'];

$errors = [];
$categories_item = [];

$transactionTypes = ['Borrow', 'Lend', 'Sell'];
$conditions = ['New', 'Good', 'Used'];

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];

/* =========================================
   CHECK LOGIN
========================================= */

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

/* =========================================
   GET ITEM ID
========================================= */

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$itemId || $itemId <= 0) {
    header('Location: items.php');
    exit;
}

/* =========================================
   LOAD ITEM AND VERIFY OWNER
========================================= */

try {
    $stmt = $conn->prepare(
        "SELECT
            id,
            user_id,
            category_id,
            name,
            transaction_type,
            item_condition,
            price,
            description,
            image_path
         FROM items
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $itemId);
    $stmt->execute();

    $result = $stmt->get_result();
    $item = $result->fetch_assoc();

    $stmt->close();

} catch (Throwable $e) {
    error_log('Load item for editing error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load this item right now.');
}

if (!$item || (int) $item['user_id'] !== $userId) {
    http_response_code(403);
    exit('You are not allowed to edit this item.');
}

/* =========================================
   LOAD ITEM CATEGORIES
========================================= */

try {
    $categoryQuery = $conn->query(
        "SELECT id, name
         FROM categories
         WHERE kind = 'item'
         ORDER BY name ASC"
    );

    while ($category = $categoryQuery->fetch_assoc()) {
        $categories_item[] = $category;
    }

} catch (Throwable $e) {
    error_log('Load item categories error: ' . $e->getMessage());
    $errors[] = 'Unable to load categories. Please try again.';
}

/* =========================================
   INITIAL FORM VALUES
========================================= */

$name = $item['name'];
$categoryId = (string) $item['category_id'];
$condition = $item['item_condition'];
$transaction = $item['transaction_type'];
$price = (string) $item['price'];
$description = $item['description'] ?? '';
$currentImage = $item['image_path'];

/* =========================================
   GENERATE CSRF TOKEN
========================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================
   PROCESS UPDATE
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $categoryId = trim($_POST['category'] ?? '');
    $condition = $_POST['condition'] ?? '';
    $transaction = $_POST['transaction'] ?? '';
    $priceInput = trim($_POST['price'] ?? '');
    $description = trim($_POST['description'] ?? '');

    /* Validate CSRF token */
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    }

    /* Validate item name */
    if ($name === '') {
        $errors[] = 'Please enter the item name.';
    } elseif (mb_strlen($name) > 120) {
        $errors[] = 'The item name must not exceed 120 characters.';
    }

    /* Validate category */
    $validCategoryIds = array_map(
        static fn($category) => (int) $category['id'],
        $categories_item
    );

    if (
        $categoryId === '' ||
        !ctype_digit($categoryId) ||
        !in_array((int) $categoryId, $validCategoryIds, true)
    ) {
        $errors[] = 'Please select a valid item category.';
    } else {
        $categoryId = (int) $categoryId;
    }

    /* Validate condition and transaction */
    if (!in_array($condition, $conditions, true)) {
        $errors[] = 'Please select a valid item condition.';
    }

    if (!in_array($transaction, $transactionTypes, true)) {
        $errors[] = 'Please select a valid transaction type.';
    }

    /* Validate price */
    if (
        $priceInput === '' ||
        !is_numeric($priceInput) ||
        (float) $priceInput < 0 ||
        (float) $priceInput > 99999999.99
    ) {
        $errors[] = 'Please enter a valid price.';
    }

    $price = is_numeric($priceInput) ? (float) $priceInput : 0;

    if ($transaction === 'Sell' && $price <= 0) {
        $errors[] = 'A selling price greater than zero is required.';
    }

    if (in_array($transaction, ['Borrow', 'Lend'], true)) {
        $price = 0.00;
    }

    /* Validate description */
    if (mb_strlen($description) > 10000) {
        $errors[] = 'The description must not exceed 10,000 characters.';
    }

    /* =====================================
       VALIDATE OPTIONAL NEW IMAGE
    ===================================== */

    $newImagePath = $currentImage;
    $uploadedImageAbsolutePath = null;
    $oldImageToDelete = null;
    $mimeType = '';

    if (
        isset($_FILES['image']) &&
        $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        $upload = $_FILES['image'];

        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The image upload failed. Please try again.';
        } elseif ($upload['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The image must not exceed 5 MB.';
        } elseif (!is_uploaded_file($upload['tmp_name'])) {
            $errors[] = 'The uploaded image is invalid.';
        } else {
            $imageInfo = @getimagesize($upload['tmp_name']);
            $mimeType = $imageInfo['mime'] ?? '';

            if (!$imageInfo || !isset($allowedMimeTypes[$mimeType])) {
                $errors[] = 'Upload a valid JPG, PNG, WEBP, or GIF image.';
            }
        }
    }

    /* =====================================
       SAVE CHANGES
    ===================================== */

    if (empty($errors)) {
        try {
            /*
             * Prepare a new image if one was uploaded.
             */
            if (
                isset($_FILES['image']) &&
                $_FILES['image']['error'] === UPLOAD_ERR_OK
            ) {
                $uploadDirectory = __DIR__ . '/uploads/items';

                if (
                    !is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true) &&
                    !is_dir($uploadDirectory)
                ) {
                    throw new RuntimeException(
                        'Unable to create the image upload directory.'
                    );
                }

                $extension = $allowedMimeTypes[$mimeType];
                $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

                $uploadedImageAbsolutePath =
                    $uploadDirectory . '/' . $fileName;

                $newImagePath = 'uploads/items/' . $fileName;

                if (!move_uploaded_file(
                    $_FILES['image']['tmp_name'],
                    $uploadedImageAbsolutePath
                )) {
                    throw new RuntimeException('Unable to save the new image.');
                }

                /*
                 * Delete the old image only after the database update succeeds.
                 */
                $oldImageToDelete = $currentImage;
            }

            $descriptionValue = $description !== '' ? $description : null;

            /*
             * Verify ownership again during the UPDATE.
             */
            $update = $conn->prepare(
                "UPDATE items
                 SET
                    category_id = ?,
                    name = ?,
                    transaction_type = ?,
                    item_condition = ?,
                    price = ?,
                    description = ?,
                    image_path = ?
                 WHERE id = ?
                   AND user_id = ?
                   AND status = 'Active'"
            );

            $update->bind_param(
                'isssdssii',
                $categoryId,
                $name,
                $transaction,
                $condition,
                $price,
                $descriptionValue,
                $newImagePath,
                $itemId,
                $userId
            );

            $update->execute();

            $updatedRows = $update->affected_rows;
            $update->close();

            /*
             * The record may be unchanged, so affected_rows can be zero.
             * Verify that the active item still belongs to the user.
             */
            $verify = $conn->prepare(
                "SELECT id
                 FROM items
                 WHERE id = ?
                   AND user_id = ?
                   AND status = 'Active'
                 LIMIT 1"
            );

            $verify->bind_param('ii', $itemId, $userId);
            $verify->execute();

            $stillExists = $verify->get_result()->num_rows > 0;
            $verify->close();

            if (!$stillExists) {
                throw new RuntimeException(
                    'The item could not be updated. It may have been removed.'
                );
            }

            /*
             * Remove the old uploaded image after a successful update.
             * Only delete paths inside uploads/items.
             */
            if (
                $oldImageToDelete &&
                $oldImageToDelete !== $newImagePath
            ) {
                $uploadBase = realpath(__DIR__ . '/uploads/items');
                $oldAbsolutePath = realpath(
                    __DIR__ . '/' . ltrim($oldImageToDelete, '/\\')
                );

                if (
                    $uploadBase !== false &&
                    $oldAbsolutePath !== false &&
                    strpos(
                        $oldAbsolutePath,
                        $uploadBase . DIRECTORY_SEPARATOR
                    ) === 0 &&
                    is_file($oldAbsolutePath)
                ) {
                    @unlink($oldAbsolutePath);
                }
            }

            header(
                'Location: item-view.php?id=' . $itemId . '&updated=1'
            );
            exit;

        } catch (Throwable $e) {
            /*
             * If the update failed, clean up the newly uploaded image.
             */
            if (
                $uploadedImageAbsolutePath !== null &&
                is_file($uploadedImageAbsolutePath)
            ) {
                @unlink($uploadedImageAbsolutePath);
            }

            error_log('Update item error: ' . $e->getMessage());
            $errors[] = 'Unable to update the item. Please try again.';
        }
    }
}

/* =========================================
   DISPLAY PAGE
========================================= */

include __DIR__ . '/includes/header.php';
?>

<main class="wrap">

    <div class="post-heading">
        <a
            class="back"
            href="item-view.php?id=<?= (int) $itemId ?>"
            aria-label="Back to item"
        >
            &larr;
        </a>

        <div>
            <p class="eyebrow">STUDENTLINK MARKETPLACE</p>
            <h1>Edit item</h1>
            <p>Update the details of your item post.</p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="post-errors" role="alert">
            <strong>Please check the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form
        class="post-card"
        method="post"
        enctype="multipart/form-data"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <div class="form-section">
            <h2>Item information</h2>

            <label for="name">Item name *</label>
            <input
                type="text"
                id="name"
                name="name"
                maxlength="120"
                value="<?= e($name) ?>"
                required
            >

            <label for="category">Category *</label>
            <select id="category" name="category" required>
                <option value="">Choose a category</option>

                <?php foreach ($categories_item as $category): ?>
                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= (string) $categoryId === (string) $category['id'] ? 'selected' : '' ?>
                    >
                        <?= e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="condition">Condition *</label>
            <select id="condition" name="condition" required>
                <?php foreach ($conditions as $option): ?>
                    <option
                        value="<?= e($option) ?>"
                        <?= $condition === $option ? 'selected' : '' ?>
                    >
                        <?= e($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-section">
            <h2>Transaction details</h2>

            <label for="transaction">Transaction type *</label>
            <select id="transaction" name="transaction" required>
                <?php foreach ($transactionTypes as $option): ?>
                    <option
                        value="<?= e($option) ?>"
                        <?= $transaction === $option ? 'selected' : '' ?>
                    >
                        <?= e($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="price">Price (₱)</label>
            <input
                type="number"
                id="price"
                name="price"
                min="0"
                max="99999999.99"
                step="0.01"
                value="<?= e((string) $price) ?>"
                required
            >

            <p class="post-help">
                Selling requires a price greater than zero.
                Borrow and lend posts are saved with a price of ₱0.00.
            </p>
        </div>

        <div class="form-section">
            <h2>Description</h2>

            <label for="description">Item description</label>
            <textarea
                id="description"
                name="description"
                rows="5"
                maxlength="10000"
                placeholder="Describe your item."
            ><?= e($description) ?></textarea>
        </div>

        <div class="form-section">
            <h2>Item photo</h2>

            <?php if (!empty($currentImage)): ?>
                <p class="post-help">Current photo:</p>

                <img
                    src="<?= e($currentImage) ?>"
                    alt="Current item photo"
                    class="edit-item-image"
                >
            <?php endif; ?>

            <label for="image">Replace photo (optional)</label>
            <input
                type="file"
                id="image"
                name="image"
                accept="image/jpeg,image/png,image/webp,image/gif"
            >

            <p class="post-help">
                Leave this empty to keep the current photo.
                JPG, PNG, WEBP, or GIF. Maximum 5 MB.
            </p>
        </div>

        <div class="form-actions">
            <a
                class="btn btn-ghost"
                href="item-view.php?id=<?= (int) $itemId ?>"
            >
                Cancel
            </a>

            <button class="btn btn-primary" type="submit">
                Save changes
            </button>
        </div>

    </form>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
