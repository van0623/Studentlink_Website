<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Logged-in user */
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

/* Page settings */
$title = 'Service Details';
$active = 'services';
$css = ['service-view'];

/* Validate service ID */
$serviceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$service = null;
$errorMessage = '';
$requestError = '';

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Load service details */
if (!$serviceId || $serviceId < 1) {
    $errorMessage = 'Invalid service selected.';
} else {
    try {
        $sql = "
            SELECT
                s.id,
                s.user_id,
                s.category_id,
                s.title,
                s.description,
                s.skills,
                s.status,
                s.created_at,
                c.name AS category,
                u.full_name AS provider_name,
                u.course,
                u.year_level,
                u.photo_path AS provider_photo
            FROM services s
            INNER JOIN categories c
                ON c.id = s.category_id
            INNER JOIN users u
                ON u.id = s.user_id
            WHERE s.id = ?
              AND c.kind = 'service'
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Unable to prepare service query.');
        }

        $stmt->bind_param('i', $serviceId);
        $stmt->execute();

        $service = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$service) {
            $errorMessage = 'This service could not be found.';
        } elseif (
            $service['status'] !== 'Active' &&
            (int) $service['user_id'] !== $currentUserId
        ) {
            $service = null;
            $errorMessage = 'This service is no longer available.';
        }
    } catch (Throwable $e) {
        error_log('Service view error: ' . $e->getMessage());
        $service = null;
        $errorMessage = 'Unable to load this service. Please try again.';
    }
}

/* Check ownership */
$isOwner = (
    $service !== null &&
    $currentUserId > 0 &&
    $currentUserId === (int) $service['user_id']
);

/* Handle service request submission */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['request_service'])
) {
    $postedServiceId = filter_input(
        INPUT_POST,
        'service_id',
        FILTER_VALIDATE_INT
    );

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
    } elseif (
        !$postedServiceId ||
        !$serviceId ||
        $postedServiceId !== (int) $serviceId
    ) {
        $requestError = 'Invalid service request.';
    } elseif (!$service) {
        $requestError = 'This service could not be found.';
    } elseif ($service['status'] !== 'Active') {
        $requestError = 'This service is no longer available.';
    } elseif ((int) $service['user_id'] === $currentUserId) {
        $requestError = 'You cannot request your own service.';
    } else {
        $providerId = (int) $service['user_id'];

        /* Check whether a request already exists */
        $checkStmt = $conn->prepare("
            SELECT id, status
            FROM service_requests
            WHERE service_id = ?
              AND requester_id = ?
            LIMIT 1
        ");

        if (!$checkStmt) {
            error_log('Service request check failed: ' . $conn->error);
            $requestError = 'Unable to process your request right now.';
        } else {
            $checkStmt->bind_param(
                'ii',
                $serviceId,
                $currentUserId
            );

            $checkStmt->execute();

            $existingRequest = $checkStmt
                ->get_result()
                ->fetch_assoc();

            $checkStmt->close();

            if ($existingRequest) {
                $requestError = 'You have already submitted a request for this service. Current status: '
                    . $existingRequest['status'] . '.';
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO service_requests (
                        service_id,
                        requester_id,
                        provider_id,
                        status
                    )
                    VALUES (?, ?, ?, 'Pending')
                ");

                if (!$insertStmt) {
                    error_log(
                        'Service request insert preparation failed: '
                        . $conn->error
                    );

                    $requestError = 'Unable to submit your request right now.';
                } else {
                    $insertStmt->bind_param(
                        'iii',
                        $serviceId,
                        $currentUserId,
                        $providerId
                    );

                    if ($insertStmt->execute()) {
                        $insertStmt->close();

                        header(
                            'Location: service-view.php?id='
                            . (int) $serviceId
                            . '&request_sent=1'
                        );
                        exit;
                    }

                    if ($insertStmt->errno === 1062) {
                        $requestError = 'You have already submitted a request for this service.';
                    } else {
                        error_log(
                            'Service request insert failed: '
                            . $insertStmt->error
                        );

                        $requestError = 'Something went wrong while submitting your request.';
                    }

                    $insertStmt->close();
                }
            }
        }
    }
}

/* Get the current student's request status */
$existingRequestStatus = null;

