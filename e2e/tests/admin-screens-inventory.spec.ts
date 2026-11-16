import { expect, test, type Page } from "@playwright/test";
import { boxOf, documentWidths, expectControlsReachable, expectWithinWidth, settle, viewports, WCAG_TAGS } from "../support/panel-layout";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";

// The stock list of the panel (docs/features/admin-screens/spec.md, „Ceny i Stany magazynowe”): the look and the
// accessibility of the list and of its three windows, the state of the list in the address, and the check of the report
// #343. The tests only open the windows and change nothing (a receipt is in stock-receipt.spec.ts).

// A demo product with variants (apps/api/fixtures/catalog.yaml: the axis „Kolor”), so its rows have labels, and one
// without axes, whose only row is the default variant.
const WITH_VARIANTS = "Mata do ćwiczeń Lumora Podłoże 6";
const DEFAULT_ONLY = "Hantle regulowane Lumora Para 10";

test.beforeAll(async ({ browser }) => {
  test.setTimeout(120_000);
  await signInPanelRoles(browser, ["warehouse", "support"]);
});

test.afterAll(closePanelContexts);

async function openInventory(page: Page, address = "/inventory"): Promise<void> {
  await go(page, address);
  await expect(page.getByRole("heading", { level: 1, name: "Stany magazynowe" })).toBeVisible();
  await expect(page.getByTestId("stock-row").first()).toBeVisible();
}

