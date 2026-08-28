import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// El portal del cliente final. Es lo que ve quien llega por el link de WhatsApp.
export default defineConfig(sharedConfig({ base: '/', budgetKb: 180 }))
