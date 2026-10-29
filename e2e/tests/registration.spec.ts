import { expect, test } from "@playwright/test";
import { waitForEmail } from "../support/mailpit";

test("a new customer registers, verifies the e-mail address and is logged in", async ({ page }) => {
  const email = `e2e+${Date.now()}@example.com`;
  const password = "E2e-Haslo-123";

  await page.goto("/");
  await page.getByRole("link", { name: "Zaloguj się" }).click();
  await page.getByRole("link", { name: "Rejestracja" }).click();

  await page.locator("#email").fill(email);
  await page.locator("#password").fill(password);
  await page.locator("#password-confirm").fill(password);
  await page.locator("#firstName").fill("Ewa");
  await page.locator("#lastName").fill("Testowa");
  await page.locator("input[type=submit]").click();

  // The realm requires a verified e-mail address: the account waits for the link from the message.
  await expect(page).toHaveURL(/execution=VERIFY_EMAIL/);

  const { html } = await waitForEmail(email);
  const link = /href="([^"]*login-actions\/action-token[^"]*)"/.exec(html)?.[1];
  expect(link, "the e-mail has a login-actions/action-token link").toBeTruthy();
  await page.goto(link!.replaceAll("&amp;", "&"));

  // The link verifies the address and finishes the login that started the registration.
  await expect(page).toHaveURL(/^http:\/\/localhost:\d+\/(\?.*)?$/);
  await expect(page.getByText("Zalogowany jako Ewa Testowa")).toBeVisible();

  // The account is verified: a later login with the password goes straight to the store.
  await page.getByRole("button", { name: "Wyloguj się" }).click();
  await page.getByRole("link", { name: "Zaloguj się" }).click();
  await page.locator("#username").fill(email);
  await page.locator("#password").fill(password);
  await page.locator("#kc-login").click();
  await expect(page.getByText("Zalogowany jako Ewa Testowa")).toBeVisible();
});
