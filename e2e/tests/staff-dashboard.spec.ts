import { expect, test } from "@playwright/test";
import { staff } from "../support/accounts";
import { loginStaff } from "../support/staff-login";

test("admin sees both dashboard sections", async ({ page }) => {
  await loginStaff(page, staff.admin);

  await expect(page.getByRole("heading", { level: 2, name: "Katalog" })).toBeVisible();
  await expect(page.getByRole("heading", { level: 2, name: "Stan systemu" })).toBeVisible();
});

test("support sees the catalog section without the system status", async ({ page }) => {
  await loginStaff(page, staff.support);

  await expect(page.getByRole("heading", { level: 2, name: "Katalog" })).toBeVisible();
  await expect(page.getByRole("heading", { level: 2, name: "Stan systemu" })).toHaveCount(0);
});
