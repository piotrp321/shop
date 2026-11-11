import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import TreeSelect from "primevue/treeselect";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { RouterView, createMemoryHistory, createRouter } from "vue-router";
import type { components } from "@/api/schema";
import { primeVueOptions } from "@/primevue";
import { type StaffMe, useAuthStore } from "@/stores/auth";
import ProductFormView from "@/views/ProductFormView.vue";

type ProductEditView = components["schemas"]["ProductEditViewResponse"];
type CategoryNode = components["schemas"]["CategoryNode"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const PRODUCT = "/api/v1/admin/products/{id}";
const PRODUCTS = "/api/v1/admin/products";
const UPLOAD = "/api/v1/admin/product-images";
const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";

const tree: CategoryNode[] = [
  { id: "c-main", name: "Obuwie", slug: "obuwie", path: "obuwie", children: [] },
  { id: "c-extra", name: "Sport", slug: "sport", path: "sport", children: [] },
];
const preview = { alt: "Zdjęcie", size: "thumbnail", aspectRatio: "1:1", variants: { webp: [{ width: 96, url: "https://img.test/a.webp" }] } };

function image(key: string, alt: string) {
  return { key, alt, preview };
}

function view(overrides: Partial<ProductEditView> = {}): ProductEditView {
  return {
    id: "p-1",
    version: 3,
    status: "draft",
    firstPublishedAt: null,
    name: "Buty biegowe",
    slug: "buty-biegowe",
    slugLocked: false,
    shortDescription: "Lekkie buty.",
    mainCategoryId: "c-main",
    additionalCategoryIds: ["c-extra"],
    taxClass: "standard",
    images: [image("originals/a.jpg", "Przód"), image("originals/b.jpg", "Bok")],
    variantAxes: ["Kolor"],
    variants: [
      { variantId: "v-1", values: ["czarne"] },
      { variantId: "v-2", values: ["białe"] },
    ],
    updatedAt: "2026-11-01T12:30:00+00:00",
    ...overrides,
  };
}

function serve(product: ProductEditView | number = view()) {
  mocks.get.mockImplementation(async (path: string) => {
    if (path === "/api/v1/admin/categories") {
      return { data: tree, response: { status: 200 } };
    }
    if (path === "/api/v1/admin/tax-classes") {
      return { data: [{ code: "standard", name: "Standardowa" }], response: { status: 200 } };
    }
    return typeof product === "number" ? { data: undefined, response: { status: product } } : { data: product, response: { status: 200 } };
  });
}

function problem(status: number, body: Record<string, unknown> = {}) {
  return { data: undefined, error: body, response: { status } };
}

async function mountForm(path: string, roles: StaffMe["roles"] = ["admin"]) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().staff = { id: "u1", username: "anna", roles };
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/products", name: "products", component: empty },
      { path: "/products/new", name: "product-new", component: ProductFormView },
      { path: "/products/:id", name: "product", component: ProductFormView },
    ],
  });
  await router.push(path);
  const wrapper = mount({ components: { RouterView }, template: "<RouterView />" }, { attachTo: document.body, global: { plugins: [pinia, router, [PrimeVue, primeVueOptions]] as never[], stubs: { transition: false } } });
  await flushPromises();
  return { wrapper, router };
}

function files(wrapper: Awaited<ReturnType<typeof mountForm>>["wrapper"], chosen: File[]) {
  const input = wrapper.get("[data-testid=image-input]");
  Object.defineProperty(input.element, "files", { value: chosen, configurable: true });
  return input.trigger("change");
}

