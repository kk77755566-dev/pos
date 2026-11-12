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
<meta name="robots" content="noindex,nofollow"><title>Girls Bar POS</title><link rel="stylesheet" href="pos.css?v=1">
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
<section class="panel"><h2><?=h($user['display_name'])?> さん / POS</h2><p class="muted">店舗ID <?=h($user['store_id'])?> ・ <?=h($user['role_code'])?></p><div id="tables" class="tables"></div><button id="refresh" class="secondary">最新状態に更新</button></section>
<section class="panel"><h2>着席・セット・注文</h2><div class="grid">
<div><label>卓ID（空席を選択）</label><input id="tableid" type="number" min="1"><label>人数</label><input id="people" type="number" min="1" value="1"><button id="seat">着席開始</button></div>
<div><label>営業セッションID</label><input id="session" type="number"><label>セット・延長</label><select id="setmenu"></select><button id="addset">セットを登録</button></div>
<div><label>注文セッションID</label><input id="ordersession" type="number"><label>商品</label><select id="product"></select><label>数量</label><input id="qty" type="number" min="0.001" step="0.001" value="1"></div>
<div><label>バック配分先（複数選択可）</label><select id="cast" multiple size="4"></select><p class="muted">選択したキャストへ均等配分します。配分合計は100%以内です。</p><button id="order">注文登録</button></div>
</div></section>
<section class="panel"><h2>会計・返金</h2><div class="grid">
<div><label>セッションID</label><input id="billsession" type="number"><button id="bill" class="secondary">会計金額を確認</button></div>
<div><label>決済方法</label><select id="method"><option value="cash">現金</option><option value="credit_card">クレジットカード</option><option value="qr">QR決済</option><option value="electronic_money">電子マネー</option><option value="transfer">振込</option><option value="other">その他</option></select><label>支払額</label><input id="payamount" type="number" min="0.01" step="0.01"><button id="pay">入金登録</button></div>
<div><label>決済ID</label><input id="paymentid" type="number"><label>返金額</label><input id="refundamount" type="number" min="0.01" step="0.01"><label>返金理由</label><input id="refundreason" maxlength="500"><button id="refund">返金登録</button></div>
</div></section>
<section class="panel"><h2>キャスト勤怠・給与</h2><div class="grid">
<div><label>出勤・退勤キャスト</label><select id="attcast"></select><button id="clockin">出勤登録</button><button id="clockout" class="secondary">退勤登録</button></div>
<div><label>給与対象日</label><input id="paydate" type="date"><label>給与確定キャスト</label><select id="paycast"></select><button id="confirmPayroll">給与計算・確定</button></div>
</div></section>
<section class="panel"><h2>日締め</h2><div class="row"><div style="flex:1"><label>営業日</label><input id="closedate" type="date"></div><button id="close">日締め実行</button></div><p class="muted">未精算卓・退勤漏れ・未確定給与・入金差異があれば締め処理を停止します。</p></section>
<section class="panel"><h2>店舗設定</h2><div class="grid">
<div><label><input id="taxenabled" type="checkbox"> 消費税を有効</label><label>税率（%）</label><input id="taxrate" type="number" min="0" max="100" step="0.01"><label>税方式</label><select id="taxmode"><option value="exclusive">外税</option><option value="inclusive">内税</option></select></div>
<div><label><input id="serviceenabled" type="checkbox"> サービス料を有効</label><label>方式</label><select id="servicemode"><option value="rate">率</option><option value="fixed">固定額</option></select><label>率（%）</label><input id="servicerate" type="number" min="0" max="100" step="0.01"><label>固定額</label><input id="servicefixed" type="number" min="0"><label>対象</label><select id="servicebasis"><option value="sets_only">セットのみ</option><option value="items_only">商品だけ</option><option value="all_subtotal">全小計</option></select><label><input id="servicetaxable" type="checkbox"> サービス料に課税</label></div>
<div><label>勤務時間の丸め（分）</label><select id="roundminutes"><option>1</option><option>5</option><option>10</option><option>15</option><option>30</option><option>60</option></select><label>丸め方法</label><select id="roundmode"><option value="floor">切り捨て</option><option value="half_up">四捨五入</option><option value="ceil">切り上げ</option></select><button id="savesettings">設定保存</button></div>
</div></section>
<section class="panel"><h2>マスター登録・変更</h2><div class="grid">
<div><h3>卓</h3><label>新規卓名</label><input id="newtable"><label>定員</label><input id="capacity" type="number" min="1" value="4"><button id="addtable">卓を追加</button><label>変更する卓</label><select id="edittable"></select><input id="edittablename"><button id="updatetable">卓名変更</button><button id="stopTable" class="secondary">卓を利用停止</button></div>
<div><h3>商品</h3><label>商品名</label><input id="newproduct"><label>販売価格</label><input id="newprice" type="number" min="0"><label>バック率・単価</label><input id="newback" type="number" min="0"><label>バック方式</label><select id="newbacktype"><option value="percentage">売上％</option><option value="fixed_per_unit">数量あたり固定額</option><option value="none">バックなし</option></select><button id="addproduct">商品を追加</button><label>変更する商品</label><select id="editproduct"></select><input id="editproductname" placeholder="商品名"><input id="editproductprice" type="number" placeholder="価格"><input id="editproductback" type="number" placeholder="バック値"><select id="editproducttype"><option value="percentage">売上％</option><option value="fixed_per_unit">数量あたり固定額</option><option value="none">なし</option></select><button id="updateproduct">商品を更新</button><button id="stopproduct" class="secondary">商品を利用停止</button></div>
<div><h3>セット・延長</h3><label>セット名</label><input id="newset"><label>時間（分）</label><input id="newduration" type="number" min="1" value="60"><label>料金</label><input id="newsetprice" type="number" min="0"><label><input id="extension" type="checkbox"> 延長メニュー</label><button id="addmasterset">セットを追加</button><label>変更するセット</label><select id="editset"></select><input id="editsetname"><input id="editsetduration" type="number" min="1"><input id="editsetprice" type="number" min="0"><label><input id="editextension" type="checkbox"> 延長メニュー</label><button id="updateset">セットを更新</button><button id="stopset" class="secondary">セットを利用停止</button></div>
<div><h3>キャスト</h3><label>コード</label><input id="newcastcode"><label>名前</label><input id="newcastname"><label>時給</label><input id="newwage" type="number" min="0"><button id="addcast">キャストを追加</button><label>変更するキャスト</label><select id="editcast"></select><input id="editcastcode"><input id="editcastname"><input id="editcastwage" type="number" min="0"><button id="updatecast">キャストを更新</button><button id="stopcast" class="secondary">キャストを利用停止</button></div>
</div><p class="muted">利用停止は過去の履歴を削除しません。過去の給与・売上の訂正は別途管理者操作として実装予定です。</p></section>
<div id="message" class="status">接続しています…</div>
<script src="pos.js?v=1" defer></script>
<?php endif; ?>
</div></section>
<section class="panel"><h2>現在の公開準備版</h2><p>ログインと店舗別の卓一覧を確認できます。注文・会計・勤怠・給与・日締めはPHP版への移植・検証後に有効化します。</p></section>
<?php endif; ?>
</main></body></html>
