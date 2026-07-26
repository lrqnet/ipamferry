import { expect, test } from "@playwright/test";

test("claims an installation and migrates baseline and expanded inventories", async ({
  page,
}) => {
  test.setTimeout(600_000);
  const token = process.env.IPAMFERRY_INSTALLATION_TOKEN;
  if (!token)
    throw new Error("IPAMFERRY_INSTALLATION_TOKEN is required for E2E.");

  await page.goto("/setup");
  await page.getByRole("button", { name: "Change language" }).click();
  await page.getByRole("menuitem", { name: "Português (Brasil)" }).click();
  await expect(
    page.getByRole("heading", { name: "Reivindicar instalação" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Alterar idioma" }).click();
  await page.getByRole("menuitem", { name: "Español" }).click();
  await expect(
    page.getByRole("heading", { name: "Reclamar instalación" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Cambiar idioma" }).click();
  await page.getByRole("menuitem", { name: "English" }).click();
  await expect(
    page.getByRole("heading", { name: "Claim installation" }),
  ).toBeVisible();
  await page.getByLabel("Token").fill(token);
  await page.getByLabel("Name").fill("E2E Owner");
  await page.getByLabel("Email").fill("owner@example.test");
  await page
    .getByLabel("Password", { exact: true })
    .fill("CorrectHorseBattery1!");
  await page.getByLabel("Confirm password").fill("CorrectHorseBattery1!");
  await page.getByRole("button", { name: "Create owner" }).click();
  await expect(
    page.getByRole("heading", { name: "Migration projects" }),
  ).toBeVisible();

  await page.getByRole("link", { name: "New project" }).click();
  await page.getByLabel("Name").fill("E2E phpIPAM");
  await page.getByLabel("Source").selectOption("dump");
  await Promise.all([
    page.waitForURL(/\/projects\/\d+$/, { timeout: 30_000 }),
    page.getByRole("button", { name: "Create" }).click(),
  ]);
  await expect(page.getByRole("heading", { name: "E2E phpIPAM" })).toBeVisible({
    timeout: 30_000,
  });
  await expect(page.getByText("Import mysqldump")).toBeVisible();

  await expect(
    page.getByLabel("Use the internal disposable NetBox sandbox"),
  ).toBeVisible();
  await page.getByLabel("Use the internal disposable NetBox sandbox").check();
  await page
    .getByLabel("SQL dump")
    .setInputFiles("tests/Fixtures/phpipam-small.sql");
  await page.getByRole("button", { name: "Read dump" }).click();
  await expect(page.getByText("vrfs", { exact: true })).toBeVisible({
    timeout: 180_000,
  });

  await page.getByRole("link", { name: "Open Mapping Studio" }).click();
  await expect(
    page.getByRole("heading", { name: "Mapping Studio" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Objects" }).click();
  await expect(
    page.getByRole("heading", { name: "Object policies" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Fields" }).click();
  await expect(
    page.getByRole("heading", { name: "Field rules" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Relations" }).click();
  await expect(
    page.getByRole("heading", { name: "Relation rules" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "JSON Expert" }).click();
  await expect(
    page.getByText("Edit the canonical English schema."),
  ).toBeVisible();
  await page.getByRole("button", { name: "Preview" }).click();
  await page.getByRole("button", { name: "Run preview" }).click();
  await expect(page.getByText("Completed")).toBeVisible({ timeout: 30_000 });
  await page.getByRole("link", { name: "Back to project" }).click();

  await page.getByRole("button", { name: "Generate plan" }).click();
  await expect(page.getByText("1 actions, 0 conflicts")).toBeVisible({
    timeout: 30_000,
  });

  await page
    .getByLabel("I reviewed the diff and approve this exact fingerprint")
    .check();
  await page.getByRole("button", { name: "Approve plan" }).click();
  await page.getByLabel("I confirm applying this exact approved plan").check();
  await page.getByRole("button", { name: "Apply through API" }).click();
  await expect(page.getByText(/Execution #\d+: Applied/)).toBeVisible({
    timeout: 30_000,
  });

  await page.getByRole("button", { name: "Run verification" }).click();
  await expect(page.getByText(/Execution #\d+: Verified/)).toBeVisible({
    timeout: 30_000,
  });

  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("link", { name: "Download audit bundle" }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toMatch(
    /^ipamferry-project-\d+-plan-\d+\.zip$/,
  );

  await page.goto("/projects");
  await expect(
    page.getByRole("heading", { name: "New project" }),
  ).toBeVisible();
  await page.getByLabel("Name").fill("E2E expanded migration");
  await page.getByLabel("Source").selectOption("dump");
  await Promise.all([
    page.waitForURL(/\/projects\/\d+$/, { timeout: 30_000 }),
    page.getByRole("button", { name: "Create" }).click(),
  ]);
  await page.getByLabel("Use the internal disposable NetBox sandbox").check();
  await page
    .getByLabel("SQL dump")
    .setInputFiles("tests/Fixtures/phpipam-expanded.sql");
  await page.getByRole("button", { name: "Read dump" }).click();
  await expect(page.getByText("customers", { exact: true })).toBeVisible({
    timeout: 180_000,
  });
  await expect(page.getByText("nat_relations", { exact: true })).toBeVisible();

  await page.getByRole("link", { name: "Open Mapping Studio" }).click();
  await page.getByRole("button", { name: "Accept all" }).click();
  await page.getByRole("button", { name: "Relations" }).click();

  await page.getByLabel("Location classification", { exact: true }).check();
  const locationCard = page
    .getByRole("heading", { name: "Classify phpIPAM locations" })
    .locator("..");
  await locationCard.locator("select").first().selectOption("site");

  await page.getByLabel("Device prerequisites", { exact: true }).check();
  await page
    .getByPlaceholder("Manufacturer", { exact: true })
    .fill("IpamFerry E2E");
  await page
    .getByPlaceholder("Physical device model", { exact: true })
    .fill("Virtual Router");
  await page
    .getByPlaceholder("NetBox interface type", { exact: true })
    .fill("1000base-t");

  await page.getByLabel("Customer contacts", { exact: true }).check();
  await page
    .getByPlaceholder("Contact Role name", { exact: true })
    .fill("Customer");

  await page.getByLabel("ASN / RIR", { exact: true }).check();
  await page.getByPlaceholder("RIR name", { exact: true }).fill("Private");

  await page.getByLabel("Circuit terminations", { exact: true }).check();
  const circuitCard = page
    .getByRole("heading", { name: "Confirmed circuit terminations" })
    .locator("..");
  await circuitCard.getByRole("checkbox").first().check();

  await page.getByLabel("Primary IP", { exact: true }).check();
  await page.getByLabel("Static NAT 1:1", { exact: true }).check();
  const natCard = page
    .getByRole("heading", { name: "Confirm static 1:1 NAT" })
    .locator("..");
  await natCard.getByRole("checkbox").first().check();

  await page.getByRole("button", { name: "Save mapping" }).click();
  await expect(page.getByText("Mapping saved.")).toBeVisible();
  await page.getByRole("button", { name: "Preview" }).click();
  await page.getByRole("button", { name: "Run preview" }).click();
  await expect(page.getByText("Completed")).toBeVisible({ timeout: 30_000 });
  await expect(
    page
      .getByText("Conflicts", { exact: true })
      .locator("..")
      .getByText("0", { exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Back to project" }).click();

  await page.getByRole("button", { name: "Generate plan" }).click();
  await expect(page.getByText(/\d+ actions, 0 conflicts/)).toBeVisible({
    timeout: 30_000,
  });
  await page
    .getByLabel("I reviewed the diff and approve this exact fingerprint")
    .check();
  await page.getByRole("button", { name: "Approve plan" }).click();
  await page.getByLabel("I confirm applying this exact approved plan").check();
  await page.getByRole("button", { name: "Apply through API" }).click();
  await expect(page.getByText(/Execution #\d+: Applied/)).toBeVisible({
    timeout: 90_000,
  });
  await page.getByRole("button", { name: "Run verification" }).click();
  await expect(page.getByText(/Execution #\d+: Verified/)).toBeVisible({
    timeout: 60_000,
  });

  await page.getByRole("button", { name: "Refresh NetBox target" }).click();
  await expect(
    page.getByText(
      "NetBox target refreshed. Generate and approve a new target-specific plan.",
    ),
  ).toBeVisible({ timeout: 180_000 });
  await page.getByRole("button", { name: "Generate plan" }).click();
  await expect(
    page.getByLabel("I reviewed the diff and approve this exact fingerprint"),
  ).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(/\d+ actions, 0 conflicts/)).toBeVisible({
    timeout: 30_000,
  });
  await page
    .getByLabel("I reviewed the diff and approve this exact fingerprint")
    .check();
  await page.getByRole("button", { name: "Approve plan" }).click();
  await page.getByLabel("I confirm applying this exact approved plan").check();
  await page.getByRole("button", { name: "Apply through API" }).click();
  await expect(page.getByText(/Execution #\d+: Applied/)).toBeVisible({
    timeout: 90_000,
  });
  await page.getByRole("button", { name: "Run verification" }).click();
  await expect(page.getByText(/Execution #\d+: Verified/)).toBeVisible({
    timeout: 60_000,
  });

  const expandedDownloadPromise = page.waitForEvent("download");
  await page.getByRole("link", { name: "Download audit bundle" }).click();
  const expandedDownload = await expandedDownloadPromise;
  expect(expandedDownload.suggestedFilename()).toMatch(
    /^ipamferry-project-\d+-plan-\d+\.zip$/,
  );
});
