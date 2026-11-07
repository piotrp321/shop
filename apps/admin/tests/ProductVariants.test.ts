import { mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { describe, expect, it } from "vitest";
import { primeVueOptions } from "@/primevue";
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

function render(props: Partial<Props> = {}) {
  return mount(ProductVariants, {
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
    // The dialog is teleported to <body>; stubbed, it is rendered in place and can be found in the wrapper.
    global: { plugins: [[PrimeVue, primeVueOptions]] as never[], stubs: { teleport: true } },
  });
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
      expect(wrapper.find("[data-testid=remove-confirm]").exists()).toBe(false);
    });

    it("asks before removing an existing variant and removes it only after the confirmation", async () => {
      const wrapper = render();

      await wrapper.findAll("[data-testid=variant-remove]")[1]!.trigger("click");

      expect(wrapper.emitted("removeVariant")).toBeUndefined();
      expect(wrapper.get("[data-testid=remove-confirm]").text()).toContain("Cena i stan wariantu przestaną być widoczne w panelu.");

      await wrapper.get("[data-testid=remove-confirm-accept]").trigger("click");

      expect(wrapper.emitted("removeVariant")).toEqual([[1]]);
      expect(wrapper.find("[data-testid=remove-confirm]").exists()).toBe(false);
    });

    it("keeps the variant when the confirmation is cancelled", async () => {
      const wrapper = render();

      await wrapper.findAll("[data-testid=variant-remove]")[0]!.trigger("click");
      await wrapper.get("[data-testid=remove-confirm-cancel]").trigger("click");

      expect(wrapper.emitted("removeVariant")).toBeUndefined();
      expect(wrapper.find("[data-testid=remove-confirm]").exists()).toBe(false);
    });

    it("does not offer removing the only row", () => {
      const wrapper = render({ rows: [{ variantId: "v-1", values: ["czarne"] }] });

      expect(DISABLED(wrapper, "variant-remove")).toEqual([true]);
    });

    it("asks before removing the last axis when that drops existing variants, and not when it drops none", async () => {
      const wrapper = render();

      await wrapper.get("[data-testid=axis-remove]").trigger("click");
      expect(wrapper.emitted("removeAxis")).toBeUndefined();
      expect(wrapper.get("[data-testid=remove-confirm]").text()).toContain("Cena i stan wariantu przestaną być widoczne w panelu.");
      await wrapper.get("[data-testid=remove-confirm-accept]").trigger("click");
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
