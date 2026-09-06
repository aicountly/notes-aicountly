/// <reference types="vitest/config" />

/**
 * Test configuration.
 *
 * A separate file from `vite.config.ts` rather than a `test` key inside it:
 * the app build has no business type-checking against Vitest's config types,
 * and keeping them apart means the dev server config cannot break the suite.
 *
 * `environment: 'jsdom'` because everything worth testing here touches the DOM —
 * a debounce that never fires and a menu that cannot be driven from the keyboard
 * are both invisible to a node-only runner.
 */

import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    // Components import their own stylesheet; the runner only needs the import
    // to resolve, not to produce styles nothing asserts on.
    css: false,
    restoreMocks: true,
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
  },
})
