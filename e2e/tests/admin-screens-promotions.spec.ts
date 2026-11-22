import { expect, test, type Page } from "@playwright/test";
import { documentWidths, expectControlsReachable, expectWithinWidth, settle, viewports, WCAG_TAGS } from "../support/panel-layout";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";
import { endActivePromotionsOf, PanelToken, toasts, warsawLocal } from "../support/promotions";
import { waitForBearerToken } from "../support/staff-login";

// The list and the form of the promotions of the panel (docs/features/admin-screens/spec.md, „Promocje i kody
// rabatowe”): the look and the accessibility of the list, of the form with the choice of variants and the windows, the
// state of the list in the address, the names of the buttons and the windows of the report #304, and the colour of the
// message of a promotion that does not exist (#446). The save of the form is in promotions.spec.ts; the tests here that
// write plan promotions of their own that start far ahead, and the two that start a promotion „od teraz” (#580) end their own.

// The product of promotions.spec.ts (the demo data keeps it out of its promotions): two variants of one price.
const PRODUCT_NAME = "Elektrolity w tabletkach Lumora Nawodnienie 20";
const MISSING_ID = "00000000-0000-4000-8000-000000000404";
const DAY_MS = 86_400_000;
const WAIT_FOR_END_MS = 30_000;

let activePromotionId = "";

test.beforeAll(async ({ browser }) => {
  test.setTimeout(120_000);
  await signInPanelRoles(browser, ["admin", "support"]);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    // The first active promotion of the demo data (the list only reads it; the tests open its page and leave it as it is).
    await go(page, "/promotions?status=active");
    const link = page.getByTestId("promotion-link").first();
    await expect(link, "the demo data has an active promotion").toBeVisible();
    activePromotionId = (await link.getAttribute("href"))!.split("/").pop()!;
  } finally {
    await page.close();
  }
});

test.afterAll(closePanelContexts);

async function openList(page: Page, address = "/promotions"): Promise<void> {
  await go(page, address);
  await expect(page.getByRole("heading", { level: 1, name: "Promocje" })).toBeVisible();
  await expect(page.getByTestId("promotion-row").first()).toBeVisible();
}

/**
 * A promotion saved „od teraz” starts at the first whole second after the save, so the answer of the save is still
 * planned: the form reads the promotion again once it has started and shows it active, without a reload (#580). When
 * the save itself takes a whole second, the answer is already active: the form does not wait and announces nothing.
 */
async function expectStartedWithoutReload(page: Page, savedStatus: string): Promise<void> {
  // A reload would drop this mark of the document.
  await page.evaluate(() => document.documentElement.setAttribute("data-e2e-not-reloaded", ""));
  const since = Date.now();
  await expect(page.getByTestId("promotion-status"), "the form shows the promotion active by itself").toHaveText("Aktywna", { timeout: 5_000 });
  await expect(page.getByRole("button", { name: "Zakończ teraz" })).toBeEnabled({ timeout: 5_000 });
  expect(Date.now() - since, "„Aktywna” and „Zakończ teraz” within 5 s of the toast").toBeLessThan(5_000);
  if (savedStatus === "planned") {
    await expect(page.getByRole("status").filter({ hasText: "Promocja jest aktywna." })).toBeVisible();
  } else {
    expect(savedStatus, "the answer of the save is planned or already active").toBe("active");
    await expect(page.getByRole("status").filter({ hasText: "Promocja rozpoczyna się." })).toHaveCount(0);
  }
  await expect(page.getByTestId("name-input")).toBeEnabled();
  await expect(page.locator("html"), "no reload of the page").toHaveAttribute("data-e2e-not-reloaded", "");
}

/** The status of the promotion in the answer of the save (set before the click on „Zapisz”). */
function waitForSavedStatus(page: Page): Promise<string> {
  return page
    .waitForResponse((response) => new URL(response.url()).pathname === "/api/v1/admin/promotions" && response.request().method() === "POST")
    .then(async (response) => ((await response.json()) as { status: string }).status);
}

