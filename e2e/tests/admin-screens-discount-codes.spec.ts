import { expect, test, type Page } from "@playwright/test";
import { documentWidths, expectControlsReachable, expectWithinWidth, settle, viewports, WCAG_TAGS } from "../support/panel-layout";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";

// The list and the form of the discount codes of the panel (docs/features/admin-screens/spec.md, „Promocje i kody
// rabatowe”): the look and the accessibility of the list, of the form with the window of unsaved changes and of the
// page of a code that does not exist, the state of the list in the address, and the check of the report #446. The tests
// change nothing: the save of the form is in admin-screens-toasts.spec.ts and admin-design.spec.ts.

// The demo codes (apps/api/fixtures/discount-codes.yaml): an active one with no end and an expired one.
const ACTIVE_CODE = "WITAJ10";
const EXPIRED_CODE = "LATO15";
const MISSING_ID = "00000000-0000-4000-8000-000000000404";

let activeCodeId = "";

test.beforeAll(async ({ browser }) => {
  test.setTimeout(120_000);
  await signInPanelRoles(browser, ["admin", "support"]);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await go(page, `/discount-codes?q=${ACTIVE_CODE}`);
    const link = page.getByTestId("code-link").filter({ hasText: ACTIVE_CODE }).first();
    await expect(link, `the demo code ${ACTIVE_CODE} is in the list`).toBeVisible();
    activeCodeId = (await link.getAttribute("href"))!.split("/").pop()!;
  } finally {
    await page.close();
  }
});

test.afterAll(closePanelContexts);

async function openList(page: Page, address = "/discount-codes"): Promise<void> {
  await go(page, address);
  await expect(page.getByRole("heading", { level: 1, name: "Kody rabatowe" })).toBeVisible();
  await expect(page.getByTestId("discount-code-row").first()).toBeVisible();
}

