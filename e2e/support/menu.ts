import { expect, type Locator, type Page } from "@playwright/test";

/** The department menu of the header (wide viewport: the bar of departments and the panel of the open one). */
export const departmentMenu = (page: Page): Locator => page.getByRole("navigation", { name: "Działy" });

/** Opens a department of the menu with a click; its panel (levels 2 and 3 and "Wszystko z działu") becomes visible. */
export async function openDepartment(page: Page, department: string): Promise<Locator> {
  const button = departmentMenu(page).getByRole("button", { name: department, exact: true });
  await button.click();
  await expect(button).toHaveAttribute("aria-expanded", "true");
  return departmentMenu(page).locator(`[id="${await button.getAttribute("aria-controls")}"]`);
}

/** Goes to a category through the menu: opens the department and follows the link (level 2 or 3) of its panel. */
export async function openCategoryFromMenu(page: Page, department: string, category: string): Promise<void> {
  const panel = await openDepartment(page, department);
  await panel.getByRole("link", { name: category, exact: true }).click();
}
