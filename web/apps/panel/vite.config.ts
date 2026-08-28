import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// El panel del dueño. Pedidos, catálogo, equipo, reportes.
export default defineConfig(sharedConfig({ base: '/panel/', budgetKb: 220 }))