if (
    $service !== null &&
    $currentUserId > 0 &&
    !$isOwner
) {
    $statusStmt = $conn->prepare("
        SELECT status
        FROM service_requests
        WHERE service_id = ?
          AND requester_id = ?
        LIMIT 1
    ");

    if ($statusStmt) {
        $statusStmt->bind_param(
            'ii',
            $serviceId,
            $currentUserId
        );

        $statusStmt->execute();

        $statusResult = $statusStmt
            ->get_result()
            ->fetch_assoc();

        if ($statusResult) {
            $existingRequestStatus = $statusResult['status'];
        }

        $statusStmt->close();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="wrap service-view-page">

    <a class="back" href="services.php" aria-label="Back to services">
        &larr; Back to Services
    </a>

    <?php if (isset($_GET['posted']) && $_GET['posted'] === '1'): ?>
        <div class="service-notice service-notice-success">
            Your service has been posted successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="service-notice service-notice-success">
            Your service has been updated successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['request_sent']) && $_GET['request_sent'] === '1'): ?>
        <div class="service-notice service-notice-success">
            Your service request has been submitted successfully.
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>

        <section class="service-message">
            <h1>Service unavailable</h1>
            <p><?= e($errorMessage) ?></p>

            <a class="btn btn-primary" href="services.php">
                Browse Services
            </a>
        </section>

    <?php else: ?>

        <section class="service-detail-card">

            <div class="service-detail-content">

                <div class="service-detail-top">
                    <span class="service-category">
                        <?= e($service['category']) ?>
                    </span>

                    <span class="service-status <?= $service['status'] === 'Active' ? 'status-active' : 'status-removed' ?>">
                        <?= e($service['status']) ?>
                    </span>
                </div>

                <h1><?= e($service['title']) ?></h1>

                <p class="service-intro">
                    Offered by <?= e($service['provider_name']) ?>
                </p>

                <div class="service-description">
                    <h2>About this service</h2>

                    <?php if (!empty($service['description'])): ?>
                        <p><?= nl2br(e($service['description'])) ?></p>
                    <?php else: ?>
                        <p class="muted-text">
                            No description was provided.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="service-skills">
                    <h2>Skills and expertise</h2>

                    <?php if (!empty($service['skills'])): ?>
                        <p><?= nl2br(e($service['skills'])) ?></p>
                    <?php else: ?>
                        <p class="muted-text">
                            No skills or expertise listed.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="service-provider">
                    <div class="provider-avatar">
                        <?php if (!empty($service['provider_photo'])): ?>
                            <img
                                src="<?= e($service['provider_photo']) ?>"
                                alt="<?= e($service['provider_name']) ?>"
                            >
                        <?php else: ?>
                            <span>
                                <?= e(strtoupper(substr($service['provider_name'], 0, 1))) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="provider-info">
                        <span class="provider-label">Service provider</span>

                        <strong>
                            <?= e($service['provider_name']) ?>
                        </strong>

                        <?php
                        $providerDetails = array_filter([
                            $service['course'] ?? '',
                            $service['year_level'] ?? ''
                        ]);
                        ?>

                        <?php if (!empty($providerDetails)): ?>
                            <small>
                                <?= e(implode(' · ', $providerDetails)) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="service-date">
                    Posted on
                    <?= e(date('F j, Y', strtotime($service['created_at']))) ?>
                </p>

                <?php if ($requestError !== ''): ?>
                    <div class="service-notice">
                        <?= e($requestError) ?>
                    </div>
                <?php endif; ?>

                <?php if ($isOwner): ?>

                    <div class="service-owner-panel">
                        <p class="owner-note">
                            You posted this service.
                        </p>

                        <?php if ($service['status'] === 'Active'): ?>

                            <div class="service-view-actions">

                                <a
                                    class="btn btn-primary"
                                    href="service-edit.php?id=<?= (int) $service['id'] ?>"
                                >
                                    Edit Service
                                </a>

                                <form
                                    action="service-delete.php"
                                    method="post"
                                    onsubmit="return confirm('Are you sure you want to remove this service?');"
                                >
                                    <input
                                        type="hidden"
                                        name="service_id"
                                        value="<?= (int) $service['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken) ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-danger"
                                    >
                                        Delete Service
                                    </button>
                                </form>

                            </div>

                        <?php else: ?>

                            <p class="muted-text">
                                This service has been removed from the public listing.
                            </p>

                        <?php endif; ?>
                    </div>

                <?php elseif ($service['status'] === 'Active'): ?>

                    <div class="service-request-panel">

                        <?php if ($currentUserId < 1): ?>

                            <a class="btn btn-primary" href="login.php">
                                Log in to Request
                            </a>

                        <?php elseif ($existingRequestStatus !== null): ?>

                            <button
                                type="button"
                                class="btn btn-primary"
                                disabled
                            >
                                Request <?= e($existingRequestStatus) ?>
                            </button>

                            <p class="muted-text">
                                You have already submitted a request for this service.
                            </p>

                        <?php else: ?>

                            <form
                                action="service-view.php?id=<?= (int) $service['id'] ?>"
                                method="post"
                            >
                                <input
                                    type="hidden"
                                    name="service_id"
                                    value="<?= (int) $service['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                    name="request_service"
                                    value="1"
                                    onclick="return confirm('Do you want to submit a request for this service?');"
                                >
                                    Request Service
                                </button>
                            </form>

                            <p class="muted-text">
                                Submit a request to the student offering this service.
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
require_once __DIR__ . '/includes/footer.php';
?>