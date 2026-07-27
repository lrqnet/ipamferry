import { expect, test } from "@playwright/test";

test("migrates a real phpIPAM inventory through the sandbox NetBox API", async ({
  page,
}) => {
  test.setTimeout(900_000);
  const installationToken = process.env.IPAMFERRY_INSTALLATION_TOKEN;
  const phpIpamToken = process.env.IPAMFERRY_LAB_READ_TOKEN;
  const dumpFile = process.env.IPAMFERRY_LAB_DUMP_FILE;
  if (!installationToken || !phpIpamToken || !dumpFile) {
    throw new Error("The real laboratory credentials and dump path are required.");
  }

  await page.goto("/setup");
  if (page.url().endsWith("/setup")) {
    await page.getByLabel("Token").fill(installationToken);
    await page.getByLabel("Name").fill("Laboratory Owner");
    await page.getByLabel("Email").fill("owner@lab.example.test");
    await page.getByLabel("Password", { exact: true }).fill("CorrectHorseBattery1!");
    await page.getByLabel("Confirm password").fill("CorrectHorseBattery1!");
    await page.getByRole("button", { name: "Create owner" }).click();
  } else {
    await page.getByLabel("Email").fill("owner@lab.example.test");
    await page.getByLabel("Password").fill("CorrectHorseBattery1!");
    await page.getByRole("button", { name: "Log in" }).click();
  }
  await expect(page.getByRole("heading", { name: "Migration projects" })).toBeVisible();

  await page.getByRole("link", { name: "New project" }).click();
  await page.getByLabel("Name").fill("Real phpIPAM API discovery");
  await page.getByLabel("Source").selectOption("api");
  await Promise.all([
    page.waitForURL(/\/projects\/\d+$/, { timeout: 30_000 }),
    page.getByRole("button", { name: "Create" }).click(),
  ]);
  await page.getByLabel("Use the internal disposable NetBox sandbox").check();
  await page.getByLabel("phpIPAM URL").fill("https://phpipam-proxy:8443");
  await page.getByLabel("phpIPAM application ID").fill("ipamferry-read");
  await page.getByLabel("phpIPAM token").fill(phpIpamToken);
  await page.getByRole("button", { name: "Run discovery" }).click();
  await expect(page.getByText("sections", { exact: true })).toBeVisible({ timeout: 180_000 });
  await expect(page.getByText("subnets", { exact: true })).toBeVisible();

  await page.goto("/projects");
  await page.getByLabel("Name").fill("Real phpIPAM dump migration");
  await page.getByLabel("Source").selectOption("dump");
  await Promise.all([
    page.waitForURL(/\/projects\/\d+$/, { timeout: 30_000 }),
    page.getByRole("button", { name: "Create" }).click(),
  ]);
  await page.getByLabel("Use the internal disposable NetBox sandbox").check();
  await page.getByLabel("SQL dump").setInputFiles(dumpFile);
  await page.getByRole("button", { name: "Read dump" }).click();
  await expect(page.getByText("customers", { exact: true })).toBeVisible({ timeout: 180_000 });
  await expect(page.getByText("nat_relations", { exact: true })).toBeVisible();

  await page.getByRole("link", { name: "Open Mapping Studio" }).click();
  await page.getByRole("button", { name: "Accept all" }).click();
  await page.getByRole("button", { name: "Relations" }).click();
  await page.getByLabel("Location classification", { exact: true }).check();
  const locationCard = page.getByRole("heading", { name: "Classify phpIPAM locations" }).locator("..");
  await locationCard.locator("select").first().selectOption("site");
  await page.getByLabel("Device prerequisites", { exact: true }).check();
  await page.getByPlaceholder("Manufacturer", { exact: true }).fill("IpamFerry Lab");
  await page.getByPlaceholder("Physical device model", { exact: true }).fill("Laboratory Router");
  await page.getByPlaceholder("NetBox interface type", { exact: true }).fill("1000base-t");
  await page.getByLabel("Customer contacts", { exact: true }).check();
  await page.getByPlaceholder("Contact Role name", { exact: true }).fill("Customer");
  await page.getByLabel("ASN / RIR", { exact: true }).check();
  await page.getByPlaceholder("RIR name", { exact: true }).fill("Private");
  await page.getByLabel("Circuit terminations", { exact: true }).check();
  const circuitCard = page.getByRole("heading", { name: "Confirmed circuit terminations" }).locator("..");
  await circuitCard.getByRole("checkbox").first().check();
  await page.getByLabel("Primary IP", { exact: true }).check();
  await page.getByLabel("Static NAT 1:1", { exact: true }).check();
  const natCard = page.getByRole("heading", { name: "Confirm static 1:1 NAT" }).locator("..");
  await natCard.getByRole("checkbox").first().check();
  await page.getByRole("button", { name: "Save mapping" }).click();
  await expect(page.getByText("Mapping saved.")).toBeVisible();
  await page.getByRole("button", { name: "Preview" }).click();
  await page.getByRole("button", { name: "Run preview" }).click();
  await expect(page.getByText("Completed")).toBeVisible({ timeout: 120_000 });
  await expect(page.getByText("Conflicts", { exact: true }).locator("..").getByText("0", { exact: true })).toBeVisible();
  await page.getByRole("link", { name: "Back to project" }).click();

  await page.getByRole("button", { name: "Generate plan" }).click();
  await expect(page.getByText(/\d+ actions, 0 conflicts/)).toBeVisible({ timeout: 120_000 });
  await page.getByLabel("I reviewed the diff and approve this exact fingerprint").check();
  await page.getByRole("button", { name: "Approve plan" }).click();
  await page.getByLabel("I confirm applying this exact approved plan").check();
  await page.getByRole("button", { name: "Apply through API" }).click();
  await expect(page.getByText(/Execution #\d+: Applied/)).toBeVisible({ timeout: 180_000 });
  await page.getByRole("button", { name: "Run verification" }).click();
  await expect(page.getByText(/Execution #\d+: Verified/)).toBeVisible({ timeout: 120_000 });

  await page.getByRole("button", { name: "Refresh NetBox target" }).click();
  await expect(page.getByText("NetBox target refreshed. Generate and approve a new target-specific plan.")).toBeVisible({ timeout: 180_000 });
  await page.getByRole("button", { name: "Generate plan" }).click();
  await expect(page.getByText(/\d+ actions, 0 conflicts/)).toBeVisible({ timeout: 120_000 });
  await page.getByLabel("I reviewed the diff and approve this exact fingerprint").check();
  await page.getByRole("button", { name: "Approve plan" }).click();
  await page.getByLabel("I confirm applying this exact approved plan").check();
  await page.getByRole("button", { name: "Apply through API" }).click();
  await expect(page.getByText(/Execution #\d+: Applied/)).toBeVisible({ timeout: 180_000 });
  await page.getByRole("button", { name: "Run verification" }).click();
  await expect(page.getByText(/Execution #\d+: Verified/)).toBeVisible({ timeout: 120_000 });
});
