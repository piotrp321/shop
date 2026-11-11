import { expect, test } from "@playwright/test";
import { staff } from "../support/accounts";
import { apiAmount, createDiscountCode, disableDiscountCode } from "../support/discount-codes";
import { waitForOrderEmail } from "../support/mailpit";
import { decideAtSimulator, expectSimulatorPage, WEBHOOK_TIMEOUT_MS } from "../support/payment";
import { PanelToken } from "../support/promotions";
import { addVariantOf, chooseProvider, fillAddress, tileNamed } from "../support/shop";
import { loginStaff, waitForBearerToken } from "../support/staff-login";
import { cartLineOf } from "../support/variants";

// A product of the demo data no promotion covers (the category Bieganie > Buty biegowe, apps/api/fixtures/promotions.yaml),
// so its effective price is the catalog price. Each run reserves 2 units of the variant (50 in fixtures/stock.yaml).
const PRODUCT = "Buty trailowe Norvane Hala 500";
const PERCENT = 10;

// An amount from the text of a price ("1 234,50 zł" -> 123450).
const groszeOf = (text: string) => Number(text.replace(/\D/g, ""));

test("a guest sees that the cart is below the minimum of a code, applies it with two units, pays the discounted amount and gets the code line in the e-mail", async ({ page, context, request }) => {
  const email = `e2e-kod+${Date.now()}@example.com`;

  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  const label = await addVariantOf(page, tile, 1);
  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await expect(cartLineOf(page, PRODUCT, label).getByRole("spinbutton")).toHaveValue("1");
  const unit = groszeOf(await page.getByTestId("cart-total").innerText());
  expect(unit).toBeGreaterThan(0);

  // The code works from two units: marketing creates it through the API of the panel, with a text of its own.
  const staffPage = await context.newPage();
  const pendingToken = waitForBearerToken(staffPage);
  await loginStaff(staffPage, staff.marketing);
  const token = await PanelToken.from(pendingToken);
  const minimum = 2 * unit;
  const code = await createDiscountCode(request, await token.fresh(staffPage), { kind: "percent", value: PERCENT, minimumOrderGross: minimum });
  await staffPage.close();

  // One unit is below the minimum: the code is refused with the minimum, and the summary has no discount.
  await page.getByRole("link", { name: "Przejdź do kasy" }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await page.getByLabel("Kod rabatowy").fill(code.toLowerCase());
  await page.getByRole("button", { name: "Zastosuj" }).click();
  await expect(page.getByRole("alert").filter({ hasText: "Ten kod działa przy zamówieniu od" })).toHaveText(`Ten kod działa przy zamówieniu od ${apiAmount(minimum)}.`);
  await expect(page.getByTestId("checkout-discount")).toHaveCount(0);
  await expect(page.getByTestId("checkout-total")).toBeVisible();

  // Two units reach it: the code applies, the summary has its line and the total after the discount.
  await page.goto("/cart");
  const quantity = cartLineOf(page, PRODUCT, label).getByRole("spinbutton");
  await quantity.fill("2");
  await quantity.press("Enter");
  await expect(quantity).toHaveValue("2");
  await expect.poll(async () => groszeOf(await page.getByTestId("cart-total").innerText())).toBe(minimum);
  await page.getByRole("link", { name: "Przejdź do kasy" }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await page.getByLabel("Kod rabatowy").fill(code);
  await page.getByRole("button", { name: "Zastosuj" }).click();
  await expect(page.getByTestId("checkout-code-status")).toHaveText(`Zastosowano kod ${code}.`);
  const discount = Math.floor((minimum * PERCENT) / 100);
  expect(discount).toBeGreaterThan(0);
  await expect(page.getByTestId("checkout-discount")).toContainText(`Kod ${code}:`);
  expect(groszeOf((await page.getByTestId("checkout-discount").innerText()).replace(/^Kod \S+:/, ""))).toBe(discount);
  const total = minimum - discount;
  await expect.poll(async () => groszeOf(await page.getByTestId("checkout-total").innerText())).toBe(total);
  const checkoutTotal = (await page.getByTestId("checkout-total").innerText()).trim();

  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseProvider(page, "Szybki przelew (demo)");
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();

  // The simulator is asked for the amount after the discount, and the confirmation shows it.
  await expectSimulatorPage(page, "demo-transfer");
  await decideAtSimulator(page, "Zapłać");
  await expect(page.getByRole("heading", { level: 1, name: "Dziękujemy za zamówienie" })).toBeVisible();
  const number = (await page.getByTestId("order-number").innerText()).trim();
  await expect(page.getByText(`Płatność przyjęta. Potwierdzenie wysłaliśmy na adres ${email}.`)).toBeVisible({ timeout: WEBHOOK_TIMEOUT_MS });
  await expect(page.getByTestId("order-total")).toHaveText(checkoutTotal);
  expect(groszeOf(await page.getByTestId("order-total").innerText())).toBe(total);

  // The e-mail has the items before the discount, the line of the code and the total after it.
  const confirmation = await waitForOrderEmail(email, number);
  expect(confirmation.text).toContain(PRODUCT);
  expect(confirmation.text).toContain(`Kod ${code}: −${apiAmount(discount)}`);
  expect(confirmation.text).toContain(`Suma brutto: ${apiAmount(total)}`);
});

test("a code that stops working between the preview and the order leaves the live region empty and is announced at the field (below the minimum, then disabled)", async ({ page, context, request }) => {
  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  const label = await addVariantOf(page, tile, 2);
  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await expect(cartLineOf(page, PRODUCT, label).getByRole("spinbutton")).toHaveValue("2");
  const twoUnits = groszeOf(await page.getByTestId("cart-total").innerText());

  // Two codes of marketing: one that needs the two units, one without a minimum.
  const staffPage = await context.newPage();
  const pendingToken = waitForBearerToken(staffPage);
  await loginStaff(staffPage, staff.marketing);
  const token = await PanelToken.from(pendingToken);
  const minimumCode = await createDiscountCode(request, await token.fresh(staffPage), { kind: "percent", value: PERCENT, minimumOrderGross: twoUnits });
  const plainCode = await createDiscountCode(request, await token.fresh(staffPage), { kind: "percent", value: PERCENT, minimumOrderGross: null });

  await page.getByRole("link", { name: "Przejdź do kasy" }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  const codeField = page.getByLabel("Kod rabatowy");
  const status = page.getByTestId("checkout-code-status");
  await codeField.fill(minimumCode);
  await page.getByRole("button", { name: "Zastosuj" }).click();
  await expect(status).toHaveText(`Zastosowano kod ${minimumCode}.`);

  // Another tab takes one unit out, so the cart falls below the minimum before the order is placed.
  const cartTab = await context.newPage();
  await cartTab.goto("/cart");
  const quantity = cartLineOf(cartTab, PRODUCT, label).getByRole("spinbutton");
  await quantity.fill("1");
  await quantity.press("Enter");
  await expect.poll(async () => groszeOf(await cartTab.getByTestId("cart-total").innerText())).toBeLessThan(twoUnits);
  await cartTab.close();

  await page.getByLabel("E-mail").fill(`e2e-kod+${Date.now()}@example.com`);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseProvider(page, "Szybki przelew (demo)");
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
  await expect(page.getByRole("alert").filter({ hasText: "Ten kod działa przy zamówieniu od" })).toHaveText(`Ten kod działa przy zamówieniu od ${apiAmount(twoUnits)}.`);
  await expect(page.getByTestId("checkout-discount")).toHaveCount(0);
  await expect(status).toHaveText("");
  await expect(status).not.toContainText("Zastosowano");

  // A code with no minimum applies, then the panel disables it before the next order.
  await codeField.fill(plainCode);
  await page.getByRole("button", { name: "Zastosuj" }).click();
  await expect(status).toHaveText(`Zastosowano kod ${plainCode}.`);
  await disableDiscountCode(request, await token.fresh(staffPage), plainCode);
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
  await expect(page.getByLabel("Kod rabatowy")).toHaveAttribute("aria-invalid", "true");
  await expect(page.getByRole("alert").filter({ hasText: "nieprawidłowy albo nieaktywny" })).toBeVisible();
  await expect(page.getByTestId("checkout-discount")).toHaveCount(0);
  await expect(status).toHaveText("");
  await expect(status).not.toContainText("Zastosowano");
  await staffPage.close();
});
