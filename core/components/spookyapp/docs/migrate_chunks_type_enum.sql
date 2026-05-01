-- ================================================================
-- Migration: fix spookyapp_chunks table
-- 1. Replace ENUM with VARCHAR(50) to match xPDO model and code
-- 2. Drop chunk_code column (no longer used — chunks render via
--    Fenom templates, data stored in the `data` JSON column)
-- ================================================================

-- Fix `type` column: change from ENUM to VARCHAR so that all
-- code-level types (movie, tv, person, product, game, device,
-- sport, football, sports, …) are accepted without truncation.
ALTER TABLE `sitespk_spookyapp_chunks`
  MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'movie'
    COMMENT 'Тип контента: movie, tv, person, product, game, device, sport, …';

-- Drop chunk_code column (no longer populated or read by the app).
-- HTML is now generated dynamically via Fenom chunk templates.
-- Remove the next line if you want to keep the column as an archive.
ALTER TABLE `sitespk_spookyapp_chunks`
  DROP COLUMN `chunk_code`;

-- Verify result
SHOW COLUMNS FROM `sitespk_spookyapp_chunks`;
