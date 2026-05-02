#!/usr/bin/env node
/**
 * Hromadně nahradí známé české texty v .vue souborech za t() volání.
 *
 *   node scripts/fix-cs.mjs           # dry-run, ukáže co by se změnilo
 *   node scripts/fix-cs.mjs --apply   # zapíše změny
 *
 * Nedělá magii — jen mechanické string-replace pro patterny v MAPPINGS.
 * Pokud soubor nemá `useI18n`, dopíše import + setup do <script setup>.
 */

import { readdirSync, readFileSync, writeFileSync, statSync } from 'node:fs'
import { join, dirname, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SRC = join(ROOT, 'src')
const APPLY = process.argv.includes('--apply')

// Mapování: text mezi tagy → klíč v i18n. Generuje se >text< → >{{ t('key') }}<.
// Pořadí záleží — nejspecifičtější napřed (kvůli partial matchům).
const TEXT_MAPPINGS = [
  ['Načítám data…',                     'dashboard.loading_data'],
  ['Načítám…',                          'common.loading'],
  ['Vítej v MyInvoice.cz!',             'dashboard.welcome'],
  ['Vytvořit prvního',                  'client.create_first'],
  ['Vystavit první',                    'invoice.issue_first'],
  ['Editace vystavené faktury (admin)', 'invoice.edit_issued_warning'],
  ['Předchozí zůstatek',                'bank.prev_balance'],
  ['Nový zůstatek',                     'bank.curr_balance'],
  ['Příchozí celkem',                   'bank.credit_total'],
  ['Odchozí celkem',                    'bank.debit_total'],
  ['Ručně spárovat',                    'bank.manual_match_title'],
  ['Výkaz víceprací',                   'invoice.work_report'],
  ['+ Přidat řádek',                    'invoice.wr_add_row'],
  ['Klient · Zakázka',                  'invoice.client_project'],
  ['✕ Zrušit datum',                    'invoice.clear_date_filter'],
  ['Hodinová sazba',                    'project.hourly_rate'],
  ['Fakturační emaily',                 'project.billing_emails'],
  ['Č. zakázky',                        'project.project_number'],
  ['Č. smlouvy',                        'project.contract_number'],
  ['Odečet zálohy',                     'invoice.totals.advance_deduction'],
  ['K úhradě',                          'invoice.amount_to_pay'],
  ['letošní zaplacené',                 'dashboard.this_year_paid'],
  ['Účet',                              'bank.account'],
  ['Zůstatek',                          'bank.balance'],
  ['Spárováno',                         'bank.matched'],
  ['Částka',                            'bank.amount'],
  ['archivováno',                       'common.archived'],
  ['Zakázky',                           'nav.projects'],
  ['Zrušit',                            'common.cancel'],
  ['Měna',                              'common.currency'],
  ['Název',                             'project.name'],
  ['Číslo',                             'project.number'],
  ['Množ.',                             'invoice.items_table.qty'],
  ['Roční',                             'common.yearly'],
  ['Měsíční',                           'common.monthly'],
  ['Rozpočty',                          'common.budgets'],
  ['Poznámka',                          'project.note'],
  ['Podíl',                             'common.share'],
  ['IČ',                                'common.ic'],
  ['DIČ',                               'common.dic'],
  ['Nastavení',                         'nav.settings'],
  ['Čeština',                           ''],  // value will be left as-is (it's the EN: 'Čeština' option label)
]

// Atributy: [(attr, oldValue, newExpression)]
// newExpression begins with `:${attr}=` (Vue binding).
const ATTR_MAPPINGS = [
  ['placeholder', 'Název výkazu (např. Vícepráce 5/2026)', "t('invoice.wr_title')"],
  ['placeholder', 'např. 6',                              "'6'"],
  ['title',       'Čeština',                              "'Čeština'"],
  ['title',       'Přihlášení',                           "t('auth.login_title')"],
  ['title',       'Jediný aktivní admin — nelze deaktivovat', "t('users.is_last_admin_lock')"],
  ['aria-label',  'Čeština',                              "'Čeština'"],
]

function walk(dir, out = []) {
  for (const e of readdirSync(dir)) {
    const p = join(dir, e)
    if (statSync(p).isDirectory()) walk(p, out)
    else if (e.endsWith('.vue')) out.push(p)
  }
  return out
}

function ensureI18n(src) {
  if (/useI18n/.test(src) || !src.includes('<script setup')) return src
  // Vlož import + const za první import řádek nebo na začátek <script setup>
  const setupIdx = src.indexOf('<script setup')
  const blockEnd = src.indexOf('>', setupIdx) + 1
  const insert =
    "\nimport { useI18n } from 'vue-i18n'\n" +
    "const { t } = useI18n()\n"
  return src.slice(0, blockEnd) + insert + src.slice(blockEnd)
}

const stats = { files: 0, changes: 0, byKind: {} }

for (const file of walk(SRC)) {
  let src = readFileSync(file, 'utf8')
  const orig = src
  let fileChanges = 0

  for (const [text, key] of TEXT_MAPPINGS) {
    if (!key) continue
    const escaped = text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    // Match >TEXT< or >TEXT  < (allow surrounding whitespace inside tags)
    const re = new RegExp(`>\\s*${escaped}\\s*<`, 'g')
    const replacement = `>{{ t('${key}') }}<`
    const before = src
    src = src.replace(re, replacement)
    const n = (before.match(re) || []).length
    if (n) {
      fileChanges += n
      stats.byKind[key] = (stats.byKind[key] || 0) + n
    }
  }

  for (const [attr, oldVal, newExpr] of ATTR_MAPPINGS) {
    const escaped = oldVal.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const re = new RegExp(`\\b${attr}="${escaped}"`, 'g')
    const replacement = `:${attr}="${newExpr}"`
    const n = (src.match(re) || []).length
    if (n) {
      src = src.replace(re, replacement)
      fileChanges += n
      stats.byKind[`@${attr}`] = (stats.byKind[`@${attr}`] || 0) + n
    }
  }

  if (fileChanges > 0) {
    src = ensureI18n(src)
    stats.files++
    stats.changes += fileChanges
    if (APPLY) writeFileSync(file, src, 'utf8')
    console.log(`${APPLY ? '✓' : '?'} ${relative(ROOT, file)}  (${fileChanges})`)
  }
}

console.log(`\n${APPLY ? 'Aplikováno' : 'Dry-run'}: ${stats.changes} změn ve ${stats.files} souborech`)
console.log('Po klíčích:')
for (const [k, n] of Object.entries(stats.byKind).sort((a, b) => b[1] - a[1])) {
  console.log(`  ${n.toString().padStart(3)} × ${k}`)
}
if (!APPLY) console.log('\nSpusť s --apply pro zápis.')
