import { DOMWrapper, flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import InventoryView from "@/views/InventoryView.vue";
import type { WithOptionalTotal } from "./listPage";

type StockList = WithOptionalTotal<components["schemas"]["StockListResponse"]>;
type StockListItem = components["schemas"]["StockListItemResponse"];
type ListedStock = components["schemas"]["ListedStockResponse"];
type HistoryEntry = components["schemas"]["StockHistoryItemResponse"];
type HistoryPage = components["schemas"]["StockHistoryResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const STOCK = "/api/v1/admin/stock";
const HISTORY = "/api/v1/admin/variants/{variantId}/stock-history";

/** A product with one default variant (empty label) unless `variants` names others; `stock` is that variant's. */
function item(name: string, overrides: { stock?: ListedStock | null; variants?: StockListItem["variants"] } = {}): StockListItem {
  return {
    productId: `id-${name}`,
    name,
    variants: overrides.variants ?? [{ variantId: `var-${name}`, label: "", stock: overrides.stock === undefined ? { physical: 10, reserved: 4, available: 6, level: "in_stock", version: 3, updatedAt: "2026-11-02T12:30:00+00:00" } : overrides.stock }],
  };
}

function entry(overrides: Partial<HistoryEntry> = {}): HistoryEntry {
  return {
    occurredAt: "2026-11-01T12:30:00+00:00",
    type: "receipt",
    physicalDelta: 12,
    reservedDelta: 0,
    physicalAfter: 12,
    reservedAfter: 0,
    reason: null,
    note: null,
    reference: null,
    source: "staff",
    actorName: "Anna Admin",
    ...overrides,
  };
}

/** Answers the stock list with the given rows and the history by the `after` cursor of the request ("" for the first page). */
function serve(histories: Record<string, HistoryPage> | number, items: StockListItem[] = [item("A")]) {
  mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) => {
    if (path === HISTORY) {
      return typeof histories === "number"
        ? { data: undefined, response: { status: histories } }
        : { data: histories[options?.params?.query?.after ?? ""], response: { status: 200 } };
    }
    const list: StockList = { items, next: null };
    return path === STOCK ? { data: list, response: { status: 200 } } : { data: [], response: { status: 200 } };
  });
}

function historyCalls() {
  return mocks.get.mock.calls.filter(([path]) => path === HISTORY).map(([, options]) => options.params);
}

async function settle() {
  await vi.advanceTimersByTimeAsync(0);
  await flushPromises();
}

/** Everything is in the body: the windows are teleported there, so a query goes through the whole document. */
const page = () => new DOMWrapper(document.body);

async function mountView(roles: StaffMe["roles"] = ["support"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/inventory", name: "inventory", component: empty },
      { path: "/", name: "dashboard", component: empty },
    ],
  });
  await router.push("/inventory");
  const wrapper = mount(InventoryView, { global: { plugins: [pinia, router] }, attachTo: document.body });
  await settle();
  return wrapper;
}