/** Fills the form of a new promotion „od teraz” of the two variants of the product, ending in a day. */
async function fillStartingNow(page: Page, name: string): Promise<void> {
  await go(page, "/promotions/new");
  await page.getByTestId("name-input").fill(name);
  await page.getByTestId("percent-input").fill("10");
  await page.getByTestId("start-now").click();
  await page.getByTestId("ends-input").fill(warsawLocal(Date.now() + DAY_MS));
  await openPickerOn(page);
  await page.getByRole("button", { name: `Dodaj cały produkt: ${PRODUCT_NAME}` }).click();
}

/** The choice of variants of the form of a new promotion, narrowed to the two variants of the product. */
async function openPickerOn(page: Page): Promise<void> {
  await page.getByTestId("open-picker").click();
  await page.getByTestId("picker-search").fill(PRODUCT_NAME);
  await expect(page.getByTestId("picker-row"), "the search narrows the picker to the two variants of the product").toHaveCount(2);
}

const states: PanelState[] = [
  {
    name: "the list of promotions",
    role: "admin",
    open: async (page) => {
      await openList(page);
      return null;
    },
  },
  {
    name: "the form of a new promotion with the choice of variants",
    role: "admin",
    open: async (page) => {
      await go(page, "/promotions/new");
      await expect(page.getByRole("heading", { level: 1, name: "Nowa promocja" })).toBeVisible();
      await openPickerOn(page);
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toBeVisible();
      // The walk of the Tab key of the reflow check starts where the focus is: at the H1, like on every other screen.
      await page.getByRole("heading", { level: 1, name: "Nowa promocja" }).focus();
      return null;
    },
  },
  {
    name: "the form of a new promotion with the window of unsaved changes",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await go(page, "/promotions/new");
      await page.getByTestId("name-input").fill("Zmiana");
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toBeVisible();
      // The breadcrumbs lead away at every width, the menu only at the wide ones.
      await page.getByRole("navigation", { name: "Okruszki" }).getByRole("link", { name: "Pulpit" }).click();
      const dialog = page.getByRole("alertdialog", { name: "Masz niezapisane zmiany" });
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the page of an active promotion with the window „Zakończ teraz”",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await go(page, `/promotions/${activePromotionId}`);
      await expect(page.getByTestId("promotion-status")).toHaveText("Aktywna");
      await page.getByTestId("end-now").click();
      const dialog = page.getByRole("alertdialog", { name: /^Zakończyć promocję .* teraz\?$/ });
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the page of a promotion that does not exist",
    role: "admin",
    open: async (page) => {
      await go(page, `/promotions/${MISSING_ID}`);
      await expect(page.getByRole("heading", { level: 1, name: "Nie udało się wczytać promocji" })).toBeVisible();
      await expect(page.getByTestId("load-error")).toContainText("Ta promocja nie istnieje.");
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

test.describe("reflow at 320 x 640 px", () => {
  for (const state of states) {
    test(`${state.name} does not scroll sideways and every control is reachable and wholly visible`, async () => {
      test.setTimeout(90_000);
      await inState(state, viewports.reflow, async (page, overlay) => {
        const widths = await documentWidths(page);
        expect(widths.scrollWidth, "the document does not scroll sideways").toBe(widths.clientWidth);
        await expectControlsReachable(page, overlay);
        const after = await documentWidths(page);
        expect(after.scrollWidth).toBe(after.clientWidth);
      });
    });
  }
});

test.describe("the windows lie in 375 px", () => {
  for (const state of states.filter((candidate) => candidate.overlay)) {
    test(`${state.name}`, async () => {
      test.setTimeout(60_000);
      await inState(state, viewports.phone, async (page, overlay) => {
        await expectWithinWidth(overlay!, viewports.phone.width, "the window");
        await expect.poll(async () => (await documentWidths(page)).scrollWidth).toBeLessThanOrEqual(viewports.phone.width);
      });
    });
  }
});

test("#446: axe finds no color-contrast violation in the message of a promotion that does not exist", async () => {
  test.setTimeout(60_000);
  await inState(states[4]!, viewports.desktop, async (page) => {
    expect(await violationsOf(page, null, ["color-contrast"])).toEqual([]);
    await expect(page.getByTestId("load-error").getByRole("link", { name: "Wróć do listy promocji" })).toBeVisible();
  });
});

test.describe("the list", () => {
  test("shows the filtered list for /promotions?status=active, with the Select „Status” filled in", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page, "/promotions?status=active");

      await expect(page.getByRole("combobox", { name: "Status" })).toHaveText("Aktywne");
      for (const status of await page.getByTestId("promotion-status").allTextContents()) expect(status.trim()).toBe("Aktywna");
      await expect(page.getByTestId("search"), "the API does not search promotions").toHaveCount(0);
    } finally {
      await page.close();
    }
  });

  test("keeps the status in the address without a new entry of the history, and „Wyczyść filtry” returns to /promotions", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page);
      const entries = await page.evaluate(() => history.length);

      await page.getByRole("combobox", { name: "Status" }).click();
      await page.getByRole("option", { name: "Aktywne" }).click();

      await expect(page).toHaveURL(/\/promotions\?status=active$/);
      await expect(page.getByTestId("promotion-status").first()).toHaveText("Aktywna");
      expect(await page.evaluate(() => history.length), "a filter replaces the entry of the history").toBe(entries);

      await page.getByTestId("clear-filters").first().click();
      await expect(page).toHaveURL(`${new URL(page.url()).origin}/promotions`);
      await expect(page.getByRole("combobox", { name: "Status" })).toHaveText("Wszystkie");
      await expect(page.getByRole("combobox", { name: "Status" })).toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("shows the empty states of the standard: nothing yet, and nothing for the filter with „Wyczyść filtry”", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      // The answer is faked, so the test does not depend on the promotions of the stack.
      await page.route(/\/api\/v1\/admin\/promotions(\?.*)?$/, (route) =>
        route.request().method() === "GET" ? route.fulfill({ json: { items: [], next: null } }) : route.fallback(),
      );

      await go(page, "/promotions");
      await expect(page.getByTestId("empty")).toContainText("Nie ma jeszcze promocji.");
      await expect(page.getByTestId("empty-add-promotion")).toBeVisible();

      await go(page, "/promotions?status=ended");
      await expect(page.getByTestId("empty")).toContainText("Brak wyników dla wybranych filtrów.");
      await page.getByTestId("empty-clear-filters").click();
      await expect(page).toHaveURL(`${new URL(page.url()).origin}/promotions`);
    } finally {
      await page.close();
    }
  });

  test("links the name to its form, and an admin has „Edytuj: <nazwa>”", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page, "/promotions?status=active");
      const link = page.getByTestId("promotion-link").first();
      const name = (await link.textContent())!.trim();

      await expect(page.getByRole("link", { name: `Edytuj: ${name}` }).first()).toHaveAttribute("href", `/promotions/${activePromotionId}`);
      await link.click();
      await expect(page.getByRole("heading", { level: 1, name })).toBeVisible();
    } finally {
      await page.close();
    }
  });
});

