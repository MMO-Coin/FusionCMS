<?php

// Included only by the guarded disposable MySQL test in next_batch_test.php.
if (!isset($db, $admin, $schema, $nonce)) exit(1);
$admin->query('DELETE FROM order_log'); $admin->query('DELETE FROM gm_log');
$orders = []; $history = []; $now = time();
for ($id = 1; $id <= 12000; $id++) {
    $status = $id % 2; $actor = $id % 100 + 1; $type = $id % 3 === 0 ? 'characters' : 'account'; $timestamp = $now - (12000 - $id) * 60;
    $orders[] = "($id,$status,$actor,1,2,'[]',$timestamp)";
    $history[] = "($id,'test','$type',1,$actor,1,'127.0.0.1',$timestamp)";
}
foreach (array_chunk($orders, 500) as $batch) $admin->query('INSERT INTO order_log VALUES ' . implode(',', $batch));
foreach (array_chunk($history, 500) as $batch) $admin->query('INSERT INTO gm_log VALUES ' . implode(',', $batch));
$queries = [
    'Order status page' => ['SELECT * FROM order_log WHERE completed=0 AND timestamp>' . ($now - 7 * 86400) . ' ORDER BY timestamp DESC,id DESC LIMIT 25', 'idx_orders_status_time_id'],
    'Order account page' => ['SELECT * FROM order_log WHERE completed=0 AND user_id=1 ORDER BY timestamp DESC,id DESC LIMIT 25', 'idx_orders_user_time_id'],
    'GM history page' => ['SELECT * FROM gm_log ORDER BY time DESC,id DESC LIMIT 25', 'idx_gm_time_id'],
    'GM actor page' => ['SELECT * FROM gm_log WHERE gm_id=1 ORDER BY time DESC,id DESC LIMIT 25', 'idx_gm_actor_time_id'],
];
$before = []; foreach ($queries as $name => [$sql]) $before[$name] = $admin->query('EXPLAIN ' . $sql)->fetch_assoc();
$migration = file_get_contents(__DIR__ . '/../application/modules/install/SQL/updates/2026_11_02_03_list_query_indexes.sql');
$apply = static function() use ($admin, $migration): void { $admin->multi_query($migration); do { if ($result = $admin->store_result()) $result->free(); } while ($admin->more_results() && $admin->next_result()); };
$apply();
$admin->query('ANALYZE TABLE order_log'); $admin->query('ANALYZE TABLE gm_log');
foreach ($queries as $name => [$sql, $index]) {
    $after = $admin->query('EXPLAIN ' . $sql)->fetch_assoc();
    checkBatch($after['key'] === $index && !str_contains((string) $after['Extra'], 'filesort'), $name . ' uses its verified index without filesort');
    echo 'PLAN: ' . $name . ': ' . ($before[$name]['key'] ?? 'none') . ' -> ' . $after['key'] . "\n";
}
$apply();
checkBatch((int) $admin->query('SELECT COUNT(*) total FROM order_log')->fetch_assoc()['total'] === 12000, 'Index migration reruns preserve all orders');
$admin->query('ALTER TABLE order_log DROP INDEX idx_orders_status_time_id, ADD INDEX custom_orders_status (completed,timestamp,id)');
$apply();
checkBatch((int) $admin->query("SELECT COUNT(*) total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_log' AND INDEX_NAME='idx_orders_status_time_id'")->fetch_assoc()['total'] === 0, 'Equivalent custom indexes are reused');
