import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";
import ProductVariants from "@/products/ProductVariants.vue";
import type { VariantRow } from "@/products/useProductForm";

interface Props {
  axes: string[];
  rows: VariantRow[];
  errors: string[];
  readOnly: boolean;
  axesLocked: boolean;
  errorsAt: (path: string) => string[];
  errorsExactly: (path: string) => string[];
}

const TWO_ROWS: VariantRow[] = [
  { variantId: "v-1", values: ["czarne"] },
  { variantId: "v-2", values: ["białe"] },
];

let mounted: VueWrapper | undefined;

afterEach(() => {
  mounted?.unmount();
  mounted = undefined;
  document.body.innerHTML = "";
});

function render(props: Partial<Props> = {}) {
  mounted = mount(ProductVariants, {
    props: {
      axes: ["Kolor"],
      rows: TWO_ROWS,
      errors: [],
      readOnly: false,
      axesLocked: false,
      errorsAt: () => [],
      errorsExactly: () => [],
      ...props,
    },
    // The dialog is teleported to <body>.
    attachTo: document.body,
  });
  return mounted;
}

const dialog = () => document.body.querySelector<HTMLElement>("[role=alertdialog]");
const dialogButton = (name: string) => [...(dialog()?.querySelectorAll("button") ?? [])].find((candidate) => candidate.textContent?.trim() === name);
const pressEscape = () => (document.activeElement ?? document.body).dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

const TWO_AXES_ROWS: VariantRow[] = [
  { variantId: "v-1", values: ["czarny", "42"] },
  { variantId: "v-2", values: ["biały", "43"] },
];

/** The form around the editor: removing a row changes the props, like `ProductFormView` does. */
function renderInForm(initial: VariantRow[] = TWO_ROWS) {
  const Host = defineComponent({
    components: { ProductVariants },
    setup() {
      const rows = ref(initial.map((row) => ({ ...row, values: [...row.values] })));
      return { rows, remove: (index: number) => rows.value.splice(index, 1) };
    },
    template: `<ProductVariants :axes="['Kolor']" :rows="rows" :errors="[]" :read-only="false" :axes-locked="false" :errors-at="() => []" :errors-exactly="() => []" @remove-variant="remove" />`,
  });
  mounted = mount(Host, { attachTo: document.body });
  return mounted;
}

const DISABLED = (wrapper: ReturnType<typeof render>, id: string) => wrapper.findAll(`[data-testid=${id}]`).map((node) => node.attributes("disabled") !== undefined);

