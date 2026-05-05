-- MyInvoice.cz / NstyInvoice fork — ON DELETE CASCADE pro parent_invoice_id
--
-- Bez CASCADE jakákoliv `cancellation` nebo `credit_note` blokuje smazání původní
-- faktury (FK violation). Po fork-feat smazání vystavených/stornovaných faktur
-- chceme, aby smazání rodičovské faktury automaticky odstranilo i navázané doklady
-- (storno, dobropis, jejich items, work_reports — vše už CASCADE má).
--
-- Smazání samotného storno/dobropisu rodiče nemaže (FK je směrem child → parent).

SET NAMES utf8mb4;

ALTER TABLE invoices DROP FOREIGN KEY fk_inv_parent;
ALTER TABLE invoices ADD CONSTRAINT fk_inv_parent
  FOREIGN KEY (parent_invoice_id) REFERENCES invoices(id)
  ON DELETE CASCADE;