describe("ProductFormView", () => {
  let mounted: Awaited<ReturnType<typeof mountForm>> | undefined;
  let scrollIntoView: Mock<Element["scrollIntoView"]>;

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    mocks.put.mockReset();
    // jsdom has no matchMedia, which the Select of PrimeVue asks for when it is mounted.
    vi.stubGlobal("matchMedia", () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
    // jsdom does not scroll and has no scrollIntoView either.
    scrollIntoView = vi.fn<Element["scrollIntoView"]>();
    Element.prototype.scrollIntoView = scrollIntoView;
    serve();
  });

  afterEach(() => {
    mounted?.wrapper.unmount();
    mounted = undefined;
    delete (Element.prototype as Partial<Element>).scrollIntoView;
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  describe("the data section", () => {
    it("fills the fields from the product and offers the tax classes of the API", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      expect((wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Buty biegowe");
      expect((wrapper.get("[data-testid=slug]").element as HTMLInputElement).value).toBe("buty-biegowe");
      expect((wrapper.get("[data-testid=short-description]").element as HTMLTextAreaElement).value).toBe("Lekkie buty.");
      expect(wrapper.get("[data-testid=tax-class]").text()).toContain("Standardowa");
      expect(wrapper.get("[data-testid=product-status]").text()).toBe("Szkic");
    });

    it("hints that an empty slug is derived from the name", async () => {
      mounted = await mountForm("/products/new");

      expect(mounted.wrapper.get("[data-testid=slug-hint]").text()).toBe("pusty: wyliczony z nazwy");
    });

    it("disables the slug of a product that was published and says why", async () => {
      serve(view({ slugLocked: true, status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.get("[data-testid=slug]").attributes("disabled")).toBeDefined();
      expect(mounted.wrapper.get("[data-testid=slug-locked-note]").text()).toContain("publikacji");
    });

    it("counts the characters of the short description", async () => {
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.get("[data-testid=short-description-counter]").text()).toBe("12/500");
      await mounted.wrapper.get("[data-testid=short-description]").setValue("Ąę😀");
      expect(mounted.wrapper.get("[data-testid=short-description-counter]").text()).toBe("3/500");
    });

    it("sends the categories chosen from the tree", async () => {
      mounted = await mountForm("/products/p-1");
      const trees = mounted.wrapper.findAllComponents(TreeSelect);
      mocks.put.mockResolvedValue({ data: view({ version: 4 }), response: { status: 200 } });

      trees[0]!.vm.$emit("update:modelValue", { "c-extra": true });
      trees[1]!.vm.$emit("update:modelValue", { "c-main": true, "c-extra": true });
      await flushPromises();
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mocks.put.mock.calls[0]![1].body).toMatchObject({ mainCategoryId: "c-extra", additionalCategoryIds: ["c-main", "c-extra"] });
    });

    it("shows an error at the field the API names", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Nazwa jest wymagana." }] }));

      await mounted.wrapper.get("[data-testid=name]").setValue("");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=error-name]").text()).toBe("Nazwa jest wymagana.");
    });

    it("shows slug-taken at the slug field", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}slug-taken`, detail: "x" }));

      await mounted.wrapper.get("[data-testid=slug]").setValue("zajety");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=error-slug]").text()).toBe("Ten slug ma już inny produkt. Podaj własny slug.");
    });
  });

  describe("actions", () => {
    it("saves an edited product with its version and shows the message", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mocks.put).toHaveBeenCalledWith(PRODUCT, expect.objectContaining({ params: { path: { id: "p-1" } } }));
      expect(mocks.put.mock.calls[0]![1].body.version).toBe(3);
      expect(mounted.wrapper.get("[data-testid=success-message]").text()).toBe("Produkt zapisany.");
    });

    it("creates a new product with the default variant and moves to its page", async () => {
      mounted = await mountForm("/products/new");
      expect(mounted.wrapper.get("h1").text()).toBe("Nowy produkt");
      mocks.post.mockResolvedValue({ data: view({ id: "p-9", variantAxes: [], variants: [{ variantId: "v-9", values: [] }] }), response: { status: 201 } });

      await mounted.wrapper.get("[data-testid=name]").setValue("Buty biegowe");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith(PRODUCTS, expect.objectContaining({ body: expect.objectContaining({ name: "Buty biegowe", variants: [{ variantId: null, values: [] }] }) }));
      expect(mounted.router.currentRoute.value.name).toBe("product");
      expect(mounted.router.currentRoute.value.params.id).toBe("p-9");
      expect(mounted.wrapper.get("[data-testid=success-message]").text()).toBe("Produkt zapisany.");
      expect(mounted.wrapper.get("h1").text()).toBe("Buty biegowe");
    });

    it("offers publication for a draft, only after the changes are saved, and withdrawal for a published product", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      expect(wrapper.get("[data-testid=publish]").attributes("disabled")).toBeUndefined();
      expect(wrapper.find("[data-testid=unpublish]").exists()).toBe(false);
      await wrapper.get("[data-testid=name]").setValue("Inne");
      expect(wrapper.get("[data-testid=publish]").attributes("disabled")).toBeDefined();

      serve(view({ status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      wrapper.unmount();
      mounted = await mountForm("/products/p-1");
      expect(mounted.wrapper.find("[data-testid=publish]").exists()).toBe(false);
      expect(mounted.wrapper.get("[data-testid=unpublish]").text()).toBe("Wycofaj z publikacji");
      expect(mounted.wrapper.get("[data-testid=product-status]").text()).toBe("Opublikowany");
    });

    it("publishes with the version and shows the status of the API", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.post.mockResolvedValue({ data: view({ version: 4, status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=publish]").trigger("click");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith("/api/v1/admin/products/{id}/publish", { params: { path: { id: "p-1" } }, body: { version: 3 } });
      expect(mounted.wrapper.get("[data-testid=product-status]").text()).toBe("Opublikowany");
      expect(mounted.wrapper.get("[data-testid=success-message]").text()).toBe("Produkt opublikowany.");
    });

    it("asks to refresh after a conflict and discards the changes when asked", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}product-modified`, detail: "x" }));

      await mounted.wrapper.get("[data-testid=name]").setValue("Moje");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();
      expect(mounted.wrapper.get("[data-testid=form-error]").text()).toContain("Produkt został w międzyczasie zmieniony – odśwież");

      serve(view({ version: 7, name: "Cudza zmiana" }));
      await mounted.wrapper.get("[data-testid=refresh]").trigger("click");
      await flushPromises();

      expect((mounted.wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Cudza zmiana");
      expect(mounted.wrapper.find("[data-testid=form-error]").exists()).toBe(false);
    });

    it("shows other failures as a message and keeps the data", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(403));

      await mounted.wrapper.get("[data-testid=name]").setValue("Moje");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=form-error]").text()).toContain("uprawnień");
      expect(mounted.wrapper.find("[data-testid=refresh]").exists()).toBe(false);
      expect((mounted.wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Moje");
    });

    it("disables the actions while the request runs", async () => {
      mounted = await mountForm("/products/p-1");
      let finish!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (finish = resolve)));

      await mounted.wrapper.get("[data-testid=name]").setValue("Moje");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=save]").attributes("disabled")).toBeDefined();
      expect(mounted.wrapper.get("[data-testid=publish]").attributes("disabled")).toBeDefined();
      finish({ data: view({ version: 4 }), response: { status: 200 } });
      await flushPromises();
      expect(mounted.wrapper.get("[data-testid=save]").attributes("disabled")).toBeUndefined();
    });
  });

  describe("showing the result of an action", () => {
    async function edited(path: string, name = "Inne") {
      mounted = await mountForm(path);
      await mounted.wrapper.get("[data-testid=name]").setValue(name);
      scrollIntoView.mockClear();
    }

    async function click(testId: string) {
      await mounted!.wrapper.get(`[data-testid=${testId}]`).trigger("click");
      await flushPromises();
    }

    function expectRevealed(element: Element) {
      expect(document.activeElement).toBe(element);
      expect(scrollIntoView).toHaveBeenCalled();
      expect(scrollIntoView.mock.contexts.at(-1)).toBe(element);
    }

    function revealed(testId: string) {
      expectRevealed(mounted!.wrapper.get(`[data-testid=${testId}]`).element);
    }

    it("moves to the field the API names and focuses it", async () => {
      await edited("/products/p-1", "");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Podaj nazwę produktu." }] }));

      await click("save");

      revealed("name");
    });

    it("moves to the first of several errors in the order of the document", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue(
        problem(422, {
          violations: [
            { propertyPath: "taxClass", title: "Wybierz klasę." },
            { propertyPath: "slug", title: "Zły slug." },
          ],
        }),
      );

      await click("save");

      revealed("slug");
    });

    it("moves to the message of a field that cannot be focused", async () => {
      serve(view({ slugLocked: true, status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      await edited("/products/p-1");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}slug-locked`, detail: "Slug jest zablokowany." }));

      await click("save");

      revealed("error-slug");
    });

    it("moves to the message of the form when there is no field to show", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}product-modified`, detail: "x" }));

      await click("save");

      revealed("form-error");
    });

    it("moves to the message of the form after a failure without a connection", async () => {
      await edited("/products/p-1");
      mocks.put.mockRejectedValue(new Error("offline"));

      await click("save");

      revealed("form-error");
    });

    it("moves to the error of the whole list of images", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "images", title: "Produkt może mieć najwyżej 10 zdjęć." }] }));

      await click("save");

      revealed("images-error");
    });

    it("moves to the conflict about variants", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}variant-in-use`, detail: "Wariant ma stan magazynowy." }));

      await click("save");

      revealed("variants-error");
    });

    it("moves to the field of a value of a variant", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "variants[1].values[0]", title: "Taka kombinacja już jest." }] }));

      await click("save");

      expectRevealed(mounted!.wrapper.findAll("[data-testid=variant-row]")[1]!.get("input").element);
    });

    it("moves to the message of a failed publication", async () => {
      await edited("/products/p-1", "Buty biegowe");
      mocks.post.mockResolvedValue(problem(409, { type: `${PREFIX}product-not-publishable`, detail: "x" }));

      await click("publish");

      revealed("form-error");
    });

    it("moves to the message of a failed withdrawal", async () => {
      serve(view({ status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      await edited("/products/p-1", "Buty biegowe");
      mocks.post.mockResolvedValue(problem(503));

      await click("unpublish");

      revealed("form-error");
    });

    it("moves to the confirmation after a save", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });

      await click("save");

      revealed("success-message");
    });

    it("moves to the confirmation after a publication", async () => {
      await edited("/products/p-1", "Buty biegowe");
      mocks.post.mockResolvedValue({ data: view({ version: 4, status: "published" }), response: { status: 200 } });

      await click("publish");

      revealed("success-message");
    });

    it("moves to the confirmation of a product that was just created, after the route moved to its page", async () => {
      await edited("/products/new", "Buty biegowe");
      mocks.post.mockResolvedValue({ data: view({ id: "p-9", variantAxes: [], variants: [{ variantId: "v-9", values: [] }] }), response: { status: 201 } });

      await click("save");

      expect(mounted!.router.currentRoute.value.name).toBe("product");
      revealed("success-message");
    });
  });

  describe("without the permission to manage products", () => {
    it.each([["support"], ["warehouse"]] as const)("shows %s a read-only form without actions", async (role) => {
      mounted = await mountForm("/products/p-1", [role]);
      const { wrapper } = mounted;

      expect((wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Buty biegowe");
      for (const field of ["name", "slug", "short-description", "image-alt"]) {
        expect(wrapper.get(`[data-testid=${field}]`).attributes("disabled")).toBeDefined();
      }
      for (const control of ["save", "publish", "unpublish", "add-images", "image-input", "image-up", "image-down", "image-remove"]) {
        expect(wrapper.find(`[data-testid=${control}]`).exists()).toBe(false);
      }
      expect(wrapper.find("[data-testid=read-only-note]").exists()).toBe(true);
    });
  });

  describe("leaving with unsaved changes", () => {
    it("asks for confirmation and stays when it is refused", async () => {
      mounted = await mountForm("/products/p-1");
      const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.router.push("/products");
      await flushPromises();

      expect(confirm).toHaveBeenCalledTimes(1);
      expect(mounted.router.currentRoute.value.name).toBe("product");
    });

    it("leaves when it is confirmed", async () => {
      mounted = await mountForm("/products/p-1");
      vi.spyOn(window, "confirm").mockReturnValue(true);

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.router.push("/products");
      await flushPromises();

      expect(mounted.router.currentRoute.value.name).toBe("products");
    });

    it("leaves without asking when nothing changed", async () => {
      mounted = await mountForm("/products/p-1");
      const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);

      await mounted.router.push("/products");
      await flushPromises();

      expect(confirm).not.toHaveBeenCalled();
      expect(mounted.router.currentRoute.value.name).toBe("products");
    });

    it("warns the browser when the tab is closed with unsaved changes only", async () => {
      mounted = await mountForm("/products/p-1");

      const clean = new Event("beforeunload", { cancelable: true });
      window.dispatchEvent(clean);
      expect(clean.defaultPrevented).toBe(false);

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      const dirty = new Event("beforeunload", { cancelable: true });
      window.dispatchEvent(dirty);
      expect(dirty.defaultPrevented).toBe(true);
    });
  });

  describe("signing out", () => {
    it("does not hold back a sign-out with the warning about unsaved changes", async () => {
      mounted = await mountForm("/products/p-1");
      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      useAuthStore().signingOut = true;

      const event = new Event("beforeunload", { cancelable: true });
      window.dispatchEvent(event);

      expect(event.defaultPrevented).toBe(false);
    });
  });

  describe("after the view is left", () => {
    it("no longer asks for confirmation on navigation and no longer warns when the tab is closed", async () => {
      mounted = await mountForm("/products/p-1");
      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      mounted.wrapper.unmount();
      const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);

      await mounted.router.push("/products");
      await flushPromises();
      const event = new Event("beforeunload", { cancelable: true });
      window.dispatchEvent(event);

      expect(confirm).not.toHaveBeenCalled();
      expect(mounted.router.currentRoute.value.name).toBe("products");
      expect(event.defaultPrevented).toBe(false);
    });
  });

  describe("load failures", () => {
    it("says that the product does not exist", async () => {
      serve(404);
      mounted = await mountForm("/products/gone");

      expect(mounted.wrapper.get("[data-testid=load-error]").text()).toContain("nie istnieje");
      expect(mounted.wrapper.find("[data-testid=name]").exists()).toBe(false);
      expect(mounted.wrapper.get("h1").text()).toBe("Produkt");
    });

    it("keeps the heading of an existing product when it fails to load", async () => {
      serve(500);
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.find("[data-testid=load-error]").exists()).toBe(true);
      expect(mounted.wrapper.get("h1").text()).toBe("Produkt");
    });

    it("keeps the heading of an existing product until it is loaded", async () => {
      mocks.get.mockReturnValue(new Promise(() => {}));
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.get("h1").text()).toBe("Produkt");
    });
  });

  describe("images", () => {
    it("lists the images with the first one marked as the main one and editable alt", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      const rows = wrapper.findAll("[data-testid=image-row]");
      expect(rows).toHaveLength(2);
      expect(rows[0]!.find("[data-testid=image-main]").exists()).toBe(true);
      expect(rows[1]!.find("[data-testid=image-main]").exists()).toBe(false);
      expect(rows[0]!.get("img").attributes("src")).toBe("https://img.test/a.webp");
      expect((rows[1]!.get("[data-testid=image-alt]").element as HTMLInputElement).value).toBe("Bok");
    });

    it("moves an image down, removes one and sends the order", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;
      mocks.put.mockResolvedValue({ data: view({ version: 4 }), response: { status: 200 } });

      await wrapper.findAll("[data-testid=image-down]")[0]!.trigger("click");
      expect(wrapper.findAll("[data-testid=image-alt]").map((input) => (input.element as HTMLInputElement).value)).toEqual(["Bok", "Przód"]);
      await wrapper.findAll("[data-testid=image-remove]")[1]!.trigger("click");
      await wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mocks.put.mock.calls[0]![1].body.images).toEqual([{ key: "originals/b.jpg", alt: "Bok" }]);
    });

    it("cannot move the first image up or the last one down", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      expect(wrapper.findAll("[data-testid=image-up]")[0]!.attributes("disabled")).toBeDefined();
      expect(wrapper.findAll("[data-testid=image-down]")[1]!.attributes("disabled")).toBeDefined();
    });

    it("shows the errors of an image next to it", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "images[1].alt", title: "Opis zdjęcia jest wymagany." }] }));

      await mounted.wrapper.findAll("[data-testid=image-alt]")[1]!.setValue("");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.findAll("[data-testid=image-row]")[1]!.get("[data-testid=image-error]").text()).toBe("Opis zdjęcia jest wymagany.");
      expect(mounted.wrapper.findAll("[data-testid=image-row]")[0]!.find("[data-testid=image-error]").exists()).toBe(false);
    });

    it("shows an error at the list of images in the section", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "images", title: "Produkt może mieć najwyżej 10 zdjęć." }] }));

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=images-error]").text()).toBe("Produkt może mieć najwyżej 10 zdjęć.");
    });

    it("uploads the chosen files one by one, shows a status per file and offers a retry for the failed one", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;
      mocks.post
        .mockResolvedValueOnce({ data: { key: "originals/c.jpg", preview: { size: "thumbnail", aspectRatio: "1:1", variants: {} } }, response: { status: 201 } })
        .mockResolvedValueOnce(problem(422, { detail: "Plik jest za duży." }));

      await files(wrapper, [new File(["x"], "c.jpg"), new File(["y"], "d.jpg")]);
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith(UPLOAD, expect.anything());
      const entries = wrapper.findAll("[data-testid=upload-entry]");
      expect(entries.map((entry) => entry.text())).toEqual([expect.stringContaining("Gotowe"), expect.stringContaining("Plik jest za duży.")]);
      expect(wrapper.findAll("[data-testid=image-row]")).toHaveLength(3);

      mocks.post.mockResolvedValueOnce({ data: { key: "originals/d.jpg", preview: { size: "thumbnail", aspectRatio: "1:1", variants: {} } }, response: { status: 201 } });
      await wrapper.get("[data-testid=upload-retry]").trigger("click");
      await flushPromises();
      expect(wrapper.findAll("[data-testid=image-row]")).toHaveLength(4);
    });

    it("disables the add button at ten images", async () => {
      serve(view({ images: Array.from({ length: 10 }, (_, index) => image(`originals/${index}.jpg`, `Zdjęcie ${index}`)) }));
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.get("[data-testid=add-images]").attributes("disabled")).toBeDefined();
    });
  });

  describe("variants", () => {
    const values = (wrapper: Awaited<ReturnType<typeof mountForm>>["wrapper"]) =>
      wrapper.findAll("[data-testid=variant-value]").map((input) => (input.element as HTMLInputElement).value);

    it("shows the axes and the values of the product as fields", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      expect(wrapper.findAll("[data-testid=axis-name]").map((input) => (input.element as HTMLInputElement).value)).toEqual(["Kolor"]);
      expect(values(wrapper)).toEqual(["czarne", "białe"]);
      expect(wrapper.find("[data-testid=variants-locked]").exists()).toBe(false);
    });

    it("sends the edited axes and rows, with the kept ids and null for the new row", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;
      mocks.put.mockResolvedValue({ data: view({ version: 4 }), response: { status: 200 } });

      await wrapper.get("[data-testid=axis-name]").setValue("Barwa");
      await wrapper.findAll("[data-testid=variant-value]")[0]!.setValue("Czarne");
      await wrapper.get("[data-testid=add-variant]").trigger("click");
      await wrapper.findAll("[data-testid=variant-value]")[2]!.setValue("zielone");
      expect(wrapper.get("[data-testid=save]").attributes("disabled")).toBeUndefined();
      await wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mocks.put.mock.calls[0]![1].body).toMatchObject({
        variantAxes: ["Barwa"],
        variants: [
          { variantId: "v-1", values: ["Czarne"] },
          { variantId: "v-2", values: ["białe"] },
          { variantId: null, values: ["zielone"] },
        ],
      });
    });

    it("removes an existing variant only after the confirmation", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      await wrapper.findAll("[data-testid=variant-remove]")[0]!.trigger("click");
      // The dialog is teleported to <body>.
      expect(document.body.textContent).toContain("Cena i stan wariantu przestaną być widoczne w panelu.");
      expect(values(wrapper)).toEqual(["czarne", "białe"]);
      document.querySelector<HTMLElement>("[data-testid=remove-confirm-accept]")!.click();
      await flushPromises();

      expect(values(wrapper)).toEqual(["białe"]);
    });

    it("locks the axes with an explanation after the first publication", async () => {
      serve(view({ status: "published", firstPublishedAt: "2026-10-01T10:00:00+00:00" }));
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      expect(wrapper.get("[data-testid=add-axis]").attributes("disabled")).toBeDefined();
      expect(wrapper.get("[data-testid=axis-remove]").attributes("disabled")).toBeDefined();
      expect(wrapper.get("[data-testid=variants-locked]").text()).toContain("Wartości istniejących wariantów można poprawić tylko w wielkości liter.");
      expect(wrapper.get("[data-testid=axis-name]").attributes("disabled")).toBeUndefined();
    });

    it("shows a 422 at the value of the row it points at", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "variants[1].values[0]", title: "Wartość może mieć najwyżej 40 znaków." }] }));

      await wrapper.findAll("[data-testid=variant-value]")[1]!.setValue("czarne");
      await wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      const rows = wrapper.findAll("[data-testid=variant-row]");
      expect(rows[0]!.find("[data-testid=value-error]").exists()).toBe(false);
      expect(rows[1]!.get("[data-testid=value-error]").text()).toBe("Wartość może mieć najwyżej 40 znaków.");
      expect(wrapper.find("[data-testid=variants-error]").exists()).toBe(false);
    });

    it("shows no way to edit the variants to staff without the permission", async () => {
      mounted = await mountForm("/products/p-1", ["support"]);
      const { wrapper } = mounted;

      expect(wrapper.find("[data-testid=add-axis]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=add-variant]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=variant-remove]").exists()).toBe(false);
      expect(wrapper.get("[data-testid=variant-value]").attributes("disabled")).toBeDefined();
    });

    it("shows the default variant of a product without axes", async () => {
      mounted = await mountForm("/products/new");

      const rows = mounted.wrapper.findAll("[data-testid=variant-row]");
      expect(rows).toHaveLength(1);
      expect(rows[0]!.text()).toBe("Wariant domyślny");
      expect(rows[0]!.find("input").exists()).toBe(false);
    });

    it("shows the conflict about variants at the section", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}variant-in-use`, detail: "Wariant ma stan magazynowy albo rezerwacje." }));

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=variants-error]").text()).toBe("Wariant ma stan magazynowy albo rezerwacje.");
    });
  });
});
