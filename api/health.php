<?php
/**
 * Public health/diagnostics for the deployed site.
 * Returns STATUS ONLY — never credentials. Point your browser at /api/health.php
 * after deploying so we can see why the API can't reach MySQL.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$cfgFile = __DIR__ . '/config.local.php';
$hasCfg  = is_file($cfgFile);
$cfg     = $hasCfg ? require $cfgFile : [];

$dbHost   = (string)($cfg['db_host'] ?? 'localhost');
$dbName   = (string)($cfg['db_name'] ?? '');
$dbUser   = (string)($cfg['db_user'] ?? '');
$dbPassSet = $hasCfg && array_key_exists('db_pass', $cfg) && $cfg['db_pass'] !== '';

$pdoOk = false; $tableCount = 0; $tablesOk = false; $err = '';
if ($hasCfg && $dbName !== '' && $dbUser !== '') {
    try {
        $pdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            (string)($cfg['db_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $pdoOk = true;
        $tableCount = (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();
        $tablesOk = $tableCount >= 8;
    } catch (Throwable $e) {
        // Sanitize: never echo passwords; redact the username if MySQL echoed it.
        $err = (string)$e->getMessage();
        $err = preg_replace("/Access denied for user '[^']*'/", "Access denied for user '<redacted>'", $err);
    }
}

$installedPath = __DIR__ . '/install.php';
$hints = [];
if (!$hasCfg)             $hints[] = 'Create api/config.local.php on the server (see _DEPLOY-SETUP.txt / README).';
if ($hasCfg && !$pdoOk)   $hints[] = 'DB credentials wrong, DB not created, or db_host not reachable. Check hPanel > Databases > MySQL.';
if ($pdoOk && !$tablesOk) $hints[] = 'DB connects but tables are missing — visit /api/install.php?install_key=YOUR_KEY once.';
if ($pdoOk && $tablesOk)  $hints[] = 'All good — log in.';

http_response_code(200);
echo json_encode([
    'ok' => true,
    'diagnostics' => [
        'config_file_exists' => $hasCfg,
        'config_source'      => $hasCfg ? 'config.local.php' : 'config.example.php (defaults — no server config yet)',
        'db_host'            => $dbHost,
        'db_name'            => $dbName,
        'db_user'            => $dbUser,
        'db_password_set'    => $dbPassSet,
        'pdo_connected'      => $pdoOk,
        'table_count'        => $tableCount,
        'tables_installed'   => $tablesOk,
        'install_php_present'=> is_file($installedPath),
        'pdo_error'          => $err,
        'hints'              => $hints,
    ],
]);