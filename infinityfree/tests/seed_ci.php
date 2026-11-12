<?php
declare(strict_types=1);
$host=getenv('DB_HOST')?:'127.0.0.1';$name=getenv('POS_TEST_DB_NAME')?:'girlsbar_pos_infinityfree';$user=getenv('DB_USER')?:'root';$pass=getenv('DB_PASSWORD')?:'rootpass';
$pdo=new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->beginTransaction();
try{
 $pdo->prepare("INSERT INTO stores(store_code,store_name,timezone,business_day_start) VALUES('CI-POS-TEST','CI POS Test','Asia/Tokyo','00:00:00')")->execute();$store=(int)$pdo->lastInsertId();
 $role=(int)$pdo->query("SELECT id FROM roles WHERE role_code='owner'")->fetchColumn();
 $pdo->prepare('INSERT INTO users(store_id,role_id,login_id,password_hash,display_name) VALUES(?,?,?,?,?)')->execute([$store,$role,'owner',password_hash('ci-owner-password-123',PASSWORD_DEFAULT),'CI Owner']);$owner=(int)$pdo->lastInsertId();
 $pdo->prepare('INSERT INTO store_settings(store_id) VALUES(?)')->execute([$store]);
 $pdo->prepare("INSERT INTO tables(store_id,table_name,capacity,status,is_active) VALUES(?,'A卓',4,'available',1)")->execute([$store]);
 $cast=$pdo->prepare('INSERT INTO casts(store_id,cast_code,cast_name,hourly_wage,is_active) VALUES(?,?,?,?,1)');
 $cast->execute([$store,'C001','キャストA',1200]);$cast1=(int)$pdo->lastInsertId();
 $cast->execute([$store,'C002','キャストB',1300]);$cast2=(int)$pdo->lastInsertId();
 $pdo->prepare('INSERT INTO cast_pay_rules(store_id,cast_id,hourly_wage,valid_from) VALUES(?,?,?,CURRENT_DATE())')->execute([$store,$cast1,1200]);
 $pdo->prepare('INSERT INTO cast_pay_rules(store_id,cast_id,hourly_wage,valid_from) VALUES(?,?,?,CURRENT_DATE())')->execute([$store,$cast2,1300]);
 $pdo->prepare("INSERT INTO products(store_id,product_name,selling_price,back_rate,back_type,is_active) VALUES(?,'ドリンク',1000,20,'percentage',1)")->execute([$store]);
 $pdo->prepare("INSERT INTO set_menus(store_id,name,duration_minutes,price,is_extension,is_active) VALUES(?,'60分セット',60,500,0,1)")->execute([$store]);
 $pdo->commit();
 echo json_encode(['store_id'=>$store,'cast1'=>$cast1,'cast2'=>$cast2],JSON_THROW_ON_ERROR);
}catch(Throwable $e){$pdo->rollBack();throw $e;}
