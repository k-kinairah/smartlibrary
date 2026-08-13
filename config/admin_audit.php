<?php
declare(strict_types=1);

function smartlib_admin_audit_actions(): array {
    return ['added', 'updated', 'deleted', 'returned', 'missing', 'status_changed', 'retrieved'];
}

function smartlib_admin_audit_ensure_table(mysqli $conn): bool {
    $actionEnum = "'" . implode("','", smartlib_admin_audit_actions()) . "'";
    $sql = "
        CREATE TABLE IF NOT EXISTS admin_activity_logs (
            activity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id INT NULL,
            actor_name VARCHAR(160) NOT NULL,
            action_type ENUM({$actionEnum}) NOT NULL,
            entity_type VARCHAR(40) NOT NULL DEFAULT 'book',
            entity_id INT NULL,
            entity_title VARCHAR(255) NOT NULL,
            metadata_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (activity_id),
            KEY idx_activity_created_at (created_at),
            KEY idx_activity_action_entity (action_type, entity_type),
            KEY idx_activity_actor (actor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        if (!$conn->query($sql)) {
            return false;
        }

        $conn->query("ALTER TABLE admin_activity_logs MODIFY action_type ENUM({$actionEnum}) NOT NULL");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function smartlib_admin_audit_actor_user_id(): int {
    $sessionKeys = ['admin_id', 'user_id', 'librarian_id'];
    foreach ($sessionKeys as $key) {
        $value = (int)($_SESSION[$key] ?? 0);
        if ($value > 0) {
            return $value;
        }
    }

    return 0;
}

function smartlib_admin_audit_actor_name(): string {
    $name = trim((string)($_SESSION['name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($_SESSION['full_name'] ?? ''));
    }
    if ($name === '') {
        $first = trim((string)($_SESSION['first_name'] ?? ''));
        $last = trim((string)($_SESSION['last_name'] ?? ''));
        $name = trim($first . ' ' . $last);
    }
    if ($name === '') {
        $name = trim((string)($_SESSION['username'] ?? ''));
    }
    if ($name === '') {
        $name = 'System Admin';
    }

    return $name;
}

function smartlib_admin_audit_log(
    mysqli $conn,
    string $actionType,
    string $entityType,
    int $entityId,
    string $entityTitle,
    array $metadata = []
): void {
    $actionType = strtolower(trim($actionType));
    $entityType = strtolower(trim($entityType));
    if (!in_array($actionType, smartlib_admin_audit_actions(), true)) {
        return;
    }

    if ($entityType === '') {
        $entityType = 'system';
    }

    if (!smartlib_admin_audit_ensure_table($conn)) {
        return;
    }

    $actorUserId = smartlib_admin_audit_actor_user_id();
    $actorName = smartlib_admin_audit_actor_name();
    $safeTitle = trim($entityTitle);
    if ($safeTitle === '') {
        $safeTitle = ucfirst($entityType) . ($entityId > 0 ? ' #' . $entityId : '');
    }

    $metaJson = '';
    if (!empty($metadata)) {
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $metaJson = $encoded;
        }
    }

    $stmt = $conn->prepare(
        "INSERT INTO admin_activity_logs (
            actor_user_id, actor_name, action_type, entity_type,
            entity_id, entity_title, metadata_json, created_at
        ) VALUES (
            NULLIF(?, 0), ?, ?, ?, NULLIF(?, 0), ?, NULLIF(?, ''), NOW()
        )"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('isssiss', $actorUserId, $actorName, $actionType, $entityType, $entityId, $safeTitle, $metaJson);
    $stmt->execute();
    $stmt->close();
}

function smartlib_admin_audit_action_label(string $action): string {
    $labels = [
        'added' => 'Added',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'returned' => 'Returned',
        'missing' => 'Marked Missing',
        'status_changed' => 'Status Changed',
        'retrieved' => 'Retrieved'
    ];

    $key = strtolower(trim($action));
    return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
}
