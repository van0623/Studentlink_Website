<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

/* Authentication */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id, full_name, status
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$currentUser = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$currentUser || $currentUser['status'] !== 'Active') {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Page values */
$errors = [];

$chatId = filter_input(
    INPUT_GET,
    'chat',
    FILTER_VALIDATE_INT
) ?: 0;

$search = trim($_GET['q'] ?? '');
$search = mb_substr($search, 0, 100);

/* Unsend a message */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'unsend_message'
) {
    $postedChatId = filter_input(
        INPUT_POST,
        'conversation_id',
        FILTER_VALIDATE_INT
    ) ?: 0;

    $messageId = filter_input(
        INPUT_POST,
        'message_id',
        FILTER_VALIDATE_INT
    ) ?: 0;

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token)
        || !hash_equals($csrfToken, $token)
    ) {
        header('Location: messages.php?chat=' . max(0, $postedChatId) . '&error=invalid_token');
        exit;
    }

    if ($postedChatId < 1 || $messageId < 1) {
        header('Location: messages.php?error=unsend_failed');
        exit;
    }

    try {
        /* Delete only your own message */
        $stmt = $conn->prepare("
            DELETE m
            FROM messages m
            INNER JOIN conversation_members cm
                ON cm.conversation_id = m.conversation_id
               AND cm.user_id = ?
            WHERE m.id = ?
              AND m.conversation_id = ?
              AND m.sender_id = ?
        ");

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare unsend query.');
        }

        $stmt->bind_param(
            'iiii',
            $userId,
            $messageId,
            $postedChatId,
            $userId
        );

        $stmt->execute();

        $deleted = $stmt->affected_rows;

        $stmt->close();

        if ($deleted < 1) {
            header(
                'Location: messages.php?chat='
                . $postedChatId
                . '&error=unsend_failed'
            );
            exit;
        }

        /* Update the conversation time */
        $stmt = $conn->prepare("
            UPDATE conversations
            SET updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND EXISTS (
                  SELECT 1
                  FROM conversation_members
                  WHERE conversation_id = conversations.id
                    AND user_id = ?
              )
        ");

        $stmt->bind_param('ii', $postedChatId, $userId);
        $stmt->execute();
        $stmt->close();

        header(
            'Location: messages.php?chat='
            . $postedChatId
            . '&unsent=1'
        );
        exit;

    } catch (Throwable $e) {
        error_log('Message unsend error: ' . $e->getMessage());

        header(
            'Location: messages.php?chat='
            . $postedChatId
            . '&error=unsend_failed'
        );
        exit;
    }
}

