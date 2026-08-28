import { defineConfig } from 'vite'
import { sharedConfig } from '../../vite.shared.ts'

// La pantalla de comandas. No navega, no filtra y no busca.
export default defineConfig(sharedConfig({ base: '/cocina/', budgetKb: 120 }))
