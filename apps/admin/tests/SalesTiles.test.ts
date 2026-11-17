import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import SalesTiles from "@/dashboard/SalesTiles.vue";
import type { Dashboard } from "./dashboardSupport";

type Sales = NonNullable<Dashboard["sales"]>;
type Period = Sales["today"];

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});

const NO_FIGURES: Period = { revenue: null, orderCount: null, averageOrderValue: null, changeBasisPoints: null };

function sales(overrides: Partial<Sales> = {}): Sales {
  return {
    dataUntil: "2026-11-15T13:00:00+00:00",
    currency: "PLN",
    today: { revenue: 1_234_550, orderCount: 3, averageOrderValue: 411_517, changeBasisPoints: 1250 },
    last7Days: { revenue: 9_000_000, orderCount: 40, averageOrderValue: 225_000, changeBasisPoints: -320 },
    last30Days: { revenue: 0, orderCount: 0, averageOrderValue: null, changeBasisPoints: null },
    ...overrides,
  };
}

async function mountTiles(value: Sales) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/", component: { template: "<div />" } }] });
  await router.push("/");
  wrapper = mount(SalesTiles, { props: { sales: value }, global: { plugins: [router] } });
  return wrapper;
}

/** The text without the no-break and narrow no-break spaces of Intl. */
function text(element: { text(): string }) {
  return element.text().replace(/[  ]/g, " ");
}

function tiles(tilesWrapper: VueWrapper) {
  return tilesWrapper.findAll("[data-testid=kpi-tile]");
}

describe("SalesTiles", () => {
  it("shows the revenue of today, the last 7 and the last 30 days in a region named „Sprzedaż”", async () => {
    const tilesWrapper = await mountTiles(sales());

    expect(tilesWrapper.get("section").attributes("aria-label")).toBe("Sprzedaż");
    expect(tiles(tilesWrapper).map((tile) => tile.get("[data-testid=kpi-title]").text())).toEqual(["Sprzedaż dziś", "Sprzedaż 7 dni", "Sprzedaż 30 dni"]);
    expect(tiles(tilesWrapper).map((tile) => text(tile.get("[data-testid=kpi-value]")))).toEqual(["12 345,50 zł", "90 000,00 zł", "0,00 zł"]);
  });

  it("shows the number of orders and the average in the footer, „—” without an average", async () => {
    const tilesWrapper = await mountTiles(sales());

    // The label and the value of the footer, after its dot.
    expect(tiles(tilesWrapper).map((tile) => tile.get("[data-testid=kpi-footer]").findAll("span").slice(1).map(text))).toEqual([
      ["Zamówienia: 3", "śr. 4115,17 zł"],
      ["Zamówienia: 40", "śr. 2250,00 zł"],
      ["Zamówienia: 0", "śr. —"],
    ]);
  });

  it("shows the change against the period before, green up, red down, and no comparison without one", async () => {
    const tilesWrapper = await mountTiles(sales({ last30Days: { revenue: 100, orderCount: 1, averageOrderValue: 100, changeBasisPoints: 0 } }));

    const trends = tiles(tilesWrapper).map((tile) => tile.get("[data-testid=kpi-trend]"));
    expect(trends.map((trend) => trend.text())).toEqual(["+12,5% vs wczoraj", "-3,2% vs poprzednie 7 dni", "0% vs poprzednie 30 dni"]);
    expect(trends[0]?.classes()).toContain("text-status-success");
    expect(trends[1]?.classes()).toContain("text-status-danger");
    expect(trends[2]?.classes()).toContain("text-muted-foreground");

    await tilesWrapper.setProps({ sales: sales() });
    const noComparison = tiles(tilesWrapper)[2]?.get("[data-testid=kpi-trend]");
    expect(noComparison?.text()).toBe("brak danych do porównania");
    expect(noComparison?.classes()).toContain("text-muted-foreground");
  });

  it("names the hour the data reach in Polish time, and tells that today counts by the hours of the clock", async () => {
    const tilesWrapper = await mountTiles(sales());

    expect(tilesWrapper.get("[data-testid=sales-until]").text()).toBe("Dane do 14:00");
    expect(tiles(tilesWrapper)[0]?.get("[data-testid=kpi-description]").text()).toBe("Od północy do pełnej godziny, wg godzin zegara.");
    // Summer time: 13:00 UTC is 15:00 in Warsaw.
    await tilesWrapper.setProps({ sales: sales({ dataUntil: "2026-07-01T13:00:00+00:00" }) });
    expect(tilesWrapper.get("[data-testid=sales-until]").text()).toBe("Dane do 15:00");
  });

  it("shows „Pierwsze dane po 1:00” for today between midnight and one, without a footer or a change", async () => {
    const tilesWrapper = await mountTiles(sales({ dataUntil: "2026-11-14T23:00:00+00:00", today: NO_FIGURES }));

    const today = tiles(tilesWrapper)[0];
    expect(today?.get("[data-testid=kpi-value]").text()).toBe("—");
    expect(today?.get("[data-testid=kpi-description]").text()).toBe("Pierwsze dane po 1:00");
    expect(today?.get("[data-testid=kpi-value]").attributes("aria-describedby")).toBe(today?.get("[data-testid=kpi-description]").attributes("id"));
    expect(today?.find("[data-testid=kpi-footer]").exists()).toBe(false);
    expect(today?.find("[data-testid=kpi-trend]").exists()).toBe(false);
    expect(tilesWrapper.get("[data-testid=sales-until]").text()).toBe("Dane do 00:00");
  });
});
