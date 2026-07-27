import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.IPAMFERRY_E2E_BASE_URL ?? 'https://localhost:18444';

export default defineConfig({
  testDir: './tests/E2E',
  timeout: 45_000,
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? [['html'], ['github']] : 'list',
  use: { baseURL, ignoreHTTPSErrors: true, trace: 'off', screenshot: 'off' },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
