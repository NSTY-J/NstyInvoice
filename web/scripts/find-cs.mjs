#!/usr/bin/env node
/**
 * Najde "podezřelé" české texty v .vue souborech, které nejsou v t() / $t() / i18n-t.
 *
 *   node scripts/find-cs.mjs           # vypíše nálezy seskupené po souborech
 *   node scripts/find-cs.mjs --json    # JSON výstup
 *
 * Pravidla:
 *   - Skenuje jen <template> sekce SFC (skript+style ignoruje).
 *   - Hledá: text mezi tagy `>...<`, atributy placeholder/title/aria-label="..."
 *   - Filter: alespoň jedno české diacritic-písmeno NEBO ≥2 česká slova ze stop-listu.
 *   - Přeskočí: text uvnitř {{ ... }} (pravděpodobně už `t()`), uvnitř `t('...')` calls.
 */

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SRC = join(ROOT, 'src')
const argJson = process.argv.includes('--json')

const CZECH_CHARS = /[ěščřžýáíéóúůďťňĚŠČŘŽÝÁÍÉÓÚŮĎŤŇ]/
const CZECH_WORDS = ['nebo', 'pokud', 'jen', 'bez', 'pro', 'při', 'jak', 'kde', 'tak', 'pak',
  'tento', 'tato', 'toto', 'taky', 'svým', 'svého', 'svůj', 'jeho', 'jejich',
  'a', 'i', 'už']

function walk(dir, out = []) {
  for (const e of readdirSync(dir)) {
    const p = join(dir, e)
    if (statSync(p).isDirectory()) walk(p, out)
    else if (e.endsWith('.vue')) out.push(p)
  }
  return out
}

function extractTemplate(src) {
  const m = src.match(/<template[^>]*>([\s\S]*)<\/template>/)
  return m ? m[1] : ''
}

function isLikelyCzech(s) {
  if (!s || s.trim().length < 2) return false
  if (CZECH_CHARS.test(s)) return true
  // ASCII-only — count Czech words
  const words = s.toLowerCase().match(/\b[a-z]+\b/g) || []
  const hits = words.filter(w => CZECH_WORDS.includes(w)).length
  return hits >= 2
}

function isProbablyCode(s) {
  // skip pure HTML/CSS/var-like content
  if (/^[\s\d.,/\-+*&|();:#@%]+$/.test(s)) return true
  if (/^\$\{|^{{|^v-|^:[a-z]/.test(s.trim())) return true
  return false
}

const findings = []
for (const file of walk(SRC)) {
  const src = readFileSync(file, 'utf8')
  const tpl = extractTemplate(src)
  if (!tpl) continue

  const lines = tpl.split('\n')
  // Compute offset: how many lines before <template>
  const beforeTpl = src.slice(0, src.indexOf('<template')).split('\n').length

  // 1) text content between tags: >...<
  //    skip when content contains {{ ... }} entirely OR is inside an attr
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    // match >TEXT<
    const re = />([^<>{}]+?)</g
    let m
    while ((m = re.exec(line))) {
      const text = m[1].trim()
      if (!text || isProbablyCode(text)) continue
      // ignore pure {{ }} — already filtered by [^{}] in regex
      if (isLikelyCzech(text)) {
        findings.push({
          file: relative(ROOT, file),
          line: beforeTpl + i,
          kind: 'text',
          text,
        })
      }
    }

    // 2) attribute strings: placeholder / title / aria-label / alt / label
    const attrRe = /\b(placeholder|title|aria-label|alt)\s*=\s*"([^"]+)"/g
    while ((m = attrRe.exec(line))) {
      const text = m[2].trim()
      if (!text || text.startsWith('{{') || isProbablyCode(text)) continue
      if (isLikelyCzech(text)) {
        findings.push({
          file: relative(ROOT, file),
          line: beforeTpl + i,
          kind: `attr:${m[1]}`,
          text,
        })
      }
    }
  }
}

if (argJson) {
  console.log(JSON.stringify(findings, null, 2))
  process.exit(findings.length > 0 ? 1 : 0)
}

const byFile = {}
for (const f of findings) {
  ;(byFile[f.file] ||= []).push(f)
}

const files = Object.keys(byFile).sort()
if (files.length === 0) {
  console.log('✓ Žádné podezřelé české texty mimo t() nebyly nalezeny.')
  process.exit(0)
}

let total = 0
for (const file of files) {
  const items = byFile[file]
  console.log(`\n\x1b[1;36m${file}\x1b[0m  (${items.length})`)
  for (const it of items) {
    console.log(`  \x1b[33m${String(it.line).padStart(4)}\x1b[0m [${it.kind}] ${it.text}`)
    total++
  }
}
console.log(`\n\x1b[1mCelkem: ${total} výskytů ve ${files.length} souborech\x1b[0m`)
process.exit(1)
