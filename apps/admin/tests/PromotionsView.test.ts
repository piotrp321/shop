import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { Select } from "@/components/ui/select";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import PromotionsView from "@/views/PromotionsView.vue";
import type { WithOptionalTotal } from "./listPage";

type PromotionList = WithOptionalTotal<components["schemas"]["PromotionListResponse"]>;
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

async function mountView(roles: StaffMe["roles"] = ["admin"], path = "/promotions") {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/promotions", name: "promotions", component: empty },
      { path: "/promotions/new", name: "promotion-new", component: empty },
      { path: "/promotions/:id", name: "promotion", component: empty },
    ],
  });
  await router.push(path);
  const wrapper = mount(PromotionsView, { attachTo: document.body, global: { plugins: [pinia, router] } });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

describe("PromotionsView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
  });

  const statusSelect = () => wrapper!.findAllComponents(Select)[0]!;
  const perPageSelect = () => wrapper!.findAllComponents(Select)[1]!;

  it("has the H1 „Promocje” and the crumbs „Pulpit › Marketing › Promocje”", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Promocje");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Marketing.*Promocje$/);
    expect(crumbs.get("[aria-current=page]").text()).toBe("Promocje");
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

  it("has the columns Nazwa, Obniżka, Początek, Koniec, Status, Warianty and Akcje, and the start and the end as lines of the cell Nazwa below 1024 px", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();

    const headers = wrapper.findAll("th[scope=col]").map((header) => header.text());
    expect(headers).toEqual(["Nazwa", "Obniżka", "Początek", "Koniec", "Status", "Warianty", "Akcje"]);
    const row = wrapper.get("[data-testid=promotion-row]");
    expect(row.get("[data-testid=line-starts]").text()).toBe("Początek: 11.11.2026, 00:00:00");
    expect(row.get("[data-testid=line-ends]").text()).toBe("Koniec: 21.11.2026, 00:00:00");
  });

  it.each([
    ["planned", "Zaplanowana", "info"],
    ["active", "Aktywna", "success"],
    ["ended", "Zakończona", "neutral"],
  ] as const)("shows the status %s as a badge %s in the colours of the status", async (status, label, colour) => {
    serve({ "": { items: [item("A", { status })], next: null } });
    wrapper = await mountView();

    const badge = wrapper.get("[data-testid=promotion-status]");
    expect(badge.text()).toBe(label);
    expect(badge.classes().join(" ")).toContain(`text-status-${colour}`);
  });

  it("filters by status with the Select „Status” and starts from the first page, the status in the address", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    statusSelect().vm.$emit("update:modelValue", "active");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ status: "active" });
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/promotions?status=active");

    statusSelect().vm.$emit("update:modelValue", "all");
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/promotions");
  });

  it("has a Select „Status” with a visible label and every status of a promotion", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    const status = wrapper.get<HTMLButtonElement>("[data-testid=status-filter]");
    expect(status.element.labels?.[0]?.textContent?.trim()).toBe("Status");
    expect(status.text()).toBe("Wszystkie");
    status.element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
    await flushPromises();
    const options = [...document.body.querySelectorAll("[role=option]")].map((option) => option.textContent?.trim());
    expect(options).toEqual(["Wszystkie", "Zaplanowane", "Aktywne", "Zakończone"]);
  });

  it("changes the page size with the Select „Wierszy na stronie” and starts from the first page", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    perPageSelect().vm.$emit("update:modelValue", "10");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ perPage: 10 });
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/promotions?perPage=10");
  });

  it("has no search: the API cannot search promotions", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();

    expect(wrapper.find("[data-testid=search]").exists()).toBe(false);
  });

  describe("the state in the address", () => {
    it("shows the list filtered by the status of the address, with that status selected", async () => {
      serve({ "": { items: [item("A", { status: "active" })], next: null } });
      wrapper = await mountView(["admin"], "/promotions?status=active&perPage=10");

      expect(queries()).toEqual([{ status: "active", perPage: 10 }]);
      expect(wrapper.get("[data-testid=status-filter]").text()).toBe("Aktywne");
      expect(wrapper.get("[data-testid=per-page]").text()).toBe("10");
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(true);
    });

    it("shows all promotions for an unknown status of the address", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView(["admin"], "/promotions?status=nope");

      expect(queries()).toEqual([{}]);
      expect(wrapper.get("[data-testid=status-filter]").text()).toBe("Wszystkie");
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(false);
    });

    it("switches the open list when the address changes, with one request", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView();
      mocks.get.mockClear();

      await wrapper.router.push({ name: "promotions", query: { status: "active" } });
      await flushPromises();

      expect(queries()).toEqual([{ status: "active" }]);
      expect(wrapper.get("[data-testid=status-filter]").text()).toBe("Aktywne");
    });

    it("clears the filter and the page size with „Wyczyść filtry”, and puts the focus in the filter", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView(["admin"], "/promotions?status=active&perPage=10");
      mocks.get.mockClear();

      await wrapper.get("[data-testid=clear-filters]").trigger("click");
      await flushPromises();

      expect(queries()).toEqual([{}]);
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/promotions");
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(false);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=status-filter]").element);
    });
  });

  describe("paging", () => {
    const pager = (testid: string) => wrapper!.get(`[data-testid=${testid}]`);

    it("says „z N” with the total of the first page on the next page too", async () => {
      serve({ "": { items: [item("A")], next: "c1", total: { value: 12, capped: false } }, c1: { items: [item("B")], next: null, total: null } });
      wrapper = await mountView();
      expect(pager("range").text()).toBe("Pokazano 1–1 z 12");

      await pager("next-page").trigger("click");
      await flushPromises();

      expect(pager("range").text()).toBe("Pokazano 26–26 z 12");
    });

    it("pages with the cursors of the API and says which promotions are shown", async () => {
      serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      wrapper = await mountView();
      expect(pager("previous-page").attributes("disabled")).toBeDefined();
      expect(pager("range").text()).toBe("Pokazano 1–1");

      await pager("next-page").trigger("click");
      await flushPromises();
      expect(pager("promotion-link").text()).toBe("B");
      expect(pager("page-number").text()).toBe("Strona 2");
      expect(pager("next-page").attributes("disabled")).toBeDefined();

      await pager("previous-page").trigger("click");
      await flushPromises();
      expect(pager("promotion-link").text()).toBe("A");
      expect(queries()).toEqual([{}, { after: "c1" }, {}]);
    });

    it("keeps the page that is shown and the focus when the next page cannot be loaded", async () => {
      serve({ "": { items: [item("A")], next: "c1" } });
      wrapper = await mountView();
      (pager("next-page").element as HTMLElement).focus();
      mocks.get.mockResolvedValueOnce({ data: undefined, response: { status: 500 } });

      await pager("next-page").trigger("click");
      await flushPromises();

      expect(pager("page-number").text()).toBe("Strona 1");
      expect(pager("promotion-link").text()).toBe("A");
      expect(document.activeElement).toBe(pager("next-page").element);
    });
  });

  describe("empty states", () => {
    it("says that there are no promotions yet, with „Dodaj promocję” for a role that can add one", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView();

      expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze promocji.");
      expect(wrapper.get("[data-testid=empty-add-promotion]").attributes("href")).toBe("/promotions/new");
    });

    it("does not offer adding a promotion in the empty list of a role that cannot", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView(["support"]);

      expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze promocji.");
      expect(wrapper.find("[data-testid=empty-add-promotion]").exists()).toBe(false);
    });

    it("says that nothing matches the filter, with „Wyczyść filtry”", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView(["admin"], "/promotions?status=ended");

      expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak wyników dla wybranych filtrów.");
      mocks.get.mockClear();
      await wrapper.get("[data-testid=empty-clear-filters]").trigger("click");
      await flushPromises();
      expect(queries()).toEqual([{}]);
    });
  });

  describe("errors", () => {
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

    it("#337: a retry that ends in a 403 leaves the focus on the message, not on the body", async () => {
      serve({}, 500);
      wrapper = await mountView();
      const retry = wrapper.get<HTMLButtonElement>("[data-testid=retry]");
      retry.element.focus();

      serve({}, 403);
      await retry.trigger("click");
      await flushPromises();

      expect(wrapper.find("[data-testid=retry]").exists()).toBe(false);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=promotions-error]").element);
    });
  });

  describe("roles", () => {
    it.each([[["admin"]], [["marketing"]], [["support", "marketing"]]] as StaffMe["roles"][][])(
      "offers adding a promotion and editing a row to the roles %j",
      async (roles) => {
        serve({ "": { items: [item("A")], next: null } });
        wrapper = await mountView(roles);

        expect(wrapper.get("[data-testid=add-promotion]").attributes("href")).toBe("/promotions/new");
        expect(wrapper.get("[data-testid=add-promotion]").text()).toBe("Dodaj promocję");
        const edit = wrapper.get("[data-testid=action-edit]");
        expect(edit.attributes("aria-label")).toBe("Edytuj: A");
        expect(edit.attributes("href")).toBe("/promotions/id-A");
      },
    );

    it.each([[["support"]], [["warehouse"]], [["support", "warehouse"]], [[]]] as StaffMe["roles"][][])(
      "does not offer adding or editing to the roles %j, who still see the list and open a promotion by its link",
      async (roles) => {
        serve({ "": { items: [item("A")], next: null } });
        wrapper = await mountView(roles);

        expect(wrapper.find("[data-testid=add-promotion]").exists()).toBe(false);
        expect(wrapper.find("[data-testid=action-edit]").exists()).toBe(false);
        expect(wrapper.findAll("[data-testid=promotion-row]")).toHaveLength(1);
        expect(wrapper.get("[data-testid=promotion-link]").attributes("href")).toBe("/promotions/id-A");
      },
    );
  });
});
