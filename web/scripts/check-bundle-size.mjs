#!/usr/bin/env node
/**
 * El guardián del presupuesto de arranque.
 *
 * Rompe el build si una aplicación se pasa. No es una métrica bonita: la
 * mayoría de estos negocios corren la caja en una PC vieja de mostrador y la
 * cocina en una tablet barata, muchas veces con una conexión mala. Cada 100 KB
 * de más son segundos de pantalla en blanco con un cliente esperando.
 *
 * Subir un presupuesto es una decisión de producto, no de build.
 */

import { gzipSync } from 'node:zlib'
import { readFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))

const APPS = [
  { name: 'portal', label: 'Portal del cliente', budgetKb: 180 },
  { name: 'caja', label: 'Caja', budgetKb: 180 },
  { name: 'panel', label: 'Panel del dueño', budgetKb: 220 },
  { name: 'kds', label: 'Pantalla de cocina', budgetKb: 120 },
  { name: 'admin', label: 'Super administración', budgetKb: 220 },
]

/**
 * Lo que de verdad retrasa el arranque: el chunk de entrada, su CSS, y todo lo
 * que importa de forma estática (recursivamente).
 *
 * Lo que se carga bajo demanda por code-splitting NO cuenta, porque no está en
 * el camino crítico. Contarlo penalizaría justo la técnica que queremos usar.
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
