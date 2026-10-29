import { expect, test } from "@playwright/test";
import { customers } from "../support/accounts";

test("a demo customer logs in and out of the store", async ({ page }) => {
  await page.goto("/");
  await page.getByRole("link", { name: "Zaloguj się" }).click();

  // Keycloak login page of the `shop` realm (field ids are stable across its languages).
  await page.locator("#username").fill(customers.jan.email);
  await page.locator("#password").fill(customers.jan.password);
  await page.locator("#kc-login").click();

  await expect(page).toHaveURL(/^http:\/\/localhost:\d+\/(\?.*)?$/);
  await expect(page.getByText(/^Zalogowany jako .+/)).toBeVisible();

  await page.getByRole("button", { name: "Wyloguj się" }).click();
  await expect(page.getByRole("link", { name: "Zaloguj się" })).toBeVisible();
  await expect(page.getByText(/^Zalogowany jako/)).toHaveCount(0);

  // Logout ended the Keycloak session too: logging in again asks for the credentials.
  await page.getByRole("link", { name: "Zaloguj się" }).click();
  await expect(page.locator("#username")).toBeVisible();
});
