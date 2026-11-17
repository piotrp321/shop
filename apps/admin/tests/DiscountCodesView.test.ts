import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { Select } from "@/components/ui/select";
import { SEARCH_DEBOUNCE_MS } from "@/discount-codes/useDiscountCodeList";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import DiscountCodesView from "@/views/DiscountCodesView.vue";
import type { WithOptionalTotal } from "./listPage";

type CodeList = WithOptionalTotal<components["schemas"]["DiscountCodeListResponse"]>;
type Code = components["schemas"]["DiscountCodeResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function item(text: string, overrides: Partial<Code> = {}): Code {
  return {
    id: `id-${text}`,
    code: text,
    kind: "percent",
    value: 10,
    minimumOrderGross: null,
    startsAt: "2026-11-10T23:00:00+00:00",
    endsAt: "2026-11-20T23:00:00+00:00",
    usageLimit: 100,
    activeUses: 3,
    enabled: true,
    status: "planned",
    version: 1,
    ...overrides,
  };
}

function serve(pages: Record<string, CodeList>, status?: number) {
  mocks.get.mockImplementation(async (_path: string, options?: { params?: { query?: { after?: string } } }) =>
    status ? { data: undefined, response: { status } } : { data: pages[options?.params?.query?.after ?? ""], response: { status: 200 } },
  );
}

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function mountView(roles: StaffMe["roles"] = ["admin"], path = "/discount-codes") {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/discount-codes", name: "discount-codes", component: empty },
      { path: "/discount-codes/new", name: "discount-code-new", component: empty },
      { path: "/discount-codes/:id", name: "discount-code", component: empty },
    ],
  });
  await router.push(path);
  const wrapper = mount(DiscountCodesView, { attachTo: document.body, global: { plugins: [pinia, router] } });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

