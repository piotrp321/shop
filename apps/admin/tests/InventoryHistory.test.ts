import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import { useAuthStore, type StaffMe } from "@/stores/auth";
import InventoryView from "@/views/InventoryView.vue";

type StockList = components["schemas"]["StockListResponse"];
type StockListItem = components["schemas"]["StockListItemResponse"];
type HistoryEntry = components["schemas"]["StockHistoryItemResponse"];
type HistoryPage = components["schemas"]["StockHistoryResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

const STOCK = "/api/v1/admin/stock";
const HISTORY = "/api/v1/admin/products/{productId}/stock-history";

function item(name: string, overrides: Partial<StockListItem> = {}): StockListItem {
  return {
    productId: `id-${name}`,
    name,
    stock: { physical: 10, reserved: 4, available: 6, level: "in_stock", version: 3, updatedAt: "2026-11-02T12:30:00+00:00" },
    ...overrides,
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

async function mountView(roles: StaffMe["roles"] = ["support"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  // The dialogs are teleported to <body>; rendered in place they can be found in the wrapper.
  const wrapper = mount(InventoryView, { global: { plugins: [pinia, [PrimeVue, primeVueOptions]] as never[], stubs: { teleport: true } } });
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
    vi.useRealTimers();
  });

  async function openHistory(row = 0) {
    await wrapper!.findAll("[data-testid=stock-row]")[row]!.get("[data-testid=show-history]").trigger("click");
    await settle();
  }

  const cells = (testId: string) => wrapper!.findAll(`[data-testid=${testId}]`).map((cell) => cell.text());

  it.each([[["admin"]], [["marketing"]], [["support"]], [["warehouse"]], [[]]] as StaffMe["roles"][][])(
    "offers the history on every row to a staff member with the roles %j, without asking the API yet",
    async (roles) => {
      serve({}, [item("A"), item("B")]);
      wrapper = await mountView(roles);

      expect(wrapper.findAll("[data-testid=show-history]")).toHaveLength(2);
      expect(historyCalls()).toEqual([]);
    },
  );

  it("keeps the receipt and correction buttons for admin and warehouse only", async () => {
    serve({});
    wrapper = await mountView(["support"]);

    expect(wrapper.find("[data-testid=receive-stock]").exists()).toBe(false);
    expect(wrapper.find("[data-testid=correct-stock]").exists()).toBe(false);
    wrapper.unmount();

    wrapper = await mountView(["warehouse"]);
    expect(wrapper.find("[data-testid=receive-stock]").exists()).toBe(true);
    expect(wrapper.find("[data-testid=correct-stock]").exists()).toBe(true);
    expect(wrapper.find("[data-testid=show-history]").exists()).toBe(true);
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

    expect(historyCalls()).toEqual([{ path: { productId: "id-A" }, query: {} }]);
    const dialog = wrapper.get("[data-testid=history-dialog]");
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

  it("loads further entries with 'Pokaż więcej' using the cursor of the API, until the last page", async () => {
    serve({
      "": { items: [entry({ physicalDelta: 3 }), entry({ physicalDelta: 2 })], next: "c1" },
      c1: { items: [entry({ physicalDelta: 1 })], next: null },
    });
    wrapper = await mountView();
    await openHistory();

    expect(cells("history-physical-delta")).toEqual(["+3", "+2"]);
    expect(wrapper.get("[data-testid=history-more]").text()).toBe("Pokaż więcej");

    await wrapper.get("[data-testid=history-more]").trigger("click");
    await settle();

    expect(historyCalls().at(-1)).toEqual({ path: { productId: "id-A" }, query: { after: "c1" } });
    expect(cells("history-physical-delta")).toEqual(["+3", "+2", "+1"]);
    expect(wrapper.find("[data-testid=history-more]").exists()).toBe(false);
  });

  it("says so when the product has no recorded stock change", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    await openHistory();

    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(0);
    expect(wrapper.get("[data-testid=history-empty]").text()).toBe("Produkt nie ma jeszcze zmian stanu.");
  });

  it("shows the error of the API and loads again on retry", async () => {
    serve(500);
    wrapper = await mountView();

    await openHistory();

    expect(wrapper.get("[data-testid=history-error]").text()).toContain("Nie udało się pobrać historii stanów (HTTP 500).");
    expect(wrapper.find("[data-testid=history-empty]").exists()).toBe(false);

    serve({ "": { items: [entry()], next: null } });
    await wrapper.get("[data-testid=history-retry]").trigger("click");
    await settle();

    expect(wrapper.find("[data-testid=history-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(1);
  });

  it("closes the dialog and forgets the entries, and another product starts from its first page", async () => {
    serve({ "": { items: [entry()], next: null } }, [item("A"), item("B")]);
    wrapper = await mountView();
    await openHistory(0);

    await wrapper.get("[data-testid=close-history]").trigger("click");
    await settle();
    expect(wrapper.find("[data-testid=history-dialog]").exists()).toBe(false);

    await openHistory(1);
    expect(historyCalls().map((params) => params.path.productId)).toEqual(["id-A", "id-B"]);
    expect(wrapper.get("[data-testid=history-dialog]").text()).toContain("B");
    expect(wrapper.findAll("[data-testid=history-row]")).toHaveLength(1);
  });
});
