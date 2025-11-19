<?php

require_once __DIR__ . '/../../bootstrap.php';

if (!APP_DEBUG) {
    http_response_code(403);
    echo json_encode(['error' => 'Debug endpoints are disabled.']);
    exit;
}

$action = $_REQUEST['action'] ?? 'status';

switch ($action) {
    case 'status':
        respondJson([
            'database_exists' => file_exists(DATABASE_FILE),
            'database_path' => DATABASE_FILE,
            'log_path' => WEBHOOK_LOG_PATH,
            'media_path' => MEDIA_STORAGE_PATH,
        ]);
        break;

    case 'tables':
        handleTablesAction();
        break;

    case 'log':
        handleLogAction();
        break;

    case 'schema':
        respondJson(['schema' => databaseSchemaDefinition()]);
        break;

    case 'run-schema':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            methodNotAllowed();
        }
        initializeDatabaseSchema(db());
        respondJson(['status' => 'schema_applied']);
        break;

    case 'reset-db':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            methodNotAllowed();
        }
        resetDatabase();
        respondJson(['status' => 'database_reset']);
        break;

    default:
        http_response_code(400);
        respondJson(['error' => 'Unknown action.']);
}

function handleTablesAction(): void
{
    $pdo = db();
    $table = filter_input(INPUT_GET, 'table', FILTER_UNSAFE_RAW);
    $tables = fetchTableNames($pdo);

    if ($table) {
        $table = trim((string) $table);
        if (!in_array($table, $tables, true)) {
            http_response_code(404);
            respondJson(['error' => 'Table not found']);
        }

        $limit = (int) ($_GET['limit'] ?? 100);
        $limit = max(1, min(500, $limit));

        $stmt = $pdo->query(sprintf('SELECT * FROM "%s" LIMIT %d', $table, $limit));
        $rows = $stmt ? $stmt->fetchAll() : [];

        respondJson([
            'table' => $table,
            'rows' => $rows,
            'count' => count($rows),
        ]);
    }

    $details = [];
    foreach ($tables as $tableName) {
        $count = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM "%s"', $tableName))->fetchColumn();
        $details[] = ['name' => $tableName, 'rows' => $count];
    }

    respondJson(['tables' => $details]);
}

function handleLogAction(): void
{
    $lines = (int) ($_GET['lines'] ?? 200);
    $lines = max(10, min(1000, $lines));

    $content = tailFile(WEBHOOK_LOG_PATH, $lines);
    respondJson([
        'path' => WEBHOOK_LOG_PATH,
        'lines' => $content,
    ]);
}

function fetchTableNames(PDO $pdo): array
{
    $names = [];
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    foreach ($stmt as $row) {
        if (!empty($row['name'])) {
            $names[] = $row['name'];
        }
    }

    return $names;
}

function tailFile(string $path, int $lines): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $file = new SplFileObject($path, 'r');
    $file->seek(PHP_INT_MAX);
    $lastLine = $file->key();
    $position = max(0, $lastLine - $lines);
    $file->seek($position);

    $output = [];
    while (!$file->eof()) {
        $line = trim((string) $file->fgets());
        if ($line !== '') {
            $output[] = $line;
        }
    }

    return array_slice($output, -$lines);
}

function resetDatabase(): void
{
    global $pdo;

    if (file_exists(DATABASE_FILE)) {
        unlink(DATABASE_FILE);
    }

    $pdo = createPdoConnection(DATABASE_FILE);
    initializeDatabaseSchema($pdo);
    ensureMessagesMediaColumns($pdo);
}

function methodNotAllowed(): void
{
    http_response_code(405);
    respondJson(['error' => 'Method not allowed']);
    exit;
}

function respondJson(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
