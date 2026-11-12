<?php
declare(strict_types=1);
/* Additional same-origin workflows. Included by api.php after authentication. */
$wfdb = pdo();

if ($method === 'GET' && $route === 'settings') {
    $q=$wfdb->prepare('SELECT tax_enabled,tax_rate,tax_mode,service_enabled,service_rate,service_mode,service_fixed_amount,service_taxable,service_basis,rounding_mode,work_rounding_minutes,work_rounding_mode FROM store_settings WHERE store_id=?');
    $q->execute([$store]); respond(200,['data'=>$q->fetch() ?: []]);
}
if ($method === 'GET' && preg_match('#^sessions/([0-9]+)/bill$#',$route,$m)) {
    $sid=(int)$m[1];
    $q=$wfdb->prepare('SELECT id,status FROM table_sessions WHERE store_id=? AND id=?'); $q->execute([$store,$sid]); $session=$q->fetch();
    if(!$session) respond(404,['error'=>'卓が見つかりません']);
    $q=$wfdb->prepare("SELECT COALESCE(SUM(oi.quantity*oi.unit_price_snapshot),0) FROM orders o JOIN order_items oi ON oi.store_id=o.store_id AND oi.order_id=o.id WHERE o.store_id=? AND o.session_id=? AND o.status='confirmed' AND oi.status='active'");
    $q->execute([$store,$sid]); $items=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(price_snapshot),0) FROM session_set_items WHERE store_id=? AND session_id=? AND status<>'cancelled'");
    $q->execute([$store,$sid]); $sets=(float)$q->fetchColumn();
    $q=$wfdb->prepare('SELECT * FROM store_settings WHERE store_id=?');$q->execute([$store]);$cfg=$q->fetch()?:[];
    $subtotal=$items+$sets; $basis=($cfg['service_basis']??'all_subtotal')==='sets_only'?$sets:(($cfg['service_basis']??'')==='items_only'?$items:$subtotal);
    $service=empty($cfg['service_enabled'])?0:(($cfg['service_mode']??'rate')==='fixed'?(float)$cfg['service_fixed_amount']:round($basis*(float)$cfg['service_rate']/100,2));
    $taxBase=$subtotal+(!empty($cfg['service_taxable'])?$service:0);
    $tax=empty($cfg['tax_enabled'])?0:round(($cfg['tax_mode']==='inclusive'?$taxBase-$taxBase/(1+(float)$cfg['tax_rate']/100):$taxBase*(float)$cfg['tax_rate']/100),2);
    $total=round($subtotal+$service+($cfg['tax_mode']==='inclusive'?0:$tax),2);
    $q=$wfdb->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE store_id=? AND session_id=? AND status='completed'");$q->execute([$store,$sid]);$paid=(float)$q->fetchColumn();
    respond(200,['session_id'=>$sid,'status'=>$session['status'],'items_subtotal'=>$items,'sets_subtotal'=>$sets,'subtotal'=>$subtotal,'service'=>$service,'tax'=>$tax,'total'=>$total,'paid'=>$paid,'balance'=>max(0,round($total-$paid,2))]);
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
csrf(); staff($u); $d=input();