test.describe("the columns of lower priority", () => {
  for (const size of [viewports.tablet, viewports.reflow]) {
    test(`the start and the end are lines of the cell Nazwa at ${size.width} px${size.width === viewports.tablet.width ? ", and the action and the status of the first row are in view" : ""}`, async () => {
      test.setTimeout(60_000);
      const page = await newPanelPage("admin", size);
      try {
        await openList(page);
        const row = page.getByTestId("promotion-row").first();

        await expect(row.getByTestId("line-starts")).toBeVisible();
        await expect(row.getByTestId("line-ends")).toBeVisible();
        await expect(row.getByTestId("promotion-starts")).toBeHidden();
        await expect(row.getByTestId("promotion-ends")).toBeHidden();
        if (size.width === viewports.tablet.width) {
          for (const control of [row.getByTestId("action-edit"), row.getByTestId("promotion-status")]) {
            await expectWithinWidth(control, size.width, "the action or the status of the first row");
          }
          const region = page.getByRole("region", { name: "Lista promocji" });
          const { scrollWidth, clientWidth } = await region.evaluate((element) => ({ scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }));
          expect(scrollWidth, "the table of 768 px does not scroll sideways").toBeLessThanOrEqual(clientWidth);
        }
      } finally {
        await page.close();
      }
    });
  }

  test("the start and the end are columns at 1280 px, and their lines are hidden", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page);
      const row = page.getByTestId("promotion-row").first();

      await expect(row.getByTestId("promotion-starts")).toBeVisible();
      await expect(row.getByTestId("promotion-ends")).toBeVisible();
      await expect(row.getByTestId("line-starts")).toBeHidden();
    } finally {
      await page.close();
    }
  });
});

