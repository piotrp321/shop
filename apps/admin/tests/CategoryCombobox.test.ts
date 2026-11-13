import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
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

  it("filters by the path, ignoring case and Polish letters", async () => {
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
});
