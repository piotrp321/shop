import { expect, type Locator, type Page, test } from "@playwright/test";
import { addVariantOf, tileNamed } from "../support/shop";
import { cartLineOf } from "../support/variants";

// Actions given in the cart while a change is saved (docs/features/store-ux-fixes/spec.md, S1). Three demo mats of the
// category "Fitness" > "Trening" (apps/api/fixtures/catalog.yaml), 1 unit each, in the cart of a new guest; the tests
// never order them.
const CATEGORY = "/c/fitness/trening";
const PRODUCTS = ["Mata do ćwiczeń Lumora Podłoże 6", "Mata do ćwiczeń Lumora Podłoże 10", "Mata do ćwiczeń Dravik Podłoże 6"];

// "Held" in the specification: the request stops in the browser for 3 s before it reaches the server.
const HOLD_MS = 3_000;
const ITEM_URL = "**/api/cart/items/*";

type Line = { name: string; label: string };
type Writes = { PATCH: number; DELETE: number };

async function cartWithThreeLines(page: Page): Promise<Line[]> {
  await page.goto(CATEGORY);
  await expect(page.getByRole("heading", { level: 1, name: "Trening" })).toBeVisible();
  const lines: Line[] = [];
  for (const name of PRODUCTS) {
    const tile = tileNamed(page, name);
    await expect(tile).toHaveCount(1);
    lines.push({ name, label: await addVariantOf(page, tile, 1) });
  }
  await page.goto("/cart");
  // The first read of a cart with three lines asks the API for the offers of all of them: slower than an action.
  await expect(page.getByTestId("cart-total")).toBeVisible({ timeout: 30_000 });
  for (const line of lines) {
    await expect(quantityOf(page, line)).toHaveValue("1");
  }
  return lines;
}

const rowOf = (page: Page, line: Line): Locator => cartLineOf(page, line.name, line.label);
const quantityOf = (page: Page, line: Line): Locator => rowOf(page, line).getByRole("spinbutton");
const removeOf = (page: Page, line: Line): Locator => rowOf(page, line).getByRole("button", { name: /^Usuń/ });

/** Counts the quantity changes and removals the page sends to the store's cart endpoints. */
function countWrites(page: Page): Writes {
  const writes: Writes = { PATCH: 0, DELETE: 0 };
  page.on("request", (request) => {
    const method = request.method();
    if (/\/api\/cart\/items\/[^/]+$/.test(new URL(request.url()).pathname) && (method === "PATCH" || method === "DELETE")) {
      writes[method]++;
    }
  });
  return writes;
}

/** Holds every quantity change of the cart page in the browser before it goes to the server. */
async function holdQuantityChanges(page: Page): Promise<void> {
  await page.route(ITEM_URL, async (route) => {
    if (route.request().method() === "PATCH") {
      await new Promise((resolve) => setTimeout(resolve, HOLD_MS));
    }
    await route.fallback();
  });
}

type Input = { touch: boolean };

/** Types a quantity into the field the way a customer does, without leaving it. */
async function typeQuantity(field: Locator, value: string, { touch }: Input): Promise<void> {
  if (touch) await field.tap();
  else await field.click();
  await field.fill(value);
}

async function press(button: Locator, { touch }: Input): Promise<void> {
  if (touch) await button.tap();
  else await button.click();
}

const scenarios = [
  { device: "the mouse at 1280 px", touch: false },
  { device: "touch at 375 px", touch: true },
];

for (const { device, touch } of scenarios) {
  test.describe(`Usuń right after typing a quantity, with ${device} (criterion 1)`, () => {
    test.use(touch ? { viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true } : { viewport: { width: 1280, height: 800 } });

    for (const held of [false, true]) {
      test(`removes the chosen line after the change ${held ? "held for 3 s" : "without a delay"}: one PATCH and one DELETE each time`, async ({ page }) => {
        test.setTimeout(90_000);
        const [a, b, c] = await cartWithThreeLines(page);
        const writes = countWrites(page);
        if (held) await holdQuantityChanges(page);

        // Usuń of the line just typed into: that line goes, the other one stays.
        await typeQuantity(quantityOf(page, a), "3", { touch });
        await press(removeOf(page, a), { touch });
        if (held) await expect(removeOf(page, a)).toHaveAttribute("aria-disabled", "true");
        await expect(rowOf(page, a)).toHaveCount(0, { timeout: HOLD_MS + 10_000 });
        await expect(quantityOf(page, b)).toHaveValue("1");
        expect(writes).toEqual({ PATCH: 1, DELETE: 1 });

        // Usuń of another line: the typed quantity is saved and the other line goes.
        await typeQuantity(quantityOf(page, b), "3", { touch });
        await press(removeOf(page, c), { touch });
        await expect(rowOf(page, c)).toHaveCount(0, { timeout: HOLD_MS + 10_000 });
        await expect(quantityOf(page, b)).toHaveValue("3");
        await expect(page.getByTestId("cart-item-count")).toHaveText("3");
        expect(writes).toEqual({ PATCH: 2, DELETE: 2 });

        // The server has what the page shows.
        await page.unroute(ITEM_URL);
        await page.reload();
        await expect(quantityOf(page, b)).toHaveValue("3");
        await expect(rowOf(page, a)).toHaveCount(0);
        await expect(rowOf(page, c)).toHaveCount(0);
        expect(writes).toEqual({ PATCH: 2, DELETE: 2 });
      });
    }
  });
}
