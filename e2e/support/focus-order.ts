import { expect, type Locator, type Page } from "@playwright/test";

/** A focusable element that is rendered (not `display: none`): where it is, what it is called, whether the eye can see it. */
export type Focusable = { label: string; x: number; cy: number; onScreen: boolean };

/** Rows closer than this (the distance of the vertical centers, in px) are one row; the controls of one row are centered on it. */
const ROW_TOLERANCE = 20;

const FOCUSABLE = 'a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]';

/**
 * The focusable elements inside `root` in the document order (the order every browser tabs in without a CSS reordering
 * feature). `onScreen` is false for an element that a scrolling container (the strip of the photos) hides or that is
 * outside the window.
 */
export async function focusablesInDomOrder(root: Locator): Promise<Focusable[]> {
  return root.evaluate((container, selector) => {
    const clipped = (item: HTMLElement, rect: DOMRect): boolean => {
      const x = rect.left + rect.width / 2;
      const y = rect.top + rect.height / 2;
      if (x < 0 || y < 0 || x > innerWidth || y > innerHeight * 4) return true;
      for (let parent = item.parentElement; parent !== null && parent !== container; parent = parent.parentElement) {
        const style = getComputedStyle(parent);
        if (style.overflowX === "visible" && style.overflowY === "visible") continue;
        const box = parent.getBoundingClientRect();
        if (x < box.left || x > box.right || y < box.top || y > box.bottom) return true;
      }
      return false;
    };
    return [...container.querySelectorAll<HTMLElement>(selector)]
      .filter((item) => item.tabIndex >= 0 && !(item as HTMLButtonElement).disabled && item.checkVisibility({ checkVisibilityCSS: true }))
      .map((item) => {
        const rect = item.getBoundingClientRect();
        const label = (item.getAttribute("aria-label") || item.innerText || item.getAttribute("name") || item.tagName).trim().replace(/\s+/g, " ").slice(0, 40);
        return { label: `${item.tagName.toLowerCase()}:${label}`, x: Math.round(rect.left), cy: Math.round(rect.top + rect.height / 2), onScreen: !clipped(item, rect) };
      });
  }, FOCUSABLE);
}

/** The elements grouped into rows, from the top. */
function rowsOf<T extends { x: number; cy: number }>(items: T[]): T[][] {
  const rows: T[][] = [];
  for (const item of [...items].sort((a, b) => a.cy - b.cy)) {
    const row = rows.at(-1);
    if (row !== undefined && item.cy - row[0]!.cy < ROW_TOLERANCE) row.push(item);
    else rows.push([item]);
  }
  return rows;
}

/** The same elements in the order of the eye: row by row from the top, each row from the left. */
export const visualOrder = <T extends { x: number; cy: number }>(items: T[]): T[] => rowsOf(items).flatMap((row) => row.sort((a, b) => a.x - b.x));

/** How many rows the elements are on. */
export const rowCount = (items: { x: number; cy: number }[]): number => rowsOf(items).length;

/**
 * The document order of the focusable elements of `root` that are on the screen equals their order on the screen (so a
 * browser that tabs in the document order, which every browser does without a CSS reordering feature, tabs in the visual
 * order), and the real Tab key visits all the focusable elements in the document order. Returns the labels.
 */
export async function expectTabOrderIsVisualOrder(page: Page, root: Locator): Promise<string[]> {
  const items = await focusablesInDomOrder(root);
  const onScreen = items.filter((item) => item.onScreen);
  expect(onScreen.length).toBeGreaterThan(1);
  expect(onScreen.map((item) => item.label), "the document order is the visual order").toEqual(visualOrder(onScreen).map((item) => item.label));

  // Number the elements in the document order, start on the first, then the real Tab key goes through the rest.
  await root.evaluate((container, selector) => {
    let n = 0;
    for (const item of container.querySelectorAll<HTMLElement>(selector)) {
      if (item.tabIndex >= 0 && !(item as HTMLButtonElement).disabled && item.checkVisibility({ checkVisibilityCSS: true })) item.dataset.focusN = String(n++);
    }
  }, FOCUSABLE);
  await root.locator('[data-focus-n="0"]').focus();
  const seen: string[] = [];
  for (let step = 0; step < items.length; step++) {
    seen.push(await page.evaluate(() => (document.activeElement as HTMLElement | null)?.dataset.focusN ?? "outside"));
    await page.keyboard.press("Tab");
  }
  expect(seen, "the Tab key follows the document order").toEqual(items.map((_, n) => String(n)));
  return items.map((item) => item.label);
}
