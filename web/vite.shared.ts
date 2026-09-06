import react from '@vitejs/plugin-react'
import tailwind from '@tailwindcss/vite'
import type { UserConfig } from 'vite'

interface AppOptions {
  /** Which path it is served under: '/', '/pos/', '/dashboard/', '/kds/'. */
  base: string
  /** Startup budget in KB gzipped. Watched by scripts/check-bundle-size.mjs. */
  budgetKb: number
}

/**
 * The configuration the five apps share.
 *
 * Each `vite.config.ts` is one line. Everything that is not "which path it is
 * served under" and "how much it may weigh" lives here, so an infrastructure
 * decision is taken once rather than five times.
 */
export function sharedConfig({ base, budgetKb }: AppOptions): UserConfig {
  return {
    base,

    plugins: [
      react(),
      // Tailwind 4 as a Vite plugin: no postcss.config, no tailwind.config.js. The
      // theme lives in CSS, in packages/ui/src/theme.css.
      tailwind(),
    ],

    server: {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,

      // Everything comes in through nginx on 8010, so hot module replacement has to
      // connect there rather than to the container's internal 5173.
      hmr: { clientPort: 8010 },

      // A wildcard, like nginx's server_name: a new tenant does not touch this
      // line. Without it, Vite answers "Blocked request" to any subdomain not
      // listed — and listing tenants here would be listing customers.
      allowedHosts: ['.localhost'],

      // Filesystem events do not cross the Docker volume on macOS. Without
      // polling, saving a file reloads nothing and twenty minutes go missing.
      watch: { usePolling: true, interval: 300 },
    },

    build: {
      target: 'es2023',
      cssCodeSplit: true,

      // Read by the budget guard to know what is in the startup path.
      manifest: true,

      // The bundler's own warning measures UNCOMPRESSED, so it cries wolf against
      // a gzip budget. The real guard is scripts/check-bundle-size.mjs, which
      // measures gzip on the startup path only and breaks the build. This is just
      // so the noise does not bury a warning that matters.
      chunkSizeWarningLimit: budgetKb * 3,

      rollupOptions: {
        output: {
          // React separately: it changes little, so the cashier's browser does not
          // re-download it on every deployment.
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
