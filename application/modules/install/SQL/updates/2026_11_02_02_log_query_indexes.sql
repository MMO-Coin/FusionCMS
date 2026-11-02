-- Apply to the website database. Existing data and equivalent custom indexes are preserved.
SET @log_index_ddl = IF(EXISTS(
    SELECT 1 FROM (
        SELECT INDEX_NAME,
            GROUP_CONCAT(IF(SUB_PART IS NULL, COLUMN_NAME, CONCAT(COLUMN_NAME, '(', SUB_PART, ')')) ORDER BY SEQ_IN_INDEX) AS columns_used,
            GROUP_CONCAT(COALESCE(COLLATION, '?') ORDER BY SEQ_IN_INDEX) AS column_order
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs' AND INDEX_TYPE = 'BTREE'
        GROUP BY INDEX_NAME
    ) indexes_found
    WHERE (columns_used = 'user_id,time,id' OR columns_used LIKE 'user_id,time,id,%')
        AND SUBSTRING_INDEX(SUBSTRING_INDEX(column_order, ',', 3), ',', -2) IN ('A,A', 'D,D')
), 'SELECT 1', 'ALTER TABLE `logs` ADD INDEX `idx_logs_user_time_id` (`user_id`, `time`, `id`)');
PREPARE log_index_stmt FROM @log_index_ddl;
EXECUTE log_index_stmt;
DEALLOCATE PREPARE log_index_stmt;

SET @log_index_ddl = IF(EXISTS(
    SELECT 1 FROM (
        SELECT INDEX_NAME,
            GROUP_CONCAT(IF(SUB_PART IS NULL, COLUMN_NAME, CONCAT(COLUMN_NAME, '(', SUB_PART, ')')) ORDER BY SEQ_IN_INDEX) AS columns_used,
            GROUP_CONCAT(COALESCE(COLLATION, '?') ORDER BY SEQ_IN_INDEX) AS column_order
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs' AND INDEX_TYPE = 'BTREE'
        GROUP BY INDEX_NAME
    ) indexes_found
    WHERE (columns_used = 'time,id' OR columns_used LIKE 'time,id,%')
        AND SUBSTRING_INDEX(column_order, ',', 2) IN ('A,A', 'D,D')
), 'SELECT 1', 'ALTER TABLE `logs` ADD INDEX `idx_logs_time_id` (`time`, `id`)');
PREPARE log_index_stmt FROM @log_index_ddl;
EXECUTE log_index_stmt;
DEALLOCATE PREPARE log_index_stmt;

SET @log_index_ddl = IF(EXISTS(
    SELECT 1 FROM (
        SELECT INDEX_NAME,
            GROUP_CONCAT(IF(SUB_PART IS NULL, COLUMN_NAME, CONCAT(COLUMN_NAME, '(', SUB_PART, ')')) ORDER BY SEQ_IN_INDEX) AS columns_used
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND INDEX_TYPE = 'BTREE'
        GROUP BY INDEX_NAME
    ) indexes_found
    WHERE columns_used = 'article_id,id' OR columns_used LIKE 'article_id,id,%'
), 'SELECT 1', 'ALTER TABLE `comments` ADD INDEX `idx_comments_article_id` (`article_id`, `id`)');
PREPARE log_index_stmt FROM @log_index_ddl;
EXECUTE log_index_stmt;
DEALLOCATE PREPARE log_index_stmt;
