<?php
declare(strict_types=1);

/* Same-origin JSON API for InfinityFree PHP hosting. All queries are store-scoped. */
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) { http_response_code(503); exit; }
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('girlsbar_pos');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$isHttps,'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never {
    http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
function pdo(): PDO {
    global $config; static $db = null;
    if ($db instanceof PDO) return $db;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['db_host'],$config['db_name']);
    $db = new PDO($dsn,$config['db_user'],$config['db_password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    return $db;
}
function input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}',true);
    if (!is_array($data)) respond(400,['error'=>'JSON形式が正しくありません']);
    return $data;
}
function user(): array {
    $u = $_SESSION['user'] ?? null;
    if (!$u) respond(401,['error'=>'ログインしてください']);
    return $u;
}
function csrf(): void {
    $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($given) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],$given))
        respond(419,['error'=>'CSRFトークンが無効です。画面を再読み込みしてください']);
}
function staff(array $u): void {
    if (!in_array($u['role_code'],['owner','manager','staff'],true)) respond(403,['error'=>'権限がありません']);
}
function businessDate(PDO $db,int $storeId): string {
    $q=$db->prepare('SELECT timezone,business_day_start FROM stores WHERE id=? AND is_active=1');
    $q->execute([$storeId]); $s=$q->fetch();
    if (!$s) respond(404,['error'=>'店舗が見つかりません']);
    $tz=new DateTimeZone($s['timezone']); $now=new DateTimeImmutable('now',$tz);
    if ($now->format('H:i:s') < $s['business_day_start']) $now=$now->modify('-1 day');
    return $now->format('Y-m-d');
}
function idempotency(array $data): string {
    $key=(string)($data['idempotency_key']??'');
    if (strlen($key)<8 || strlen($key)>100) respond(422,['error'=>'idempotency_keyは8〜100文字で指定してください']);
    return $key;
}