const states: PanelState[] = [
  {
    name: "the list of discount codes",
    role: "admin",
    open: async (page) => {
      await openList(page);
      return null;
    },
  },
  {
    name: "the form of a new discount code",
    role: "admin",
    open: async (page) => {
      await go(page, "/discount-codes/new");
      await expect(page.getByRole("heading", { level: 1, name: "Nowy kod rabatowy" })).toBeVisible();
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toBeVisible();
      return null;
    },
  },
  {
    name: "the form of a started discount code",
    role: "admin",
    open: async (page) => {
      await go(page, `/discount-codes/${activeCodeId}`);
      await expect(page.getByRole("heading", { level: 1, name: ACTIVE_CODE })).toBeVisible();
      await expect(page.getByTestId("terms-locked-note")).toBeVisible();
      return null;
    },
  },
  {
    name: "the form of a new discount code with the window of unsaved changes",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await go(page, "/discount-codes/new");
      await page.getByTestId("code-input").fill("ZMIANA");
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toBeVisible();
      // The breadcrumbs lead away at every width, the menu only at the wide ones.
      await page.getByRole("navigation", { name: "Okruszki" }).getByRole("link", { name: "Pulpit" }).click();
      const dialog = page.getByRole("alertdialog", { name: "Masz niezapisane zmiany" });
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the page of a discount code that does not exist",
    role: "admin",
    open: async (page) => {
      await go(page, `/discount-codes/${MISSING_ID}`);
      await expect(page.getByRole("heading", { level: 1, name: "Nie udało się wczytać kodu rabatowego" })).toBeVisible();
      await expect(page.getByTestId("load-error")).toContainText("Ten kod rabatowy nie istnieje.");
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

test("the window of unsaved changes lies in 375 px", async () => {
  test.setTimeout(60_000);
  await inState(states[3]!, viewports.phone, async (page, overlay) => {
    await expectWithinWidth(overlay!, viewports.phone.width, "the window of unsaved changes");
    await expect.poll(async () => (await documentWidths(page)).scrollWidth).toBeLessThanOrEqual(viewports.phone.width);
  });
});

test("#446: axe finds no color-contrast violation in the message of a code that does not exist", async () => {
  test.setTimeout(60_000);
  await inState(states[4]!, viewports.desktop, async (page) => {
    expect(await violationsOf(page, null, ["color-contrast"])).toEqual([]);
    await expect(page.getByTestId("load-error").getByRole("link", { name: "Wróć do listy kodów rabatowych" })).toBeVisible();
  });
});

test.describe("the list", () => {
  test("shows the filters of the address and the rows that match: an active code that starts with LATO does not exist, so the empty state offers „Wyczyść filtry”", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/discount-codes?status=active&q=LATO");
      await expect(page.getByRole("heading", { level: 1, name: "Kody rabatowe" })).toBeVisible();

      await expect(page.getByRole("searchbox", { name: "Szukaj po początku kodu" })).toHaveValue("LATO");
      await expect(page.getByTestId("status-filter")).toHaveText("Aktywne");
      await expect(page.getByTestId("empty")).toContainText("Brak wyników dla wybranych filtrów.");
      await expect(page.getByTestId("discount-code-row")).toHaveCount(0);
    } finally {
      await page.close();
    }
  });

  test("shows the expired code for ?status=expired&q=lato, whatever the case of the search", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page, "/discount-codes?status=expired&q=lato");

      await expect(page.getByTestId("code-link")).toHaveText([EXPIRED_CODE]);
      await expect(page.getByTestId("code-status")).toHaveText("Wygasły");
    } finally {
      await page.close();
    }
  });

  test("keeps the filter in the address without a new entry of the history, and „Wyczyść filtry” returns to /discount-codes", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page);
      const entries = await page.evaluate(() => history.length);

      await page.getByTestId("status-filter").click();
      await page.getByRole("option", { name: "Wygasłe" }).click();

      await expect(page).toHaveURL(/\/discount-codes\?status=expired$/);
      await expect(page.getByTestId("code-link").first()).toHaveText(EXPIRED_CODE);
      expect(await page.evaluate(() => history.length), "a filter replaces the entry of the history").toBe(entries);

      await page.getByRole("searchbox", { name: "Szukaj po początku kodu" }).fill("lato");
      await expect(page).toHaveURL(/\/discount-codes\?q=lato&status=expired$/);
      expect(await page.evaluate(() => history.length)).toBe(entries);

      await page.getByTestId("clear-filters").first().click();
      await expect(page).toHaveURL(`${new URL(page.url()).origin}/discount-codes`);
      await expect(page.getByRole("searchbox", { name: "Szukaj po początku kodu" })).toHaveValue("");
      await expect(page.getByRole("searchbox", { name: "Szukaj po początku kodu" })).toBeFocused();
      await expect(page.getByTestId("status-filter")).toHaveText("Wszystkie");
    } finally {
      await page.close();
    }
  });

  test("#336: a search of only an invisible character leaves the whole list and asks for no q", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page);
      const requests: string[] = [];
      page.on("request", (request) => {
        if (new URL(request.url()).pathname === "/api/v1/admin/discount-codes") requests.push(request.url());
      });

      await page.getByRole("searchbox", { name: "Szukaj po początku kodu" }).fill("​");
      await page.waitForTimeout(800);

      expect(requests.filter((url) => new URL(url).searchParams.has("q"))).toEqual([]);
      await expect(page).toHaveURL(`${new URL(page.url()).origin}/discount-codes`);
      await expect(page.getByTestId("discount-code-row").first()).toBeVisible();
    } finally {
      await page.close();
    }
  });

  test("links the code to its form, and an admin has „Edytuj: <kod>”", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page, `/discount-codes?q=${ACTIVE_CODE}`);

      await expect(page.getByRole("link", { name: `Edytuj: ${ACTIVE_CODE}` })).toHaveAttribute("href", `/discount-codes/${activeCodeId}`);
      await page.getByTestId("code-link").first().click();
      await expect(page.getByRole("heading", { level: 1, name: ACTIVE_CODE })).toBeVisible();
    } finally {
      await page.close();
    }
  });
});

test.describe("the columns of lower priority", () => {
  for (const size of [viewports.tablet, viewports.reflow]) {
    test(`the minimum and the period are lines of the cell Kod at ${size.width} px${size.width === viewports.tablet.width ? ", and the action and the status of the first row are in view" : ""}`, async () => {
      test.setTimeout(60_000);
      const page = await newPanelPage("admin", size);
      try {
        await openList(page);
        const row = page.getByTestId("discount-code-row").first();

        await expect(row.getByTestId("line-minimum")).toBeVisible();
        await expect(row.getByTestId("line-period")).toBeVisible();
        await expect(row.getByTestId("code-minimum")).toBeHidden();
        await expect(row.getByTestId("code-period")).toBeHidden();
        if (size.width === viewports.tablet.width) {
          for (const control of [row.getByTestId("action-edit"), row.getByTestId("code-status")]) {
            await expectWithinWidth(control, size.width, "the action or the status of the first row");
          }
          const region = page.getByRole("region", { name: "Lista kodów rabatowych" });
          const { scrollWidth, clientWidth } = await region.evaluate((element) => ({ scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }));
          expect(scrollWidth, "the table of 768 px does not scroll sideways").toBeLessThanOrEqual(clientWidth);
        }
      } finally {
        await page.close();
      }
    });
  }

  test("the minimum and the period are columns at 1280 px, and their lines are hidden", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openList(page);
      const row = page.getByTestId("discount-code-row").first();

      await expect(row.getByTestId("code-minimum")).toBeVisible();
      await expect(row.getByTestId("code-period")).toBeVisible();
      await expect(row.getByTestId("line-minimum")).toBeHidden();
    } finally {
      await page.close();
    }
  });
});

