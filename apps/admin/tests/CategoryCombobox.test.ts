import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { ComboboxRoot } from "reka-ui";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, h, ref } from "vue";
import CategoryCombobox, { ROOT_CATEGORY } from "@/components/CategoryCombobox.vue";
import type { CategoryTreeNode } from "@/composables/useCategoryTree";

// The shape of `useCategoryTree().nodes`: Turystyka > (Biwak > (Namioty, Śpiwory), Plecaki), Sport > Bieganie.
const nodes: CategoryTreeNode[] = [
  {
    key: "c-tourism",
    label: "Turystyka",
    children: [
      {
        key: "c-camping",
        label: "Biwak",
        children: [
          { key: "c-tents", label: "Namioty", children: [] },
          { key: "c-sleeping-bags", label: "Śpiwory", children: [] },
        ],
      },
      { key: "c-backpacks", label: "Plecaki", children: [] },
    ],
  },
  { key: "c-sport", label: "Sport", children: [{ key: "c-running", label: "Bieganie", children: [] }] },
];

const TREE_ORDER = [
  "Turystyka",
  "Turystyka › Biwak",
  "Turystyka › Biwak › Namioty",
  "Turystyka › Biwak › Śpiwory",
  "Turystyka › Plecaki",
  "Sport",
  "Sport › Bieganie",
];

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

function mountCombobox(props: Record<string, unknown> = {}) {
  wrapper = mount(CategoryCombobox, {
    props: { nodes, label: "Kategoria", modelValue: null, ...props },
    attachTo: document.body,
  });
  return wrapper;
}

const input = () => document.body.querySelector<HTMLInputElement>("[role=combobox]")!;
const options = () => [...document.body.querySelectorAll<HTMLElement>("[role=option]")];
const optionNames = () => options().map((option) => option.getAttribute("aria-label"));

async function open() {
  input().dispatchEvent(new MouseEvent("click", { bubbles: true }));
  await flushPromises();
}

async function type(text: string) {
  input().focus();
  input().value = text;
  input().dispatchEvent(new Event("input", { bubbles: true }));
  await flushPromises();
}

