
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Require an active logged-in account */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];

$accountStmt = $conn->prepare("
    SELECT status
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$accountStmt) {
    exit('Unable to verify your account.');
}

$accountStmt->bind_param('i', $currentUserId);
$accountStmt->execute();

$accountResult = $accountStmt->get_result();
$account = $accountResult->fetch_assoc();

$accountStmt->close();

if (!$account || $account['status'] !== 'Active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

$title = 'Resource';
$active = 'resources';
$css = ['resource-view'];

$resource = null;
$errorMessage = '';
$uploadedBy = 'Student';
$fileExists = false;
$fileUrl = '';
$fileExtension = '';
$previewType = 'none';
$formattedSize = 'Size unavailable';
$uploadedDate = '';

$resourceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$resourceId || $resourceId < 1) {
    $errorMessage = 'Invalid resource ID.';
} else {
    $sql = "
        SELECT
            r.id,
            r.user_id,
            r.subject_id,
            r.title,
            r.resource_type,
            r.description,
            r.file_name,
            r.file_path,
            r.file_type,
            r.file_size,
            r.created_at,
            s.name AS subject_name
        FROM resources r
        LEFT JOIN subjects s ON s.id = r.subject_id
        WHERE r.id = ?
          AND r.status = 'Active'
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param('i', $resourceId);
        $stmt->execute();

        $result = $stmt->get_result();
        $resource = $result->fetch_assoc();

        $stmt->close();
    }

    if (!$resource) {
        $errorMessage = 'This resource could not be found or is no longer available.';
    }
}

