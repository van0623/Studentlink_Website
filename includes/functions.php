
<?php

/* Escape output */
function e($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/* Find a row by ID */
function find_by_id(array $rows, $id) {
    foreach ($rows as $r) {
        if ($r['id'] == $id) {
            return $r;
        }
    }

    return $rows[0] ?? null;
}

/* Search and filter rows */
function filter_rows(array $rows, array $keys, array $eq = []) {
    $q = strtolower(trim($_GET['q'] ?? ''));

    return array_values(array_filter($rows, function ($r) use ($q, $keys, $eq) {
        foreach ($eq as $f) {
            $v = $_GET[$f] ?? '';

            if ($v !== '' && strcasecmp((string) $r[$f], $v) !== 0) {
                return false;
            }
        }

        if ($q === '') {
            return true;
        }

        foreach ($keys as $k) {
            if (str_contains(strtolower((string) $r[$k]), $q)) {
                return true;
            }
        }

        return false;
    }));
}

/* Prints a select filter */
function select_filter(string $name, string $label, array $options) {
    $cur = $_GET[$name] ?? '';

    echo '<select class="select" name="' . e($name) . '" aria-label="' . e($label) . '" onchange="this.form.submit()">';
    echo '<option value="">' . e($label) . '</option>';

    foreach ($options as $o) {
        echo '<option' . (strcasecmp($cur, $o) === 0 ? ' selected' : '') . '>' . e($o) . '</option>';
    }

    echo '</select>';
}

/* Prints option tags */
function options(array $list, string $placeholder = '', string $selected = '') {
    if ($placeholder !== '') {
        echo '<option value="">' . e($placeholder) . '</option>';
    }

    foreach ($list as $o) {
        echo '<option' . ($o === $selected ? ' selected' : '') . '>' . e($o) . '</option>';
    }
}

/* Checks required fields */
function missing(array $labels) {
    $out = [];

    foreach ($labels as $key => $label) {
        if (trim($_POST[$key] ?? '') === '') {
            $out[] = $label;
        }
    }

    return $out;
}

/* Displays validation errors */
function show_errors(array $errors) {
    if (!$errors) {
        return;
    }

    echo '<div class="notice error" role="alert"><strong>Please fill in:</strong><ul>';

    foreach ($errors as $m) {
        echo '<li>' . e($m) . '</li>';
    }

    echo '</ul></div>';
}

/* Displays a success message */
function flash(string $message) {
    if (isset($_GET['posted'])) {
        echo '<div class="notice" role="status">' . e($message) . '</div>';
    }
}

/* Gets initials from a name */
function initials(string $name) {
    $p = preg_split('/\s+/', trim($name));

    return strtoupper(
        substr($p[0] ?? '', 0, 1) .
        (isset($p[1]) ? substr($p[1], 0, 1) : '')
    );
}

/* Creates a notification */
function createNotification(
    mysqli $conn,
    int $userId,
    string $message,
    ?string $link = null,
    ?int $actorId = null
): bool {

    if ($userId <= 0 || trim($message) === '') {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications
            (user_id, actor_id, message, link)
        VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log('Create notification error: ' . $conn->error);
        return false;
    }

    $stmt->bind_param(
        'iiss',
        $userId,
        $actorId,
        $message,
        $link
    );

    $success = $stmt->execute();

    if (!$success) {
        error_log('Create notification execution error: ' . $stmt->error);
    }

    $stmt->close();

    return $success;
}

/* Sends notifications to admins based on admin_settings preferences */
function notifyAdmins(
    mysqli $conn,
    string $preference,
    string $message,
    ?string $link = null,
    ?int $actorId = null
): int {

    /* Only allow valid notification preference columns */
    $allowedPreferences = [
        'notify_reports',
        'notify_registrations',
        'notify_content',
        'notify_transactions',
        'notify_new_content',
        'notify_security'
    ];

    if (!in_array($preference, $allowedPreferences, true)) {
        return 0;
    }

    if (trim($message) === '') {
        return 0;
    }

    /* Get active administrators and their notification preferences */
    $sql = "
        SELECT
            u.id,
            COALESCE(s.$preference, 1) AS notification_enabled
        FROM users u
        LEFT JOIN admin_settings s
            ON s.user_id = u.id
        WHERE u.role = 'admin'
          AND u.status = 'Active'
    ";

    $result = $conn->query($sql);

    if (!$result) {
        error_log('Admin notification query error: ' . $conn->error);
        return 0;
    }

    $sentCount = 0;

    while ($admin = $result->fetch_assoc()) {

        /* Skip admins who disabled this notification */
        if ((int) $admin['notification_enabled'] !== 1) {
            continue;
        }

        /* Create notification for the administrator */
        if (createNotification(
            $conn,
            (int) $admin['id'],
            $message,
            $link,
            $actorId
        )) {
            $sentCount++;
        }
    }

    $result->free();

    return $sentCount;
}

/* Records an activity performed by an admin */
function logAdminActivity(
    mysqli $conn,
    int $adminId,
    string $preference,
    string $message,
    ?string $link = null
): int {

    if ($adminId <= 0 || trim($message) === '') {
        return 0;
    }

    /* Only allow valid admin notification preferences */
    $allowedPreferences = [
        'notify_reports',
        'notify_registrations',
        'notify_content',
        'notify_transactions',
        'notify_new_content',
        'notify_security'
    ];

    if (!in_array($preference, $allowedPreferences, true)) {
        return 0;
    }

    /*
     * Send the activity notification to active admins
     */
    return notifyAdmins(
        $conn,
        $preference,
        $message,
        $link,
        $adminId
    );
}
