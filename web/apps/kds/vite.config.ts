import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// The ticket board. It does not navigate, filter or search.
export default defineConfig(sharedConfig({ base: '/kds/', budgetKb: 120 }))
