import { test, expect } from '@playwright/test';

// Runs against any page that renders `{{ craft.quickchat.widget() }}`: QUICKCHAT_URL, else the host suite's baseURL.
// Local/staging only: it leaves an "e2e …" conversation behind.
test('widget is lazy, cookieless and live', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ ignoreHTTPSErrors: true }); // fresh: no host storageState
  const page = await context.newPage();
  const cookieResponses: string[] = [];
  page.on('response', (r) => {
    if (r.url().includes('quickchat') && r.headers()['set-cookie']) cookieResponses.push(r.url());
  });

  await page.goto(process.env.QUICKCHAT_URL || baseURL!);
  const css = page.locator('link[href*="quickchat.css"]');
  await expect(css).toHaveCount(0); // nothing but the launcher until the first click

  await page.locator('.qc-launcher').click();
  await expect(css).toHaveCount(1);
  const dialog = page.locator('.qc-modal');
  await expect(dialog).toBeVisible();

  if (await dialog.locator('a[href^="mailto:"]').count()) {
    test.info().annotations.push({ type: 'note', description: 'Outside office hours: only the email fallback was checked' });
  } else {
    const text = `e2e ${Date.now()}`;
    await dialog.locator('textarea').fill(text);
    await dialog.locator('textarea').press('Enter');
    await expect(dialog.locator('#qc-messages')).toContainText(text, { timeout: 10_000 });
    expect(await page.evaluate(() => localStorage.getItem('quickchat'))).toMatch(/^[a-zA-Z0-9]{32}$/);

    // The conversation survives a reload (token in localStorage, history over SSE)
    await page.reload();
    await page.locator('.qc-launcher').click();
    await expect(dialog.locator('#qc-messages')).toContainText(text, { timeout: 10_000 });
  }

  expect(cookieResponses).toEqual([]);
  await context.close();
});