test.describe("the form", () => {
  test("#304 F5: the buttons of the choice of variants are named with the variant, and the focus goes back to the search after an add (F4)", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/promotions/new");
      await openPickerOn(page);

      const addButtons = page.getByRole("button", { name: new RegExp(`^Dodaj: ${PRODUCT_NAME}( / .+)?$`) });
      await expect(addButtons, "a button named with the product and the variant per variant").toHaveCount(2);
      await expect(page.getByRole("button", { name: `Dodaj cały produkt: ${PRODUCT_NAME}` })).toHaveCount(1);

      const first = addButtons.first();
      const name = (await first.getAttribute("aria-label"))!;
      await first.click();

      await expect(page.getByRole("button", { name: name.replace(/^Dodaj:/, "Dodano:") })).toBeDisabled();
      await expect(page.getByRole("button", { name: name.replace(/^Dodaj:/, "Usuń:") })).toBeVisible();
      await expect(page.getByTestId("picker-search"), "the focus goes back to the search").toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("#304 F4: after removing a chosen variant the focus goes to the button of the next row, and after the last one to the search of the picker", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/promotions/new");
      await openPickerOn(page);
      await page.getByRole("button", { name: `Dodaj cały produkt: ${PRODUCT_NAME}` }).click();
      await expect(page.getByTestId("variant-row")).toHaveCount(2);

      await page.getByTestId("variant-row").first().getByTestId("remove-variant").click();
      await expect(page.getByTestId("variant-row")).toHaveCount(1);
      await expect(page.getByTestId("variant-row").first().getByTestId("remove-variant")).toBeFocused();

      await page.getByTestId("variant-row").first().getByTestId("remove-variant").click();
      await expect(page.getByTestId("variant-row")).toHaveCount(0);
      await expect(page.getByTestId("picker-search")).toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("#304 F6: „Zakończ teraz” asks in a window with „Wróć” in the focus, Escape closes it and the focus returns to the button", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, `/promotions/${activePromotionId}`);
      const endNow = page.getByTestId("end-now");
      await endNow.focus();
      await page.keyboard.press("Enter");

      const dialog = page.getByRole("alertdialog", { name: /^Zakończyć promocję .* teraz\?$/ });
      await expect(dialog).toBeVisible();
      await expect(dialog.getByRole("button", { name: "Zakończ promocję teraz" })).toBeVisible();
      await expect(dialog.getByRole("button", { name: "Wróć" })).toBeFocused();

      await page.keyboard.press("Escape");
      await expect(dialog).toBeHidden();
      await expect(endNow).toBeFocused();
      await expect(page.getByTestId("promotion-status"), "nothing was ended").toHaveText("Aktywna");

      await endNow.click();
      await dialog.getByRole("button", { name: "Wróć" }).click();
      await expect(dialog).toBeHidden();
      await expect(endNow).toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("a promotion planned on the form is deleted through the window: Escape and „Wróć” keep it, „Usuń promocję” deletes it with a toast and the focus on the H1 of the list", async () => {
    test.setTimeout(120_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      const name = `E2E usuwana ${Date.now()}`;
      await go(page, "/promotions/new");
      await page.getByTestId("name-input").fill(name);
      await page.getByTestId("percent-input").fill("10");
      // Planned far ahead: nothing changes in the store, and it overlaps no promotion.
      await page.getByTestId("starts-input").fill(warsawLocal(Date.now() + 200 * DAY_MS));
      await page.getByTestId("ends-input").fill(warsawLocal(Date.now() + 201 * DAY_MS));
      await openPickerOn(page);
      await page.getByRole("button", { name: `Dodaj cały produkt: ${PRODUCT_NAME}` }).click();
      await page.getByTestId("save").click();
      await expect(toasts(page).getByText("Promocja zapisana.")).toBeVisible();
      await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]{36}$/);
      await expect(page.getByTestId("promotion-status")).toHaveText("Zaplanowana");
      await expect(page.getByRole("heading", { level: 1, name })).toBeFocused();

      const remove = page.getByTestId("delete");
      await remove.focus();
      await page.keyboard.press("Enter");
      const dialog = page.getByRole("alertdialog", { name: `Usunąć promocję ${name}?` });
      await expect(dialog.getByRole("button", { name: "Wróć" })).toBeFocused();
      await page.keyboard.press("Escape");
      await expect(dialog).toBeHidden();
      await expect(remove).toBeFocused();

      await remove.click();
      await dialog.getByRole("button", { name: "Usuń promocję" }).click();

      await expect(toasts(page).getByText("Promocja usunięta.")).toBeVisible();
      await expect(page).toHaveURL(`${new URL(page.url()).origin}/promotions`);
      await expect(page.getByRole("heading", { level: 1, name: "Promocje" })).toBeFocused();
      await expect(page.getByRole("link", { name })).toHaveCount(0);
    } finally {
      await page.close();
    }
  });

  test("#580, #304 F7: a save „od teraz” shows the promotion active by itself, and after „Zakończ teraz” the form waits for the end, reads the promotion again and shows it ended and read-only, with the toast", async () => {
    test.setTimeout(WAIT_FOR_END_MS + 150_000);
    const page = await newPanelPage("admin", viewports.desktop);
    const pendingToken = waitForBearerToken(page);
    try {
      await go(page, "/promotions");
      const token = await PanelToken.from(pendingToken);
      try {
        // A promotion of the product left by an earlier run would overlap the new one.
        await endActivePromotionsOf(page.request, await token.fresh(page), PRODUCT_NAME);

        await fillStartingNow(page, `E2E koniec ${Date.now()}`);
        const savedStatus = waitForSavedStatus(page);
        await page.getByTestId("save").click();
        await expect(toasts(page).getByText("Promocja zapisana."), "the promotion was rejected (conflict, validation or API error)").toBeVisible();
        await expectStartedWithoutReload(page, await savedStatus);

        await page.getByTestId("end-now").click();
        await page.getByRole("alertdialog").getByRole("button", { name: "Zakończ promocję teraz" }).click();

        await expect(toasts(page).getByText("Promocja zakończona."), "the promotion did not end within the wait").toBeVisible({ timeout: WAIT_FOR_END_MS });
        await expect(page.getByTestId("promotion-status")).toHaveText("Zakończona");
        await expect(page.getByTestId("read-only-note")).toContainText("tylko do odczytu");
        await expect(page.getByTestId("name-input")).toBeDisabled();
        await expect(page.getByTestId("end-now")).toHaveCount(0);
        await expect(page.getByRole("region", { name: "Zapis formularza" })).toHaveCount(0);
        await expect(page.getByRole("heading", { level: 2, name: "Status" }), "the focus stays on the heading of the card").toBeFocused();
      } finally {
        // The next run (and the stack, until the next make seed) must not meet this promotion.
        await endActivePromotionsOf(page.request, await token.fresh(page), PRODUCT_NAME);
      }
    } finally {
      await page.close();
    }
  });

  test("#580: a save „od teraz” whose answer is held for 3 s locks the form meanwhile, and then shows the promotion active without a reload", async () => {
    test.setTimeout(150_000);
    const page = await newPanelPage("admin", viewports.desktop);
    const pendingToken = waitForBearerToken(page);
    try {
      await go(page, "/promotions");
      const token = await PanelToken.from(pendingToken);
      try {
        await endActivePromotionsOf(page.request, await token.fresh(page), PRODUCT_NAME);
        await fillStartingNow(page, `E2E od teraz ${Date.now()}`);
        // The API saves at once; its answer reaches the panel 3 s later.
        let held = 0;
        let savedStatus = "";
        await page.route("**/api/v1/admin/promotions", async (route) => {
          if (route.request().method() !== "POST") {
            await route.fallback();
            return;
          }
          held += 1;
          const response = await route.fetch();
          savedStatus = ((await response.json()) as { status: string }).status;
          await new Promise((resolve) => setTimeout(resolve, 3_000));
          await route.fulfill({ response });
        });

        await page.getByTestId("save").click();

        await expect(page.getByTestId("name-input"), "the field is locked while the answer of the save is held").toBeDisabled();
        await expect(page.getByTestId("picker-search")).toBeDisabled();
        await expect(toasts(page).getByText("Promocja zapisana.")).toHaveCount(0);
        await expect(page.getByTestId("name-input")).toBeDisabled();
        await expect(toasts(page).getByText("Promocja zapisana."), "the promotion was rejected (conflict, validation or API error)").toBeVisible({ timeout: 10_000 });
        expect(held).toBe(1);
        await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]{36}$/);
        await expectStartedWithoutReload(page, savedStatus);
      } finally {
        await page.unrouteAll({ behavior: "ignoreErrors" });
        await endActivePromotionsOf(page.request, await token.fresh(page), PRODUCT_NAME);
      }
    } finally {
      await page.close();
    }
  });

  test("shows the promotion read-only to a role without the right to change it, without the save bar and the actions", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("support", viewports.desktop);
    try {
      await go(page, `/promotions/${activePromotionId}`);
      await expect(page.getByTestId("read-only-note")).toBeVisible();
      await expect(page.getByTestId("name-input")).toBeDisabled();
      await expect(page.getByTestId("end-now")).toHaveCount(0);
      await expect(page.getByTestId("open-picker")).toHaveCount(0);
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toHaveCount(0);

      await openList(page);
      await expect(page.getByTestId("add-promotion")).toHaveCount(0);
      await expect(page.getByTestId("action-edit")).toHaveCount(0);
    } finally {
      await page.close();
    }
  });

  test("from the H1 the Tab key reaches the card „Status” of the side column before the field „Nazwa” of the main one", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, `/promotions/${activePromotionId}`);
      await settle(page);
      await page.getByRole("heading", { level: 1 }).focus();

      const visited: string[] = [];
      for (let step = 0; step < 12 && !visited.includes("name-input"); step++) {
        await page.keyboard.press("Tab");
        visited.push(await page.evaluate(() => document.activeElement?.getAttribute("data-testid") ?? document.activeElement?.tagName ?? ""));
      }

      expect(visited, "the Tab key reaches the field „Nazwa”").toContain("name-input");
      expect(visited.indexOf("end-now"), `„Zakończ teraz” comes before the field „Nazwa” (${visited.join(", ")})`).toBeGreaterThanOrEqual(0);
      expect(visited.indexOf("end-now")).toBeLessThan(visited.indexOf("name-input"));
    } finally {
      await page.close();
    }
  });
});
