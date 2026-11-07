import { expect, type Page } from "@playwright/test";
import { staff } from "./accounts";
import { totp } from "./totp";
import { urls } from "./urls";

type StaffAccount = (typeof staff)[keyof typeof staff];

const OTP_PERIOD_MS = 30_000;

/**
 * Opens the panel and logs in on the Keycloak page of the `backoffice` realm with the password and the TOTP code
 * of the fixed demo secret. Keycloak accepts a code once, so a second login of the same account within the same
 * 30 s window (e.g. two consecutive `make e2e` runs) is rejected: then the login waits for the next window and
 * submits the new code.
 */
export async function loginStaff(page: Page, account: StaffAccount): Promise<void> {
  await page.goto(urls.admin);
  await page.locator("#username").fill(account.username);
  await page.locator("#password").fill(account.password);
  await page.locator("#kc-login").click();

  for (let attempt = 0; attempt < 2; attempt++) {
    const rejection = page.locator("#input-error-otp");
    await page.locator("#otp").fill(await totp(account.totpSecret));
    // On a retry the previous rejection is still in the DOM; drop it so only a new rejection can resolve the race below.
    await rejection.evaluateAll((elements) => elements.forEach((element) => element.remove()));
    await page.locator("#kc-login").click();
    // Accepted: the browser leaves Keycloak for the panel. Rejected: the OTP form comes back with an error message.
    const accepted = await Promise.race([
      page.waitForURL(/^http:\/\/localhost:\d+\/(?!realms\/)/, { timeout: 15_000 }).then(() => true),
      rejection.waitFor({ timeout: 15_000 }).then(() => false),
    ]);
    if (accepted) return;
    // Rejected (reused code): the next code differs only in the next window.
    await page.waitForTimeout(OTP_PERIOD_MS - (Date.now() % OTP_PERIOD_MS) + 200);
  }
  await expect(page.getByRole("heading", { name: "Dashboard" }), "the panel opens after the OTP step").toBeVisible();
}

/**
 * Starts waiting for the bearer token the panel sends to the admin API (the panel keeps its tokens in memory only) and
 * returns the pending token. Call it before the login, so the first request of the panel after the login is not missed,
 * and await the result after it. It rejects when no such request is made within the timeout or the page closes first.
 */
export function waitForBearerToken(page: Page, timeout = 60_000): Promise<string> {
  let token = "";
  const found = page
    .waitForRequest(
      async (request) => {
        if (!request.url().includes("/api/v1/admin/")) return false;
        const header = await request.headerValue("authorization").catch(() => null);
        if (!header?.startsWith("Bearer ")) return false;
        token = header.slice("Bearer ".length);
        return true;
      },
      { timeout },
    )
    .then(
      () => token,
      (error: unknown) => {
        throw new Error(`the panel sent no request with a bearer token to the admin API within ${timeout / 1000} s`, { cause: error });
      },
    );
  // A login that fails first leaves this promise unawaited: keep its later rejection from being reported as unhandled.
  found.catch(() => undefined);
  return found;
}
