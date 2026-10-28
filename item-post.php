
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Post an item';
$active = 'items';
$css = ['item-post'];

$errors = [];
$success = false;
$categories_item = [];

$name = '';
$categoryId = '';
$condition = 'Good';
$transaction = 'Borrow';
$price = '0.00';
$description = '';

$transactionTypes = ['Borrow', 'Lend', 'Sell'];
$conditions = ['New', 'Good', 'Used'];

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];

$userId = (int) ($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Load item categories only
|--------------------------------------------------------------------------
*/
try {
    $categoryQuery = $conn->prepare(
        "SELECT id, name
         FROM categories
         WHERE kind = 'item'
         ORDER BY name ASC"
    );

    $categoryQuery->execute();
    $categoryResult = $categoryQuery->get_result();

    while ($row = $categoryResult->fetch_assoc()) {
        $categories_item[] = $row;
    }

    $categoryQuery->close();
} catch (Throwable $e) {
    $errors[] = 'Unable to load categories. Please check your database connection.';
}

/*
|--------------------------------------------------------------------------
| Process form submission
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $categoryId = trim($_POST['category'] ?? '');
    $condition = $_POST['condition'] ?? 'Good';
    $transaction = $_POST['transaction'] ?? 'Borrow';
    $priceInput = trim($_POST['price'] ?? '0');
    $description = trim($_POST['description'] ?? '');

    /*
    | Validate CSRF token
    */
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        $errors[] = 'Your session token is invalid. Please refresh the page and try again.';
    }

    /*
    | Validate login
    */
    if ($userId <= 0) {
        $errors[] = 'Please log in before posting an item.';
    }

    /*
    | Validate name
    */
    if ($name === '') {
        $errors[] = 'Please enter the item name.';
    } elseif (mb_strlen($name) > 120) {
        $errors[] = 'The item name must not exceed 120 characters.';
    }

    /*
    | Validate category against the database
    */
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

    /*
    | Validate condition and transaction
    */
    if (!in_array($condition, $conditions, true)) {
        $errors[] = 'Please select a valid item condition.';
    }

    if (!in_array($transaction, $transactionTypes, true)) {
        $errors[] = 'Please select a valid transaction type.';
    }

    /*
    | Validate price
    */
    if (
        $priceInput === '' ||
        !is_numeric($priceInput) ||
        (float) $priceInput < 0
    ) {
        $errors[] = 'Please enter a valid price.';
    } elseif ((float) $priceInput > 99999999.99) {
        $errors[] = 'The price is too large.';
    }

    $price = (float) $priceInput;

    if ($transaction === 'Sell' && $price <= 0) {
        $errors[] = 'Please enter a price greater than zero for an item you want to sell.';
    }

    // Borrowing and lending posts do not require a selling price.
    if (in_array($transaction, ['Borrow', 'Lend'], true)) {
        $price = 0.00;
    }

    /*
    | Validate description
    */
    if (mb_strlen($description) > 10000) {
        $errors[] = 'The description must not exceed 10,000 characters.';
    }

    /*
    | Validate optional image upload
    */
    $imagePath = null;
    $temporaryImagePath = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
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

            if (
                !$imageInfo ||
                !isset($allowedMimeTypes[$mimeType])
            ) {
                $errors[] = 'Please upload a valid JPG, PNG, WEBP, or GIF image.';
            }
        }
    }

    /*
    | Insert into database
    */
    if (empty($errors)) {
        try {
            $uploadDirectory = __DIR__ . '/uploads/items';

            if (
                !is_dir($uploadDirectory) &&
                !mkdir($uploadDirectory, 0755, true) &&
                !is_dir($uploadDirectory)
            ) {
                throw new RuntimeException('Unable to create the upload directory.');
            }

            /*
            | Save the image after validation
            */
            if (
                isset($_FILES['image']) &&
                $_FILES['image']['error'] === UPLOAD_ERR_OK
            ) {
                $extension = $allowedMimeTypes[$mimeType];
                $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

                $destination = $uploadDirectory . '/' . $fileName;
                $temporaryImagePath = $destination;
                $imagePath = 'uploads/items/' . $fileName;

                if (!move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                    throw new RuntimeException('Unable to save the uploaded image.');
                }
            }

            $descriptionValue = $description !== '' ? $description : null;

            $stmt = $conn->prepare(
                "INSERT INTO items
                    (
                        user_id,
                        category_id,
                        name,
                        transaction_type,
                        item_condition,
                        price,
                        description,
                        image_path
                    )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                'iisssdss',
                $userId,
                $categoryId,
                $name,
                $transaction,
                $condition,
                $price,
                $descriptionValue,
                $imagePath
            );

            $stmt->execute();
            $newItemId = $conn->insert_id;
            $stmt->close();

            /*
            | Redirect after successful creation
            */
            header('Location: item-view.php?id=' . $newItemId . '&posted=1');
            exit;

        } catch (Throwable $e) {
            // Remove the newly uploaded image if saving the post failed.
            if (
                $temporaryImagePath !== null &&
                is_file($temporaryImagePath)
            ) {
                @unlink($temporaryImagePath);
            }

            error_log('Item post error: ' . $e->getMessage());
            $errors[] = 'Unable to post your item right now. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Generate CSRF token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include __DIR__ . '/includes/header.php';
?>

<main class="wrap">
    <div class="post-heading">
        <a class="back" href="items.php" aria-label="Back to items">
            &larr;
        </a>

        <div>
            <p class="eyebrow">STUDENTLINK MARKETPLACE</p>
            <h1>Post an item</h1>
            <p>Share something with your fellow students.</p>
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

    <?php if ($userId <= 0): ?>
        <div class="post-errors" role="alert">
            You need to log in before you can post an item.
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
            <p class="post-help">
                Enter the details so other students know what you are offering.
            </p>

            <label for="name">Item name *</label>
            <input
                type="text"
                id="name"
                name="name"
                maxlength="120"
                placeholder="e.g. Scientific calculator"
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

            <?php if (empty($categories_item)): ?>
                <p class="post-help">
                    No item categories are available. Add a category with
                    kind set to item in your categories table first.
                </p>
            <?php endif; ?>

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

            <label for="transaction">What do you want to do? *</label>
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
            >

            <p class="post-help">
                A price greater than zero is required when selling.
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
                placeholder="Describe the item and any details students should know."
            ><?= e($description) ?></textarea>
        </div>

        <div class="form-section">
            <h2>Item photo</h2>

            <label for="image">Upload an image (optional)</label>
            <input
                type="file"
                id="image"
                name="image"
                accept="image/jpeg,image/png,image/webp,image/gif"
            >

            <p class="post-help">
                JPG, PNG, WEBP, or GIF. Maximum file size: 5 MB.
            </p>
        </div>

        <div class="form-actions">
            <a class="btn btn-ghost" href="items.php">Cancel</a>

            <button
                class="btn btn-primary"
                type="submit"
                <?= $userId <= 0 || empty($categories_item) ? 'disabled' : '' ?>
            >
                Publish item
            </button>
        </div>
    </form>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
