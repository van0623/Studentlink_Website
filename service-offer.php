
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Only logged-in users can offer services */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$errors = [];
$categories_service = [];

/* Page settings */
$title = 'Offer a service';
$active = 'services';
$css = ['service-offer'];

/* CSRF protection */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Load service categories */
try {
    $categoryQuery = $conn->query(
        "SELECT id, name
         FROM categories
         WHERE kind = 'service'
         ORDER BY name ASC"
    );

    while ($category = $categoryQuery->fetch_assoc()) {
        $categories_service[] = $category;
    }
} catch (Throwable $e) {
    error_log('Service categories error: ' . $e->getMessage());
    $errors[] = 'Unable to load service categories. Please try again.';
}

/* Form submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Verify CSRF token */
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] = 'Your session token is invalid. Please refresh the page and try again.';
    }

    /* Read submitted values */
    $serviceTitle = trim($_POST['title'] ?? '');
    $categoryInput = $_POST['category'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $skills = trim($_POST['skills'] ?? '');

    /* Validate title */
    if ($serviceTitle === '') {
        $errors[] = 'Service title is required.';
    } elseif (mb_strlen($serviceTitle) > 120) {
        $errors[] = 'Service title must not exceed 120 characters.';
    }

    /* Validate category */
    $categoryId = filter_var(
        $categoryInput,
        FILTER_VALIDATE_INT
    );

    if ($categoryId === false || $categoryId < 1) {
        $errors[] = 'Please select a valid service category.';
    } else {
        $categoryExists = false;

        foreach ($categories_service as $category) {
            if ((int) $category['id'] === $categoryId) {
                $categoryExists = true;
                break;
            }
        }

        if (!$categoryExists) {
            $errors[] = 'The selected category is not a valid service category.';
        }
    }

    /* Validate description */
    if ($description === '') {
        $errors[] = 'Description is required.';
    } elseif (mb_strlen($description) > 10000) {
        $errors[] = 'Description must not exceed 10,000 characters.';
    }

    /* Validate skills */
    if ($skills === '') {
        $errors[] = 'Skills or expertise is required.';
    } elseif (mb_strlen($skills) > 2000) {
        $errors[] = 'Skills or expertise must not exceed 2,000 characters.';
    }

    /* Save service */
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO services
                        (user_id, category_id, title, description, skills, status)
                    VALUES (?, ?, ?, ?, ?, 'Active')";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                throw new Exception('Unable to prepare the service insert query.');
            }

            $stmt->bind_param(
                'iisss',
                $userId,
                $categoryId,
                $serviceTitle,
                $description,
                $skills
            );

            $stmt->execute();
            $stmt->close();

            header('Location: services.php?posted=1');
            exit;

        } catch (Throwable $e) {
            error_log('Service posting error: ' . $e->getMessage());
            $errors[] = 'Unable to post your service right now. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="wrap">

    <a class="back" href="services.php" aria-label="Back to services">
        &larr;
    </a>

    <form class="form-card" method="post">

        <header>
            <h1>Offer a service</h1>
            <p>Share your skills and help fellow students.</p>
        </header>

        <?php if (!empty($errors)): ?>
            <?php show_errors($errors); ?>
        <?php endif; ?>

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <div class="field">
            <label for="title">Service title</label>

            <input
                id="title"
                name="title"
                type="text"
                maxlength="120"
                required
                value="<?= e($_POST['title'] ?? '') ?>"
                placeholder="Example: Basic Graphic Design"
            >
        </div>

        <div class="field">
            <label for="category">Category</label>

            <select id="category" name="category" required>
                <option value="">Select category</option>

                <?php foreach ($categories_service as $category): ?>
                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= (string) ($_POST['category'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>
                    >
                        <?= e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="description">Description</label>

            <textarea
                id="description"
                name="description"
                maxlength="10000"
                required
                placeholder="Explain the service you can provide..."
            ><?= e($_POST['description'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label for="skills">Skills or expertise</label>

            <input
                id="skills"
                name="skills"
                type="text"
                maxlength="2000"
                required
                placeholder="Separate with commas"
                value="<?= e($_POST['skills'] ?? '') ?>"
            >
        </div>

        <div class="actions">
            <a class="btn btn-quiet" href="services.php">
                Cancel
            </a>

            <button type="submit" class="btn btn-primary">
                Post service
            </button>
        </div>

    </form>

</main>

<?php
$conn->close();
require_once __DIR__ . '/includes/footer.php';
?>
