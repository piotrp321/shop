import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";
import ProductImages from "@/products/ProductImages.vue";
import type { FormImage, UploadEntry } from "@/products/useImageUpload";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

const image = (key: string, alt: string): FormImage => ({ key, alt, variants: {} });
const entry = (id: number, status: UploadEntry["status"], message: string | null = null): UploadEntry => ({ id, fileName: `plik-${id}.jpg`, status, message, retryable: status === "failed" });

/** The form around the section: removing an image or hiding a result changes the props, like `ProductFormView` does. */
function mountSection(initial: { images?: FormImage[]; entries?: UploadEntry[]; readOnly?: boolean; errors?: string[] } = {}) {
  const Host = defineComponent({
    components: { ProductImages },
    setup() {
      const images = ref(initial.images ?? [image("a", "Przód"), image("b", "Bok"), image("c", "Tył")]);
      const entries = ref(initial.entries ?? []);
      return {
        images,
        entries,
        readOnly: initial.readOnly ?? false,
        errors: initial.errors ?? [],
        remove: (index: number) => images.value.splice(index, 1),
        dismiss: (id: number) => (entries.value = entries.value.filter((candidate) => candidate.id !== id)),
      };
    },
    template: `<ProductImages :images="images" :entries="entries" :can-add-more="true" :read-only="readOnly" :errors-at="() => []" :errors="errors" @remove="remove" @dismiss="dismiss" />`,
  });
  wrapper = mount(Host, { attachTo: document.body });
  return wrapper;
}

const rows = () => [...document.body.querySelectorAll<HTMLElement>("[data-testid=image-row]")];
const removeButton = (row: number) => rows()[row]!.querySelector<HTMLElement>("[data-testid=image-remove]")!;
const addButton = () => document.body.querySelector<HTMLElement>("[data-testid=add-images]")!;

describe("ProductImages", () => {
  describe("after removing an image (#250 F8)", () => {
    it("puts the focus on the remove button of the image that took its place", async () => {
      mountSection();
      removeButton(1).focus();

      removeButton(1).click();
      await flushPromises();

      expect(rows()).toHaveLength(2);
      expect(document.activeElement).toBe(removeButton(1));
      expect(document.activeElement?.getAttribute("aria-label")).toBe("Usuń zdjęcie 2");
    });

    it("puts the focus on the add button when the last image was removed", async () => {
      mountSection();
      removeButton(2).focus();

      removeButton(2).click();
      await flushPromises();

      expect(document.activeElement).toBe(addButton());
    });

    it("puts the focus on the add button when the only image was removed", async () => {
      mountSection({ images: [image("a", "Przód")] });
      removeButton(0).focus();

      removeButton(0).click();
      await flushPromises();

      expect(document.activeElement).toBe(addButton());
    });
  });

  describe("after hiding the result of an upload (#250 F8)", () => {
    const dismissButtons = () => [...document.body.querySelectorAll<HTMLElement>("[data-testid=upload-dismiss]")];

    it("puts the focus on the next result", async () => {
      mountSection({ entries: [entry(1, "done"), entry(2, "done")] });
      dismissButtons()[0]!.focus();

      dismissButtons()[0]!.click();
      await flushPromises();

      expect(dismissButtons()).toHaveLength(1);
      expect(document.activeElement).toBe(dismissButtons()[0]);
    });

    it("puts the focus on the add button when no result is left", async () => {
      mountSection({ entries: [entry(1, "done")] });
      dismissButtons()[0]!.focus();

      dismissButtons()[0]!.click();
      await flushPromises();

      expect(document.activeElement).toBe(addButton());
      expect(document.activeElement).not.toBe(document.body);
    });
  });

  describe("the failure of an upload (#250 F14)", () => {
    it("is announced as an alert in the row of the file", () => {
      mountSection({ entries: [entry(1, "failed", "Plik jest za duży."), entry(2, "done")] });

      const alerts = [...document.body.querySelectorAll("[data-testid=upload-entry] [role=alert]")];
      expect(alerts.map((node) => node.textContent?.trim())).toEqual(["Plik jest za duży."]);
      expect(alerts[0]?.closest("[data-testid=upload-entry]")?.textContent).toContain("plik-1.jpg");
    });
  });

  it("shows an error of the whole list as an alert", () => {
    mountSection({ errors: ["Produkt może mieć najwyżej 10 zdjęć."] });

    const error = document.body.querySelector("[data-testid=images-error]");
    expect(error?.getAttribute("role")).toBe("alert");
    expect(error?.textContent).toContain("Produkt może mieć najwyżej 10 zdjęć.");
  });

  it("has a heading „Zdjęcia” that can take the focus", () => {
    mountSection();

    const heading = document.body.querySelector("h2");
    expect(heading?.textContent).toBe("Zdjęcia");
    expect(heading?.getAttribute("tabindex")).toBe("-1");
  });
});
