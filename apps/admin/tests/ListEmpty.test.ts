import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { h } from "vue";
import ListEmpty from "@/components/list/ListEmpty.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});

describe("ListEmpty", () => {
  it("says there is nothing yet, with the main action of the list, when no filter is set", () => {
    wrapper = mount(ListEmpty, {
      props: { filtered: false, emptyText: "Nie ma jeszcze produktów." },
      slots: { action: () => h("button", { "data-testid": "add" }, "Dodaj produkt") },
    });

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Nie ma jeszcze produktów.");
    expect(wrapper.find("[data-testid=add]").exists()).toBe(true);
    expect(wrapper.text()).not.toContain("Brak wyników");
    expect(wrapper.find("[data-testid=empty-clear-filters]").exists()).toBe(false);
  });

  it("has no action when the role cannot create", () => {
    wrapper = mount(ListEmpty, { props: { filtered: false, emptyText: "Nie ma jeszcze produktów." } });

    expect(wrapper.find("button").exists()).toBe(false);
  });

  it("says there are no results for the filters, with „Wyczyść filtry”, and has no main action", async () => {
    wrapper = mount(ListEmpty, {
      props: { filtered: true, emptyText: "Nie ma jeszcze produktów." },
      slots: { action: () => h("button", { "data-testid": "add" }, "Dodaj produkt") },
    });

    expect(wrapper.get("[data-testid=empty]").text()).toContain("Brak wyników dla wybranych filtrów.");
    expect(wrapper.text()).not.toContain("Nie ma jeszcze produktów.");
    expect(wrapper.find("[data-testid=add]").exists()).toBe(false);
    const clear = wrapper.get("[data-testid=empty-clear-filters]");
    expect(clear.text()).toBe("Wyczyść filtry");

    await clear.trigger("click");
    expect(wrapper.emitted("clear")).toHaveLength(1);
  });

  it("says only that the page is empty on a page after the first, with no action and no „Wyczyść filtry”", () => {
    wrapper = mount(ListEmpty, {
      props: { filtered: true, laterPage: true, emptyText: "Nie ma jeszcze produktów." },
      slots: { action: () => h("button", { "data-testid": "add" }, "Dodaj produkt") },
    });

    expect(wrapper.get("[data-testid=empty]").text()).toBe("Na tej stronie nie ma już wyników.");
    expect(wrapper.find("button").exists()).toBe(false);
  });
});
