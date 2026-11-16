import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryHistory, createRouter } from "vue-router";
import OrdersView from "@/views/OrdersView.vue";

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get }) }));

function queries(): Record<string, unknown>[] {
  return mocks.get.mock.calls.map(([, options]) => (options as { params: { query: Record<string, unknown> } }).params.query);
}

async function mountView(path = "/orders") {
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/", name: "dashboard", component: empty },
      { path: "/orders", name: "orders", component: empty },
      { path: "/orders/:orderId", name: "order-detail", component: empty },
    ],
  });
  await router.push(path);
  const wrapper = mount(OrdersView, { attachTo: document.body, global: { plugins: [router] } });
  await flushPromises();
  return Object.assign(wrapper, { router });
}

describe("OrdersView: the search by fragment and the range of days", () => {
  let wrapper: Awaited<ReturnType<typeof mountView>> | undefined;

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.get.mockResolvedValue({ data: { items: [], nextCursor: null, total: { value: 0, capped: false } }, response: { status: 200 } });
    vi.stubGlobal("matchMedia", (query: string) => ({
      matches: query === "(min-width: 640px)",
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
      addListener: () => undefined,
      removeListener: () => undefined,
    }));
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = undefined;
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
  });

  const from = () => wrapper!.get("[data-testid=placed-from]");
  const to = () => wrapper!.get("[data-testid=placed-to]");
  const value = (input: ReturnType<typeof from>) => (input.element as HTMLInputElement).value;

  describe("the search field", () => {
    it("keeps the label and says what it takes: a number, an email or a fragment from 3 characters", async () => {
      wrapper = await mountView();

      const search = wrapper.get("[data-testid=search]");
      expect(search.attributes("aria-label")).toBe("Szukaj po numerze zamówienia lub e-mailu");
      const hint = wrapper.get("[data-testid=search-hint]");
      expect(hint.text()).toBe("Numer, e-mail albo fragment (od 3 znaków) numeru, e-maila lub nazwiska. Enter wyszukuje.");
      expect(hint.attributes("role")).toBe("status");
      expect(search.attributes("aria-describedby")).toBe(hint.attributes("id"));
    });

    it("says a text of 1 or 2 characters is too short, asks nothing on Enter, and goes back to the hint with a longer one", async () => {
      wrapper = await mountView();
      const search = wrapper.get("[data-testid=search]");

      await search.setValue("ab");
      expect(wrapper.get("[data-testid=search-hint]").text()).toBe("Wpisz co najmniej 3 znaki, aby wyszukać.");
      await search.trigger("keydown.enter");
      await flushPromises();
      expect(queries()).toEqual([{}]);
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders");

      await search.setValue("abc");
      expect(wrapper.get("[data-testid=search-hint]").text()).toContain("Enter wyszukuje.");
      await search.trigger("keydown.enter");
      await flushPromises();
      expect(queries()).toEqual([{}, { q: "abc" }]);
    });
  });

  describe("the days „Od” and „Do”", () => {
    it("has two date fields with their labels, in a group with a name, reachable by the keyboard", async () => {
      wrapper = await mountView();

      const group = wrapper.get("fieldset");
      expect(group.get("legend").text()).toBe("Data zamówienia");
      for (const [input, label] of [
        [from(), "Od"],
        [to(), "Do"],
      ] as const) {
        expect(input.attributes("type")).toBe("date");
        expect(group.get(`label[for="${input.attributes("id")}"]`).text()).toBe(label);
        expect(input.attributes("disabled")).toBeUndefined();
        expect(input.attributes("tabindex")).toBeUndefined();
      }
    });

    it("puts the days of the address into the fields and asks with them", async () => {
      wrapper = await mountView("/orders?placedFrom=2026-11-01&placedTo=2026-11-30");

      expect(value(from())).toBe("2026-11-01");
      expect(value(to())).toBe("2026-11-30");
      expect(queries()).toEqual([{ placedFrom: "2026-11-01", placedTo: "2026-11-30" }]);
      expect(wrapper.find("[data-testid=clear-filters]").exists()).toBe(true);
    });

    it("writes a chosen day to the address and asks from the first page, both ways", async () => {
      wrapper = await mountView();

      await from().setValue("2026-11-05");
      await flushPromises();
      await to().setValue("2026-11-20");
      await flushPromises();

      expect(queries()).toEqual([{}, { placedFrom: "2026-11-05" }, { placedFrom: "2026-11-05", placedTo: "2026-11-20" }]);
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?placedFrom=2026-11-05&placedTo=2026-11-20");

      await wrapper.router.push("/orders?placedTo=2026-12-24");
      await flushPromises();
      expect(value(from())).toBe("");
      expect(value(to())).toBe("2026-12-24");
      expect(queries().at(-1)).toEqual({ placedTo: "2026-12-24" });
    });

    it("clears a day with the field, and „Wyczyść filtry” clears both and puts the focus into the search", async () => {
      wrapper = await mountView("/orders?placedFrom=2026-11-01&placedTo=2026-11-30");

      await from().setValue("");
      await flushPromises();
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders?placedTo=2026-11-30");

      await wrapper.get("[data-testid=clear-filters]").trigger("click");
      await flushPromises();
      expect(value(from())).toBe("");
      expect(value(to())).toBe("");
      expect(wrapper.router.currentRoute.value.fullPath).toBe("/orders");
      expect(document.activeElement).toBe(wrapper.get("[data-testid=search]").element);
    });

    it("limits one field by the other, and tells an end before the start instead of asking", async () => {
      wrapper = await mountView("/orders?placedFrom=2026-11-10");
      expect(to().attributes("min")).toBe("2026-11-10");

      await to().setValue("2026-11-01");
      await flushPromises();

      expect(queries()).toEqual([{ placedFrom: "2026-11-10" }]);
      const error = wrapper.get("[data-testid=range-error]");
      expect(error.text()).toBe("Data „Do” nie może być wcześniejsza niż data „Od”.");
      expect(error.attributes("role")).toBe("alert");
      expect(to().attributes("aria-invalid")).toBe("true");
      expect(to().attributes("aria-describedby")).toContain(error.attributes("id"));

      await to().setValue("2026-11-12");
      await flushPromises();
      expect(wrapper.find("[data-testid=range-error]").exists()).toBe(false);
      expect(to().attributes("aria-invalid")).toBeUndefined();
      expect(queries().at(-1)).toEqual({ placedFrom: "2026-11-10", placedTo: "2026-11-12" });
      expect(from().attributes("max")).toBe("2026-11-12");
    });

    it("tells an end before the applied start also while a year is being typed in the start, and bounds the fields to the years the API takes", async () => {
      wrapper = await mountView("/orders?placedFrom=2026-11-01");
      expect(from().attributes("min")).toBe("2000-01-01");
      expect(to().attributes("max")).toBe("2999-12-31");

      await from().setValue("0202-11-05");
      await to().setValue("2026-10-15");
      await flushPromises();

      expect(queries()).toEqual([{ placedFrom: "2026-11-01" }]);
      expect(wrapper.get("[data-testid=range-error]").text()).toBe("Data „Do” nie może być wcześniejsza niż data „Od”.");
      expect(to().attributes("aria-invalid")).toBe("true");
      expect(to().attributes("min")).toBe("2026-11-01");
    });

    it("disables the fields for the refunds to do and says why, and enables them again", async () => {
      wrapper = await mountView("/orders?refund=pending&placedFrom=2026-11-01");

      expect(queries()).toEqual([{ refund: "pending" }]);
      for (const input of [from(), to()]) {
        expect(input.attributes("disabled")).toBeDefined();
      }
      const reason = wrapper.get("[data-testid=range-hint]");
      expect(reason.text()).toBe("Zwroty do wykonania nie łączą się z zakresem dat.");
      expect(reason.attributes("role")).toBe("status");
      expect(from().attributes("aria-describedby")).toContain(reason.attributes("id"));

      await wrapper.get("[data-testid=refund-pending]").trigger("click");
      await flushPromises();
      expect(from().attributes("disabled")).toBeUndefined();
      expect(wrapper.find("[data-testid=range-hint]").text()).toBe("");
      expect(queries().at(-1)).toEqual({ placedFrom: "2026-11-01" });
    });

    it("says why the amount cannot be sorted while a range is applied", async () => {
      wrapper = await mountView("/orders?placedTo=2026-11-30");

      expect(wrapper.get("[data-testid=sort-hint]").text()).toBe("Kwoty nie da się sortować przy zakresie dat.");
    });
  });
});