describe("CategoryCombobox", () => {
  it("is a combobox named by its label", () => {
    mountCombobox({ label: "Kategoria główna" });

    const field = input();
    expect(field).not.toBeNull();
    expect(field.labels?.[0]?.textContent).toBe("Kategoria główna");
  });

  it("can hide the label visually and keep it as the accessible name", () => {
    mountCombobox({ label: "Kategoria", hideLabel: true });

    const label = input().labels?.[0];
    expect(label?.textContent).toBe("Kategoria");
    expect(label?.classList.contains("sr-only")).toBe(true);
  });

  it("lists the categories in the order of the tree with the full path as the accessible name", async () => {
    mountCombobox();
    await open();

    expect(optionNames()).toEqual(TREE_ORDER);
    // The visible text is the name only, indented by the level.
    expect(options().map((option) => option.textContent?.trim())).toEqual([
      "Turystyka",
      "Biwak",
      "Namioty",
      "Śpiwory",
      "Plecaki",
      "Sport",
      "Bieganie",
    ]);
    expect(options().map((option) => option.dataset.depth)).toEqual(["0", "1", "2", "2", "1", "0", "1"]);
  });

  it("filters by the name of a category", async () => {
    mountCombobox();
    await open();
    await type("namio");

    expect(optionNames()).toEqual(["Turystyka › Biwak › Namioty"]);
  });

  it("filters by the path, ignoring case and matching Polish letters as typed", async () => {
    mountCombobox();
    await open();
    await type("BIWAK");

    expect(optionNames()).toEqual(["Turystyka › Biwak", "Turystyka › Biwak › Namioty", "Turystyka › Biwak › Śpiwory"]);

    await type("śpi");
    expect(optionNames()).toEqual(["Turystyka › Biwak › Śpiwory"]);
  });

  it("says so when nothing matches", async () => {
    mountCombobox();
    await open();
    await type("zzz");

    expect(options()).toEqual([]);
    expect(document.body.textContent).toContain("Brak pasujących kategorii.");
  });

  it("selects one category in single mode and shows its name", async () => {
    const wrapper = mountCombobox();
    await open();
    options()[2]!.click();
    await flushPromises();

    expect(wrapper.emitted("update:modelValue")).toEqual([["c-tents"]]);
  });

  it("shows the selected category in the field in single mode", async () => {
    mountCombobox({ modelValue: "c-tents" });
    await flushPromises();

    expect(input().value).toBe("Namioty");
  });

  it("shows the name of the selected category when the tree arrives after the value (the address of a list names it first)", async () => {
    const wrapper = mountCombobox({ nodes: [], modelValue: "c-tents" });
    await flushPromises();
    expect(input().value).toBe("");

    await wrapper.setProps({ nodes });
    await flushPromises();

    expect(input().value).toBe("Namioty");
  });

  it("selects several categories in multiple mode and lists them with a removal button each", async () => {
    const wrapper = mountCombobox({ multiple: true, modelValue: ["c-tents", "c-running"] });
    await flushPromises();

    const removals = [...document.body.querySelectorAll<HTMLButtonElement>("button")].map((button) =>
      button.getAttribute("aria-label"),
    );
    expect(removals).toEqual(["Usuń: Namioty", "Usuń: Bieganie"]);

    document.body.querySelector<HTMLButtonElement>("button[aria-label='Usuń: Namioty']")!.click();
    await flushPromises();
    expect(wrapper.emitted("update:modelValue")).toEqual([[["c-running"]]]);
  });

  it("adds a category to the selected ones in multiple mode", async () => {
    const wrapper = mountCombobox({ multiple: true, modelValue: ["c-tents"] });
    await open();
    options()[4]!.click();
    await flushPromises();

    expect(wrapper.emitted("update:modelValue")).toEqual([[["c-tents", "c-backpacks"]]]);
  });

  it("has no button without an accessible name", async () => {
    mountCombobox({ multiple: true, modelValue: ["c-tents", "c-sport"] });
    await open();

    const unnamed = [...document.body.querySelectorAll("button")].filter(
      (button) => !(button.getAttribute("aria-label") || button.textContent?.trim()),
    );
    expect(unnamed).toEqual([]);
  });

  it("shows no options for an empty tree", async () => {
    mountCombobox({ nodes: [] });
    await open();

    expect(options()).toEqual([]);
    expect(document.body.textContent).toContain("Brak pasujących kategorii.");
  });

  it("keeps the selected category when it is chosen again, unless the field is deselectable", async () => {
    const wrapper = mountCombobox({ modelValue: "c-sport" });
    await open();
    options()[5]!.click();
    await flushPromises();

    expect(wrapper.emitted("update:modelValue")).toEqual([["c-sport"]]);
  });

  it("clears the selected category when it is chosen again in a deselectable field, and selects another as usual", async () => {
    const wrapper = mountCombobox({ modelValue: "c-sport", deselectable: true });
    await open();
    options()[5]!.click();
    await flushPromises();
    expect(wrapper.emitted("update:modelValue")).toEqual([[null]]);

    await open();
    options()[2]!.click();
    await flushPromises();
    expect(wrapper.emitted("update:modelValue")?.at(-1)).toEqual(["c-tents"]);
  });

  it("emits null, not undefined, when the combobox clears a single selection", async () => {
    const wrapper = mountCombobox({ modelValue: "c-tents" });
    wrapper.findComponent(ComboboxRoot).vm.$emit("update:modelValue", undefined);
    await flushPromises();

    expect(wrapper.emitted("update:modelValue")).toEqual([[null]]);
  });
});

describe("CategoryCombobox after a choice in single mode", () => {
  /** The element the field points at as the active option, if any. */
  const activeDescendant = () => {
    const id = input().getAttribute("aria-activedescendant");
    return id === null || id === "" ? null : document.getElementById(id);
  };
  const pointsAtExistingElement = () => {
    const id = input().getAttribute("aria-activedescendant");
    return id === null || id === "" || document.getElementById(id) !== null;
  };

  it("does not point at a removed option once an option is chosen by a click and the list is closed", async () => {
    mountCombobox();
    await open();
    input().focus();
    options()[2]!.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
    options()[2]!.click();
    await flushPromises();

    expect(options()).toEqual([]);
    expect(pointsAtExistingElement(), "aria-activedescendant is empty or names an element in the DOM").toBe(true);
  });

  it("does not point at a removed option once an option is chosen with Enter and the list is closed", async () => {
    const wrapper = mountCombobox();
    await open();
    input().focus();
    input().dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowDown", bubbles: true }));
    await flushPromises();
    input().dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true }));
    await flushPromises();

    expect(wrapper.emitted("update:modelValue")).toHaveLength(1);
    expect(options()).toEqual([]);
    expect(pointsAtExistingElement(), "aria-activedescendant is empty or names an element in the DOM").toBe(true);
  });

  it("points at the highlighted option while the list is open", async () => {
    mountCombobox();
    await open();
    input().focus();
    input().dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowDown", bubbles: true }));
    await flushPromises();

    expect(options().length).toBeGreaterThan(0);
    expect(activeDescendant()).not.toBeNull();
    expect(activeDescendant()?.getAttribute("role")).toBe("option");
  });

  // jsdom does not move the focus on a mousedown, so this passes before the change as well; the move of the focus to the
  // option and its return to the field are covered by the E2E tests (admin-screens-inventory.spec.ts, catalog-admin.spec.ts).
  it("keeps the focus in the field after an option is clicked", async () => {
    mountCombobox();
    await open();
    input().focus();
    options()[2]!.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
    options()[2]!.click();
    await flushPromises();

    expect(document.activeElement).toBe(input());
  });
});

