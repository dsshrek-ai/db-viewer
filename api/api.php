<?php
// DB Viewer — tiny admin-only tool: pick any real table in the shared
// MyDataWorld database and dump its rows. Reuses the same users/sessions
// login as every other MyDataWorld app, but isn't registered as an app in
// My Apps Hub — access is gated on users.is_admin = 1 directly, since this
// reaches every table in the shared database, not just one app's own data.

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Never let a raw PHP error/notice leak through as HTML — every response
// this API sends must be JSON.
ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function requireAdmin(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];

  $stmt = db()->prepare(
    'SELECT u.id, u.display_name, u.is_admin
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user) {
    fail('Session expired or invalid — please log in again', 401);
  }
  if ((int)$user['is_admin'] !== 1) {
    fail('Not authorized — admin accounts only', 403);
  }
  return $user;
}

// Real table names in DB_NAME's own schema only. This is both the dropdown's
// data source AND the injection guard for the `dump` action below — a table
// name can't be a bound query parameter, so it's only ever used in a query
// after being checked against this exact list.
function listRealTables(): array {
  // bind_param() takes its arguments by reference, which a constant (DB_NAME)
  // can't satisfy directly -- has to go through a real variable first.
  $dbName = DB_NAME;
  $stmt = db()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
  $stmt->bind_param('s', $dbName);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return array_map(fn($r) => $r['TABLE_NAME'], $rows);
}

// The `runQuery` action runs whatever SQL is typed, so it's fenced to reads
// only. This is admin-only already (is_admin = 1), so the guard is here to stop
// an accidental UPDATE/DELETE and to blunt a stolen session -- not to sandbox a
// hostile user. Anything that isn't plainly a SELECT/SHOW/EXPLAIN/DESCRIBE, or
// that smells of a write, DDL, privilege change, file access, stacked statement,
// or a MySQL /*! ... */ executable comment, is rejected before it runs.
function assertReadOnly(string $sql): void {
  if (strpos($sql, '/*!') !== false) {
    fail('Executable comments ( /*! ... */ ) are not allowed', 400);
  }

  // Strip comments so a keyword can't hide behind one during inspection.
  $noComments = preg_replace('!/\*.*?\*/!s', ' ', $sql);
  $noComments = preg_replace('/(^|\s)(--\s|#)[^\n]*/', ' ', (string)$noComments);
  $noComments = trim((string)$noComments);
  if ($noComments === '') {
    fail('Enter a query', 400);
  }

  if (!preg_match('/^(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $noComments)) {
    fail('Only read-only queries are allowed (SELECT, WITH, SHOW, EXPLAIN, DESCRIBE)', 400);
  }

  // Blank out string / backtick literals too, so a ";" or a keyword sitting
  // inside quoted data (WHERE note = 'call back tomorrow') doesn't trip the
  // checks below.
  $scan = preg_replace(
    ['/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s', '/"(?:[^"\\\\]|\\\\.|"")*"/s', '/`[^`]*`/s'],
    ' ',
    $noComments
  );

  // One statement at a time (a lone trailing ";" is fine).
  if (strpos(rtrim((string)$scan, "; \t\r\n"), ';') !== false) {
    fail('One statement at a time -- remove the extra ";"', 400);
  }

  if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|RENAME|GRANT|REVOKE|CALL|LOCK|UNLOCK|LOAD|HANDLER|PREPARE|DEALLOCATE|OUTFILE|DUMPFILE)\b/i', (string)$scan)) {
    fail('That query contains a write, DDL, or privilege keyword -- this tool is read-only', 400);
  }
  if (preg_match('/\bINTO\b/i', (string)$scan)) {
    fail('SELECT ... INTO is not allowed', 400);
  }
  if (preg_match('/\bLOAD_FILE\s*\(/i', (string)$scan)) {
    fail('LOAD_FILE() is not allowed', 400);
  }
}

// Saved queries live in db_viewer_queries (see api/schema.sql). Fail clearly if
// that one-time schema step hasn't been run yet.
function requireSavedQueriesTable(): void {
  try {
    db()->query('SELECT 1 FROM db_viewer_queries LIMIT 1');
  } catch (mysqli_sql_exception $e) {
    fail('Saved-queries table not found -- run api/schema.sql once in phpMyAdmin.', 400);
  }
}

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');

