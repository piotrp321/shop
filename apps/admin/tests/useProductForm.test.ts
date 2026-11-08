import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, ref } from "vue";
import type { components } from "@/api/schema";
import { useProductForm } from "@/products/useProductForm";

type ProductEditView = components["schemas"]["ProductEditViewResponse"];

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ GET: mocks.get, POST: mocks.post, PUT: mocks.put }) }));

const PRODUCT = "/api/v1/admin/products/{id}";
const PRODUCTS = "/api/v1/admin/products";
const PUBLISH = "/api/v1/admin/products/{id}/publish";
const UNPUBLISH = "/api/v1/admin/products/{id}/unpublish";
const UPLOAD = "/api/v1/admin/product-images";
const TAX_CLASSES = "/api/v1/admin/tax-classes";
const PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";

const preview = { alt: "Zdjęcie", size: "thumbnail", aspectRatio: "1:1", variants: { webp: [{ width: 96, url: "https://img.test/a" }] } };

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
    images: [
      { key: "originals/a.jpg", alt: "Przód", preview },
      { key: "originals/b.jpg", alt: "Bok", preview },
    ],
    variantAxes: ["Kolor"],
    variants: [
      { variantId: "v-1", values: ["czarne"] },
      { variantId: "v-2", values: ["białe"] },
    ],
    updatedAt: "2026-11-01T12:30:00+00:00",
    ...overrides,
  };
}

function ok(data: unknown, status = 200) {
  return { data, response: { status } };
}

function problem(status: number, body: Record<string, unknown> = {}) {
  return { data: undefined, error: body, response: { status } };
}

function conflict(kind: string, detail = "Szczegóły z API.") {
  return problem(409, { type: `${PREFIX}${kind}`, title: "Conflict", status: 409, detail });
}

/** Answers the reads the form makes on mount. */
function serveReads(product: ProductEditView | null = view()) {
  mocks.get.mockImplementation(async (path: string) => {
    if (path === TAX_CLASSES) {
      return ok([{ code: "standard", name: "Standardowa" }]);
    }
    return product ? ok(product) : problem(404);
  });
}

interface Setup {
  id?: string | null;
  canManage?: boolean;
}

async function start(options: Setup = {}) {
  const id = ref<string | null>(options.id === undefined ? "p-1" : options.id);
  const canManage = ref(options.canManage ?? true);
  const onCreated = vi.fn();
  let form!: ReturnType<typeof useProductForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = useProductForm({ productId: () => id.value, canManage: () => canManage.value, onCreated });
        return () => null;
      },
    }),
  );
  await flushPromises();
  return { form, id, onCreated, wrapper };
}

function deferred() {
  let resolve!: (value: unknown) => void;
  const promise = new Promise((done) => (resolve = done));
  return { promise, resolve };
}

