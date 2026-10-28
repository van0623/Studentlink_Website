<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Upload a resource';
$active = 'resources';
$css = ['resource-upload'];

$errors = [];

$uploadDir = __DIR__ . '/uploads/resources/';
$uploadPath = 'uploads/resources/';
$maxFileSize = 10 * 1024 * 1024;

$allowedExtensions = [
    'pdf', 'doc', 'docx', 'ppt', 'pptx',
    'xls', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'
];

$allowedMimeTypes = [
    'pdf' => ['application/pdf'],
    'doc' => [
        'application/msword',
        'application/octet-stream'
    ],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream'
    ],
    'ppt' => [
        'application/vnd.ms-powerpoint',
        'application/octet-stream'
    ],
    'pptx' => [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
        'application/octet-stream'
    ],
    'xls' => [
        'application/vnd.ms-excel',
        'application/octet-stream'
    ],
    'xlsx' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream'
    ],
    'txt' => [
        'text/plain',
        'application/octet-stream'
    ],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png']
];

/*
|--------------------------------------------------------------------------
| Get the logged-in user's ID
|--------------------------------------------------------------------------
*/

$userId = $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? $_SESSION['user']['user_id']
    ?? $_SESSION['user']['id']
    ?? null;

if (!is_numeric($userId) || (int) $userId < 1) {
    $userId = null;
} else {
    $userId = (int) $userId;
}

/*
|--------------------------------------------------------------------------
| Read ENUM values from MySQL
|--------------------------------------------------------------------------
*/

function getEnumValues(string $columnType): array
{
    if (stripos($columnType, 'enum(') !== 0) {
        return [];
    }

    preg_match_all(
        "/'((?:\\\\\\\\'|[^'])*)'/",
        $columnType,
        $matches
    );

    return $matches[1] ?? [];
}

/*
|--------------------------------------------------------------------------
| Fetch subjects
|--------------------------------------------------------------------------
*/

$uploadSubjectOptions = [];

$subjectQuery = $conn->query(
    "SELECT id, name FROM subjects ORDER BY name"
);

if ($subjectQuery instanceof mysqli_result) {
    while ($subjectRow = $subjectQuery->fetch_assoc()) {
        $uploadSubjectOptions[] = [
            'id' => (int) $subjectRow['id'],
            'name' => (string) $subjectRow['name']
        ];
    }

    $subjectQuery->free();
} else {
    error_log('Subject query failed: ' . $conn->error);

    $errors[] = 'Unable to load subjects. Please check the subjects table.';
}

/*
|--------------------------------------------------------------------------
| Fetch resource types
|--------------------------------------------------------------------------
*/

$resourceTypes = [];

$typeColumnQuery = $conn->query(
    "SHOW COLUMNS FROM resources LIKE 'resource_type'"
);

if (
    $typeColumnQuery instanceof mysqli_result &&
    ($typeColumn = $typeColumnQuery->fetch_assoc())
) {
    $resourceTypes = getEnumValues($typeColumn['Type']);
    $typeColumnQuery->free();
}

if (empty($resourceTypes)) {
    $resourceTypes = [
        'Notes',
        'Reviewer',
        'Module',
        'Presentation',
        'Worksheet',
        'Other'
    ];
}

/*
|--------------------------------------------------------------------------
| Determine resource status
|--------------------------------------------------------------------------
*/

$status = 'pending';

$statusColumnQuery = $conn->query(
    "SHOW COLUMNS FROM resources LIKE 'status'"
);

if (
    $statusColumnQuery instanceof mysqli_result &&
    ($statusColumn = $statusColumnQuery->fetch_assoc())
) {
    $statusType = $statusColumn['Type'];
    $statusDefault = $statusColumn['Default'];
    $statusOptions = getEnumValues($statusType);

    if (!empty($statusOptions)) {
        if (in_array('pending', $statusOptions, true)) {
            $status = 'pending';
        } elseif (
            $statusDefault !== null &&
            in_array($statusDefault, $statusOptions, true)
        ) {
            $status = $statusDefault;
        } else {
            $status = $statusOptions[0];
        }
    } elseif ($statusDefault !== null && $statusDefault !== '') {
        $status = $statusDefault;
    }

    $statusColumnQuery->free();
}

