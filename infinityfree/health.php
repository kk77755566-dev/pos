<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) { http_response_code(503); echo json_encode(['ok'=>false,'status'=>'config_missing']); exit; }
try {
    $config = require $configPath;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
    $db = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $required = ['stores','roles','users','store_settings','product_categories','products','set_menus','casts','cast_pay_rules','tables','customers','table_sessions','session_casts','session_set_items','session_discounts','orders','order_items','order_item_back_allocations','payments','session_charge_snapshots','payment_refunds','attendance','payroll_details','payroll_back_details','expenses','daily_closings','daily_closing_details','closing_errors','audit_logs'];
    $found = [];
    $q = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = 'BASE TABLE'");
    foreach ($required as $table) {
        $q->execute([$table]);
        if ((int)$q->fetchColumn() > 0) $found[] = $table;
    }
    $missing = array_values(array_diff($required, $found));
    if ($missing) { http_response_code(503); echo json_encode(['ok'=>false,'status'=>'schema_incomplete','missing_tables'=>$missing], JSON_UNESCAPED_UNICODE); exit; }
    $storeCount = (int)$db->query('SELECT COUNT(*) FROM stores')->fetchColumn();
    echo json_encode(['ok'=>true,'status'=>$storeCount > 0 ? 'ready' : 'awaiting_first_setup','database'=>'connected','store_count'=>$storeCount], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('POS health check failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok'=>false,'status'=>'database_unavailable']);
}
