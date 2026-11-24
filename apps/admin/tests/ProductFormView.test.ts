import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { RouterView, createMemoryHistory, createRouter } from "vue-router";
import { toast } from "vue-sonner";
import type { components } from "@/api/schema";
import { Toaster } from "@/components/ui/sonner";
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
  { id: "c-main", name: "Obuwie", slug: "obuwie", path: "obuwie", version: 1, productCount: null, children: [] },
  { id: "c-extra", name: "Sport", slug: "sport", path: "sport", version: 1, productCount: null, children: [] },
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
    if (path === "/api/v1/admin/prices" || path === "/api/v1/admin/stock") {
      return { data: { items: [], next: null, total: { value: 0, capped: false } }, response: { status: 200 } };
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
      { path: "/", name: "dashboard", component: empty },
      { path: "/products", name: "products", component: empty },
      { path: "/products/new", name: "product-new", component: ProductFormView },
      { path: "/products/:id", name: "product", component: ProductFormView },
    ],
  });
  await router.push(path);
  const wrapper = mount({ components: { RouterView, Toaster }, template: "<RouterView /><Toaster />" }, { attachTo: document.body, global: { plugins: [pinia, router], stubs: { transition: false } } });
  await flushPromises();
  return { wrapper, router };
}

function files(wrapper: Awaited<ReturnType<typeof mountForm>>["wrapper"], chosen: File[]) {
  const input = wrapper.get("[data-testid=image-input]");
  Object.defineProperty(input.element, "files", { value: chosen, configurable: true });
  return input.trigger("change");
}

const field = (label: string) => [...document.body.querySelectorAll<HTMLInputElement>("[role=combobox]")].find((candidate) => candidate.labels?.[0]?.textContent?.trim() === label);
const dialog = () => document.body.querySelector<HTMLElement>("[role=alertdialog]");
const dialogButton = (name: string) => [...(dialog()?.querySelectorAll("button") ?? [])].find((candidate) => candidate.textContent?.trim() === name);
const pressEscape = () => (document.activeElement ?? document.body).dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
const heading = (level: "h1" | "h2", text?: string) => [...document.body.querySelectorAll(level)].find((candidate) => text === undefined || candidate.textContent?.trim() === text);
const barButton = (name: string) => [...document.body.querySelectorAll("section[aria-label='Zapis formularza'] button")].find((candidate) => candidate.textContent?.trim() === name) as HTMLButtonElement | undefined;
const saveBar = () => document.body.querySelector("section[aria-label='Zapis formularza']");

async function chooseCategory(label: string, category: string) {
  const input = field(label)!;
  input.dispatchEvent(new MouseEvent("click", { bubbles: true }));
  await flushPromises();
  document.body.querySelector<HTMLElement>(`[role=option][aria-label="${category}"]`)!.click();
  await flushPromises();
}

