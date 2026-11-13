import { expect, type Locator, type Page } from "@playwright/test";

/** The sidebar menu of the panel (a wide viewport: the sidebar is fixed from 1024 px). */
export const panelMenu = (page: Page): Locator => page.getByRole("navigation", { name: "Menu główne" });

/**
 * Follows an item of the panel menu: opens the groups it sits in (outermost first) when they are collapsed, then clicks
 * the item. Groups are buttons with `aria-expanded`, items are links; the item of the current page has
 * `aria-current="page"`.
 */
export async function openMenuItem(page: Page, item: string, ...groups: string[]): Promise<void> {
  const menu = panelMenu(page);
  for (const group of groups) {
    const button = menu.getByRole("button", { name: group, exact: true });
    if ((await button.getAttribute("aria-expanded")) === "false") {
      await button.click();
      await expect(button).toHaveAttribute("aria-expanded", "true");
    }
  }
  await menu.getByRole("link", { name: item, exact: true }).click();
}
