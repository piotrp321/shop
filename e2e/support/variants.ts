import { expect, type Locator, type Page } from "@playwright/test";

/**
 * Chooses the first variant that can be bought on a tile that offers a choice (a product with two or more variants)
 * and returns its label; a tile without a choice (one variant) adds its only variant, label "". The choice is the same
 * on every call, so adding a tile twice adds the same variant.
 */
export async function chooseFirstVariant(tile: Locator): Promise<string> {
  const list = tile.getByRole("combobox");
  if ((await list.count()) === 0) {
    return "";
  }
  const option = list.locator("option:not([disabled])").first();
  const value = await option.getAttribute("value");
  expect(value).toBeTruthy();
  const label = (await option.innerText()).trim();
  await list.selectOption(value!);
  await expect(tile.getByRole("button", { name: "Do koszyka" })).toBeEnabled();
  return label;
}

/** The lines of the cart page of a product (by name) and, for a variant with a label, of that variant. */
export function cartLineOf(page: Page, name: string, label: string): Locator {
  const lines = page.getByRole("main").getByRole("listitem").filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });
  return label === "" ? lines : lines.filter({ has: page.getByTestId("variant-label").getByText(label, { exact: true }) });
}
