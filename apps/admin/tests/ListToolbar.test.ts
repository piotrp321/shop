import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { h } from "vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import { Select } from "@/components/ui/select";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

function mountToolbar(props: Record<string, unknown> = {}) {
  wrapper = mount(ListToolbar, {
    props: { search: "", searchLabel: "Szukaj po nazwie produktu", filtered: false, perPage: 25, ...props },
    slots: {
      filters: () => h("div", { "data-testid": "filters" }, "filtry"),
      actions: () => h("a", { "data-testid": "add-product" }, "Dodaj produkt"),
    },
    attachTo: document.body,
  });
  return wrapper;
}

describe("ListToolbar", () => {
  it("has a search field with an accessible name, a placeholder and the length limit", () => {
    const toolbar = mountToolbar({ searchPlaceholder: "Szukaj po nazwie", searchMax: 100 });

    const field = toolbar.get<HTMLInputElement>("[data-testid=search]");
    expect(field.attributes("type")).toBe("search");
    expect(field.attributes("aria-label")).toBe("Szukaj po nazwie produktu");
    expect(field.attributes("placeholder")).toBe("Szukaj po nazwie");
    expect(field.attributes("maxlength")).toBe("100");
  });

  it("reports what is typed", async () => {
    const toolbar = mountToolbar();

    await toolbar.get("[data-testid=search]").setValue("buty");

    expect(toolbar.emitted("update:search")).toEqual([["buty"]]);
  });

  it("has no search field for a list that cannot be searched", () => {
    const toolbar = mountToolbar({ search: undefined });

    expect(toolbar.find("[data-testid=search]").exists()).toBe(false);
  });

  it("shows the hint under the search, in a status region tied to the field", () => {
    const toolbar = mountToolbar({ searchHint: "Wpisz co najmniej 2 znaki, aby wyszukać." });

    const hint = toolbar.get("[data-testid=search-hint]");
    expect(hint.text()).toBe("Wpisz co najmniej 2 znaki, aby wyszukać.");
    expect(hint.attributes("role")).toBe("status");
    expect(toolbar.get("[data-testid=search]").attributes("aria-describedby")).toBe(hint.attributes("id"));
  });

  it("shows no hint text when there is none, but keeps the region", () => {
    const toolbar = mountToolbar({ searchHint: null });

    expect(toolbar.get("[data-testid=search-hint]").text()).toBe("");
  });

  it("shows the filters and the main action of the list", () => {
    const toolbar = mountToolbar();

    expect(toolbar.find("[data-testid=filters]").exists()).toBe(true);
    expect(toolbar.find("[data-testid=add-product]").exists()).toBe(true);
  });

  it("shows „Wyczyść filtry” only when a filter or a search is set", async () => {
    const toolbar = mountToolbar({ filtered: false });
    expect(toolbar.find("[data-testid=clear-filters]").exists()).toBe(false);

    await toolbar.setProps({ filtered: true });
    const clear = toolbar.get("[data-testid=clear-filters]");
    expect(clear.text()).toBe("Wyczyść filtry");

    await clear.trigger("click");
    expect(toolbar.emitted("clear")).toHaveLength(1);
  });

  it("offers 10, 25 and 50 rows with a label, and reports the choice as a number", async () => {
    const toolbar = mountToolbar({ perPage: 25 });

    const trigger = document.body.querySelector<HTMLButtonElement>("[data-testid=per-page]")!;
    expect(trigger.getAttribute("role")).toBe("combobox");
    expect(trigger.labels?.[0]?.textContent?.trim()).toBe("Wierszy na stronie");
    expect(trigger.textContent).toContain("25");

    toolbar.getComponent(Select).vm.$emit("update:modelValue", "10");
    await flushPromises();
    expect(toolbar.emitted("update:perPage")).toEqual([[10]]);
  });

  it("moves the focus to the search field on request", async () => {
    const toolbar = mountToolbar();

    (toolbar.vm as unknown as { focusSearch: () => void }).focusSearch();

    expect(document.activeElement).toBe(toolbar.get("[data-testid=search]").element);
  });
});