test.describe("the form", () => {
  test("shows the save bar when the switch „Aktywny” changes, and „Odrzuć” brings the state back and puts the focus on the H1", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, `/discount-codes/${activeCodeId}`);
      await expect(page.getByRole("heading", { level: 1, name: ACTIVE_CODE })).toBeVisible();
      const bar = page.getByRole("region", { name: "Zapis formularza" });
      await expect(bar).toHaveCount(0);

      const active = page.getByRole("switch", { name: "Aktywny" });
      await expect(active).toBeChecked();
      await active.click();
      await expect(bar).toBeVisible();

      await bar.getByRole("button", { name: "Odrzuć" }).click();
      await expect(bar).toHaveCount(0);
      await expect(active).toBeChecked();
      await expect(page.getByRole("heading", { level: 1, name: ACTIVE_CODE })).toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("asks before leaving with unsaved changes, and „Zostań” leaves the form as it was", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/discount-codes/new");
      await page.getByTestId("code-input").fill("ZOSTAN");
      await page.getByRole("navigation", { name: "Okruszki" }).getByRole("link", { name: "Kody rabatowe" }).click();

      const dialog = page.getByRole("alertdialog", { name: "Masz niezapisane zmiany" });
      await expect(dialog).toBeVisible();
      await expect(dialog.getByRole("button", { name: "Zostań" })).toBeFocused();
      await dialog.getByRole("button", { name: "Zostań" }).press("Enter");

      await expect(dialog).toBeHidden();
      await expect(page).toHaveURL(/\/discount-codes\/new$/);
      await expect(page.getByTestId("code-input")).toHaveValue("ZOSTAN");
    } finally {
      await page.close();
    }
  });

  test("the kind of the discount is a group of radio buttons that the arrow keys move", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/discount-codes/new");
      const group = page.getByRole("radiogroup", { name: "Rodzaj rabatu" });
      await expect(group.getByRole("radio", { name: "Procent" })).toBeChecked();

      await group.getByRole("radio", { name: "Procent" }).focus();
      // Reka checks the radio the arrow key moved to when the key is still down (it reads the key state on focus), so the key is held.
      await page.keyboard.down("ArrowRight");
      await expect(group.getByRole("radio", { name: "Kwota" })).toBeChecked();
      await page.keyboard.up("ArrowRight");
      await expect(page.getByText("Kwota rabatu (zł)")).toBeVisible();
    } finally {
      await page.close();
    }
  });

  test("a role without the right to change codes sees the list without „Dodaj kod” and „Edytuj”, and the code read-only, without the save bar", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("support", viewports.desktop);
    try {
      await openList(page, `/discount-codes?q=${ACTIVE_CODE}`);
      await expect(page.getByTestId("add-discount-code")).toHaveCount(0);
      await expect(page.getByTestId("action-edit")).toHaveCount(0);

      await page.getByTestId("code-link").first().click();
      await expect(page.getByRole("heading", { level: 1, name: ACTIVE_CODE })).toBeVisible();
      await expect(page.getByTestId("read-only-note")).toBeVisible();
      await expect(page.getByTestId("value-input")).toBeDisabled();
      await expect(page.getByRole("switch", { name: "Aktywny" })).toBeDisabled();
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toHaveCount(0);
    } finally {
      await page.close();
    }
  });

  test("puts the side column before the main one in the document: Tab starts on the switch „Aktywny”", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, `/discount-codes/${activeCodeId}`);
      await settle(page);
      await page.getByRole("heading", { level: 1 }).focus();
      await page.keyboard.press("Tab");
      // The breadcrumbs come before the content: the first stop after the H1 is a link, then the switch.
      const order = await page.evaluate(() => {
        const aside = document.querySelector("[data-testid=enabled]")!;
        const main = document.querySelector("[data-testid=value-input]")!;
        return aside.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING ? "side first" : "main first";
      });
      expect(order).toBe("side first");
    } finally {
      await page.close();
    }
  });
});
