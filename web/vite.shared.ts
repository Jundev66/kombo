import react from '@vitejs/plugin-react'
import tailwind from '@tailwindcss/vite'
import type { UserConfig } from 'vite'

interface AppOptions {
  /** Bajo qué ruta se sirve: '/', '/caja/', '/panel/', '/cocina/'. */
  base: string
  /** Presupuesto de arranque en KB gzip. Lo vigila scripts/check-bundle-size.mjs. */
  budgetKb: number
}

/**
 * La configuración que comparten las cinco aplicaciones.
 *
 * Cada `vite.config.ts` es una línea. Todo lo que no sea "bajo qué ruta se
 * sirve" y "cuánto puede pesar" vive aquí, para que una decisión de
 * infraestructura se tome una vez y no cinco.
 */
export function sharedConfig({ base, budgetKb }: AppOptions): UserConfig {
  return {
    base,

    plugins: [
      react(),
      // Tailwind 4 como plugin de Vite: sin postcss.config, sin
      // tailwind.config.js. El tema vive en CSS, en packages/ui/src/theme.css.
      tailwind(),
    ],

    server: {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,

      // Todo entra por nginx en el 8010, así que el cliente de recambio en
      // caliente tiene que conectarse ahí y no al 5173 interno del contenedor.
      hmr: { clientPort: 8010 },

      // Comodín, igual que el server_name de nginx: un negocio nuevo no toca
      // esta línea. Sin esto, Vite responde "Blocked request" a cualquier
      // subdominio que no esté enumerado — y enumerar negocios aquí sería
      // enumerar clientes.
      allowedHosts: ['.localhost'],

      // Los eventos del sistema de archivos no cruzan el volumen de Docker en
      // macOS. Sin polling, guardar un fichero no recarga nada y se pierden
      // veinte minutos buscando por qué.
      watch: { usePolling: true, interval: 300 },
    },

    build: {
      target: 'es2023',
      cssCodeSplit: true,

      // Lo lee el guardián de presupuesto para saber qué entra en el arranque.
      manifest: true,

      // El aviso propio del bundler mide SIN comprimir, así que con el
      // presupuesto real (gzip) grita en falso. El guardián de verdad es
      // scripts/check-bundle-size.mjs, que mide gzip y sólo el camino de
      // arranque, y que rompe el build. Esto es sólo para que el ruido no tape
      // un aviso que sí importe.
      chunkSizeWarningLimit: budgetKb * 3,

      rollupOptions: {
        output: {
          // React aparte: cambia poco y así el navegador del cajero no vuelve
          // a descargarlo en cada despliegue. En una conexión lenta se nota.
          manualChunks(id: string) {
            if (id.includes('node_modules/react') || id.includes('node_modules/scheduler')) {
              return 'react'
            }
            return undefined
          },
        },
      },
    },
  }
}
