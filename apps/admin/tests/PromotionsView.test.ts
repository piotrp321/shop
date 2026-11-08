import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import SelectButton from "primevue/selectbutton";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import PromotionsView from "@/views/PromotionsView.vue";

type PromotionList = components["schemas"]["PromotionListResponse"];
type PromotionListItem = components["schemas"]["PromotionListItemResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function item(name: string, overrides: Partial<PromotionListItem> = {}): PromotionListItem {
  return {
    id: `id-${name}`,
    name,
    percentOff: 20,
    startsAt: "2026-11-10T23:00:00+00:00",
    endsAt: "2026-11-20T23:00:00+00:00",
    status: "planned",
    variantCount: 3,
    version: 1,
    ...overrides,
  };
}

function serve(pages: Record<string, PromotionList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function mountView(roles: StaffMe["roles"] = ["admin"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/promotions", name: "promotions", component: empty },
      { path: "/promotions/new", name: "promotion-new", component: empty },
      { path: "/promotions/:id", name: "promotion", component: empty },
    ],
  });
  await router.push("/promotions");
  const wrapper = mount(PromotionsView, { global: { plugins: [pinia, router, [PrimeVue, primeVueOptions]] as never[] } });
  await flushPromises();
  return wrapper;
}

describe("PromotionsView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
  });

  it("shows a row per promotion with the percent, the Polish times, the status and the variant count", async () => {
    serve({
      "": {
        items: [item("Jesień w górach", { status: "active", variantCount: 12 }), item("Wiosna", { status: "ended", percentOff: 5 })],
        next: null,
      },
    });
    wrapper = await mountView();

    const rows = wrapper.findAll("[data-testid=promotion-row]");
    expect(rows).toHaveLength(2);
    const first = rows[0]!;
    expect(first.get("[data-testid=promotion-link]").text()).toBe("Jesień w górach");
    expect(first.get("[data-testid=promotion-link]").attributes("href")).toBe(`/promotions/${encodeURIComponent("id-Jesień w górach")}`);
    expect(first.get("[data-testid=promotion-percent]").text()).toBe("20%");
    expect(first.get("[data-testid=promotion-starts]").text()).toBe("11.11.2026, 00:00:00");
    expect(first.get("[data-testid=promotion-ends]").text()).toBe("21.11.2026, 00:00:00");
    expect(first.get("[data-testid=promotion-status]").text()).toBe("Aktywna");
    expect(first.get("[data-testid=variant-count]").text()).toBe("12");
    expect(rows[1]!.get("[data-testid=promotion-status]").text()).toBe("Zakończona");
    expect(rows[1]!.get("[data-testid=promotion-percent]").text()).toBe("5%");
  });

  it.each([
    ["planned", "Zaplanowana"],
    ["active", "Aktywna"],
    ["ended", "Zakończona"],
  ] as const)("labels the status %s as %s", async (status, label) => {
    serve({ "": { items: [item("A", { status })], next: null } });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=promotion-status]").text()).toBe(label);
  });

  it("filters by status and starts from the first page", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "active");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ status: "active" });
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "all");
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
  });

  it("offers the statuses of a promotion in the filter", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    const labels = wrapper.get("[data-testid=status-filter]").text();
    for (const label of ["Wszystkie", "Zaplanowane", "Aktywne", "Zakończone"]) {
      expect(labels).toContain(label);
    }
  });

  it("pages with the cursors of the API", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();

    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();
    expect(wrapper.get("[data-testid=promotion-link]").text()).toBe("B");
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 2");
    expect(wrapper.get("[data-testid=next-page]").attributes("disabled")).toBeDefined();

    await wrapper.get("[data-testid=previous-page]").trigger("click");
    await flushPromises();
    expect(wrapper.get("[data-testid=promotion-link]").text()).toBe("A");
    expect(queries()).toEqual([{}, { after: "c1" }, {}]);
  });

  it("says the list is empty, and that nothing matches a filter", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=empty]").text()).toBe("Brak promocji.");

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "ended");
    await flushPromises();
    expect(wrapper.get("[data-testid=empty]").text()).toBe("Nie znaleziono promocji.");
  });

  it("shows an error with a retry, and no retry for a 403", async () => {
    serve({}, 500);
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=promotions-error]").text()).toContain("Nie udało się pobrać promocji (HTTP 500).");
    expect(wrapper.find("[data-testid=promotion-row]").exists()).toBe(false);

    serve({ "": { items: [item("A")], next: null } });
    await wrapper.get("[data-testid=retry]").trigger("click");
    await flushPromises();
    expect(wrapper.find("[data-testid=promotions-error]").exists()).toBe(false);
    expect(wrapper.findAll("[data-testid=promotion-row]")).toHaveLength(1);

    wrapper.unmount();
    serve({}, 403);
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=promotions-error]").text()).toContain("Nie masz dostępu do promocji.");
    expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
  });

  it.each([[["admin"]], [["marketing"]], [["support", "marketing"]]] as StaffMe["roles"][][])(
    "offers adding a promotion to the roles %j",
    async (roles) => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView(roles);

      const add = wrapper.get("[data-testid=add-promotion]");
      expect(add.attributes("href")).toBe("/promotions/new");
    },
  );

  it.each([[["support"]], [["warehouse"]], [["support", "warehouse"]], [[]]] as StaffMe["roles"][][])(
    "does not offer adding a promotion to the roles %j, who still see the list",
    async (roles) => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView(roles);

      expect(wrapper.find("[data-testid=add-promotion]").exists()).toBe(false);
      expect(wrapper.findAll("[data-testid=promotion-row]")).toHaveLength(1);
    },
  );
});
