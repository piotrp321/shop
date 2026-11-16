import AxeBuilder from "@axe-core/playwright";
import { expect, type Browser, type BrowserContext, type Locator, type Page } from "@playwright/test";
import { staff } from "./accounts";
import { settle } from "./panel-layout";
import { loginStaff } from "./staff-login";
import { urls } from "./urls";

// Helpers shared by the spec files that check the screens and windows of the panel (axe, reflow, phone width): a staff
// member logs in once per role (Keycloak asks for the password and the code once), every page after that opens in the
// same browser context and Keycloak's session signs it in without a question, so the tests do not wait for a new
// one-time code. A spec file signs in its roles in `beforeAll` (`signInPanelRoles`) and closes them in `afterAll`
// (`closePanelContexts`).

export type PanelRole = keyof typeof staff;
export type WindowSize = { width: number; height: number };

/** One screen or window of the panel the checks of a spec file run on. */
export type PanelState = {
  name: string;
  role: PanelRole;
  /** Opens the screen (and the window); returns the window as the element the controls of the check are in, or null for a plain screen. */
  open: (page: Page) => Promise<Locator | null>;
  /** The widths the state exists at: the drawer of the menu replaces the sidebar below 1024 px only. */
  minWidth?: number;
  maxWidth?: number;
  /** A window or a menu: the width checks of the phone (criterion 8) apply. */
  overlay?: boolean;
};

const contexts = new Map<PanelRole, BrowserContext>();

async function signIn(browser: Browser, role: PanelRole): Promise<BrowserContext> {
  const context = await browser.newContext({ locale: "pl-PL" });
  context.setDefaultTimeout(10_000);
  context.setDefaultNavigationTimeout(20_000);
  const page = await context.newPage();
  await loginStaff(page, staff[role]);
  await page.close();
  return context;
}

/** Logs in each role once; the pages of `newPanelPage` share that session. */
export async function signInPanelRoles(browser: Browser, roles: PanelRole[]): Promise<void> {
  for (const role of roles) contexts.set(role, await signIn(browser, role));
}

/** Closes the contexts of `signInPanelRoles`. */
export async function closePanelContexts(): Promise<void> {
  await Promise.all([...contexts.values()].map((context) => context.close()));
  contexts.clear();
}

/** A new page of the signed-in staff member at the size of the window; the caller closes it. */
export async function newPanelPage(role: PanelRole, size: WindowSize): Promise<Page> {
  const context = contexts.get(role);
  expect(context, `the role ${role} is signed in (signInPanelRoles)`).toBeDefined();
  const page = await context!.newPage();
  await page.setViewportSize(size);
  return page;
}

/** Opens an address of the panel and waits for the page to be the panel (the sign-in by the session of Keycloak passes by itself). */
export async function go(page: Page, path: string): Promise<void> {
  await page.goto(`${urls.admin}${path}`);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
}

/** Opens the state at the size, runs the check, and closes the page (a page with unsaved changes would ask before it leaves). */
export async function inState(state: PanelState, size: WindowSize, check: (page: Page, overlay: Locator | null) => Promise<void>): Promise<void> {
  const page = await newPanelPage(state.role, size);
  try {
    const overlay = await state.open(page);
    await settle(page);
    await check(page, overlay);
  } finally {
    await page.close();
  }
}

/** The axe violations of the page, as text: the rules with these tags (all of them for null), restricted to `rules` when given. */
export async function violationsOf(page: Page, tags: string[] | null, rules?: string[]): Promise<string[]> {
  let builder = new AxeBuilder({ page });
  if (tags !== null) builder = builder.withTags(tags);
  if (rules !== undefined) builder = builder.withRules(rules);
  const results = await builder.analyze();
  return results.violations.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`);
}
