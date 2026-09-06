import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// The counter till. Touch, big buttons, one primary action.
export default defineConfig(sharedConfig({ base: '/pos/', budgetKb: 180 }))
