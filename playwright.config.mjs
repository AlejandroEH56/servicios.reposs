import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/Browser',
  testMatch: '**/*.spec.mjs',
  workers: 1,
  retries: 0,
  reporter: [['list'], ['junit', { outputFile: `artifacts/browser-${process.env.E2E_ENVIRONMENT ?? 'stage'}.xml` }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
    browserName: 'chromium',
    ignoreHTTPSErrors: false,
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
});
