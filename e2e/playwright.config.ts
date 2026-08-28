import { defineConfig, devices } from '@playwright/test'

const RESOLVER = process.env.E2E_RESOLVER

export default defineConfig({
  testDir: './tests',
  outputDir: './resultados',

  /*
   * En serie y con un solo worker.
   *
   * Las pruebas comparten los negocios sembrados: dos corriendo a la vez se
   * pisan el turno de caja, el estado de un módulo o el número de comanda, y
   * el fallo aparece una de cada cinco veces. Un worker es más lento y es
   * honesto.
   */
  fullyParallel: false,
  workers: 1,

  /*
   * CERO reintentos.
   *
   * Un reintento convierte una prueba intermitente en una que pasa —y que por
   * tanto nadie arregla—. Si algo falla una de cada diez veces, eso ES el bug.
   */
  retries: 0,

  forbidOnly: !!process.env.CI,
  timeout: 30_000,
  expect: { timeout: 10_000 },

  reporter: [['list'], ['html', { open: 'never', outputFolder: 'reportes' }]],

  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',

    // El sistema habla español de Venezuela y calcula horas en Caracas. Una
    // prueba que corriera en UTC daría por buenas comandas con la hora corrida.
    locale: 'es-VE',
    timezoneId: 'America/Caracas',

    /*
     * El COMODÍN de subdominios dentro del contenedor.
     *
     * Es lo mismo que hace el server_name de nginx y lo que hará el DNS
     * comodín en producción: cualquier negocio resuelve sin enumerarlo. Si
     * algún día te ves escribiendo cinco negocios en `extra_hosts`, estás
     * enumerando clientes y eso no escala.
     */
    launchOptions: RESOLVER ? { args: [`--host-resolver-rules=MAP *.localhost ${RESOLVER}`] } : {},
  },

  // Sólo Chromium: --host-resolver-rules es de Chromium, y lo que hay en el
  // mostrador de estos negocios es Chrome sobre una PC vieja.
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
