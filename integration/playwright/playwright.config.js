const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  outputDir: `/artifacts/test-results/${process.env.WHMCS_VERSION || 'unknown'}`,
  reporter: 'line',
  timeout: 90_000,
  workers: 1,
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost',
    launchOptions: {
      slowMo: 250,
      args: ['--host-resolver-rules=MAP localhost 172.24.0.3'],
    },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
