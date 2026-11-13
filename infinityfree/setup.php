<?php
declare(strict_types=1);

/*
 * One-time first-store/owner setup for shared hosting where remote MySQL is blocked.
 * Configure private config.php before opening this page. On an empty database, the
 * installer applies the bundled, access-denied database/schema.sql exactly once.
 * This endpoint becomes permanently unavailable after the first store is created.
 */
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) { http_response_code(503); exit('config.php の設定が必要です。'); }
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
$isLocalHost = (bool)preg_match('/^(localhost|127\\.0\\.0\\.1)(:\\d+)?$/i', $hostHeader);
if (!$isHttps && !$isLocalHost) { http_response_code(400); exit('安全のためHTTPS接続が必要です。'); }
session_name('girlsbar_pos_setup');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$isHttps,'httponly'=>true,'samesite'=>'Strict']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
function setupDb(): PDO {
    global $config;
    static $db = null;
    if ($db instanceof PDO) return $db;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
    $db = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $db;
}
function setupEnsureSchema(PDO $db): void {
    $tableCount = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchColumn();
    $required = [
        'stores','roles','users','store_settings','product_categories','products','set_menus',
        'casts','cast_pay_rules','tables','customers','table_sessions','session_casts',
        'session_set_items','session_discounts','orders','order_items','order_item_back_allocations',
        'payments','session_charge_snapshots','payment_refunds','attendance','payroll_details',
        'payroll_back_details','expenses','daily_closings','daily_closing_details','closing_errors','audit_logs'
    ];
    if ($tableCount === 0) {
        $schemaPath = __DIR__ . '/database/schema.sql';
        if (!is_file($schemaPath) || !is_readable($schemaPath)) {
            throw new RuntimeException('Bundled database schema is missing');
        }
        $sql = file_get_contents($schemaPath);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Bundled database schema is empty');
        }
        // The checked-in schema contains plain DDL/DML statements and no stored routines.
        // Execute one statement at a time; never run this against a non-empty database.
        $statements = preg_split('/;\\s*(?:\\r?\\n|$)/', $sql);
        if (!is_array($statements)) throw new RuntimeException('Could not parse database schema');
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $db->exec($statement);
        }
    }
    $found = [];
    $check = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = 'BASE TABLE'");
    foreach ($required as $table) {
        $check->execute([$table]);
        if ((int)$check->fetchColumn() > 0) $found[] = $table;
    }
    $missing = array_values(array_diff($required, $found));
    if ($missing) throw new RuntimeException('Database schema is incomplete: ' . implode(',', $missing));
    if ((int)$db->query("SELECT COUNT(*) FROM roles WHERE role_code = 'owner'")->fetchColumn() !== 1) {
        throw new RuntimeException('Owner role seed is missing');
    }
}
function setupH(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function setupPage(string $message = '', int $status = 200): never {
    http_response_code($status);
    $csrf = (string)($_SESSION['csrf'] ?? '');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>POS初回設定</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#f2f4f8;margin:0;padding:20px;color:#1f2937}.card{max-width:520px;margin:5vh auto;background:white;padding:24px;border-radius:14px}label{display:block;margin-top:12px;font-size:14px}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;margin-top:5px}button{width:100%;padding:13px;margin-top:18px;background:#26364c;color:white;border:0;border-radius:8px;font-weight:bold}.msg{padding:10px;background:#fff7ed;border-radius:8px}</style><body><main class="card"><h1>店舗・管理者の初回登録</h1>';
    if ($message !== '') echo '<p class="msg">' . setupH($message) . '</p>';
    echo '<p>この画面は最初の店舗を一度だけ登録します。登録後は自動的に使用できなくなります。</p>';
    echo '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . setupH($csrf) . '">';
    echo '<label>初回設定キー<input type="password" name="setup_token" required minlength="40" autocomplete="off"></label>';
    echo '<label>店舗コード（英数字・_・-）<input name="store_code" required minlength="3" maxlength="40" pattern="[A-Za-z0-9_-]{3,40}"></label>';
    echo '<label>店舗名<input name="store_name" required maxlength="120"></label>';
    echo '<label>管理者ログインID<input name="login_id" required minlength="3" maxlength="120" pattern="[A-Za-z0-9_.-]{3,120}" autocomplete="username"></label>';
    echo '<label>管理者表示名<input name="display_name" required maxlength="120"></label>';
    echo '<label>管理者パスワード（12文字以上）<input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password"></label>';
    echo '<button type="submit">店舗と管理者を登録</button></form></main></body></html>';
    exit;
}
try {
    $db = setupDb();
    setupEnsureSchema($db);
    if ((int)$db->query('SELECT COUNT(*) FROM stores')->fetchColumn() > 0) setupPage('初回登録は完了済みです。安全のため、この画面は利用できません。', 410);
    $token = (string)($config['setup_token'] ?? '');
    if (strlen($token) < 40 || str_contains($token, 'REPLACE_')) setupPage('config.phpに40文字以上のランダムなsetup_tokenを設定してください。', 503);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') setupPage();
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) setupPage('画面の有効期限が切れました。再読み込みしてください。', 419);
    if (!hash_equals($token, (string)($_POST['setup_token'] ?? ''))) setupPage('初回設定キーが正しくありません。', 403);
    $code = trim((string)($_POST['store_code'] ?? ''));
    $name = trim((string)($_POST['store_name'] ?? ''));
    $login = trim((string)($_POST['login_id'] ?? ''));
    $display = trim((string)($_POST['display_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $code) || $name === '' || strlen($name) > 480 ||
        !preg_match('/^[A-Za-z0-9_.-]{3,120}$/', $login) || $display === '' || strlen($display) > 480 ||
        strlen($password) < 12 || strlen($password) > 200) {
        setupPage('入力内容を確認してください。パスワードは12〜200文字です。', 422);
    }
    $db->beginTransaction();
    $lock = $db->query('SELECT id FROM roles WHERE role_code = "owner" LIMIT 1 FOR UPDATE')->fetchColumn();
    if (!$lock) throw new RuntimeException('Owner role is missing');
    if ((int)$db->query('SELECT COUNT(*) FROM stores')->fetchColumn() > 0) {
        $db->rollBack();
        setupPage('別の初回登録が先に完了しました。この画面は利用できません。', 410);
    }
    $q = $db->prepare('INSERT INTO stores (store_code,store_name,timezone,business_day_start) VALUES (?,?,?,?)');
    $q->execute([$code, $name, $config['timezone'] ?? 'Asia/Tokyo', '06:00:00']);
    $storeId = (int)$db->lastInsertId();
    $q = $db->prepare('INSERT INTO store_settings (store_id) VALUES (?)');
    $q->execute([$storeId]);
    $q = $db->prepare('INSERT INTO users (store_id,role_id,login_id,password_hash,display_name) VALUES (?,?,?,?,?)');
    $q->execute([$storeId, (int)$lock, $login, password_hash($password, PASSWORD_DEFAULT), $display]);
    $db->commit();
    unset($_SESSION['csrf']);
    setupPage('登録が完了しました。setup.phpは以後利用できません。index.phpからログインしてください。', 200);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('POS first setup failed: ' . $e->getMessage());
    setupPage('初回設定に失敗しました。DBスキーマ・設定値・重複した店舗コードを確認してください。詳細はサーバーログを確認してください。', 500);
}
