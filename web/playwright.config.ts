import { defineConfig, devices } from '@playwright/test'

/**
 * Browser tests for the Notes SPA.
 *
 * These drive the real application — the real editor, the real offline queue,
 * the real keyboard handling — against a **stubbed API**, not against the live
 * AICOUNTLY portal.
 *
 * That seam is deliberate. Signing in means a redirect to my.aicountly.com and
 * a real user's credentials; a browser suite that depends on that would depend
 * on a third-party service being up and on a test identity existing in
 * production. The alternative some codebases reach for — a "test mode" that
 * skips authentication — is worse: an auth bypass shipped in production code is
 * a vulnerability waiting for someone to find the flag.
 *
 * So the client is tested here with the network intercepted, and the server is
 * tested by `server-php/tests` driving the real router against a real
 * PostgreSQL database. Between the two, the only thing not covered end to end
 * is the portal handshake itself, which docs/auth/ describes how to verify by
 * hand in each environment.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? 'line' : 'list',

  use: {
    baseURL: 'http://localhost:5173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },

  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],

  webServer: {
    command: 'npm run dev -- --port 5173',
    url: 'http://localhost:5173',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
})
