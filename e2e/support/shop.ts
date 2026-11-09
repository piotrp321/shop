import { expect, type Locator, type Page } from "@playwright/test";
import { customers } from "./accounts";
import { chooseFirstVariant } from "./variants";

/** The tile of a product of the first page of the list, by its exact name. */
export const tileNamed = (page: Page, name: string): Locator =>
  page.getByRole("main").getByRole("listitem").filter({ has: page.getByRole("heading", { name, exact: true }) });

const cartLines = (page: Page) => page.getByRole("main").getByRole("listitem");

type CustomerAccount = (typeof customers)[keyof typeof customers];

/** Logs in as a demo customer from the current page; Keycloak sends the browser back to it. */
export async function logInAs(page: Page, customer: CustomerAccount): Promise<void> {
  const loginLink = page.getByRole("banner").getByRole("link", { name: "Zaloguj się" });
  await expect(loginLink).toBeVisible();
  await loginLink.click();
  await page.locator("#username").fill(customer.email);
  await page.locator("#password").fill(customer.password);
  await page.locator("#kc-login").click();
  await expect(page.getByText(/^Zalogowany jako .+/)).toBeVisible();
}

/** Logs in as Jan from the current page; Keycloak sends the browser back to it. */
export const logInAsJan = (page: Page): Promise<void> => logInAs(page, customers.jan);

/** Removes every line on the cart page: whatever earlier runs left in the customer's cart. */
export async function emptyCart(page: Page): Promise<void> {
  await page.goto("/cart");
  const empty = page.getByText("Twój koszyk jest pusty");
  await expect(empty.or(cartLines(page).first())).toBeVisible();
  for (let count = await cartLines(page).count(); count > 0; count--) {
    await cartLines(page).first().getByRole("button", { name: /^Usuń/ }).click();
    await expect(cartLines(page)).toHaveCount(count - 1);
  }
  await expect(empty).toBeVisible();
}

/** Chooses the first purchasable variant of the tile and adds it `times` times (1 unit each); returns its label. */
export async function addVariantOf(page: Page, tile: Locator, times: number): Promise<string> {
  const label = await chooseFirstVariant(tile);
  await expect(page.getByTestId("cart-count")).toBeVisible();
  const before = Number(await page.getByTestId("cart-count").innerText());
  for (let added = 1; added <= times; added++) {
    await tile.getByRole("button", { name: "Do koszyka" }).click();
    await expect(page.getByTestId("cart-count")).toHaveText(String(before + added));
  }
  return label;
}

/** The order form fields shared by guests and customers: a demo address in Poland. */
export async function fillAddress(page: Page): Promise<void> {
  await page.getByLabel("Telefon").fill("600 100 200");
  await page.getByLabel("Ulica i numer").fill("ul. Długa 5/3");
  await page.getByLabel("Kod pocztowy").fill("00-950");
  await page.getByLabel("Miejscowość").fill("Warszawa");
}

/** Chooses a payment provider by the name the customer sees, on the checkout page or on the confirmation page. */
export async function chooseProvider(page: Page, name: string): Promise<void> {
  await page.getByRole("radio", { name }).check();
}
