#!/usr/bin/env node
/**
 * The startup budget's guard.
 *
 * It breaks the build when an app goes over. Not a vanity metric: most of these
 * businesses run the till on an old counter PC and the kitchen on a cheap
 * tablet, often on a bad connection. Every extra 100 KB is seconds of blank
 * screen with a customer waiting.
 *
 * Raising a budget is a product decision, not a build one.
 */

import { gzipSync } from 'node:zlib'
import { readFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))

const APPS = [
  { name: 'portal', label: 'Portal del cliente', budgetKb: 180 },
  { name: 'pos', label: 'Caja', budgetKb: 180 },
  { name: 'dashboard', label: 'Panel del dueño', budgetKb: 220 },
  { name: 'kds', label: 'Pantalla de cocina', budgetKb: 120 },
  { name: 'admin', label: 'Super administración', budgetKb: 220 },
]

/**
 * What actually delays startup: the entry chunk, its CSS, and everything it
 * imports statically (recursively).
 *
 * What is loaded on demand by code splitting does NOT count, because it is not
 * on the critical path. Counting it would penalise the very technique we want.
 */
function startupFiles(manifest) {
  const entry = Object.values(manifest).find((chunk) => chunk.isEntry)
  if (!entry) throw new Error('El manifiesto no tiene chunk de entrada.')

  const files = new Set()
  const seen = new Set()

  const walk = (chunk) => {
    if (!chunk) return
    files.add(chunk.file)
    for (const css of chunk.css ?? []) files.add(css)
    for (const key of chunk.imports ?? []) {
      if (seen.has(key)) continue
      seen.add(key)
      walk(manifest[key])
    }
  }

  walk(entry)
  return [...files]
}

let failed = false

for (const app of APPS) {
  const distDir = resolve(here, '..', 'apps', app.name, 'dist')

  let manifest
  try {
    manifest = JSON.parse(readFileSync(resolve(distDir, '.vite/manifest.json'), 'utf8'))
  } catch {
    console.error(`✗ ${app.label}: no encuentro el manifiesto. ¿Corriste "npm run build"?`)
    failed = true
    continue
  }

  const files = startupFiles(manifest)
  const sizes = files.map((file) => ({
    file,
    bytes: gzipSync(readFileSync(resolve(distDir, file))).length,
  }))

  const total = sizes.reduce((sum, f) => sum + f.bytes, 0)
  const totalKb = total / 1024
  const pct = Math.round((totalKb / app.budgetKb) * 100)
  const ok = totalKb <= app.budgetKb

  console.log(
    `${ok ? '✓' : '✗'} ${app.label.padEnd(22)} ${totalKb.toFixed(1).padStart(7)} KB gzip de ${app.budgetKb} KB (${pct}%)`,
  )

  if (!ok) {
    failed = true
    console.error('\n  Lo más pesado del arranque:')
    for (const f of sizes.sort((a, b) => b.bytes - a.bytes).slice(0, 6)) {
      console.error(`    ${(f.bytes / 1024).toFixed(1).padStart(7)} KB  ${f.file}`)
    }
    console.error('')
  }
}

if (failed) {
  console.error('\nSubir el presupuesto es una decisión de producto, no de build.\n')
  process.exit(1)
}
