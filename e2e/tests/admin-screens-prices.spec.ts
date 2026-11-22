import { expect, test, type Page } from "@playwright/test";
import { boxOf, documentWidths, expectControlsReachable, expectWithinWidth, settle, textContrast, viewports, WCAG_TAGS } from "../support/panel-layout";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";

// The price list of the panel (docs/features/admin-screens/spec.md, „Ceny i Stany magazynowe”): the look and the
// accessibility of the list and of its two windows, the state of the list in the address, and the checks of the reports
// #135, #272, #273, #343 and #393. The tests only open the windows and change nothing (the change of a price is in
// price-change.spec.ts).

// A demo product with variants (apps/api/fixtures/catalog.yaml: the axis „Kolor”), so its rows have labels, and one
// without axes, whose only row is the default variant.
const WITH_VARIANTS = "Mata do ćwiczeń Lumora Podłoże 6";
const DEFAULT_ONLY = "Hantle regulowane Lumora Para 10";

test.beforeAll(async ({ browser }) => {
  test.setTimeout(120_000);
  await signInPanelRoles(browser, ["admin", "marketing"]);
});

test.afterAll(closePanelContexts);

async function openPrices(page: Page, address = "/prices"): Promise<void> {
  await go(page, address);
  await expect(page.getByRole("heading", { level: 1, name: "Ceny" })).toBeVisible();
  await expect(page.getByTestId("price-row").first()).toBeVisible();
}

const states: PanelState[] = [
  {
    name: "the price list",
    role: "admin",
    open: async (page) => {
      await openPrices(page);
      return null;
    },
  },
  {
    name: "the price list with the window „Zmień cenę”",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await openPrices(page);
      await page.getByTestId("change-price").first().click();
      const dialog = page.getByRole("dialog", { name: "Zmień cenę" });
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the price list with the window of the history",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await openPrices(page);
      await page.getByTestId("show-history").first().click();
      const dialog = page.getByRole("dialog", { name: "Historia ceny" });
      await expect(dialog).toBeVisible();
      await expect(dialog.getByTestId("history-row").first()).toBeVisible();
      return dialog;
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

test.describe("the windows fit in a phone", () => {
  for (const state of states.filter((candidate) => candidate.overlay)) {
    test(`${state.name} lies in 375 px`, async () => {
      test.setTimeout(60_000);
      await inState(state, viewports.phone, async (page, overlay) => {
        await expectWithinWidth(overlay!, viewports.phone.width, state.name);
        await expect.poll(async () => (await documentWidths(page)).scrollWidth).toBeLessThanOrEqual(viewports.phone.width);
      });
    });
  }
});

test("#393: the window „Zmień cenę” lies in 375 px (x >= 0, x + width <= 375)", async () => {
  test.setTimeout(60_000);
  await inState(states[1]!, viewports.phone, async (_page, overlay) => {
    const { x, width } = await boxOf(overlay!);
    expect(x, "the left edge").toBeGreaterThanOrEqual(0);
    expect(x + width, "the right edge").toBeLessThanOrEqual(375);
  });
});

test("#135: axe finds no color-contrast violation on the list and in the window „Zmień cenę”, and „Zapisz cenę” has a contrast of at least 4.5:1", async () => {
  test.setTimeout(60_000);
  await inState(states[0]!, viewports.desktop, async (page) => {
    expect(await violationsOf(page, null, ["color-contrast"])).toEqual([]);
  });
  await inState(states[1]!, viewports.desktop, async (page, dialog) => {
    expect(await violationsOf(page, null, ["color-contrast"])).toEqual([]);
    expect(await textContrast(dialog!.getByRole("button", { name: "Zapisz cenę" }))).toBeGreaterThanOrEqual(4.5);
  });
});

test("#343: axe finds no button without a name and no aria-command-name on the list, and the filter of the category is named „Kategoria”", async () => {
  test.setTimeout(60_000);
  await inState(states[0]!, viewports.desktop, async (page) => {
    expect(await violationsOf(page, null, ["aria-command-name", "button-name", "label"])).toEqual([]);
    await expect(page.getByRole("combobox", { name: "Kategoria" })).toBeVisible();
  });
});

test("#272: a failed search shows the message and no row of the old list", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await openPrices(page);
    await page.route(
      (url) => url.pathname === "/api/v1/admin/prices" && url.searchParams.get("q") === "hantle",
      (route) => route.fulfill({ status: 500, contentType: "application/problem+json", body: JSON.stringify({ title: "Internal Server Error", status: 500 }) }),
    );

    await page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" }).fill("hantle");

    await expect(page.getByText("Nie udało się pobrać cen")).toBeVisible();
    await expect(page.getByTestId("price-row")).toHaveCount(0);
  } finally {
    await page.close();
  }
});

