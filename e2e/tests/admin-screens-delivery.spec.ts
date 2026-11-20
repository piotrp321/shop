import { expect, test, type Page } from "@playwright/test";
import { documentWidths, expectControlsReachable, viewports, WCAG_TAGS } from "../support/panel-layout";
import { openMenuItem, panelMenu } from "../support/panel-menu";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";
import { urls } from "../support/urls";

// The screen „Dostawa” of the panel (docs/features/admin-screens/spec.md, „Dostawa”; docs/features/delivery/spec.md, W9):
// the list and the form of the delivery methods, the errors of the form at their fields and above it, the access of the
// roles, the expired session, and the look and the accessibility of the screens.
//
// The demo methods (apps/api/fixtures/delivery-methods.yaml) are only read. The tests that write create methods of
// their own: a unique name, dearer than the demo ones, position 999 (last among the active methods), and leave them
// inactive, so no other test meets them in the cart or the checkout.

const SEED_STANDARD = "Kurier standardowy";
const SEED_EXPRESS = "Kurier ekspresowy";
const OWN_PREFIX = "E2E dostawa";

const uniqueName = (): string => `${OWN_PREFIX} ${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
const money = (text: string): RegExp => new RegExp(`^${text}\\s*zł$`);

let seedId = "";

test.beforeAll(async ({ browser }) => {
  test.setTimeout(120_000);
  await signInPanelRoles(browser, ["admin", "support"]);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await openList(page);
    const link = rowOf(page, SEED_STANDARD).getByTestId("method-link");
    await expect(link, `the demo method ${SEED_STANDARD} is in the list`).toBeVisible();
    seedId = (await link.getAttribute("href"))!.split("/").pop()!;
  } finally {
    await page.close();
  }
});

test.afterAll(closePanelContexts);

const rowOf = (page: Page, name: string) => page.getByTestId("delivery-row").filter({ has: page.getByRole("link", { name, exact: true }) });

async function openList(page: Page): Promise<void> {
  await go(page, "/delivery");
  await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
  await expect(page.getByTestId("delivery-row").first()).toBeVisible();
}

async function fillMethod(page: Page, method: { name: string; description: string; price: string; freeFrom: string; position: string; tracking: string }): Promise<void> {
  await page.getByLabel("Nazwa", { exact: true }).fill(method.name);
  await page.getByLabel("Opis (czas dostawy)").fill(method.description);
  await page.getByLabel("Cena brutto (zł)").fill(method.price);
  await page.getByLabel("Darmowa od (zł, opcjonalnie)").fill(method.freeFrom);
  await page.getByLabel("Kolejność").fill(method.position);
  await page.getByLabel("Link śledzenia (opcjonalnie)").fill(method.tracking);
}

/** Switches off every method of this test run's family that is still active, so a failed test leaves nothing in the cart. */
async function switchOffOwnMethods(page: Page): Promise<void> {
  await openList(page);
  for (;;) {
    const active = page.getByTestId("delivery-row").filter({ hasText: OWN_PREFIX }).filter({ hasNotText: "Nieaktywna" });
    if ((await active.count()) === 0) return;
    await active.first().getByTestId("method-link").click();
    await expect(page.getByLabel("Nazwa", { exact: true })).toBeVisible();
    await page.getByRole("switch", { name: "Aktywna" }).click();
    await page.getByTestId("save").click();
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
  }
}

test("an administrator adds a method, changes it and switches it off; it stays in the list as „Nieaktywna” below the active ones", async () => {
  test.setTimeout(120_000);
  const page = await newPanelPage("admin", viewports.desktop);
  const name = uniqueName();
  try {
    await go(page, "/");
    await openMenuItem(page, "Dostawa");
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
    await expect(panelMenu(page).getByRole("link", { name: "Dostawa", exact: true })).toHaveAttribute("aria-current", "page");
    await expect(page.getByTestId("active-count")).toHaveText(/^\s*Aktywne: \d+ z 20\s*$/);
    await expect(rowOf(page, SEED_STANDARD).getByTestId("method-price")).toHaveText(money("19,99"));
    await expect(rowOf(page, SEED_EXPRESS).getByTestId("method-price")).toHaveText(money("39,99"));

    // Add: the method is dearer than the demo ones and last among the active ones.
    await page.getByTestId("add-delivery-method").click();
    await expect(page.getByRole("heading", { level: 1, name: "Nowa metoda dostawy" })).toBeVisible();
    await fillMethod(page, { name, description: "Dostawa testowa w 5 dni", price: "99,90", freeFrom: "", position: "999", tracking: "https://e2e.example.com/{numer}" });
    await page.getByTestId("save").click();
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
    await expect(page.getByText("Metoda dostawy zapisana.").first()).toBeVisible();
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" }), "the focus follows the save to the H1 of the list").toBeFocused();
    const added = rowOf(page, name);
    await expect(added.getByTestId("method-price")).toHaveText(money("99,90"));
    await expect(added.getByTestId("method-free-from")).toHaveText("—");
    await expect(added.getByTestId("method-position")).toHaveText("999");
    await expect(added.getByTestId("method-status")).toHaveText("Aktywna");

    // Change: the price and the threshold; the form shows the stored amounts with a comma.
    await added.getByTestId("method-link").click();
    await expect(page.getByLabel("Cena brutto (zł)")).toHaveValue("99,90");
    await expect(page.getByLabel("Link śledzenia (opcjonalnie)")).toHaveValue("https://e2e.example.com/{numer}");
    await page.getByLabel("Cena brutto (zł)").fill("109,50");
    await page.getByLabel("Darmowa od (zł, opcjonalnie)").fill("1 500");
    await page.getByTestId("save").click();
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
    await expect(rowOf(page, name).getByTestId("method-price")).toHaveText(money("109,50"));
    await expect(rowOf(page, name).getByTestId("method-free-from")).toHaveText(money("1\\s?500,00"));

    // Switch off: the method stays in the list, muted and labelled, below every active one.
    await rowOf(page, name).getByTestId("method-link").click();
    await page.getByRole("switch", { name: "Aktywna" }).click();
    await page.getByTestId("save").click();
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
    await expect(rowOf(page, name).getByTestId("method-status")).toHaveText("Nieaktywna");
    const statuses = await page.getByTestId("delivery-row").getByTestId("method-status").allTextContents();
    expect(statuses.lastIndexOf("Aktywna"), "every active method is above the first inactive one").toBeLessThan(statuses.indexOf("Nieaktywna"));

    // The demo methods are as they were.
    await expect(rowOf(page, SEED_STANDARD).getByTestId("method-price")).toHaveText(money("19,99"));
    await expect(rowOf(page, SEED_STANDARD).getByTestId("method-status")).toHaveText("Aktywna");
    await expect(rowOf(page, SEED_EXPRESS).getByTestId("method-price")).toHaveText(money("39,99"));
    await expect(rowOf(page, SEED_EXPRESS).getByTestId("method-status")).toHaveText("Aktywna");
  } finally {
    await switchOffOwnMethods(page).catch(() => undefined);
    await page.close();
  }
});

test("the form shows a rule of the API at its field with the focus on it, and a taken name above the form with the focus on the message", async () => {
  test.setTimeout(90_000);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await go(page, "/delivery/new");
    await fillMethod(page, { name: "", description: "", price: "12,00", freeFrom: "", position: "999", tracking: "http://niebezpieczny.example.com/{numer}" });
    await page.getByTestId("save").click();

    await expect(page.getByTestId("name-error")).toHaveText("Nazwa jest wymagana.");
    await expect(page.getByTestId("tracking-error")).toHaveText("Link śledzenia musi zaczynać się od https:// i mieć nazwę hosta.");
    await expect(page.getByLabel("Nazwa", { exact: true })).toBeFocused();
    await expect(page.getByLabel("Nazwa", { exact: true })).toHaveAttribute("aria-invalid", "true");
    await expect(page).toHaveURL(`${urls.admin}/delivery/new`);

    // A text that is not an amount is shown at the field without a request.
    await page.getByLabel("Nazwa", { exact: true }).fill(uniqueName());
    await page.getByLabel("Cena brutto (zł)").fill("dużo");
    await page.getByTestId("save").click();
    await expect(page.getByTestId("price-error")).toHaveText("Wpisz kwotę w złotych, np. 54,90.");
    await expect(page.getByLabel("Cena brutto (zł)")).toBeFocused();

    // The name of a demo method in another case: 409 above the form, the typed values stay.
    await page.getByLabel("Nazwa", { exact: true }).fill(SEED_STANDARD.toUpperCase());
    await page.getByLabel("Cena brutto (zł)").fill("12,00");
    await page.getByLabel("Link śledzenia (opcjonalnie)").fill("");
    await page.getByTestId("save").click();
    const alert = page.getByTestId("form-error");
    await expect(alert).toHaveText("Metoda dostawy o tej nazwie już istnieje.");
    await expect(alert).toHaveAttribute("role", "alert");
    await expect(alert).toBeFocused();
    await expect(page.getByLabel("Nazwa", { exact: true })).toHaveValue(SEED_STANDARD.toUpperCase());
    await expect(page).toHaveURL(`${urls.admin}/delivery/new`);

    // „Anuluj” asks about the typed values; leaving adds nothing: no method of the test, and the demo method once (the
    // text filter ignores the case).
    await page.getByTestId("cancel").click();
    const dialog = page.getByRole("alertdialog", { name: "Masz niezapisane zmiany" });
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: "Opuść bez zapisu" }).click();
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
    await expect(page.getByTestId("delivery-row").filter({ hasText: OWN_PREFIX })).toHaveCount(0);
    await expect(page.getByTestId("delivery-row").filter({ hasText: SEED_STANDARD })).toHaveCount(1);
  } finally {
    await page.close();
  }
});

test("a role other than admin has no item „Dostawa” in the menu and lands on the dashboard with the message when it opens the address", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("support", viewports.desktop);
  try {
    await go(page, "/");
    await expect(panelMenu(page).getByRole("link", { name: "Dostawa", exact: true })).toHaveCount(0);

    for (const path of ["/delivery", "/delivery/new", `/delivery/${seedId}`]) {
      await page.goto(`${urls.admin}${path}`);
      await expect(page.getByRole("alert").filter({ hasText: "Nie masz dostępu do tej strony." }), path).toBeVisible();
      await expect(page).toHaveURL(`${urls.admin}/`);
    }
  } finally {
    await page.close();
  }
});

test("an expired session (401) sends the administrator to sign in like on the other screens, and the list loads after the return", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    // Once: the next request is the real one, so the screen loads after the sign-in through the session of Keycloak.
    await page.route("**/api/v1/admin/delivery-methods", (route) => route.fulfill({ status: 401, contentType: "application/problem+json", body: JSON.stringify({ title: "Unauthorized", status: 401 }) }), { times: 1 });
    const signIn = page.waitForRequest((request) => request.url().includes("/protocol/openid-connect/auth"));

    await page.goto(`${urls.admin}/delivery`);

    await signIn;
    await expect(page.getByRole("heading", { level: 1, name: "Dostawa" })).toBeVisible();
    await expect(rowOf(page, SEED_STANDARD)).toBeVisible();
  } finally {
    await page.close();
  }
});

const states: PanelState[] = [
  {
    name: "the list of the delivery methods",
    role: "admin",
    open: async (page) => {
      await openList(page);
      return null;
    },
  },
  {
    name: "the form of a new delivery method",
    role: "admin",
    open: async (page) => {
      await go(page, "/delivery/new");
      await expect(page.getByRole("heading", { level: 1, name: "Nowa metoda dostawy" })).toBeVisible();
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toBeVisible();
      return null;
    },
  },
  {
    name: "the form of a delivery method",
    role: "admin",
    open: async (page) => {
      await go(page, `/delivery/${seedId}`);
      await expect(page.getByRole("heading", { level: 1, name: SEED_STANDARD })).toBeVisible();
      return null;
    },
  },
  {
    name: "the form with errors at its fields and above it",
    role: "admin",
    open: async (page) => {
      await go(page, "/delivery/new");
      await page.getByLabel("Cena brutto (zł)").fill("12,00");
      await page.getByLabel("Link śledzenia (opcjonalnie)").fill("http://niebezpieczny.example.com/{numer}");
      await page.getByTestId("save").click();
      await expect(page.getByTestId("name-error")).toBeVisible();
      await expect(page.getByTestId("tracking-error")).toBeVisible();
      return null;
    },
  },
];

test.describe("axe with the rules of WCAG 2.2 AA", () => {
  for (const state of states) {
    test(`${state.name} has no violation at 1280 and 768 px`, async () => {
      test.setTimeout(90_000);
      for (const size of [viewports.desktop, viewports.tablet]) {
        await inState(state, size, async (page) => {
          expect(await violationsOf(page, WCAG_TAGS), `axe at ${size.width} px`).toEqual([]);
        });
      }
    });
  }
});

test.describe("reflow at 320 x 640 px and the phone at 375 px", () => {
  for (const state of states) {
    for (const size of [viewports.reflow, viewports.phone]) {
      test(`${state.name} at ${size.width} px does not scroll sideways and every control is reachable and wholly visible`, async () => {
        test.setTimeout(90_000);
        await inState(state, size, async (page, overlay) => {
          const widths = await documentWidths(page);
          expect(widths.scrollWidth, "the document does not scroll sideways").toBe(widths.clientWidth);
          await expectControlsReachable(page, overlay);
          const after = await documentWidths(page);
          expect(after.scrollWidth).toBe(after.clientWidth);
        });
      });
    }
  }
});