$method=$_SERVER['REQUEST_METHOD']??'GET';
$route=trim((string)($_GET['r']??''),'/');
try {
    if ($method==='GET' && $route==='csrf') respond(200,['csrf'=>$_SESSION['csrf']??=bin2hex(random_bytes(32))]);
    $u=user(); $store=(int)$u['store_id'];
    require __DIR__ . '/api_workflows.php';
    if ($method==='GET') {
        staff($u);
        $queries=[
            'tables'=>["SELECT t.id,t.table_name,t.capacity,t.status,s.id session_id,s.customer_count,s.started_at FROM `tables` t LEFT JOIN table_sessions s ON s.store_id=t.store_id AND s.table_id=t.id AND s.status IN ('open','checkout') WHERE t.store_id=? AND t.is_active=1 ORDER BY t.sort_order,t.id",[]],
            'products'=>["SELECT id,category_id,product_name,selling_price,back_rate,back_type FROM products WHERE store_id=? AND is_active=1 ORDER BY id",[]],
            'sets'=>["SELECT id,name,duration_minutes,price,is_extension FROM set_menus WHERE store_id=? AND is_active=1 ORDER BY sort_order,id",[]],
            'casts'=>["SELECT id,cast_code,cast_name,hourly_wage FROM casts WHERE store_id=? AND is_active=1 ORDER BY cast_name",[]],
        ];
        if (!isset($queries[$route])) respond(404,['error'=>'APIルートがありません']);
        $q=pdo()->prepare($queries[$route][0]); $q->execute([$store]); respond(200,['data'=>$q->fetchAll()]);
    }
    if (!in_array($method,['POST','PUT'],true)) respond(405,['error'=>'Method not allowed']);
    csrf(); staff($u); $data=input(); $db=pdo();

    if ($route==='sessions') {
        $table=(int)($data['table_id']??0); $count=(int)($data['customer_count']??1); $key=idempotency($data);
        if ($table<1 || $count<1 || $count>100) respond(422,['error'=>'卓または人数が不正です']);
        $db->beginTransaction();
        $old=$db->prepare('SELECT id,table_id,status FROM table_sessions WHERE store_id=? AND idempotency_key=?');
        $old->execute([$store,$key]); if ($row=$old->fetch()) {$db->commit();respond(200,['data'=>$row,'replayed'=>true]);}
        $q=$db->prepare("SELECT id,status FROM `tables` WHERE store_id=? AND id=? AND is_active=1 FOR UPDATE");
        $q->execute([$store,$table]); $t=$q->fetch();
        if (!$t || $t['status']!=='available') { $db->rollBack(); respond(409,['error'=>'卓が利用できません']); }
        $q=$db->prepare("SELECT id FROM table_sessions WHERE store_id=? AND table_id=? AND status IN ('open','checkout') LIMIT 1 FOR UPDATE");
        $q->execute([$store,$table]); if ($q->fetch()) {$db->rollBack();respond(409,['error'=>'この卓は既に利用中です']);}
        $q=$db->prepare("INSERT INTO table_sessions(store_id,table_id,business_date,customer_count,started_at,status,created_by_user_id,idempotency_key) VALUES(?,?,?,?,UTC_TIMESTAMP(3),'open',?,?)");
        $q->execute([$store,$table,businessDate($db,$store),$count,(int)$u['id'],$key]); $sid=(int)$db->lastInsertId();
        $db->prepare("UPDATE `tables` SET status='occupied' WHERE store_id=? AND id=?")->execute([$store,$table]);
        $db->commit(); respond(201,['data'=>['session_id'=>$sid,'table_id'=>$table,'status'=>'open'],'replayed'=>false]);
    }

    if (preg_match('#^sessions/(\d+)/sets$#',$route,$m)) {
        $sid=(int)$m[1]; $set=(int)($data['set_menu_id']??0); $key=idempotency($data);
        $db->beginTransaction();
        $old=$db->prepare('SELECT id,session_id,set_menu_id FROM session_set_items WHERE store_id=? AND idempotency_key=?');$old->execute([$store,$key]);if($existing=$old->fetch()){$db->commit();respond(200,['data'=>['session_set_item_id'=>(int)$existing['id'],'session_id'=>(int)$existing['session_id'],'set_menu_id'=>(int)$existing['set_menu_id']],'replayed'=>true]);}
        $q=$db->prepare("SELECT id,status FROM table_sessions WHERE store_id=? AND id=? FOR UPDATE");
        $q->execute([$store,$sid]); if (($s=$q->fetch())===false || $s['status']!=='open') {$db->rollBack();respond(409,['error'=>'営業中の卓ではありません']);}
        $q=$db->prepare('SELECT id,name,duration_minutes,price FROM set_menus WHERE store_id=? AND id=? AND is_active=1');
        $q->execute([$store,$set]); if (!$menu=$q->fetch()) {$db->rollBack();respond(404,['error'=>'セットが見つかりません']);}
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC')); $start=$now->format('Y-m-d H:i:s.v'); $end=$now->modify('+'.(int)$menu['duration_minutes'].' minutes')->format('Y-m-d H:i:s.v');
        $q=$db->prepare("INSERT INTO session_set_items(store_id,session_id,set_menu_id,name_snapshot,duration_minutes_snapshot,price_snapshot,started_at,ends_at,idempotency_key,status) VALUES(?,?,?,?,?,?,?,?,?,'active')");
        $q->execute([$store,$sid,$set,$menu['name'],$menu['duration_minutes'],$menu['price'],$start,$end,$key]);
        $db->commit(); respond(201,['data'=>['session_set_item_id'=>(int)$db->lastInsertId(),'name'=>$menu['name'],'price'=>$menu['price'],'ends_at'=>$end]]);
    }

    if ($route==='orders') {
        $sid=(int)($data['session_id']??0); $key=idempotency($data); $items=$data['items']??[];
        if ($sid<1 || !is_array($items) || count($items)<1 || count($items)>100) respond(422,['error'=>'注文内容が不正です']);
        $db->beginTransaction();
        $q=$db->prepare('SELECT id,order_number,status FROM orders WHERE store_id=? AND idempotency_key=?');
        $q->execute([$store,$key]); if ($old=$q->fetch()) {$db->commit();respond(200,['data'=>$old,'replayed'=>true]);}
        $q=$db->prepare("SELECT id FROM table_sessions WHERE store_id=? AND id=? AND status='open' FOR UPDATE");
        $q->execute([$store,$sid]); if (!$q->fetch()) {$db->rollBack();respond(409,['error'=>'営業中の卓ではありません']);}
        $number= strtoupper(bin2hex(random_bytes(10)));
        $q=$db->prepare("INSERT INTO orders(store_id,session_id,order_number,entered_by_user_id,status,ordered_at,idempotency_key) VALUES(?,?,?,?,'confirmed',UTC_TIMESTAMP(3),?)");
        $q->execute([$store,$sid,$number,(int)$u['id'],$key]); $oid=(int)$db->lastInsertId(); $created=[];
        foreach ($items as $item) {
            $pid=(int)($item['product_id']??0); $qty=(float)($item['quantity']??0); $alloc=$item['allocations']??[];
            if ($pid<1 || $qty<=0 || $qty>9999 || !is_array($alloc)) {$db->rollBack();respond(422,['error'=>'商品明細が不正です']);}
            $q=$db->prepare('SELECT id,product_name,selling_price,back_rate,back_type FROM products WHERE store_id=? AND id=? AND is_active=1 FOR UPDATE');
            $q->execute([$store,$pid]); if (!$p=$q->fetch()) {$db->rollBack();respond(404,['error'=>'商品が見つかりません']);}
            $gross=(float)$p['selling_price']*$qty; $back= $p['back_type']==='percentage' ? round($gross*(float)$p['back_rate']/100,2,PHP_ROUND_HALF_UP) : ($p['back_type']==='fixed_per_unit' ? round((float)$p['back_rate']*$qty,2,PHP_ROUND_HALF_UP):0);
            $rateSum=0.0; foreach($alloc as $a){$rateSum+=(float)($a['allocation_rate']??0);}
            if ($rateSum>100.000001) {$db->rollBack();respond(422,['error'=>'バック配分率は合計100%以下です']);}
            $q=$db->prepare("INSERT INTO order_items(store_id,order_id,product_id,product_name_snapshot,quantity,unit_price_snapshot,back_rate_snapshot,back_type_snapshot,back_amount_total,status) VALUES(?,?,?,?,?,?,?,?,?,'active')");
            $q->execute([$store,$oid,$pid,$p['product_name'],$qty,$p['selling_price'],$p['back_rate'],$p['back_type'],$back]); $itemId=(int)$db->lastInsertId();
            $remaining=$back; $done=0; $n=count($alloc);
            foreach($alloc as $a){
                $cid=(int)($a['cast_id']??0); $rate=(float)($a['allocation_rate']??0);
                if ($cid<1 || $rate<0 || $rate>100) {$db->rollBack();respond(422,['error'=>'バック付与先が不正です']);}
                $q=$db->prepare('SELECT id FROM casts WHERE store_id=? AND id=? AND is_active=1');
                $q->execute([$store,$cid]); if (!$q->fetch()) {$db->rollBack();respond(404,['error'=>'キャストが見つかりません']);}
                $amount=($done===$n-1 && abs($rateSum-100)<0.000001)?$remaining:min($remaining,round($back*$rate/100,2,PHP_ROUND_HALF_UP));
                $remaining=round($remaining-$amount,2);
                $q=$db->prepare("INSERT INTO order_item_back_allocations(store_id,order_item_id,cast_id,allocation_rate,back_amount,status) VALUES(?,?,?,?,?,'active')");
                $q->execute([$store,$itemId,$cid,$rate,$amount]); $done++;
            }
            $created[]=['order_item_id'=>$itemId,'product_name'=>$p['product_name'],'quantity'=>$qty,'back_total'=>$back];
        }
        $db->commit(); respond(201,['data'=>['order_id'=>$oid,'order_number'=>$number,'items'=>$created],'replayed'=>false]);
    }

    respond(404,['error'=>'APIルートがありません']);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('POS API error: '.$e->getMessage());
    respond(500,['error'=>'処理に失敗しました。入力とDB設定を確認してください']);
}
