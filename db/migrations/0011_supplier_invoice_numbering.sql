-- MyInvoice.cz — Per-supplier konfigurace formátu čísla faktury
-- (NstyInvoice fork, feat: ruční nastavení číselné řady)
--
-- Přidává:
--   * invoice_number_format       VARCHAR(60) — template pro `invoice` (např. 'JD{YYYY}-{CC}')
--   * proforma_number_format      VARCHAR(60) — template pro proforma
--   * credit_note_number_format   VARCHAR(60) — template pro dobropis
--   * invoice_number_period       ENUM('year','month','none') — kdy se counter resetuje
--                                  (default 'month' = backwards compatible s globálním cfg)
--
-- Pokud je *_format NULL, generator použije fallback z cfg.varsymbol.templates.{type}.
-- Period 'year' = counter běží v rámci roku, 'month' = v rámci měsíce, 'none' = nikdy se neresetuje.
--
-- Současně rozšiřuje invoice_counters.period z CHAR(6) na VARCHAR(10), aby pojal
-- "2026" (year), "202604" (month) i "ALL" (none) bez ztráty atomicity (PRIMARY KEY zůstává).
--
-- Podporuje fork-only placeholdery: {YYYY},{YY},{MM},{C+} (existující) + nově NIC navíc —
-- prefix se prostě zapisuje rovnou do template stringu (např. 'JD-{YYYY}-{CCC}').

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_number_format     VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro varsymbol (typ invoice). NULL = fallback na cfg.',
  ADD COLUMN IF NOT EXISTS proforma_number_format    VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro varsymbol (typ proforma). NULL = fallback na cfg.',
  ADD COLUMN IF NOT EXISTS credit_note_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro varsymbol (typ credit_note). NULL = fallback na cfg.',
  ADD COLUMN IF NOT EXISTS invoice_number_period     ENUM('year','month','none') NOT NULL DEFAULT 'month'
    COMMENT 'Reset countru: year = 1.1., month = 1. dne v měsíci, none = nikdy.';

-- Rozšiř period column (CHAR(6) -> VARCHAR(10)) pro podporu year/none scope.
-- ALTER bez IF NOT EXISTS support → ošetříme přes information_schema check.
SET @col_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'invoice_counters'
     AND COLUMN_NAME = 'period'
);
SET @sql := IF(@col_type = 'char(6)',
  'ALTER TABLE invoice_counters MODIFY COLUMN period VARCHAR(10) NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