describe("useProductForm", () => {
  beforeEach(() => {
    mocks.get.mockReset();
    mocks.post.mockReset();
    mocks.put.mockReset();
    serveReads();
  });

  describe("loading", () => {
    it("loads the product and the tax classes and fills the form from the API", async () => {
      const { form } = await start();

      expect(mocks.get).toHaveBeenCalledWith(PRODUCT, { params: { path: { id: "p-1" } } });
      expect(form.loaded.value).toBe(true);
      expect(form.form.value).toEqual({
        name: "Buty biegowe",
        slug: "buty-biegowe",
        shortDescription: "Lekkie buty.",
        mainCategoryId: "c-main",
        additionalCategoryIds: ["c-extra"],
        taxClass: "standard",
        images: [
          { key: "originals/a.jpg", alt: "Przód", variants: preview.variants },
          { key: "originals/b.jpg", alt: "Bok", variants: preview.variants },
        ],
        variantAxes: ["Kolor"],
        variants: [
          { variantId: "v-1", values: ["czarne"] },
          { variantId: "v-2", values: ["białe"] },
        ],
      });
      expect(form.view.value).toMatchObject({ version: 3, status: "draft", slugLocked: false, firstPublishedAt: null });
      expect(form.taxClasses.value).toEqual([{ code: "standard", name: "Standardowa" }]);
      expect(form.dirty.value).toBe(false);
    });

    it("starts a new product empty, with the default variant and without asking for a product", async () => {
      const { form } = await start({ id: null });

      expect(mocks.get).not.toHaveBeenCalledWith(PRODUCT, expect.anything());
      expect(form.loaded.value).toBe(true);
      expect(form.view.value).toBeNull();
      expect(form.form.value).toMatchObject({
        name: "",
        slug: "",
        mainCategoryId: null,
        taxClass: null,
        images: [],
        variantAxes: [],
        variants: [{ variantId: null, values: [] }],
      });
      expect(form.dirty.value).toBe(false);
    });

    it("says that the product does not exist on a 404 and keeps the form unloaded", async () => {
      serveReads(null);
      const { form } = await start();

      expect(form.loaded.value).toBe(false);
      expect(form.loadError.value).toContain("nie istnieje");
    });

    it("explains other load failures, including a network one", async () => {
      mocks.get.mockImplementation(async (path: string) => (path === TAX_CLASSES ? ok([]) : problem(500)));
      const failed = await start();
      expect(failed.form.loadError.value).toContain("HTTP 500");

      mocks.get.mockImplementation(async (path: string) => {
        if (path === TAX_CLASSES) {
          return ok([]);
        }
        throw new Error("offline");
      });
      const offline = await start();
      expect(offline.form.loadError.value).toContain("połączenie");
    });

    it("keeps the form usable when the tax classes cannot be read, and says so", async () => {
      mocks.get.mockImplementation(async (path: string) => (path === TAX_CLASSES ? problem(500) : ok(view())));
      const { form } = await start();

      expect(form.loaded.value).toBe(true);
      expect(form.taxClassesError.value).toContain("klas podatkowych");
    });

    it("loads another product when the route changes to it", async () => {
      const { id, form } = await start();
      mocks.get.mockImplementation(async () => ok(view({ id: "p-2", name: "Kurtka" })));

      id.value = "p-2";
      await flushPromises();

      expect(form.form.value.name).toBe("Kurtka");
    });
  });

  describe("dirty state", () => {
    it("is dirty when a field differs from the loaded product and clean again when it is restored", async () => {
      const { form } = await start();

      form.form.value.name = "Inne buty";
      expect(form.dirty.value).toBe(true);
      form.form.value.name = "Buty biegowe";
      expect(form.dirty.value).toBe(false);
    });

    it("counts a changed alt, order and removal of an image as changes", async () => {
      const { form } = await start();

      form.form.value.images[0]!.alt = "Nowy opis";
      expect(form.dirty.value).toBe(true);
      form.form.value.images[0]!.alt = "Przód";
      form.moveImage(0, 1);
      expect(form.form.value.images.map((image) => image.key)).toEqual(["originals/b.jpg", "originals/a.jpg"]);
      expect(form.dirty.value).toBe(true);
      form.moveImage(1, -1);
      expect(form.dirty.value).toBe(false);
      form.removeImage(1);
      expect(form.form.value.images.map((image) => image.key)).toEqual(["originals/a.jpg"]);
      expect(form.dirty.value).toBe(true);
    });

    it("ignores moves outside the list", async () => {
      const { form } = await start();

      form.moveImage(0, -1);
      form.moveImage(1, 1);

      expect(form.form.value.images.map((image) => image.key)).toEqual(["originals/a.jpg", "originals/b.jpg"]);
    });
  });

  describe("saving a new product", () => {
    it("posts the form and then hands the new id over for navigation, showing the product of the API", async () => {
      const { form, onCreated } = await start({ id: null });
      form.form.value.name = "Buty biegowe";
      form.form.value.mainCategoryId = "c-main";
      form.form.value.taxClass = "standard";
      form.form.value.images = [{ key: "originals/a.jpg", alt: "Przód", variants: preview.variants }];
      mocks.post.mockResolvedValue(ok(view({ images: [{ key: "originals/a.jpg", alt: "Przód", preview }], variantAxes: [], variants: [{ variantId: "v-9", values: [] }] }), 201));

      await form.save();

      expect(mocks.post).toHaveBeenCalledWith(PRODUCTS, {
        body: {
          name: "Buty biegowe",
          slug: "",
          shortDescription: "",
          mainCategoryId: "c-main",
          additionalCategoryIds: [],
          taxClass: "standard",
          images: [{ key: "originals/a.jpg", alt: "Przód" }],
          variantAxes: [],
          variants: [{ variantId: null, values: [] }],
        },
      });
      expect(onCreated).toHaveBeenCalledWith("p-1");
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(false);
      expect(form.message.value).toContain("zapisany");
    });

    it("ignores the answer of a post that finishes after the form was left", async () => {
      const { form, onCreated, wrapper } = await start({ id: null });
      const pending = deferred();
      mocks.post.mockReturnValue(pending.promise);

      const saving = form.save();
      await flushPromises();
      wrapper.unmount();
      pending.resolve(ok(view(), 201));
      await saving;

      expect(onCreated).not.toHaveBeenCalled();
      expect(form.view.value).toBeNull();
      expect(form.message.value).toBeNull();
    });

    it("does not navigate when the post fails", async () => {
      const { form, onCreated } = await start({ id: null });
      mocks.post.mockResolvedValue(problem(500));

      await form.save();

      expect(onCreated).not.toHaveBeenCalled();
      expect(form.error.value).toContain("HTTP 500");
    });

    it("does not load the product it has just created when the route moves to it", async () => {
      const { form, id } = await start({ id: null });
      mocks.post.mockResolvedValue(ok(view(), 201));
      await form.save();
      mocks.get.mockClear();

      id.value = "p-1";
      await flushPromises();

      expect(mocks.get).not.toHaveBeenCalledWith(PRODUCT, expect.anything());
    });
  });

  describe("saving an existing product", () => {
    it("puts the whole form with the version, sending the variants of the API unchanged", async () => {
      const { form } = await start();
      form.form.value.name = "Inne buty";
      mocks.put.mockResolvedValue(ok(view({ version: 4, name: "Inne buty" })));

      await form.save();

      expect(mocks.put).toHaveBeenCalledWith(PRODUCT, {
        params: { path: { id: "p-1" } },
        body: {
          version: 3,
          name: "Inne buty",
          slug: "buty-biegowe",
          shortDescription: "Lekkie buty.",
          mainCategoryId: "c-main",
          additionalCategoryIds: ["c-extra"],
          taxClass: "standard",
          images: [
            { key: "originals/a.jpg", alt: "Przód" },
            { key: "originals/b.jpg", alt: "Bok" },
          ],
          variantAxes: ["Kolor"],
          variants: [
            { variantId: "v-1", values: ["czarne"] },
            { variantId: "v-2", values: ["białe"] },
          ],
        },
      });
      expect(form.view.value?.version).toBe(4);
      expect(form.dirty.value).toBe(false);
      expect(form.message.value).toContain("zapisany");
    });

    it("sends the new version on the next save", async () => {
      const { form } = await start();
      mocks.put.mockResolvedValueOnce(ok(view({ version: 4 }))).mockResolvedValueOnce(ok(view({ version: 5 })));

      await form.save();
      form.form.value.name = "Znowu";
      await form.save();

      expect(mocks.put.mock.calls[1]![1].body.version).toBe(4);
    });
  });

  describe("publishing and withdrawing", () => {
    it("publishes a saved draft with its version and takes the status from the API", async () => {
      const { form } = await start();
      expect(form.canPublish.value).toBe(true);
      expect(form.canUnpublish.value).toBe(false);
      mocks.post.mockResolvedValue(ok(view({ version: 4, status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00", slugLocked: true })));

      await form.publish();

      expect(mocks.post).toHaveBeenCalledWith(PUBLISH, { params: { path: { id: "p-1" } }, body: { version: 3 } });
      expect(form.view.value).toMatchObject({ version: 4, status: "published", slugLocked: true });
      expect(form.message.value).toContain("opublikowany");
      expect(form.canPublish.value).toBe(false);
      expect(form.canUnpublish.value).toBe(true);
    });

    it("withdraws a published product with its version", async () => {
      serveReads(view({ status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00" }));
      const { form } = await start();
      mocks.post.mockResolvedValue(ok(view({ version: 4, status: "draft" })));

      await form.unpublish();

      expect(mocks.post).toHaveBeenCalledWith(UNPUBLISH, { params: { path: { id: "p-1" } }, body: { version: 3 } });
      expect(form.view.value?.status).toBe("draft");
      expect(form.message.value).toContain("wycofany");
    });

    it("does not publish or withdraw with unsaved changes", async () => {
      const { form } = await start();
      form.form.value.name = "Inne buty";

      expect(form.canPublish.value).toBe(false);
      await form.publish();
      await form.unpublish();

      expect(mocks.post).not.toHaveBeenCalled();
    });

    it("cannot publish a product that is not saved yet", async () => {
      const { form } = await start({ id: null });

      expect(form.canPublish.value).toBe(false);
      expect(form.canUnpublish.value).toBe(false);
    });
  });

  describe("requests in flight", () => {
    it("disables every action while a request runs and sends no second request", async () => {
      const { form } = await start();
      const pending = deferred();
      mocks.put.mockReturnValue(pending.promise);

      const saving = form.save();
      await flushPromises();

      expect(form.busy.value).toBe(true);
      expect(form.canSave.value).toBe(false);
      expect(form.canPublish.value).toBe(false);
      expect(form.canUnpublish.value).toBe(false);
      await form.save();
      await form.publish();
      expect(mocks.put).toHaveBeenCalledTimes(1);
      expect(mocks.post).not.toHaveBeenCalled();

      pending.resolve(ok(view({ version: 4 })));
      await saving;
      expect(form.busy.value).toBe(false);
      expect(form.canSave.value).toBe(true);
    });

    it("disables the actions while an image is being uploaded", async () => {
      const { form } = await start();
      mocks.post.mockImplementation(() => new Promise(() => undefined));

      form.upload.addFiles([new File(["x"], "c.jpg")]);
      await flushPromises();

      expect(mocks.post).toHaveBeenCalledWith(UPLOAD, expect.anything());
      expect(form.canSave.value).toBe(false);
      expect(form.canPublish.value).toBe(false);
    });

    it("uploads a file with the current name as alt and counts it as a change", async () => {
      const { form } = await start();
      mocks.post.mockResolvedValue(ok({ key: "originals/c.jpg", preview: { size: "thumbnail", aspectRatio: "1:1", variants: {} } }, 201));

      form.upload.addFiles([new File(["x"], "c.jpg")]);
      await flushPromises();

      expect(form.form.value.images.at(-1)).toEqual({ key: "originals/c.jpg", alt: "Buty biegowe", variants: {} });
      expect(form.dirty.value).toBe(true);
    });
  });

  describe("without the permission to manage products", () => {
    it("cannot act and sends nothing", async () => {
      const { form } = await start({ canManage: false });
      form.form.value.name = "Inne";

      expect(form.readOnly.value).toBe(true);
      expect(form.canSave.value).toBe(false);
      expect(form.canPublish.value).toBe(false);
      expect(form.canUnpublish.value).toBe(false);
      await form.save();
      await form.publish();
      await form.unpublish();
      expect(mocks.put).not.toHaveBeenCalled();
      expect(mocks.post).not.toHaveBeenCalled();
    });
  });

  describe("conflicts (409)", () => {
    it("tells that the product changed meanwhile and refreshes on request, discarding the changes", async () => {
      const { form } = await start();
      form.form.value.name = "Moje zmiany";
      mocks.put.mockResolvedValue(conflict("product-modified"));

      await form.save();

      expect(form.conflict.value).toBe(true);
      expect(form.error.value).toBe("Produkt został w międzyczasie zmieniony – odśwież");
      expect(form.form.value.name).toBe("Moje zmiany");

      serveReads(view({ version: 7, name: "Cudza zmiana" }));
      await form.refresh();

      expect(form.conflict.value).toBe(false);
      expect(form.error.value).toBeNull();
      expect(form.form.value.name).toBe("Cudza zmiana");
      expect(form.view.value?.version).toBe(7);
      expect(form.dirty.value).toBe(false);
    });

    it("shows product-modified of a publication the same way", async () => {
      const { form } = await start();
      mocks.post.mockResolvedValue(conflict("product-modified"));

      await form.publish();

      expect(form.conflict.value).toBe(true);
    });

    it("puts slug-taken at the slug field with a fixed message", async () => {
      const { form } = await start();
      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(conflict("slug-taken", "Slug zajęty."));

      await form.save();

      expect(form.errorsAt("slug")).toEqual(["Ten slug ma już inny produkt. Podaj własny slug."]);
      expect(form.error.value).toBeNull();
      expect(form.conflict.value).toBe(false);
    });

    it("puts slug-taken of a new product at the slug field", async () => {
      const { form } = await start({ id: null });
      mocks.post.mockResolvedValue(conflict("slug-taken"));

      await form.save();

      expect(form.errorsAt("slug")).toEqual(["Ten slug ma już inny produkt. Podaj własny slug."]);
    });

    it("puts slug-locked at the slug field with the detail of the API", async () => {
      const { form } = await start();
      form.form.value.slug = "inny";
      mocks.put.mockResolvedValue(conflict("slug-locked", "Slug jest zablokowany."));

      await form.save();

      expect(form.errorsAt("slug")).toEqual(["Slug jest zablokowany."]);
    });

    it("explains product-not-publishable differently for a publication and for a save", async () => {
      const { form } = await start();
      mocks.post.mockResolvedValue(conflict("product-not-publishable"));
      await form.publish();
      expect(form.error.value).toBe("Dodaj co najmniej jedno zdjęcie przed publikacją.");

      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(conflict("product-not-publishable"));
      await form.save();
      expect(form.error.value).toBe("Opublikowany produkt musi mieć co najmniej jedno zdjęcie.");
    });

    it.each(["variant-locked", "variant-in-use"])("shows the detail of %s at the variants section", async (kind) => {
      const { form } = await start();
      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(conflict(kind, "Wariant ma stan magazynowy."));

      await form.save();

      expect(form.variantsErrors.value).toEqual(["Wariant ma stan magazynowy."]);
      expect(form.error.value).toBeNull();
    });

    it("shows an unknown conflict as a form message", async () => {
      const { form } = await start();
      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(conflict("something-new", "Coś nowego."));

      await form.save();

      expect(form.error.value).toBe("Coś nowego.");
    });
  });

  describe("validation errors (422)", () => {
    it("puts the violations at their fields, including nested paths", async () => {
      const { form } = await start();
      form.form.value.name = "";
      mocks.put.mockResolvedValue(
        problem(422, {
          violations: [
            { propertyPath: "name", title: "Nazwa jest wymagana." },
            { propertyPath: "images[1].alt", title: "Opis zdjęcia jest wymagany." },
            { propertyPath: "images[1].key", title: "To zdjęcie już jest w produkcie." },
            { propertyPath: "variants[0].values", title: "Zła liczba wartości." },
            { propertyPath: "variantAxes[0]", title: "Zła oś." },
          ],
        }),
      );

      await form.save();

      expect(form.errorsAt("name")).toEqual(["Nazwa jest wymagana."]);
      expect(form.errorsAt("images[1]")).toEqual(["Opis zdjęcia jest wymagany.", "To zdjęcie już jest w produkcie."]);
      expect(form.errorsAt("images[0]")).toEqual([]);
      expect(form.errorsAt("images[1].alt")).toEqual(["Opis zdjęcia jest wymagany."]);
      // The section shows only what is about the lists themselves; the rest is at the axis and at the row.
      expect(form.variantsErrors.value).toEqual([]);
      expect(form.errorsAt("variantAxes[0]")).toEqual(["Zła oś."]);
      expect(form.errorsAt("variants[0]")).toEqual(["Zła liczba wartości."]);
      expect(form.error.value).toBeNull();
      expect(form.dirty.value).toBe(true);
    });

    it("keeps a violation at the images list itself apart from the ones at a single image", async () => {
      const { form } = await start();
      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(
        problem(422, {
          violations: [
            { propertyPath: "images", title: "Produkt może mieć najwyżej 10 zdjęć." },
            { propertyPath: "images[0].alt", title: "Opis zdjęcia jest wymagany." },
          ],
        }),
      );

      await form.save();

      expect(form.imagesErrors.value).toEqual(["Produkt może mieć najwyżej 10 zdjęć."]);
      expect(form.errorsAt("images[0]")).toEqual(["Opis zdjęcia jest wymagany."]);
    });

    it("shows a violation at an unknown path as a form message so that nothing is lost", async () => {
      const { form } = await start();
      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "version", title: "Wersja jest wymagana." }] }));

      await form.save();

      expect(form.error.value).toBe("Wersja jest wymagana.");
    });

    it("shows a 422 without violations as a form message", async () => {
      const { form } = await start();
      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(problem(422, { detail: "Nie można wyliczyć sluga z nazwy." }));

      await form.save();

      expect(form.error.value).toBe("Nie można wyliczyć sluga z nazwy.");

      mocks.put.mockResolvedValue(problem(422));
      await form.save();
      expect(form.error.value).toContain("HTTP 422");
    });

    it("clears the errors of the earlier attempt when the next request starts", async () => {
      const { form } = await start();
      form.form.value.name = "";
      mocks.put.mockResolvedValueOnce(problem(422, { violations: [{ propertyPath: "name", title: "Nazwa jest wymagana." }] }));
      await form.save();
      expect(form.errorsAt("name")).toHaveLength(1);

      const pending = deferred();
      mocks.put.mockReturnValue(pending.promise);
      const saving = form.save();
      await flushPromises();

      expect(form.errorsAt("name")).toEqual([]);
      pending.resolve(ok(view({ version: 4 })));
      await saving;
    });
  });

  describe("other failures", () => {
    it.each([
      [403, "uprawnień"],
      [404, "nie istnieje"],
      [503, "niedostępn"],
      [500, "HTTP 500"],
    ])("shows a %i as a message and keeps what was typed", async (status, expected) => {
      const { form } = await start();
      form.form.value.name = "Moje zmiany";
      mocks.put.mockResolvedValue(problem(status));

      await form.save();

      expect(form.error.value).toContain(expected);
      expect(form.form.value.name).toBe("Moje zmiany");
      expect(form.dirty.value).toBe(true);
      expect(form.busy.value).toBe(false);
    });

    it("reports a network failure and keeps the form", async () => {
      const { form } = await start();
      form.form.value.name = "Moje zmiany";
      mocks.put.mockRejectedValue(new Error("offline"));

      await form.save();

      expect(form.error.value).toContain("połączenie");
      expect(form.form.value.name).toBe("Moje zmiany");
      expect(form.busy.value).toBe(false);
    });

    it("reports a failed publication as a message", async () => {
      const { form } = await start();
      mocks.post.mockResolvedValue(problem(403));

      await form.publish();

      expect(form.error.value).toContain("uprawnień");
    });
  });

  describe("editing variants", () => {
    const sentVariants = () => mocks.put.mock.calls.at(-1)![1].body;

    it("adds an axis with an empty value in every row, up to two axes", async () => {
      const { form } = await start();

      form.addAxis();
      form.setAxis(1, "Rozmiar");
      form.addAxis();

      expect(form.form.value.variantAxes).toEqual(["Kolor", "Rozmiar"]);
      expect(form.form.value.variants).toEqual([
        { variantId: "v-1", values: ["czarne", ""] },
        { variantId: "v-2", values: ["białe", ""] },
      ]);
      expect(form.dirty.value).toBe(true);
    });

    it("renames an axis without touching the values", async () => {
      const { form } = await start();

      form.setAxis(0, "Barwa");

      expect(form.form.value.variantAxes).toEqual(["Barwa"]);
      expect(form.form.value.variants[0]!.values).toEqual(["czarne"]);
    });

    it("removes the values of a removed axis at its position", async () => {
      const { form } = await start({ id: null });
      form.addAxis();
      form.addAxis();
      form.setAxis(0, "Kolor");
      form.setAxis(1, "Rozmiar");
      form.setValue(0, 0, "czarne");
      form.setValue(0, 1, "M");
      form.addVariant();
      form.setValue(1, 0, "białe");
      form.setValue(1, 1, "L");

      form.removeAxis(0);

      expect(form.form.value.variantAxes).toEqual(["Rozmiar"]);
      expect(form.form.value.variants).toEqual([
        { variantId: null, values: ["M"] },
        { variantId: null, values: ["L"] },
      ]);
    });

    it("moves an axis together with the values in the rows", async () => {
      const { form } = await start({ id: null });
      form.addAxis();
      form.addAxis();
      form.setAxis(0, "Kolor");
      form.setAxis(1, "Rozmiar");
      form.setValue(0, 0, "czarne");
      form.setValue(0, 1, "M");

      form.moveAxis(0, 1);

      expect(form.form.value.variantAxes).toEqual(["Rozmiar", "Kolor"]);
      expect(form.form.value.variants[0]!.values).toEqual(["M", "czarne"]);

      form.moveAxis(0, -1);
      expect(form.form.value.variantAxes).toEqual(["Rozmiar", "Kolor"]);
    });

    it("does not add a third axis", async () => {
      const { form } = await start({ id: null });
      form.addAxis();
      form.addAxis();
      form.addAxis();

      expect(form.form.value.variantAxes).toHaveLength(2);
    });

    it("keeps the default variant, with its id, as the first row when the first axis is added", async () => {
      const { form } = await start({ id: "p-1" });
      serveReads(view({ variantAxes: [], variants: [{ variantId: "v-default", values: [] }] }));
      await form.refresh();

      form.addAxis();
      form.setAxis(0, "Kolor");
      form.setValue(0, 0, "czarne");
      form.addVariant();
      form.setValue(1, 0, "białe");
      mocks.put.mockResolvedValue(ok(view()));
      await form.save();

      expect(sentVariants().variants).toEqual([
        { variantId: "v-default", values: ["czarne"] },
        { variantId: null, values: ["białe"] },
      ]);
    });

    it("collapses to the first row, without values and with its id, when the last axis is removed", async () => {
      const { form } = await start();

      form.removeAxis(0);

      expect(form.form.value.variantAxes).toEqual([]);
      expect(form.form.value.variants).toEqual([{ variantId: "v-1", values: [] }]);
    });

    it("adds a row with an empty value per axis and sends null for it", async () => {
      const { form } = await start();

      form.addVariant();
      form.setValue(2, 0, "zielone");
      mocks.put.mockResolvedValue(ok(view()));
      await form.save();

      expect(sentVariants().variants).toEqual([
        { variantId: "v-1", values: ["czarne"] },
        { variantId: "v-2", values: ["białe"] },
        { variantId: null, values: ["zielone"] },
      ]);
    });

    it("removes a row, keeps the ids of the others and sends them", async () => {
      const { form } = await start();

      form.removeVariant(0);
      mocks.put.mockResolvedValue(ok(view()));
      await form.save();

      expect(sentVariants().variants).toEqual([{ variantId: "v-2", values: ["białe"] }]);
    });

    it("keeps at least one row and does not add a row to a product without axes", async () => {
      const { form } = await start();
      form.removeVariant(0);
      form.removeVariant(0);
      expect(form.form.value.variants).toHaveLength(1);

      form.removeAxis(0);
      form.addVariant();
      expect(form.form.value.variants).toHaveLength(1);
    });

    it("does not add more than 100 rows", async () => {
      const { form } = await start();
      for (let i = 0; i < 120; i++) {
        form.addVariant();
      }

      expect(form.form.value.variants).toHaveLength(100);
    });

    it("tells whether the axes are locked by the first publication", async () => {
      expect((await start()).form.axesLocked.value).toBe(false);
      expect((await start({ id: null })).form.axesLocked.value).toBe(false);

      serveReads(view({ status: "draft", firstPublishedAt: "2026-10-01T10:00:00+00:00" }));
      expect((await start()).form.axesLocked.value).toBe(true);
    });

    it("leaves the axes alone after the first publication but still edits values and rows", async () => {
      serveReads(view({ status: "published", firstPublishedAt: "2026-10-01T10:00:00+00:00" }));
      const { form } = await start();

      form.addAxis();
      form.removeAxis(0);
      form.moveAxis(0, 1);
      expect(form.form.value.variantAxes).toEqual(["Kolor"]);

      form.setAxis(0, "kolor");
      form.setValue(0, 0, "Czarne");
      form.addVariant();
      form.removeVariant(1);
      expect(form.form.value.variantAxes).toEqual(["kolor"]);
      expect(form.form.value.variants).toEqual([
        { variantId: "v-1", values: ["Czarne"] },
        { variantId: null, values: [""] },
      ]);
    });

    it("changes nothing without the permission to manage products", async () => {
      const { form } = await start({ canManage: false });

      form.addAxis();
      form.setAxis(0, "Barwa");
      form.setValue(0, 0, "x");
      form.addVariant();
      form.removeVariant(0);

      expect(form.dirty.value).toBe(false);
    });

    it("shows the 422 at the axis, the row and the value, and forgets them when the rows move", async () => {
      const { form } = await start();
      form.setValue(1, 0, "czarne");
      mocks.put.mockResolvedValue(
        problem(422, {
          violations: [
            { propertyPath: "variantAxes[0]", title: "Nazwa osi jest za długa." },
            { propertyPath: "variants[1].values[0]", title: "Ta kombinacja już istnieje." },
            { propertyPath: "variants[1].values", title: "Zła liczba wartości." },
            { propertyPath: "variants", title: "Za dużo wariantów." },
          ],
        }),
      );

      await form.save();

      expect(form.errorsAt("variants[1].values[0]")).toEqual(["Ta kombinacja już istnieje."]);
      expect(form.errorsAt("variants[0]")).toEqual([]);
      expect(form.errorsExactly("variants[1].values")).toEqual(["Zła liczba wartości."]);
      expect(form.variantsErrors.value).toEqual(["Za dużo wariantów."]);

      form.removeVariant(0);

      expect(form.errorsAt("variants[1]")).toEqual([]);
      expect(form.errorsAt("variantAxes[0]")).toEqual([]);
    });
  });
});