if ($resource) {
    $resourceTitle = $resource['title'];
    $subjectName = $resource['subject_name'] ?? 'General';
    $description = trim($resource['description'] ?? '');
    $resourceType = $resource['resource_type'] ?? 'Resource';
    $originalFileName = $resource['file_name'] ?? 'Resource file';

    if ($description === '') {
        $description = 'No description provided.';
    }

    /* Format file size */
    $fileSize = (int) ($resource['file_size'] ?? 0);

    if ($fileSize >= 1048576) {
        $formattedSize = number_format($fileSize / 1048576, 2) . ' MB';
    } elseif ($fileSize >= 1024) {
        $formattedSize = number_format($fileSize / 1024, 1) . ' KB';
    } elseif ($fileSize > 0) {
        $formattedSize = $fileSize . ' bytes';
    }

    /* Format upload date */
    if (!empty($resource['created_at'])) {
        $timestamp = strtotime($resource['created_at']);

        if ($timestamp !== false) {
            $uploadedDate = date('M j, Y', $timestamp);
        }
    }

    /* Find uploader name */
    $uploaderId = (int) $resource['user_id'];

    $userStmt = $conn->prepare("
        SELECT full_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if ($userStmt) {
        $userStmt->bind_param('i', $uploaderId);
        $userStmt->execute();

        $userResult = $userStmt->get_result();
        $userRow = $userResult->fetch_assoc();

        if (!empty($userRow['full_name'])) {
            $uploadedBy = $userRow['full_name'];
        }

        $userStmt->close();
    }

    if ($uploadedBy === 'Student') {
        $uploadedBy = 'Student #' . $uploaderId;
    }

    /*
     * Check the uploaded file.
     * Only allow files inside uploads/resources/.
     */
    $storedPath = str_replace(
        '\\',
        '/',
        trim($resource['file_path'] ?? '')
    );

    if (
        $storedPath !== '' &&
        strpos($storedPath, 'uploads/resources/') === 0 &&
        strpos($storedPath, '..') === false &&
        strpos($storedPath, "\0") === false
    ) {
        $absolutePath = __DIR__ . '/' . $storedPath;

        $realBasePath = realpath(__DIR__ . '/uploads/resources');
        $realFilePath = realpath($absolutePath);

        if (
            $realBasePath !== false &&
            $realFilePath !== false &&
            is_file($realFilePath) &&
            strpos(
                $realFilePath,
                $realBasePath . DIRECTORY_SEPARATOR
            ) === 0
        ) {
            $fileExists = true;

            /* Keep preview files accessible */
            $fileUrl = $storedPath;

            $fileExtension = strtolower(
                pathinfo($originalFileName, PATHINFO_EXTENSION)
            );

            if ($fileExtension === '') {
                $fileExtension = strtolower(
                    pathinfo($storedPath, PATHINFO_EXTENSION)
                );
            }

            if ($fileExtension === 'pdf') {
                $previewType = 'pdf';
            } elseif (
                in_array(
                    $fileExtension,
                    ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    true
                )
            ) {
                $previewType = 'image';
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="css/resource-view.css">

<main class="wrap resource-view-page">

    <a
        class="back"
        href="resources.php"
        aria-label="Back to resources"
    >
        &larr;
    </a>

    <?php if ($errorMessage !== ''): ?>

        <section class="resource-message">
            <div class="message-icon">!</div>

            <h1>Resource unavailable</h1>

            <p><?= e($errorMessage) ?></p>

            <a class="btn btn-primary" href="resources.php">
                Back to resources
            </a>
        </section>

    <?php else: ?>

        <div class="detail">

            <section class="stack">

                <div class="info lead">
                    <span class="eyebrow">RESOURCE DETAILS</span>

                    <h1><?= e($resourceTitle) ?></h1>

                    <p class="subject-name">
                        <?= e($subjectName) ?>
                    </p>

                    <div class="resource-tags">
                        <span class="tag">
                            <?= e($resourceType) ?>
                        </span>

                        <?php if ($uploadedDate !== ''): ?>
                            <span class="tag tag-muted">
                                <?= e($uploadedDate) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info">
                    <h2>Description</h2>

                    <p class="description">
                        <?= nl2br(e($description ?? '')) ?>
                    </p>
                </div>

                <div class="info file-info">
                    <h2>File information</h2>

                    <div class="file-name-row">
                        <div class="file-icon">
                            <icon name="file-text" size="24" />
                        </div>

                        <div class="file-name-details">
                            <p class="file-name">
                                <?= e($originalFileName) ?>
                            </p>

                            <p class="file-meta">
                                <?= e($formattedSize) ?>

                                <?php if ($fileExtension !== ''): ?>
                                    <span>·</span>
                                    <?= e(strtoupper($fileExtension)) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="uploader">
                        <span>Uploaded by</span>
                        <strong><?= e($uploadedBy) ?></strong>
                    </div>
                </div>

                <div class="actions">
                    <a class="btn btn-quiet" href="resources.php">
                        Back
                    </a>

                    <?php if ($fileExists): ?>
                        <a
                            class="btn btn-primary"
                            href="resource-download.php?id=<?= (int) $resource['id'] ?>"
                        >
                            <icon name="download" size="17" />
                            Download file
                        </a>
                    <?php else: ?>
                        <button class="btn btn-disabled" disabled>
                            File unavailable
                        </button>
                    <?php endif; ?>
                </div>

            </section>

            <section class="preview-card">

                <div class="preview-heading">
                    <div>
                        <span class="eyebrow">FILE VIEWER</span>
                        <h2>Preview</h2>
                    </div>

                    <?php if ($fileExists): ?>
                        <span class="preview-status">Available</span>
                    <?php else: ?>
                        <span class="preview-status unavailable">
                            Unavailable
                        </span>
                    <?php endif; ?>
                </div>

                <div class="preview-content">

                    <?php if (!$fileExists): ?>

                        <div class="preview-empty">
                            <icon name="file-warning" size="42" />

                            <h3>File not found</h3>

                            <p>
                                The uploaded file may have been moved,
                                deleted, or saved in a different folder.
                            </p>
                        </div>

                    <?php elseif ($previewType === 'pdf'): ?>

                        <iframe
                            class="pdf-frame"
                            src="<?= e($fileUrl) ?>#toolbar=1&navpanes=0"
                            title="PDF preview"
                        ></iframe>

                    <?php elseif ($previewType === 'image'): ?>

                        <div class="image-preview">
                            <img
                                src="<?= e($fileUrl) ?>"
                                alt="<?= e($resourceTitle) ?>"
                            >
                        </div>

                    <?php else: ?>

                        <div class="preview-empty">
                            <icon name="file-text" size="42" />

                            <h3>Preview not available</h3>

                            <p>
                                Download this file to view its contents.
                            </p>

                            <span class="supported-note">
                                <?= e(strtoupper($fileExtension ?: 'FILE')) ?>
                            </span>
                        </div>

                    <?php endif; ?>

                </div>
            </section>

        </div>

    <?php endif; ?>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