describe("DiscountCodesView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.useRealTimers();
  });

  const statusSelect = () => wrapper!.findAllComponents(Select)[0]!;
  const perPageSelect = () => wrapper!.findAllComponents(Select)[1]!;

  it("has the H1 „Kody rabatowe” and the crumbs „Pulpit › Marketing › Kody rabatowe”", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();

    expect(wrapper.get("h1").text()).toBe("Kody rabatowe");
    const crumbs = wrapper.get("nav[aria-label=Okruszki]");
    expect(crumbs.text()).toMatch(/^Pulpit.*Marketing.*Kody rabatowe$/);
    expect(crumbs.get("[aria-current=page]").text()).toBe("Kody rabatowe");
  });

  it("shows a row per code with the discount, the minimum, the Polish period, the uses and the status", async () => {
    serve({
      "": {
        items: [
          item("WITAJ10", { status: "active" }),
          item("MINUS50", { kind: "amount", value: 5000, minimumOrderGross: 30000, endsAt: null, usageLimit: null, activeUses: 7, status: "expired" }),
        ],
        next: null,
      },
    });
    wrapper = await mountView();

    const rows = wrapper.findAll("[data-testid=discount-code-row]");
    expect(rows).toHaveLength(2);
    const first = rows[0]!;
    expect(first.get("[data-testid=code-link]").text()).toBe("WITAJ10");
    expect(first.get("[data-testid=code-link]").attributes("href")).toBe("/discount-codes/id-WITAJ10");
    expect(first.get("[data-testid=code-discount]").text()).toBe("10%");
    expect(first.get("[data-testid=code-minimum]").text()).toBe("—");
    expect(first.get("[data-testid=code-period]").text()).toBe("11.11.2026, 00:00:00 – 21.11.2026, 00:00:00");
    expect(first.get("[data-testid=code-uses]").text()).toBe("3 / 100");
    expect(first.get("[data-testid=code-status]").text()).toBe("Aktywny");
    const second = rows[1]!;
    expect(second.get("[data-testid=code-discount]").text().replace(/\s/g, " ")).toBe("50,00 zł");
    expect(second.get("[data-testid=code-minimum]").text().replace(/\s/g, " ")).toBe("300,00 zł");
    expect(second.get("[data-testid=code-period]").text()).toBe("11.11.2026, 00:00:00 – bez końca");
    expect(second.get("[data-testid=code-uses]").text()).toBe("7 (bez limitu)");
    expect(second.get("[data-testid=code-status]").text()).toBe("Wygasły");
  });

  it("has the columns Kod, Rabat, Minimum, Okres, Użycia and Status, and the minimum and the period as lines of the cell Kod below 1024 px", async () => {
    serve({ "": { items: [item("MINUS50", { kind: "amount", value: 5000, minimumOrderGross: 30000, endsAt: null })], next: null } });
    wrapper = await mountView();

    const headers = wrapper.findAll("th[scope=col]").map((header) => header.text());
    expect(headers).toEqual(["Kod", "Rabat", "Minimum", "Okres", "Użycia", "Status", "Akcje"]);
    const row = wrapper.get("[data-testid=discount-code-row]");
    expect(row.get("[data-testid=line-minimum]").text().replace(/\s/g, " ")).toBe("Minimum: 300,00 zł");
    expect(row.get("[data-testid=line-period]").text()).toBe("Okres: 11.11.2026, 00:00:00 – bez końca");
  });

  it.each([
    ["planned", "Zaplanowany", "info"],
    ["active", "Aktywny", "success"],
    ["expired", "Wygasły", "neutral"],
    ["disabled", "Wyłączony", "warning"],
  ] as const)("shows the status %s as a badge %s in the colours of the status", async (status, label, colour) => {
    serve({ "": { items: [item("A", { status })], next: null } });
    wrapper = await mountView();

    const badge = wrapper.get("[data-testid=code-status]");
    expect(badge.text()).toBe(label);
    expect(badge.classes().join(" ")).toContain(`text-status-${colour}`);
  });

  it("filters by status with the Select „Status” and starts from the first page, the status in the address", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    statusSelect().vm.$emit("update:modelValue", "disabled");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ status: "disabled" });
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/discount-codes?status=disabled");

    statusSelect().vm.$emit("update:modelValue", "all");
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/discount-codes");
  });

  it("has a Select „Status” with a visible label and every status", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    const status = wrapper.get<HTMLButtonElement>("[data-testid=status-filter]");
    expect(status.element.labels?.[0]?.textContent?.trim()).toBe("Status");
    expect(status.text()).toBe("Wszystkie");
    status.element.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, ctrlKey: false }));
    await flushPromises();
    const options = [...document.body.querySelectorAll("[role=option]")].map((option) => option.textContent?.trim());
    expect(options).toEqual(["Wszystkie", "Zaplanowane", "Aktywne", "Wygasłe", "Wyłączone"]);
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
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/discount-codes?perPage=10");
  });

  describe("the state in the address", () => {
    it("shows the list filtered by the address, with the filters filled in", async () => {
      serve({ "": { items: [item("LATO", { status: "active" })], next: null } });
      wrapper = await mountView(["admin"], "/discount-codes?status=active&q=LATO&perPage=10");

      expect(queries()).toEqual([{ status: "active", q: "LATO", perPage: 10 }]);
      expect(wrapper.get("[data-testid=status-filter]").text()).toBe("Aktywne");
      expect((wrapper.get("[data-testid=search]").element as HTMLInputElement).value).toBe("LATO");
      expect(wrapper.get("[data-testid=per-page]").text()).toBe("10");
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(true);
    });

    it("shows all codes for an unknown status of the address", async () => {
      serve({ "": { items: [item("LATO")], next: null } });
      wrapper = await mountView(["admin"], "/discount-codes?status=nope");

      expect(queries()).toEqual([{}]);
      expect(wrapper.get("[data-testid=status-filter]").text()).toBe("Wszystkie");
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(false);
    });

    it("switches the open list when the address changes, with one request", async () => {
      serve({ "": { items: [item("LATO")], next: null } });
      wrapper = await mountView();
      mocks.get.mockClear();

      await wrapper.router.push({ name: "discount-codes", query: { status: "active" } });
      await flushPromises();

      expect(queries()).toEqual([{ status: "active" }]);
      expect(wrapper.get("[data-testid=status-filter]").text()).toBe("Aktywne");
    });

    it("clears the search, the filter and the page size with „Wyczyść filtry”, and puts the focus in the search", async () => {
      serve({ "": { items: [item("LATO")], next: null } });
      wrapper = await mountView(["admin"], "/discount-codes?status=active&q=LATO&perPage=10");
      mocks.get.mockClear();

      await wrapper.get("[data-testid=clear-filters]").trigger("click");
      await flushPromises();

      expect(queries()).toEqual([{}]);
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/discount-codes");
      expect((wrapper.get("[data-testid=search]").element as HTMLInputElement).value).toBe("");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=search]").element);
    });
  });

  it("searches by the beginning of the code, labelled for a screen reader, after a pause in typing, and writes it to the address", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    const search = wrapper.get("[data-testid=search]");
    expect(search.attributes("aria-label")).toBe("Szukaj po początku kodu");
    expect(search.attributes("maxlength")).toBe("32");

    await search.setValue("wit");
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();

    expect(queries().at(-1)).toEqual({ q: "wit" });
    expect(wrapper.router.currentRoute.value.fullPath).toBe("/discount-codes?q=wit");
  });

  it("#336: a search of only an invisible character does not ask for q", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });

    await wrapper.get("[data-testid=search]").setValue("​");
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();

    expect(queries()).toEqual([{}]);
  });

  it("says in a status region that a search longer than a code is not sent", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });

    // The input has a maximum length; a paste can still exceed it in the model.
    await wrapper.get("[data-testid=search]").setValue("A".repeat(33));
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();

    const hint = wrapper.get("[data-testid=search-hint]");
    expect(hint.text()).toBe("Kod ma najwyżej 32 znaki.");
    expect(hint.attributes("role")).toBe("status");
    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  describe("paging", () => {
    const THREE_PAGES = { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: "c2" }, c2: { items: [item("C")], next: null } };
    const pager = (testid: string) => wrapper!.get(`[data-testid=${testid}]`);

    it("says „z N” with the total of the first page on the next page too", async () => {
      serve({ "": { items: [item("A")], next: "c1", total: { value: 12, capped: false } }, c1: { items: [item("B")], next: null, total: null } });
      wrapper = await mountView();
      expect(pager("range").text()).toBe("Pokazano 1–1 z 12");

      await pager("next-page").trigger("click");
      await flushPromises();

      expect(pager("range").text()).toBe("Pokazano 26–26 z 12");
    });

    it("pages with the cursors of the API and says which codes are shown", async () => {
      serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      wrapper = await mountView();
      expect(pager("previous-page").attributes("disabled")).toBeDefined();
      expect(pager("range").text()).toBe("Pokazano 1–1");

      await pager("next-page").trigger("click");
      await flushPromises();
      expect(pager("code-link").text()).toBe("B");
      expect(pager("page-number").text()).toBe("Strona 2");
      expect(pager("next-page").attributes("disabled")).toBeDefined();

      await pager("previous-page").trigger("click");
      await flushPromises();
      expect(pager("code-link").text()).toBe("A");
      expect(queries()).toEqual([{}, { after: "c1" }, {}]);
    });

    it("leaves the focus on the pressed button while it stays available, and moves it to the other one at the end of the list", async () => {
      serve(THREE_PAGES);
      wrapper = await mountView();

      (pager("next-page").element as HTMLElement).focus();
      await pager("next-page").trigger("click");
      await flushPromises();
      expect(pager("page-number").text()).toBe("Strona 2");
      expect(document.activeElement).toBe(pager("next-page").element);

      await pager("next-page").trigger("click");
      await flushPromises();
      expect(pager("next-page").attributes("disabled")).toBeDefined();
      expect(document.activeElement).toBe(pager("previous-page").element);
    });

    it("keeps the page that is shown and the focus when the next page cannot be loaded", async () => {
      serve({ "": { items: [item("A")], next: "c1" } });
      wrapper = await mountView();
      (pager("next-page").element as HTMLElement).focus();
      mocks.get.mockResolvedValueOnce({ data: undefined, response: { status: 500 } });

      await pager("next-page").trigger("click");
      await flushPromises();

      expect(pager("page-number").text()).toBe("Strona 1");
      expect(pager("code-link").text()).toBe("A");
      expect(document.activeElement).toBe(pager("next-page").element);
    });
  });

  describe("empty states", () => {
    it("says that there are no codes yet, with „Dodaj kod” for a role that can add one", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView();

      expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze kodów rabatowych.");
      expect(wrapper.get("[data-testid=empty-add-discount-code]").attributes("href")).toBe("/discount-codes/new");
    });

    it("does not offer adding a code in the empty list of a role that cannot", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView(["support"]);

      expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze kodów rabatowych.");
      expect(wrapper.find("[data-testid=empty-add-discount-code]").exists()).toBe(false);
    });

    it("says that nothing matches the filters, with „Wyczyść filtry”", async () => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView(["admin"], "/discount-codes?status=expired");

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
      expect(wrapper.get("[data-testid=discount-codes-error]").text()).toContain("Nie udało się pobrać kodów rabatowych (HTTP 500).");
      expect(wrapper.find("[data-testid=discount-code-row]").exists()).toBe(false);

      serve({ "": { items: [item("A")], next: null } });
      await wrapper.get("[data-testid=retry]").trigger("click");
      await flushPromises();
      expect(wrapper.find("[data-testid=discount-codes-error]").exists()).toBe(false);
      expect(wrapper.findAll("[data-testid=discount-code-row]")).toHaveLength(1);

      wrapper.unmount();
      serve({}, 403);
      wrapper = await mountView();
      expect(wrapper.get("[data-testid=discount-codes-error]").text()).toContain("Nie masz dostępu do kodów rabatowych.");
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
      const alert = wrapper.get("[data-testid=discount-codes-error]");
      expect(alert.text()).toContain("Nie masz dostępu do kodów rabatowych.");
      expect(document.activeElement).not.toBe(document.body);
      expect(document.activeElement).toBe(alert.element);
    });

    it("leaves the focus on the retry button when the retry fails", async () => {
      serve({}, 500);
      wrapper = await mountView();
      const retry = wrapper.get<HTMLButtonElement>("[data-testid=retry]");
      retry.element.focus();

      await retry.trigger("click");
      await flushPromises();

      expect(document.activeElement).toBe(wrapper.get("[data-testid=retry]").element);
    });

    it("moves the focus to the first row's region after a successful retry, never to the body", async () => {
      serve({}, 500);
      wrapper = await mountView();
      const retry = wrapper.get<HTMLButtonElement>("[data-testid=retry]");
      retry.element.focus();

      serve({ "": { items: [item("A")], next: null } });
      await retry.trigger("click");
      await flushPromises();

      expect(wrapper.find("[data-testid=discount-codes-error]").exists()).toBe(false);
      expect(document.activeElement).not.toBe(document.body);
    });
  });

  describe("roles", () => {
    it.each([[["admin"]], [["marketing"]], [["support", "marketing"]]] as StaffMe["roles"][][])(
      "offers adding a discount code and editing a row to the roles %j",
      async (roles) => {
        serve({ "": { items: [item("A")], next: null } });
        wrapper = await mountView(roles);

        expect(wrapper.get("[data-testid=add-discount-code]").attributes("href")).toBe("/discount-codes/new");
        expect(wrapper.get("[data-testid=add-discount-code]").text()).toBe("Dodaj kod");
        const edit = wrapper.get("[data-testid=action-edit]");
        expect(edit.attributes("aria-label")).toBe("Edytuj: A");
        expect(edit.attributes("href")).toBe("/discount-codes/id-A");
      },
    );

    it.each([[["support"]], [["warehouse"]], [["support", "warehouse"]], [[]]] as StaffMe["roles"][][])(
      "does not offer adding or editing to the roles %j, who still see the list and open a code by its link",
      async (roles) => {
        serve({ "": { items: [item("A")], next: null } });
        wrapper = await mountView(roles);

        expect(wrapper.find("[data-testid=add-discount-code]").exists()).toBe(false);
        expect(wrapper.find("[data-testid=action-edit]").exists()).toBe(false);
        expect(wrapper.findAll("[data-testid=discount-code-row]")).toHaveLength(1);
        expect(wrapper.get("[data-testid=code-link]").attributes("href")).toBe("/discount-codes/id-A");
      },
    );
  });
});
