import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Locator, type Page } from "@playwright/test";
import { urls } from "../support/urls";

// The gallery and the enlargement of the product page (VISUAL-DESIGN-8). Demo data (apps/api/fixtures): the skis have
// three photos (VISUAL-DESIGN-11), the shoes one and the bottle none.
const SKIS = { name: "Narty skiturowe Stavro Grań 95", path: "/p/narty-skiturowe-stavro-gran-95" };
const ONE_PHOTO_PATH = "/p/buty-szosowe-tarvik-asfalt-200";
const NO_PHOTO_PATH = "/p/bidon-termiczny-dravik-zrodlo-750";

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

const strip = (page: Page) => page.getByRole("group", { name: "Zdjęcia produktu" });
const dialog = (page: Page) => page.getByRole("dialog", { name: SKIS.name });
const scrollWidth = (page: Page): Promise<number> => page.evaluate(() => document.documentElement.scrollWidth);

/** The page is hydrated when the buttons that only JavaScript adds are there. */
async function openProduct(page: Page, path = SKIS.path): Promise<void> {
  await page.goto(path);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
}

async function waitForHydration(page: Page): Promise<void> {
  await expect(page.getByRole("button", { name: "Następne zdjęcie" }).first()).toBeVisible();
}

async function altsOfProduct(page: Page, path: string): Promise<{ images: string[]; zoomImages: string[] }> {
  const response = await page.request.get(`${urls.api}/api/v1/products/${path.replace("/p/", "")}`);
  expect(response.status()).toBe(200);
  const product = (await response.json()) as { images: { alt: string }[]; zoomImages: { alt: string }[] };
  return { images: product.images.map((image) => image.alt), zoomImages: product.zoomImages.map((image) => image.alt) };
}

/**
 * A swipe of a finger over the element: real touch events (CDP `Input.dispatchTouchEvent`), so the browser scrolls the
 * strip by itself, as it does for a finger. `Input.synthesizeScrollGesture` with `gestureSourceType: "touch"` moves nothing
 * in the headless Chromium of this stack (and with `default` it sends mouse wheel events, not touch), so it cannot be used.
 */
async function swipe(page: Page, target: Locator, direction: "next" | "previous"): Promise<void> {
  const box = (await target.boundingBox())!;
  const y = Math.round(box.y + box.height / 2);
  // Starts away from the arrow buttons (they are not inside the strip, so a finger on them does not scroll it).
  const from = Math.round(box.x + box.width * (direction === "next" ? 0.7 : 0.3));
  const step = Math.round((box.width * 0.55) / 10) * (direction === "next" ? -1 : 1);
  const cdp = await page.context().newCDPSession(page);
  await cdp.send("Input.dispatchTouchEvent", { type: "touchStart", touchPoints: [{ x: from, y }] });
  for (let move = 1; move <= 10; move++) {
    await cdp.send("Input.dispatchTouchEvent", { type: "touchMove", touchPoints: [{ x: from + move * step, y }] });
    await page.waitForTimeout(16);
  }
  await cdp.send("Input.dispatchTouchEvent", { type: "touchEnd", touchPoints: [] });
  await cdp.detach();
}

