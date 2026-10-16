import { defineConfig, devices } from '@playwright/test';

// QUICKCHAT_URL: a page on the host site that renders the widget (local DDEV or staging).
export default defineConfig({
  testDir: './tests/playwright',
  reporter: 'list',
  use: { ...devices['Desktop Chrome'], baseURL: process.env.QUICKCHAT_URL, ignoreHTTPSErrors: true },
});
