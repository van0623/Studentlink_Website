<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Only logged-in users can open this page */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];

/* Page settings */
$title  = 'Services';
$active = 'services';
$css    = ['services'];

/* Messaging page */
$messagePage = file_exists(__DIR__ . '/messages.php')
    ? 'messages.php'
    : 'message.php';

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================
   HANDLE MESSAGE PROVIDER ACTION
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'message_provider'
) {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    $serviceId = filter_var(
        $_POST['service_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        $postedToken === ''
        || !hash_equals($csrfToken, $postedToken)
    ) {
        header('Location: services.php?error=invalid_token');
        exit;
    }

    if (!$serviceId || $serviceId <= 0) {
        header('Location: services.php?error=message_failed');
        exit;
    }

    try {
        /* Get the provider and service details from the database */
        $providerStmt = $conn->prepare("
            SELECT
                s.user_id,
                s.title AS service_title,
                u.status AS user_status
            FROM services AS s
            INNER JOIN categories AS c
                ON c.id = s.category_id
            INNER JOIN users AS u
                ON u.id = s.user_id
            WHERE s.id = ?
              AND s.status = 'Active'
              AND c.kind = 'service'
            LIMIT 1
        ");

        if (!$providerStmt) {
            throw new RuntimeException(
                'Unable to prepare service provider query.'
            );
        }

        $providerStmt->bind_param('i', $serviceId);
        $providerStmt->execute();

        $provider = $providerStmt
            ->get_result()
            ->fetch_assoc();

        $providerStmt->close();

        if (!$provider) {
            header('Location: services.php?error=provider_unavailable');
            exit;
        }

        if ($provider['user_status'] !== 'Active') {
            header('Location: services.php?error=provider_unavailable');
            exit;
        }

        $recipientId = (int) $provider['user_id'];
        $serviceTitle = $provider['service_title'];
        $serviceUrl = 'service-view.php?id=' . $serviceId;

        /* Prevent users from messaging themselves */
        if ($recipientId === $currentUserId) {
            header('Location: services.php?error=own_service');
            exit;
        }

        /* Find an existing private conversation */
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
                (c.user_one_id = ? AND c.user_two_id = ?)
                OR
                (c.user_one_id = ? AND c.user_two_id = ?)
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
                'Unable to prepare conversation query.'
            );
        }

        $conversationStmt->bind_param(
            'iiiiiiii',
            $currentUserId,
            $recipientId,
            $currentUserId,
            $recipientId,
            $recipientId,
            $currentUserId,
            $currentUserId,
            $recipientId
        );

        $conversationStmt->execute();

        $conversation = $conversationStmt
            ->get_result()
            ->fetch_assoc();

        $conversationStmt->close();

        if ($conversation) {
            /* Reuse the existing conversation */
            $conversationId = (int) $conversation['id'];
        } else {
            /* Create the conversation and add both participants */
            $conn->begin_transaction();

            try {
                /* Check again before creating a conversation */
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
                        (c.user_one_id = ? AND c.user_two_id = ?)
                        OR
                        (c.user_one_id = ? AND c.user_two_id = ?)
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
                        'Unable to prepare conversation recheck.'
                    );
                }

                $checkStmt->bind_param(
                    'iiiiiiii',
                    $currentUserId,
                    $recipientId,
                    $currentUserId,
                    $recipientId,
                    $recipientId,
                    $currentUserId,
                    $currentUserId,
                    $recipientId
                );

                $checkStmt->execute();

                $existingConversation = $checkStmt
                    ->get_result()
                    ->fetch_assoc();

                $checkStmt->close();

                if ($existingConversation) {
                    $conversationId = (int) $existingConversation['id'];
                } else {
                    /* Create the conversation with both required user IDs */
                    $createStmt = $conn->prepare("
                        INSERT INTO conversations (
                            user_one_id,
                            user_two_id
                        )
                        VALUES (?, ?)
                    ");

                    if (!$createStmt) {
                        throw new RuntimeException(
                            'Unable to prepare conversation creation.'
                        );
                    }

                    $createStmt->bind_param(
                        'ii',
                        $currentUserId,
                        $recipientId
                    );

                    if (!$createStmt->execute()) {
                        throw new RuntimeException(
                            'Unable to create the conversation.'
                        );
                    }

                    $conversationId = (int) $conn->insert_id;
                    $createStmt->close();

                    if ($conversationId <= 0) {
                        throw new RuntimeException(
                            'Conversation was not created.'
                        );
                    }

                    /* Add both participants */
                    $memberStmt = $conn->prepare("
                        INSERT INTO conversation_members (
                            conversation_id,
                            user_id
                        )
                        VALUES (?, ?)
                    ");

                    if (!$memberStmt) {
                        throw new RuntimeException(
                            'Unable to prepare conversation members.'
                        );
                    }

                    $memberId = $currentUserId;

                    $memberStmt->bind_param(
                        'ii',
                        $conversationId,
                        $memberId
                    );

                    if (!$memberStmt->execute()) {
                        throw new RuntimeException(
                            'Unable to add the current user to the conversation.'
                        );
                    }

                    $memberId = $recipientId;

                    if (!$memberStmt->execute()) {
                        throw new RuntimeException(
                            'Unable to add the provider to the conversation.'
                        );
                    }

                    $memberStmt->close();
                }

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        /* Save the service context for this conversation */
        $contextStmt = $conn->prepare("
            INSERT INTO conversation_contexts (
                conversation_id,
                source_type,
                source_id,
                source_title,
                source_url,
                started_by
            )
            VALUES (?, 'service', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                source_title = VALUES(source_title),
                source_url = VALUES(source_url)
        ");

        if (!$contextStmt) {
            throw new RuntimeException(
                'Unable to prepare the service context. Check that the conversation_contexts table exists.'
            );
        }

        $contextStmt->bind_param(
            'iissi',
            $conversationId,
            $serviceId,
            $serviceTitle,
            $serviceUrl,
            $currentUserId
        );

        if (!$contextStmt->execute()) {
            throw new RuntimeException(
                'Unable to save the service conversation context.'
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

    } catch (Throwable $e) {
        error_log('Services messaging error: ' . $e->getMessage());

        header('Location: services.php?error=message_failed');
        exit;
    }
}

/* =========================================
   SEARCH AND FILTER VALUES
========================================= */

$search         = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$sort           = $_GET['sort'] ?? '';

/* Database results */
$categories_service = [];
$rows = [];
$loadError = false;

try {
    /* Service categories only */
    $categoryQuery = $conn->query(
        "SELECT id, name
         FROM categories
         WHERE kind = 'service'
         ORDER BY name ASC"
    );

    if (!$categoryQuery) {
        throw new RuntimeException(
            'Unable to load service categories.'
        );
    }

    while ($category = $categoryQuery->fetch_assoc()) {
        $categories_service[] = $category;
    }

    /* Active services */
    $sql = "SELECT
                s.id,
                s.user_id,
                s.title,
                s.description AS `desc`,
                s.skills,
                s.status,
                s.created_at,
                c.name AS category,
                u.full_name AS `by`
            FROM services AS s
            INNER JOIN categories AS c
                ON c.id = s.category_id
            INNER JOIN users AS u
                ON u.id = s.user_id
            WHERE s.status = 'Active'
              AND c.kind = 'service'";

    $types = '';
    $params = [];

    /* Search title, description, skills, and category */
    if ($search !== '') {
        $sql .= " AND (
            s.title LIKE ?
            OR s.description LIKE ?
            OR s.skills LIKE ?
            OR c.name LIKE ?
        )";

        $keyword = '%' . $search . '%';

        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;

        $types .= 'ssss';
    }

    /* Filter by category */
    $categoryId = filter_var(
        $categoryFilter,
        FILTER_VALIDATE_INT
    );

    if ($categoryId !== false && $categoryId > 0) {
        $sql .= " AND s.category_id = ?";
        $params[] = $categoryId;
        $types .= 'i';
    }

    /* Sort services */
    if ($sort === 'az') {
        $sql .= " ORDER BY s.title ASC";
    } else {
        $sql .= " ORDER BY s.created_at DESC, s.id DESC";
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare services query.'
        );
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $rows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

} catch (Throwable $e) {
    error_log('Services page error: ' . $e->getMessage());
    $loadError = true;
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="services-page">

    <!-- Page introduction -->
    <header class="services-intro">
        <div class="services-intro-content">
            <span class="services-eyebrow">Student Services</span>
            <h1>Get <span>help</span></h1>
            <p>Find students who can help you with academic tasks.</p>
        </div>

        <a class="service-offer-btn" href="service-offer.php">
            <span class="offer-icon">+</span>
            Offer a service
        </a>
    </header>

    <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>

        <p class="success-message">
            Your service has been removed successfully.
        </p>

    <?php elseif (isset($_GET['error'])): ?>

        <p class="error-message">
            <?php
            $errorCode = (string) $_GET['error'];

            switch ($errorCode) {
                case 'own_service':
                    echo 'You cannot message yourself about your own service.';
                    break;

                case 'provider_unavailable':
                    echo 'This service or its provider is no longer available.';
                    break;

                case 'invalid_token':
                    echo 'Your session token is invalid. Please try again.';
                    break;

                case 'message_failed':
                    echo 'Unable to open a conversation right now. Please try again.';
                    break;

                default:
                    echo 'Unable to complete your request. Please try again.';
                    break;
            }
            ?>
        </p>

    <?php endif; ?>

    <!-- Success notification -->
    <?php flash('Service posted. Students can now request your help.'); ?>

    <!-- Search and filters -->
    <section class="services-toolbar">
        <form class="services-filter-form" method="get" role="search">

            <label class="service-search">
                <span class="search-icon" aria-hidden="true">&#9906;</span>

                <input
                    type="search"
                    name="q"
                    placeholder="Search services and skills"
                    value="<?= e($search) ?>"
                    aria-label="Search services"
                >
            </label>

            <div class="service-filter">
                <select
                    name="category"
                    aria-label="Filter by category"
                    onchange="this.form.submit()"
                >
                    <option value="">All categories</option>

                    <?php foreach ($categories_service as $category): ?>
                        <option
                            value="<?= (int) $category['id'] ?>"
                            <?= $categoryFilter === (string) $category['id'] ? 'selected' : '' ?>
                        >
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="service-filter">
                <select
                    name="sort"
                    aria-label="Sort services"
                    onchange="this.form.submit()"
                >
                    <option value="" <?= $sort !== 'az' ? 'selected' : '' ?>>
                        Newest first
                    </option>

                    <option
                        value="az"
                        <?= $sort === 'az' ? 'selected' : '' ?>
                    >
                        Title, A to Z
                    </option>
                </select>
            </div>

            <button type="submit" class="service-search-btn">
                Search
            </button>

            <a class="service-clear-btn" href="services.php">
                Clear
            </a>

        </form>
    </section>

    <!-- Results -->
    <section class="services-library">

        <div class="services-section-heading">
            <div>
                <span class="services-eyebrow">Service library</span>
                <h2>Browse services</h2>
                <p>Tutoring, design, writing and more from fellow students.</p>
            </div>

            <div class="services-count">
                <strong><?= count($rows) ?></strong>

                <span>
                    service<?= count($rows) === 1 ? '' : 's' ?> found
                </span>
            </div>
        </div>

        <?php if ($loadError): ?>

            <div class="services-empty-state">
                <div class="empty-state-icon" aria-hidden="true">&#9671;</div>

                <h3>Unable to load services</h3>

                <p>
                    Unable to load services from the database.
                    Please check the PHP error log for details.
                </p>
            </div>

        <?php elseif (empty($rows)): ?>

            <div class="services-empty-state">
                <div class="empty-state-icon" aria-hidden="true">&#9671;</div>

                <h3>No services found</h3>

                <p>Offer one and help someone out.</p>

                <a class="service-search-btn" href="service-offer.php">
                    Offer a service
                </a>
            </div>

        <?php else: ?>

            <div class="services-grid">

                <?php foreach ($rows as $s): ?>

                    <?php
                    $isOwner = (
                        $currentUserId === (int) $s['user_id']
                    );

                    /* Initials for the card icon */
                    $words = preg_split(
                        '/\s+/',
                        trim($s['title']),
                        -1,
                        PREG_SPLIT_NO_EMPTY
                    );

                    $serviceInitials = '';

                    foreach (array_slice($words, 0, 2) as $word) {
                        $serviceInitials .= mb_strtoupper(
                            mb_substr($word, 0, 1)
                        );
                    }

                    if ($serviceInitials === '') {
                        $serviceInitials = 'SV';
                    }

                    /* Skills as small tags (comma separated, max 4) */
                    $skillList = [];

                    if (!empty($s['skills'])) {
                        $skillList = array_slice(
                            array_filter(
                                array_map(
                                    'trim',
                                    explode(',', $s['skills'])
                                )
                            ),
                            0,
                            4
                        );
                    }
                    ?>

                    <article class="service-card">

                        <div class="service-card-top">
                            <div class="service-icon" aria-hidden="true">
                                <?= e($serviceInitials) ?>
                            </div>

                            <span class="service-category">
                                <?= e($s['category']) ?>
                            </span>
                        </div>

                        <div class="service-card-content">
                            <h3><?= e($s['title']) ?></h3>

                            <p class="service-description">
                                <?= e(
                                    $s['desc']
                                    ?: 'No description has been added yet.'
                                ) ?>
                            </p>

                            <?php if (!empty($skillList)): ?>
                                <div class="service-skills">
                                    <?php foreach ($skillList as $skill): ?>
                                        <span class="service-skill">
                                            <?= e($skill) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="service-card-footer">

                            <span class="service-by">
                                Offered by
                                <strong><?= e($s['by']) ?></strong>
                            </span>

                            <a
                                class="service-view-btn"
                                href="service-view.php?id=<?= (int) $s['id'] ?>"
                            >
                                View service
                                <span aria-hidden="true">&rarr;</span>
                            </a>

                        </div>

                        <!-- Message provider -->
                        <?php if (!$isOwner): ?>

                            <div class="service-message-actions">
                                <form method="post" action="services.php">

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="message_provider"
                                    >

                                    <input
                                        type="hidden"
                                        name="service_id"
                                        value="<?= (int) $s['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken) ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="service-view-btn"
                                    >
                                        Message Provider
                                        <span aria-hidden="true">&#8594;</span>
                                    </button>

                                </form>
                            </div>

                            <!-- Report Service -->
                            <?php if (!$isOwner): ?>

                                <div class="service-report-actions">
                                    <a
                                        href="report.php?type=service&id=<?= (int) $s['id'] ?>"
                                        class="service-report-btn"
                                    >
                                        <i class="fa-solid fa-flag"></i>
                                        Report Service
                                    </a>
                                </div>

                            <?php endif; ?>


                        <?php endif; ?>

                        <!-- Owner actions -->
                        <?php if ($isOwner): ?>

                            <div class="service-owner-actions">

                                <span class="service-owner-label">
                                    Your service
                                </span>

                                <div class="service-owner-buttons">

                                    <a
                                        class="service-edit-btn"
                                        href="service-edit.php?id=<?= (int) $s['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        class="service-delete-form"
                                        action="service-delete.php"
                                        method="post"
                                        onsubmit="return confirm('Are you sure you want to remove this service?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="service_id"
                                            value="<?= (int) $s['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="service-delete-btn"
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

        <?php endif; ?>

    </section>

</main>

<?php
$conn->close();
require_once __DIR__ . '/includes/footer.php';
?>