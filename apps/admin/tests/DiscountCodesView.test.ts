import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import SelectButton from "primevue/selectbutton";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { SEARCH_DEBOUNCE_MS } from "@/discount-codes/useDiscountCodeList";
import { primeVueOptions } from "@/primevue";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import DiscountCodesView from "@/views/DiscountCodesView.vue";

type CodeList = components["schemas"]["DiscountCodeListResponse"];
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

async function mountView(roles: StaffMe["roles"] = ["admin"], attach = false) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/discount-codes", name: "discount-codes", component: empty },
      { path: "/discount-codes/new", name: "discount-code-new", component: empty },
      { path: "/discount-codes/:id", name: "discount-code", component: empty },
    ],
  });
  await router.push("/discount-codes");
  const wrapper = mount(DiscountCodesView, {
    attachTo: attach ? document.body : undefined,
    global: { plugins: [pinia, router, [PrimeVue, primeVueOptions]] as never[] },
  });
  await flushPromises();
  return wrapper;
}

describe("DiscountCodesView", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    vi.useRealTimers();
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

  it.each([
    ["planned", "Zaplanowany"],
    ["active", "Aktywny"],
    ["expired", "Wygasły"],
    ["disabled", "Wyłączony"],
  ] as const)("labels the status %s as %s", async (status, label) => {
    serve({ "": { items: [item("A", { status })], next: null } });
    wrapper = await mountView();

    expect(wrapper.get("[data-testid=code-status]").text()).toBe(label);
  });

  it("filters by status and starts from the first page", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "disabled");
    await flushPromises();

    expect(queries().at(-1)).toEqual({ status: "disabled" });
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 1");

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "all");
    await flushPromises();
    expect(queries().at(-1)).toEqual({});
  });

  it("offers every status in the filter", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();

    const labels = wrapper.get("[data-testid=status-filter]").text();
    for (const label of ["Wszystkie", "Zaplanowane", "Aktywne", "Wygasłe", "Wyłączone"]) {
      expect(labels).toContain(label);
    }
  });

  it("searches by the beginning of the code, labelled for a screen reader, after a pause in typing", async () => {
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
  });

  it("says that a search longer than a code is not sent", async () => {
    serve({ "": { items: [item("A")], next: null } });
    wrapper = await mountView();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });

    // The input has a maximum length; a paste can still exceed it in the model.
    await wrapper.get("[data-testid=search]").setValue("A".repeat(33));
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS);
    await flushPromises();

    expect(wrapper.get("[data-testid=search-hint]").text()).toBe("Kod ma najwyżej 32 znaki.");
    expect(mocks.get).toHaveBeenCalledTimes(1);
  });

  it("pages with the cursors of the API", async () => {
    serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=previous-page]").attributes("disabled")).toBeDefined();

    await wrapper.get("[data-testid=next-page]").trigger("click");
    await flushPromises();
    expect(wrapper.get("[data-testid=code-link]").text()).toBe("B");
    expect(wrapper.get("[data-testid=page-number]").text()).toBe("Strona 2");
    expect(wrapper.get("[data-testid=next-page]").attributes("disabled")).toBeDefined();

    await wrapper.get("[data-testid=previous-page]").trigger("click");
    await flushPromises();
    expect(wrapper.get("[data-testid=code-link]").text()).toBe("A");
    expect(queries()).toEqual([{}, { after: "c1" }, {}]);
  });

  describe("paging with the keyboard", () => {
    const THREE_PAGES = { "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: "c2" }, c2: { items: [item("C")], next: null } };
    const pager = (testid: string) => wrapper!.get(`[data-testid=${testid}]`);
    const press = async (testid: string) => {
      (pager(testid).element as HTMLElement).focus();
      await pager(testid).trigger("click");
    };
    /** The next request stays open until the returned function answers it. */
    const holdNextRequest = (page: CodeList) => {
      let answer: () => void = () => undefined;
      mocks.get.mockImplementationOnce(() => new Promise((resolve) => (answer = () => resolve({ data: page, response: { status: 200 } }))));
      return () => answer();
    };

    it("keeps the pager buttons on while a page loads, so that the focused one keeps the focus", async () => {
      serve(THREE_PAGES);
      wrapper = await mountView(["admin"], true);
      const answer = holdNextRequest(THREE_PAGES.c1);

      await press("next-page");

      expect(pager("next-page").attributes("disabled")).toBeUndefined();
      answer();
      await flushPromises();
      expect(pager("page-number").text()).toBe("Strona 2");
    });

    it("leaves the focus on the button that was pressed while it stays available", async () => {
      serve(THREE_PAGES);
      wrapper = await mountView(["admin"], true);

      await press("next-page");
      await flushPromises();

      expect(pager("page-number").text()).toBe("Strona 2");
      expect(document.activeElement).toBe(pager("next-page").element);
    });

    it("moves the focus to the other button when the pressed one goes off at the end of the list", async () => {
      serve({ "": { items: [item("A")], next: "c1" }, c1: { items: [item("B")], next: null } });
      wrapper = await mountView(["admin"], true);

      await press("next-page");
      await flushPromises();

      expect(pager("next-page").attributes("disabled")).toBeDefined();
      expect(document.activeElement).toBe(pager("previous-page").element);

      await press("previous-page");
      await flushPromises();

      expect(pager("page-number").text()).toBe("Strona 1");
      expect(pager("previous-page").attributes("disabled")).toBeDefined();
      expect(document.activeElement).toBe(pager("next-page").element);
    });

    it("does not move the focus when the page cannot be loaded", async () => {
      serve({ "": { items: [item("A")], next: "c1" } });
      wrapper = await mountView(["admin"], true);
      mocks.get.mockResolvedValueOnce({ data: undefined, response: { status: 500 } });

      await press("next-page");
      await flushPromises();

      expect(pager("page-number").text()).toBe("Strona 1");
      expect(document.activeElement).toBe(pager("next-page").element);
    });

    it("announces the page number to a screen reader", async () => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView();

      expect(pager("page-number").attributes("aria-live")).toBe("polite");
    });

    it("does not skip a page when the button is pressed twice before the page arrives", async () => {
      serve(THREE_PAGES);
      wrapper = await mountView(["admin"], true);
      const answer = holdNextRequest(THREE_PAGES.c1);

      await press("next-page");
      await press("next-page");
      answer();
      await flushPromises();

      expect(pager("page-number").text()).toBe("Strona 2");
      expect(wrapper.get("[data-testid=code-link]").text()).toBe("B");
      expect(queries().filter((query) => query.after === "c2")).toEqual([]);
    });
  });

  it("says the list is empty, and that nothing matches a filter", async () => {
    serve({ "": { items: [], next: null } });
    wrapper = await mountView();
    expect(wrapper.get("[data-testid=empty]").text()).toBe("Brak kodów rabatowych.");

    wrapper.getComponent(SelectButton).vm.$emit("update:modelValue", "expired");
    await flushPromises();
    expect(wrapper.get("[data-testid=empty]").text()).toBe("Nie znaleziono kodów rabatowych.");
  });

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

  describe("retry with the keyboard", () => {
    const retryButton = () => wrapper!.get("[data-testid=retry]");
    const press = async () => {
      (retryButton().element as HTMLElement).focus();
      await retryButton().trigger("click");
    };

    it("keeps the retry button on while the list loads, so that it keeps the focus", async () => {
      serve({}, 500);
      wrapper = await mountView(["admin"], true);
      let answer: () => void = () => undefined;
      mocks.get.mockImplementationOnce(
        () => new Promise((resolve) => (answer = () => resolve({ data: { items: [item("A")], next: null }, response: { status: 200 } }))),
      );

      await press();

      expect(retryButton().attributes("disabled")).toBeUndefined();
      expect(document.activeElement).toBe(retryButton().element);
      answer();
      await flushPromises();
    });

    it("moves the focus to the first code after a successful retry", async () => {
      serve({}, 500);
      wrapper = await mountView(["admin"], true);
      serve({ "": { items: [item("A"), item("B")], next: null } });

      await press();
      await flushPromises();

      expect(wrapper.find("[data-testid=discount-codes-error]").exists()).toBe(false);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=code-link]").element);
    });

    it("moves the focus to the pager after a successful retry that finds no codes", async () => {
      serve({}, 500);
      wrapper = await mountView(["admin"], true);
      serve({ "": { items: [], next: "c1" } });

      await press();
      await flushPromises();

      expect(wrapper.find("[data-testid=code-link]").exists()).toBe(false);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=next-page]").element);
    });

    it("moves the focus to the search field after a successful retry that finds no codes on the only page", async () => {
      serve({}, 500);
      wrapper = await mountView(["admin"], true);
      serve({ "": { items: [], next: null } });

      await press();
      await flushPromises();

      expect(wrapper.find("[data-testid=code-link]").exists()).toBe(false);
      expect(document.activeElement).toBe(wrapper.get("[data-testid=search]").element);
    });

    it("leaves the focus on the retry button when the retry fails", async () => {
      serve({}, 500);
      wrapper = await mountView(["admin"], true);

      await press();
      await flushPromises();

      expect(wrapper.find("[data-testid=discount-codes-error]").exists()).toBe(true);
      expect(document.activeElement).toBe(retryButton().element);
    });
  });

  it.each([[["admin"]], [["marketing"]], [["support", "marketing"]]] as StaffMe["roles"][][])(
    "offers adding a discount code to the roles %j",
    async (roles) => {
      serve({ "": { items: [], next: null } });
      wrapper = await mountView(roles);

      expect(wrapper.get("[data-testid=add-discount-code]").attributes("href")).toBe("/discount-codes/new");
    },
  );

  it.each([[["support"]], [["warehouse"]], [["support", "warehouse"]], [[]]] as StaffMe["roles"][][])(
    "does not offer adding a discount code to the roles %j, who still see the list",
    async (roles) => {
      serve({ "": { items: [item("A")], next: null } });
      wrapper = await mountView(roles);

      expect(wrapper.find("[data-testid=add-discount-code]").exists()).toBe(false);
      expect(wrapper.findAll("[data-testid=discount-code-row]")).toHaveLength(1);
    },
  );
});