if ($route === 'settings') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'権限がありません']);
    $minutes=(int)($d['work_rounding_minutes']??15);
    if(!in_array($minutes,[1,5,10,15,30,60],true)||!in_array($d['tax_mode']??'',['inclusive','exclusive'],true)||!in_array($d['service_mode']??'',['rate','fixed'],true)||!in_array($d['service_basis']??'',['sets_only','items_only','all_subtotal','configured'],true)||!in_array($d['work_rounding_mode']??'',['floor','half_up','ceil'],true))respond(422,['error'=>'設定値が不正です']);
    $q=$wfdb->prepare('UPDATE store_settings SET tax_enabled=?,tax_rate=?,tax_mode=?,service_enabled=?,service_rate=?,service_mode=?,service_fixed_amount=?,service_taxable=?,service_basis=?,work_rounding_minutes=?,work_rounding_mode=?,updated_by_user_id=? WHERE store_id=?');
    $q->execute([(int)!!($d['tax_enabled']??false),(float)($d['tax_rate']??0),$d['tax_mode'],(int)!!($d['service_enabled']??false),(float)($d['service_rate']??0),$d['service_mode'],(float)($d['service_fixed_amount']??0),(int)!!($d['service_taxable']??false),$d['service_basis'],$minutes,$d['work_rounding_mode'],(int)$u['id'],$store]);
    respond(200,['ok'=>true]);
}
if (preg_match('#^attendance/(clock-in|clock-out)$#',$route,$m)) {
    $cast=(int)($d['cast_id']??0);if($cast<1)respond(422,['error'=>'キャストを指定してください']);
    $wfdb->beginTransaction();$q=$wfdb->prepare('SELECT id FROM casts WHERE store_id=? AND id=? AND is_active=1 FOR UPDATE');$q->execute([$store,$cast]);
    if(!$q->fetch()){$wfdb->rollBack();respond(404,['error'=>'キャストが見つかりません']);}
    if($m[1]==='clock-in'){
        $q=$wfdb->prepare('SELECT id FROM attendance WHERE store_id=? AND cast_id=? AND clock_out_at IS NULL FOR UPDATE');$q->execute([$store,$cast]);
        if($q->fetch()){$wfdb->rollBack();respond(409,['error'=>'既に出勤中です']);}
        $wfdb->prepare("INSERT INTO attendance(store_id,cast_id,business_date,clock_in_at,status,created_by_user_id) VALUES(?,?,?,UTC_TIMESTAMP(3),'working',?)")->execute([$store,$cast,businessDate($wfdb,$store),(int)$u['id']]);
    }else{
        $q=$wfdb->prepare('SELECT id FROM attendance WHERE store_id=? AND cast_id=? AND clock_out_at IS NULL ORDER BY clock_in_at DESC LIMIT 1 FOR UPDATE');$q->execute([$store,$cast]);$a=$q->fetch();
        if(!$a){$wfdb->rollBack();respond(409,['error'=>'出勤中の記録がありません']);}
        $wfdb->prepare("UPDATE attendance SET clock_out_at=UTC_TIMESTAMP(3),status='completed',updated_by_user_id=? WHERE store_id=? AND id=?")->execute([(int)$u['id'],$store,$a['id']]);
    }
    $wfdb->commit();respond(200,['ok'=>true,'action'=>$m[1]]);
}
if (preg_match('#^casts/([0-9]+)/payroll/confirm$#',$route,$m)) {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'給与確定権限がありません']);
    $cast=(int)$m[1];$date=(string)($_GET['business_date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))respond(422,['error'=>'対象日が不正です']);
    $q=$wfdb->prepare('SELECT id,hourly_wage FROM casts WHERE store_id=? AND id=? AND is_active=1');$q->execute([$store,$cast]);$c=$q->fetch();if(!$c)respond(404,['error'=>'キャストが見つかりません']);
    $q=$wfdb->prepare('SELECT COUNT(*) FROM attendance WHERE store_id=? AND cast_id=? AND business_date=? AND clock_out_at IS NULL');$q->execute([$store,$cast,$date]);if((int)$q->fetchColumn())respond(409,['error'=>'退勤未記録があるため給与を確定できません']);
    $q=$wfdb->prepare('SELECT COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,clock_in_at,clock_out_at)-break_minutes)),0) FROM attendance WHERE store_id=? AND cast_id=? AND business_date=? AND clock_out_at IS NOT NULL');$q->execute([$store,$cast,$date]);$raw=(int)$q->fetchColumn();
    $q=$wfdb->prepare('SELECT work_rounding_minutes,work_rounding_mode FROM store_settings WHERE store_id=?');$q->execute([$store]);$s=$q->fetch()?:['work_rounding_minutes'=>15,'work_rounding_mode'=>'floor'];$unit=(int)$s['work_rounding_minutes'];$paymins=match($s['work_rounding_mode']){'ceil'=>(int)(ceil($raw/$unit)*$unit),'half_up'=>(int)(floor($raw/$unit+0.5)*$unit),default=>(int)(floor($raw/$unit)*$unit)};
    $q=$wfdb->prepare("SELECT COALESCE(SUM(a.back_amount),0) FROM order_item_back_allocations a JOIN order_items i ON i.store_id=a.store_id AND i.id=a.order_item_id JOIN orders o ON o.store_id=i.store_id AND o.id=i.order_id WHERE a.store_id=? AND a.cast_id=? AND a.status='active' AND i.status='active' AND o.status='confirmed' AND EXISTS (SELECT 1 FROM table_sessions ts WHERE ts.store_id=o.store_id AND ts.id=o.session_id AND ts.business_date=?)");$q->execute([$store,$cast,$date]);$back=(float)$q->fetchColumn();
    $hourly=(float)$c['hourly_wage'];$hourpay=round($hourly*$paymins/60,2);$total=$hourpay+$back;
    $q=$wfdb->prepare('SELECT id,status FROM payroll_details WHERE store_id=? AND cast_id=? AND business_date=?');$q->execute([$store,$cast,$date]);$old=$q->fetch();
    if($old&&in_array($old['status'],['confirmed','paid'],true))respond(409,['error'=>'給与は既に確定済みです']);
    if($old){$wfdb->prepare("UPDATE payroll_details SET work_minutes_raw=?,work_minutes_payable=?,rounding_minutes_snapshot=?,rounding_mode_snapshot=?,hourly_wage_snapshot=?,hourly_pay=?,back_pay=?,total_pay=?,status='confirmed' WHERE store_id=? AND id=?")->execute([$raw,$paymins,$unit,$s['work_rounding_mode'],$hourly,$hourpay,$back,$total,$store,$old['id']]);$pid=(int)$old['id'];}
    else{$wfdb->prepare("INSERT INTO payroll_details(store_id,cast_id,business_date,work_minutes_raw,work_minutes_payable,rounding_minutes_snapshot,rounding_mode_snapshot,hourly_wage_snapshot,hourly_pay,back_pay,total_pay,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'confirmed')")->execute([$store,$cast,$date,$raw,$paymins,$unit,$s['work_rounding_mode'],$hourly,$hourpay,$back,$total]);$pid=(int)$wfdb->lastInsertId();}
    respond(200,['payroll_id'=>$pid,'raw_minutes'=>$raw,'payable_minutes'=>$paymins,'hourly_pay'=>$hourpay,'back_pay'=>$back,'total_pay'=>$total,'status'=>'confirmed']);
}
if ($route === 'payments') {
    $sid=(int)($d['session_id']??0);$amount=round((float)($d['amount']??0),2);$method=(string)($d['payment_method']??'');$key=idempotency($d);
    if($sid<1||$amount<=0||!in_array($method,['cash','credit_card','qr','electronic_money','transfer','other'],true))respond(422,['error'=>'決済情報が不正です']);
    $wfdb->beginTransaction();$q=$wfdb->prepare("SELECT id,table_id,status FROM table_sessions WHERE store_id=? AND id=? FOR UPDATE");$q->execute([$store,$sid]);$s=$q->fetch();
    if(!$s||!in_array($s['status'],['open','checkout'],true)){$wfdb->rollBack();respond(409,['error'=>'会計可能な卓ではありません']);}
    $q=$wfdb->prepare('SELECT id FROM payments WHERE store_id=? AND idempotency_key=?');$q->execute([$store,$key]);if($old=$q->fetch()){$wfdb->commit();respond(200,['payment_id'=>(int)$old['id'],'replayed'=>true]);}
    $q=$wfdb->prepare("SELECT COALESCE(SUM(i.quantity*i.unit_price_snapshot),0) FROM orders o JOIN order_items i ON i.store_id=o.store_id AND i.order_id=o.id WHERE o.store_id=? AND o.session_id=? AND o.status='confirmed' AND i.status='active'");$q->execute([$store,$sid]);$items=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(price_snapshot),0) FROM session_set_items WHERE store_id=? AND session_id=? AND status<>'cancelled'");$q->execute([$store,$sid]);$sets=(float)$q->fetchColumn();
    $q=$wfdb->prepare('SELECT * FROM store_settings WHERE store_id=?');$q->execute([$store]);$cfg=$q->fetch()?:[];
    $sub=$items+$sets;$basis=($cfg['service_basis']??'all_subtotal')==='sets_only'?$sets:(($cfg['service_basis']??'')==='items_only'?$items:$sub);
    $service=empty($cfg['service_enabled'])?0:(($cfg['service_mode']??'rate')==='fixed'?(float)$cfg['service_fixed_amount']:round($basis*(float)$cfg['service_rate']/100,2));
    $taxBase=$sub+(!empty($cfg['service_taxable'])?$service:0);$tax=empty($cfg['tax_enabled'])?0:round(($cfg['tax_mode']==='inclusive'?$taxBase-$taxBase/(1+(float)$cfg['tax_rate']/100):$taxBase*(float)$cfg['tax_rate']/100),2);
    $total=round($sub+$service+($cfg['tax_mode']==='inclusive'?0:$tax),2);
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
    $wfdb->beginTransaction();$q=$wfdb->prepare('SELECT p.id,p.amount,s.business_date FROM payments p JOIN table_sessions s ON s.store_id=p.store_id AND s.id=p.session_id WHERE p.store_id=? AND p.id=? FOR UPDATE');$q->execute([$store,$pid]);$p=$q->fetch();if(!$p){$wfdb->rollBack();respond(404,['error'=>'決済が見つかりません']);}
    $q=$wfdb->prepare('SELECT id FROM payment_refunds WHERE store_id=? AND idempotency_key=?');$q->execute([$store,$key]);if($old=$q->fetch()){$wfdb->commit();respond(200,['refund_id'=>(int)$old['id'],'replayed'=>true]);}
    $q=$wfdb->prepare('SELECT COALESCE(SUM(amount),0) FROM payment_refunds WHERE store_id=? AND payment_id=?');$q->execute([$store,$pid]);$already=(float)$q->fetchColumn();if($amount>(float)$p['amount']-$already+0.001){$wfdb->rollBack();respond(422,['error'=>'返金可能額を超えています']);}
    $wfdb->prepare('INSERT INTO payment_refunds(store_id,payment_id,business_date,amount,reason,refunded_at,created_by_user_id,idempotency_key) VALUES(?,?,?,?,?,UTC_TIMESTAMP(3),?,?)')->execute([$store,$pid,$p['business_date'],$amount,$reason,(int)$u['id'],$key]);$rid=(int)$wfdb->lastInsertId();
    if($already+$amount>=(float)$p['amount']-0.001)$wfdb->prepare("UPDATE payments SET status='refunded' WHERE store_id=? AND id=?")->execute([$store,$pid]);
    $wfdb->commit();respond(201,['refund_id'=>$rid,'refunded_total'=>$already+$amount]);
}
if ($route === 'closings/run') {
    if(!in_array($u['role_code'],['owner','manager'],true))respond(403,['error'=>'日締め権限がありません']);
    $date=(string)($d['business_date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))respond(422,['error'=>'営業日が不正です']);
    $checks=[
      ['open_sessions',"SELECT COUNT(*) FROM table_sessions WHERE store_id=? AND business_date=? AND status IN ('open','checkout')",'未精算の卓があります'],
      ['unclocked_casts','SELECT COUNT(*) FROM attendance WHERE store_id=? AND business_date=? AND clock_out_at IS NULL','退勤未記録があります'],
      ['unconfirmed_payroll',"SELECT COUNT(*) FROM payroll_details WHERE store_id=? AND business_date=? AND status='calculated'",'未確定給与があります']
    ];$errors=[];
    foreach($checks as [$code,$sql,$label]){$q=$wfdb->prepare($sql);$q->execute([$store,$date]);$n=(int)$q->fetchColumn();if($n)$errors[]=['code'=>$code,'description'=>$label.' '.$n.'件'];}
    $wfdb->beginTransaction();$q=$wfdb->prepare('SELECT id,status FROM daily_closings WHERE store_id=? AND business_date=? FOR UPDATE');$q->execute([$store,$date]);$cl=$q->fetch();
    if($cl&&$cl['status']==='closed'){$wfdb->rollBack();respond(409,['error'=>'締め済みです']);}
    if(!$cl){$wfdb->prepare("INSERT INTO daily_closings(store_id,business_date,status,settings_snapshot) VALUES(?,?,'pending',JSON_OBJECT())")->execute([$store,$date]);$cid=(int)$wfdb->lastInsertId();}
    else{$cid=(int)$cl['id'];$wfdb->prepare('DELETE FROM closing_errors WHERE store_id=? AND daily_closing_id=?')->execute([$store,$cid]);}
    $ins=$wfdb->prepare('INSERT INTO closing_errors(store_id,daily_closing_id,error_code,description) VALUES(?,?,?,?)');foreach($errors as $e)$ins->execute([$store,$cid,$e['code'],$e['description']]);
    if($errors){$wfdb->prepare("UPDATE daily_closings SET status='blocked',started_at=UTC_TIMESTAMP(3),closed_at=NULL WHERE store_id=? AND id=?")->execute([$store,$cid]);$wfdb->commit();respond(409,['status'=>'blocked','errors'=>$errors]);}
    $q=$wfdb->prepare("SELECT COALESCE(SUM(i.quantity*i.unit_price_snapshot),0) FROM orders o JOIN order_items i ON i.store_id=o.store_id AND i.order_id=o.id WHERE o.store_id=? AND EXISTS (SELECT 1 FROM table_sessions ts WHERE ts.store_id=o.store_id AND ts.id=o.session_id AND ts.business_date=?) AND o.status='confirmed' AND i.status='active'");$q->execute([$store,$date]);$sales=(float)$q->fetchColumn();
    $q=$wfdb->prepare('SELECT COALESCE(SUM(amount),0) FROM payment_refunds WHERE store_id=? AND business_date=?');$q->execute([$store,$date]);$refund=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE store_id=? AND DATE(paid_at)=? AND payment_method='cash'");$q->execute([$store,$date]);$cash=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE store_id=? AND DATE(paid_at)=? AND payment_method='credit_card'");$q->execute([$store,$date]);$card=(float)$q->fetchColumn();
    $q=$wfdb->prepare("SELECT COALESCE(SUM(total_pay),0) FROM payroll_details WHERE store_id=? AND business_date=? AND status IN ('confirmed','paid')");$q->execute([$store,$date]);$pay=(float)$q->fetchColumn();
    $q=$wfdb->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE store_id=? AND business_date=?');$q->execute([$store,$date]);$expense=(float)$q->fetchColumn();
    $wfdb->prepare("UPDATE daily_closings SET status='closed',sales_subtotal=?,sales_total=?,refund_total=?,cash_total=?,card_total=?,payroll_total=?,expense_total=?,difference=?,started_at=UTC_TIMESTAMP(3),closed_at=UTC_TIMESTAMP(3) WHERE store_id=? AND id=?")->execute([$sales,$sales,$refund,$cash,$card,$pay,$expense,round($cash+$card-$sales+$refund,2),$store,$cid]);
    $wfdb->commit();respond(200,['status'=>'closed','closing_id'=>$cid,'sales_total'=>$sales,'refund_total'=>$refund,'cash_total'=>$cash,'card_total'=>$card,'payroll_total'=>$pay,'expense_total'=>$expense]);
}