/* Send a message */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? 'send_message') === 'send_message'
) {
    $chatId = filter_input(
        INPUT_POST,
        'conversation_id',
        FILTER_VALIDATE_INT
    ) ?: 0;

    $message = trim($_POST['message'] ?? '');
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token)
        || !hash_equals($csrfToken, $token)
    ) {
        $errors[] = 'Session verification failed. Refresh the page and try again.';
    } elseif ($chatId < 1) {
        $errors[] = 'Please select a valid conversation.';
    } elseif ($message === '') {
        $errors[] = 'Please enter a message.';
    } elseif (mb_strlen($message) > 2000) {
        $errors[] = 'Messages cannot exceed 2,000 characters.';
    }

    /* Check conversation access */
    if (!$errors) {
        $stmt = $conn->prepare("
            SELECT conversation_id
            FROM conversation_members
            WHERE conversation_id = ?
              AND user_id = ?
            LIMIT 1
        ");

        $stmt->bind_param('ii', $chatId, $userId);
        $stmt->execute();

        $isMember = $stmt->get_result()->num_rows > 0;

        $stmt->close();

        if (!$isMember) {
            $errors[] = 'You do not have access to this conversation.';
        }
    }

    /* Save the message */
    if (!$errors) {
        $stmt = $conn->prepare("
            INSERT INTO messages (
                conversation_id,
                sender_id,
                body
            )
            VALUES (?, ?, ?)
        ");

        $stmt->bind_param(
            'iis',
            $chatId,
            $userId,
            $message
        );

        if ($stmt->execute()) {
            $stmt->close();

            $stmt = $conn->prepare("
                UPDATE conversations
                SET updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->bind_param('i', $chatId);
            $stmt->execute();
            $stmt->close();

            header('Location: messages.php?chat=' . $chatId);
            exit;
        }

        $stmt->close();

        $errors[] = 'Unable to send your message. Please try again.';
    }
}

/* Load conversations */
$stmt = $conn->prepare("
    SELECT
        c.id AS conversation_id,
        other_user.id AS other_user_id,
        other_user.full_name AS other_name,
        other_user.photo_path AS other_photo,
        latest.body AS last_message,
        latest.sender_id AS last_sender_id,
        latest.created_at AS last_message_at,
        c.updated_at
    FROM conversation_members AS mine
    INNER JOIN conversations AS c
        ON c.id = mine.conversation_id
    INNER JOIN conversation_members AS theirs
        ON theirs.conversation_id = c.id
       AND theirs.user_id <> mine.user_id
    INNER JOIN users AS other_user
        ON other_user.id = theirs.user_id
    LEFT JOIN messages AS latest
        ON latest.id = (
            SELECT m2.id
            FROM messages AS m2
            WHERE m2.conversation_id = c.id
            ORDER BY m2.created_at DESC, m2.id DESC
            LIMIT 1
        )
    WHERE mine.user_id = ?
      AND other_user.status = 'Active'
      AND (
          ? = ''
          OR other_user.full_name LIKE CONCAT('%', ?, '%')
      )
    ORDER BY
        COALESCE(latest.created_at, c.updated_at) DESC,
        c.id DESC
");

$stmt->bind_param('iss', $userId, $search, $search);
$stmt->execute();

$result = $stmt->get_result();
$conversations = [];

while ($row = $result->fetch_assoc()) {
    $conversations[(int) $row['conversation_id']] = $row;
}

$stmt->close();

/* Load linked posts */
$conversationContexts = [];

if (!empty($conversations)) {
    $contextStmt = $conn->prepare("
        SELECT
            id,
            conversation_id,
            source_type,
            source_id,
            source_title,
            source_url,
            started_by,
            created_at
        FROM conversation_contexts
        WHERE conversation_id = ?
        ORDER BY created_at DESC, id DESC
    ");

    foreach ($conversations as $conversationId => $conversation) {
        $contextStmt->bind_param('i', $conversationId);
        $contextStmt->execute();

        $contextResult = $contextStmt->get_result();

        $conversationContexts[$conversationId] = [];

        while ($context = $contextResult->fetch_assoc()) {
            $conversationContexts[$conversationId][] = $context;
        }
    }

    $contextStmt->close();
}

/* Select the conversation */
if ($chatId && !isset($conversations[$chatId])) {
    $chatId = 0;
}

if (!$chatId && !empty($conversations)) {
    $chatId = (int) array_key_first($conversations);
}

$selectedChat = $conversations[$chatId] ?? null;
$selectedContexts = $conversationContexts[$chatId] ?? [];

/* Load messages */
$thread = [];

if ($selectedChat) {
    $stmt = $conn->prepare("
        SELECT
            m.id,
            m.sender_id,
            m.body,
            m.created_at,
            u.full_name AS sender_name
        FROM messages AS m
        INNER JOIN users AS u
            ON u.id = m.sender_id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC, m.id ASC
    ");

    $stmt->bind_param('i', $chatId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($messageRow = $result->fetch_assoc()) {
        $thread[] = $messageRow;
    }

    $stmt->close();
}

/* Page settings */
$title = 'Messages';
$active = 'messages';
$css = ['messages'];

include __DIR__ . '/includes/header.php';
?>

<main class="wrap narrow">

    <header class="page-head">
        <h1>Messages</h1>
        <p>Chat with other students on StudentLink.</p>
    </header>

    <?php if (isset($_GET['unsent']) && $_GET['unsent'] === '1'): ?>
        <div class="message-notice" role="status">
            Message unsent successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="message-errors" role="alert">
            <?php
            $errorCode = (string) $_GET['error'];

            if ($errorCode === 'invalid_token') {
                echo 'Session verification failed. Please try again.';
            } elseif ($errorCode === 'unsend_failed') {
                echo 'Unable to unsend that message. You can only unsend your own messages.';
            } else {
                echo 'Something went wrong. Please try again.';
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="message-errors" role="alert">
            <?php foreach ($errors as $error): ?>
                <p><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="chat">

        <!-- Conversation list -->
        <section class="pane list" aria-label="Chats">

            <h2>Chats</h2>

            <form class="mini-search" method="get" role="search">
                <?php if ($chatId): ?>
                    <input
                        type="hidden"
                        name="chat"
                        value="<?= (int) $chatId ?>"
                    >
                <?php endif; ?>

                <input
                    type="search"
                    name="q"
                    placeholder="Search students"
                    value="<?= e($search) ?>"
                    aria-label="Search conversations"
                >
            </form>

            <nav>
                <?php foreach ($conversations as $cid => $conversation): ?>

                    <?php
                    $lastMessage = $conversation['last_message'] ?? '';
                    $lastSender = (int) ($conversation['last_sender_id'] ?? 0);
                    $contexts = $conversationContexts[$cid] ?? [];
                    ?>

                    <a
                        class="convo<?= $cid === $chatId ? ' on' : '' ?>"
                        href="messages.php?chat=<?= (int) $cid ?>"
                    >

                        <?php if (!empty($conversation['other_photo'])): ?>
                            <img
                                class="pic"
                                src="<?= e($conversation['other_photo']) ?>"
                                alt=""
                            >
                        <?php else: ?>
                            <span class="pic">
                                <?= e(initials($conversation['other_name'])) ?>
                            </span>
                        <?php endif; ?>

                        <span class="txt">
                            <strong><?= e($conversation['other_name']) ?></strong>

                            <?php if (!empty($contexts)): ?>
                                <small class="chat-context-preview">
                                    About:
                                    <?= e($contexts[0]['source_title']) ?>
                                    (<?= e(ucfirst($contexts[0]['source_type'])) ?>)
                                </small>
                            <?php endif; ?>

                            <small>
                                <?php if ($lastMessage !== ''): ?>
                                    <?php if ($lastSender === $userId): ?>
                                        You:
                                    <?php endif; ?>

                                    <?= e($lastMessage) ?>
                                <?php elseif (empty($contexts)): ?>
                                    Start a conversation
                                <?php else: ?>
                                    Asked about a post
                                <?php endif; ?>

                                <?php if (!empty($conversation['last_message_at'])): ?>
                                    &middot;
                                    <?= e(date(
                                        'M j, g:i a',
                                        strtotime($conversation['last_message_at'])
                                    )) ?>
                                <?php endif; ?>
                            </small>
                        </span>

                    </a>

                <?php endforeach; ?>

                <?php if (!$conversations): ?>
                    <p class="meta" style="padding: 12px 14px;">
                        <?= $search !== ''
                            ? 'No conversations match your search.'
                            : 'No conversations yet. Message a student from a post to start chatting.' ?>
                    </p>
                <?php endif; ?>
            </nav>

        </section>

        <!-- Selected conversation -->
        <section class="pane convo-pane" aria-label="Conversation">

            <?php if ($selectedChat): ?>

                <header class="who">
                    <?php if (!empty($selectedChat['other_photo'])): ?>
                        <img
                            class="pic"
                            src="<?= e($selectedChat['other_photo']) ?>"
                            alt=""
                        >
                    <?php else: ?>
                        <span class="pic">
                            <?= e(initials($selectedChat['other_name'])) ?>
                        </span>
                    <?php endif; ?>

                    <strong><?= e($selectedChat['other_name']) ?></strong>
                </header>

                <!-- Linked posts -->
                <details class="conversation-context">

                    <summary class="conversation-context-heading">
                        <span class="context-label">CONVERSATION DETAILS</span>
                        <span class="context-heading-title">What this chat is about</span>

                        <span class="context-summary-end">
                            <span class="context-count">
                                <?= count($selectedContexts) ?>
                            </span>
                            <span class="context-chevron" aria-hidden="true"></span>
                        </span>
                    </summary>

                    <div class="context-dropdown">

                        <?php if (!empty($selectedContexts)): ?>

                            <div class="conversation-context-list">

                                <?php foreach ($selectedContexts as $context): ?>

                                    <?php
                                    $sourceType = ucfirst(strtolower($context['source_type']));
                                    $startedByMe = (int) $context['started_by'] === $userId;
                                    ?>

                                    <article class="conversation-context-card">

                                        <div class="context-card-info">
                                            <span class="context-source">
                                                <?= e($sourceType) ?>
                                            </span>

                                            <strong class="context-title">
                                                <?= e($context['source_title']) ?>
                                            </strong>

                                            <small class="context-started-by">
                                                <?= $startedByMe
                                                    ? 'You started this chat from this post.'
                                                    : 'This student started the chat from this post.' ?>
                                            </small>
                                        </div>

                                        <a
                                            class="context-view-link"
                                            href="<?= e($context['source_url']) ?>"
                                        >
                                            View post
                                            <span aria-hidden="true">&rarr;</span>
                                        </a>

                                    </article>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <p class="context-empty">
                                No post has been linked to this conversation yet.
                            </p>

                        <?php endif; ?>

                    </div>

                </details>

                <!-- Message history -->
                <div class="thread" id="thread" aria-live="polite">

                    <?php if (!$thread): ?>
                        <p class="meta empty-thread">
                            No messages yet. Say hello!
                        </p>
                    <?php endif; ?>

                    <?php foreach ($thread as $messageRow): ?>

                        <?php
                        $isMine = (int) $messageRow['sender_id'] === $userId;
                        ?>

                        <div class="message-row <?= $isMine ? 'mine' : 'theirs' ?>">

                            <div class="message-content">
                                <p class="bubble <?= $isMine ? 'me' : 'them' ?>">
                                    <?= nl2br(e($messageRow['body'])) ?>
                                </p>

                                <small class="message-time">
                                    <?= e(date(
                                        'M j, g:i a',
                                        strtotime($messageRow['created_at'])
                                    )) ?>
                                </small>

                                <?php if ($isMine): ?>
                                    <form
                                        class="unsend-form"
                                        method="post"
                                        onsubmit="return confirm('Unsend this message for everyone? This cannot be undone.');"
                                    >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="unsend_message"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="conversation_id"
                                            value="<?= (int) $chatId ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="message_id"
                                            value="<?= (int) $messageRow['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="unsend-button"
                                        >
                                            Unsend
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

                <!-- Send message -->
                <form class="compose" method="post">

                    <input
                        type="hidden"
                        name="action"
                        value="send_message"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="conversation_id"
                        value="<?= (int) $chatId ?>"
                    >

                    <input
                        name="message"
                        type="text"
                        maxlength="2000"
                        placeholder="Type a message..."
                        autocomplete="off"
                        aria-label="Type a message"
                        required
                    >

                    <button class="btn btn-primary" type="submit">
                        Send
                    </button>

                </form>

            <?php else: ?>

                <div class="empty-conversation">
                    <h2>Your conversations</h2>
                    <p>
                        Select a conversation from the left to read
                        and send messages.
                    </p>
                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<script>
    const thread = document.getElementById('thread');
    if (thread) {
        thread.scrollTop = thread.scrollHeight;
    }

    /* Close the details dropdown when clicking outside or pressing Esc */
    const contextDropdown = document.querySelector('details.conversation-context');

    if (contextDropdown) {
        document.addEventListener('click', function (event) {
            if (!contextDropdown.contains(event.target)) {
                contextDropdown.removeAttribute('open');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && contextDropdown.hasAttribute('open')) {
                contextDropdown.removeAttribute('open');
                contextDropdown.querySelector('summary').focus();
            }
        });
    }

</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
