-- SpookyApp Topics Table Migration
-- Run this script to add missing columns to the spookyapp_topics table.
-- Adjust the table prefix if different from ''.
-- Safe to run multiple times (uses IF NOT EXISTS logic via PROCEDURE).

SET @table = (
    SELECT CONCAT(
        (SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_VARIABLES WHERE VARIABLE_NAME = 'table_prefix'),
        'spookyapp_topics'
    )
);

-- ──────────────────────────────────────────────────────────
-- Fallback: use literal prefix from MODX config
-- Change '' to your actual table prefix if needed
-- ──────────────────────────────────────────────────────────

-- Add topic_id column (unique identifier like 'tmdb_movie_12345')
ALTER TABLE `_spookyapp_topics`
    ADD COLUMN IF NOT EXISTS `topic_id` VARCHAR(64) NULL DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=New,1=Approved,2=InProgress,3=Published,4=Rejected,5=Archived' AFTER `cached_at`,
    ADD COLUMN IF NOT EXISTS `assigned_to` INT(11) NOT NULL DEFAULT 0 COMMENT 'MODX user ID' AFTER `status`,
    ADD COLUMN IF NOT EXISTS `notes` TEXT NULL DEFAULT NULL COMMENT 'Editor notes' AFTER `assigned_to`,
    ADD COLUMN IF NOT EXISTS `created_at` DATETIME NULL DEFAULT NULL AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL DEFAULT NULL AFTER `created_at`;

-- Add unique index on topic_id if not exists
-- (DROP + CREATE is safer across MySQL versions than IF NOT EXISTS for indexes)
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = '_spookyapp_topics'
      AND index_name = 'topic_id_unique'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `_spookyapp_topics` ADD UNIQUE INDEX `topic_id_unique` (`topic_id`)',
    'SELECT ''topic_id_unique index already exists'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add status index if not exists
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = '_spookyapp_topics'
      AND index_name = 'status_idx'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `_spookyapp_topics` ADD INDEX `status_idx` (`status`)',
    'SELECT ''status_idx already exists'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add created_at index if not exists
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name = '_spookyapp_topics'
      AND index_name = 'created_at_idx'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `_spookyapp_topics` ADD INDEX `created_at_idx` (`created_at`)',
    'SELECT ''created_at_idx already exists'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Populate created_at for existing rows that have NULL
UPDATE `_spookyapp_topics`
SET `created_at` = NOW()
WHERE `created_at` IS NULL;

-- Verify
SELECT
    COUNT(*) AS total_rows,
    SUM(topic_id IS NOT NULL) AS rows_with_topic_id,
    SUM(status = 0) AS new_status_count
FROM `_spookyapp_topics`;
