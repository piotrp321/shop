import { expect, test, type Page } from "@playwright/test";
import { waitForEmail } from "../support/mailpit";

const password = "E2e-Haslo-123";

async function openRegistration(page: Page) {
  await page.goto("/");
  await page.getByRole("link", { name: "Zaloguj się" }).click();
  await page.getByRole("link", { name: "Rejestracja" }).click();
}

/**
 * Registers `email` and opens the link of the verification e-mail. With registration and "Verify Email" in the realm,
 * Keycloak's form has no password: the account is created without one, and the link verifies the address and asks for
 * the password on the page "Update password".
 */
async function registerAndOpenTheLink(page: Page, email: string) {
  await openRegistration(page);
  await expect(page.locator("#email")).toBeVisible();
  await expect(page.locator("#password")).toHaveCount(0);
  await page.locator("#email").fill(email);
  await page.locator("#firstName").fill("Ewa");
  await page.locator("#lastName").fill("Testowa");
  await page.locator("input[type=submit]").click();

  // The realm requires a verified e-mail address: the account waits for the link from the message.
  await expect(page).toHaveURL(/execution=VERIFY_EMAIL/);

  const { html } = await waitForEmail(email);
  const link = /href="([^"]*login-actions\/action-token[^"]*)"/.exec(html)?.[1];
  expect(link, "the e-mail has a login-actions/action-token link").toBeTruthy();
  await page.goto(link!.replaceAll("&amp;", "&"));
  await expect(page.locator("#password-new")).toBeVisible();
}

async function setPassword(page: Page, chosenPassword: string) {
  await page.locator("#password-new").fill(chosenPassword);
  await page.locator("#password-confirm").fill(chosenPassword);
  await page.locator("#kc-submit").click();
}

test("a new customer registers, sets the password from the e-mail link and is logged in", async ({ page }) => {
  const email = `e2e+${Date.now()}@example.com`;

  await registerAndOpenTheLink(page, email);
  await setPassword(page, password);

  // Setting the password finishes the login that started the registration.
  await expect(page).toHaveURL(/^http:\/\/localhost:\d+\/(\?.*)?$/);
  await expect(page.getByText("Zalogowany jako Ewa Testowa")).toBeVisible();

  // The account is verified and has the password: a later login with it goes straight to the store.
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
  test(`${weak.name} is refused on the page that sets the password and logs nobody in`, async ({ page }) => {
    const email = `e2e-weak+${Date.now()}@example.com`;

    await registerAndOpenTheLink(page, email);
    await setPassword(page, weak.password ?? email);

    // The page stays with the message of Keycloak (the alert of the page, not a message under the field) and nothing
    // goes back to the store.
    await expect(page.locator(".kc-feedback-text")).toContainText(weak.message);
    await expect(page.locator("#password-new")).toBeVisible();

    // The customer is not logged in to the store.
    const store = await page.context().newPage();
    await store.goto("/");
    await expect(store.getByRole("link", { name: "Zaloguj się" })).toBeVisible();
    await expect(store.getByText(/^Zalogowany jako/)).toHaveCount(0);
    await store.close();

    // A password that meets the policy, given next on the same page, logs the customer in.
    await setPassword(page, password);
    await expect(page).toHaveURL(/^http:\/\/localhost:\d+\/(\?.*)?$/);
    await expect(page.getByText("Zalogowany jako Ewa Testowa")).toBeVisible();
  });
}
