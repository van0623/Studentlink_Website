
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

$currentUserId = (int) $_SESSION['user_id'];

/* Verify active account */
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

/* Validate resource ID */
$resourceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$resourceId || $resourceId < 1) {
    exit('Invalid resource ID.');
}

/* Get active resource */
$stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        title,
        file_name,
        file_path,
        file_type
    FROM resources
    WHERE id = ?
      AND status = 'Active'
    LIMIT 1
");

if (!$stmt) {
    exit('Unable to retrieve the resource.');
}

$stmt->bind_param('i', $resourceId);
$stmt->execute();

$result = $stmt->get_result();
$resource = $result->fetch_assoc();

$stmt->close();

if (!$resource) {
    exit('This resource could not be found or is no longer available.');
}

/* Validate stored file path */
$storedPath = str_replace(
    '\\',
    '/',
    trim($resource['file_path'] ?? '')
);

if (
    $storedPath === '' ||
    strpos($storedPath, 'uploads/resources/') !== 0 ||
    strpos($storedPath, '..') !== false ||
    strpos($storedPath, "\0") !== false
) {
    exit('Invalid file path.');
}

$basePath = realpath(__DIR__ . '/uploads/resources');
$filePath = realpath(__DIR__ . '/' . $storedPath);

if (
    $basePath === false ||
    $filePath === false ||
    !is_file($filePath) ||
    strpos($filePath, $basePath . DIRECTORY_SEPARATOR) !== 0
) {
    exit('The uploaded file could not be found.');
}

/* Open the file before sending notifications */
$fileHandle = fopen($filePath, 'rb');

if ($fileHandle === false) {
    exit('Unable to open the uploaded file.');
}

/*
 * Notify the downloader.
 */
$resourceTitle = $resource['title'];
$ownerId = (int) $resource['user_id'];

createNotification(
    $conn,
    $currentUserId,
    'You successfully downloaded: ' . $resourceTitle,
    'resource-view.php?id=' . $resourceId
);

/*
 * Notify the owner only when another user downloads it.
 */
if ($ownerId !== $currentUserId) {
    $downloaderStmt = $conn->prepare("
        SELECT full_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $downloaderName = 'A student';

    if ($downloaderStmt) {
        $downloaderStmt->bind_param('i', $currentUserId);
        $downloaderStmt->execute();

        $downloaderResult = $downloaderStmt->get_result();
        $downloader = $downloaderResult->fetch_assoc();

        if (!empty($downloader['full_name'])) {
            $downloaderName = $downloader['full_name'];
        }

        $downloaderStmt->close();
    }

    createNotification(
        $conn,
        $ownerId,
        $downloaderName . ' downloaded your resource: ' . $resourceTitle,
        'resource-view.php?id=' . $resourceId
    );
}

/* Prepare safe download filename */
$originalFileName = basename(
    str_replace('\\', '/', $resource['file_name'])
);

$originalFileName = str_replace(
    ["\r", "\n", '"'],
    '',
    $originalFileName
);

if ($originalFileName === '') {
    $originalFileName = 'resource-file';
}

/* Detect MIME type */
$fileMimeType = 'application/octet-stream';

if (function_exists('mime_content_type')) {
    $detectedMimeType = mime_content_type($filePath);

    if ($detectedMimeType !== false) {
        $fileMimeType = $detectedMimeType;
    }
}

/* Send download headers */
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $fileMimeType);
header(
    'Content-Disposition: attachment; filename="' .
    $originalFileName . '"; filename*=UTF-8\'\'' .
    rawurlencode($originalFileName)
);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, must-revalidate');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

/* Send file */
fpassthru($fileHandle);
fclose($fileHandle);

exit;