const states: PanelState[] = [
  {
    name: "the stock list",
    role: "warehouse",
    open: async (page) => {
      await openInventory(page);
      return null;
    },
  },
  {
    name: "the stock list with the window „Przyjęcie”",
    role: "warehouse",
    overlay: true,
    open: async (page) => {
      await openInventory(page);
      await page.getByTestId("receive-stock").first().click();
      const dialog = page.getByRole("dialog", { name: "Przyjęcie" });
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the stock list with the window „Korekta”",
    role: "warehouse",
    overlay: true,
    open: async (page) => {
      await openInventory(page);
      await page.getByTestId("correct-stock").first().click();
      const dialog = page.getByRole("dialog", { name: "Korekta" });
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the stock list with the window of the history",
    role: "warehouse",
    overlay: true,
    open: async (page) => {
      await openInventory(page);
      await page.getByTestId("show-history").first().click();
      const dialog = page.getByRole("dialog", { name: "Historia stanu" });
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
    test(`${state.name} lies in 375 px (x >= 0, x + width <= 375)`, async () => {
      test.setTimeout(60_000);
      await inState(state, viewports.phone, async (page, overlay) => {
        await expectWithinWidth(overlay!, viewports.phone.width, state.name);
        const { x, width } = await boxOf(overlay!);
        expect(x, "the left edge").toBeGreaterThanOrEqual(0);
        expect(x + width, "the right edge").toBeLessThanOrEqual(375);
        await expect.poll(async () => (await documentWidths(page)).scrollWidth).toBeLessThanOrEqual(viewports.phone.width);
      });
    });
  }
});

test("#343: axe finds no button without a name and no aria-command-name on the list, and the filter of the category is named „Kategoria”", async () => {
  test.setTimeout(60_000);
  await inState(states[0]!, viewports.desktop, async (page) => {
    expect(await violationsOf(page, null, ["aria-command-name", "button-name", "label"])).toEqual([]);
    await expect(page.getByRole("combobox", { name: "Kategoria" })).toBeVisible();
  });
});

test("the history is a scrolling region with a name that the keyboard reaches", async () => {
  test.setTimeout(60_000);
  for (const size of [viewports.desktop, viewports.reflow]) {
    await inState(states[3]!, size, async (page, dialog) => {
      const region = dialog!.getByRole("region", { name: "Historia stanu" });
      await expect(region).toHaveAttribute("tabindex", "0");
      let focused = false;
      for (let press = 0; press < 8 && !focused; press++) {
        await page.keyboard.press("Tab");
        focused = await region.evaluate((element) => element === document.activeElement);
      }
      expect(focused, `Tab reaches the region of the history at ${size.width} px`).toBe(true);
      // 11 columns from 1024 px, the four of the highest priority below it.
      await expect(dialog!.getByRole("columnheader")).toHaveCount(size.width >= 1024 ? 11 : 4);
    });
  }
});

test("the focus goes back to the button of the row when a window closes with Escape", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("warehouse", viewports.desktop);
  try {
    await openInventory(page);
    for (const [button, name] of [
      ["receive-stock", "Przyjęcie"],
      ["correct-stock", "Korekta"],
      ["show-history", "Historia stanu"],
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

test("the windows of a receipt and a correction open with the focus in their first field", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("warehouse", viewports.desktop);
  try {
    await openInventory(page);

    await page.getByTestId("receive-stock").first().click();
    await expect(page.getByRole("textbox", { name: "Przyjęta ilość (szt.)" })).toBeFocused();
    await page.keyboard.press("Escape");

    await page.getByTestId("correct-stock").first().click();
    await expect(page.getByRole("textbox", { name: "Stan policzony (szt.)" })).toBeFocused();
    await expect(page.getByRole("combobox", { name: "Powód" })).toContainText("Inwentaryzacja");
  } finally {
    await page.close();
  }
});

test("a staff member without the roles admin and warehouse has the history but not „Przyjęcie” and „Korekta”", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("support", viewports.desktop);
  try {
    await openInventory(page);

    await expect(page.getByTestId("show-history").first()).toBeVisible();
    await expect(page.getByTestId("receive-stock")).toHaveCount(0);
    await expect(page.getByTestId("correct-stock")).toHaveCount(0);
  } finally {
    await page.close();
  }
});

test.describe("the list", () => {
  test("groups the variants under the row of their product, with the level as a label", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("warehouse", viewports.desktop);
    try {
      await openInventory(page, `/inventory?q=${encodeURIComponent(WITH_VARIANTS)}`);

      const group = page.getByTestId("product-row").filter({ has: page.getByTestId("name").getByText(WITH_VARIANTS, { exact: true }) });
      await expect(group).toHaveCount(1);
      await expect(group.locator("th[scope=rowgroup]")).toHaveCount(1);
      await expect(group.getByRole("button")).toHaveCount(0);
      const rows = group.locator("xpath=following-sibling::tr[@data-testid='stock-row']");
      expect(await rows.count(), "a row per variant under the product").toBeGreaterThan(1);
      await expect(rows.first().getByTestId("variant-label")).not.toHaveText("Wariant domyślny");
      await expect(rows.first().getByRole("button", { name: `Przyjęcie: ${WITH_VARIANTS} / ` })).toBeVisible();
      await expect(rows.first().getByTestId("level")).toHaveText(/^(Dostępny|Ostatnie sztuki|Niedostępny)$/);
    } finally {
      await page.close();
    }
  });

  test("shows „Wariant domyślny” for a product without variant axes", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("warehouse", viewports.desktop);
    try {
      await openInventory(page, `/inventory?q=${encodeURIComponent(DEFAULT_ONLY)}`);

      const group = page.getByTestId("product-row").filter({ has: page.getByTestId("name").getByText(DEFAULT_ONLY, { exact: true }) });
      await expect(group.locator("xpath=following-sibling::tr[@data-testid='stock-row'][1]").getByTestId("variant-label")).toHaveText("Wariant domyślny");
    } finally {
      await page.close();
    }
  });

  test("shows the category of the address with its results (/inventory?category=<id>)", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("warehouse", viewports.desktop);
    try {
      await openInventory(page);
      const filter = page.getByRole("combobox", { name: "Kategoria" });
      await filter.fill("Kajaki");
      await page.getByRole("option", { name: /Kajaki/ }).first().click();
      await expect(page).toHaveURL(/category=/);
      const id = new URL(page.url()).searchParams.get("category");
      expect(id, "the address has the category").not.toBeNull();

      await openInventory(page, `/inventory?category=${id}`);

      await expect(page.getByRole("combobox", { name: "Kategoria" })).toHaveValue("Kajaki");
      await expect(page.getByTestId("clear-filters").first()).toBeVisible();
      await expect(page.getByTestId("product-row").first()).toBeVisible();
      await expect(page.getByTestId("name").first()).toContainText(/kajak/i);
    } finally {
      await page.close();
    }
  });

  test("keeps the search and the page size in the address, without a new entry of the history", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("warehouse", viewports.desktop);
    try {
      await openInventory(page, "/inventory?q=hantle&perPage=25");
      await expect(page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" })).toHaveValue("hantle");
      await expect(page.getByTestId("per-page")).toHaveText("25");
      await expect(page.getByTestId("name").first()).toContainText(/hantle/i);

      const entries = await page.evaluate(() => history.length);
      await page.getByRole("combobox", { name: "Kategoria" }).click();
      await page.getByRole("option").nth(1).click();
      await expect(page).toHaveURL(/category=/);
      expect(await page.evaluate(() => history.length), "a filter replaces the entry of the history").toBe(entries);

      await page.getByTestId("clear-filters").first().click();
      await expect(page).toHaveURL(`${new URL(page.url()).origin}/inventory`);
      await expect(page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" })).toHaveValue("");
      await expect(page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" })).toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("counts products in „Pokazano produkty X–Y”: the second page of 10 is 11–20 and the total of products follows „z”, whatever the number of variants", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("warehouse", viewports.desktop);
    try {
      await openInventory(page);
      await expect(page.getByTestId("range")).toHaveText(/^Pokazano produkty 1–10 z (ponad )?\d[\d\s]*$/);
      await expect(page.getByTestId("product-row")).toHaveCount(10);

      await page.getByRole("button", { name: "Następna strona" }).click();

      await expect(page.getByTestId("range")).toHaveText(/^Pokazano produkty 11–20 z (ponad )?\d[\d\s]*$/);
      await expect(page.getByTestId("product-row")).toHaveCount(10);
    } finally {
      await page.close();
    }
  });

  test("a failed search shows the message and no row of the old list", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("warehouse", viewports.desktop);
    try {
      await openInventory(page);
      await page.route(
        (url) => url.pathname === "/api/v1/admin/stock" && url.searchParams.get("q") === "hantle",
        (route) => route.fulfill({ status: 500, contentType: "application/problem+json", body: JSON.stringify({ title: "Internal Server Error", status: 500 }) }),
      );

      await page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" }).fill("hantle");

      await expect(page.getByText("Nie udało się pobrać stanów magazynowych")).toBeVisible();
      await expect(page.getByTestId("stock-row")).toHaveCount(0);
      await expect(page.getByRole("button", { name: "Następna strona" })).toHaveCount(0);
    } finally {
      await page.close();
    }
  });
});

test.describe("the columns of lower priority", () => {
  for (const size of [viewports.tablet, viewports.reflow]) {
    test(`Zarezerwowane is a line of the cell Wariant at ${size.width} px${size.width === viewports.tablet.width ? ", and the actions and the level of the first row are in view" : ""}`, async () => {
      test.setTimeout(60_000);
      const page = await newPanelPage("warehouse", size);
      try {
        await openInventory(page);
        const row = page.getByTestId("stock-row").first();

        await expect(row.getByTestId("line-reserved")).toBeVisible();
        await expect(row.getByTestId("line-reserved")).toHaveText(/^Zarezerwowane: \d+$/);
        await expect(row.getByTestId("reserved")).toBeHidden();
        if (size.width === viewports.tablet.width) {
          for (const control of [row.getByTestId("receive-stock"), row.getByTestId("correct-stock"), row.getByTestId("show-history"), row.getByTestId("level")]) {
            await expectWithinWidth(control, size.width, "an action or the level of the first row");
          }
          const region = page.getByRole("region", { name: "Lista stanów magazynowych" });
          const { scrollWidth, clientWidth } = await region.evaluate((element) => ({ scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }));
          expect(scrollWidth, "the table of 768 px does not scroll sideways").toBeLessThanOrEqual(clientWidth);
        }
      } finally {
        await page.close();
      }
    });
  }

  test("Zarezerwowane is a column at 1280 px, and its line is hidden", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("warehouse", viewports.desktop);
    try {
      await openInventory(page);
      const row = page.getByTestId("stock-row").first();

      await expect(row.getByTestId("reserved")).toBeVisible();
      await expect(row.getByTestId("line-reserved")).toBeHidden();
    } finally {
      await page.close();
    }
  });

  test("the window of the history keeps Data, Rodzaj, Zmiana fizycznego and Stan fizyczny po zmianie at 768 px, the rest as lines of the date cell", async () => {
    test.setTimeout(60_000);
    await inState(states[3]!, viewports.tablet, async (_page, dialog) => {
      await expect(dialog!.getByRole("columnheader", { name: "Zmiana zarezerwowanego" })).toBeHidden();
      await expect(dialog!.getByRole("columnheader", { name: "Stan fizyczny po zmianie" })).toBeVisible();
      const row = dialog!.getByTestId("history-row").first();
      await expect(row.getByTestId("line-reservedDelta")).toBeVisible();
      await expect(row.getByTestId("line-actor")).toBeVisible();
    });
  });
});

test("the windows show the labels of the fields, the buttons of the effect and „Anuluj”", async () => {
  test.setTimeout(60_000);
  await inState(states[1]!, viewports.desktop, async (page, dialog) => {
    await settle(page);
    await expect(dialog!.getByLabel("Przyjęta ilość (szt.)")).toBeVisible();
    await expect(dialog!.getByLabel("Notatka (opcjonalnie)")).toBeVisible();
    await expect(dialog!.getByRole("button", { name: "Przyjmij" })).toBeVisible();
    await expect(dialog!.getByRole("button", { name: "Anuluj" })).toBeVisible();
  });
  await inState(states[2]!, viewports.desktop, async (page, dialog) => {
    await settle(page);
    await expect(dialog!.getByLabel("Stan policzony (szt.)")).toBeVisible();
    await expect(dialog!.getByRole("combobox", { name: "Powód" })).toBeVisible();
    await expect(dialog!.getByRole("button", { name: "Zapisz korektę" })).toBeVisible();
    await expect(dialog!.getByRole("button", { name: "Anuluj" })).toBeVisible();
  });
});