/*
|--------------------------------------------------------------------------
| Process upload form
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resourceTitle = trim($_POST['title'] ?? '');

    $subjectId = filter_var(
        $_POST['subject'] ?? '',
        FILTER_VALIDATE_INT
    );

    $resourceType = trim($_POST['type'] ?? '');
    $description = trim($_POST['description'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Validate user and form fields
    |--------------------------------------------------------------------------
    */

    if ($userId === null) {
        $errors[] = 'Please log in before uploading a resource.';
    }

    if ($resourceTitle === '') {
        $errors[] = 'Please enter a resource title.';
    } elseif (mb_strlen($resourceTitle) > 150) {
        $errors[] = 'The resource title must not exceed 150 characters.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate selected subject
    |--------------------------------------------------------------------------
    */

    if ($subjectId === false || $subjectId === null || $subjectId < 1) {
        $errors[] = 'Please select a subject.';
    } else {
        $subjectCheck = $conn->prepare(
            'SELECT id FROM subjects WHERE id = ?'
        );

        if ($subjectCheck) {
            $subjectCheck->bind_param('i', $subjectId);
            $subjectCheck->execute();
            $subjectCheck->store_result();

            if ($subjectCheck->num_rows === 0) {
                $errors[] = 'The selected subject does not exist.';
            }

            $subjectCheck->close();
        } else {
            error_log(
                'Subject validation failed: ' . $conn->error
            );

            $errors[] = 'Unable to validate the selected subject.';
        }
    }

    if (!in_array($resourceType, $resourceTypes, true)) {
        $errors[] = 'Please select a valid resource type.';
    }

    if (mb_strlen($description) > 5000) {
        $errors[] = 'The description must not exceed 5,000 characters.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate uploaded file
    |--------------------------------------------------------------------------
    */

    $uploadedFile = null;
    $originalFileName = '';
    $extension = '';
    $mimeType = '';
    $fileSize = 0;

    if (
        !isset($_FILES['file']) ||
        !is_array($_FILES['file']) ||
        !isset($_FILES['file']['error']) ||
        $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        $errors[] = 'Please select a file to upload.';
    } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'The file upload failed. Please try again.';
    } else {
        $uploadedFile = $_FILES['file'];

        $originalFileName = basename($uploadedFile['name']);

        $extension = strtolower(
            pathinfo($originalFileName, PATHINFO_EXTENSION)
        );

        $fileSize = (int) $uploadedFile['size'];

        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            $errors[] = 'The uploaded file is invalid.';
        }

        if ($fileSize < 1 || $fileSize > $maxFileSize) {
            $errors[] = 'The file must be larger than 0 bytes and no more than 10 MB.';
        }

        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'This file type is not allowed.';
        }

        if (
            is_uploaded_file($uploadedFile['tmp_name']) &&
            in_array($extension, $allowedExtensions, true)
        ) {
            if (!class_exists('finfo')) {
                $errors[] = 'File validation is unavailable on this server.';
            } else {
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $fileInfo->file(
                    $uploadedFile['tmp_name']
                );

                if (
                    !isset($allowedMimeTypes[$extension]) ||
                    !in_array(
                        $mimeType,
                        $allowedMimeTypes[$extension],
                        true
                    )
                ) {
                    $errors[] = 'The file contents do not match a supported file type.';
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Save uploaded file and database record
    |--------------------------------------------------------------------------
    */

    if (empty($errors) && $uploadedFile !== null) {
        if (
            !is_dir($uploadDir) &&
            !mkdir($uploadDir, 0755, true) &&
            !is_dir($uploadDir)
        ) {
            $errors[] = 'Unable to create the upload folder.';
        } elseif (!is_writable($uploadDir)) {
            $errors[] = 'The upload folder is not writable.';
        } else {
            try {
                $storedFileName = bin2hex(random_bytes(16))
                    . '.' . $extension;
            } catch (Throwable $exception) {
                $storedFileName = uniqid('resource_', true)
                    . '.' . $extension;
            }

            $destination = $uploadDir . $storedFileName;
            $relativeFilePath = $uploadPath . $storedFileName;

            if (
                !move_uploaded_file(
                    $uploadedFile['tmp_name'],
                    $destination
                )
            ) {
                $errors[] = 'Unable to save the uploaded file.';
            } else {
                $insert = $conn->prepare(
                    "INSERT INTO resources (
                        user_id,
                        subject_id,
                        title,
                        resource_type,
                        description,
                        file_name,
                        file_path,
                        file_type,
                        file_size,
                        status,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );

                if (!$insert) {
                    error_log(
                        'Resource insert preparation failed: '
                        . $conn->error
                    );

                    @unlink($destination);

                    $errors[] = 'Unable to prepare the database record.';
                } else {
                    $insert->bind_param(
                        'iissssssis',
                        $userId,
                        $subjectId,
                        $resourceTitle,
                        $resourceType,
                        $description,
                        $originalFileName,
                        $relativeFilePath,
                        $mimeType,
                        $fileSize,
                        $status
                    );

                    if ($insert->execute()) {
                        $insert->close();

                        header('Location: resources.php?posted=1');
                        exit;
                    }

                    error_log(
                        'Resource insert failed: ' . $insert->error
                    );

                    $insert->close();
                    @unlink($destination);

                    $errors[] = 'Unable to save the resource. Please check your database settings and try again.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Display page
|--------------------------------------------------------------------------
*/

include __DIR__ . '/includes/header.php';
?>

<main class="wrap">
    <a
        class="back"
        href="resources.php"
        aria-label="Back to resources"
    >
        &larr;
    </a>

    <form
        class="form-card"
        method="post"
        enctype="multipart/form-data"
    >
        <header>
            <h1>Upload a resource</h1>
            <p>Share useful academic materials with other students.</p>
        </header>

        <?php if (!empty($errors)): ?>
            <div class="form-errors" role="alert">
                <strong>Please check the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Resource Title -->
        <div class="field">
            <label for="title">Resource Title</label>

            <input
                type="text"
                id="title"
                name="title"
                maxlength="150"
                placeholder="Enter resource title"
                value="<?= e($_POST['title'] ?? '') ?>"
                required
            >

            <small>
                Give your learning material a clear title so other students
                can easily identify it.
            </small>
        </div>

        <div class="row">
            <div class="field">
                <label for="subject">Subject</label>

                <select id="subject" name="subject" required>
                    <option
                        value=""
                        disabled
                        <?= empty($_POST['subject']) ? 'selected' : '' ?>
                    >
                        Choose the related subject
                    </option>

                    <?php foreach ($uploadSubjectOptions as $subjectOption): ?>
                        <option
                            value="<?= (int) $subjectOption['id'] ?>"
                            <?= (string) ($_POST['subject'] ?? '') ===
                                (string) $subjectOption['id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($subjectOption['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <small>
                    Select the class or subject where this resource is useful.
                </small>
            </div>

            <div class="field">
                <label for="type">Resource type</label>

                <select id="type" name="type" required>
                    <option
                        value=""
                        disabled
                        <?= empty($_POST['type']) ? 'selected' : '' ?>
                    >
                        Choose the material type
                    </option>

                    <?php foreach ($resourceTypes as $type): ?>
                        <option
                            value="<?= e($type) ?>"
                            <?= ($_POST['type'] ?? '') === $type
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <small>
                    For example, notes, a reviewer, a module, or a presentation.
                </small>
            </div>
        </div>

        <div class="field">
            <label for="description">Description</label>

            <textarea
                id="description"
                name="description"
                rows="5"
                maxlength="5000"
                placeholder="Example: These notes cover database concepts, ER diagrams, SQL queries, and normalization. They can help students review lessons and prepare for quizzes and exams."
            ><?= e($_POST['description'] ?? '') ?></textarea>

            <small>
                Explain what the file contains, which topics it covers,
                and how it can help other students. This description will
                be shown with your resource on the Resources page.
            </small>
        </div>

        <div class="field file-drop">
            <label for="file">Upload file</label>

            <input
                id="file"
                name="file"
                type="file"
                accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.jpg,.jpeg,.png"
                required
            >

            <small>
                Choose the actual learning material you want to share.
                Supported formats: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX,
                TXT, JPG, and PNG. Maximum file size: 10 MB.
            </small>
        </div>

        <div class="actions">
            <a class="btn btn-quiet" href="resources.php">
                Cancel
            </a>

            <button class="btn btn-primary" type="submit">
                Upload resource
            </button>
        </div>
    </form>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
