import { expect, type Locator, type Page } from "@playwright/test";

/** The window sizes of the panel tests: the widest layout, the tablet and the phone, and the reflow width of WCAG 1.4.10. */
export const viewports = {
  desktop: { width: 1280, height: 720 },
  tablet: { width: 768, height: 1024 },
  phone: { width: 375, height: 812 },
  reflow: { width: 320, height: 640 },
} as const;

/** The rules every screen of the panel has to pass (WCAG 2.2 AA). */
export const WCAG_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];

/** Waits until the fonts are loaded and the animations of dialogs, drawers and menus are over, so a measurement sees the final state. */
export async function settle(page: Page): Promise<void> {
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)));
  });
}

/** Does the document scroll sideways? Both numbers are given, so a failure shows by how much. */
export const documentWidths = (page: Page): Promise<{ scrollWidth: number; clientWidth: number }> =>
  page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));

/** The box of an element in the window; the sides are compared with the width of the window by the caller. */
export async function boxOf(locator: Locator): Promise<{ x: number; width: number; right: number }> {
  const box = await locator.boundingBox();
  expect(box, "the element has a box").not.toBeNull();
  return { x: box!.x, width: box!.width, right: box!.x + box!.width };
}

/** The element lies in the window from side to side. */
export async function expectWithinWidth(locator: Locator, windowWidth: number, what: string): Promise<void> {
  const { x, right } = await boxOf(locator);
  expect(x, `${what}: the left edge is in the window`).toBeGreaterThanOrEqual(0);
  expect(right, `${what}: the right edge is in the window (${windowWidth} px)`).toBeLessThanOrEqual(windowWidth + 0.5);
}

const FOCUSABLE = 'a[href], button, input:not([type="hidden"]), select, textarea, summary, [tabindex]';

/**
 * WCAG 1.4.10 for the part of the screen under `root` (the whole page when it is null, otherwise a window): every
 * control (links, buttons, fields, scrolling regions) is reached by the Tab key and is wholly in the window once it is
 * scrolled into view (`scrollIntoView`, which also scrolls the region of a table), and not hidden under another element
 * (the sticky bar of the form, the top bar). Returns the number of controls.
 */
export async function expectControlsReachable(page: Page, root: Locator | null): Promise<number> {
  const container = root ?? page.locator("body");
  const total = await container.evaluate((element, selector) => {
    let n = 0;
    for (const item of element.querySelectorAll<HTMLElement>(selector)) {
      delete item.dataset.reflowN;
      if (item.tabIndex >= 0 && !(item as HTMLButtonElement).disabled && item.checkVisibility({ checkVisibilityCSS: true })) item.dataset.reflowN = String(n++);
    }
    return n;
  }, FOCUSABLE);
  expect(total, "the screen has controls").toBeGreaterThan(0);

  if (root === null) {
    await page.evaluate(() => {
      (document.activeElement as HTMLElement | null)?.blur();
      window.scrollTo(0, 0);
    });
  } else {
    // A window moves the focus inside itself when it opens; the walk starts there.
    await expect.poll(() => root.evaluate((element) => element.contains(document.activeElement))).toBe(true);
  }

  const visited = new Set<number>();
  const problems: string[] = [];
  for (let step = 0; step < total + 12 && visited.size < total; step++) {
    await page.keyboard.press("Tab");
    const state = await page.evaluate(() => {
      const item = document.activeElement as HTMLElement | null;
      if (item === null || item.dataset.reflowN === undefined) return null;
      item.scrollIntoView({ block: "center", inline: "center" });
      const rect = item.getBoundingClientRect();
      const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
      const label = `${item.tagName.toLowerCase()}:${(item.getAttribute("aria-label") || item.innerText || item.getAttribute("name") || "").trim().replace(/\s+/g, " ").slice(0, 40)}`;
      return {
        n: Number(item.dataset.reflowN),
        label,
        // A scrolling region (a table in its card) is taller than the window by design: it is checked from side to side only.
        inWindow: rect.left >= -0.5 && rect.right <= innerWidth + 0.5 && (rect.height > innerHeight || (rect.top >= -0.5 && rect.bottom <= innerHeight + 0.5)),
        rect: `${Math.round(rect.left)}..${Math.round(rect.right)} x ${Math.round(rect.top)}..${Math.round(rect.bottom)}`,
        covered: rect.height > innerHeight ? false : hit === null || !(item.contains(hit) || hit.contains(item)),
      };
    });
    if (state === null || visited.has(state.n)) continue;
    visited.add(state.n);
    if (!state.inWindow) problems.push(`${state.label} is not wholly in the window (${state.rect})`);
    if (state.covered) problems.push(`${state.label} is covered by another element`);
  }
  const missed = await container.evaluate((element, [selector, seen]) => {
    const seenSet = new Set(seen as number[]);
    return [...element.querySelectorAll<HTMLElement>(selector as string)]
      .filter((item) => item.dataset.reflowN !== undefined && !seenSet.has(Number(item.dataset.reflowN)))
      .map((item) => `${item.tagName.toLowerCase()}:${(item.getAttribute("aria-label") || item.innerText || "").trim().replace(/\s+/g, " ").slice(0, 40)}`);
  }, [FOCUSABLE, [...visited]] as const);
  expect(missed, "every control is reached by the Tab key").toEqual([]);
  expect(problems, "every control is wholly visible after scrolling to it").toEqual([]);
  return total;
}

/** The contrast ratio of WCAG between the colour of the text of the element and the colour behind it, from the computed colours. */
export async function textContrast(locator: Locator): Promise<number> {
  return locator.evaluate((element) => {
    // A canvas turns any CSS colour (hex, rgb, oklch, color-mix results) into sRGB.
    const canvas = document.createElement("canvas");
    canvas.width = canvas.height = 1;
    const context = canvas.getContext("2d", { willReadFrequently: true })!;
    const rgba = (css: string): [number, number, number, number] => {
      context.clearRect(0, 0, 1, 1);
      context.fillStyle = "#000";
      context.fillStyle = css;
      context.fillRect(0, 0, 1, 1);
      const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data;
      return [r!, g!, b!, a! / 255];
    };
    const over = (top: [number, number, number, number], below: [number, number, number]): [number, number, number] => [
      top[0] * top[3] + below[0] * (1 - top[3]),
      top[1] * top[3] + below[1] * (1 - top[3]),
      top[2] * top[3] + below[2] * (1 - top[3]),
    ];
    const layers: [number, number, number, number][] = [];
    for (let node: Element | null = element; node !== null; node = node.parentElement) {
      const layer = rgba(getComputedStyle(node).backgroundColor);
      if (layer[3] > 0) layers.push(layer);
      if (layer[3] === 1) break;
    }
    let background: [number, number, number] = [255, 255, 255];
    for (const layer of layers.reverse()) background = over(layer, background);
    const foreground = over(rgba(getComputedStyle(element).color), background);
    const luminance = ([r, g, b]: [number, number, number]): number => {
      const channel = (value: number): number => {
        const v = value / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
      };
      return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    };
    const [light, dark] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
    return (light! + 0.05) / (dark! + 0.05);
  });
}
