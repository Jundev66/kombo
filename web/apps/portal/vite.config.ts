import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// The customer portal. What somebody arriving from a WhatsApp link sees.
export default defineConfig(sharedConfig({ base: '/', budgetKb: 180 }))
