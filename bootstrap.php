<?php

require_once __DIR__ . '/config.php';

$defaultDatabaseDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$defaultDatabasePath = $defaultDatabaseDirectory . DIRECTORY_SEPARATOR . 'social_insight.sqlite';

$customPath = env('DB_PATH');
$databasePath = $defaultDatabasePath;

if (is_string($customPath) && $customPath !== '') {
    $candidatePath = trim($customPath);

    // Treat relative paths as relative to the project root.
    $isAbsolute = preg_match('/^(?:[a-zA-Z]:\\\\|\\\\\\\\|\\/)/', $candidatePath) === 1;

    if (!$isAbsolute) {
        $candidatePath = __DIR__ . DIRECTORY_SEPARATOR . $candidatePath;
    }

    $databasePath = $candidatePath;
}

$databaseDirectory = dirname($databasePath);

if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0775, true) && !is_dir($databaseDirectory)) {
    throw new RuntimeException('Unable to create data directory at ' . $databaseDirectory);
}

$logPath = $databaseDirectory . DIRECTORY_SEPARATOR . 'php-error.log';
if (!file_exists($logPath) && !touch($logPath)) {
    $logPath = ini_get('error_log');
}
ini_set('error_log', $logPath);

$initializeSchema = !file_exists($databasePath);

$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if ($initializeSchema) {
    $schema = <<<SQL
        PRAGMA foreign_keys = ON;

        CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wa_id TEXT NOT NULL UNIQUE,
            name TEXT,
            channel TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            last_message_at TEXT
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL,
            wa_message_id TEXT,
            sender_name TEXT,
            sender_phone TEXT,
            message_type TEXT,
            message_body TEXT,
            is_from_me INTEGER NOT NULL DEFAULT 0,
            sent_at TEXT NOT NULL,
            raw_payload TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_messages_group_id_sent_at ON messages (group_id, sent_at);
        CREATE INDEX IF NOT EXISTS idx_groups_last_message_at ON groups (last_message_at);
    SQL;

    $pdo->exec($schema);
}

/**
 * Returns a cached PDO instance for the application.
 */
function db(): PDO
{
    static $instance;

    if ($instance instanceof PDO) {
        return $instance;
    }

    global $pdo;
    $instance = $pdo;

    return $instance;
}

/**
 * Inserts or updates a group record.
 */
function upsertGroup(PDO $pdo, array $group): int
{
    $existing = $pdo->prepare('SELECT id FROM groups WHERE wa_id = :wa_id');
    $existing->execute([':wa_id' => $group['wa_id']]);
    $groupId = $existing->fetchColumn();

    if ($groupId) {
        $update = $pdo->prepare(
            'UPDATE groups SET name = :name, channel = :channel, updated_at = datetime("now"), last_message_at = :last_message_at WHERE id = :id'
        );
        $update->execute([
            ':name' => $group['name'],
            ':channel' => $group['channel'],
            ':last_message_at' => $group['last_message_at'],
            ':id' => $groupId,
        ]);

        return (int) $groupId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO groups (wa_id, name, channel, last_message_at) VALUES (:wa_id, :name, :channel, :last_message_at)'
    );
    $insert->execute([
        ':wa_id' => $group['wa_id'],
        ':name' => $group['name'],
        ':channel' => $group['channel'],
        ':last_message_at' => $group['last_message_at'],
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Persists a message for a given group.
 */
function storeMessage(PDO $pdo, int $groupId, array $message): void
{
    $insert = $pdo->prepare(
        'INSERT INTO messages (group_id, wa_message_id, sender_name, sender_phone, message_type, message_body, is_from_me, sent_at, raw_payload)
         VALUES (:group_id, :wa_message_id, :sender_name, :sender_phone, :message_type, :message_body, :is_from_me, :sent_at, :raw_payload)'
    );

    $insert->execute([
        ':group_id' => $groupId,
        ':wa_message_id' => $message['wa_message_id'],
        ':sender_name' => $message['sender_name'],
        ':sender_phone' => $message['sender_phone'],
        ':message_type' => $message['message_type'],
        ':message_body' => $message['message_body'],
        ':is_from_me' => $message['is_from_me'] ? 1 : 0,
        ':sent_at' => $message['sent_at'],
        ':raw_payload' => json_encode($message['raw_payload'], JSON_UNESCAPED_UNICODE),
    ]);

    $pdo->prepare('UPDATE groups SET last_message_at = :sent_at WHERE id = :group_id')
        ->execute([':sent_at' => $message['sent_at'], ':group_id' => $groupId]);
}