test("#273: the window „Zmień cenę” opened with the Enter key has the focus in the field of the price", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await openPrices(page);
    await page.getByTestId("change-price").first().focus();

    await page.keyboard.press("Enter");

    await expect(page.getByRole("dialog", { name: "Zmień cenę" })).toBeVisible();
    await expect(page.getByTestId("gross-input")).toBeFocused();
  } finally {
    await page.close();
  }
});

test("the focus goes back to the button of the row when a window closes with Escape", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await openPrices(page);
    for (const [button, name] of [
      ["change-price", "Zmień cenę"],
      ["show-history", "Historia ceny"],
    ] as const) {
      await page.getByTestId(button).first().focus();
      await page.keyboard.press("Enter");
      await expect(page.getByRole("dialog", { name })).toBeVisible();
      await page.keyboard.press("Escape");
      await expect(page.getByRole("dialog", { name })).toBeHidden();
      await expect(page.getByTestId(button).first()).toBeFocused();
    }
  } finally {
    await page.close();
  }
});

test.describe("the list", () => {
  test("groups the variants under the row of their product, with the name of the tax class in Polish", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openPrices(page, `/prices?q=${encodeURIComponent(WITH_VARIANTS)}`);

      const group = page.getByTestId("product-row").filter({ has: page.getByTestId("name").getByText(WITH_VARIANTS, { exact: true }) });
      await expect(group).toHaveCount(1);
      await expect(group.locator("th[scope=rowgroup]")).toHaveCount(1);
      await expect(group.getByTestId("tax-class")).toHaveText(/^[A-ZĄĆĘŁŃÓŚŹŻ][^_]+$/);
      await expect(group.getByRole("button")).toHaveCount(0);
      const rows = group.locator("xpath=following-sibling::tr[@data-testid='price-row']");
      expect(await rows.count(), "a row per variant under the product").toBeGreaterThan(1);
      await expect(rows.first().getByTestId("variant-label")).not.toHaveText("Wariant domyślny");
      await expect(rows.first().getByRole("button", { name: `Zmień cenę: ${WITH_VARIANTS} / ` })).toBeVisible();
    } finally {
      await page.close();
    }
  });

  test("#579: the history of a price shows the tax class of its variant as the row of the list does", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openPrices(page, `/prices?q=${encodeURIComponent(WITH_VARIANTS)}`);

      const group = page.getByTestId("product-row").filter({ has: page.getByTestId("name").getByText(WITH_VARIANTS, { exact: true }) });
      const listed = (await group.getByTestId("tax-class").innerText()).trim();
      expect(listed).toMatch(/^[A-ZĄĆĘŁŃÓŚŹŻ][^_]+$/);
      await group.locator("xpath=following-sibling::tr[@data-testid='price-row']").first().getByTestId("show-history").click();

      const dialog = page.getByRole("dialog", { name: "Historia ceny" });
      await expect(dialog.getByTestId("history-row").first()).toBeVisible();
      await expect(dialog.getByTestId("history-tax-class").first()).toHaveText(listed);
    } finally {
      await page.close();
    }
  });

  test("shows „Wariant domyślny” for a product without variant axes", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openPrices(page, `/prices?q=${encodeURIComponent(DEFAULT_ONLY)}`);

      const group = page.getByTestId("product-row").filter({ has: page.getByTestId("name").getByText(DEFAULT_ONLY, { exact: true }) });
      await expect(group.locator("xpath=following-sibling::tr[@data-testid='price-row'][1]").getByTestId("variant-label")).toHaveText("Wariant domyślny");
    } finally {
      await page.close();
    }
  });

  test("keeps the search, the category and the page size in the address, without a new entry of the history", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openPrices(page, "/prices?q=hantle&perPage=25");
      await expect(page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" })).toHaveValue("hantle");
      await expect(page.getByTestId("per-page")).toHaveText("25");
      await expect(page.getByTestId("name").first()).toContainText(/hantle/i);

      const entries = await page.evaluate(() => history.length);
      await page.getByRole("combobox", { name: "Kategoria" }).click();
      await page.getByRole("option").nth(1).click();
      await expect(page).toHaveURL(/category=/);
      expect(await page.evaluate(() => history.length), "a filter replaces the entry of the history").toBe(entries);

      await page.getByTestId("clear-filters").first().click();
      await expect(page).toHaveURL(`${new URL(page.url()).origin}/prices`);
      await expect(page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" })).toHaveValue("");
      await expect(page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" })).toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("counts products in „Pokazano produkty X–Y”: the second page of 10 is 11–20 and the total of products follows „z”, whatever the number of variants", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openPrices(page);
      await expect(page.getByTestId("range")).toHaveText(/^Pokazano produkty 1–10 z (ponad )?\d[\d\s]*$/);
      await expect(page.getByTestId("product-row")).toHaveCount(10);

      await page.getByRole("button", { name: "Następna strona" }).click();

      await expect(page.getByTestId("range")).toHaveText(/^Pokazano produkty 11–20 z (ponad )?\d[\d\s]*$/);
      await expect(page.getByTestId("product-row")).toHaveCount(10);
    } finally {
      await page.close();
    }
  });
});

