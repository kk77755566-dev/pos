<?php
declare(strict_types=1);

/*
 * Run locally with PHP CLI after configuring config.php:
 *   php bootstrap_owner.php
 * Do not upload this script to htdocs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "config.php not found. Copy config.example.php and fill DB credentials.\n");
    exit(1);
}
$config = require $configFile;
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$ask = static function(string $label): string {
    fwrite(STDOUT, $label . ': ');
    return trim((string)fgets(STDIN));
};
$storeCode = $ask('Store code (unique)');
$storeName = $ask('Store name');
$loginId = $ask('Owner login ID');
$displayName = $ask('Owner display name');
fwrite(STDOUT, 'Owner password (12+ characters): ');
$password = trim((string)fgets(STDIN));
if ($storeCode === '' || $storeName === '' || $loginId === '' || $displayName === '' || strlen($password) < 12) {
    fwrite(STDERR, "Invalid input. Password must be at least 12 characters.\n");
    exit(1);
}
$pdo->beginTransaction();
try {
    $q = $pdo->prepare("INSERT INTO stores (store_code,store_name,timezone,business_day_start) VALUES (?,?,?,?)");
    $q->execute([$storeCode,$storeName,$config['timezone'] ?? 'Asia/Tokyo','06:00:00']);
    $storeId = (int)$pdo->lastInsertId();
    $role = $pdo->prepare("SELECT id FROM roles WHERE role_code='owner' LIMIT 1");
    $role->execute();
    $roleId = $role->fetchColumn();
    if (!$roleId) throw new RuntimeException('Owner role missing. Import schema.sql first.');
    $q = $pdo->prepare("INSERT INTO store_settings (store_id) VALUES (?)");
    $q->execute([$storeId]);
    $q = $pdo->prepare("INSERT INTO users (store_id,role_id,login_id,password_hash,display_name) VALUES (?,?,?,?,?)");
    $q->execute([$storeId,$roleId,$loginId,password_hash($password,PASSWORD_DEFAULT),$displayName]);
    $pdo->commit();
    fwrite(STDOUT, "Created store and owner. Store code: {$storeCode}; owner login: {$loginId}\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Bootstrap failed: " . $e->getMessage() . "\n");
    exit(1);
}
