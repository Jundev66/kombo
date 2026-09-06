import { defineConfig, devices } from '@playwright/test'

const RESOLVER = process.env.E2E_RESOLVER

export default defineConfig({
  testDir: './tests',
  outputDir: './results',

  /*
   * Serial, with a single worker.
   *
   * The tests share the seeded tenants: two running at once collide over a till
   * shift, a module's state or a ticket number, and the failure shows up one
   * time in five. One worker is slower and honest.
   */
  fullyParallel: false,
  workers: 1,

  /*
   * ZERO retries. A retry turns an intermittent test into one that passes — and
   * that therefore nobody fixes. If something fails one time in ten, that IS the
   * bug.
   */
  retries: 0,

  forbidOnly: !!process.env.CI,
  timeout: 30_000,
  expect: { timeout: 10_000 },

  reporter: [['list'], ['html', { open: 'never', outputFolder: 'reports' }]],

  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',

    // The system speaks Venezuelan Spanish and computes hours in Caracas. A test
    // running in UTC would accept tickets with the time shifted.
    locale: 'es-VE',
    timezoneId: 'America/Caracas',

    /*
     * The subdomain WILDCARD inside the container.
     *
     * The same thing nginx's server_name does and what wildcard DNS will do in
     * production: any tenant resolves without being listed. Writing five tenants
     * into `extra_hosts` would be listing customers, and that does not scale.
     */
    launchOptions: RESOLVER ? { args: [`--host-resolver-rules=MAP *.localhost ${RESOLVER}`] } : {},
  },

  // Chromium only: --host-resolver-rules is a Chromium flag, and what sits on
  // these counters is Chrome on an old PC.
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
