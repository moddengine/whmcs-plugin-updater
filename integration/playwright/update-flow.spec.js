const fs = require('fs');
const { test, expect } = require('@playwright/test');

const adminUser = 'integration-admin';
const adminPassword = 'IntegrationOnly-ChangeMe-8137!';

test('record complete plugin update and rollback', async ({ browser, baseURL }) => {
  const loginContext = await browser.newContext({ baseURL });
  const loginPage = await loginContext.newPage();
  await loginPage.goto('/admin/login.php');
  await loginPage.locator('input[name="username"]').fill(adminUser);
  await loginPage.locator('input[name="password"]').fill(adminPassword);
  await loginPage.locator('input[type="submit"][value="Login"], button[type="submit"]').click();
  await loginPage.waitForLoadState('networkidle');
  const acceptEula = loginPage.locator('#btnEulaAgree');
  if (await acceptEula.isVisible()) {
    await acceptEula.click();
    await loginPage.waitForLoadState('networkidle');
  }
  await expect(loginPage).not.toHaveURL(/login\.php/);
  await loginPage.goto('/admin/configaddonmods.php');
  const moduleRow = loginPage.locator('tr').filter({ hasText: 'Plugin Updater' }).first();
  const activate = moduleRow.locator('input[value="Activate"]');
  if (await activate.isEnabled()) {
    loginPage.once('dialog', dialog => dialog.accept());
    await activate.click();
    await loginPage.waitForLoadState('networkidle');
  }
  const storageState = await loginContext.storageState();
  await loginContext.close();

  fs.mkdirSync('/artifacts', { recursive: true });
  const context = await browser.newContext({
    baseURL,
    storageState,
    viewport: { width: 1440, height: 900 },
    recordVideo: { dir: '/tmp/playwright-videos', size: { width: 1440, height: 900 } },
  });
  const page = await context.newPage();
  const video = page.video();

  await page.goto('/admin/addonmodules.php?module=pluginupdater');
  await expect(page.getByRole('heading', { name: 'Plugin Updater', level: 2 })).toBeVisible();
  await expect(page.getByText('addon:playwrightfixture 1.0.0')).toBeVisible();
  await page.waitForTimeout(1000);

  await page.getByRole('button', { name: 'Check now' }).click();
  await expect(page.getByText('acme/playwright-fixture: checked')).toBeVisible();
  await expect(page.getByText('1.1.0', { exact: true })).toBeVisible();
  await page.getByText('Release notes', { exact: true }).click();
  await expect(page.getByText(/Playwright demo: secure full-tree update/)).toBeVisible();
  await page.waitForTimeout(1200);

  await page.getByRole('button', { name: 'Run pre-flight' }).click();
  await expect(page.getByText('acme/playwright-fixture: pre-flight passed')).toBeVisible();
  await page.waitForTimeout(1000);

  const updateForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Update to 1.1.0' }) });
  await updateForm.getByRole('checkbox').all().then(boxes => Promise.all(boxes.map(box => box.check())));
  await updateForm.getByRole('button', { name: 'Update to 1.1.0' }).click();
  await expect(page.getByText('acme/playwright-fixture: update completed')).toBeVisible();
  await expect(page.getByText('addon:playwrightfixture 1.1.0')).toBeVisible();
  await page.waitForTimeout(1400);

  const rollbackForm = page.locator('form').filter({ has: page.getByRole('button', { name: 'Roll back' }) });
  await rollbackForm.getByRole('checkbox').all().then(boxes => Promise.all(boxes.map(box => box.check())));
  await rollbackForm.getByRole('button', { name: 'Roll back' }).click();
  await expect(page.getByText('acme/playwright-fixture: rollback completed')).toBeVisible();
  await expect(page.getByText('addon:playwrightfixture 1.0.0')).toBeVisible();
  await page.waitForTimeout(1400);

  await page.close();
  await context.close();
  await video.saveAs(`/artifacts/whmcs-plugin-update-demo-${process.env.WHMCS_VERSION || 'unknown'}.webm`);
});
