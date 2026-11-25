import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";
import ProductVariants from "@/products/ProductVariants.vue";
import { upperAscii } from "@/products/contentText";
import type { VariantRow } from "@/products/useProductForm";

/**
 * The columns „SKU” and „EAN” of the table of the variants (docs/features/product-data/spec.md, „Panel”): a field of each in
 * every row, also of a product with one variant and no axis, with the limits of the length, a label with the number of the
 * variant, the messages of the API at the row they are about, and the changes that go to the form as they are typed.
 */

const ROWS: VariantRow[] = [
  { variantId: "v-1", values: ["czarne"], sku: "BUT-C", ean: "5901234123457" },
  { variantId: "v-2", values: ["białe"], sku: "", ean: "" },
];

let mounted: VueWrapper | undefined;

afterEach(() => {
  mounted?.unmount();
  mounted = undefined;
  document.body.innerHTML = "";
});

function render(props: Partial<InstanceType<typeof ProductVariants>["$props"]> = {}) {
  mounted = mount(ProductVariants, {
    props: {
      axes: ["Kolor"],
      rows: ROWS,
      errors: [],
      readOnly: false,
      axesLocked: false,
      errorsAt: () => [],
      errorsExactly: () => [],
      ...props,
    },
    attachTo: document.body,
  });
  return mounted;
}

const inputs = (wrapper: VueWrapper, id: string) => wrapper.findAll<HTMLInputElement>(`[data-testid=${id}]`);

