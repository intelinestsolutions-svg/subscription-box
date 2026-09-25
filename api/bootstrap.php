<?php
/**
 * SBC Subscription Box — bootstrap.
 * Shared by every api/*.php endpoint: CORS, JSON helpers, DB, auth, config.
 */
declare(strict_types=1);

// ---- Config ----
$cfgFile = __DIR__ . '/config.local.php';
$config  = is_file($cfgFile) ? require $cfgFile : require __DIR__ . '/config.example.php';
$config += [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'demo_mode' => false, 'timezone' => 'UTC', 'auth_token_ttl' => 2592000,
    'cutoff_day' => 15, 'storage_dir' => __DIR__ . '/../storage',
];
date_default_timezone_set($config['timezone'] ?? 'UTC');

// ---- CORS (public API used from static pages; tokens via Authorization header) ----
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ---- DB ----
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed. Run api/install.php first.']);
    exit;
}

// ---- Storage dir (self-healing: labels + logs + web-denial .htaccess) ----
$storage = rtrim((string)$config['storage_dir'], '/');
foreach (['', '/labels', '/logs'] as $d) {
    if (!is_dir($storage . $d)) { @mkdir($storage . $d, 0775, true); }
}
if (!is_file($storage . '/.htaccess')) {
    @file_put_contents($storage . '/.htaccess', "Require all denied\n");
}

// ---- Helpers ----
function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}
function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function body(): array {
    $raw = file_get_contents('php://input');
    $j   = json_decode((string)$raw, true);
    return is_array($j) ? $j : ($_POST ?: []);
}
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

/** Resolve the bearer token from header, query string or JSON body. */
function bearer_token(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) return $m[1];
    return (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
}

/** Require an authenticated user; optionally one of $roles. Returns user row. */
function require_auth(array $roles = []): array {
    $token = bearer_token();
    if ($token === '') json_err('Authentication required.', 401);
    // Token may include an internal user hint for service->service calls: token:userId
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM users WHERE token = ? AND (token_expires IS NULL OR token_expires > NOW()) LIMIT 1');
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) json_err('Invalid or expired session.', 401);
    if ($roles && !in_array($user['role'], $roles, true)) {
        json_err('Forbidden: requires role ' . implode('/', $roles) . '.', 403);
    }
    return $user;
}

/** Normalize date parts of a subscription for display. */
function sub_cycle_label(array $sub): string {
    return $sub['current_period_end'];
}

/** Public-safe user array. */
function public_user(array $u): array {
    unset($u['password_hash'], $u['token'], $u['token_expires']);
    return $u;
}