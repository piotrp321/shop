import { expect, type Page, test } from "@playwright/test";
import { customers, staff } from "../support/accounts";
import { apiAmount, createDiscountCode } from "../support/discount-codes";
import { waitForOrderEmail } from "../support/mailpit";
import { decideAtSimulator, expectSimulatorPage, WEBHOOK_TIMEOUT_MS } from "../support/payment";
import { PanelToken } from "../support/promotions";
import { addVariantOf, chooseDelivery, chooseProvider, emptyCart, fillAddress, logInAsJan, STANDARD_DELIVERY, tileNamed } from "../support/shop";
import { loginStaff, waitForBearerToken } from "../support/staff-login";

// Demo products (apps/api/fixtures/catalog.yaml, prices.yaml) and the thresholds of the delivery methods of the demo
// (fixtures/delivery-methods.yaml; "Kurier standardowy" is free from 299,00 zł). The tests do not count the methods: they
// rely on "Kurier standardowy" and on the prices of the goods being clearly below or above its threshold.
//  - a mat, 89,90 zł: below the threshold; every variant has 22 units, an order takes 1 (a paid order keeps it for good);
//  - Buty trailowe Norvane Hala 500, 549,00 zł: above it, and 274,50 zł (half of it) below it; 50 units;
//  - Buty szosowe Norvane Tempo 600, 599,00 zł: above it (Jan, as in checkout-customer.spec.ts); 45 units.
const MAT = "Mata do ćwiczeń Lumora Podłoże 6";
const MAT_PAGE = "/c/fitness/trening";
const HALA = "Buty trailowe Norvane Hala 500";
const TEMPO = "Buty szosowe Norvane Tempo 600";
const STANDARD_FREE_FROM = 29900;

// An amount in grosze from the text of a price ("1 234,50 zł" -> 123450).
const groszeOf = (text: string) => Number(text.replace(/\D/g, ""));

const goodsOf = async (page: Page) => groszeOf(await page.getByTestId("cart-total").innerText());
const standardOption = (page: Page) => page.getByRole("group", { name: "Sposób dostawy" }).getByRole("radio", { name: new RegExp(`^${STANDARD_DELIVERY}`) });
const labelOf = async (page: Page) => (await standardOption(page).evaluate((input: HTMLInputElement) => input.labels?.[0]?.textContent ?? "")).trim();

/** One unit of the product of the tile on `path` in the cart, then the checkout; returns the goods of the cart page. */
async function toTheCheckout(page: Page, path: string, product: string): Promise<number> {
  await page.goto(path);
  const tile = tileNamed(page, product);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);
  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await expect(page.getByTestId("cart-total")).toBeVisible();
  const goods = await goodsOf(page);
  await page.getByRole("link", { name: "Przejdź do kasy" }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeVisible();
  return goods;
}

/** Pays the order of the checkout form with the fast transfer; answers the number of the order. */
async function payAndConfirm(page: Page, accountEmail: string): Promise<string> {
  await chooseProvider(page, "Szybki przelew (demo)");
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
  await expectSimulatorPage(page, "demo-transfer");
  await decideAtSimulator(page, "Zapłać");
  await expect(page.getByRole("heading", { level: 1, name: "Dziękujemy za zamówienie" })).toBeVisible();
  await expect(page.getByText(`Płatność przyjęta. Potwierdzenie wysłaliśmy na adres ${accountEmail}.`)).toBeVisible({ timeout: WEBHOOK_TIMEOUT_MS });
  return (await page.getByTestId("order-number").innerText()).trim();
}

test("a guest below the threshold of free delivery pays for the delivery: the cost is in the summary and the order total on the confirmation page has it", async ({ page }) => {
  const email = `e2e-dostawa+${Date.now()}@example.com`;
  const goods = await toTheCheckout(page, MAT_PAGE, MAT);
  expect(goods).toBeLessThan(STANDARD_FREE_FROM);

  // The option is paid and says what is missing to free delivery; no method is chosen for the customer.
  const label = await labelOf(page);
  expect(label).toContain("Do darmowej dostawy brakuje");
  expect(label).not.toContain("Darmowa");
  await expect(page.getByTestId("checkout-total")).toHaveText("Wybierz sposób dostawy");

  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseDelivery(page);
  const cost = groszeOf(await page.getByTestId("checkout-delivery").innerText());
  expect(cost).toBeGreaterThan(0);
  await expect.poll(async () => groszeOf(await page.getByTestId("checkout-total").innerText())).toBe(goods + cost);
  const checkoutTotal = (await page.getByTestId("checkout-total").innerText()).trim();

  const number = await payAndConfirm(page, email);
  await expect(page.getByTestId("order-total")).toHaveText(checkoutTotal);
  expect(groszeOf(await page.getByTestId("order-total").innerText())).toBe(goods + cost);
  const confirmation = await waitForOrderEmail(email, number);
  expect(confirmation.text).toContain(`Dostawa (${STANDARD_DELIVERY}): ${apiAmount(cost)}`);
  expect(confirmation.text).toContain(`Suma brutto: ${apiAmount(goods + cost)}`);
});

