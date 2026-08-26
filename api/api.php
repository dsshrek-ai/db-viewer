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
  $stmt = db()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
  $stmt->bind_param('s', DB_NAME);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return array_map(fn($r) => $r['TABLE_NAME'], $rows);
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

  default:
    fail('Unknown action', 404);
}
