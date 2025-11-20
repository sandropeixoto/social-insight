<?php

require_once __DIR__ . '/config.php';

$defaultDatabaseDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$defaultDatabasePath = $defaultDatabaseDirectory . DIRECTORY_SEPARATOR . 'social_insight.sqlite';
$defaultMediaDirectory = $defaultDatabaseDirectory . DIRECTORY_SEPARATOR . 'media';

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

if (!defined('DATABASE_FILE')) {
    define('DATABASE_FILE', $databasePath);
}

$mediaPath = env('MEDIA_STORAGE_PATH');

if (is_string($mediaPath) && $mediaPath !== '') {
    $candidatePath = trim($mediaPath);
    $isAbsolute = preg_match('/^(?:[a-zA-Z]:\\\\|\\\\|\/)/', $candidatePath) === 1;

    if (!$isAbsolute) {
        $candidatePath = __DIR__ . DIRECTORY_SEPARATOR . $candidatePath;
    }

    $mediaDirectory = $candidatePath;
} else {
    $mediaDirectory = $defaultMediaDirectory;
}

if (!is_dir($mediaDirectory) && !mkdir($mediaDirectory, 0775, true) && !is_dir($mediaDirectory)) {
    throw new RuntimeException('Unable to create media directory at ' . $mediaDirectory);
}

if (!defined('MEDIA_STORAGE_PATH')) {
    define('MEDIA_STORAGE_PATH', realpath($mediaDirectory) ?: $mediaDirectory);
}

$webhookLog = $databaseDirectory . DIRECTORY_SEPARATOR . 'webhook.log';
if (!file_exists($webhookLog)) {
    @touch($webhookLog);
}
if (!defined('WEBHOOK_LOG_PATH')) {
    define('WEBHOOK_LOG_PATH', $webhookLog);
}

$logPath = $databaseDirectory . DIRECTORY_SEPARATOR . 'php-error.log';
if (!file_exists($logPath) && !touch($logPath)) {
    $logPath = ini_get('error_log');
}
ini_set('error_log', $logPath);

$initializeSchema = !file_exists($databasePath);

$pdo = createPdoConnection($databasePath);

if ($initializeSchema) {
    initializeDatabaseSchema($pdo);
}

ensureMessagesMediaColumns($pdo);
ensureGroupsAvatarColumn($pdo);

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
function upsertGroup(PDO $pdo, array $group, bool $preserveName = false): int
{
    $existingStmt = $pdo->prepare('SELECT id, name, avatar_url FROM groups WHERE wa_id = :wa_id');
    $existingStmt->execute([':wa_id' => $group['wa_id']]);
    $existing = $existingStmt->fetch();
    $groupId = $existing['id'] ?? null;

    $incomingName = trim((string) ($group['name'] ?? ''));
    $currentName = isset($existing['name']) ? (string) $existing['name'] : null;
    $incomingAvatar = trim((string) ($group['avatar_url'] ?? ''));
    $currentAvatar = isset($existing['avatar_url']) ? (string) $existing['avatar_url'] : null;

    $shouldUpdateName = $incomingName !== '' && (
        !$preserveName
        || $currentName === null
        || $currentName === ''
        || $currentName === $group['wa_id']
    );

    $nameToStore = $shouldUpdateName ? $incomingName : ($currentName ?? $incomingName);
    $avatarToStore = $incomingAvatar !== '' ? $incomingAvatar : $currentAvatar;

    if ($groupId) {
        $update = $pdo->prepare(
            'UPDATE groups SET name = :name, channel = :channel, avatar_url = :avatar_url, updated_at = datetime("now"), last_message_at = :last_message_at WHERE id = :id'
        );
        $update->execute([
            ':name' => $nameToStore,
            ':channel' => $group['channel'],
            ':avatar_url' => $avatarToStore,
            ':last_message_at' => $group['last_message_at'],
            ':id' => $groupId,
        ]);

        return (int) $groupId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO groups (wa_id, name, channel, avatar_url, last_message_at) VALUES (:wa_id, :name, :channel, :avatar_url, :last_message_at)'
    );
    $insert->execute([
        ':wa_id' => $group['wa_id'],
        ':name' => $nameToStore,
        ':channel' => $group['channel'],
        ':avatar_url' => $avatarToStore,
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
        'INSERT INTO messages (group_id, wa_message_id, sender_name, sender_phone, message_type, message_body, is_from_me, sent_at, media_path, media_mime, media_size, media_duration, media_caption, media_original_name, media_url, raw_payload)
         VALUES (:group_id, :wa_message_id, :sender_name, :sender_phone, :message_type, :message_body, :is_from_me, :sent_at, :media_path, :media_mime, :media_size, :media_duration, :media_caption, :media_original_name, :media_url, :raw_payload)'
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
        ':media_path' => $message['media_path'] ?? null,
        ':media_mime' => $message['media_mime'] ?? null,
        ':media_size' => $message['media_size'] ?? null,
        ':media_duration' => $message['media_duration'] ?? null,
        ':media_caption' => $message['media_caption'] ?? null,
        ':media_original_name' => $message['media_original_name'] ?? null,
        ':media_url' => $message['media_url'] ?? null,
        ':raw_payload' => json_encode($message['raw_payload'], JSON_UNESCAPED_UNICODE),
    ]);

    $pdo->prepare('UPDATE groups SET last_message_at = :sent_at WHERE id = :group_id')
        ->execute([':sent_at' => $message['sent_at'], ':group_id' => $groupId]);
}

function ensureMessagesMediaColumns(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(messages)') as $column) {
        if (isset($column['name'])) {
            $columns[$column['name']] = true;
        }
    }

    $migrations = [
        'media_path' => 'ALTER TABLE messages ADD COLUMN media_path TEXT',
        'media_mime' => 'ALTER TABLE messages ADD COLUMN media_mime TEXT',
        'media_size' => 'ALTER TABLE messages ADD COLUMN media_size INTEGER',
        'media_duration' => 'ALTER TABLE messages ADD COLUMN media_duration INTEGER',
        'media_caption' => 'ALTER TABLE messages ADD COLUMN media_caption TEXT',
        'media_original_name' => 'ALTER TABLE messages ADD COLUMN media_original_name TEXT',
        'media_url' => 'ALTER TABLE messages ADD COLUMN media_url TEXT',
    ];

    foreach ($migrations as $name => $sql) {
        if (!isset($columns[$name])) {
            $pdo->exec($sql);
        }
    }
}

function ensureGroupsAvatarColumn(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(groups)') as $column) {
        if (isset($column['name'])) {
            $columns[$column['name']] = true;
        }
    }

    if (!isset($columns['avatar_url'])) {
        $pdo->exec('ALTER TABLE groups ADD COLUMN avatar_url TEXT');
    }
}

function createPdoConnection(string $databasePath): PDO
{
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function initializeDatabaseSchema(PDO $pdo): void
{
    $pdo->exec(databaseSchemaDefinition());
}

function databaseSchemaDefinition(): string
{
    return <<<SQL
        PRAGMA foreign_keys = ON;

        CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wa_id TEXT NOT NULL UNIQUE,
            name TEXT,
            channel TEXT,
            avatar_url TEXT,
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
            media_path TEXT,
            media_mime TEXT,
            media_size INTEGER,
            media_duration INTEGER,
            media_caption TEXT,
            media_original_name TEXT,
            media_url TEXT,
            raw_payload TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_messages_group_id_sent_at ON messages (group_id, sent_at);
        CREATE INDEX IF NOT EXISTS idx_groups_last_message_at ON groups (last_message_at);
    SQL;
}
