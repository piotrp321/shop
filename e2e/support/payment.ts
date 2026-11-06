import { expect, type Page } from "@playwright/test";

/** Where the browser lands to pay: the page of a provider of the simulator (served by the API, outside /api/v1). */
export async function expectSimulatorPage(page: Page, provider: "demo-transfer" | "demo-card"): Promise<void> {
  await expect(page).toHaveURL(new RegExp(`/payment-simulator/${provider}/pay/[0-9a-f-]+$`));
  await expect(page.getByText("To symulacja: żadne pieniądze nie zostaną pobrane.")).toBeVisible();
}

/** Decides on the page of the simulator; the simulator sends the browser back to the confirmation page. */
export async function decideAtSimulator(page: Page, decision: "Zapłać" | "Odrzuć płatność"): Promise<void> {
  await page.getByRole("button", { name: decision, exact: true }).click();
  await expect(page).toHaveURL(/\/checkout\/confirmation$/);
}

/** The webhook of the provider reaches the store through the worker, so the status changes a moment after the return. */
export const WEBHOOK_TIMEOUT_MS = 30_000;