describe("the columns SKU and EAN of the variants", () => {
  it("have a field in every row, with the code of the row and a label with the number of the variant", () => {
    const wrapper = render();

    expect(inputs(wrapper, "variant-sku").map((node) => node.element.value)).toEqual(["BUT-C", ""]);
    expect(inputs(wrapper, "variant-ean").map((node) => node.element.value)).toEqual(["5901234123457", ""]);
    expect(inputs(wrapper, "variant-sku").map((node) => node.attributes("aria-label"))).toEqual(["SKU, wariant 1", "SKU, wariant 2"]);
    expect(inputs(wrapper, "variant-ean").map((node) => node.attributes("aria-label"))).toEqual(["EAN, wariant 1", "EAN, wariant 2"]);
  });

  it("have the limits of 40 characters for the SKU and 14 for the EAN, and never shorten what is in the field", () => {
    const wrapper = render();

    expect(inputs(wrapper, "variant-sku")[0]!.attributes("maxlength")).toBe("40");
    expect(inputs(wrapper, "variant-ean")[0]!.attributes("maxlength")).toBe("14");
  });

  it("are headed in the table also for a product without axes", () => {
    const wrapper = render({ axes: [], rows: [{ variantId: null, values: [], sku: "", ean: "" }] });

    expect(wrapper.findAll("th").map((node) => node.text())).toEqual(["Wariant", "SKU", "EAN", "Akcje"]);
  });

  it("are headed after the axes of a product that has them", () => {
    const wrapper = render({ axes: ["Kolor", "Rozmiar"], rows: [{ variantId: "v-1", values: ["czarne", "M"], sku: "", ean: "" }] });

    expect(wrapper.findAll("th").map((node) => node.text())).toEqual(["Kolor", "Rozmiar", "SKU", "EAN", "Akcje"]);
  });

  it("are in the one row of a product with one variant and no axis", () => {
    const wrapper = render({ axes: [], rows: [{ variantId: "v-1", values: [], sku: "BUT-1", ean: "59012344" }] });

    expect(wrapper.findAll("[data-testid=variant-row]")).toHaveLength(1);
    expect(inputs(wrapper, "variant-sku")[0]!.element.value).toBe("BUT-1");
    expect(inputs(wrapper, "variant-ean")[0]!.element.value).toBe("59012344");
    expect(wrapper.get("[data-testid=variant-row]").text()).toContain("Wariant domyślny");
  });

  it("emit the typed code with the number of the row, the SKU already in capitals", async () => {
    const wrapper = render();

    await inputs(wrapper, "variant-sku")[1]!.setValue("nowy-1/ż");
    await inputs(wrapper, "variant-ean")[0]!.setValue("59012344");

    expect(wrapper.emitted("setSku")).toEqual([[1, "NOWY-1/ż"]]);
    expect(wrapper.emitted("setEan")).toEqual([[0, "59012344"]]);
  });

  describe("the SKU in the field while it is typed", () => {
    /** The form around the table: it keeps the rows and turns the SKU into capitals, as `useProductForm` does. */
    function renderInForm(sku: string) {
      const Host = defineComponent({
        components: { ProductVariants },
        setup() {
          const rows = ref<VariantRow[]>([{ variantId: "v-1", values: ["czarne"], sku, ean: "" }]);
          const typed = (index: number, value: string) => (rows.value[index]!.sku = upperAscii(value));
          const readOnly = ref(false);
          return { rows, typed, readOnly };
        },
        template: `<ProductVariants :axes="['Kolor']" :rows="rows" :errors="[]" :read-only="readOnly" :axes-locked="false" :errors-at="() => []" :errors-exactly="() => []" @set-sku="typed" />`,
      });
      mounted = mount(Host, { attachTo: document.body });
      return mounted;
    }

    it("shows capitals, and no character is removed", async () => {
      const wrapper = renderInForm("");

      await inputs(wrapper, "variant-sku")[0]!.setValue("but-1/ż_2 .x\t");
      await flushPromises();

      expect(inputs(wrapper, "variant-sku")[0]!.element.value).toBe("BUT-1/ż_2 .X\t");
    });

    it("shows capitals also when the form already has the same code, so the state does not change and nothing is drawn again", async () => {
      const wrapper = renderInForm("A");

      await inputs(wrapper, "variant-sku")[0]!.setValue("a");
      await flushPromises();

      expect(inputs(wrapper, "variant-sku")[0]!.element.value).toBe("A");
    });

    it("keeps the position of the caret when it turns a letter typed in the middle into a capital", async () => {
      const wrapper = renderInForm("AB");
      const input = inputs(wrapper, "variant-sku")[0]!.element;
      input.focus();
      input.value = "AxB";
      input.setSelectionRange(2, 2);

      await inputs(wrapper, "variant-sku")[0]!.trigger("input");
      await flushPromises();

      expect(input.value).toBe("AXB");
      expect([input.selectionStart, input.selectionEnd]).toEqual([2, 2]);
    });

    it("keeps the capitals after the field is drawn again for another reason (the form locks while it saves)", async () => {
      const wrapper = renderInForm("A");
      await inputs(wrapper, "variant-sku")[0]!.setValue("a");
      await flushPromises();

      (wrapper.vm as unknown as { readOnly: boolean }).readOnly = true;
      await flushPromises();

      expect(inputs(wrapper, "variant-sku")[0]!.element.value).toBe("A");
    });
  });

  it("show the SKU in the form of the row: the capitals the form made of what was typed", async () => {
    const wrapper = render();

    await inputs(wrapper, "variant-sku")[1]!.setValue("nowy-1");
    await wrapper.setProps({ rows: [ROWS[0]!, { ...ROWS[1]!, sku: "NOWY-1" }] });

    expect(inputs(wrapper, "variant-sku")[1]!.element.value).toBe("NOWY-1");
  });

  it("are read-only, not disabled, for a staff member who may not change products: the whole code can still be read", () => {
    const wrapper = render({ readOnly: true });

    for (const node of [...inputs(wrapper, "variant-sku"), ...inputs(wrapper, "variant-ean")]) {
      expect(node.element.readOnly).toBe(true);
      expect(node.element.disabled).toBe(false);
    }
  });

  it("show the messages of the API at the code they are about, joined to the field and marked invalid", () => {
    const at: Record<string, string[]> = {
      "variants[1].sku": ["Ten kod SKU powtarza się w produkcie."],
      "variants[0].ean": ["Podaj poprawny kod EAN: 8, 12, 13 albo 14 cyfr z cyfrą kontrolną."],
    };
    const wrapper = render({ errorsAt: (path) => Object.entries(at).filter(([key]) => key === path || key.startsWith(`${path}.`) || key.startsWith(`${path}[`)).flatMap(([, titles]) => titles), errorsExactly: (path) => at[path] ?? [] });

    const rows = wrapper.findAll("[data-testid=variant-row]");
    expect(rows[1]!.findAll("[data-testid=sku-error]").map((node) => node.text())).toEqual(["Ten kod SKU powtarza się w produkcie."]);
    expect(rows[0]!.findAll("[data-testid=sku-error]")).toHaveLength(0);
    expect(rows[0]!.findAll("[data-testid=ean-error]").map((node) => node.text())).toEqual(["Podaj poprawny kod EAN: 8, 12, 13 albo 14 cyfr z cyfrą kontrolną."]);
    expect(inputs(wrapper, "variant-sku")[1]!.attributes("aria-invalid")).toBe("true");
    expect(inputs(wrapper, "variant-sku")[0]!.attributes("aria-invalid")).toBeUndefined();
    expect(inputs(wrapper, "variant-ean")[0]!.attributes("aria-invalid")).toBe("true");
    const describedBy = inputs(wrapper, "variant-sku")[1]!.attributes("aria-describedby");
    expect(describedBy).toBeTruthy();
    expect(document.getElementById(describedBy!)?.textContent).toBe("Ten kod SKU powtarza się w produkcie.");
  });

  it("are inside the region of the table that scrolls, so the page does not (375 px)", () => {
    const wrapper = render();

    const region = wrapper.get("[role=region]");
    expect(region.attributes("aria-label")).toBe("Warianty produktu");
    expect(region.attributes("tabindex")).toBe("0");
    expect(region.findAll("[data-testid=variant-sku]")).toHaveLength(2);
  });
});