test.describe("on a desktop", () => {
  test.use({ viewport: DESKTOP });

  test("the strip, the thumbnails and the photo texts come from the API: alt on the photos, empty alt in named thumbnail links", async ({ page }) => {
    const { images, zoomImages } = await altsOfProduct(page, SKIS.path);
    expect(images).toHaveLength(3);
    expect(zoomImages).toEqual(images);
    await openProduct(page);

    const photos = strip(page).locator("img");
    await expect(photos).toHaveCount(3);
    expect(await photos.evaluateAll((list) => list.map((img) => (img as HTMLImageElement).alt))).toEqual(images);
    for (const [index, alt] of images.entries()) {
      await expect(page.getByRole("button", { name: `Powiększ zdjęcie ${index + 1} z 3: ${alt}`, exact: true })).toBeVisible();
    }

    const thumbs = page.getByRole("list", { name: "Miniatury zdjęć" }).getByRole("link");
    await expect(thumbs).toHaveCount(3);
    for (const [index, thumb] of (await thumbs.all()).entries()) {
      await expect(thumb).toHaveAttribute("aria-label", `Pokaż zdjęcie ${index + 1} z 3`);
      await expect(thumb).toHaveAttribute("href", `#zdjecie-${index + 1}`);
      await expect(thumb.locator("img")).toHaveAttribute("alt", "");
      await expect(thumb.locator("img")).toHaveAttribute("loading", "lazy");
    }
    // The first photo has priority, the other two do not.
    expect(await photos.evaluateAll((list) => list.map((img) => img.getAttribute("loading")))).toEqual(["eager", "lazy", "lazy"]);
  });

  test("a thumbnail scrolls the strip, marks itself current and does not change the address or the history", async ({ page }) => {
    await openProduct(page);
    await waitForHydration(page);
    const url = page.url();
    const history = await page.evaluate(() => window.history.length);
    await expect(page.getByText("1 / 3", { exact: true })).toBeVisible();

    const thumbs = page.getByRole("list", { name: "Miniatury zdjęć" }).getByRole("link");
    await expect(thumbs.nth(0)).toHaveAttribute("aria-current", "true");
    await thumbs.nth(2).click();

    await expect(page.getByText("3 / 3", { exact: true })).toBeVisible();
    await expect(thumbs.nth(2)).toHaveAttribute("aria-current", "true");
    await expect(thumbs.nth(0)).not.toHaveAttribute("aria-current", "true");
    expect(page.url()).toBe(url);
    expect(await page.evaluate(() => window.history.length)).toBe(history);
    const box = (await page.locator("#zdjecie-3").boundingBox())!;
    const stripBox = (await strip(page).boundingBox())!;
    expect(Math.abs(box.x - stripBox.x)).toBeLessThan(2);
  });

  test("the arrow buttons and the arrow keys change the photo; the counter is a live region", async ({ page }) => {
    await openProduct(page);
    await waitForHydration(page);
    const counter = page.locator("[aria-live=polite]", { hasText: /^\d \/ 3$/ }).first();
    await expect(counter).toHaveText("1 / 3");

    await page.getByRole("button", { name: "Następne zdjęcie" }).click();
    await expect(counter).toHaveText("2 / 3");
    await page.getByRole("button", { name: "Poprzednie zdjęcie" }).click();
    await expect(counter).toHaveText("1 / 3");
    await page.getByRole("button", { name: "Poprzednie zdjęcie" }).click();
    await expect(counter).toHaveText("3 / 3");

    // Tab to the photos of the strip, then the arrows move on from the focused photo.
    await page.getByRole("button", { name: /^Powiększ zdjęcie 3 z 3/ }).focus();
    await page.keyboard.press("ArrowLeft");
    await expect(counter).toHaveText("2 / 3");
    await expect(page.getByRole("button", { name: /^Powiększ zdjęcie 2 z 3/ })).toBeFocused();
    await page.keyboard.press("ArrowRight");
    await expect(counter).toHaveText("3 / 3");
    // Wraps around at the end.
    await page.keyboard.press("ArrowRight");
    await expect(counter).toHaveText("1 / 3");
  });

  test("the enlargement opens with Enter and with Space on the visible photo, changes the photo with buttons and arrows, closes with Escape and gives the focus back", async ({ page }) => {
    const { zoomImages } = await altsOfProduct(page, SKIS.path);
    await openProduct(page);
    await waitForHydration(page);
    await page.getByRole("button", { name: "Następne zdjęcie" }).click();
    await expect(page.getByText("2 / 3", { exact: true })).toBeVisible();

    const opener = page.getByRole("button", { name: /^Powiększ zdjęcie 2 z 3/ });
    await opener.focus();
    await page.keyboard.press("Enter");
    await expect(dialog(page)).toBeVisible();
    // Opens on the visible photo, the counter and the alt text of the photo in the enlargement.
    await expect(dialog(page).getByText("2 / 3", { exact: true })).toBeVisible();
    const zoomPhotos = dialog(page).locator("img");
    await expect(zoomPhotos).toHaveCount(3);
    expect(await zoomPhotos.evaluateAll((list) => list.map((img) => (img as HTMLImageElement).alt))).toEqual(zoomImages);
    await expect.poll(() => zoomPhotos.nth(1).evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    expect(await zoomPhotos.nth(1).evaluate((img) => getComputedStyle(img).objectFit)).toBe("contain");

    await dialog(page).getByRole("button", { name: "Następne zdjęcie" }).click();
    await expect(dialog(page).getByText("3 / 3", { exact: true })).toBeVisible();
    await page.keyboard.press("ArrowLeft");
    await expect(dialog(page).getByText("2 / 3", { exact: true })).toBeVisible();
    await page.keyboard.press("ArrowLeft");
    await expect(dialog(page).getByText("1 / 3", { exact: true })).toBeVisible();

    await page.keyboard.press("Escape");
    await expect(dialog(page)).toBeHidden();
    await expect(opener).toBeFocused();

    // Space opens it too, "Zamknij" closes it.
    await page.keyboard.press("Space");
    await expect(dialog(page)).toBeVisible();
    await dialog(page).getByRole("button", { name: "Zamknij" }).click();
    await expect(dialog(page)).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test("a click on the photo opens the enlargement; the dialog is the whole window with the name of the product as its title", async ({ page }) => {
    await openProduct(page);
    await waitForHydration(page);
    await page.getByRole("button", { name: /^Powiększ zdjęcie 1 z 3/ }).click();
    await expect(dialog(page)).toBeVisible();
    // Poll: the dialog fades in.
    await expect.poll(async () => (await dialog(page).boundingBox())!.width).toBeGreaterThanOrEqual(DESKTOP.width - 1);
    expect((await dialog(page).boundingBox())!.height).toBeGreaterThanOrEqual(DESKTOP.height - 1);
  });

  test("the position of the name and of „Do koszyka” is the same without JavaScript and after hydration, and hydration reports no error", async ({ page, browser }) => {
    const errors: string[] = [];
    page.on("console", (message) => {
      if (message.type() === "error" || message.type() === "warning") errors.push(message.text());
    });
    page.on("pageerror", (error) => errors.push(error.message));

    const plain = await browser.newContext({ viewport: DESKTOP, javaScriptEnabled: false, locale: "pl-PL" });
    const plainPage = await plain.newPage();
    await plainPage.goto(`${urls.store}${SKIS.path}`);
    const boxes = async (target: Page) => ({
      heading: await target.getByRole("heading", { level: 1 }).boundingBox(),
      buy: await target.getByRole("button", { name: "Do koszyka" }).boundingBox(),
      price: await target.getByTestId("price").boundingBox(),
    });
    const before = await boxes(plainPage);
    await plain.close();

    await openProduct(page);
    await waitForHydration(page);
    const after = await boxes(page);
    expect(before.heading).not.toBeNull();
    for (const key of ["heading", "buy", "price"] as const) {
      expect(Math.abs(after[key]!.x - before[key]!.x), `${key} x`).toBeLessThan(1);
      expect(Math.abs(after[key]!.y - before[key]!.y), `${key} y`).toBeLessThan(1);
      expect(Math.abs(after[key]!.height - before[key]!.height), `${key} height`).toBeLessThan(1);
    }
    expect(errors.filter((text) => /hydrat/i.test(text))).toEqual([]);
  });

  test("without JavaScript all the photos and thumbnails are in the HTML and a thumbnail scrolls the strip", async ({ browser }) => {
    const context = await browser.newContext({ viewport: DESKTOP, javaScriptEnabled: false, locale: "pl-PL" });
    const page = await context.newPage();
    await page.goto(`${urls.store}${SKIS.path}`);
    await expect(strip(page).locator("img")).toHaveCount(3);
    await expect(page.getByRole("list", { name: "Miniatury zdjęć" }).getByRole("link")).toHaveCount(3);
    await expect(page.getByText("1 / 3", { exact: true })).toBeVisible();
    // No JavaScript: nothing that needs it.
    await expect(page.getByRole("button", { name: "Następne zdjęcie" })).toHaveCount(0);

    await page.getByRole("link", { name: "Pokaż zdjęcie 3 z 3" }).click();
    await expect.poll(() => strip(page).evaluate((element) => Math.round(element.scrollLeft / element.clientWidth))).toBe(2);
    await context.close();
  });

  test("a product with one photo has the enlargement but no thumbnails, counter or arrows; one without photos has the placeholder only", async ({ page }) => {
    expect((await altsOfProduct(page, ONE_PHOTO_PATH)).images).toHaveLength(1);
    await openProduct(page, ONE_PHOTO_PATH);
    await expect(page.getByRole("button", { name: /^Powiększ zdjęcie 1 z 1/ })).toBeVisible();
    await expect(page.getByRole("list", { name: "Miniatury zdjęć" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Następne zdjęcie" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Poprzednie zdjęcie" })).toHaveCount(0);
    await expect(page.getByText(/^1 \/ 1$/)).toHaveCount(0);
    const single = page.getByRole("dialog");
    // No arrows to tell that the page is hydrated: click until the dialog opens.
    await expect(async () => {
      await page.getByRole("button", { name: /^Powiększ zdjęcie 1 z 1/ }).click();
      await expect(single).toBeVisible({ timeout: 1000 });
    }).toPass();
    await expect(single.getByRole("button", { name: "Następne zdjęcie" })).toHaveCount(0);
    await page.keyboard.press("Escape");
    await expect(single).toBeHidden();

    expect((await altsOfProduct(page, NO_PHOTO_PATH)).images).toHaveLength(0);
    await openProduct(page, NO_PHOTO_PATH);
    await expect(page.getByRole("img", { name: "Brak zdjęcia" })).toBeVisible();
    await expect(page.getByRole("button", { name: /^Powiększ/ })).toHaveCount(0);
    await expect(page.getByRole("list", { name: "Miniatury zdjęć" })).toHaveCount(0);
  });

  test("axe finds no serious or critical violation on the product page, also with the enlargement open", async ({ page }) => {
    await openProduct(page);
    await waitForHydration(page);
    const audit = async () => {
      const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
      return results.violations.filter((violation) => violation.impact === "serious" || violation.impact === "critical").map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(" | ")}`);
    };
    expect(await audit()).toEqual([]);
    await page.getByRole("button", { name: /^Powiększ zdjęcie 1 z 3/ }).click();
    await expect(dialog(page)).toBeVisible();
    await dialog(page).locator("img").first().evaluate((img: HTMLImageElement) => img.decode());
    expect(await audit()).toEqual([]);
  });
});

test.describe("on a phone", () => {
  // A touch device at 375 px with the densest screen (DPR 3): the largest file the page may ask for is the widest it needs.
  test.use({ viewport: PHONE, hasTouch: true, isMobile: true, deviceScaleFactor: 3 });

  test("a swipe in the strip changes the photo; no horizontal scroll; the target of every control is at least 44 px", async ({ page }) => {
    await openProduct(page);
    await waitForHydration(page);
    const counter = page.getByText(/^\d \/ 3$/).first();
    await expect(counter).toHaveText("1 / 3");

    await swipe(page, strip(page), "next");
    await expect(counter).toHaveText("2 / 3");
    await expect(page.getByRole("list", { name: "Miniatury zdjęć" }).getByRole("link").nth(1)).toHaveAttribute("aria-current", "true");
    await swipe(page, strip(page), "previous");
    await expect(counter).toHaveText("1 / 3");

    expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
    const targets = [
      ...(await page.getByRole("list", { name: "Miniatury zdjęć" }).getByRole("link").all()),
      page.getByRole("button", { name: "Poprzednie zdjęcie" }),
      page.getByRole("button", { name: "Następne zdjęcie" }),
      page.getByRole("button", { name: /^Powiększ zdjęcie 1 z 3/ }),
      page.getByRole("button", { name: "Do koszyka" }),
    ];
    for (const target of targets) {
      const box = (await target.boundingBox())!;
      expect(box.width).toBeGreaterThanOrEqual(44);
      expect(box.height).toBeGreaterThanOrEqual(44);
    }
  });

  test("the enlargement: a swipe changes the photo, it never downloads the 2560 px file, and it fits the screen", async ({ page }) => {
    const requested: string[] = [];
    page.on("request", (request) => requested.push(request.url()));
    await openProduct(page);
    await waitForHydration(page);
    await page.getByRole("button", { name: /^Powiększ zdjęcie 1 z 3/ }).tap();
    await expect(dialog(page)).toBeVisible();
    const counter = dialog(page).getByText(/^\d \/ 3$/);
    await expect(counter).toHaveText("1 / 3");

    const zoomStrip = dialog(page).getByRole("group", { name: "Powiększone zdjęcia" });
    await swipe(page, zoomStrip, "next");
    await expect(counter).toHaveText("2 / 3");
    await swipe(page, zoomStrip, "next");
    await expect(counter).toHaveText("3 / 3");
    await dialog(page).locator("img").nth(2).evaluate((img: HTMLImageElement) => img.decode());

    expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
    const zoomed = requested.filter((url) => /rs:fit:\d+:0/.test(url));
    expect(zoomed.length).toBeGreaterThan(0);
    expect(zoomed.filter((url) => url.includes("rs:fit:2560:0") || url.includes("rs:fit:1920:0"))).toEqual([]);
    // The photo on the screen (the third one) fits the window whole.
    const box = (await dialog(page).locator("img").nth(2).boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(PHONE.width);
    expect(box.y + box.height).toBeLessThanOrEqual(PHONE.height);

    await dialog(page).getByRole("button", { name: "Zamknij" }).tap();
    await expect(dialog(page)).toBeHidden();
  });

  test("axe finds no serious or critical violation on the phone, also with the enlargement open", async ({ page }) => {
    await openProduct(page);
    await waitForHydration(page);
    const audit = async () =>
      (await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze()).violations
        .filter((violation) => violation.impact === "serious" || violation.impact === "critical")
        .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(" | ")}`);
    expect(await audit()).toEqual([]);
    await page.getByRole("button", { name: /^Powiększ zdjęcie 1 z 3/ }).tap();
    await expect(dialog(page)).toBeVisible();
    expect(await audit()).toEqual([]);
  });
});
