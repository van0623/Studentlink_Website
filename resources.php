<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Check login and account */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$userStmt = $conn->prepare(
    "SELECT id, full_name, email, role, status
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$userStmt) {
    error_log('StudentLink user query error: ' . $conn->error);
    exit('Unable to verify your account. Please try again later.');
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user || $user['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}

if ($user['role'] !== 'student' && $user['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$_SESSION['full_name'] = $user['full_name'];
$_SESSION['user'] = $user['full_name'];

/* Create CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Helper functions */
function resourceEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function resourceFormatSize($bytes)
{
    $bytes = (int) $bytes;

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' bytes';
}

/* Messages page */
$messagePage = file_exists(__DIR__ . '/messages.php')
    ? 'messages.php'
    : 'message.php';

$actionError = '';

/* Start or reuse a conversation with a resource owner */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['start_conversation'])
) {
    $postedToken = $_POST['csrf_token'] ?? '';

    $resourceId = filter_input(
        INPUT_POST,
        'resource_id',
        FILTER_VALIDATE_INT
    );

    if (
        !is_string($postedToken)
        || !hash_equals($csrfToken, $postedToken)
    ) {
        $actionError = 'Your session token is invalid. Please refresh and try again.';
    } elseif (!$resourceId || $resourceId < 1) {
        $actionError = 'The selected resource is invalid.';
    } else {
        try {
            /* Get the real resource owner and title */
            $ownerStmt = $conn->prepare("
                SELECT
                    r.user_id,
                    r.title,
                    u.full_name,
                    u.status
                FROM resources AS r
                INNER JOIN users AS u
                    ON u.id = r.user_id
                WHERE r.id = ?
                  AND r.status = 'Active'
                LIMIT 1
            ");

            if (!$ownerStmt) {
                throw new RuntimeException(
                    'Resource owner query preparation failed.'
                );
            }

            $ownerStmt->bind_param('i', $resourceId);
            $ownerStmt->execute();

            $resourceOwner = $ownerStmt
                ->get_result()
                ->fetch_assoc();

            $ownerStmt->close();

            if (!$resourceOwner) {
                $actionError = 'This resource is no longer available.';
            } elseif ($resourceOwner['status'] !== 'Active') {
                $actionError = 'This student account is not available.';
            } elseif ((int) $resourceOwner['user_id'] === $userId) {
                $actionError = 'You cannot start a conversation with yourself.';
            } else {
                $recipientId = (int) $resourceOwner['user_id'];
                $resourceTitle = $resourceOwner['title'];
                $resourceUrl = 'resource-view.php?id=' . $resourceId;

                /* Find an existing conversation between the two students */
                $conversationStmt = $conn->prepare("
                    SELECT c.id
                    FROM conversations AS c
                    INNER JOIN conversation_members AS mine
                        ON mine.conversation_id = c.id
                       AND mine.user_id = ?
                    INNER JOIN conversation_members AS theirs
                        ON theirs.conversation_id = c.id
                       AND theirs.user_id = ?
                    WHERE (
                        (
                            c.user_one_id = ?
                            AND c.user_two_id = ?
                        )
                        OR
                        (
                            c.user_one_id = ?
                            AND c.user_two_id = ?
                        )
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM conversation_members AS extra
                        WHERE extra.conversation_id = c.id
                          AND extra.user_id <> ?
                          AND extra.user_id <> ?
                    )
                    LIMIT 1
                ");

                if (!$conversationStmt) {
                    throw new RuntimeException(
                        'Conversation lookup preparation failed.'
                    );
                }

                $conversationStmt->bind_param(
                    'iiiiiiii',
                    $userId,
                    $recipientId,
                    $userId,
                    $recipientId,
                    $recipientId,
                    $userId,
                    $userId,
                    $recipientId
                );

                $conversationStmt->execute();

                $existingConversation = $conversationStmt
                    ->get_result()
                    ->fetch_assoc();

                $conversationStmt->close();

                if ($existingConversation) {
                    $conversationId = (int) $existingConversation['id'];
                } else {
                    /* Create the conversation and add both members */
                    $conn->begin_transaction();

                    try {
                        /* Check again before creating a new conversation */
                        $checkStmt = $conn->prepare("
                            SELECT c.id
                            FROM conversations AS c
                            INNER JOIN conversation_members AS mine
                                ON mine.conversation_id = c.id
                               AND mine.user_id = ?
                            INNER JOIN conversation_members AS theirs
                                ON theirs.conversation_id = c.id
                               AND theirs.user_id = ?
                            WHERE (
                                (
                                    c.user_one_id = ?
                                    AND c.user_two_id = ?
                                )
                                OR
                                (
                                    c.user_one_id = ?
                                    AND c.user_two_id = ?
                                )
                            )
                            AND NOT EXISTS (
                                SELECT 1
                                FROM conversation_members AS extra
                                WHERE extra.conversation_id = c.id
                                  AND extra.user_id <> ?
                                  AND extra.user_id <> ?
                            )
                            LIMIT 1
                            FOR UPDATE
                        ");

                        if (!$checkStmt) {
                            throw new RuntimeException(
                                'Conversation verification preparation failed.'
                            );
                        }

                        $checkStmt->bind_param(
                            'iiiiiiii',
                            $userId,
                            $recipientId,
                            $userId,
                            $recipientId,
                            $recipientId,
                            $userId,
                            $userId,
                            $recipientId
                        );

                        $checkStmt->execute();

                        $conversationAgain = $checkStmt
                            ->get_result()
                            ->fetch_assoc();

                        $checkStmt->close();

                        if ($conversationAgain) {
                            $conversationId = (int) $conversationAgain['id'];
                        } else {
                            $createStmt = $conn->prepare("
                                INSERT INTO conversations
                                    (user_one_id, user_two_id)
                                VALUES (?, ?)
                            ");

                            if (!$createStmt) {
                                throw new RuntimeException(
                                    'Conversation creation preparation failed.'
                                );
                            }

                            $createStmt->bind_param(
                                'ii',
                                $userId,
                                $recipientId
                            );

                            if (!$createStmt->execute()) {
                                throw new RuntimeException(
                                    'Conversation creation failed.'
                                );
                            }

                            $conversationId = (int) $conn->insert_id;
                            $createStmt->close();

                            /* Add both students to the conversation */
                            $memberStmt = $conn->prepare("
                                INSERT INTO conversation_members
                                    (conversation_id, user_id)
                                VALUES (?, ?)
                            ");

                            if (!$memberStmt) {
                                throw new RuntimeException(
                                    'Conversation member preparation failed.'
                                );
                            }

                            $memberStmt->bind_param(
                                'ii',
                                $conversationId,
                                $memberId
                            );

                            $memberId = $userId;

                            if (!$memberStmt->execute()) {
                                throw new RuntimeException(
                                    'Unable to add the current student to the conversation.'
                                );
                            }

                            $memberId = $recipientId;

                            if (!$memberStmt->execute()) {
                                throw new RuntimeException(
                                    'Unable to add the resource owner to the conversation.'
                                );
                            }

                            $memberStmt->close();
                        }

                        $conn->commit();

                    } catch (Throwable $error) {
                        $conn->rollback();
                        throw $error;
                    }
                }

                /* Save the resource linked to this conversation */
                $contextStmt = $conn->prepare("
                    INSERT INTO conversation_contexts (
                        conversation_id,
                        source_type,
                        source_id,
                        source_title,
                        source_url,
                        started_by
                    )
                    VALUES (?, 'resource', ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        source_title = VALUES(source_title),
                        source_url = VALUES(source_url)
                ");

                if (!$contextStmt) {
                    throw new RuntimeException(
                        'Unable to prepare the resource conversation context. Check that the conversation_contexts table exists.'
                    );
                }

                $contextStmt->bind_param(
                    'iissi',
                    $conversationId,
                    $resourceId,
                    $resourceTitle,
                    $resourceUrl,
                    $userId
                );

                if (!$contextStmt->execute()) {
                    throw new RuntimeException(
                        'Unable to save the resource conversation context.'
                    );
                }

                $contextStmt->close();

                /* Open the conversation */
                header(
                    'Location: '
                    . $messagePage
                    . '?chat='
                    . $conversationId
                );
                exit;
            }

        } catch (Throwable $error) {
            error_log(
                'StudentLink resource messaging error: '
                . $error->getMessage()
            );

            if ($actionError === '') {
                $actionError = 'Unable to open the conversation. Please try again.';
            }
        }
    }
}

/* Page settings */
$title = 'Academic Resources';
$active = 'resources';
$css = ['resources'];
$bare = false;

/* Search and filters */
$search = trim($_GET['q'] ?? '');
$selectedSubject = trim($_GET['subject'] ?? '');
$selectedType = trim($_GET['type'] ?? '');

$subjects = [];
$fileTypes = [];
$resources = [];
$loadError = false;

/* Load resources from the database */
try {
    $subjectResult = $conn->query(
        "SELECT name
         FROM subjects
         ORDER BY name ASC"
    );

    if (!$subjectResult) {
        throw new RuntimeException(
            'Unable to load subjects: ' . $conn->error
        );
    }

    while ($row = $subjectResult->fetch_assoc()) {
        $subjects[] = $row['name'];
    }

    $typeResult = $conn->query(
        "SELECT DISTINCT file_type
         FROM resources
         WHERE status = 'Active'
           AND file_type IS NOT NULL
           AND file_type <> ''
         ORDER BY file_type ASC"
    );

    if (!$typeResult) {
        throw new RuntimeException(
            'Unable to load file types: ' . $conn->error
        );
    }

    while ($row = $typeResult->fetch_assoc()) {
        $fileTypes[] = $row['file_type'];
    }

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
            COALESCE(s.name, 'Uncategorized') AS subject,
            COALESCE(
                NULLIF(u.full_name, ''),
                NULLIF(u.email, ''),
                'Student'
            ) AS uploader
        FROM resources AS r
        LEFT JOIN subjects AS s
            ON s.id = r.subject_id
        LEFT JOIN users AS u
            ON u.id = r.user_id
        WHERE r.status = 'Active'
          AND (
              ? = ''
              OR r.title LIKE ?
              OR COALESCE(s.name, '') LIKE ?
              OR COALESCE(r.description, '') LIKE ?
              OR COALESCE(u.full_name, u.email, 'Student') LIKE ?
          )
          AND (? = '' OR s.name = ?)
          AND (? = '' OR r.file_type = ?)
        ORDER BY r.created_at DESC, r.id DESC
    ";

    $resourceStmt = $conn->prepare($sql);

    if (!$resourceStmt) {
        throw new RuntimeException(
            'Resource query preparation failed: ' . $conn->error
        );
    }

    $searchTerm = '%' . $search . '%';

    $resourceStmt->bind_param(
        'sssssssss',
        $search,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $selectedSubject,
        $selectedSubject,
        $selectedType,
        $selectedType
    );

    if (!$resourceStmt->execute()) {
        throw new RuntimeException(
            'Resource query execution failed: ' . $resourceStmt->error
        );
    }

    $resourceResult = $resourceStmt->get_result();

    while ($row = $resourceResult->fetch_assoc()) {
        $resources[] = $row;
    }

    $resourceStmt->close();

} catch (Throwable $error) {
    $loadError = true;
    $resources = [];

    error_log(
        'StudentLink resources error: ' . $error->getMessage()
    );

    if (isset($resourceStmt) && $resourceStmt instanceof mysqli_stmt) {
        try {
            $resourceStmt->close();
        } catch (Throwable $closeError) {
            error_log($closeError->getMessage());
        }
    }
}

/* Page header */
require_once __DIR__ . '/includes/header.php';
?>

<main class="resources-page">

    <section class="resources-intro">

        <div class="resources-intro-content">
            <span class="resources-eyebrow">
                STUDENT RESOURCE HUB
            </span>

            <h1>
                Academic <span>Resources.</span>
            </h1>

            <p>
                Find reviewers, study materials, and helpful resources
                shared by your fellow students.
            </p>
        </div>

        <a href="resource-upload.php" class="resource-upload-btn">
            <span class="upload-icon" aria-hidden="true">+</span>
            Upload Resource
        </a>

    </section>

    <!-- Feedback messages -->
    <?php
    $messages = [
        'updated' => [
            'class' => 'success',
            'text' => 'Your resource was updated successfully.'
        ],
        'deleted' => [
            'class' => 'success',
            'text' => 'Your resource was removed successfully.'
        ],
        'edit_failed' => [
            'class' => 'error',
            'text' => 'Unable to edit that resource. It may not exist or may not belong to your account.'
        ],
        'delete_failed' => [
            'class' => 'error',
            'text' => 'Unable to remove the resource. It may already be removed or may not belong to your account.'
        ],
        'invalid_token' => [
            'class' => 'error',
            'text' => 'Your session token is invalid. Please try again.'
        ]
    ];

    $messageKey = $_GET['message'] ?? '';

    if (isset($messages[$messageKey])):
    ?>
        <div
            class="notice <?= resourceEscape($messages[$messageKey]['class']) ?>"
            role="status"
            style="margin-top: 22px;"
        >
            <?= resourceEscape($messages[$messageKey]['text']) ?>
        </div>
    <?php endif; ?>

    <?php if ($actionError !== ''): ?>
        <div class="notice error" role="alert" style="margin-top: 22px;">
            <?= resourceEscape($actionError) ?>
        </div>
    <?php endif; ?>

    <!-- Search and filters -->
    <section class="resources-toolbar">

        <form
            action="resources.php"
            method="GET"
            class="resources-filter-form"
        >

            <div class="resource-search">
                <span class="search-icon" aria-hidden="true">&#9906;</span>

                <input
                    type="search"
                    name="q"
                    placeholder="Search resources, subjects, or keywords..."
                    value="<?= resourceEscape($search) ?>"
                    aria-label="Search resources"
                >
            </div>

            <div class="resource-filter">
                <select name="subject" aria-label="Filter by subject">
                    <option value="">All Subjects</option>

                    <?php foreach ($subjects as $subject): ?>
                        <option
                            value="<?= resourceEscape($subject) ?>"
                            <?= $selectedSubject === $subject ? 'selected' : '' ?>
                        >
                            <?= resourceEscape($subject) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="resource-filter">
                <select name="type" aria-label="Filter by file type">
                    <option value="">All File Types</option>

                    <?php foreach ($fileTypes as $fileType): ?>
                        <option
                            value="<?= resourceEscape($fileType) ?>"
                            <?= $selectedType === $fileType ? 'selected' : '' ?>
                        >
                            <?= resourceEscape(
                                ucwords(str_replace(['-', '_'], ' ', $fileType))
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="resource-search-btn">
                Search
            </button>

            <?php if (
                $search !== ''
                || $selectedSubject !== ''
                || $selectedType !== ''
            ): ?>
                <a href="resources.php" class="resource-clear-btn">
                    Clear
                </a>
            <?php endif; ?>

        </form>

    </section>

    <!-- Resource list -->
    <section class="resources-library">

        <div class="resources-section-heading">
            <div>
                <span class="resources-eyebrow">
                    YOUR STUDY MATERIALS
                </span>

                <h2>Explore Resources</h2>

                <p>
                    Discover materials to help you with your studies.
                </p>
            </div>

            <div class="resources-count">
                <strong><?= count($resources) ?></strong>

                <span>
                    <?= count($resources) === 1
                        ? 'resource found'
                        : 'resources found' ?>
                </span>
            </div>
        </div>

        <?php if ($loadError): ?>

            <div class="resources-empty-state">
                <h3>Unable to load resources</h3>
                <p>
                    Something went wrong while retrieving resources.
                    Please try again later.
                </p>
            </div>

        <?php elseif (!empty($resources)): ?>

            <div class="resources-grid">

                <?php foreach ($resources as $resource): ?>

                    <?php
                    $fileType = strtoupper(
                        trim($resource['file_type'] ?? '')
                    );

                    if ($fileType === '') {
                        $fileType = 'FILE';
                    }

                    $description = trim(
                        $resource['description'] ?? ''
                    );

                    if ($description === '') {
                        $description = 'No description provided.';
                    }

                    $fileSize = resourceFormatSize(
                        $resource['file_size'] ?? 0
                    );

                    $resourceOwnerId = (int) ($resource['user_id'] ?? 0);
                    $isOwner = ($resourceOwnerId === (int) $userId);
                    ?>

                    <article class="resource-card">

                        <div class="resource-card-top">

                            <div
                                class="resource-file-icon"
                                aria-hidden="true"
                            >
                                <span class="file-fold"></span>

                                <span class="file-type-label">
                                    <?= resourceEscape($fileType) ?>
                                </span>
                            </div>

                            <span class="resource-subject">
                                <?= resourceEscape($resource['subject']) ?>
                            </span>

                        </div>

                        <div class="resource-card-content">

                            <h3>
                                <?= resourceEscape($resource['title']) ?>
                            </h3>

                            <p class="resource-description">
                                <?= resourceEscape($description) ?>
                            </p>

                        </div>

                        <div class="resource-card-meta">

                            <span class="resource-size">
                                <span aria-hidden="true">&#9638;</span>
                                <?= resourceEscape($fileSize) ?>
                            </span>

                            <span class="resource-uploader">
                                Shared by
                                <?= resourceEscape($resource['uploader']) ?>
                            </span>

                        </div>

                        <div class="resource-card-footer">

                            <span class="resource-format">
                                <?= resourceEscape($fileType) ?> Document
                            </span>

                            <a
                                href="resource-view.php?id=<?= (int) $resource['id'] ?>"
                                class="resource-view-btn"
                            >
                                View Resource
                                <span aria-hidden="true">&rarr;</span>
                            </a>

                        </div>

                        <!-- Message the resource owner -->
                        <?php if (!$isOwner): ?>

                            <div class="resource-message-actions">
                                <form
                                    method="post"
                                    action="resources.php"
                                    class="resource-message-form"
                                >
                                    <input
                                        type="hidden"
                                        name="start_conversation"
                                        value="1"
                                    >

                                    <input
                                        type="hidden"
                                        name="resource_id"
                                        value="<?= (int) $resource['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= resourceEscape($csrfToken) ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="resource-view-btn"
                                    >
                                        Message Owner
                                        <span aria-hidden="true">&#8594;</span>
                                    </button>
                                </form>
                            </div>

                        <?php endif; ?>

                        
                                <!-- Report button -->
                        <?php if (!$isOwner): ?>

                            <div class="resource-report-actions">
                                <a
                                    href="report.php?type=Resource&id=<?= (int) $resource['id'] ?>"
                                    class="resource-report-btn"
                                >
                                    Report
                                </a>
                            </div>

                        <?php endif; ?>


                        <!-- CRUD actions: only for the resource owner -->
                        <?php if ($isOwner): ?>

                            <div class="resource-owner-actions">

                                <span class="resource-owner-label">
                                    Your resource
                                </span>

                                <div class="resource-owner-buttons">

                                    <a
                                        href="resource-edit.php?id=<?= (int) $resource['id'] ?>"
                                        class="resource-edit-btn"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="post"
                                        action="resource-delete.php"
                                        class="resource-delete-form"
                                        onsubmit="return confirm('Are you sure you want to remove this resource?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="resource_id"
                                            value="<?= (int) $resource['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= resourceEscape($csrfToken) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="resource-delete-btn"
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

        <?php else: ?>

            <div class="resources-empty-state">

                <div class="empty-state-icon" aria-hidden="true">
                    &#9906;
                </div>

                <h3>No resources found</h3>

                <p>
                    <?php if (
                        $search !== ''
                        || $selectedSubject !== ''
                        || $selectedType !== ''
                    ): ?>
                        Try another search keyword or change your filters
                        to find the resource you need.
                    <?php else: ?>
                        No active resources are available yet.
                        Upload a resource to get started.
                    <?php endif; ?>
                </p>

                <?php if (
                    $search !== ''
                    || $selectedSubject !== ''
                    || $selectedType !== ''
                ): ?>
                    <a href="resources.php" class="resource-search-btn">
                        View All Resources
                    </a>
                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>