describe("ProductVariants", () => {
  describe("axes and rows", () => {
    it("shows a field for the name of every axis and a field for every value", () => {
      const wrapper = render({ axes: ["Kolor", "Rozmiar"], rows: [{ variantId: "v-1", values: ["czarne", "M"] }] });

      expect(wrapper.findAll("[data-testid=axis-name]").map((node) => (node.element as HTMLInputElement).value)).toEqual(["Kolor", "Rozmiar"]);
      expect(wrapper.findAll("[data-testid=variant-value]").map((node) => (node.element as HTMLInputElement).value)).toEqual(["czarne", "M"]);
      expect(wrapper.get("[data-testid=variant-value]").attributes("aria-label")).toBe("Kolor, wariant 1");
    });

    it("shows one default variant without fields for a product without axes", () => {
      const wrapper = render({ axes: [], rows: [{ variantId: null, values: [] }] });

      expect(wrapper.findAll("[data-testid=variant-row]")).toHaveLength(1);
      expect(wrapper.get("[data-testid=variant-row]").text()).toContain("Wariant domyślny");
      expect(wrapper.find("[data-testid=variant-value]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=variant-remove]").exists()).toBe(false);
      expect(DISABLED(wrapper, "add-variant")).toEqual([true]);
      expect(wrapper.text()).toContain("Dodaj oś, aby produkt mógł mieć więcej niż jeden wariant.");
    });

    it("does not suggest adding an axis when the axes are locked", () => {
      const wrapper = render({ axes: [], rows: [{ variantId: "v-1", values: [] }], axesLocked: true });

      expect(DISABLED(wrapper, "add-axis")).toEqual([true]);
      expect(wrapper.text()).not.toContain("Dodaj oś, aby");
    });

    it("labels the buttons that move an axis earlier or later", () => {
      const wrapper = render({ axes: ["Kolor", "Rozmiar"], rows: [{ variantId: null, values: ["a", "b"] }] });

      expect(wrapper.findAll("[data-testid=axis-move-up]").map((node) => node.attributes("aria-label"))).toEqual(["Przesuń oś 1 wcześniej", "Przesuń oś 2 wcześniej"]);
      expect(wrapper.findAll("[data-testid=axis-move-down]").map((node) => node.attributes("aria-label"))).toEqual(["Przesuń oś 1 później", "Przesuń oś 2 później"]);
    });

    it("emits the changes of an axis name and of a value", async () => {
      const wrapper = render();

      await wrapper.get("[data-testid=axis-name]").setValue("Barwa");
      await wrapper.get("[data-testid=variant-value]").setValue("Czarne");

      expect(wrapper.emitted("setAxis")).toEqual([[0, "Barwa"]]);
      expect(wrapper.emitted("setValue")).toEqual([[0, 0, "Czarne"]]);
    });

    it("emits adding an axis and a row, and disables adding a third axis", async () => {
      const wrapper = render();
      await wrapper.get("[data-testid=add-axis]").trigger("click");
      await wrapper.get("[data-testid=add-variant]").trigger("click");
      expect(wrapper.emitted("addAxis")).toHaveLength(1);
      expect(wrapper.emitted("addVariant")).toHaveLength(1);

      await wrapper.setProps({ axes: ["Kolor", "Rozmiar"] });
      expect(DISABLED(wrapper, "add-axis")).toEqual([true]);
    });

    it("disables adding a row at 100 rows", () => {
      const rows = Array.from({ length: 100 }, () => ({ variantId: null, values: [""] }));

      expect(DISABLED(render({ rows }), "add-variant")).toEqual([true]);
    });

    it("emits moving and removing an axis, and moves are disabled at the ends", async () => {
      const wrapper = render({ axes: ["Kolor", "Rozmiar"], rows: [{ variantId: null, values: ["a", "b"] }] });

      expect(DISABLED(wrapper, "axis-move-up")).toEqual([true, false]);
      expect(DISABLED(wrapper, "axis-move-down")).toEqual([false, true]);
      await wrapper.findAll("[data-testid=axis-move-down]")[0]!.trigger("click");
      await wrapper.findAll("[data-testid=axis-remove]")[1]!.trigger("click");

      expect(wrapper.emitted("moveAxis")).toEqual([[0, 1]]);
      expect(wrapper.emitted("removeAxis")).toEqual([[1]]);
    });
  });

  describe("removing a row", () => {
    it("removes a new row without asking", async () => {
      const wrapper = render({ rows: [{ variantId: "v-1", values: ["czarne"] }, { variantId: null, values: ["zielone"] }] });

      await wrapper.findAll("[data-testid=variant-remove]")[1]!.trigger("click");

      expect(wrapper.emitted("removeVariant")).toEqual([[1]]);
      expect(dialog()).toBeNull();
    });

    it("#251 F12: asks with the name of the variant before removing an existing one, and removes it only after the confirmation", async () => {
      const wrapper = render({ axes: ["Kolor", "Rozmiar"], rows: TWO_AXES_ROWS });

      await wrapper.findAll("[data-testid=variant-remove]")[0]!.trigger("click");
      await flushPromises();

      expect(wrapper.emitted("removeVariant")).toBeUndefined();
      const title = document.getElementById(dialog()?.getAttribute("aria-labelledby") ?? "");
      const description = document.getElementById(dialog()?.getAttribute("aria-describedby") ?? "");
      expect(title?.textContent).toBe("Usunąć wariant czarny / 42?");
      expect(description?.textContent).toBe("Cena i stan wariantu przestaną być widoczne w panelu.");

      dialogButton("Usuń wariant czarny / 42")?.click();
      await flushPromises();

      expect(wrapper.emitted("removeVariant")).toEqual([[0]]);
      expect(dialog()).toBeNull();
    });

    it("#251 F12: the confirmation is red, has „Wróć” with the focus and a close button named „Zamknij”", async () => {
      const wrapper = render();

      await wrapper.findAll("[data-testid=variant-remove]")[1]!.trigger("click");
      await flushPromises();

      expect(dialogButton("Usuń wariant białe")?.className).toContain("destructive");
      expect(document.activeElement).toBe(dialogButton("Wróć"));
      expect(dialog()?.querySelector(".sr-only")?.textContent).toBe("Zamknij");
    });

    it("keeps the variant when the confirmation is cancelled with „Wróć” and gives the focus back to the button", async () => {
      const wrapper = render();
      const trigger = wrapper.findAll("[data-testid=variant-remove]")[0]!.element as HTMLElement;
      trigger.focus();

      await wrapper.findAll("[data-testid=variant-remove]")[0]!.trigger("click");
      await flushPromises();
      dialogButton("Wróć")?.click();
      await flushPromises();

      expect(wrapper.emitted("removeVariant")).toBeUndefined();
      expect(dialog()).toBeNull();
      expect(document.activeElement).toBe(trigger);
    });

    it("closes with Escape and gives the focus back to the button", async () => {
      const wrapper = render();
      const trigger = wrapper.findAll("[data-testid=variant-remove]")[1]!.element as HTMLElement;
      trigger.focus();

      await wrapper.findAll("[data-testid=variant-remove]")[1]!.trigger("click");
      await flushPromises();
      pressEscape();
      await flushPromises();

      expect(dialog()).toBeNull();
      expect(document.activeElement).toBe(trigger);
    });

    it("moves the focus to the heading „Warianty” when the removed row took its button with it", async () => {
      const wrapper = renderInForm();
      const trigger = wrapper.findAll("[data-testid=variant-remove]")[0]!.element as HTMLElement;
      trigger.focus();

      await wrapper.findAll("[data-testid=variant-remove]")[0]!.trigger("click");
      await flushPromises();
      dialogButton("Usuń wariant czarne")?.click();
      await flushPromises();

      expect(wrapper.findAll("[data-testid=variant-row]")).toHaveLength(1);
      expect(document.activeElement).toBe(document.querySelector("h2"));
      expect(document.activeElement?.textContent).toBe("Warianty");
    });

    it("does not offer removing the only row", () => {
      const wrapper = render({ rows: [{ variantId: "v-1", values: ["czarne"] }] });

      expect(DISABLED(wrapper, "variant-remove")).toEqual([true]);
    });

    it("asks before removing the last axis when that drops existing variants, and not when it drops none", async () => {
      const wrapper = render();

      await wrapper.get("[data-testid=axis-remove]").trigger("click");
      await flushPromises();
      expect(wrapper.emitted("removeAxis")).toBeUndefined();
      const title = document.getElementById(dialog()?.getAttribute("aria-labelledby") ?? "");
      expect(title?.textContent).toBe("Usunąć oś Kolor?");
      expect(dialog()?.textContent).toContain("Cena i stan wariantu przestaną być widoczne w panelu.");
      dialogButton("Usuń oś Kolor")?.click();
      await flushPromises();
      expect(wrapper.emitted("removeAxis")).toEqual([[0]]);

      const single = render({ rows: [{ variantId: "v-1", values: ["czarne"] }] });
      await single.get("[data-testid=axis-remove]").trigger("click");
      expect(single.emitted("removeAxis")).toEqual([[0]]);
    });
  });

  describe("after the first publication", () => {
    it("disables the controls of the axes, shows the explanation and keeps the fields and rows editable", () => {
      const wrapper = render({ axesLocked: true });

      expect(DISABLED(wrapper, "add-axis")).toEqual([true]);
      expect(DISABLED(wrapper, "axis-remove")).toEqual([true]);
      expect(DISABLED(wrapper, "axis-move-up")).toEqual([true]);
      expect(DISABLED(wrapper, "axis-move-down")).toEqual([true]);
      expect(wrapper.get("[data-testid=variants-locked]").text()).toBe(
        "Wartości istniejących wariantów można poprawić tylko w wielkości liter. Inną zmianę zrób przez dodanie nowego wariantu i usunięcie starego.",
      );
      expect(DISABLED(wrapper, "axis-name")).toEqual([false]);
      expect(DISABLED(wrapper, "variant-value")).toEqual([false, false]);
      expect(DISABLED(wrapper, "add-variant")).toEqual([false]);
      expect(DISABLED(wrapper, "variant-remove")).toEqual([false, false]);
    });

    it("does not show the explanation before the first publication", () => {
      expect(render().find("[data-testid=variants-locked]").exists()).toBe(false);
    });
  });

  describe("read-only", () => {
    it("has no controls for editing and disables the fields", () => {
      const wrapper = render({ readOnly: true, axesLocked: true });

      for (const id of ["add-axis", "add-variant", "axis-remove", "axis-move-up", "axis-move-down", "variant-remove"]) {
        expect(wrapper.find(`[data-testid=${id}]`).exists()).toBe(false);
      }
      expect(DISABLED(wrapper, "axis-name")).toEqual([true]);
      expect(DISABLED(wrapper, "variant-value")).toEqual([true, true]);
      expect(wrapper.find("[data-testid=variants-locked]").exists()).toBe(false);
    });
  });

  describe("errors", () => {
    it("shows the section messages, and the ones for an axis, a row and a value where they belong", () => {
      const at: Record<string, string[]> = {
        "variantAxes[0]": ["Nazwa osi jest za długa."],
        "variants[1].values[0]": ["Ta kombinacja już istnieje."],
        "variants[1].values": ["Zła liczba wartości."],
        "variants[1].variantId": ["Nieznany wariant."],
      };
      const prefix = (path: string) => Object.entries(at).filter(([key]) => key === path || key.startsWith(`${path}[`) || key.startsWith(`${path}.`)).flatMap(([, titles]) => titles);
      const exact = (path: string) => at[path] ?? [];
      const wrapper = render({ errors: ["Wariant ma stan magazynowy."], errorsAt: prefix, errorsExactly: exact });

      expect(wrapper.findAll("[data-testid=variants-error]").map((node) => node.text())).toEqual(["Wariant ma stan magazynowy."]);
      expect(wrapper.findAll("[data-testid=axis-error]").map((node) => node.text())).toEqual(["Nazwa osi jest za długa."]);
      const rows = wrapper.findAll("[data-testid=variant-row]");
      expect(rows[0]!.findAll("[data-testid=value-error], [data-testid=row-error]")).toHaveLength(0);
      expect(rows[1]!.findAll("[data-testid=value-error]").map((node) => node.text())).toEqual(["Ta kombinacja już istnieje."]);
      expect(rows[1]!.findAll("[data-testid=row-error]").map((node) => node.text())).toEqual(["Zła liczba wartości.", "Nieznany wariant."]);
      expect(rows[1]!.findAll("[data-testid=variant-value]")[0]!.attributes("aria-invalid")).toBe("true");
    });
  });
});
