<?php
declare(strict_types=1);
/* Additional same-origin workflows. Included by api.php after authentication. */
$wfdb = pdo();
function posRoundTotal(float $amount, string $mode): float {
    return match ($mode) {
        'floor' => (float)floor($amount),
        'ceil' => (float)ceil($amount),
        default => (float)round($amount, 0, PHP_ROUND_HALF_UP),
    };
}

function posCalculateSessionBill(PDO $db, int $store, int $sid, array $cfg): array {
    $q=$db->prepare("SELECT COALESCE(SUM(oi.quantity*oi.unit_price_snapshot),0) FROM orders o JOIN order_items oi ON oi.store_id=o.store_id AND oi.order_id=o.id WHERE o.store_id=? AND o.session_id=? AND o.status='confirmed' AND oi.status='active'");
    $q->execute([$store,$sid]); $items=(float)$q->fetchColumn();
    $q=$db->prepare("SELECT COALESCE(SUM(price_snapshot),0) FROM session_set_items WHERE store_id=? AND session_id=? AND status<>'cancelled'");
    $q->execute([$store,$sid]); $sets=(float)$q->fetchColumn();
    $subtotal=$items+$sets;
    $basis=($cfg['service_basis']??'all_subtotal')==='sets_only'?$sets:(($cfg['service_basis']??'')==='items_only'?$items:$subtotal);
    $service=empty($cfg['service_enabled'])?0:(($cfg['service_mode']??'rate')==='fixed'?(float)($cfg['service_fixed_amount']??0):round($basis*(float)($cfg['service_rate']??0)/100,2));
    $taxBase=$subtotal+(!empty($cfg['service_taxable'])?$service:0);
    $tax=empty($cfg['tax_enabled'])?0:round((($cfg['tax_mode']??'exclusive')==='inclusive'?$taxBase-$taxBase/(1+(float)($cfg['tax_rate']??0)/100):$taxBase*(float)($cfg['tax_rate']??0)/100),2);
    $total=posRoundTotal($subtotal+$service+((($cfg['tax_mode']??'exclusive')==='inclusive')?0:$tax),(string)($cfg['rounding_mode']??'floor'));
    return ['items_subtotal'=>$items,'sets_subtotal'=>$sets,'subtotal'=>$subtotal,'service'=>$service,'tax'=>$tax,'total'=>$total];
}
function posGetSessionBill(PDO $db, int $store, int $sid): array {
    $q=$db->prepare('SELECT items_subtotal,sets_subtotal,subtotal,service_amount service,tax_amount tax,total_amount total,settings_snapshot FROM session_charge_snapshots WHERE store_id=? AND session_id=?');
    $q->execute([$store,$sid]); $saved=$q->fetch();
    if($saved){$saved['snapshot_locked']=true;return $saved;}
    $q=$db->prepare('SELECT * FROM store_settings WHERE store_id=?');$q->execute([$store]);$cfg=$q->fetch()?:[];
    $bill=posCalculateSessionBill($db,$store,$sid,$cfg);$bill['snapshot_locked']=false;return $bill;
}

/* Store-scoped master-data maintenance. Soft-disable preserves historical snapshots. */
if ($method === 'GET' && $route === 'expenses') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $date=(string)($_GET['business_date']??businessDate(pdo(),$store));
    $q=pdo()->prepare('SELECT id,business_date,category,amount,description FROM expenses WHERE store_id=? AND business_date=? ORDER BY id DESC');$q->execute([$store,$date]);respond(200,['data'=>$q->fetchAll()]);
}
if ($method === 'GET' && $route === 'closings') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $date=(string)($_GET['business_date']??businessDate(pdo(),$store));
    $q=pdo()->prepare('SELECT * FROM daily_closings WHERE store_id=? AND business_date=?');$q->execute([$store,$date]);$c=$q->fetch();
    if($c){$q=pdo()->prepare('SELECT error_code,description,resolved_at FROM closing_errors WHERE store_id=? AND daily_closing_id=? ORDER BY id');$q->execute([$store,$c['id']]);$c['errors']=$q->fetchAll();}
    respond(200,['data'=>$c]);
}
if ($method === 'GET' && $route === 'settings') {
    $q=$wfdb->prepare('SELECT tax_enabled,tax_rate,tax_mode,service_enabled,service_rate,service_mode,service_fixed_amount,service_taxable,service_basis,rounding_mode,work_rounding_minutes,work_rounding_mode FROM store_settings WHERE store_id=?');
    $q->execute([$store]); respond(200,['data'=>$q->fetch() ?: []]);
}
if ($method === 'GET' && preg_match('#^sessions/([0-9]+)/bill$#',$route,$m)) {
    $sid=(int)$m[1];
    $q=$wfdb->prepare('SELECT id,status FROM table_sessions WHERE store_id=? AND id=?'); $q->execute([$store,$sid]); $session=$q->fetch();
    if(!$session) respond(404,['error'=>'卓が見つかりません']);
    $bill=posGetSessionBill($wfdb,$store,$sid);
    $q=$wfdb->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE store_id=? AND session_id=? AND status='completed'");$q->execute([$store,$sid]);$paid=(float)$q->fetchColumn();
    respond(200,['session_id'=>$sid,'status'=>$session['status']]+$bill+['paid'=>$paid,'balance'=>max(0,round((float)$bill['total']-$paid,2))]);
}
if ($method === 'GET' && $route === 'attendance') {
    staff($u);$date=(string)($_GET['business_date']??businessDate($wfdb,$store));
    $q=$wfdb->prepare('SELECT a.id,a.cast_id,c.cast_name,a.business_date,a.clock_in_at,a.clock_out_at,a.break_minutes,a.status FROM attendance a JOIN casts c ON c.store_id=a.store_id AND c.id=a.cast_id WHERE a.store_id=? AND a.business_date=? ORDER BY c.cast_name,a.clock_in_at');
    $q->execute([$store,$date]);respond(200,['data'=>$q->fetchAll()]);
}
if ($method === 'GET' && $route === 'payroll') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $date=(string)($_GET['business_date']??businessDate($wfdb,$store));
    $q=$wfdb->prepare('SELECT p.*,c.cast_name FROM payroll_details p JOIN casts c ON c.store_id=p.store_id AND c.id=p.cast_id WHERE p.store_id=? AND p.business_date=? ORDER BY c.cast_name');
    $q->execute([$store,$date]);respond(200,['data'=>$q->fetchAll()]);
}
if (!in_array($method,['POST','PUT'],true)) return;
$workflowWrite = in_array($route,['settings','tables','products','sets','casts','payments','expenses','attendance/clock-in','attendance/clock-out','attendance/break','closings/run'],true)
    || preg_match('#^(tables|products|sets|casts)/[0-9]+$#',$route)
    || preg_match('#^payments/[0-9]+/refund$#',$route)
    || preg_match('#^casts/[0-9]+/payroll/confirm$#',$route);
