
<?php

/* Escape output to prevent HTML injection */
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
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

    return array_values(array_filter($rows, function($r) use ($q, $keys, $eq) {
        foreach ($eq as $f) {
            $v = $_GET[$f] ?? '';

            if ($v !== '' && strcasecmp((string)$r[$f], $v) !== 0) {
                return false;
            }
        }

        if ($q === '') {
            return true;
        }

        foreach ($keys as $k) {
            if (str_contains(strtolower((string)$r[$k]), $q)) {
                return true;
            }
        }

        return false;
    }));
}

/* Prints a select whose first option clears the filter */
function select_filter(string $name, string $label, array $options) {
    $cur = $_GET[$name] ?? '';

    echo '<select class="select" name="' . e($name) . '" aria-label="' . e($label) . '" onchange="this.form.submit()">';
    echo '<option value="">' . e($label) . '</option>';

    foreach ($options as $o) {
        echo '<option' . (strcasecmp($cur, $o) === 0 ? ' selected' : '') . '>' . e($o) . '</option>';
    }

    echo '</select>';
}

/* Prints option tags for form selects */
function options(array $list, string $placeholder = '', string $selected = '') {
    if ($placeholder !== '') {
        echo '<option value="">' . e($placeholder) . '</option>';
    }

    foreach ($list as $o) {
        echo '<option' . ($o === $selected ? ' selected' : '') . '>' . e($o) . '</option>';
    }
}

/* Returns labels of required POST fields that are empty */
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

/* Displays a success message when posted */
function flash(string $message) {
    if (isset($_GET['posted'])) {
        echo '<div class="notice" role="status">' . e($message) . '</div>';
    }
}

/* Gets initials from a full name */
function initials(string $name) {
    $p = preg_split('/\s+/', trim($name));

    return strtoupper(
        substr($p[0] ?? '', 0, 1) .
        (isset($p[1]) ? substr($p[1], 0, 1) : '')
    );
}


/* =========================================================
   NOTIFICATION FUNCTIONS
   ========================================================= */

/*
   Sends a notification to one user.

   Example:
   createNotification(
       $conn,
       $userId,
       'You successfully downloaded a resource.',
       'notifications.php'
   );
*/
function createNotification(mysqli $conn, int $userId, string $message, ?string $link = null): bool {

    if ($userId <= 0 || trim($message) === '') {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, message, link)
        VALUES (?, ?, ?)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iss", $userId, $message, $link);

    $success = $stmt->execute();

    $stmt->close();

    return $success;
}


/*
   Sends a notification to active admins who enabled
   the selected notification preference.

   Allowed preferences:
   notify_reports
   notify_registrations
   notify_content
   notify_transactions
   notify_new_content

   Example:
   notifyAdmins(
       $conn,
       'notify_new_content',
       'A new resource has been uploaded.',
       'admin/resources.php'
   );
*/
function notifyAdmins(
    mysqli $conn,
    string $preference,
    string $message,
    ?string $link = null
): int {

    $allowedPreferences = [
        'notify_reports',
        'notify_registrations',
        'notify_content',
        'notify_transactions',
        'notify_new_content'
    ];

    /* Prevent invalid SQL column names */
    if (!in_array($preference, $allowedPreferences, true)) {
        return 0;
    }

    if (trim($message) === '') {
        return 0;
    }

    /*
       Get active admin accounts.

       If an admin has no settings row yet,
       their notifications are enabled by default.
    */
    $sql = "
        SELECT
            u.id,
            COALESCE(s.$preference, 1) AS notification_enabled
        FROM users u
        LEFT JOIN admin_notification_settings s
            ON s.admin_id = u.id
        WHERE u.role = 'admin'
          AND u.status = 'Active'
    ";

    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $sentCount = 0;

    while ($admin = $result->fetch_assoc()) {

        if ((int)$admin['notification_enabled'] !== 1) {
            continue;
        }

        if (createNotification(
            $conn,
            (int)$admin['id'],
            $message,
            $link
        )) {
            $sentCount++;
        }
    }

    $result->free();

    return $sentCount;
}
