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

$userId = (int) $_SESSION['user_id'];

$user = [
    'name' => $_SESSION['full_name'] ?? 'Student'
];

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Messaging page */
$messagePage = file_exists(__DIR__ . '/messages.php')
    ? 'messages.php'
    : 'message.php';

/* Load subjects */
$subjects = [];

$subjectQuery = $conn->query(
    "SELECT id, name FROM subjects ORDER BY name ASC"
);

if ($subjectQuery) {
    while ($subject = $subjectQuery->fetch_assoc()) {
        $subjects[] = $subject;
    }
}

/* Search and filters */
$search = trim($_GET['q'] ?? '');
$like = '%' . $search . '%';

$subjectFilter = (int) ($_GET['subject'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

if (!in_array($statusFilter, ['', 'Open', 'Full', 'Joined'], true)) {
    $statusFilter = '';
}

/* Handle messaging, joining, and unfollowing */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        header('Location: groups.php?message=invalid_token');
        exit;
    }

    $action = $_POST['action'] ?? '';

    /* Message the study group creator */
    if ($action === 'message_creator') {
        $groupId = filter_input(
            INPUT_POST,
            'group_id',
            FILTER_VALIDATE_INT
        );

        if (!$groupId || $groupId < 1) {
            header('Location: groups.php?message=invalid');
            exit;
        }

        try {
            /* Get the real creator and group title */
            $creatorStmt = $conn->prepare("
                SELECT
                    g.creator_id,
                    g.name AS group_name,
                    u.status
                FROM study_groups AS g
                INNER JOIN users AS u
                    ON u.id = g.creator_id
                WHERE g.id = ?
                  AND g.status = 'Active'
                LIMIT 1
            ");

            if (!$creatorStmt) {
                throw new RuntimeException(
                    'Group creator query preparation failed.'
                );
            }

            $creatorStmt->bind_param('i', $groupId);
            $creatorStmt->execute();

            $creator = $creatorStmt
                ->get_result()
                ->fetch_assoc();

            $creatorStmt->close();

            if (!$creator) {
                header('Location: groups.php?message=unavailable');
                exit;
            }

            if ($creator['status'] !== 'Active') {
                header('Location: groups.php?message=creator_unavailable');
                exit;
            }

            $recipientId = (int) $creator['creator_id'];
            $groupTitle = $creator['group_name'];
            $groupUrl = 'group-view.php?id=' . $groupId;

            if ($recipientId === $userId) {
                header('Location: groups.php?message=own_group');
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
                /* Create the conversation and add both participants */
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

                        /* Add both participants */
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
                                'Unable to add you to the conversation.'
                            );
                        }

                        $memberId = $recipientId;

                        if (!$memberStmt->execute()) {
                            throw new RuntimeException(
                                'Unable to add the creator to the conversation.'
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

            /* Save the study group linked to this conversation */
            $contextStmt = $conn->prepare("
                INSERT INTO conversation_contexts (
                    conversation_id,
                    source_type,
                    source_id,
                    source_title,
                    source_url,
                    started_by
                )
                VALUES (?, 'group', ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    source_title = VALUES(source_title),
                    source_url = VALUES(source_url)
            ");

            if (!$contextStmt) {
                throw new RuntimeException(
                    'Unable to prepare the group conversation context. Check that the conversation_contexts table exists.'
                );
            }

            $contextStmt->bind_param(
                'iissi',
                $conversationId,
                $groupId,
                $groupTitle,
                $groupUrl,
                $userId
            );

            if (!$contextStmt->execute()) {
                throw new RuntimeException(
                    'Unable to save the study group conversation context.'
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
            error_log(
                'StudentLink group messaging error: '
                . $e->getMessage()
            );

            header('Location: groups.php?message=error');
            exit;
        }
    }

    /* Continue with joining and unfollowing */
    $groupId = filter_input(
        INPUT_POST,
        'group_id',
        FILTER_VALIDATE_INT
    );

    if (
        !$groupId ||
        $groupId < 1 ||
        !in_array($action, ['join', 'unfollow'], true)
    ) {
        header('Location: groups.php?message=invalid');
        exit;
    }

    /* Unfollow group */
    if ($action === 'unfollow') {
        try {
            $stmt = $conn->prepare(
                "DELETE FROM group_members
                 WHERE group_id = ? AND user_id = ?"
            );

            $stmt->bind_param('ii', $groupId, $userId);
            $stmt->execute();

            $removed = $stmt->affected_rows > 0;
            $stmt->close();

            header(
                'Location: groups.php?message=' .
                ($removed ? 'unfollowed' : 'not_member')
            );
            exit;

        } catch (Throwable $e) {
            error_log('Unfollow group error: ' . $e->getMessage());
            header('Location: groups.php?message=error');
            exit;
        }
    }

    /* Join group */
    try {
        $conn->begin_transaction();

        $groupCheck = $conn->prepare(
            "SELECT id, max_members, status
             FROM study_groups
             WHERE id = ?
             FOR UPDATE"
        );

        if (!$groupCheck) {
            throw new RuntimeException(
                'Group verification preparation failed.'
            );
        }

        $groupCheck->bind_param('i', $groupId);
        $groupCheck->execute();

        $group = $groupCheck->get_result()->fetch_assoc();
        $groupCheck->close();

        if (!$group || $group['status'] !== 'Active') {
            $conn->rollback();
            header('Location: groups.php?message=unavailable');
            exit;
        }

        /* Check existing membership */
        $memberCheck = $conn->prepare(
            "SELECT 1
             FROM group_members
             WHERE group_id = ? AND user_id = ?
             LIMIT 1"
        );

        $memberCheck->bind_param('ii', $groupId, $userId);
        $memberCheck->execute();

        $alreadyJoined = (bool) $memberCheck
            ->get_result()
            ->fetch_row();

        $memberCheck->close();

        if ($alreadyJoined) {
            $conn->commit();
            header('Location: groups.php?message=already_joined');
            exit;
        }

        /* Count current members */
        $countQuery = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM group_members
             WHERE group_id = ?"
        );

        $countQuery->bind_param('i', $groupId);
        $countQuery->execute();

        $countRow = $countQuery->get_result()->fetch_assoc();
        $countQuery->close();

        $currentMembers = (int) ($countRow['total'] ?? 0);
        $maxMembers = (int) $group['max_members'];

        if ($maxMembers < 1 || $currentMembers >= $maxMembers) {
            $conn->commit();
            header('Location: groups.php?message=full');
            exit;
        }

        /* Save membership */
        $join = $conn->prepare(
            "INSERT INTO group_members (group_id, user_id)
             VALUES (?, ?)"
        );

        $join->bind_param('ii', $groupId, $userId);
        $join->execute();
        $join->close();

        $conn->commit();

        header('Location: groups.php?message=joined');
        exit;

    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Transaction may already be closed.
        }

        error_log('Study group membership error: ' . $e->getMessage());

        header('Location: groups.php?message=error');
        exit;
    }
}

/* Load active groups */
$sql = "SELECT
            g.id,
            g.creator_id,
            g.name,
            g.description,
            g.max_members,
            g.created_at,
            s.name AS subject,
            COUNT(DISTINCT gm.user_id) AS members,
            COUNT(DISTINCT mym.user_id) AS joined_by_me
        FROM study_groups g
        INNER JOIN subjects s
            ON s.id = g.subject_id
        LEFT JOIN group_members gm
            ON gm.group_id = g.id
        LEFT JOIN group_members mym
            ON mym.group_id = g.id
            AND mym.user_id = ?
        WHERE g.status = 'Active'
          AND (? = '' OR g.name LIKE ? OR g.description LIKE ?)
          AND (? = 0 OR g.subject_id = ?)
        GROUP BY
            g.id,
            g.creator_id,
            g.name,
            g.description,
            g.max_members,
            g.created_at,
            s.name
        ORDER BY g.created_at DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Study groups query preparation error: ' . $conn->error);
    exit('Unable to load study groups. Please try again later.');
}

$stmt->bind_param(
    'isssii',
    $userId,
    $search,
    $like,
    $like,
    $subjectFilter,
    $subjectFilter
);

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Prepare group data */
$groups = [];

foreach ($rows as $g) {
    $members = (int) $g['members'];
    $maximum = max(1, (int) $g['max_members']);

    $isMember = (int) $g['joined_by_me'] > 0;
    $isOwner = (int) $g['creator_id'] === $userId;
    $isFull = $members >= $maximum;

    $g['members'] = $members;
    $g['max_members'] = $maximum;
    $g['is_member'] = $isMember;
    $g['is_owner'] = $isOwner;
    $g['is_full'] = $isFull;
    $g['seat_status'] = $isFull ? 'Full' : 'Open';

    $g['percentage'] = min(
        100,
        (int) round(($members / $maximum) * 100)
    );

    /* Create group initials */
    $words = preg_split(
        '/\s+/',
        trim($g['name']),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $initials = '';

    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }

    $g['initials'] = $initials !== '' ? $initials : 'SG';

    /* Apply status filter */
    if ($statusFilter === 'Joined' && !$isMember) {
        continue;
    }

    if ($statusFilter === 'Open' && $isFull) {
        continue;
    }

    if ($statusFilter === 'Full' && !$isFull) {
        continue;
    }

    $groups[] = $g;
}


require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="css/groups.css">

<main class="groups-page">

    <header class="groups-intro">
        <div class="groups-intro-content">
            <span class="groups-eyebrow">Study Groups</span>
            <h1>Study <span>together</span></h1>
            <p>Find classmates, join a group, and study together.</p>

        </div>

        <a class="group-create-btn" href="group-create.php">
            <span class="create-icon">+</span>
            Create group
        </a>
    </header>

    <!-- Feedback messages -->
    <?php
    $messages = [
        'joined' => [
            'class' => 'success',
            'text' => 'You successfully joined the study group.'
        ],
        'unfollowed' => [
            'class' => 'success',
            'text' => 'You have unfollowed the study group.'
        ],
        'not_member' => [
            'class' => 'notice',
            'text' => 'You are not a member of that group.'
        ],
        'already_joined' => [
            'class' => 'notice',
            'text' => 'You are already a member of that group.'
        ],
        'full' => [
            'class' => 'error',
            'text' => 'Sorry, that group is already full.'
        ],
        'unavailable' => [
            'class' => 'error',
            'text' => 'That group is no longer available.'
        ],
        'creator_unavailable' => [
            'class' => 'error',
            'text' => 'The group creator account is not available.'
        ],
        'own_group' => [
            'class' => 'notice',
            'text' => 'This is your group. Other students can message you here.'
        ],
        'invalid' => [
            'class' => 'error',
            'text' => 'Invalid group or action.'
        ],
        'invalid_token' => [
            'class' => 'error',
            'text' => 'Your session token is invalid. Please try again.'
        ],
        'error' => [
            'class' => 'error',
            'text' => 'Something went wrong. Please try again.'
        ],
        'created' => [
            'class' => 'success',
            'text' => 'Your study group was created successfully.'
        ],
        'updated' => [
            'class' => 'success',
            'text' => 'Your study group was updated successfully.'
        ],
        'deleted' => [
            'class' => 'success',
            'text' => 'Your study group was deleted successfully.'
        ],
        'delete_failed' => [
            'class' => 'error',
            'text' => 'Unable to delete the group. Please try again.'
        ],
        'edit_failed' => [
            'class' => 'error',
            'text' => 'Unable to edit that group.'
        ]
    ];

    $messageKey = $_GET['message'] ?? '';

    if (isset($messages[$messageKey])):
    ?>
        <div
            class="notice <?= e($messages[$messageKey]['class']) ?>"
            role="status"
            style="margin-top: 22px;"
        >
            <?= e($messages[$messageKey]['text']) ?>
        </div>
    <?php endif; ?>

    <!-- Search and filters -->
    <section class="groups-toolbar">
        <form class="groups-filter-form" method="get" role="search">

            <label class="group-search">
                <span class="search-icon" aria-hidden="true">&#9906;</span>

                <input
                    type="search"
                    name="q"
                    placeholder="Search study groups..."
                    value="<?= e($search) ?>"
                    aria-label="Search study groups"
                >
            </label>

            <div class="group-filter">
                <select name="subject" aria-label="Filter by subject">
                    <option value="0">All subjects</option>

                    <?php foreach ($subjects as $subject): ?>
                        <option
                            value="<?= (int) $subject['id'] ?>"
                            <?= $subjectFilter === (int) $subject['id'] ? 'selected' : '' ?>
                        >
                            <?= e($subject['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="group-filter">
                <select name="status" aria-label="Filter by group status">
                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>
                        All groups
                    </option>
                    <option value="Open" <?= $statusFilter === 'Open' ? 'selected' : '' ?>>
                        Open groups
                    </option>
                    <option value="Full" <?= $statusFilter === 'Full' ? 'selected' : '' ?>>
                        Full groups
                    </option>
                    <option value="Joined" <?= $statusFilter === 'Joined' ? 'selected' : '' ?>>
                        Groups I've joined
                    </option>
                </select>
            </div>

            <button type="submit" class="group-search-btn">Search</button>
            <a class="group-clear-btn" href="groups.php">Clear</a>

        </form>
    </section>

    <!-- Group results -->
    <section class="groups-library">

        <div class="groups-section-heading">
            <div>
                <span class="groups-eyebrow">Group library</span>
                <h2>Explore study groups</h2>
                <p>Join a group that fits your subject and schedule.</p>
            </div>

            <div class="groups-count">
                <strong><?= count($groups) ?></strong>
                <span>group<?= count($groups) === 1 ? '' : 's' ?> found</span>
            </div>
        </div>

        <?php if (empty($groups)): ?>

            <div class="groups-empty-state">
                <div class="empty-state-icon" aria-hidden="true">&#9671;</div>
                <h3>No study groups found</h3>
                <p>
                    Try another search or change your filters.
                    You can also create a new group for your classmates.
                </p>
                <a class="group-search-btn" href="group-create.php">
                    Create a study group
                </a>
            </div>

        <?php else: ?>

            <div class="groups-grid">

                <?php foreach ($groups as $g): ?>

                    <article class="group-card">

                        <div class="group-card-top">
                            <div class="group-icon" aria-hidden="true">
                                <?= e($g['initials']) ?>
                            </div>

                            <span class="group-subject">
                                <?= e($g['subject']) ?>
                            </span>
                        </div>

                        <div class="group-card-content">
                            <h3><?= e($g['name']) ?></h3>

                            <p class="group-description">
                                <?= e(
                                    $g['description']
                                    ?: 'No description has been added yet.'
                                ) ?>
                            </p>

                            <span
                                class="group-meter"
                                role="progressbar"
                                aria-label="Group capacity"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="<?= (int) $g['percentage'] ?>"
                            >
                                <i style="width: <?= (int) $g['percentage'] ?>%;"></i>
                            </span>
                        </div>

                        <div class="group-card-meta">
                            <span class="group-members">
                                <span aria-hidden="true">&#9679;</span>
                                <?= (int) $g['members'] ?> of
                                <?= (int) $g['max_members'] ?> members
                            </span>

                            <span class="group-owner">
                                <?= max(
                                    0,
                                    (int) $g['max_members'] - (int) $g['members']
                                ) ?>
                                seats left
                            </span>
                        </div>

                        <div class="group-card-footer">

                            <?php if ($g['is_member']): ?>
                                <span class="group-status is-joined">Joined</span>
                            <?php else: ?>
                                <span class="group-status <?= $g['is_full'] ? '' : 'is-open' ?>">
                                    <?= e($g['seat_status']) ?>
                                </span>
                            <?php endif; ?>

                           
<div class="group-actions">

    <a
        class="group-view-btn"
        href="group-view.php?id=<?= (int) $g['id'] ?>"
    >
        View <span aria-hidden="true">&rarr;</span>
    </a>

    <?php if (!$g['is_owner']): ?>

        <!-- Message the group creator -->
        <form
            method="post"
            action="groups.php"
            class="group-message-form"
        >
            <input
                type="hidden"
                name="group_id"
                value="<?= (int) $g['id'] ?>"
            >

            <input
                type="hidden"
                name="action"
                value="message_creator"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <button
                type="submit"
                class="group-message-btn"
            >
                Message Creator
                <span aria-hidden="true">&#8594;</span>
            </button>
        </form>

        <!-- Report another student's group -->
        <a
            href="report.php?type=Group&id=<?= (int) $g['id'] ?>"
            class="group-report-btn"
        >
            Report
        </a>

    <?php endif; ?>

    <!-- Join or unfollow -->
    <?php if ($g['is_member']): ?>

        <form
            method="post"
            action="groups.php"
            onsubmit="return confirm('Are you sure you want to unfollow this group?');"
        >
            <input
                type="hidden"
                name="group_id"
                value="<?= (int) $g['id'] ?>"
            >

            <input
                type="hidden"
                name="action"
                value="unfollow"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <button type="submit" class="group-leave-btn">
                Unfollow
            </button>
        </form>

    <?php elseif ($g['is_full']): ?>

        <button
            type="button"
            class="group-disabled-btn"
            disabled
        >
            Group full
        </button>

    <?php else: ?>

        <form method="post" action="groups.php">
            <input
                type="hidden"
                name="group_id"
                value="<?= (int) $g['id'] ?>"
            >

            <input
                type="hidden"
                name="action"
                value="join"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <button type="submit" class="group-join-btn">
                Join group
            </button>
        </form>

    <?php endif; ?>

</div>


                        <!-- Owner-only edit and delete actions -->
                        <?php if ($g['is_owner']): ?>

                            <div class="group-owner-actions">
                                <span class="group-owner-label">Your group</span>

                                <div class="group-owner-buttons">
                                    <a
                                        class="group-edit-btn"
                                        href="group-edit.php?id=<?= (int) $g['id'] ?>"
                                    >
                                        Edit group
                                    </a>

                                    <form
                                        method="post"
                                        action="group-delete.php"
                                        onsubmit="return confirm('Are you sure you want to permanently delete this study group and its memberships? This cannot be undone.');"
                                    >
                                        <input
                                            type="hidden"
                                            name="group_id"
                                            value="<?= (int) $g['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="group-delete-btn"
                                        >
                                            Delete group
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>