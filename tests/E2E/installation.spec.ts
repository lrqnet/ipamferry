import { expect, test } from '@playwright/test';

test('claims an installation and creates a migration project', async ({ page }) => {
  const token = process.env.IPAMFERRY_INSTALLATION_TOKEN;
  if (!token) throw new Error('IPAMFERRY_INSTALLATION_TOKEN is required for E2E.');

  await page.goto('/setup');
  await page.getByRole('button', { name: 'Change language' }).click();
  await page.getByRole('menuitem', { name: 'Português (Brasil)' }).click();
  await expect(page.getByRole('heading', { name: 'Reivindicar instalação' })).toBeVisible();
  await page.getByRole('button', { name: 'Alterar idioma' }).click();
  await page.getByRole('menuitem', { name: 'Español' }).click();
  await expect(page.getByRole('heading', { name: 'Reclamar instalación' })).toBeVisible();
  await page.getByRole('button', { name: 'Cambiar idioma' }).click();
  await page.getByRole('menuitem', { name: 'English' }).click();
  await expect(page.getByRole('heading', { name: 'Claim installation' })).toBeVisible();
  await page.getByLabel('Token').fill(token);
  await page.getByLabel('Name').fill('E2E Owner');
  await page.getByLabel('Email').fill('owner@example.test');
  await page.getByLabel('Password', { exact: true }).fill('CorrectHorseBattery1!');
  await page.getByLabel('Confirm password').fill('CorrectHorseBattery1!');
  await page.getByRole('button', { name: 'Create owner' }).click();
  await expect(page.getByRole('heading', { name: 'Migration projects' })).toBeVisible();

  await page.getByRole('link', { name: 'New project' }).click();
  await page.getByLabel('Name').fill('E2E phpIPAM');
  await page.getByLabel('Source').selectOption('dump');
  await page.getByRole('button', { name: 'Create' }).click();
  await expect(page.getByRole('heading', { name: 'E2E phpIPAM' })).toBeVisible();
  await expect(page.getByText('Import mysqldump')).toBeVisible();

  await expect(page.getByLabel('Use the internal disposable NetBox sandbox')).toBeVisible();
  await page.getByLabel('Use the internal disposable NetBox sandbox').check();
  await page.getByLabel('SQL dump').setInputFiles('tests/Fixtures/phpipam-small.sql');
  await page.getByRole('button', { name: 'Read dump' }).click();
  await expect(page.getByText('vrfs', { exact: true })).toBeVisible({ timeout: 30_000 });

  await page.getByRole('button', { name: 'Generate plan' }).click();
  await expect(page.getByText('1 actions, 0 conflicts')).toBeVisible({ timeout: 30_000 });

  await page.getByLabel('I reviewed the diff and approve this exact fingerprint').check();
  await page.getByRole('button', { name: 'Approve plan' }).click();
  await page.getByLabel('I confirm applying this exact approved plan').check();
  await page.getByRole('button', { name: 'Apply through API' }).click();
  await expect(page.getByText(/Execution #\d+: Applied/)).toBeVisible({ timeout: 30_000 });

  await page.getByRole('button', { name: 'Run verification' }).click();
  await expect(page.getByText(/Execution #\d+: Verified/)).toBeVisible({ timeout: 30_000 });

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('link', { name: 'Download audit bundle' }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(/^ipamferry-project-\d+-plan-\d+\.zip$/);
});