describe("InventoryView: stock history", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.useRealTimers();
  });

  async function openHistory(row = 0) {
    await page().findAll("[data-testid=stock-row]")[row]!.get("[data-testid=show-history]").trigger("click");
    await settle();
  }

  const cells = (testId: string) => page().findAll(`[data-testid=${testId}]`).map((cell) => cell.text());

  it.each([[["admin"]], [["marketing"]], [["support"]], [["warehouse"]], [[]]] as StaffMe["roles"][][])(
    "offers the history on every row to a staff member with the roles %j, without asking the API yet",
    async (roles) => {
      serve({}, [item("A"), item("B")]);
      wrapper = await mountView(roles);

      expect(page().findAll("[data-testid=show-history]")).toHaveLength(2);
      expect(historyCalls()).toEqual([]);
    },
  );

  it("keeps the receipt and correction buttons for admin and warehouse only", async () => {
    serve({});
    wrapper = await mountView(["support"]);

    expect(page().find("[data-testid=receive-stock]").exists()).toBe(false);
    expect(page().find("[data-testid=correct-stock]").exists()).toBe(false);
    wrapper.unmount();

    wrapper = await mountView(["warehouse"]);
    expect(page().find("[data-testid=receive-stock]").exists()).toBe(true);
    expect(page().find("[data-testid=correct-stock]").exists()).toBe(true);
    expect(page().find("[data-testid=show-history]").exists()).toBe(true);
  });

  it("shows the entries newest first with labels for the type, the reason and the source", async () => {
    serve({
      "": {
        items: [
          entry({
            occurredAt: "2026-11-03T09:00:00+00:00",
            type: "correction",
            physicalDelta: -3,
            physicalAfter: 17,
            reservedAfter: 4,
            reason: "damage",
            note: "Zalane w magazynie",
            source: "staff",
            actorName: "Jan Kowalski",
          }),
          entry({ occurredAt: "2026-11-02T09:00:00+00:00", type: "reservation", physicalDelta: 0, reservedDelta: 4, physicalAfter: 20, reservedAfter: 4, reference: "order-1", source: "system", actorName: null }),
          entry({ occurredAt: "2026-11-01T09:00:00+00:00", type: "receipt", physicalDelta: 20, physicalAfter: 20, reference: "seed:p", source: "seed", actorName: null }),
        ],
        next: null,
      },
    });
    wrapper = await mountView();

    await openHistory();

    expect(historyCalls()).toEqual([{ path: { variantId: "var-A" }, query: {} }]);
    const dialog = page().get("[data-testid=history-dialog]");
    expect(dialog.text()).toContain("A");
    expect(dialog.findAll("[data-testid=history-row]")).toHaveLength(3);
    expect(cells("history-type")).toEqual(["Korekta", "Rezerwacja", "Przyjęcie"]);
    expect(cells("history-reason")).toEqual(["Uszkodzenie", "—", "—"]);
    expect(cells("history-source")).toEqual(["Pracownik", "System", "Dane demo"]);
    expect(cells("history-actor")).toEqual(["Jan Kowalski", "—", "—"]);
    expect(cells("history-physical-delta")).toEqual(["−3", "0", "+20"]);
    expect(cells("history-reserved-delta")).toEqual(["0", "+4", "0"]);
    expect(cells("history-physical-after")).toEqual(["17", "20", "20"]);
    expect(cells("history-reserved-after")).toEqual(["4", "4", "0"]);
    expect(cells("history-note")).toEqual(["Zalane w magazynie", "—", "—"]);
    expect(cells("history-reference")).toEqual(["—", "order-1", "seed:p"]);
    expect(cells("history-occurred-at")[0]).toMatch(/^03\.11\.2026.*10:00:00$/);
    expect(dialog.find("[data-testid=history-more]").exists()).toBe(false);
  });

  it("labels the release and the commit of a reservation and the other reasons of a correction", async () => {
    serve({
      "": {
        items: [
          entry({ type: "commit", physicalDelta: -2, reservedDelta: -2 }),
          entry({ type: "release", physicalDelta: 0, reservedDelta: -2 }),
          entry({ type: "correction", reason: "inventory_count" }),
          entry({ type: "correction", reason: "loss" }),
          entry({ type: "correction", reason: "other" }),
        ],
        next: null,
      },
    });
    wrapper = await mountView();

    await openHistory();

    expect(cells("history-type")).toEqual(["Zatwierdzenie", "Zwolnienie", "Korekta", "Korekta", "Korekta"]);
    expect(cells("history-reason")).toEqual(["—", "—", "Inwentaryzacja", "Zaginięcie", "Inne"]);
  });

  it("opens the history of the variant of the row, named after its product and label", async () => {
    serve({ "": { items: [entry()], next: null } }, [
      item("Kajak", { variants: [{ variantId: "v-green", label: "zielony", stock: null }, { variantId: "v-orange", label: "pomarańczowy", stock: null }] }),
    ]);
    wrapper = await mountView();

    await openHistory(1);

    expect(historyCalls()).toEqual([{ path: { variantId: "v-orange" }, query: {} }]);
    expect(page().get("[data-testid=history-dialog]").text()).toContain("Kajak / pomarańczowy");
  });

  it("loads further entries with 'Pokaż więcej' using the cursor of the API, until the last page", async () => {
    serve({
      "": { items: [entry({ physicalDelta: 3 }), entry({ physicalDelta: 2 })], next: "c1" },
      c1: { items: [entry({ physicalDelta: 1 })], next: null },
    });
    wrapper = await mountView();
    await openHistory();

    expect(cells("history-physical-delta")).toEqual(["+3", "+2"]);
    expect(page().get("[data-testid=history-more]").text()).toBe("Pokaż więcej");

    await page().get("[data-testid=history-more]").trigger("click");
    await settle();

    expect(historyCalls().at(-1)).toEqual({ path: { variantId: "var-A" }, query: { after: "c1" } });
    expect(cells("history-physical-delta")).toEqual(["+3", "+2", "+1"]);
    expect(page().find("[data-testid=history-more]").exists()).toBe(false);
  });

  it("says so when the variant has no recorded stock change", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    await openHistory();

    expect(page().findAll("[data-testid=history-row]")).toHaveLength(0);
    expect(page().get("[data-testid=history-empty]").text()).toBe("Produkt nie ma jeszcze zmian stanu.");
  });

  it("shows the error of the API and loads again on retry", async () => {
    serve(500);
    wrapper = await mountView();

    await openHistory();

    expect(page().get("[data-testid=history-error]").text()).toContain("Nie udało się pobrać historii stanów (HTTP 500).");
    expect(page().find("[data-testid=history-empty]").exists()).toBe(false);

    serve({ "": { items: [entry()], next: null } });
    await page().get("[data-testid=history-retry]").trigger("click");
    await settle();

    expect(page().find("[data-testid=history-error]").exists()).toBe(false);
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(1);
  });

  it("closes the dialog and forgets the entries, and another variant starts from its first page", async () => {
    serve({ "": { items: [entry()], next: null } }, [item("A"), item("B")]);
    wrapper = await mountView();
    await openHistory(0);

    await page().get("[data-testid=close-history]").trigger("click");
    await settle();
    expect(page().find("[data-testid=history-dialog]").exists()).toBe(false);

    await openHistory(1);
    expect(historyCalls().map((params) => params.path.variantId)).toEqual(["var-A", "var-B"]);
    expect(page().get("[data-testid=history-dialog]").text()).toContain("B");
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(1);
  });
  it("is a dialog titled „Historia stanu” in the wide width, with the 11 columns in a named region that the keyboard reaches", async () => {
    serve({ "": { items: [entry()], next: null } });
    wrapper = await mountView();

    await openHistory();

    const dialog = page().get("[role=dialog]");
    expect(dialog.attributes("data-testid")).toBe("history-dialog");
    expect(page().get("[role=dialog] [data-slot=dialog-title]").text()).toBe("Historia stanu");
    expect(dialog.classes()).toContain("w-[min(56rem,calc(100vw-2rem))]");
    const region = dialog.get("[role=region]");
    expect(region.attributes("aria-label")).toBe("Historia stanu");
    expect(region.attributes("tabindex")).toBe("0");
    expect(dialog.findAll("thead th").map((head) => head.text())).toEqual([
      "Data",
      "Rodzaj",
      "Zmiana fizycznego",
      "Stan fizyczny po zmianie",
      "Zmiana zarezerwowanego",
      "Stan zarezerwowanego po zmianie",
      "Powód",
      "Notatka",
      "Referencja",
      "Źródło",
      "Pracownik",
    ]);
  });

  it("keeps Data, Rodzaj, Zmiana fizycznego and Stan fizyczny po zmianie on a narrow screen and gives the rest as lines of the date cell", async () => {
    serve({
      "": {
        items: [entry({ type: "correction", physicalDelta: -3, reservedDelta: 0, physicalAfter: 17, reservedAfter: 4, reason: "damage", note: "Zalane", reference: "ref-1", source: "staff", actorName: "Jan Kowalski" })],
        next: null,
      },
    });
    wrapper = await mountView();

    await openHistory();

    const heads = page().findAll("[role=dialog] thead th");
    expect(heads.map((head) => head.classes().includes("hidden"))).toEqual([false, false, false, false, true, true, true, true, true, true, true]);
    const row = page().get("[data-testid=history-row]");
    expect(row.findAll("[data-testid^=line-]").map((line) => line.text().replace(/\s+/g, " "))).toEqual([
      "Zmiana zarezerwowanego: 0",
      "Stan zarezerwowanego po zmianie: 4",
      "Powód: Uszkodzenie",
      "Notatka: Zalane",
      "Referencja: ref-1",
      "Źródło: Pracownik",
      "Pracownik: Jan Kowalski",
    ]);
  });

  it("gives the focus back to „Historia” of the row when it closes", async () => {
    serve({ "": { items: [entry()], next: null } }, [item("A"), item("B")]);
    wrapper = await mountView();

    await openHistory(1);
    await page().get("[data-testid=close-history]").trigger("click");
    await settle();

    expect(document.activeElement).toBe(page().findAll("[data-testid=show-history]")[1]!.element);
  });

  it("keeps the entries and offers a retry when a further page fails, and puts the focus on „Zamknij” when the retry button goes", async () => {
    serve({ "": { items: [entry({ physicalDelta: 3 })], next: "c1" } });
    wrapper = await mountView();
    await openHistory();
    const answerWithFirstPage = mocks.get.getMockImplementation()!;
    mocks.get.mockImplementation(async (path: string, options?: { params?: { query?: { after?: string } } }) =>
      path === HISTORY && options?.params?.query?.after ? { data: undefined, response: { status: 500 } } : answerWithFirstPage(path, options),
    );

    await page().get("[data-testid=history-more]").trigger("click");
    await settle();
    expect(page().get("[data-testid=history-error]").text()).toContain("HTTP 500");
    expect(page().findAll("[data-testid=history-row]")).toHaveLength(1);

    serve({ "": { items: [entry({ physicalDelta: 3 })], next: "c1" }, c1: { items: [entry({ physicalDelta: 1 })], next: null } });
    await page().get("[data-testid=history-retry]").trigger("click");
    await settle();

    expect(page().findAll("[data-testid=history-row]")).toHaveLength(2);
    expect(page().find("[data-testid=history-error]").exists()).toBe(false);
    expect(document.activeElement).toBe(page().get("[data-testid=close-history]").element);
  });
});
