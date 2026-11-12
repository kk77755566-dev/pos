<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><h1>初期設定が必要です</h1><p>config.example.php を config.php にコピーし、DB接続情報を設定してください。</p>';
    exit;
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('girlsbar_pos');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $isHttps,
    'httponly' => true, 'samesite' => 'Lax'
]);
session_start();

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $given = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals(csrf_token(), $given)) {
        http_response_code(419); exit('セッションの有効期限が切れました。画面を再読み込みしてください。');
    }
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
    $user = current_user();
    if (!$user) { header('Location: index.php'); exit; }
    return $user;
}
function flash(string $message): void { $_SESSION['flash'] = $message; }
function redirect_home(): never { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        try {
            $stmt = db()->prepare(
                "SELECT u.id,u.store_id,u.login_id,u.password_hash,u.display_name,r.role_code
                   FROM users u JOIN roles r ON r.id=u.role_id
                   JOIN stores s ON s.id=u.store_id
                  WHERE u.login_id=? AND s.store_code=? AND u.is_active=1 AND s.is_active=1 LIMIT 1"
            );
            $stmt->execute([trim((string)($_POST['login_id'] ?? '')), trim((string)($_POST['store_code'] ?? ''))]);
            $user = $stmt->fetch();
            if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                session_regenerate_id(true);
                unset($user['password_hash']);
                $_SESSION['user'] = $user;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $upd = db()->prepare('UPDATE users SET last_login_at=UTC_TIMESTAMP(3) WHERE id=?');
                $upd->execute([$user['id']]);
                redirect_home();
            }
            $error = 'ログインIDまたはパスワードが違います。';
        } catch (Throwable $e) {
            error_log('POS login failure: ' . $e->getMessage());
            $error = 'ログイン処理に失敗しました。DB設定と初期データを確認してください。';
        }
    } elseif ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], '', $p['secure'], $p['httponly']);
        }
        session_destroy();
        redirect_home();
    }
}

$user = current_user();
$tables = [];
if ($user) {
    try {
        $stmt = db()->prepare(
            "SELECT t.id,t.table_name,t.status,s.id AS session_id,s.customer_count,s.started_at
               FROM `tables` t
               LEFT JOIN table_sessions s ON s.store_id=t.store_id AND s.table_id=t.id
                    AND s.status IN ('open','checkout')
              WHERE t.store_id=? AND t.is_active=1 ORDER BY t.sort_order,t.id"
        );
        $stmt->execute([$user['store_id']]);
        $tables = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('POS dashboard failure: ' . $e->getMessage());
        $error = 'DBの読み込みに失敗しました。スキーマ適用状況を確認してください。';
    }
}
$notice = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Girls Bar POS</title>
<style>
:root{font-family:system-ui,-apple-system,"Noto Sans JP",sans-serif;color:#20242b;background:#f3f5f8}
*{box-sizing:border-box}body{margin:0}.wrap{max-width:1100px;margin:auto;padding:18px}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
h1{font-size:22px;margin:0}h2{font-size:18px}.panel{background:#fff;border:1px solid #e1e5eb;border-radius:14px;padding:18px;margin:14px 0}
input,button{font:inherit;padding:12px;border:1px solid #cbd2dc;border-radius:9px;width:100%;margin:5px 0}
button{background:#202b3c;color:#fff;border:0;font-weight:650;cursor:pointer}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:10px}
.table{min-height:90px;white-space:pre-line}.available{background:#e7f5ec;color:#14532d}.occupied{background:#fff1d9;color:#854d0e}.checkout{background:#fee2e2;color:#991b1b}
.muted{color:#64748b;font-size:13px}.error{color:#b91c1c}.notice{color:#166534}
@media(max-width:600px){.wrap{padding:10px}header{align-items:flex-start}}
</style></head><body><main class="wrap">
<header><h1>Girls Bar POS</h1><?php if ($user): ?><form method="post"><input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="logout"><button>ログアウト</button></form><?php endif; ?></header>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<?php if ($notice): ?><p class="notice"><?=h($notice)?></p><?php endif; ?>
<?php if (!$user): ?>
<section class="panel" style="max-width:440px;margin:8vh auto">
<h2>店舗ログイン</h2><form method="post" autocomplete="on">
<input type="hidden" name="_csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="login">
<label>店舗コード</label><input name="store_code" required autocomplete="organization" placeholder="店舗管理者から指定されたコード"><label>ログインID</label><input name="login_id" required autocomplete="username">
<label>パスワード</label><input type="password" name="password" required autocomplete="current-password">
<button type="submit">ログイン</button></form><p class="muted">店舗管理者から発行されたアカウントでログインしてください。</p>
</section>
<?php else: ?>
<section class="panel"><h2><?=h($user['display_name'])?> さん</h2><p class="muted">店舗ID: <?=h($user['store_id'])?> / 権限: <?=h($user['role_code'])?></p>
<h2>卓一覧</h2><div class="grid">
<?php foreach ($tables as $t): ?><button class="table <?=h($t['status'])?>" type="button"><?=h($t['table_name'])?>&#10;<?=h($t['status'])?><?= $t['session_id'] ? '&#10;Session #'.h($t['session_id']) : '' ?></button><?php endforeach; ?>
<?php if (!$tables): ?><p class="muted">卓が登録されていません。初期マスター登録が必要です。</p><?php endif; ?>
</div></section>
<section class="panel"><h2>現在の公開準備版</h2><p>ログインと店舗別の卓一覧を確認できます。注文・会計・勤怠・給与・日締めはPHP版への移植・検証後に有効化します。</p></section>
<?php endif; ?>
</main></body></html>
