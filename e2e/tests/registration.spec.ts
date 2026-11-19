import { expect, test, type Page } from "@playwright/test";
import { waitForEmail } from "../support/mailpit";

const password = "E2e-Haslo-123";

async function openRegistration(page: Page) {
  await page.goto("/");
  await page.getByRole("link", { name: "Zaloguj się" }).click();
  await page.getByRole("link", { name: "Rejestracja" }).click();
}

async function submitRegistration(page: Page, email: string, chosenPassword: string) {
  await page.locator("#email").fill(email);
  await page.locator("#password").fill(chosenPassword);
  await page.locator("#password-confirm").fill(chosenPassword);
  await page.locator("#firstName").fill("Ewa");
  await page.locator("#lastName").fill("Testowa");
  await page.locator("input[type=submit]").click();
}

test("a new customer registers, verifies the e-mail address and is logged in", async ({ page }) => {
  const email = `e2e+${Date.now()}@example.com`;

  await openRegistration(page);
  await submitRegistration(page, email, password);

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

// The password policy of the realm shop (L11, D11): at least 12 characters, not the e-mail address (the username of the
// realm). `password: null` stands for the e-mail address of the registration.
const weakPasswords = [
  { name: "a one-letter password", password: "a", message: "minimalna długość 12" },
  { name: "an 11-character password", password: "Abcdefg-123", message: "minimalna długość 12" },
  { name: "a password equal to the e-mail address", password: null, message: "nie może być nazwą użytkownika" },
];

for (const weak of weakPasswords) {
  test(`registration with ${weak.name} is refused by Keycloak and creates no account`, async ({ page }) => {
    const email = `e2e-weak+${Date.now()}@example.com`;

    await openRegistration(page);
    await submitRegistration(page, email, weak.password ?? email);

    // The form stays with the message of Keycloak: the account has not reached the step of the e-mail verification.
    await expect(page.locator("#input-error-password")).toContainText(weak.message);
    await expect(page).not.toHaveURL(/execution=VERIFY_EMAIL/);

    // No account was created: the same e-mail address registers with a strong password (no "already exists" error).
    await submitRegistration(page, email, password);
    await expect(page).toHaveURL(/execution=VERIFY_EMAIL/);
  });
}
