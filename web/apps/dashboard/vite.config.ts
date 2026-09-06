import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// The owner's dashboard. Orders, catalog, team, reports.
export default defineConfig(sharedConfig({ base: '/dashboard/', budgetKb: 220 }))