test.describe("the columns of lower priority", () => {
  for (const size of [viewports.tablet, viewports.reflow]) {
    test(`Netto is a line of the cell Wariant at ${size.width} px${size.width === viewports.tablet.width ? ", and the actions of the first row are in view" : ""}`, async () => {
      test.setTimeout(60_000);
      const page = await newPanelPage("admin", size);
      try {
        await openPrices(page);
        const row = page.getByTestId("price-row").first();

        await expect(row.getByTestId("line-net")).toBeVisible();
        await expect(row.getByTestId("line-net")).toHaveText(/^Netto: .*zł$/);
        await expect(row.getByTestId("net")).toBeHidden();
        if (size.width === viewports.tablet.width) {
          for (const button of [row.getByTestId("change-price"), row.getByTestId("show-history")]) {
            await expectWithinWidth(button, size.width, "an action of the first row");
          }
          const region = page.getByRole("region", { name: "Lista cen" });
          const { scrollWidth, clientWidth } = await region.evaluate((element) => ({ scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }));
          expect(scrollWidth, "the table of 768 px does not scroll sideways").toBeLessThanOrEqual(clientWidth);
        }
      } finally {
        await page.close();
      }
    });
  }

  test("Netto and VAT are columns at 1280 px, and their lines are hidden", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openPrices(page);
      const row = page.getByTestId("price-row").first();

      await expect(row.getByTestId("net")).toBeVisible();
      await expect(row.getByTestId("vat")).toBeVisible();
      await expect(row.getByTestId("line-net")).toBeHidden();
    } finally {
      await page.close();
    }
  });
});

test("a staff member without the role admin has the history but not „Zmień cenę”", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("marketing", viewports.desktop);
  try {
    await openPrices(page);

    await expect(page.getByTestId("show-history").first()).toBeVisible();
    await expect(page.getByTestId("change-price")).toHaveCount(0);
  } finally {
    await page.close();
  }
});

test("the window „Zmień cenę” shows the current price, the rate and the buttons „Zapisz cenę” and „Anuluj”", async () => {
  test.setTimeout(60_000);
  await inState(states[1]!, viewports.desktop, async (page, dialog) => {
    await settle(page);
    await expect(dialog!.getByTestId("current-gross")).toContainText("Obecna cena brutto:");
    await expect(dialog!.getByTestId("rate-preview")).toContainText("Stawka VAT:");
    await expect(dialog!.getByRole("button", { name: "Zapisz cenę" })).toBeVisible();
    await expect(dialog!.getByRole("button", { name: "Anuluj" })).toBeVisible();
  });
});
