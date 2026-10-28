
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

/* Validate service ID */
$serviceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
}

if (!$serviceId || $serviceId < 1) {
    header('Location: services.php');
    exit;
}

/* Page settings */
$title = 'Edit Service';
$active = 'services';
$css = ['service-offer'];

$errors = [];
$categories_service = [];
$service = null;

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Load the service only if it belongs to the logged-in user */
try {
    $stmt = $conn->prepare(
        "SELECT id, user_id, category_id, title, description, skills
         FROM services
         WHERE id = ?
           AND user_id = ?
           AND status = 'Active'
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare service query.');
    }

    $stmt->bind_param('ii', $serviceId, $userId);
    $stmt->execute();

    $service = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$service) {
        $conn->close();
        header('Location: services.php');
        exit;
    }

    /* Load service categories */
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
    error_log('Service edit loading error: ' . $e->getMessage());
    $errors[] = 'Unable to load this service. Please try again.';
}

/* Form submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service) {

    /* Verify CSRF token */
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] = 'Your session token is invalid. Please refresh the page and try again.';
    }

    /* Read form values */
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
            $errors[] = 'Please select a valid service category.';
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

    /* Update service */
    if (empty($errors)) {
        try {
            $stmt = $conn->prepare(
                "UPDATE services
                 SET category_id = ?,
                     title = ?,
                     description = ?,
                     skills = ?
                 WHERE id = ?
                   AND user_id = ?
                   AND status = 'Active'"
            );

            if (!$stmt) {
                throw new Exception('Unable to prepare service update.');
            }

            $stmt->bind_param(
                'isssii',
                $categoryId,
                $serviceTitle,
                $description,
                $skills,
                $serviceId,
                $userId
            );

            $stmt->execute();
            $updatedRows = $stmt->affected_rows;
            $stmt->close();

            /* Confirm that the service still belongs to this user */
            $verify = $conn->prepare(
                "SELECT id
                 FROM services
                 WHERE id = ?
                   AND user_id = ?
                   AND status = 'Active'
                 LIMIT 1"
            );

            if (!$verify) {
                throw new Exception('Unable to verify service update.');
            }

            $verify->bind_param('ii', $serviceId, $userId);
            $verify->execute();

            $stillExists = $verify->get_result()->num_rows > 0;
            $verify->close();

            if (!$stillExists) {
                throw new Exception('The service is no longer available.');
            }

            header(
                'Location: service-view.php?id=' .
                $serviceId . '&updated=1'
            );
            exit;

        } catch (Throwable $e) {
            error_log('Service update error: ' . $e->getMessage());
            $errors[] = 'Unable to update your service. Please try again.';
        }
    }

    /* Preserve submitted values when validation fails */
    $service['title'] = $serviceTitle;
    $service['category_id'] = $categoryInput;
    $service['description'] = $description;
    $service['skills'] = $skills;
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="wrap">

    <a class="back" href="service-view.php?id=<?= (int) $serviceId ?>"
       aria-label="Back to service">
        &larr;
    </a>

    <form class="form-card" method="post">

        <header>
            <h1>Edit service</h1>
            <p>Update your service details and skills.</p>
        </header>

        <?php if (!empty($errors)): ?>
            <?php show_errors($errors); ?>
        <?php endif; ?>

        <input
            type="hidden"
            name="service_id"
            value="<?= (int) $serviceId ?>"
        >

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
                value="<?= e($service['title'] ?? '') ?>"
            >
        </div>

        <div class="field">
            <label for="category">Category</label>

            <select id="category" name="category" required>
                <option value="">Select category</option>

                <?php foreach ($categories_service as $category): ?>
                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= (string) ($service['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>
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
            ><?= e($service['description'] ?? '') ?></textarea>
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
                value="<?= e($service['skills'] ?? '') ?>"
            >
        </div>

        <div class="actions">
            <a
                class="btn btn-quiet"
                href="service-view.php?id=<?= (int) $serviceId ?>"
            >
                Cancel
            </a>

            <button type="submit" class="btn btn-primary">
                Save changes
            </button>
        </div>

    </form>

</main>

<?php
$conn->close();
require_once __DIR__ . '/includes/footer.php';
?>