if (!$workflowWrite) return;
csrf(); staff($u); $d=input();

/* Master data CRUD: create and update only; delete is always a soft-disable. */
if ($route==='tables' && $method==='POST') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $name=trim((string)($d['table_name']??''));$capacity=(int)($d['capacity']??4);
    if($name===''||mb_strlen($name)>40||$capacity<1||$capacity>100)respond(422,['error'=>'卓情報が不正です']);
    $q=$wfdb->prepare("INSERT INTO tables(store_id,table_name,capacity,status,is_active) VALUES(?,?,?,'available',1)");$q->execute([$store,$name,$capacity]);respond(201,['id'=>(int)$wfdb->lastInsertId()]);
}
if (preg_match('#^tables/([0-9]+)$#',$route,$m) && $method==='PUT') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $id=(int)$m[1];$name=trim((string)($d['table_name']??''));$capacity=(int)($d['capacity']??4);$active=(int)!!($d['is_active']??true);
    if($name===''||mb_strlen($name)>40||$capacity<1||$capacity>100)respond(422,['error'=>'卓情報が不正です']);
    $q=$wfdb->prepare("SELECT COUNT(*) FROM table_sessions WHERE store_id=? AND table_id=? AND status IN ('open','checkout')");$q->execute([$store,$id]);if(!$active&&(int)$q->fetchColumn()>0)respond(409,['error'=>'利用中の卓は停止できません']);
    $q=$wfdb->prepare('UPDATE tables SET table_name=?,capacity=?,is_active=? WHERE store_id=? AND id=?');$q->execute([$name,$capacity,$active,$store,$id]);if(!$q->rowCount()){$q=$wfdb->prepare('SELECT id FROM tables WHERE store_id=? AND id=?');$q->execute([$store,$id]);if(!$q->fetch())respond(404,['error'=>'卓が見つかりません']);}respond(200,['ok'=>true]);
}
if ($route==='products' && $method==='POST') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $name=trim((string)($d['product_name']??''));$price=(float)($d['selling_price']??-1);$rate=(float)($d['back_rate']??0);$type=(string)($d['back_type']??'percentage');
    if($name===''||mb_strlen($name)>120||$price<0||$rate<0||!in_array($type,['percentage','fixed_per_unit','none'],true)||($type==='percentage'&&$rate>100))respond(422,['error'=>'商品情報が不正です']);
    $q=$wfdb->prepare('INSERT INTO products(store_id,product_name,selling_price,back_rate,back_type,is_active) VALUES(?,?,?,?,?,1)');$q->execute([$store,$name,$price,$rate,$type]);respond(201,['id'=>(int)$wfdb->lastInsertId()]);
}
if (preg_match('#^products/([0-9]+)$#',$route,$m) && $method==='PUT') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $id=(int)$m[1];$name=trim((string)($d['product_name']??''));$price=(float)($d['selling_price']??-1);$rate=(float)($d['back_rate']??0);$type=(string)($d['back_type']??'percentage');$active=(int)!!($d['is_active']??true);
    if($name===''||mb_strlen($name)>120||$price<0||$rate<0||!in_array($type,['percentage','fixed_per_unit','none'],true)||($type==='percentage'&&$rate>100))respond(422,['error'=>'商品情報が不正です']);
    $q=$wfdb->prepare('UPDATE products SET product_name=?,selling_price=?,back_rate=?,back_type=?,is_active=? WHERE store_id=? AND id=?');$q->execute([$name,$price,$rate,$type,$active,$store,$id]);respond(200,['ok'=>true]);
}
if ($route==='sets' && $method==='POST') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $name=trim((string)($d['name']??''));$minutes=(int)($d['duration_minutes']??0);$price=(float)($d['price']??-1);$extension=(int)!!($d['is_extension']??false);
    if($name===''||mb_strlen($name)>120||$minutes<1||$minutes>1440||$price<0)respond(422,['error'=>'セット情報が不正です']);
    $q=$wfdb->prepare('INSERT INTO set_menus(store_id,name,duration_minutes,price,is_extension,is_active) VALUES(?,?,?,?,?,1)');$q->execute([$store,$name,$minutes,$price,$extension]);respond(201,['id'=>(int)$wfdb->lastInsertId()]);
}
if (preg_match('#^sets/([0-9]+)$#',$route,$m) && $method==='PUT') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $id=(int)$m[1];$name=trim((string)($d['name']??''));$minutes=(int)($d['duration_minutes']??0);$price=(float)($d['price']??-1);$extension=(int)!!($d['is_extension']??false);$active=(int)!!($d['is_active']??true);
    if($name===''||mb_strlen($name)>120||$minutes<1||$minutes>1440||$price<0)respond(422,['error'=>'セット情報が不正です']);
    $q=$wfdb->prepare('UPDATE set_menus SET name=?,duration_minutes=?,price=?,is_extension=?,is_active=? WHERE store_id=? AND id=?');$q->execute([$name,$minutes,$price,$extension,$active,$store,$id]);respond(200,['ok'=>true]);
}
if ($route==='casts' && $method==='POST') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $code=trim((string)($d['cast_code']??''));$name=trim((string)($d['cast_name']??''));$wage=(float)($d['hourly_wage']??-1);
    if($code===''||mb_strlen($code)>40||$name===''||mb_strlen($name)>120||$wage<0)respond(422,['error'=>'キャスト情報が不正です']);
    $wfdb->beginTransaction();$q=$wfdb->prepare('INSERT INTO casts(store_id,cast_code,cast_name,hourly_wage,is_active) VALUES(?,?,?,?,1)');$q->execute([$store,$code,$name,$wage]);$castId=(int)$wfdb->lastInsertId();$effective=businessDate($wfdb,$store);$wfdb->prepare('INSERT INTO cast_pay_rules(store_id,cast_id,hourly_wage,valid_from) VALUES(?,?,?,?)')->execute([$store,$castId,$wage,$effective]);$wfdb->commit();respond(201,['id'=>$castId]);
}
if (preg_match('#^casts/([0-9]+)$#',$route,$m) && $method==='PUT') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $id=(int)$m[1];$code=trim((string)($d['cast_code']??''));$name=trim((string)($d['cast_name']??''));$wage=(float)($d['hourly_wage']??-1);$active=(int)!!($d['is_active']??true);
    if($code===''||mb_strlen($code)>40||$name===''||mb_strlen($name)>120||$wage<0)respond(422,['error'=>'キャスト情報が不正です']);
    $wfdb->beginTransaction();$q=$wfdb->prepare('SELECT hourly_wage FROM casts WHERE store_id=? AND id=? FOR UPDATE');$q->execute([$store,$id]);$existing=$q->fetch();if(!$existing){$wfdb->rollBack();respond(404,['error'=>'キャストが見つかりません']);}$oldWage=(float)$existing['hourly_wage'];$wfdb->prepare('UPDATE casts SET cast_code=?,cast_name=?,hourly_wage=?,is_active=? WHERE store_id=? AND id=?')->execute([$code,$name,$wage,$active,$store,$id]);if(abs($oldWage-$wage)>0.0001){$effective=businessDate($wfdb,$store);$wfdb->prepare('UPDATE cast_pay_rules SET valid_to=DATE_SUB(?,INTERVAL 1 DAY) WHERE store_id=? AND cast_id=? AND valid_to IS NULL AND valid_from<?')->execute([$effective,$store,$id,$effective]);$wfdb->prepare('INSERT INTO cast_pay_rules(store_id,cast_id,hourly_wage,valid_from) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE hourly_wage=VALUES(hourly_wage),valid_to=NULL')->execute([$store,$id,$wage,$effective]);}$wfdb->commit();respond(200,['ok'=>true]);
}
if ($route==='expenses' && $method==='POST') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'経費登録権限がありません']);
    $date=(string)($d['business_date']??businessDate($wfdb,$store));assertBusinessDateOpen($wfdb,$store,$date);$category=trim((string)($d['category']??''));$amount=round((float)($d['amount']??0),2);$desc=trim((string)($d['description']??''));
    if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)||$category===''||mb_strlen($category)>80||$amount<0)respond(422,['error'=>'経費情報が不正です']);
    $q=$wfdb->prepare('INSERT INTO expenses(store_id,business_date,category,amount,description,created_by_user_id) VALUES(?,?,?,?,?,?)');$q->execute([$store,$date,$category,$amount,$desc,(int)$u['id']]);respond(201,['expense_id'=>(int)$wfdb->lastInsertId()]);
}
if ($route === 'settings') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $minutes=(int)($d['work_rounding_minutes']??15);
    if(!in_array($minutes,[1,5,10,15,30,60],true)||!in_array($d['tax_mode']??'',['inclusive','exclusive'],true)||!in_array($d['service_mode']??'',['rate','fixed'],true)||!in_array($d['service_basis']??'',['sets_only','items_only','all_subtotal','configured'],true)||!in_array($d['work_rounding_mode']??'',['floor','half_up','ceil'],true)||!in_array($d['rounding_mode']??'',['floor','half_up','ceil'],true))respond(422,['error'=>'設定値が不正です']);
    $q=$wfdb->prepare('UPDATE store_settings SET tax_enabled=?,tax_rate=?,tax_mode=?,service_enabled=?,service_rate=?,service_mode=?,service_fixed_amount=?,service_taxable=?,service_basis=?,rounding_mode=?,work_rounding_minutes=?,work_rounding_mode=?,updated_by_user_id=? WHERE store_id=?');
    $q->execute([(int)!!($d['tax_enabled']??false),(float)($d['tax_rate']??0),$d['tax_mode'],(int)!!($d['service_enabled']??false),(float)($d['service_rate']??0),$d['service_mode'],(float)($d['service_fixed_amount']??0),(int)!!($d['service_taxable']??false),$d['service_basis'],$d['rounding_mode'],$minutes,$d['work_rounding_mode'],(int)$u['id'],$store]);
    respond(200,['ok'=>true]);
}
if ($route==='attendance/break') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'休憩修正権限がありません']);
    $aid=(int)($d['attendance_id']??0);$break=(int)($d['break_minutes']??-1);if($aid<1||$break<0||$break>720)respond(422,['error'=>'休憩時間が不正です']);
    $wfdb->beginTransaction();$q=$wfdb->prepare('SELECT id,clock_in_at,clock_out_at FROM attendance WHERE store_id=? AND id=? FOR UPDATE');$q->execute([$store,$aid]);$a=$q->fetch();
    if(!$a||!$a['clock_out_at']){$wfdb->rollBack();respond(409,['error'=>'退勤済みの勤怠だけ休憩を修正できます']);}$q=$wfdb->prepare('SELECT business_date FROM attendance WHERE store_id=? AND id=?');$q->execute([$store,$aid]);assertBusinessDateOpen($wfdb,$store,(string)$q->fetchColumn());
    $minutes=(int)floor((strtotime($a['clock_out_at'])-strtotime($a['clock_in_at']))/60);if($break>$minutes){$wfdb->rollBack();respond(422,['error'=>'休憩時間が勤務時間を超えています']);}
    $q=$wfdb->prepare("SELECT COUNT(*) FROM payroll_details p JOIN attendance a ON a.store_id=p.store_id AND a.cast_id=p.cast_id AND a.business_date=p.business_date WHERE a.store_id=? AND a.id=? AND p.status IN ('confirmed','paid')");$q->execute([$store,$aid]);if((int)$q->fetchColumn()){$wfdb->rollBack();respond(409,['error'=>'給与確定後の勤怠は変更できません']);}
    $wfdb->prepare("UPDATE attendance SET break_minutes=?,status='corrected',updated_by_user_id=? WHERE store_id=? AND id=?")->execute([$break,(int)$u['id'],$store,$aid]);$wfdb->commit();respond(200,['ok'=>true,'attendance_id'=>$aid,'break_minutes'=>$break]);
}
if (preg_match('#^attendance/(clock-in|clock-out)$#',$route,$m)) {
    $cast=(int)($d['cast_id']??0);if($cast<1)respond(422,['error'=>'キャストを指定してください']);
    $wfdb->beginTransaction();$q=$wfdb->prepare('SELECT id FROM casts WHERE store_id=? AND id=? AND is_active=1 FOR UPDATE');$q->execute([$store,$cast]);
    if(!$q->fetch()){$wfdb->rollBack();respond(404,['error'=>'キャストが見つかりません']);}
    if($m[1]==='clock-in'){
        $date=businessDate($wfdb,$store);assertBusinessDateOpen($wfdb,$store,$date);
        $q=$wfdb->prepare('SELECT id FROM attendance WHERE store_id=? AND cast_id=? AND clock_out_at IS NULL FOR UPDATE');$q->execute([$store,$cast]);
        if($q->fetch()){$wfdb->rollBack();respond(409,['error'=>'既に出勤中です']);}
        $wfdb->prepare("INSERT INTO attendance(store_id,cast_id,business_date,clock_in_at,status,created_by_user_id) VALUES(?,?,?,UTC_TIMESTAMP(3),'working',?)")->execute([$store,$cast,$date,(int)$u['id']]);
    }else{
        $q=$wfdb->prepare('SELECT id,business_date FROM attendance WHERE store_id=? AND cast_id=? AND clock_out_at IS NULL ORDER BY clock_in_at DESC LIMIT 1 FOR UPDATE');$q->execute([$store,$cast]);$a=$q->fetch();
        if(!$a){$wfdb->rollBack();respond(409,['error'=>'出勤中の記録がありません']);}assertBusinessDateOpen($wfdb,$store,$a['business_date']);
        $wfdb->prepare("UPDATE attendance SET clock_out_at=UTC_TIMESTAMP(3),status='completed',updated_by_user_id=? WHERE store_id=? AND id=?")->execute([(int)$u['id'],$store,$a['id']]);
    }
    $wfdb->commit();respond(200,['ok'=>true,'action'=>$m[1]]);
}
if (preg_match('#^casts/([0-9]+)/payroll/confirm$#',$route,$m)) {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'給与確定権限がありません']);
    $cast=(int)$m[1];$date=(string)($_GET['business_date']??'');if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date))respond(422,['error'=>'対象日が不正です']);
    assertBusinessDateOpen($wfdb,$store,$date);$wfdb->beginTransaction();
    $q=$wfdb->prepare('SELECT c.id,COALESCE((SELECT pr.hourly_wage FROM cast_pay_rules pr WHERE pr.store_id=c.store_id AND pr.cast_id=c.id AND pr.valid_from<=? AND (pr.valid_to IS NULL OR pr.valid_to>=?) ORDER BY pr.valid_from DESC LIMIT 1),c.hourly_wage) AS hourly_wage FROM casts c WHERE c.store_id=? AND c.id=? AND c.is_active=1 FOR UPDATE');$q->execute([$date,$date,$store,$cast]);$cst=$q->fetch();if(!$cst){$wfdb->rollBack();respond(404,['error'=>'キャストが見つかりません']);}
    $q=$wfdb->prepare('SELECT COUNT(*) FROM attendance WHERE store_id=? AND cast_id=? AND business_date=? AND clock_out_at IS NULL');$q->execute([$store,$cast,$date]);if((int)$q->fetchColumn()){$wfdb->rollBack();respond(409,['error'=>'退勤未記録があるため給与を確定できません']);}
    $q=$wfdb->prepare('SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,clock_in_at,clock_out_at)),0) gross_minutes,COALESCE(SUM(break_minutes),0) break_minutes FROM attendance WHERE store_id=? AND cast_id=? AND business_date=? AND clock_out_at IS NOT NULL');$q->execute([$store,$cast,$date]);$time=$q->fetch();$gross=(int)$time['gross_minutes'];$breakMinutes=(int)$time['break_minutes'];$raw=max(0,$gross-$breakMinutes);if($raw>360&&$breakMinutes<45){$wfdb->rollBack();respond(409,['error'=>'実労働6時間超のため45分以上の休憩記録が必要です']);}if($raw>480&&$breakMinutes<60){$wfdb->rollBack();respond(409,['error'=>'実労働8時間超のため60分以上の休憩記録が必要です']);}
    $q=$wfdb->prepare('SELECT work_rounding_minutes,work_rounding_mode FROM store_settings WHERE store_id=?');$q->execute([$store]);$cfg=$q->fetch()?:['work_rounding_minutes'=>15,'work_rounding_mode'=>'floor'];$unit=(int)$cfg['work_rounding_minutes'];
    $paymins=match($cfg['work_rounding_mode']){'ceil'=>(int)(ceil($raw/$unit)*$unit),'half_up'=>(int)(floor($raw/$unit+0.5)*$unit),default=>(int)(floor($raw/$unit)*$unit)};
    $q=$wfdb->prepare("SELECT COALESCE(SUM(a.back_amount),0) FROM order_item_back_allocations a JOIN order_items i ON i.store_id=a.store_id AND i.id=a.order_item_id JOIN orders o ON o.store_id=i.store_id AND o.id=i.order_id WHERE a.store_id=? AND a.cast_id=? AND a.status='active' AND i.status='active' AND o.status='confirmed' AND EXISTS (SELECT 1 FROM table_sessions ts WHERE ts.store_id=o.store_id AND ts.id=o.session_id AND ts.business_date=?)");$q->execute([$store,$cast,$date]);$back=(float)$q->fetchColumn();
    $hourly=(float)$cst['hourly_wage'];$hourpay=round($hourly*$paymins/60,2);$total=round($hourpay+$back,2);
    $q=$wfdb->prepare('SELECT id,status FROM payroll_details WHERE store_id=? AND cast_id=? AND business_date=? FOR UPDATE');$q->execute([$store,$cast,$date]);$old=$q->fetch();
    if($old&&in_array($old['status'],['confirmed','paid'],true)){$wfdb->rollBack();respond(409,['error'=>'給与は既に確定済みです']);}
    if($old){$pid=(int)$old['id'];$wfdb->prepare('DELETE FROM payroll_back_details WHERE store_id=? AND payroll_detail_id=?')->execute([$store,$pid]);$wfdb->prepare("UPDATE payroll_details SET work_minutes_raw=?,work_minutes_payable=?,rounding_minutes_snapshot=?,rounding_mode_snapshot=?,hourly_wage_snapshot=?,hourly_pay=?,back_pay=?,total_pay=?,status='confirmed' WHERE store_id=? AND id=?")->execute([$raw,$paymins,$unit,$cfg['work_rounding_mode'],$hourly,$hourpay,$back,$total,$store,$pid]);}
    else{$wfdb->prepare("INSERT INTO payroll_details(store_id,cast_id,business_date,work_minutes_raw,work_minutes_payable,rounding_minutes_snapshot,rounding_mode_snapshot,hourly_wage_snapshot,hourly_pay,back_pay,total_pay,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'confirmed')")->execute([$store,$cast,$date,$raw,$paymins,$unit,$cfg['work_rounding_mode'],$hourly,$hourpay,$back,$total]);$pid=(int)$wfdb->lastInsertId();}
    $q=$wfdb->prepare("SELECT a.id,a.back_amount FROM order_item_back_allocations a JOIN order_items i ON i.store_id=a.store_id AND i.id=a.order_item_id JOIN orders o ON o.store_id=i.store_id AND o.id=i.order_id WHERE a.store_id=? AND a.cast_id=? AND a.status='active' AND i.status='active' AND o.status='confirmed' AND EXISTS (SELECT 1 FROM table_sessions ts WHERE ts.store_id=o.store_id AND ts.id=o.session_id AND ts.business_date=?)");$q->execute([$store,$cast,$date]);
    $ins=$wfdb->prepare('INSERT INTO payroll_back_details(store_id,payroll_detail_id,back_allocation_id,back_amount) VALUES(?,?,?,?)');foreach($q->fetchAll() as $a)$ins->execute([$store,$pid,$a['id'],$a['back_amount']]);
    $wfdb->commit();respond(200,['payroll_id'=>$pid,'raw_minutes'=>$raw,'payable_minutes'=>$paymins,'hourly_pay'=>$hourpay,'back_pay'=>$back,'total_pay'=>$total,'status'=>'confirmed']);
}
if ($route === 'payments') {
    $sid=(int)($d['session_id']??0);$amount=round((float)($d['amount']??0),2);$method=(string)($d['payment_method']??'');$key=idempotency($d);
    if($sid<1||$amount<=0||!in_array($method,['cash','credit_card','qr','electronic_money','transfer','other'],true))respond(422,['error'=>'決済情報が不正です']);
    $wfdb->beginTransaction();
    $q=$wfdb->prepare('SELECT id,session_id,amount,status FROM payments WHERE store_id=? AND idempotency_key=? FOR UPDATE');$q->execute([$store,$key]);if($old=$q->fetch()){$wfdb->commit();respond(200,['payment_id'=>(int)$old['id'],'session_id'=>(int)$old['session_id'],'amount'=>(float)$old['amount'],'status'=>$old['status'],'replayed'=>true]);}
    $q=$wfdb->prepare("SELECT id,table_id,status,business_date FROM table_sessions WHERE store_id=? AND id=? FOR UPDATE");$q->execute([$store,$sid]);$s=$q->fetch();
    if(!$s||!in_array($s['status'],['open','checkout'],true)){$wfdb->rollBack();respond(409,['error'=>'会計可能な卓ではありません']);}assertBusinessDateOpen($wfdb,$store,$s['business_date']);
    $bill=posGetSessionBill($wfdb,$store,$sid);
    if(empty($bill['snapshot_locked'])){
        $q=$wfdb->prepare('SELECT * FROM store_settings WHERE store_id=?');$q->execute([$store]);$cfg=$q->fetch()?:[];
        $snapshotJson=json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $wfdb->prepare('INSERT INTO session_charge_snapshots(store_id,session_id,items_subtotal,sets_subtotal,subtotal,service_amount,tax_amount,total_amount,settings_snapshot) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$store,$sid,$bill['items_subtotal'],$bill['sets_subtotal'],$bill['subtotal'],$bill['service'],$bill['tax'],$bill['total'],$snapshotJson]);
        $bill['snapshot_locked']=true;
    }
    $total=(float)$bill['total'];
    $q=$wfdb->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE store_id=? AND session_id=? AND status='completed'");$q->execute([$store,$sid]);$paid=(float)$q->fetchColumn();
    if($amount>$total-$paid+0.001){$wfdb->rollBack();respond(422,['error'=>'請求残額を超える入金です']);}
    $q=$wfdb->prepare("INSERT INTO payments(store_id,session_id,payment_method,amount,status,idempotency_key,paid_at,created_by_user_id) VALUES(?,?,?,?,'completed',?,UTC_TIMESTAMP(3),?)");$q->execute([$store,$sid,$method,$amount,$key,(int)$u['id']]);$pid=(int)$wfdb->lastInsertId();$newPaid=$paid+$amount;$closed=$newPaid>=$total-0.001;
    if($closed){$wfdb->prepare("UPDATE table_sessions SET status='closed',ended_at=UTC_TIMESTAMP(3) WHERE store_id=? AND id=?")->execute([$store,$sid]);$wfdb->prepare("UPDATE tables SET status='available' WHERE store_id=? AND id=?")->execute([$store,$s['table_id']]);}
    else{$wfdb->prepare("UPDATE table_sessions SET status='checkout' WHERE store_id=? AND id=?")->execute([$store,$sid]);$wfdb->prepare("UPDATE tables SET status='checkout' WHERE store_id=? AND id=?")->execute([$store,$s['table_id']]);}
    $wfdb->commit();respond(201,['payment_id'=>$pid,'bill_total'=>$total,'paid_total'=>$newPaid,'balance'=>max(0,$total-$newPaid),'session_closed'=>$closed]);
}
if (preg_match('#^payments/([0-9]+)/refund$#',$route,$m)) {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'返金権限がありません']);
    $pid=(int)$m[1];$amount=round((float)($d['amount']??0),2);$reason=trim((string)($d['reason']??''));$key=idempotency($d);
    if($amount<=0||$reason==='')respond(422,['error'=>'返金額と理由が必要です']);
    $wfdb->beginTransaction();$q=$wfdb->prepare('SELECT p.id,p.amount,s.business_date FROM payments p JOIN table_sessions s ON s.store_id=p.store_id AND s.id=p.session_id WHERE p.store_id=? AND p.id=? FOR UPDATE');$q->execute([$store,$pid]);$p=$q->fetch();if(!$p){$wfdb->rollBack();respond(404,['error'=>'決済が見つかりません']);}assertBusinessDateOpen($wfdb,$store,$p['business_date']);
    $q=$wfdb->prepare('SELECT id FROM payment_refunds WHERE store_id=? AND idempotency_key=?');$q->execute([$store,$key]);if($old=$q->fetch()){$wfdb->commit();respond(200,['refund_id'=>(int)$old['id'],'replayed'=>true]);}
    $q=$wfdb->prepare('SELECT COALESCE(SUM(amount),0) FROM payment_refunds WHERE store_id=? AND payment_id=?');$q->execute([$store,$pid]);$already=(float)$q->fetchColumn();if($amount>(float)$p['amount']-$already+0.001){$wfdb->rollBack();respond(422,['error'=>'返金可能額を超えています']);}
    $wfdb->prepare('INSERT INTO payment_refunds(store_id,payment_id,business_date,amount,reason,refunded_at,created_by_user_id,idempotency_key) VALUES(?,?,?,?,?,UTC_TIMESTAMP(3),?,?)')->execute([$store,$pid,$p['business_date'],$amount,$reason,(int)$u['id'],$key]);$rid=(int)$wfdb->lastInsertId();
    if($already+$amount>=(float)$p['amount']-0.001)$wfdb->prepare("UPDATE payments SET status='refunded' WHERE store_id=? AND id=?")->execute([$store,$pid]);
    $wfdb->commit();respond(201,['refund_id'=>$rid,'refunded_total'=>$already+$amount]);
}
if ($route === 'closings/run') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'日締め権限がありません']);
    $date=(string)($d['business_date']??'');$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)respond(422,['error'=>'営業日が不正です']);
    $wfdb->beginTransaction();
    $q=$wfdb->prepare('SELECT id FROM table_sessions WHERE store_id=? AND business_date=? FOR UPDATE');$q->execute([$store,$date]);$q->fetchAll();
    $q=$wfdb->prepare('SELECT id FROM attendance WHERE store_id=? AND business_date=? FOR UPDATE');$q->execute([$store,$date]);$q->fetchAll();
    $q=$wfdb->prepare('SELECT id FROM payroll_details WHERE store_id=? AND business_date=? FOR UPDATE');$q->execute([$store,$date]);$q->fetchAll();
    $q=$wfdb->prepare('SELECT id,status FROM daily_closings WHERE store_id=? AND business_date=? FOR UPDATE');$q->execute([$store,$date]);$cl=$q->fetch();
    if($cl&&$cl['status']==='closed'){$wfdb->rollBack();respond(409,['error'=>'締め済みです']);}
    $errors=[];
    $checks=[
      ['open_sessions',"SELECT COUNT(*) FROM table_sessions WHERE store_id=? AND business_date=? AND status IN ('open','checkout')",'未精算の卓があります'],
      ['unclocked_casts','SELECT COUNT(*) FROM attendance WHERE store_id=? AND business_date=? AND clock_out_at IS NULL','退勤未記録があります'],
      ['unconfirmed_payroll',"SELECT COUNT(*) FROM attendance a WHERE a.store_id=? AND a.business_date=? AND a.clock_out_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payroll_details p WHERE p.store_id=a.store_id AND p.cast_id=a.cast_id AND p.business_date=a.business_date AND p.status IN ('confirmed','paid'))",'給与未確定のキャスト勤務があります']
    ];
    foreach($checks as [$code,$sql,$label]){$q=$wfdb->prepare($sql);$q->execute([$store,$date]);$n=(int)$q->fetchColumn();if($n)$errors[]=['code'=>$code,'description'=>$label.' '.$n.'件'];}
    $q=$wfdb->prepare('SELECT * FROM store_settings WHERE store_id=?');$q->execute([$store]);$cfg=$q->fetch()?:[];
    $q=$wfdb->prepare("SELECT id FROM table_sessions WHERE store_id=? AND business_date=? AND status='closed' ORDER BY id");$q->execute([$store,$date]);$sessions=$q->fetchAll();
    $subtotal=0.0;$serviceTotal=0.0;$taxTotal=0.0;$salesTotal=0.0;
    foreach($sessions as $session){
        $sid=(int)$session['id'];
        $bill=posGetSessionBill($wfdb,$store,$sid);
        $sub=(float)$bill['subtotal'];$service=(float)$bill['service'];$tax=(float)$bill['tax'];$total=(float)$bill['total'];
        $subtotal+=$sub;$serviceTotal+=$service;$taxTotal+=$tax;$salesTotal+=$total;
    }
    $q=$wfdb->prepare('SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN table_sessions s ON s.store_id=p.store_id AND s.id=p.session_id WHERE p.store_id=? AND s.business_date=? AND p.status IN ("completed","refunded")');$q->execute([$store,$date]);$paidGross=(float)$q->fetchColumn();
    $q=$wfdb->prepare('SELECT COALESCE(SUM(r.amount),0) FROM payment_refunds r WHERE r.store_id=? AND r.business_date=?');$q->execute([$store,$date]);$refund=(float)$q->fetchColumn();
    $netPaid=round($paidGross-$refund,2);$netSales=round($salesTotal-$refund,2);$difference=round($netPaid-$netSales,2);
    if(abs($difference)>0.01)$errors[]=['code'=>'payment_mismatch','description'=>'入金と請求の差額があります（差額 '.$difference.'円）'];
    if(!$cl){$wfdb->prepare("INSERT INTO daily_closings(store_id,business_date,status,settings_snapshot) VALUES(?,?,'pending',?)")->execute([$store,$date,json_encode($cfg,JSON_UNESCAPED_UNICODE)]);$cid=(int)$wfdb->lastInsertId();}
    else{$cid=(int)$cl['id'];$wfdb->prepare('DELETE FROM closing_errors WHERE store_id=? AND daily_closing_id=?')->execute([$store,$cid]);$wfdb->prepare('DELETE FROM daily_closing_details WHERE store_id=? AND daily_closing_id=?')->execute([$store,$cid]);}
    $ins=$wfdb->prepare('INSERT INTO closing_errors(store_id,daily_closing_id,error_code,description) VALUES(?,?,?,?)');foreach($errors as $e)$ins->execute([$store,$cid,$e['code'],$e['description']]);
    if($errors){$wfdb->prepare("UPDATE daily_closings SET status='blocked',sales_subtotal=?,service_total=?,tax_total=?,sales_total=?,refund_total=?,difference=?,started_at=UTC_TIMESTAMP(3),closed_at=NULL WHERE store_id=? AND id=?")->execute([$subtotal,$serviceTotal,$taxTotal,$salesTotal,$refund,$difference,$store,$cid]);$wfdb->commit();respond(409,['status'=>'blocked','closing_id'=>$cid,'errors'=>$errors,'difference'=>$difference]);}
    $q=$wfdb->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN table_sessions s ON s.store_id=p.store_id AND s.id=p.session_id WHERE p.store_id=? AND s.business_date=? AND p.payment_method='cash' AND p.status IN ('completed','refunded')");$q->execute([$store,$date]);$cash=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN table_sessions s ON s.store_id=p.store_id AND s.id=p.session_id WHERE p.store_id=? AND s.business_date=? AND p.payment_method='credit_card' AND p.status IN ('completed','refunded')");$q->execute([$store,$date]);$card=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN table_sessions s ON s.store_id=p.store_id AND s.id=p.session_id WHERE p.store_id=? AND s.business_date=? AND p.payment_method NOT IN ('cash','credit_card') AND p.status IN ('completed','refunded')");$q->execute([$store,$date]);$other=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(total_pay),0) FROM payroll_details WHERE store_id=? AND business_date=? AND status IN ('confirmed','paid')");$q->execute([$store,$date]);$pay=(float)$q->fetchColumn();
    $q=$wfdb->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE store_id=? AND business_date=?');$q->execute([$store,$date]);$expense=(float)$q->fetchColumn();
    $wfdb->prepare("UPDATE daily_closings SET status='closed',sales_subtotal=?,service_total=?,tax_total=?,sales_total=?,refund_total=?,cash_total=?,card_total=?,other_total=?,payroll_total=?,expense_total=?,difference=0,started_at=UTC_TIMESTAMP(3),closed_at=UTC_TIMESTAMP(3) WHERE store_id=? AND id=?")->execute([$subtotal,$serviceTotal,$taxTotal,$salesTotal,$refund,$cash,$card,$other,$pay,$expense,$store,$cid]);
    $det=$wfdb->prepare('INSERT INTO daily_closing_details(store_id,daily_closing_id,item_type,amount,detail_json) VALUES(?,?,?,?,?)');
    foreach([['sales_subtotal',$subtotal],['service',$serviceTotal],['tax',$taxTotal],['sales_total',$salesTotal],['refund',$refund],['cash',$cash],['card',$card],['other_payment',$other],['payroll',$pay],['expense',$expense]] as [$type,$amount])$det->execute([$store,$cid,$type,$amount,json_encode(['business_date'=>$date],JSON_UNESCAPED_UNICODE)]);
    $wfdb->commit();respond(200,['status'=>'closed','closing_id'=>$cid,'business_date'=>$date,'sales_subtotal'=>$subtotal,'service_total'=>$serviceTotal,'tax_total'=>$taxTotal,'sales_total'=>$salesTotal,'refund_total'=>$refund,'cash_total'=>$cash,'card_total'=>$card,'other_total'=>$other,'payroll_total'=>$pay,'expense_total'=>$expense,'difference'=>0]);
}