switch ($action) {

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $stmt = db()->prepare('SELECT id, password_hash, display_name, is_admin FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    if ((int)$user['is_admin'] !== 1) {
      fail('Not authorized — admin accounts only', 403);
    }
    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();
    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['ok' => true]);
  }

  case 'checkAccess': {
    $user = requireAdmin();
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  case 'tables': {
    requireAdmin();
    respond(['tables' => listRealTables()]);
  }

  // Read-only, capped at 2000 rows -- this is a debugging/inspection tool,
  // not a paginated data browser.
  case 'dump': {
    requireAdmin();
    $table = (string)($_GET['table'] ?? '');
    if (!in_array($table, listRealTables(), true)) {
      fail('Unknown table', 400);
    }
    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 2000);
    $result = db()->query('SELECT * FROM `' . $table . '` LIMIT ' . $limit);
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    respond(['table' => $table, 'rowCount' => count($rows), 'limit' => $limit, 'rows' => $rows]);
  }

  // Run typed SQL -- reads only (see assertReadOnly). Column order comes from
  // fetch_fields() so the header renders even for a zero-row result, and the
  // payload is capped so a careless SELECT can't return the whole database.
  case 'runQuery': {
    requireAdmin();
    $sql = trim((string)($body['sql'] ?? ''));
    if ($sql === '') {
      fail('Enter a query', 400);
    }
    assertReadOnly($sql);
    $sqlToRun = rtrim($sql, "; \t\r\n");

    // Best-effort server-side time limit (name/units differ across MySQL and
    // MariaDB; ignore if the server has neither).
    try { db()->query('SET SESSION max_execution_time = 20000'); } catch (Throwable $e) {}
    try { db()->query('SET SESSION max_statement_time = 20'); } catch (Throwable $e) {}

    try {
      $result = db()->query($sqlToRun);
    } catch (mysqli_sql_exception $e) {
      fail('SQL error: ' . $e->getMessage(), 400);
    }

    if (!($result instanceof mysqli_result)) {
      respond(['ok' => true, 'columns' => [], 'rowCount' => 0, 'capped' => false, 'rows' => []]);
    }

    $columns = array_map(fn($f) => $f->name, $result->fetch_fields());
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $cap = 5000;
    $capped = count($rows) > $cap;
    if ($capped) {
      $rows = array_slice($rows, 0, $cap);
    }
    respond([
      'ok' => true,
      'columns' => $columns,
      'rowCount' => count($rows),
      'capped' => $capped,
      'rows' => $rows,
    ]);
  }

  case 'savedQueries': {
    requireAdmin();
    requireSavedQueriesTable();
    $res = db()->query('SELECT id, name, sql_text FROM db_viewer_queries ORDER BY name');
    $out = array_map(fn($r) => [
      'id' => (int)$r['id'],
      'name' => $r['name'],
      'sql' => $r['sql_text'],
    ], $res->fetch_all(MYSQLI_ASSOC));
    respond(['queries' => $out]);
  }

  case 'saveQuery': {
    requireAdmin();
    requireSavedQueriesTable();
    $name = trim((string)($body['name'] ?? ''));
    $sql = trim((string)($body['sql'] ?? ''));
    if ($name === '' || $sql === '') {
      fail('Name and SQL are both required', 400);
    }
    if (strlen($name) > 150) {
      fail('Name is too long (150 characters max)', 400);
    }
    $stmt = db()->prepare(
      'INSERT INTO db_viewer_queries (name, sql_text) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE sql_text = VALUES(sql_text)'
    );
    $stmt->bind_param('ss', $name, $sql);
    $stmt->execute();
    $stmt->close();
    respond(['ok' => true, 'name' => $name]);
  }

  case 'deleteQuery': {
    requireAdmin();
    requireSavedQueriesTable();
    $id = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    if ($id <= 0 && $name === '') {
      fail('Which saved query? Pass an id or a name', 400);
    }
    if ($id > 0) {
      $stmt = db()->prepare('DELETE FROM db_viewer_queries WHERE id = ?');
      $stmt->bind_param('i', $id);
    } else {
      $stmt = db()->prepare('DELETE FROM db_viewer_queries WHERE name = ?');
      $stmt->bind_param('s', $name);
    }
    $stmt->execute();
    $stmt->close();
    respond(['ok' => true]);
  }

  default:
    fail('Unknown action', 404);
}
