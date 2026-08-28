import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// La caja del mostrador. Táctil, botones grandes, una sola acción primaria.
export default defineConfig(sharedConfig({ base: '/caja/', budgetKb: 180 }))