describe("ProductFormView", () => {
  let mounted: Awaited<ReturnType<typeof mountForm>> | undefined;
  let scrollIntoView: Mock<Element["scrollIntoView"]>;
  let successToast: Mock<typeof toast.success>;

  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    mocks.put.mockReset();
    // jsdom does not scroll and has no scrollIntoView either.
    scrollIntoView = vi.fn<Element["scrollIntoView"]>();
    Element.prototype.scrollIntoView = scrollIntoView;
    // The toast store is global; the tests read what was shown from the calls, one test reads the live region itself.
    successToast = vi.spyOn(toast, "success").mockImplementation(() => 1);
    serve();
  });

  afterEach(() => {
    mounted?.wrapper.unmount();
    mounted = undefined;
    document.body.innerHTML = "";
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
      mocks.put.mockResolvedValue({ data: view({ version: 4 }), response: { status: 200 } });

      await chooseCategory("Kategoria główna", "Sport");
      await chooseCategory("Kategorie dodatkowe", "Obuwie");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      const body = mocks.put.mock.calls[0]![1].body;
      expect(body.mainCategoryId).toBe("c-extra");
      expect([...body.additionalCategoryIds].sort()).toEqual(["c-extra", "c-main"]);
    });

    it("#250 F13: names the tax class and the categories, and leaves no button without a name", async () => {
      mounted = await mountForm("/products/p-1");

      expect(document.getElementById("product-tax-class")?.getAttribute("role")).toBe("combobox");
      expect((document.getElementById("product-tax-class") as HTMLButtonElement).labels?.[0]?.textContent?.trim()).toBe("Klasa podatkowa");
      expect(field("Kategoria główna")).toBeDefined();
      expect(field("Kategorie dodatkowe")).toBeDefined();
      const nameless = [...mounted.wrapper.element.querySelectorAll("button")].filter(
        (button) => (button.getAttribute("aria-label") ?? button.textContent ?? "").trim() === "" && !button.hasAttribute("aria-labelledby") && !(button as HTMLButtonElement).labels?.length,
      );
      expect(nameless).toEqual([]);
    });

    it("marks the category with the error invalid and points it at the message", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "mainCategoryId", title: "Wybierz kategorię." }] }));

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      const error = mounted.wrapper.get("[data-testid=error-mainCategoryId]");
      expect(field("Kategoria główna")?.getAttribute("aria-invalid")).toBe("true");
      expect(field("Kategoria główna")?.getAttribute("aria-describedby")).toContain(error.attributes("id"));
    });

    it("marks the field with the error invalid and points it at the message", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Nazwa jest wymagana." }] }));

      await mounted.wrapper.get("[data-testid=name]").setValue("");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      const name = mounted.wrapper.get("[data-testid=name]");
      expect(name.attributes("aria-invalid")).toBe("true");
      expect(name.attributes("aria-describedby")).toBe(mounted.wrapper.get("[data-testid=error-name]").attributes("id"));
      expect(mounted.wrapper.get("[data-testid=slug]").attributes("aria-invalid")).toBeUndefined();
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
    it("saves an edited product with its version and says so in a toast", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mocks.put).toHaveBeenCalledWith(PRODUCT, expect.objectContaining({ params: { path: { id: "p-1" } } }));
      expect(mocks.put.mock.calls[0]![1].body.version).toBe(3);
      expect(successToast).toHaveBeenCalledWith("Produkt zapisany.");
      expect(mounted.wrapper.find("[data-testid=success-message]").exists()).toBe(false);
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
      expect(successToast).toHaveBeenCalledWith("Produkt zapisany. Ustaw cenę, inaczej produkt będzie w sklepie niedostępny.");
      expect(mounted.wrapper.get("h1").text()).toBe("Buty biegowe");
    });

    it("offers publication for a draft, only after the changes are saved, and withdrawal for a published product", async () => {
      mounted = await mountForm("/products/p-1");
      const { wrapper } = mounted;

      expect(wrapper.get("[data-testid=publish]").attributes("disabled")).toBeUndefined();
      expect(wrapper.find("[data-testid=publish-reason]").exists()).toBe(false);
      expect(wrapper.find("[data-testid=unpublish]").exists()).toBe(false);
      await wrapper.get("[data-testid=name]").setValue("Inne");
      expect(wrapper.get("[data-testid=publish]").attributes("disabled")).toBeDefined();
      expect(wrapper.get("[data-testid=publish-reason]").text()).toBe("Najpierw zapisz zmiany.");
      expect(wrapper.get("[data-testid=publish]").attributes("aria-describedby")).toBe(wrapper.get("[data-testid=publish-reason]").attributes("id"));

      serve(view({ status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      wrapper.unmount();
      mounted = await mountForm("/products/p-1");
      expect(mounted.wrapper.find("[data-testid=publish]").exists()).toBe(false);
      expect(mounted.wrapper.get("[data-testid=unpublish]").text()).toBe("Wycofaj z publikacji");
      expect(mounted.wrapper.get("[data-testid=product-status]").text()).toBe("Opublikowany");
    });

    it("publishes with the version, shows the status of the API and says so in a toast", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.post.mockResolvedValue({ data: view({ version: 4, status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=publish]").trigger("click");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith("/api/v1/admin/products/{id}/publish", { params: { path: { id: "p-1" } }, body: { version: 3 } });
      expect(mounted.wrapper.get("[data-testid=product-status]").text()).toBe("Opublikowany");
      expect(successToast).toHaveBeenCalledWith("Produkt opublikowany.");
    });

    it("withdraws a published product and says so in a toast", async () => {
      serve(view({ status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      mounted = await mountForm("/products/p-1");
      mocks.post.mockResolvedValue({ data: view({ version: 4, status: "draft", firstPublishedAt: "2026-11-02T10:00:00+00:00" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=unpublish]").trigger("click");
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith("/api/v1/admin/products/{id}/unpublish", { params: { path: { id: "p-1" } }, body: { version: 3 } });
      expect(mounted.wrapper.get("[data-testid=product-status]").text()).toBe("Szkic");
      expect(successToast).toHaveBeenCalledWith("Produkt wycofany z publikacji.");
    });

    it("keeps the draft after a conflict of the version, reads nothing, and shows the server state only after „Odrzuć moje zmiany i pokaż stan z serwera”", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}product-modified`, detail: "x" }));

      await mounted.wrapper.get("[data-testid=name]").setValue("Moje");
      mocks.get.mockClear();
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=form-error]").text()).toContain("Produkt został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.");
      expect((mounted.wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Moje");
      expect(mounted.wrapper.find("[data-testid=refresh]").exists()).toBe(false);
      expect(mocks.get).not.toHaveBeenCalledWith(PRODUCT, expect.anything());

      serve(view({ version: 7, name: "Cudza zmiana" }));
      expect(mounted.wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
      await mounted.wrapper.get("[data-testid=restore-state]").trigger("click");
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
      expect(mounted.wrapper.get("[data-testid=form-error]").attributes("role")).toBe("alert");
      expect(mounted.wrapper.find("[data-testid=refresh]").exists()).toBe(false);
      expect((mounted.wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Moje");
      expect(successToast).not.toHaveBeenCalled();
    });

    it("disables the actions while the request runs", async () => {
      mounted = await mountForm("/products/p-1");
      let finish!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (finish = resolve)));

      await mounted.wrapper.get("[data-testid=name]").setValue("Moje");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(mounted.wrapper.get("[data-testid=save]").attributes("aria-disabled")).toBe("true");
      expect(mounted.wrapper.get("[data-testid=publish]").attributes("disabled")).toBeDefined();
      finish({ data: view({ version: 4 }), response: { status: 200 } });
      await flushPromises();
      expect(mounted.wrapper.find("[data-testid=save]").exists()).toBe(false);
    });

    it("shows the toast in a polite live region (criterion 15)", async () => {
      successToast.mockRestore();
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      const region = document.body.querySelector("section[aria-live=polite]");
      expect(region?.textContent).toContain("Produkt zapisany.");
      toast.dismiss();
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

    it("moves to the message that the result is unknown after a failure without a connection", async () => {
      await edited("/products/p-1");
      mocks.put.mockRejectedValue(new Error("offline"));

      await click("save");

      expect(mounted!.wrapper.find("[data-testid=form-error]").exists()).toBe(false);
      revealed("unknown-result");
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

    it("moves to the category with the error", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "mainCategoryId", title: "Wybierz kategorię." }] }));

      await click("save");

      expectRevealed(field("Kategoria główna")!);
    });

    it("moves to the message of a failed publication", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.post.mockResolvedValue(problem(409, { type: `${PREFIX}product-not-publishable`, detail: "x" }));

      await click("publish");

      revealed("form-error");
    });

    it("moves to the message of a failed withdrawal", async () => {
      serve(view({ status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      mounted = await mountForm("/products/p-1");
      mocks.post.mockResolvedValue(problem(403));

      await click("unpublish");

      revealed("form-error");
    });

    it("moves to the heading of the page after a save, because the save bar went away with its button", async () => {
      await edited("/products/p-1");
      mocks.put.mockResolvedValue({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });

      await click("save");

      expect(saveBar()).toBeNull();
      expect(document.activeElement).toBe(heading("h1"));
      expect(document.activeElement?.textContent?.trim()).toBe("Inne");
    });

    it("moves to the heading of the card „Status” after a publication, because the button was replaced", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.post.mockResolvedValue({ data: view({ version: 4, status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }), response: { status: 200 } });

      await click("publish");

      expect(document.activeElement).toBe(heading("h2", "Status"));
      expect(document.activeElement).not.toBe(document.body);
    });

    it("moves to the heading of the card „Status” after a withdrawal", async () => {
      serve(view({ status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      mounted = await mountForm("/products/p-1");
      mocks.post.mockResolvedValue({ data: view({ version: 4, status: "draft", firstPublishedAt: "2026-11-02T10:00:00+00:00" }), response: { status: 200 } });

      await click("unpublish");

      expect(document.activeElement).toBe(heading("h2", "Status"));
    });

    it("moves to the heading of the page after a product was created, once the route moved to its page", async () => {
      await edited("/products/new", "Buty biegowe");
      mocks.post.mockResolvedValue({ data: view({ id: "p-9", variantAxes: [], variants: [{ variantId: "v-9", values: [] }] }), response: { status: 201 } });

      await click("save");

      expect(mounted!.router.currentRoute.value.name).toBe("product");
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("#250 F16 does not apply to a save: the fields are off while it runs, so the focus goes to the heading of the page", async () => {
      await edited("/products/p-1");
      let finish!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (finish = resolve)));

      await mounted!.wrapper.get("[data-testid=save]").trigger("click");
      const slug = mounted!.wrapper.get("[data-testid=slug]").element as HTMLInputElement;
      slug.focus();
      expect(document.activeElement).not.toBe(slug);
      finish({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });
      await flushPromises();

      expect(document.activeElement).toBe(heading("h1"));
      expect(successToast).toHaveBeenCalledWith("Produkt zapisany.");
    });

    it("#250 F16 does not apply to a publication either: the focus goes to the heading of the card „Status”", async () => {
      mounted = await mountForm("/products/p-1");
      let finish!: (value: unknown) => void;
      mocks.post.mockReturnValue(new Promise((resolve) => (finish = resolve)));
      (mounted.wrapper.get("[data-testid=slug]").element as HTMLInputElement).focus();

      await mounted.wrapper.get("[data-testid=publish]").trigger("click");
      finish({ data: view({ version: 4, status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }), response: { status: 200 } });
      await flushPromises();

      expect(document.activeElement).toBe(heading("h2", "Status"));
    });

    it("keeps the category field off while the request runs, so it cannot take the focus", async () => {
      await edited("/products/p-1");
      let finish!: (value: unknown) => void;
      mocks.put.mockReturnValue(new Promise((resolve) => (finish = resolve)));

      await mounted!.wrapper.get("[data-testid=save]").trigger("click");
      field("Kategoria główna")!.focus();
      expect(document.activeElement).not.toBe(field("Kategoria główna"));
      finish({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });
      await flushPromises();

      expect(document.activeElement).toBe(heading("h1"));
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

  describe("the page", () => {
    it("has crumbs with a link to the list and the product as the current page, instead of „Wróć do listy”", async () => {
      mounted = await mountForm("/products/p-1");

      const crumbs = mounted.wrapper.get("nav[aria-label=Okruszki]");
      expect(crumbs.text()).toContain("Katalog");
      expect(crumbs.get("a[href='/products']").text()).toBe("Produkty");
      expect(crumbs.get("[aria-current=page]").text()).toBe("Buty biegowe");
      expect(mounted.wrapper.text()).not.toContain("Wróć do listy");
    });

    it("has the cards „Dane produktu”, „Zdjęcia”, „Warianty”, „Ceny i stany”, „Status” and „Organizacja”", async () => {
      mounted = await mountForm("/products/p-1");

      expect([...document.body.querySelectorAll("h2")].map((node) => node.textContent?.trim())).toEqual(["Status", "Organizacja", "Dane produktu", "Zdjęcia", "Warianty", "Ceny i stany"]);
      expect(heading("h2", "Status")?.getAttribute("tabindex")).toBe("-1");
    });

    it("has no card „Status” for a product that does not exist yet", async () => {
      mounted = await mountForm("/products/new");

      expect(heading("h2", "Status")).toBeUndefined();
      expect(heading("h2", "Organizacja")).toBeDefined();
      expect(mounted.wrapper.find("[data-testid=publish]").exists()).toBe(false);
    });
  });

  describe("the save bar", () => {
    it("is not there for an untouched product and appears with the first change", async () => {
      mounted = await mountForm("/products/p-1");
      expect(saveBar()).toBeNull();

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");

      expect(saveBar()?.textContent).toContain("Niezapisane zmiany");
      expect(barButton("Odrzuć")).toBeDefined();
      expect(barButton("Zapisz")).toBeDefined();
      expect(document.documentElement.style.scrollPaddingBottom).not.toBe("");
    });

    it("is there from the start for a new product, with „Nowy produkt” and „Anuluj” that goes back to the list", async () => {
      mounted = await mountForm("/products/new");

      expect(saveBar()?.textContent).toContain("Nowy produkt");
      expect(saveBar()?.textContent).not.toContain("Niezapisane zmiany");
      expect(barButton("Odrzuć")).toBeUndefined();

      barButton("Anuluj")!.click();
      await flushPromises();

      expect(mounted.router.currentRoute.value.name).toBe("products");
      expect(dialog()).toBeNull();
    });

    it("goes away after a save and gives the room back", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(saveBar()).toBeNull();
      expect(document.documentElement.style.scrollPaddingBottom).toBe("");
    });

    it("stays after a failed save", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue(problem(403));

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();

      expect(saveBar()).not.toBeNull();
    });

    it("„Odrzuć” restores the product without a request and puts the focus on the heading", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.get.mockClear();

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      barButton("Odrzuć")!.click();
      await flushPromises();

      expect((mounted.wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Buty biegowe");
      expect(saveBar()).toBeNull();
      expect(mocks.get).not.toHaveBeenCalled();
      expect(mocks.put).not.toHaveBeenCalled();
      expect(document.activeElement).toBe(heading("h1"));
    });

    it("is not there for staff who cannot change products", async () => {
      mounted = await mountForm("/products/p-1", ["support"]);

      expect(saveBar()).toBeNull();
    });
  });

  describe("leaving with unsaved changes", () => {
    it("asks in a dialog and stays, with the form as it was, when the staff member stays", async () => {
      mounted = await mountForm("/products/p-1");
      const confirm = vi.spyOn(window, "confirm");

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      const navigation = mounted.router.push("/products");
      await flushPromises();

      expect(dialog()?.textContent).toContain("Masz niezapisane zmiany");
      expect(document.activeElement).toBe(dialogButton("Zostań"));
      dialogButton("Zostań")!.click();
      await navigation;
      await flushPromises();

      expect(confirm).not.toHaveBeenCalled();
      expect(dialog()).toBeNull();
      expect(mounted.router.currentRoute.value.name).toBe("product");
      expect((mounted.wrapper.get("[data-testid=name]").element as HTMLInputElement).value).toBe("Inne");
    });

    it("puts the focus on the heading when the navigation did not come from an element, e.g. the Back button of the browser", async () => {
      mounted = await mountForm("/products/p-1");
      (document.activeElement as HTMLElement | null)?.blur();

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      expect(document.activeElement).toBe(document.body);
      const navigation = mounted.router.push("/products");
      await flushPromises();
      dialogButton("Zostań")!.click();
      await navigation;
      await flushPromises();

      expect(document.activeElement).toBe(heading("h1"));
    });

    it("stays with Escape", async () => {
      mounted = await mountForm("/products/p-1");

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      const navigation = mounted.router.push("/products");
      await flushPromises();
      pressEscape();
      await navigation;
      await flushPromises();

      expect(mounted.router.currentRoute.value.name).toBe("product");
    });

    it("leaves when the staff member chooses „Opuść bez zapisu”", async () => {
      mounted = await mountForm("/products/p-1");

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      const navigation = mounted.router.push("/products");
      await flushPromises();
      dialogButton("Opuść bez zapisu")!.click();
      await navigation;
      await flushPromises();

      expect(mounted.router.currentRoute.value.name).toBe("products");
    });

    it("leaves without asking when nothing changed", async () => {
      mounted = await mountForm("/products/p-1");

      await mounted.router.push("/products");
      await flushPromises();

      expect(dialog()).toBeNull();
      expect(mounted.router.currentRoute.value.name).toBe("products");
    });

    it("does not ask after a save", async () => {
      mounted = await mountForm("/products/p-1");
      mocks.put.mockResolvedValue({ data: view({ version: 4, name: "Inne" }), response: { status: 200 } });

      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      await mounted.wrapper.get("[data-testid=save]").trigger("click");
      await flushPromises();
      await mounted.router.push("/products");
      await flushPromises();

      expect(dialog()).toBeNull();
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

  describe("an image upload in progress (B4)", () => {
    const stored = (key: string) => ({ data: { key, preview: { size: "thumbnail", aspectRatio: "1:1", variants: {} } }, response: { status: 201 } });
    const rows = () => document.body.querySelectorAll("[data-testid=image-row]").length;
    const entries = () => document.body.querySelectorAll("[data-testid=upload-entry]").length;

    function sendOne() {
      let finish!: (value: unknown) => void;
      mocks.post.mockReturnValue(new Promise((resolve) => (finish = resolve)));
      return (key: string) => finish(stored(key));
    }

    it("counts as an unsaved change: the question opens, and staying keeps the upload, which ends in the same form", async () => {
      mounted = await mountForm("/products/p-1");
      const finish = sendOne();
      await files(mounted.wrapper, [new File(["x"], "c.jpg")]);
      await flushPromises();
      expect(saveBar()).toBeNull();

      const navigation = mounted.router.push("/products/new");
      await flushPromises();

      expect(dialog()?.textContent).toContain("Masz niezapisane zmiany");
      expect(mounted.router.currentRoute.value.name).toBe("product");
      dialogButton("Zostań")!.click();
      await navigation;
      await flushPromises();
      expect(entries()).toBe(1);

      finish("originals/c.jpg");
      await flushPromises();

      expect(mounted.router.currentRoute.value.name).toBe("product");
      expect(rows()).toBe(3);
    });

    it("drops the result when the staff member leaves without saving to a new product", async () => {
      mounted = await mountForm("/products/p-1");
      const finish = sendOne();
      await files(mounted.wrapper, [new File(["x"], "c.jpg"), new File(["y"], "d.jpg")]);
      await flushPromises();

      const navigation = mounted.router.push("/products/new");
      await flushPromises();
      dialogButton("Opuść bez zapisu")!.click();
      await navigation;
      await flushPromises();
      expect(mounted.router.currentRoute.value.name).toBe("product-new");
      expect(entries()).toBe(0);

      finish("originals/c.jpg");
      await flushPromises();

      expect(rows()).toBe(0);
      expect(entries()).toBe(0);
      expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it("warns the browser when the tab is closed during an upload", async () => {
      mounted = await mountForm("/products/p-1");
      sendOne();
      await files(mounted.wrapper, [new File(["x"], "c.jpg")]);
      await flushPromises();

      const event = new Event("beforeunload", { cancelable: true });
      window.dispatchEvent(event);

      expect(event.defaultPrevented).toBe(true);
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
    it("no longer asks about unsaved changes on navigation and no longer warns when the tab is closed", async () => {
      mounted = await mountForm("/products/p-1");
      await mounted.wrapper.get("[data-testid=name]").setValue("Inne");
      mounted.wrapper.unmount();

      await mounted.router.push("/products");
      await flushPromises();
      const event = new Event("beforeunload", { cancelable: true });
      window.dispatchEvent(event);

      expect(dialog()).toBeNull();
      expect(mounted.router.currentRoute.value.name).toBe("products");
      expect(event.defaultPrevented).toBe(false);
    });
  });

  describe("load failures", () => {
    it("#251 F9: says that the product could not be loaded in the heading, and that it does not exist in the message", async () => {
      serve(404);
      mounted = await mountForm("/products/gone");

      expect(mounted.wrapper.get("[data-testid=load-error]").text()).toContain("nie istnieje");
      expect(mounted.wrapper.get("[data-testid=load-error]").attributes("role")).toBe("alert");
      expect(mounted.wrapper.find("[data-testid=name]").exists()).toBe(false);
      expect(mounted.wrapper.get("h1").text()).toBe("Nie udało się wczytać produktu");
      expect(saveBar()).toBeNull();
    });

    it("#251 F9: keeps that heading for an existing product that fails to load, not „Nowy produkt”", async () => {
      serve(500);
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.find("[data-testid=load-error]").exists()).toBe(true);
      expect(mounted.wrapper.get("h1").text()).toBe("Nie udało się wczytać produktu");
    });

    it("#251 F9: has that heading when the API cannot be reached", async () => {
      mocks.get.mockImplementation(async (path: string) => {
        if (path === "/api/v1/admin/products/{id}") {
          throw new Error("offline");
        }
        return { data: [], response: { status: 200 } };
      });
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.get("h1").text()).toBe("Nie udało się wczytać produktu");
    });

    it("keeps the heading of an existing product until it is loaded, with a skeleton and no form", async () => {
      mocks.get.mockReturnValue(new Promise(() => {}));
      mounted = await mountForm("/products/p-1");

      expect(mounted.wrapper.get("h1").text()).toBe("Produkt");
      expect(mounted.wrapper.find("[data-testid=name]").exists()).toBe(false);
      expect(mounted.wrapper.find("[data-testid=load-error]").exists()).toBe(false);
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
      expect(wrapper.get("[data-testid=save]").attributes("aria-disabled")).toBeUndefined();
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
      [...document.querySelectorAll<HTMLElement>("[role=alertdialog] button")].find((button) => button.textContent?.trim() === "Usuń wariant czarne")!.click();
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

  describe("the requests of the form (stage 2: B2, B3 and B4)", () => {
    const UNKNOWN = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
    const PUBLISHED = { status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00", slugLocked: true } as const;
    // The controls of the draft: the fields, the tax class, the images and the editor of the variants.
    const CONTROLS = [
      "name",
      "slug",
      "short-description",
      "tax-class",
      "image-alt",
      "image-up",
      "image-down",
      "image-remove",
      "add-images",
      "image-input",
      "axis-name",
      "axis-move-up",
      "axis-move-down",
      "axis-remove",
      "add-axis",
      "variant-value",
      "variant-remove",
      "add-variant",
    ];

    beforeEach(() => {
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    async function settle() {
      await vi.advanceTimersByTimeAsync(0);
      await flushPromises();
    }

    type Wrapper = Awaited<ReturnType<typeof mountForm>>["wrapper"];

    async function open(path = "/products/p-1", product?: ProductEditView) {
      if (product) {
        serve(product);
      }
      mounted = await mountForm(path);
      await settle();
      return mounted.wrapper;
    }

    // Whether the browser would refuse input in every element of the test id now (its own `disabled` or a disabled fieldset).
    function off(wrapper: Wrapper, testid: string): boolean {
      const elements = wrapper.findAll(`[data-testid=${testid}]`);
      expect(elements.length, `an element ${testid}`).toBeGreaterThan(0);
      return elements.every((element) => element.element.matches(":disabled"));
    }
    // The only axis of the product is the first and the last: its buttons that move it are off whatever the state of the form.
    const BY_POSITION = ["axis-move-up", "axis-move-down"];
    const states = (wrapper: Wrapper, unlocked = false) =>
      Object.fromEntries(CONTROLS.filter((testid) => !unlocked || !BY_POSITION.includes(testid)).map((testid) => [testid, off(wrapper, testid)]));
    const all = (value: boolean) => Object.fromEntries(CONTROLS.filter((testid) => value || !BY_POSITION.includes(testid)).map((testid) => [testid, value]));
    const categoriesOff = () => [field("Kategoria główna"), field("Kategorie dodatkowe")].map((input) => input!.matches(":disabled"));
    const inputOf = (wrapper: Wrapper, testid: string) => wrapper.get(`[data-testid=${testid}]`).element as HTMLInputElement;
    const alertOf = (wrapper: Wrapper) => wrapper.get("[data-testid=unknown-result]");

    /** Holds every answer of one of the calls of the client. */
    function hold(mock: typeof mocks.put) {
      const calls: { resolve: (value: unknown) => void; reject: (reason: unknown) => void }[] = [];
      mock.mockImplementation(
        () =>
          new Promise((resolve, reject) => {
            calls.push({ resolve, reject });
          }),
      );
      return calls;
    }

    /** Holds the reads of the product only; the other reads of the form (categories, tax classes, prices) are answered. */
    function holdProductReads() {
      const calls: { resolve: (value: unknown) => void; reject: (reason: unknown) => void }[] = [];
      const answer = mocks.get.getMockImplementation()!;
      mocks.get.mockImplementation((path: string, ...rest: unknown[]) =>
        path === PRODUCT
          ? new Promise((resolve, reject) => {
              calls.push({ resolve, reject });
            })
          : answer(path, ...rest),
      );
      return calls;
    }

    const okView = (data: ProductEditView) => ({ data, response: { status: 200 } });

    async function saveHeld(path = "/products/p-1") {
      const wrapper = await open(path);
      await wrapper.get("[data-testid=name]").setValue("Pierwsza");
      const calls = hold(mocks.put);
      wrapper.get<HTMLButtonElement>("[data-testid=save]").element.focus();
      await wrapper.get("[data-testid=save]").trigger("click");
      await settle();
      return { wrapper, calls };
    }

    it("keeps the focus on „Zapisz” while the save runs, and pressing it again sends nothing", async () => {
      const { wrapper } = await saveHeld();
      const save = wrapper.get<HTMLButtonElement>("[data-testid=save]").element;
      const writes = () => mocks.put.mock.calls.length + mocks.post.mock.calls.length;

      expect(document.activeElement).toBe(save);
      expect(save.disabled).toBe(false);
      expect(save.getAttribute("aria-disabled")).toBe("true");
      await wrapper.get("[data-testid=save]").trigger("click");
      expect(writes()).toBe(1);
      expect(document.activeElement).toBe(save);
    });

    describe("B2: the lock", () => {
      it("turns off the fields, the categories, the images and the editor of the variants from the save to its answer, and a second „Zapisz” sends nothing", async () => {
        const { wrapper, calls } = await saveHeld();

        expect(states(wrapper)).toEqual(all(true));
        expect(categoriesOff()).toEqual([true, true]);
        expect(barButton("Zapisz")?.getAttribute("aria-disabled")).toBe("true");
        expect(barButton("Odrzuć")?.disabled).toBe(true);
        barButton("Zapisz")!.click();
        await settle();
        expect(mocks.put).toHaveBeenCalledTimes(1);

        calls[0]!.resolve(okView(view({ name: "Pierwsza", version: 4 })));
        await settle();

        expect(successToast).toHaveBeenCalledWith("Produkt zapisany.");
        expect(states(wrapper, true)).toEqual(all(false));
        expect(categoriesOff()).toEqual([false, false]);
      });

      it("turns the same controls off while a publication runs", async () => {
        const wrapper = await open();
        const calls = hold(mocks.post);

        await wrapper.get("[data-testid=publish]").trigger("click");
        await settle();

        expect(states(wrapper)).toEqual(all(true));
        expect(categoriesOff()).toEqual([true, true]);
        expect(wrapper.get("[data-testid=publish]").attributes("disabled")).toBeDefined();
        calls[0]!.resolve(okView(view({ ...PUBLISHED, version: 4 })));
        await settle();

        expect(successToast).toHaveBeenCalledWith("Produkt opublikowany.");
        expect(off(wrapper, "name")).toBe(false);
      });

      it("turns the same controls off while a withdrawal runs", async () => {
        const wrapper = await open("/products/p-1", view(PUBLISHED));
        const calls = hold(mocks.post);

        await wrapper.get("[data-testid=unpublish]").trigger("click");
        await settle();

        expect(off(wrapper, "name")).toBe(true);
        expect(off(wrapper, "image-remove")).toBe(true);
        expect(off(wrapper, "variant-value")).toBe(true);
        expect(categoriesOff()).toEqual([true, true]);
        expect(wrapper.get("[data-testid=unpublish]").attributes("disabled")).toBeDefined();
        calls[0]!.resolve(okView(view({ ...PUBLISHED, status: "draft", version: 4 })));
        await settle();

        expect(successToast).toHaveBeenCalledWith("Produkt wycofany z publikacji.");
        expect(off(wrapper, "name")).toBe(false);
      });

      it("does not turn off the card „Ceny i stany”, which has its own requests and is not part of the draft", async () => {
        const { wrapper, calls } = await saveHeld();

        expect(heading("h2", "Dane produktu")!.closest("fieldset:disabled")).not.toBeNull();
        expect(heading("h2", "Zdjęcia")!.closest("fieldset:disabled")).not.toBeNull();
        expect(heading("h2", "Warianty")!.closest("fieldset:disabled")).not.toBeNull();
        expect(heading("h2", "Ceny i stany")!.closest("fieldset:disabled")).toBeNull();
        calls[0]!.resolve(okView(view({ name: "Pierwsza", version: 4 })));
        await settle();
        expect(wrapper.find("[data-testid=price-stock-empty]").exists()).toBe(true);
      });

      it("leaves the controls on after a 422, with the values that were sent", async () => {
        const wrapper = await open();
        await wrapper.get("[data-testid=name]").setValue("");
        await wrapper.get("[data-testid=short-description]").setValue("Nowy opis.");
        mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Podaj nazwę." }] }));

        await wrapper.get("[data-testid=save]").trigger("click");
        await settle();

        expect(states(wrapper, true)).toEqual(all(false));
        expect(inputOf(wrapper, "short-description").value).toBe("Nowy opis.");
        expect(inputOf(wrapper, "name").value).toBe("");
      });

      it("ignores the answer of a save that comes after the route moved to another product", async () => {
        const { wrapper, calls } = await saveHeld();
        serve(view({ id: "p-2", name: "Drugi", slug: "drugi", version: 7 }));
        void mounted!.router.push("/products/p-2");
        await settle();
        // The window of unsaved changes asks first; leave.
        dialogButton("Opuść bez zapisu")?.click();
        await settle();
        expect(mounted!.router.currentRoute.value.params.id).toBe("p-2");

        calls[0]!.resolve(okView(view({ name: "Pierwsza", version: 4 })));
        await settle();

        expect(successToast).not.toHaveBeenCalled();
        expect(inputOf(wrapper, "name").value).toBe("Drugi");
        expect(off(wrapper, "name")).toBe(false);
      });

      it("turns the controls off while „Odśwież” (the server state after a conflict) reads, and says „Odświeżamy…” in a status region", async () => {
        const wrapper = await open();
        await wrapper.get("[data-testid=name]").setValue("Moje");
        mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}product-modified`, detail: "x" }));
        await wrapper.get("[data-testid=save]").trigger("click");
        await settle();
        const reads = holdProductReads();

        await wrapper.get("[data-testid=restore-state]").trigger("click");
        await settle();

        expect(states(wrapper)).toEqual(all(true));
        expect(categoriesOff()).toEqual([true, true]);
        expect(barButton("Zapisz")?.getAttribute("aria-disabled")).toBe("true");
        expect(wrapper.get("[data-testid=publish]").attributes("disabled")).toBeDefined();
        expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Odświeżamy…");
        expect(wrapper.get("[data-testid=safety-status]").attributes("role")).toBe("status");
        expect(inputOf(wrapper, "name").value).toBe("Moje");
        reads[0]!.resolve(okView(view({ name: "Cudze", version: 7 })));
        await settle();

        expect(inputOf(wrapper, "name").value).toBe("Cudze");
        expect(off(wrapper, "name")).toBe(false);
        expect(document.activeElement).toBe(heading("h1"));
      });
    });

    describe("B3: a read that does not answer", () => {
      it("gives up after 10 s with a message and a button to try again, keeps the form, and ignores the answer that comes later, also after a change", async () => {
        const wrapper = await open();
        await wrapper.get("[data-testid=name]").setValue("Moje");
        mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}product-modified`, detail: "x" }));
        await wrapper.get("[data-testid=save]").trigger("click");
        await settle();
        const reads = holdProductReads();
        await wrapper.get("[data-testid=restore-state]").trigger("click");
        await settle();

        await vi.advanceTimersByTimeAsync(9_999);
        expect(wrapper.find("[data-testid=read-failed]").exists()).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        await settle();

        const failed = wrapper.get("[data-testid=read-failed]");
        expect(failed.text()).toContain("Nie udało się odczytać stanu. Spróbuj ponownie.");
        expect(failed.attributes("role")).toBe("alert");
        expect(failed.get("[data-testid=retry-read]").text()).toBe("Spróbuj ponownie");
        expect(document.activeElement).toBe(failed.element);
        expect(off(wrapper, "name")).toBe(false);
        await wrapper.get("[data-testid=name]").setValue("Jeszcze inne");
        reads[0]!.resolve(okView(view({ name: "Cudze", version: 7 })));
        await settle();

        expect(inputOf(wrapper, "name").value).toBe("Jeszcze inne");
      });
    });

    describe("B4: the result of a write that is not known", () => {
      it("says after 10 s that the result is unknown, with „Sprawdź stan” and the focus on the message, and keeps the fields locked", async () => {
        const { wrapper } = await saveHeld();

        await vi.advanceTimersByTimeAsync(9_999);
        expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        await settle();

        expect(alertOf(wrapper).text()).toContain(UNKNOWN);
        expect(alertOf(wrapper).attributes("role")).toBe("alert");
        expect(alertOf(wrapper).get("[data-testid=check-state]").text()).toBe("Sprawdź stan");
        expect(wrapper.find("[data-testid=unblock-form]").exists()).toBe(false);
        expect(document.activeElement).toBe(alertOf(wrapper).element);
        expect(off(wrapper, "name")).toBe(true);
        expect(barButton("Zapisz")?.getAttribute("aria-disabled")).toBe("true");
      });

      it("„Sprawdź stan” stops saying that the server matches the form when the staff member changes a field afterwards", async () => {
        const { wrapper } = await saveHeld();
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();
        serve(view({ name: "Pierwsza", version: 4 }));
        await alertOf(wrapper).get("[data-testid=check-state]").trigger("click");
        await settle();
        expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");

        await wrapper.get("[data-testid=name]").setValue("Druga");

        expect(wrapper.get("[data-testid=safety-status]").text()).toBe("");
        expect(inputOf(wrapper, "name").value).toBe("Druga");
      });

      it("„Sprawdź stan” takes the product from the server when it equals the form, says so in a status region and sends no write", async () => {
        const { wrapper } = await saveHeld();
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();
        serve(view({ name: "Pierwsza", version: 4 }));

        await alertOf(wrapper).get("[data-testid=check-state]").trigger("click");
        await settle();

        expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
        expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");
        expect(wrapper.get("[data-testid=safety-status]").attributes("role")).toBe("status");
        expect(off(wrapper, "name")).toBe(false);
        expect(inputOf(wrapper, "name").value).toBe("Pierwsza");
        expect(saveBar()).toBeNull();
        expect(mocks.put).toHaveBeenCalledTimes(1);
      });

      it("„Sprawdź stan” says the write is not on the server when the version is the same and the values are not, and the draft stays", async () => {
        const { wrapper } = await saveHeld();
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();

        await alertOf(wrapper).get("[data-testid=check-state]").trigger("click");
        await settle();

        expect(alertOf(wrapper).text()).toContain("Zapisu nie widać na serwerze. Może jeszcze dotrzeć.");
        expect(document.activeElement).toBe(alertOf(wrapper).element);
        expect(inputOf(wrapper, "name").value).toBe("Pierwsza");
        expect(off(wrapper, "name")).toBe(false);
      });

      it("„Sprawdź stan” offers „Odrzuć moje zmiany i pokaż stan z serwera” when the version on the server is another", async () => {
        const { wrapper } = await saveHeld();
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();
        serve(view({ name: "Cudze", version: 8 }));

        await alertOf(wrapper).get("[data-testid=check-state]").trigger("click");
        await settle();

        expect(alertOf(wrapper).text()).toContain("Na serwerze jest inna wersja niż ta, którą edytujesz.");
        expect(inputOf(wrapper, "name").value).toBe("Pierwsza");
        await wrapper.get("[data-testid=restore-state]").trigger("click");
        await settle();
        expect(inputOf(wrapper, "name").value).toBe("Cudze");
        expect(wrapper.find("[data-testid=unknown-result]").exists()).toBe(false);
      });

      it("says in a toast that the earlier save was done when its answer comes after „Sprawdź stan”, and changes nothing in the form", async () => {
        const { wrapper, calls } = await saveHeld();
        await vi.advanceTimersByTimeAsync(10_000);
        await settle();
        await alertOf(wrapper).get("[data-testid=check-state]").trigger("click");
        await settle();
        await wrapper.get("[data-testid=name]").setValue("Druga");

        calls[0]!.resolve(okView(view({ name: "Pierwsza", version: 4 })));
        await settle();

        expect(successToast).toHaveBeenCalledWith("Wcześniejszy zapis został wykonany.");
        expect(successToast).not.toHaveBeenCalledWith("Produkt zapisany.");
        expect(inputOf(wrapper, "name").value).toBe("Druga");
        expect(off(wrapper, "name")).toBe(false);
      });

      it("moves the focus to the message after an error that does not reach the API, with the fields unlocked", async () => {
        const { wrapper, calls } = await saveHeld();

        calls[0]!.reject(new TypeError("network down"));
        await settle();

        expect(alertOf(wrapper).text()).toContain(UNKNOWN);
        expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
        expect(document.activeElement).toBe(alertOf(wrapper).element);
        expect(off(wrapper, "name")).toBe(false);
        expect(inputOf(wrapper, "name").value).toBe("Pierwsza");
      });

      it("has „Sprawdź stan” after a publication that ended unknown, and takes the published product when the server has it", async () => {
        const wrapper = await open();
        mocks.post.mockRejectedValue(new TypeError("network down"));
        await wrapper.get("[data-testid=publish]").trigger("click");
        await settle();
        expect(alertOf(wrapper).text()).toContain(UNKNOWN);
        serve(view({ ...PUBLISHED, version: 4 }));

        await alertOf(wrapper).get("[data-testid=check-state]").trigger("click");
        await settle();

        expect(wrapper.get("[data-testid=product-status]").text()).toBe("Opublikowany");
        expect(wrapper.get("[data-testid=safety-status]").text()).toBe("Stan na serwerze odpowiada formularzowi.");
        expect(mocks.post).toHaveBeenCalledTimes(1);
      });

      describe("a new product", () => {
        async function createHeld() {
          const wrapper = await open("/products/new");
          await wrapper.get("[data-testid=name]").setValue("Nowy");
          const calls = hold(mocks.post);
          await wrapper.get("[data-testid=save]").trigger("click");
          await settle();
          return { wrapper, calls };
        }

        it("says after 10 s that the result is unknown, with „Odblokuj formularz” instead of „Sprawdź stan”", async () => {
          const { wrapper } = await createHeld();
          await vi.advanceTimersByTimeAsync(10_000);
          await settle();

          expect(alertOf(wrapper).text()).toContain(UNKNOWN);
          expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
          expect(alertOf(wrapper).get("[data-testid=unblock-form]").text()).toBe("Odblokuj formularz");
          expect(off(wrapper, "name")).toBe(true);
        });

        it("unlocks the form with the message about the list and a link to it in a new tab, and a late success only gives a toast", async () => {
          const { wrapper, calls } = await createHeld();
          await vi.advanceTimersByTimeAsync(10_000);
          await settle();

          await alertOf(wrapper).get("[data-testid=unblock-form]").trigger("click");
          await settle();

          expect(alertOf(wrapper).text()).toContain(
            "Nie wiemy, czy produkt został utworzony. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.",
          );
          const link = alertOf(wrapper).get("[data-testid=list-link]");
          expect(link.attributes("href")).toBe("/products");
          expect(link.attributes("target")).toBe("_blank");
          expect(link.attributes("rel")).toContain("noopener");
          expect(link.text()).toContain("Lista produktów");
          expect(off(wrapper, "name")).toBe(false);
          await wrapper.get("[data-testid=name]").setValue("Inny");

          calls[0]!.resolve({ data: view({ id: "created", name: "Nowy", version: 1 }), response: { status: 201 } });
          await settle();

          expect(successToast).toHaveBeenCalledWith("Wcześniejszy zapis został wykonany.");
          expect(mounted!.router.currentRoute.value.name).toBe("product-new");
          expect(inputOf(wrapper, "name").value).toBe("Inny");
        });
      });
    });

    describe("a 409 of the version and the way to the server state (E7)", () => {
      it("keeps the way to the server state with its context after „Odrzuć”, the label as it was, and the focus on the message", async () => {
        const wrapper = await open();
        await wrapper.get("[data-testid=name]").setValue("Moje");
        mocks.put.mockResolvedValue(problem(409, { type: `${PREFIX}product-modified`, detail: "x" }));
        await wrapper.get("[data-testid=save]").trigger("click");
        await settle();

        barButton("Odrzuć")!.click();
        await settle();

        expect(inputOf(wrapper, "name").value).toBe("Buty biegowe");
        expect(wrapper.find("[data-testid=form-error]").exists()).toBe(false);
        const note = wrapper.get("[data-testid=restore-note]");
        expect(note.text()).toContain("Na serwerze jest inna wersja niż ta, którą edytujesz.");
        expect(wrapper.find("[data-testid=check-state]").exists()).toBe(false);
        expect(wrapper.get("[data-testid=restore-state]").text()).toBe("Odrzuć moje zmiany i pokaż stan z serwera");
        expect(document.activeElement).toBe(note.element);
      });

      it("puts a 409 slug-taken at the slug field with the note of an earlier unknown creation", async () => {
        const wrapper = await open("/products/new");
        await wrapper.get("[data-testid=name]").setValue("Nowy");
        mocks.post.mockRejectedValueOnce(new TypeError("network down"));
        await wrapper.get("[data-testid=save]").trigger("click");
        await settle();
        mocks.post.mockResolvedValue(problem(409, { type: `${PREFIX}slug-taken`, detail: "x" }));

        await wrapper.get("[data-testid=save]").trigger("click");
        await settle();

        expect(wrapper.get("[data-testid=error-slug]").text()).toBe("Ten slug ma już inny produkt. Podaj własny slug. Możliwe, że utworzył go Twój wcześniejszy zapis.");
        expect(document.activeElement).toBe(wrapper.get("[data-testid=slug]").element);
      });
    });
  });
});