test("Jan above the threshold of free delivery sees the method as free, pays only the goods and the confirmation page shows that total", async ({ page }) => {
  await page.goto("/");
  await logInAsJan(page);
  await emptyCart(page);
  const goods = await toTheCheckout(page, "/", TEMPO);
  expect(goods).toBeGreaterThanOrEqual(STANDARD_FREE_FROM);

  // „Darmowa” never comes with the amount missing to free delivery.
  const label = await labelOf(page);
  expect(label).toContain("Darmowa");
  expect(label).not.toContain("Do darmowej dostawy brakuje");
  await fillAddress(page);
  await chooseDelivery(page);
  await expect(page.getByTestId("checkout-delivery")).toHaveText("Darmowa");
  expect(groszeOf(await page.getByTestId("checkout-total").innerText())).toBe(goods);
  const checkoutTotal = (await page.getByTestId("checkout-total").innerText()).trim();

  const number = await payAndConfirm(page, customers.jan.email);
  await expect(page.getByTestId("order-total")).toHaveText(checkoutTotal);
  const confirmation = await waitForOrderEmail(customers.jan.email, number);
  expect(confirmation.text).toContain(`Dostawa (${STANDARD_DELIVERY}): ${apiAmount(0)}`);
  expect(confirmation.text).toContain(`Suma brutto: ${apiAmount(goods)}`);
});

test("a code that takes the goods below the threshold makes the delivery paid: in the cart read with the code, in the summary of the checkout (announced) and in the placed order", async ({ page, context, request }) => {
  const email = `e2e-dostawa-kod+${Date.now()}@example.com`;
  const goods = await toTheCheckout(page, "/", HALA);
  expect(goods).toBeGreaterThanOrEqual(STANDARD_FREE_FROM);
  expect(goods / 2).toBeLessThan(STANDARD_FREE_FROM);

  // Marketing makes a code of half the price through the API of the panel.
  const staffPage = await context.newPage();
  const pendingToken = waitForBearerToken(staffPage);
  await loginStaff(staffPage, staff.marketing);
  const token = await PanelToken.from(pendingToken);
  const code = await createDiscountCode(request, await token.fresh(staffPage), { kind: "percent", value: 50, minimumOrderGross: null });
  await staffPage.close();

  // Above the threshold the method is free, and the customer chooses it.
  await expect(page.getByTestId("checkout-summary-status")).toHaveText("");
  await chooseDelivery(page);
  await expect(page.getByTestId("checkout-delivery")).toHaveText("Darmowa");

  // The code is applied: the summary says at once that the delivery costs, with what is missing, and announces it.
  await page.getByLabel("Kod rabatowy").fill(code);
  await page.getByRole("button", { name: "Zastosuj" }).click();
  await expect(page.getByTestId("checkout-code-status")).toHaveText(`Zastosowano kod ${code}.`);
  const summaryStatus = page.getByTestId("checkout-summary-status");
  await expect(summaryStatus).toHaveAttribute("role", "status");
  await expect(summaryStatus).toContainText(`Dostawa „${STANDARD_DELIVERY}” nie jest już darmowa, kosztuje`);
  await expect(summaryStatus).toContainText("Do darmowej dostawy brakuje");
  const discount = groszeOf((await page.getByTestId("checkout-discount").innerText()).replace(/^Kod \S+:/, ""));
  const goodsAfter = goods - discount;
  expect(goodsAfter).toBeLessThan(STANDARD_FREE_FROM);
  const cost = groszeOf(await page.getByTestId("checkout-delivery").innerText());
  expect(cost).toBeGreaterThan(0);
  const label = await labelOf(page);
  expect(label).toContain("Do darmowej dostawy brakuje");
  expect(label).not.toContain("Darmowa");
  await expect.poll(async () => groszeOf(await page.getByTestId("checkout-total").innerText())).toBe(goodsAfter + cost);

  // The cart read with the code (the preview of the checkout) has the same: the delivery of the method is paid.
  const preview = await page.request.get(`/api/cart?discountCode=${encodeURIComponent(code)}`);
  expect(preview.status()).toBe(200);
  const cart = (await preview.json()) as { delivery: { options: { name: string; costGross: number; missingToFreeGross: number | null; totalGross: number }[] } };
  const option = cart.delivery.options.find((candidate) => candidate.name === STANDARD_DELIVERY);
  expect(option?.costGross).toBe(cost);
  expect(option?.missingToFreeGross).toBeGreaterThan(0);
  expect(option?.totalGross).toBe(goodsAfter + cost);

  // The order has the paid delivery.
  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  const checkoutTotal = (await page.getByTestId("checkout-total").innerText()).trim();
  const number = await payAndConfirm(page, email);
  await expect(page.getByTestId("order-total")).toHaveText(checkoutTotal);
  const confirmation = await waitForOrderEmail(email, number);
  expect(confirmation.text).toContain(`Kod ${code}: −${apiAmount(discount)}`);
  expect(confirmation.text).toContain(`Dostawa (${STANDARD_DELIVERY}): ${apiAmount(cost)}`);
  expect(confirmation.text).toContain(`Suma brutto: ${apiAmount(goodsAfter + cost)}`);
});

test("the delivery choice fits a 375 px screen, works from the keyboard and, left out, asks for a method with the focus on the first one", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await toTheCheckout(page, MAT_PAGE, MAT);

  const group = page.getByRole("group", { name: "Sposób dostawy" });
  await expect(group).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);

  // Without a method nothing is ordered: the message is at the group and the focus is on its first option.
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
  await expect(group.getByText("Wybierz sposób dostawy.")).toBeVisible();
  await expect(group.getByRole("radio").first()).toBeFocused();
  await expect(page).toHaveURL(/\/checkout$/);

  // The keyboard chooses a method (the focus is on the first radio) and the summary shows its cost.
  await page.keyboard.press("Space");
  await expect(group.getByRole("radio").first()).toBeChecked();
  await expect(page.getByTestId("checkout-delivery")).not.toHaveText("Wybierz sposób dostawy");
  await expect(group.getByText("Wybierz sposób dostawy.")).toHaveCount(0);
  const afterOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(afterOverflow).toBeLessThanOrEqual(0);
});
