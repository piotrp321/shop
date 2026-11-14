import { expect, test, type Locator, type Page } from "@playwright/test";
import { createDraftInPanel, newProduct } from "../support/catalog-admin";
import { settle, viewports } from "../support/panel-layout";
import { closePanelContexts, go, newPanelPage, type PanelRole, signInPanelRoles } from "../support/panel-states";

// The toasts of the panel (docs/features/admin-screens/spec.md, „Toasty”): the name of their region (#508) and their place,
// which never covers the save bar or the top bar (#507). The test of the place runs on every form of the panel that has a
// save bar: a form is one entry of `forms`, so the next one (the discount code, ADMIN-SCREENS-4) is one more entry.

type ToastForm = {
  name: string;
  role: PanelRole;
  /** Opens the form of an existing object and waits until it is ready to be edited. */
  open: (page: Page) => Promise<void>;
  /** Changes a field to a value that differs on every call, so the save bar shows and the save has something to write. */
  edit: (page: Page, change: number) => Promise<void>;
};

let product = newProduct("Toasty");
let productId = "";

const forms: ToastForm[] = [
  {
    name: "the form of a product",
    role: "admin",
    open: async (page) => {
      await go(page, `/products/${productId}`);
      await expect(page.getByRole("heading", { level: 1, name: product.name })).toBeVisible();
      await settle(page);
    },
    edit: async (page, change) => {
      await page.getByTestId("name").fill(`${product.name} ${change}`);
    },
  },
];

// 620 and 639 px lie between the breakpoint of vue-sonner (600 px) and the one of the panel (640 px); 640 px is the first
// width of the toast as a card.
const sizes = [viewports.desktop, { width: 640, height: 900 }, { width: 639, height: 900 }, { width: 620, height: 900 }, viewports.phone, viewports.reflow];

test.beforeAll(async ({ browser }) => {
  test.setTimeout(180_000);
  await signInPanelRoles(browser, ["admin"]);
  // The form of a product is the one the test saves; it is a product of its own, so no other test meets the changes.
  product = newProduct("Toasty");
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await go(page, "/");
    productId = await createDraftInPanel(page, product);
  } finally {
    await page.close();
  }
});

test.afterAll(closePanelContexts);

/** The part of the rectangles that lies in both; 0 when they only touch or are apart. */
function overlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): number {
  const width = Math.min(a.x + a.width, b.x + b.width) - Math.max(a.x, b.x);
  const height = Math.min(a.y + a.height, b.y + b.height) - Math.max(a.y, b.y);
  return width > 0 && height > 0 ? width * height : 0;
}

async function boxesOf(locator: Locator): Promise<{ label: string; x: number; y: number; width: number; height: number }[]> {
  const boxes = [];
  for (const element of await locator.all()) {
    const box = await element.boundingBox();
    if (box) {
      const label = (await element.getAttribute("aria-label")) || (await element.innerText()).trim() || (await element.evaluate((node) => node.tagName));
      boxes.push({ label, ...box });
    }
  }
  return boxes;
}

test("#508: the region of the toasts is named exactly „Powiadomienia”", async () => {
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await go(page, "/");
    await expect(page.getByRole("region", { name: "Powiadomienia", exact: true })).toHaveCount(1);
  } finally {
    await page.close();
  }
});

for (const form of forms) {
  for (const size of sizes) {
    test(`#507: a toast does not cover the save bar or the top bar on ${form.name} at ${size.width} x ${size.height} px, and „Zapisz” can be clicked`, async () => {
      test.setTimeout(90_000);
      const page = await newPanelPage(form.role, size);
      try {
        await form.open(page);
        const bar = page.getByRole("region", { name: "Zapis formularza" });

        // The first save puts a toast on the screen; the second edit brings the save bar back while the toast (5 s) is there.
        await form.edit(page, 1);
        await page.getByTestId("save").click();
        const toasts = page.locator("[data-sonner-toast]");
        await expect(toasts.first(), "the save shows a toast").toBeVisible();
        await expect(bar, "the save bar goes away after the save").toHaveCount(0);
        await form.edit(page, 2);
        await expect(bar).toBeVisible();
        await settle(page);
        await expect(toasts.first(), "the toast is still there with the save bar").toBeVisible();

        // Below 640 px the toast spans the screen with a margin of 16 px; from 640 px it is a card 24 px from the right edge.
        const toast = (await boxesOf(toasts.first()))[0]!;
        const margin = size.width < 640 ? 16 : 24;
        expect(Math.abs(toast.x + toast.width - (size.width - margin)), `the right edge of the toast is ${margin} px from the edge of the screen (toast ${JSON.stringify(toast)})`).toBeLessThanOrEqual(1);
        if (size.width < 640) {
          expect(Math.abs(toast.x - 16), `the toast starts 16 px from the left edge (toast ${JSON.stringify(toast)})`).toBeLessThanOrEqual(1);
        } else {
          expect(toast.width, "the toast is a card, not the width of the screen").toBeLessThan(size.width - 2 * 16);
        }

        const covered = [...(await boxesOf(bar.getByRole("button"))), ...(await boxesOf(page.locator("header").locator("a, button, input")))];
        expect(covered.length, "the save bar and the top bar have controls").toBeGreaterThan(2);
        for (const toast of await boxesOf(toasts)) {
          for (const control of covered) {
            expect(overlap(toast, control), `the toast does not cover „${control.label}” (toast ${JSON.stringify(toast)}, control ${JSON.stringify(control)})`).toBe(0);
          }
        }

        // The click has 3 s: a covered button would not receive it (Playwright waits for the click to reach the button).
        await page.getByTestId("save").click({ timeout: 3_000 });
        await expect(bar, "the second save went through").toHaveCount(0);
      } finally {
        await page.close();
      }
    });
  }
}
