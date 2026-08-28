import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// La super administración de la plataforma. Cross-tenant.
export default defineConfig(sharedConfig({ base: '/', budgetKb: 220 }))
