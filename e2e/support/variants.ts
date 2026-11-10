import { expect, type Locator, type Page } from "@playwright/test";

/**
 * Chooses the first variant that can be bought on a tile that offers a choice (a product with two or more variants:
 * „Wybierz wariant”, then the variants as radio buttons) and returns its label; a tile without a choice (one variant)
 * adds its only variant, label "". The choice is the same on every call, so adding a tile twice adds the same variant.
 */
export async function chooseFirstVariant(tile: Locator): Promise<string> {
  const expand = tile.getByRole("button", { name: "Wybierz wariant" });
  // A tile that was expanded before keeps its variants.
  if ((await expand.count()) > 0) {
    await expand.click();
  }
  const option = tile.locator("input[type=radio]:not([disabled])").first();
  if ((await option.count()) === 0) {
    return "";
  }
  const label = (await option.evaluate((input: HTMLInputElement) => input.labels?.[0]?.textContent ?? "")).trim();
  expect(label).not.toBe("");
  await option.check();
  await expect(tile.getByRole("button", { name: "Do koszyka" })).toBeEnabled();
  return label;
}

/** The lines of the cart page of a product (by name) and, for a variant with a label, of that variant. */
export function cartLineOf(page: Page, name: string, label: string): Locator {
  const lines = page.getByRole("main").getByRole("listitem").filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });
  return label === "" ? lines : lines.filter({ has: page.getByTestId("variant-label").getByText(label, { exact: true }) });
}
