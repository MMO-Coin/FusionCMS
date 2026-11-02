-- Apply to the website database. Reuses equivalent full-column indexes and preserves rows.
SET @list_index_ddl = IF(EXISTS(
    SELECT 1 FROM (
        SELECT INDEX_NAME,
            GROUP_CONCAT(IF(SUB_PART IS NULL, COLUMN_NAME, CONCAT(COLUMN_NAME, '(', SUB_PART, ')')) ORDER BY SEQ_IN_INDEX) AS columns_used,
            GROUP_CONCAT(COALESCE(COLLATION, '?') ORDER BY SEQ_IN_INDEX) AS column_order
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_log' AND INDEX_TYPE = 'BTREE'
        GROUP BY INDEX_NAME
    ) indexes_found
    WHERE (columns_used = 'completed,timestamp,id' OR columns_used LIKE 'completed,timestamp,id,%')
        AND SUBSTRING_INDEX(SUBSTRING_INDEX(column_order, ',', 3), ',', -2) IN ('A,A', 'D,D')
), 'SELECT 1', 'ALTER TABLE `order_log` ADD INDEX `idx_orders_status_time_id` (`completed`, `timestamp`, `id`)');
PREPARE list_index_stmt FROM @list_index_ddl;
EXECUTE list_index_stmt;
DEALLOCATE PREPARE list_index_stmt;

SET @list_index_ddl = IF(EXISTS(
    SELECT 1 FROM (
        SELECT INDEX_NAME,
            GROUP_CONCAT(IF(SUB_PART IS NULL, COLUMN_NAME, CONCAT(COLUMN_NAME, '(', SUB_PART, ')')) ORDER BY SEQ_IN_INDEX) AS columns_used,
            GROUP_CONCAT(COALESCE(COLLATION, '?') ORDER BY SEQ_IN_INDEX) AS column_order
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_log' AND INDEX_TYPE = 'BTREE'
        GROUP BY INDEX_NAME
    ) indexes_found
    WHERE (columns_used = 'completed,user_id,timestamp,id' OR columns_used LIKE 'completed,user_id,timestamp,id,%')
        AND SUBSTRING_INDEX(SUBSTRING_INDEX(column_order, ',', 4), ',', -2) IN ('A,A', 'D,D')
), 'SELECT 1', 'ALTER TABLE `order_log` ADD INDEX `idx_orders_user_time_id` (`completed`, `user_id`, `timestamp`, `id`)');
PREPARE list_index_stmt FROM @list_index_ddl;
EXECUTE list_index_stmt;
DEALLOCATE PREPARE list_index_stmt;

SET @list_index_ddl = IF(EXISTS(
    SELECT 1 FROM (
        SELECT INDEX_NAME,
            GROUP_CONCAT(IF(SUB_PART IS NULL, COLUMN_NAME, CONCAT(COLUMN_NAME, '(', SUB_PART, ')')) ORDER BY SEQ_IN_INDEX) AS columns_used,
            GROUP_CONCAT(COALESCE(COLLATION, '?') ORDER BY SEQ_IN_INDEX) AS column_order
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gm_log' AND INDEX_TYPE = 'BTREE'
        GROUP BY INDEX_NAME
    ) indexes_found
    WHERE (columns_used = 'time,id' OR columns_used LIKE 'time,id,%')
        AND SUBSTRING_INDEX(SUBSTRING_INDEX(column_order, ',', 2), ',', -2) IN ('A,A', 'D,D')
), 'SELECT 1', 'ALTER TABLE `gm_log` ADD INDEX `idx_gm_time_id` (`time`, `id`)');
PREPARE list_index_stmt FROM @list_index_ddl;
EXECUTE list_index_stmt;
DEALLOCATE PREPARE list_index_stmt;

SET @list_index_ddl = IF(EXISTS(
    SELECT 1 FROM (
        SELECT INDEX_NAME,
            GROUP_CONCAT(IF(SUB_PART IS NULL, COLUMN_NAME, CONCAT(COLUMN_NAME, '(', SUB_PART, ')')) ORDER BY SEQ_IN_INDEX) AS columns_used,
            GROUP_CONCAT(COALESCE(COLLATION, '?') ORDER BY SEQ_IN_INDEX) AS column_order
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gm_log' AND INDEX_TYPE = 'BTREE'
        GROUP BY INDEX_NAME
    ) indexes_found
    WHERE (columns_used = 'gm_id,time,id' OR columns_used LIKE 'gm_id,time,id,%')
        AND SUBSTRING_INDEX(SUBSTRING_INDEX(column_order, ',', 3), ',', -2) IN ('A,A', 'D,D')
), 'SELECT 1', 'ALTER TABLE `gm_log` ADD INDEX `idx_gm_actor_time_id` (`gm_id`, `time`, `id`)');
PREPARE list_index_stmt FROM @list_index_ddl;
EXECUTE list_index_stmt;
DEALLOCATE PREPARE list_index_stmt;
