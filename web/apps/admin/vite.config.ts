import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// Platform administration. Cross-tenant.
export default defineConfig(sharedConfig({ base: '/', budgetKb: 220 }))