describe("CategoryCombobox focus after a removal", () => {
  // A parent that owns the value, so that the removed chip really leaves the DOM.
  function mountWithParent(initial: string[]) {
    const Parent = defineComponent({
      setup() {
        const value = ref<string[]>(initial);
        return () => h(CategoryCombobox, { nodes, label: "Kategoria", multiple: true, modelValue: value.value, "onUpdate:modelValue": (next: unknown) => (value.value = next as string[]) });
      },
    });
    wrapper = mount(Parent, { attachTo: document.body });
    return wrapper;
  }

  const removal = (name: string) => document.body.querySelector<HTMLButtonElement>(`button[aria-label='Usuń: ${name}']`)!;

  async function remove(name: string) {
    removal(name).focus();
    removal(name).click();
    await flushPromises();
  }

  it("moves the focus to the removal button of the next chip", async () => {
    mountWithParent(["c-tents", "c-running", "c-sport"]);
    await flushPromises();
    await remove("Namioty");

    expect(document.activeElement).toBe(removal("Bieganie"));
  });

  it("moves the focus to the previous chip when the last one is removed", async () => {
    mountWithParent(["c-tents", "c-running", "c-sport"]);
    await flushPromises();
    await remove("Sport");

    expect(document.activeElement).toBe(removal("Bieganie"));
  });

  it("moves the focus to the input when the only chip is removed", async () => {
    mountWithParent(["c-tents"]);
    await flushPromises();
    await remove("Namioty");

    expect(document.activeElement).toBe(input());
  });
});

describe("CategoryCombobox in a form", () => {
  it("marks the input invalid and points it at the error message", () => {
    mountCombobox({ invalid: true, describedBy: "category-error" });

    expect(input().getAttribute("aria-invalid")).toBe("true");
    expect(input().getAttribute("aria-describedby")).toBe("category-error");
  });

  it("has neither attribute by default", () => {
    mountCombobox();

    expect(input().hasAttribute("aria-invalid")).toBe(false);
    expect(input().hasAttribute("aria-describedby")).toBe(false);
  });
});

describe("CategoryCombobox: a place to move to (root option and disabled categories)", () => {
  it("offers the root as the first option under the given label, with the depth 0", async () => {
    mountCombobox({ rootLabel: "Poziom główny" });
    await open();

    expect(optionNames()).toEqual(["Poziom główny", ...TREE_ORDER]);
    expect(options()[0]!.dataset.depth).toBe("0");
  });

  it("emits the marker of the root when it is chosen, and shows its label when it is the value", async () => {
    const wrapper = mountCombobox({ rootLabel: "Poziom główny" });
    await open();
    options()[0]!.click();
    await flushPromises();

    expect(wrapper.emitted("update:modelValue")).toEqual([[ROOT_CATEGORY]]);
    await wrapper.setProps({ modelValue: ROOT_CATEGORY });
    expect(input().value).toBe("Poziom główny");
  });

  it("finds the root by typing its label", async () => {
    mountCombobox({ rootLabel: "Poziom główny" });
    await open();
    await type("poziom");

    expect(optionNames()).toEqual(["Poziom główny"]);
  });

  it("has no root option without the label", async () => {
    mountCombobox();
    await open();

    expect(optionNames()).not.toContain("Poziom główny");
  });

  it("disables the given categories: they are marked, cannot be chosen and keep their place in the list", async () => {
    const wrapper = mountCombobox({ disabledIds: ["c-camping", "c-tents", "c-sleeping-bags"] });
    await open();

    expect(optionNames()).toEqual(TREE_ORDER);
    expect(options().map((option) => option.getAttribute("aria-disabled"))).toEqual([null, "true", "true", "true", null, null, null]);
    options()[1]!.click();
    options()[2]!.click();
    await flushPromises();
    expect(wrapper.emitted("update:modelValue")).toBeUndefined();

    options()[4]!.click();
    await flushPromises();
    expect(wrapper.emitted("update:modelValue")).toEqual([["c-backpacks"]]);
  });
});
