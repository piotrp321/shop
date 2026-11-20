import { expect, test, type Locator, type Page } from "@playwright/test";

// The bar of a demo store (SMALL-FIXES-3, B8): the browser reads `/api/site` after the page is shown, so the bar must
// not push the header and the content down, and it must not hide the end of the page or the focused element.

const SIZES = [
  { name: "desktop", viewport: { width: 1440, height: 900 } },
  { name: "phone", viewport: { width: 375, height: 812 } },
];

/** Answers `/api/site` with `demoNotice: true` only after `release()` is called, so the page is measured before and after the bar. */
async function delaySwitches(page: Page): Promise<{ release: () => void }> {
  let release!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route("**/api/site", async (route) => {
    await gate;
    await route.fulfill({ json: { demoNotice: true, customerAccounts: true }, headers: { "Cache-Control": "no-store" } });
  });
  return { release };
}

async function topOf(locator: Locator): Promise<number> {
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  return box!.y;
}

async function boxOf(locator: Locator): Promise<{ top: number; bottom: number }> {
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  return { top: box!.y, bottom: box!.y + box!.height };
}

for (const { name, viewport } of SIZES) {
  test.describe(`the demo bar on ${name}`, () => {
    test.use({ viewport });

    test("appearing does not move the header or the first element of the content", async ({ page }) => {
      const { release } = await delaySwitches(page);
      await page.goto("/");
      const header = page.getByRole("banner");
      const hero = page.getByRole("heading", { level: 1 });
      await expect(hero).toBeVisible();
      await expect(page.getByRole("note")).toHaveCount(0);
      const before = { header: await topOf(header), hero: await topOf(hero) };

      release();
      await expect(page.getByRole("note")).toBeVisible();

      expect({ header: await topOf(header), hero: await topOf(hero) }).toEqual(before);
    });

    test("the bar is pinned to the bottom of the window and stays there after scrolling", async ({ page }) => {
      const { release } = await delaySwitches(page);
      await page.goto("/");
      release();
      const bar = page.getByRole("note");
      await expect(bar).toBeVisible();

      const windowHeight = await page.evaluate(() => window.innerHeight);
      expect((await boxOf(bar)).bottom).toBe(windowHeight);
      await page.evaluate(() => window.scrollTo(0, 400));
      expect((await boxOf(bar)).bottom).toBe(windowHeight);
    });

    test("scrolled to the end, the last link of the footer is not covered by the bar", async ({ page }) => {
      const { release } = await delaySwitches(page);
      await page.goto("/");
      release();
      const bar = page.getByRole("note");
      await expect(bar).toBeVisible();

      await page.keyboard.press("End");
      await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
      const lastLink = page.locator("footer a").last();
      await expect(lastLink).toBeVisible();
      await expect.poll(async () => (await boxOf(bar)).top - (await boxOf(lastLink)).bottom).toBeGreaterThanOrEqual(0);
    });

    test("a footer link reached with Tab is not covered by the bar", async ({ page }) => {
      const { release } = await delaySwitches(page);
      await page.goto("/");
      release();
      const bar = page.getByRole("note");
      await expect(bar).toBeVisible();

      // The link before the last one has the focus, and the page is scrolled so that the last link is inside the window
      // but under the bar: only the scroll padding makes the browser scroll again when Tab reaches it.
      const links = page.locator("footer a");
      const lastLink = links.last();
      await links.nth(-2).evaluate((link) => (link as HTMLElement).focus({ preventScroll: true }));
      await lastLink.evaluate((link) => {
        window.scrollBy(0, link.getBoundingClientRect().bottom - window.innerHeight + 1);
      });
      expect((await boxOf(bar)).top).toBeLessThan((await boxOf(lastLink)).bottom);

      await page.keyboard.press("Tab");
      await expect(lastLink).toBeFocused();
      // The scroll that brings the focused link into view may still be running.
      await expect.poll(async () => (await boxOf(bar)).top - (await boxOf(lastLink)).bottom).toBeGreaterThanOrEqual(0);
    });
  });
}
