import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
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
  const onLateSaved = vi.fn();
  let form!: ReturnType<typeof useProductForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        form = useProductForm({ productId: () => id.value, canManage: () => canManage.value, onCreated, onLateSaved });
        return () => null;
      },
    }),
  );
  await flushPromises();
  return { form, id, onCreated, onLateSaved, wrapper };
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

  describe("discarding the changes", () => {
    it("restores the product as the API sent it, without a request", async () => {
      const { form } = await start();
      mocks.get.mockClear();

      form.form.value.name = "Inne buty";
      form.removeImage(0);
      form.addAxis();
      form.discard();

      expect(form.dirty.value).toBe(false);
      expect(form.form.value.name).toBe("Buty biegowe");
      expect(form.form.value.images.map((image) => image.key)).toEqual(["originals/a.jpg", "originals/b.jpg"]);
      expect(form.form.value.variantAxes).toEqual(["Kolor"]);
      expect(form.form.value.images[0]!.variants).toEqual(preview.variants);
      expect(mocks.get).not.toHaveBeenCalled();
    });

    it("restores the product as it was last saved and clears the messages", async () => {
      const { form } = await start();
      mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Podaj nazwę." }] }));
      form.form.value.name = "";
      await form.save();
      expect(form.errorsAt("name")).toEqual(["Podaj nazwę."]);

      form.discard();

      expect(form.form.value.name).toBe("Buty biegowe");
      expect(form.errorsAt("name")).toEqual([]);
    });

    it("does nothing while a request runs", async () => {
      const { form } = await start();
      const pending = deferred();
      mocks.put.mockReturnValue(pending.promise);
      form.form.value.name = "Inne buty";
      const saving = form.save();

      form.discard();

      expect(form.form.value.name).toBe("Inne buty");
      pending.resolve(ok(view({ name: "Inne buty", version: 4 })));
      await saving;
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
      expect(form.message.value).toBe("Produkt zapisany. Ustaw cenę, inaczej produkt będzie w sklepie niedostępny.");
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

    it.each([
      ["a 422", () => mocks.post.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Podaj nazwę." }] }))],
      ["a 500", () => mocks.post.mockResolvedValue(problem(500))],
      ["a request that does not reach the API", () => mocks.post.mockRejectedValue(new TypeError("network down"))],
    ])("does not navigate when the post ends with %s", async (_name, answer) => {
      const { form, onCreated } = await start({ id: null });
      answer();

      await form.save();

      expect(onCreated).not.toHaveBeenCalled();
      expect(form.view.value).toBeNull();
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
      expect(form.message.value).toBe("Produkt zapisany.");
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

  describe("an upload that outlives the product it was sent for (B4)", () => {
    const stored = (key: string) => ok({ key, preview: { size: "thumbnail", aspectRatio: "1:1", variants: {} } }, 201);

    it("does not add the image to the next product, and leaves no files in the list", async () => {
      const { form, id } = await start();
      const sending = deferred();
      mocks.post.mockReturnValue(sending.promise);
      form.upload.addFiles([new File(["x"], "c.jpg"), new File(["y"], "d.jpg")]);
      await flushPromises();
      expect(form.upload.uploading.value).toBe(true);

      serveReads(view({ id: "p-2", name: "Kurtka", images: [] }));
      id.value = "p-2";
      await flushPromises();
      sending.resolve(stored("originals/c.jpg"));
      await flushPromises();

      expect(form.form.value.images).toEqual([]);
      expect(form.view.value?.id).toBe("p-2");
      expect(form.upload.entries.value).toEqual([]);
      expect(form.upload.uploading.value).toBe(false);
      expect(form.dirty.value).toBe(false);
      expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it("does not add the image to a new product opened in its place", async () => {
      const { form, id } = await start();
      const sending = deferred();
      mocks.post.mockReturnValue(sending.promise);
      form.upload.addFiles([new File(["x"], "c.jpg")]);
      await flushPromises();

      id.value = null;
      await flushPromises();
      sending.resolve(stored("originals/c.jpg"));
      await flushPromises();

      expect(form.view.value).toBeNull();
      expect(form.form.value.images).toEqual([]);
      expect(form.upload.entries.value).toEqual([]);
      expect(form.dirty.value).toBe(false);
    });

    it("lets a file chosen after the switch upload into the new product", async () => {
      const { form, id } = await start();
      mocks.post.mockReturnValueOnce(new Promise(() => undefined));
      form.upload.addFiles([new File(["x"], "c.jpg")]);
      await flushPromises();

      id.value = null;
      await flushPromises();
      mocks.post.mockResolvedValueOnce(stored("originals/e.jpg"));
      form.upload.addFiles([new File(["z"], "e.jpg")]);
      await flushPromises();

      expect(form.form.value.images.map((image) => image.key)).toEqual(["originals/e.jpg"]);
    });

    it("keeps the upload when the route only moves to the product that was just created", async () => {
      const { form, id } = await start({ id: null });
      mocks.post.mockResolvedValueOnce(ok(view({ images: [] }), 201));
      await form.save();
      const sending = deferred();
      mocks.post.mockReturnValue(sending.promise);
      form.upload.addFiles([new File(["x"], "c.jpg")]);
      await flushPromises();

      id.value = "p-1";
      await flushPromises();
      sending.resolve(stored("originals/c.jpg"));
      await flushPromises();

      expect(form.form.value.images.map((image) => image.key)).toEqual(["originals/c.jpg"]);
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
    it("keeps the draft after product-modified, says so, reads nothing, and shows the server state only after „Odrzuć moje zmiany i pokaż stan z serwera”", async () => {
      const { form } = await start();
      form.form.value.name = "Moje zmiany";
      mocks.put.mockResolvedValue(conflict("product-modified"));
      mocks.get.mockClear();

      await form.save();

      expect(form.error.value).toBe("Produkt został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.");
      expect(form.form.value.name).toBe("Moje zmiany");
      expect(form.view.value?.version).toBe(3);
      expect(form.dirty.value).toBe(true);
      expect(mocks.get).not.toHaveBeenCalled();
      expect(form.safety.restoreOffered.value).toBe(true);

      serveReads(view({ version: 7, name: "Cudza zmiana" }));
      await form.safety.restore();

      expect(form.error.value).toBeNull();
      expect(form.form.value.name).toBe("Cudza zmiana");
      expect(form.view.value?.version).toBe(7);
      expect(form.dirty.value).toBe(false);
      expect(form.safety.restoreOffered.value).toBe(false);
    });

    it("shows product-modified of a publication the same way", async () => {
      const { form } = await start();
      mocks.post.mockResolvedValue(conflict("product-modified"));
      mocks.get.mockClear();

      await form.publish();

      expect(form.error.value).toBe("Produkt został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.");
      expect(mocks.get).not.toHaveBeenCalled();
      expect(form.safety.restoreOffered.value).toBe(true);
    });

    it("offers the server state after no other 409", async () => {
      const { form } = await start();
      form.form.value.name = "Inne";
      mocks.put.mockResolvedValue(conflict("slug-taken"));

      await form.save();

      expect(form.errorsAt("slug")).toEqual(["Ten slug ma już inny produkt. Podaj własny slug."]);
      expect(form.safety.restoreOffered.value).toBe(false);
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
      [400, "HTTP 400"],
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

    it.each([
      ["a network failure", () => mocks.put.mockRejectedValue(new Error("offline"))],
      ["a 500", () => mocks.put.mockResolvedValue(problem(500))],
      ["a 503", () => mocks.put.mockResolvedValue(problem(503))],
    ])("does not say that the save failed after %s: the result is unknown and the form is kept", async (_name, answer) => {
      const { form } = await start();
      form.form.value.name = "Moje zmiany";
      answer();

      await form.save();

      expect(form.error.value).toBeNull();
      expect(form.form.value.name).toBe("Moje zmiany");
      expect(form.busy.value).toBe(false);
      expect(form.safety.unknown.value?.text).toBe("Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.");
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

  describe("the draft and the requests of the form (stage 2: B2, B3 and B4)", () => {
    const UNKNOWN = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
    const NOT_ON_SERVER = "Zapisu nie widać na serwerze. Może jeszcze dotrzeć.";
    const OTHER_VERSION = "Na serwerze jest inna wersja niż ta, którą edytujesz.";
    const LATE = "Wcześniejszy zapis został wykonany.";
    const MODIFIED = "Produkt został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.";
    const PUBLISHED = { status: "published", firstPublishedAt: "2026-11-02T10:00:00+00:00", slugLocked: true } as const;

    const wrappers: Awaited<ReturnType<typeof start>>["wrapper"][] = [];

    beforeEach(() => {
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    });

    afterEach(() => {
      wrappers.splice(0).forEach((mounted) => mounted.unmount());
      vi.useRealTimers();
    });

    const advance = (ms: number) => vi.advanceTimersByTimeAsync(ms);

    async function open(product: ProductEditView | null = view(), options: Setup = {}) {
      serveReads(product);
      const result = await start(options);
      wrappers.push(result.wrapper);
      return result;
    }

    type Form = Awaited<ReturnType<typeof start>>["form"];

    /** Holds every call of a mock; `calls[n].resolve` answers the n-th. */
    function holdCalls(mock: typeof mocks.put) {
      const calls: { resolve: (value: unknown) => void; reject: (reason: unknown) => void }[] = [];
      mock.mockImplementation(
        () =>
          new Promise((resolve, reject) => {
            calls.push({ resolve, reject });
          }),
      );
      return calls;
    }

    /** Whether the promise has settled; read after the timers and the answers the test gave were processed. */
    function trackSettled(promise: Promise<unknown>) {
      const state = { settled: false };
      void promise.then(
        () => (state.settled = true),
        () => (state.settled = true),
      );
      return state;
    }

    /** Every editor of the draft that the form offers is tried once; the caller reads back the draft. */
    function tryToEdit(form: Form) {
      form.moveImage(0, 1);
      form.removeImage(1);
      form.addAxis();
      form.setAxis(0, "Rozmiar");
      form.moveAxis(0, 1);
      form.removeAxis(0);
      form.addVariant();
      form.removeVariant(1);
      form.setValue(0, 0, "zielone");
    }

    type Kind = "save" | "publish" | "unpublish";
    const KINDS: Kind[] = ["save", "publish", "unpublish"];

    /** The product the action starts from, what the server has once it was done, and what it has when it was not. */
    function scenario(kind: Kind) {
      const before = kind === "unpublish" ? view(PUBLISHED) : view();
      const after =
        kind === "save"
          ? view({ name: "Pierwsza", version: 4 })
          : kind === "publish"
            ? view({ ...PUBLISHED, version: 4 })
            : view({ ...PUBLISHED, status: "draft", version: 4 });
      const mock = kind === "save" ? mocks.put : mocks.post;
      const run = (form: Form) => (kind === "save" ? form.save() : kind === "publish" ? form.publish() : form.unpublish());
      const edit = (form: Form) => {
        if (kind === "save") {
          form.form.value.name = "Pierwsza";
        }
      };
      return { before, after, mock, run, edit };
    }

    /** The action started and held. */
    async function begin(kind: Kind) {
      const chosen = scenario(kind);
      const result = await open(chosen.before);
      chosen.edit(result.form);
      const calls = holdCalls(chosen.mock);
      const running = chosen.run(result.form);
      return { ...result, ...chosen, calls, running };
    }

    /** The action ended with an error that does not reach the API: the result is unknown. */
    async function beginUnknown(kind: Kind) {
      const chosen = scenario(kind);
      const result = await open(chosen.before);
      chosen.edit(result.form);
      chosen.mock.mockRejectedValueOnce(new TypeError("network down"));
      expect(await chosen.run(result.form)).toBe("failure");
      chosen.mock.mockReset();
      return { ...result, ...chosen };
    }

    const writes = () => mocks.put.mock.calls.length + mocks.post.mock.calls.length;

    describe("B2: the lock while a request runs", () => {
      it.each(KINDS)("locks the editors of the draft from the request to its answer (%s), and a second action sends nothing", async (kind) => {
        const { form, calls, running, after } = await begin(kind);
        const draft = JSON.stringify(form.form.value);

        tryToEdit(form);

        expect(JSON.stringify(form.form.value)).toBe(draft);
        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        expect(form.canPublish.value).toBe(false);
        expect(form.canUnpublish.value).toBe(false);
        expect(await form.save()).toBeNull();
        expect(await form.publish()).toBeNull();
        expect(await form.unpublish()).toBeNull();
        expect(writes()).toBe(1);
        calls[0]!.resolve(ok(after));

        expect(await running).toBe("success");
        expect(form.locked.value).toBe(false);
        expect(form.busy.value).toBe(false);
      });

      it.each([
        ["a 422", () => mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Podaj nazwę." }] }))],
        ["a 409 of the version", () => mocks.put.mockResolvedValue(conflict("product-modified"))],
      ])("unlocks the form with the values that were sent after %s", async (_name, answer) => {
        const { form } = await open();
        form.form.value.name = "";
        form.form.value.shortDescription = "Nowy opis.";
        form.removeImage(0);
        answer();

        expect(await form.save()).toBe("failure");

        expect(form.locked.value).toBe(false);
        expect(form.form.value.name).toBe("");
        expect(form.form.value.shortDescription).toBe("Nowy opis.");
        expect(form.form.value.images.map((image) => image.key)).toEqual(["originals/b.jpg"]);
        expect(form.dirty.value).toBe(true);
      });

      it("locks the editors and the actions while „Odśwież” reads, and the card of prices is not part of the draft", async () => {
        const { form } = await open();
        const reads = holdCalls(mocks.get);
        const refreshing = form.refresh();
        const draft = JSON.stringify(form.form.value);

        tryToEdit(form);

        expect(JSON.stringify(form.form.value)).toBe(draft);
        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        expect(form.canPublish.value).toBe(false);
        expect(await form.save()).toBeNull();
        expect(await form.publish()).toBeNull();
        expect(writes()).toBe(0);
        reads[0]!.resolve(ok(view({ version: 5 })));
        await refreshing;
        expect(form.locked.value).toBe(false);
        expect(form.canPublish.value).toBe(true);
      });

      it("ignores the answer of a save that comes after another product was opened: no change, no message, no move, no lock", async () => {
        const { form, id, onCreated, onLateSaved } = await open();
        form.form.value.name = "Pierwsza";
        const puts = holdCalls(mocks.put);
        const saving = form.save();
        serveReads(view({ id: "p-2", name: "Drugi", slug: "drugi", version: 7 }));

        id.value = "p-2";
        await flushPromises();
        expect(form.locked.value).toBe(false);
        expect(form.busy.value).toBe(false);
        puts[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));

        expect(await saving).toBeNull();
        expect(form.view.value?.id).toBe("p-2");
        expect(form.form.value.name).toBe("Drugi");
        expect(form.view.value?.version).toBe(7);
        expect(form.message.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(onCreated).not.toHaveBeenCalled();
        expect(onLateSaved).not.toHaveBeenCalled();
      });

      it("ignores the answer of a create that comes after the form moved to an existing product or to a new one again", async () => {
        const { form, id, onCreated, onLateSaved } = await open(null, { id: null });
        form.form.value.name = "Nowy";
        const posts = holdCalls(mocks.post);
        const saving = form.save();

        serveReads(view({ id: "p-2", name: "Drugi", slug: "drugi", version: 7 }));
        id.value = "p-2";
        await flushPromises();
        expect(form.locked.value).toBe(false);
        id.value = null;
        await flushPromises();
        posts[0]!.resolve(ok(view({ id: "created", name: "Nowy", version: 1 }), 201));

        expect(await saving).toBeNull();
        expect(form.view.value).toBeNull();
        expect(form.form.value.name).toBe("");
        expect(form.message.value).toBeNull();
        expect(onCreated).not.toHaveBeenCalled();
        expect(onLateSaved).not.toHaveBeenCalled();
      });
    });

    describe("B3: „Odśwież” and the other reads that replace the form", () => {
      it("says „Odświeżamy…”, keeps the draft meanwhile and replaces it only with the answer 200", async () => {
        const { form } = await open();
        form.form.value.name = "Moje";
        const reads = holdCalls(mocks.get);

        const refreshing = form.refresh();

        expect(form.safety.status.value).toBe("Odświeżamy…");
        expect(form.form.value.name).toBe("Moje");
        reads[0]!.resolve(ok(view({ name: "Cudze", version: 9 })));
        await refreshing;

        expect(form.form.value.name).toBe("Cudze");
        expect(form.view.value?.version).toBe(9);
        expect(form.dirty.value).toBe(false);
        expect(form.safety.status.value).toBe("");
      });

      it("gives up 10 s after the request: the form is unlocked as it was, says so, and the answer that comes later changes nothing, also after a field was changed", async () => {
        const { form } = await open();
        form.form.value.name = "Moje";
        const reads = holdCalls(mocks.get);
        const refreshing = form.refresh();
        const state = trackSettled(refreshing);

        await advance(9_999);
        expect(state.settled).toBe(false);
        await advance(1);

        expect(state.settled).toBe(true);
        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
        form.form.value.name = "Jeszcze inne";
        reads[0]!.resolve(ok(view({ name: "Cudze", version: 9 })));
        await refreshing;

        expect(form.form.value.name).toBe("Jeszcze inne");
        expect(form.view.value?.version).toBe(3);
        expect(form.locked.value).toBe(false);
      });

      it.each([
        ["an error", () => mocks.get.mockRejectedValue(new TypeError("network down"))],
        ["an answer other than 200", () => mocks.get.mockResolvedValue(problem(500))],
      ])("keeps the form as it was and offers to try again after %s", async (_name, fail) => {
        const { form } = await open();
        form.form.value.name = "Moje";
        fail();

        await form.refresh();

        expect(form.form.value.name).toBe("Moje");
        expect(form.view.value?.version).toBe(3);
        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
        expect(form.error.value).toBeNull();

        serveReads(view({ name: "Cudze", version: 9 }));
        expect(await form.safety.retryRead()).toBe(true);
        expect(form.form.value.name).toBe("Cudze");
        expect(form.safety.readFailed.value).toBe(false);
      });
    });

    describe("a 409 of the version (E7)", () => {
      it("reads nothing by itself, and the draft is replaced only by „Odrzuć moje zmiany i pokaż stan z serwera” after the answer 200", async () => {
        const { form } = await open();
        form.form.value.name = "Moje";
        mocks.put.mockResolvedValue(conflict("product-modified"));
        mocks.get.mockClear();
        await form.save();
        await advance(30_000);
        expect(mocks.get).not.toHaveBeenCalled();
        expect(form.error.value).toBe(MODIFIED);

        const reads = holdCalls(mocks.get);
        const restoring = form.safety.restore();
        expect(form.form.value.name).toBe("Moje");
        expect(form.locked.value).toBe(true);
        reads[0]!.resolve(ok(view({ name: "Cudze", version: 7 })));
        await restoring;

        expect(form.form.value.name).toBe("Cudze");
        expect(form.view.value?.version).toBe(7);
        expect(form.error.value).toBeNull();
        expect(form.dirty.value).toBe(false);
      });

      it.each([
        ["an error", () => mocks.get.mockRejectedValue(new TypeError("network down"))],
        ["a 500", () => mocks.get.mockResolvedValue(problem(500))],
        ["no answer within the limit", () => mocks.get.mockReturnValue(new Promise(() => {}))],
      ])("keeps the draft and the message after %s of „Odrzuć moje zmiany…”, unlocks, and offers the button again", async (_name, fail) => {
        const { form } = await open();
        form.form.value.name = "Moje";
        mocks.put.mockResolvedValue(conflict("product-modified"));
        await form.save();
        fail();

        const restoring = form.safety.restore();
        await advance(10_000);
        await restoring;

        expect(form.form.value.name).toBe("Moje");
        expect(form.view.value?.version).toBe(3);
        expect(form.error.value).toBe(MODIFIED);
        expect(form.locked.value).toBe(false);
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.safety.readFailed.value).toBe(true);
      });
    });

    describe("B4 (a): the limit of a request", () => {
      it.each(KINDS)("says after 10 s that the result is unknown, keeps the fields locked, does not abort the request, and a released success is an ordinary success (%s)", async (kind) => {
        const { form, calls, running, after, mock } = await begin(kind);

        await advance(9_999);
        expect(form.safety.unknown.value).toBeNull();
        await advance(1);

        expect(form.safety.unknown.value).toMatchObject({ pending: true, text: UNKNOWN });
        expect(form.error.value).toBeNull();
        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        expect(mock.mock.calls[0]![1]).not.toHaveProperty("signal");
        calls[0]!.resolve(ok(after));

        expect(await running).toBe("success");
        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.dirty.value).toBe(false);
      });

      it("shows the errors of a 422 that comes after the limit at their fields, without the message of an unknown result", async () => {
        const { form, calls, running } = await begin("save");
        await advance(10_000);

        calls[0]!.resolve(problem(422, { violations: [{ propertyPath: "name", title: "Za długa." }] }));

        expect(await running).toBe("failure");
        expect(form.errorsAt("name")).toEqual(["Za długa."]);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.form.value.name).toBe("Pierwsza");
      });
    });

    describe("B4 (b): an error, a 502, a 504", () => {
      const ANSWERS: [string, (mock: typeof mocks.put) => void][] = [
        ["a request that does not reach the API", (mock) => mock.mockRejectedValue(new TypeError("network down"))],
        ["a 502", (mock) => mock.mockResolvedValue(problem(502))],
        ["a 504", (mock) => mock.mockResolvedValue(problem(504))],
        ["a 500", (mock) => mock.mockResolvedValue(problem(500))],
      ];

      describe.each(KINDS)("%s", (kind) => {
        it.each(ANSWERS)("makes the result unknown after %s, unlocks with the draft, and says nothing of a failure", async (_name, answer) => {
          const chosen = scenario(kind);
          const { form } = await open(chosen.before);
          chosen.edit(form);
          answer(chosen.mock);

          expect(await chosen.run(form)).toBe("failure");

          expect(form.error.value).toBeNull();
          expect(form.safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: UNKNOWN });
          expect(form.locked.value).toBe(false);
          expect(form.view.value?.version).toBe(3);
          expect(form.dirty.value).toBe(kind === "save");
        });
      });

      it("puts the errors of a 422 at the fields without that message", async () => {
        const { form } = await open();
        form.form.value.name = "";
        mocks.put.mockResolvedValue(problem(422, { violations: [{ propertyPath: "name", title: "Podaj nazwę." }] }));

        await form.save();

        expect(form.errorsAt("name")).toEqual(["Podaj nazwę."]);
        expect(form.safety.unknown.value).toBeNull();
      });
    });

    describe("B4 (c): „Sprawdź stan”", () => {
      it.each(KINDS)("takes the product from the server when it equals the draft and shows the status the action gives, and sends no write (%s)", async (kind) => {
        const { form, after, mock } = await beginUnknown(kind);
        mocks.get.mockResolvedValue(ok(after));

        await form.safety.check();

        expect(form.view.value).toMatchObject({ version: 4, status: after.status });
        expect(form.dirty.value).toBe(false);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.safety.status.value).toBe("Stan na serwerze odpowiada formularzowi.");
        expect(form.locked.value).toBe(false);
        expect(mock).not.toHaveBeenCalled();
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it.each(KINDS)("leaves the draft, the base and the version when the server has the same version without the change, and says so (%s)", async (kind) => {
        const { form, before } = await beginUnknown(kind);
        mocks.get.mockResolvedValue(ok(before));

        await form.safety.check();

        expect(form.view.value?.version).toBe(3);
        expect(form.view.value?.status).toBe(before.status);
        expect(form.form.value.name).toBe(kind === "save" ? "Pierwsza" : "Buty biegowe");
        expect(form.dirty.value).toBe(kind === "save");
        expect(form.safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER });
        expect(form.locked.value).toBe(false);
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it.each(KINDS)("leaves them too and offers the server state when the version differs (%s)", async (kind) => {
        const { form, before } = await beginUnknown(kind);
        mocks.get.mockResolvedValue(ok({ ...before, name: "Cudza zmiana", version: 6 }));

        await form.safety.check();

        expect(form.view.value?.version).toBe(3);
        expect(form.form.value.name).toBe(kind === "save" ? "Pierwsza" : "Buty biegowe");
        expect(form.safety.unknown.value).toMatchObject({ kind: "otherVersion", text: OTHER_VERSION });
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it.each(KINDS.flatMap((kind) => [
        [kind, "an error", () => mocks.get.mockRejectedValue(new TypeError("network down"))],
        [kind, "a 500", () => mocks.get.mockResolvedValue(problem(500))],
        [kind, "the limit", () => mocks.get.mockReturnValue(new Promise(() => {}))],
      ] as const))("keeps the draft and the message of the unknown result after %s: %s", async (kind, _name, fail) => {
        const { form } = await beginUnknown(kind);
        fail();

        const checking = form.safety.check();
        await advance(10_000);
        await checking;

        expect(form.view.value?.version).toBe(3);
        expect(form.form.value.name).toBe(kind === "save" ? "Pierwsza" : "Buty biegowe");
        expect(form.safety.unknown.value).toMatchObject({ kind: "unknown", text: UNKNOWN });
        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
      });

      it("locks the fields while it reads, and the read is not an automatic write", async () => {
        const { form, after } = await beginUnknown("save");
        const reads = holdCalls(mocks.get);

        const checking = form.safety.check();
        const draft = JSON.stringify(form.form.value);
        tryToEdit(form);

        expect(JSON.stringify(form.form.value)).toBe(draft);
        expect(form.locked.value).toBe(true);
        expect(form.canSave.value).toBe(false);
        reads[0]!.resolve(ok(after));
        await checking;
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (d): what counts as the same request", () => {
      /** The save of the name „Pierwsza” (and of what `edit` changes) ended unknown; the read answers `served`. */
      async function checkSaved(edit: (form: Form) => void, answer: ProductEditView) {
        const { form } = await open();
        form.form.value.name = "Pierwsza";
        edit(form);
        mocks.put.mockRejectedValueOnce(new TypeError("network down"));
        await form.save();
        mocks.get.mockResolvedValue(ok(answer));
        await form.safety.check();
        return form;
      }
      const served = (overrides: Partial<ProductEditView> = {}) => view({ name: "Pierwsza", version: 4, ...overrides });
      const imageOf = (key: string, alt: string) => ({ key, alt, preview });

      it("is the same when nothing differs", async () => {
        const form = await checkSaved(() => {}, served());

        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value).toBeNull();
      });

      it.each([
        ["the short description", { shortDescription: "Inny opis." }],
        ["the main category", { mainCategoryId: "c-extra" }],
        ["the additional categories", { additionalCategoryIds: [] }],
        ["the tax class", { taxClass: "reduced" }],
        ["the slug", { slug: "inny-slug" }],
        ["the alt of an image", { images: [imageOf("originals/a.jpg", "Inny przód"), imageOf("originals/b.jpg", "Bok")] }],
        ["the key of an image", { images: [imageOf("originals/c.jpg", "Przód"), imageOf("originals/b.jpg", "Bok")] }],
        ["the order of the images", { images: [imageOf("originals/b.jpg", "Bok"), imageOf("originals/a.jpg", "Przód")] }],
        ["a name of an axis", { variantAxes: ["Rozmiar"] }],
        [
          "a value of a variant",
          {
            variants: [
              { variantId: "v-1", values: ["czarne"] },
              { variantId: "v-2", values: ["czerwone"] },
            ],
          },
        ],
        [
          "the order of the variants",
          {
            variants: [
              { variantId: "v-2", values: ["białe"] },
              { variantId: "v-1", values: ["czarne"] },
            ],
          },
        ],
      ] as [string, Partial<ProductEditView>][])("is not the same when one field differs: %s", async (_name, difference) => {
        const form = await checkSaved(() => {}, served(difference));

        expect(form.view.value?.version).toBe(3);
        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when the order of the additional categories differs", async () => {
        const form = await checkSaved((current) => (current.form.value.additionalCategoryIds = ["c-extra", "c-second"]), served({ additionalCategoryIds: ["c-second", "c-extra"] }));

        expect(form.view.value?.version).toBe(3);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is the same when the additional categories are in the order of the draft", async () => {
        const form = await checkSaved((current) => (current.form.value.additionalCategoryIds = ["c-extra", "c-second"]), served({ additionalCategoryIds: ["c-extra", "c-second"] }));

        expect(form.view.value?.version).toBe(4);
        expect(form.safety.unknown.value).toBeNull();
      });

      it("is not the same when a trimmed space is the only difference: a text is sent as it was typed", async () => {
        const trailing = await checkSaved((current) => (current.form.value.name = "Pierwsza "), served());
        expect(trailing.view.value?.version).toBe(3);
        expect(trailing.form.value.name).toBe("Pierwsza ");
        expect(trailing.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when the server has a space the draft does not", async () => {
        const form = await checkSaved(() => {}, served({ name: "Pierwsza " }));

        expect(form.view.value?.version).toBe(3);
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it.each([
        ["the slug the server derived", "buty-biegowe"],
        ["an empty slug", ""],
      ])("is never the same for an empty slug in the draft, whatever the server has (%s)", async (_name, slug) => {
        const form = await checkSaved((current) => (current.form.value.slug = ""), served({ slug }));

        expect(form.view.value?.version).toBe(3);
        expect(form.form.value.slug).toBe("");
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
        expect(form.safety.status.value).toBe("");
      });

      it("is the same for a row of a variant that is new in the draft and has an id on the server, and not when its values differ", async () => {
        const added = (current: Form) => {
          current.addVariant();
          current.setValue(2, 0, "zielone");
        };
        const rows = (value: string) => [
          { variantId: "v-1", values: ["czarne"] },
          { variantId: "v-2", values: ["białe"] },
          { variantId: "v-3", values: [value] },
        ];

        const same = await checkSaved(added, served({ variants: rows("zielone") }));
        expect(same.view.value?.version).toBe(4);
        expect(same.safety.unknown.value).toBeNull();
      });

      it("is not the same for a row that is new in the draft when the server has another value in it or no such row", async () => {
        const added = (current: Form) => {
          current.addVariant();
          current.setValue(2, 0, "zielone");
        };
        const other = await checkSaved(
          added,
          served({
            variants: [
              { variantId: "v-1", values: ["czarne"] },
              { variantId: "v-2", values: ["białe"] },
              { variantId: "v-3", values: ["żółte"] },
            ],
          }),
        );
        expect(other.view.value?.version).toBe(3);
        expect(other.safety.unknown.value?.kind).toBe("otherVersion");

        const missing = await checkSaved(added, served());
        expect(missing.view.value?.version).toBe(3);
        expect(missing.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is not the same when no request can be built from the draft, and the check puts no error at the field", async () => {
        const form = await checkSaved((current) => (current.form.value.slug = ""), served());

        expect(form.errorsAt("slug")).toEqual([]);
        expect(form.error.value).toBeNull();
        expect(form.view.value?.version).toBe(3);
      });

      it("is not the same for a publication whose fields equal the server's when the server still has the draft status", async () => {
        const { form } = await beginUnknown("publish");
        mocks.get.mockResolvedValue(ok(view({ version: 4 })));

        await form.safety.check();

        expect(form.view.value).toMatchObject({ version: 3, status: "draft" });
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
        expect(form.safety.status.value).toBe("");
      });

      it("is not the same for a withdrawal whose fields equal the server's when the server still has the published status", async () => {
        const { form } = await beginUnknown("unpublish");
        mocks.get.mockResolvedValue(ok(view({ ...PUBLISHED, version: 4 })));

        await form.safety.check();

        expect(form.view.value).toMatchObject({ version: 3, status: "published" });
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
      });

      it("is the same for a publication when the server has the published status and the fields of the draft", async () => {
        const { form } = await beginUnknown("publish");
        mocks.get.mockResolvedValue(ok(view({ ...PUBLISHED, version: 4 })));

        await form.safety.check();

        expect(form.view.value).toMatchObject({ version: 4, status: "published", slugLocked: true });
        expect(form.safety.unknown.value).toBeNull();
      });
    });

    describe("B4 (e) and (f): the draft changed after the unlock", () => {
      it.each(KINDS)("(e) keeps what was typed after the unlock, whatever „Sprawdź stan” finds, without the message of a match and without a write (%s)", async (kind) => {
        const { form, after } = await beginUnknown(kind);
        form.form.value.name = "Druga";
        mocks.get.mockResolvedValue(ok(after));

        await form.safety.check();

        expect(form.form.value.name).toBe("Druga");
        expect(form.dirty.value).toBe(true);
        expect(form.view.value?.version).toBe(3);
        expect(form.safety.status.value).toBe("");
        expect(mocks.put).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
      });

      it("(f) keeps the changed field after the earlier save is done and the next one gets a 409, and reads nothing by itself", async () => {
        const { form, calls, running, onLateSaved } = await begin("save");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(view({ version: 3 })));
        await form.safety.check();
        expect(form.safety.unknown.value?.kind).toBe("missing");
        form.form.value.name = "Druga";
        const second = form.save();
        mocks.get.mockClear();

        calls[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));
        await running;
        calls[1]!.resolve(conflict("product-modified"));
        await second;

        expect(form.form.value.name).toBe("Druga");
        expect(form.view.value?.version).toBe(3);
        expect(form.error.value).toBe(MODIFIED);
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(onLateSaved).toHaveBeenCalledTimes(1);
        await advance(30_000);
        expect(mocks.get).not.toHaveBeenCalled();
      });
    });

    describe("B4 (g): „Odrzuć moje zmiany i pokaż stan z serwera” after a check", () => {
      it("replaces the draft only with the answer 200", async () => {
        const { form } = await beginUnknown("save");
        mocks.get.mockResolvedValue(ok(view({ name: "Cudza zmiana", version: 6 })));
        await form.safety.check();
        const reads = holdCalls(mocks.get);

        const restoring = form.safety.restore();
        expect(form.form.value.name).toBe("Pierwsza");
        reads[0]!.resolve(ok(view({ name: "Cudza zmiana", version: 6 })));
        await restoring;

        expect(form.form.value.name).toBe("Cudza zmiana");
        expect(form.view.value?.version).toBe(6);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.safety.restoreOffered.value).toBe(false);
      });

      it("keeps the draft and the messages after an error and after the limit", async () => {
        const { form } = await beginUnknown("save");
        mocks.get.mockResolvedValue(ok(view({ name: "Cudza zmiana", version: 6 })));
        await form.safety.check();
        mocks.get.mockRejectedValue(new TypeError("network down"));

        await form.safety.restore();

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.safety.unknown.value?.kind).toBe("otherVersion");
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.locked.value).toBe(false);

        mocks.get.mockReturnValue(new Promise(() => {}));
        const again = form.safety.restore();
        await advance(10_000);
        await again;

        expect(form.form.value.name).toBe("Pierwsza");
        expect(form.locked.value).toBe(false);
        expect(form.safety.readFailed.value).toBe(true);
      });
    });

    describe("B4 (h) and (i): the earlier request answers during or after the check", () => {
      it.each(KINDS)("(h) keeps the draft and leaves nothing locked when it answers before the answer of the read (%s)", async (kind) => {
        const { form, calls, running, after, before } = await begin(kind);
        await advance(10_000);
        const reads = holdCalls(mocks.get);
        const checking = form.safety.check();

        calls[0]!.resolve(ok(after));
        await running;
        expect(form.view.value?.version).toBe(3);
        expect(form.form.value.name).toBe(kind === "save" ? "Pierwsza" : "Buty biegowe");
        reads[0]!.resolve(ok(before));
        await checking;

        expect(form.view.value?.version).toBe(3);
        expect(form.form.value.name).toBe(kind === "save" ? "Pierwsza" : "Buty biegowe");
        expect(form.locked.value).toBe(false);
        expect(form.safety.writing.value).toBe(false);
      });

      it.each(KINDS)("(h) keeps the draft and leaves nothing locked when it answers after the answer of the read (%s)", async (kind) => {
        const { form, calls, running, before } = await begin(kind);
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(before));
        await form.safety.check();

        calls[0]!.reject(new TypeError("network down"));
        await running;

        expect(form.form.value.name).toBe(kind === "save" ? "Pierwsza" : "Buty biegowe");
        expect(form.locked.value).toBe(false);
        expect(form.safety.unknown.value?.kind).toBe("missing");
      });

      it("(i) does not take the lock of the newer save and shows no message of the earlier one when its error and end come after the newer save started", async () => {
        const { form, calls, running } = await begin("save");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(view({ version: 3 })));
        await form.safety.check();
        form.form.value.name = "Druga";
        const second = form.save();
        expect(form.locked.value).toBe(true);

        calls[0]!.reject(new TypeError("network down"));
        await running;

        expect(form.locked.value).toBe(true);
        expect(form.busy.value).toBe(true);
        expect(form.safety.unknown.value).toBeNull();
        expect(form.error.value).toBeNull();
        calls[1]!.resolve(ok(view({ name: "Druga", version: 4 })));
        expect(await second).toBe("success");
        expect(form.locked.value).toBe(false);
      });
    });

    describe("B4 (j): the late success of the earlier request", () => {
      async function afterCheck() {
        const result = await begin("save");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(view({ version: 3 })));
        await result.form.safety.check();
        result.form.form.value.name = "Druga";
        const second = result.form.save();
        return { ...result, second };
      }

      it("only says so in a toast after the success of the newer save, and changes no field, version or dirty flag", async () => {
        const { form, calls, running, second, onLateSaved } = await afterCheck();
        calls[1]!.resolve(ok(view({ name: "Druga", version: 5 })));
        await second;

        calls[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));
        await running;

        expect(onLateSaved).toHaveBeenCalledWith(LATE);
        expect(form.form.value.name).toBe("Druga");
        expect(form.view.value?.version).toBe(5);
        expect(form.dirty.value).toBe(false);
      });

      it("keeps the errors of the newer save after its 422", async () => {
        const { form, calls, running, second, onLateSaved } = await afterCheck();
        calls[1]!.resolve(problem(422, { violations: [{ propertyPath: "name", title: "Za długa." }] }));
        await second;

        calls[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));
        await running;

        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.errorsAt("name")).toEqual(["Za długa."]);
        expect(form.form.value.name).toBe("Druga");
        expect(form.view.value?.version).toBe(3);
        expect(form.dirty.value).toBe(true);
      });

      it("keeps the lock of the newer save while it runs", async () => {
        const { form, calls, running, second, onLateSaved } = await afterCheck();

        calls[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));
        await running;

        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.locked.value).toBe(true);
        expect(form.form.value.name).toBe("Druga");
        calls[1]!.resolve(ok(view({ name: "Druga", version: 5 })));
        await second;
      });

      it("says nothing when the staff member moved to another product, and changes nothing there", async () => {
        const { form, id, calls, running, second, onLateSaved } = await afterCheck();
        calls[1]!.resolve(ok(view({ name: "Druga", version: 5 })));
        await second;
        serveReads(view({ id: "p-2", name: "Drugi", slug: "drugi", version: 2 }));
        id.value = "p-2";
        await flushPromises();

        calls[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));
        await running;

        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.form.value.name).toBe("Drugi");
        expect(form.view.value?.version).toBe(2);
        expect(form.locked.value).toBe(false);
      });

      it.each(["publish", "unpublish"] as const)("says so in a toast for the late success of a %s and leaves the form as the newer request made it", async (kind) => {
        const { form, calls, running, after, onLateSaved } = await begin(kind);
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(scenario(kind).before));
        await form.safety.check();
        form.form.value.name = "Druga";

        calls[0]!.resolve(ok(after));
        await running;

        expect(onLateSaved).toHaveBeenCalledWith(LATE);
        expect(form.form.value.name).toBe("Druga");
        expect(form.view.value?.version).toBe(3);
        expect(form.view.value?.status).toBe(scenario(kind).before.status);
        expect(form.dirty.value).toBe(true);
      });
    });

    describe("B4 (k): moving to another product", () => {
      it.each(KINDS)("ignores the answers of a request in progress, with no toast, no message, no move and no lock (%s)", async (kind) => {
        const { form, id, calls, running, after, onCreated, onLateSaved } = await begin(kind);
        await advance(10_000);
        expect(form.safety.unknown.value).not.toBeNull();
        serveReads(view({ id: "p-2", name: "Drugi", slug: "drugi", version: 2 }));
        id.value = "p-2";
        await flushPromises();

        expect(form.safety.unknown.value).toBeNull();
        expect(form.locked.value).toBe(false);
        calls[0]!.resolve(ok(after));
        await running;

        expect(onCreated).not.toHaveBeenCalled();
        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.form.value.name).toBe("Drugi");
        expect(form.message.value).toBeNull();
      });

      it("ignores the answer of a check that comes after that", async () => {
        const { form, id } = await beginUnknown("save");
        const reads = holdCalls(mocks.get);
        const checking = form.safety.check();
        serveReads(view({ id: "p-2", name: "Drugi", slug: "drugi", version: 2 }));
        id.value = "p-2";
        await flushPromises();

        reads[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));
        await checking;

        expect(form.form.value.name).toBe("Drugi");
        expect(form.view.value?.id).toBe("p-2");
        expect(form.locked.value).toBe(false);
      });

      it("ignores the answer of „Odśwież” that comes after that", async () => {
        const { form, id } = await open();
        form.form.value.name = "Moje";
        const reads = holdCalls(mocks.get);
        const refreshing = form.refresh();
        serveReads(view({ id: "p-2", name: "Drugi", slug: "drugi", version: 2 }));
        id.value = "p-2";
        await flushPromises();

        reads[0]!.resolve(ok(view({ name: "Cudze", version: 9 })));
        await refreshing;

        expect(form.form.value.name).toBe("Drugi");
        expect(form.view.value?.id).toBe("p-2");
        expect(form.locked.value).toBe(false);
      });
    });

    describe("discarding the draft after a 409 or an unknown result", () => {
      it("keeps the way to the server state after a 409 of the version, with the context that the version is another, and nothing unknown", async () => {
        const { form } = await open();
        form.form.value.name = "Moje";
        mocks.put.mockResolvedValue(conflict("product-modified"));
        await form.save();
        expect(form.safety.restoreOffered.value).toBe(true);

        form.discard();

        expect(form.error.value).toBeNull();
        expect(form.form.value.name).toBe("Buty biegowe");
        expect(form.safety.restoreOffered.value).toBe(true);
        expect(form.safety.restoreNote.value).toBe(OTHER_VERSION);
        expect(form.safety.unknown.value).toBeNull();
      });

      it("keeps the unknown result of a save after a 502, without the sentence that the changes stay, and „Sprawdź stan” compares the restored base", async () => {
        const { form } = await open();
        form.form.value.name = "Pierwsza";
        mocks.put.mockResolvedValue(problem(502));
        await form.save();

        form.discard();

        expect(form.form.value.name).toBe("Buty biegowe");
        expect(form.safety.unknown.value?.text).toBe("Nie wiemy, czy zapis się powiódł.");
        mocks.get.mockResolvedValue(ok(view({ name: "Pierwsza", version: 4 })));
        await form.safety.check();
        expect(form.safety.unknown.value).toMatchObject({ kind: "otherVersion" });
        expect(form.form.value.name).toBe("Buty biegowe");
        expect(form.view.value?.version).toBe(3);
      });

      it("keeps what „Sprawdź stan” found, and the answer of that save that comes later is only a toast", async () => {
        const { form, calls, running, onLateSaved } = await begin("save");
        await advance(10_000);
        mocks.get.mockResolvedValue(ok(view({ version: 3 })));
        await form.safety.check();
        expect(form.safety.unknown.value?.kind).toBe("missing");

        form.discard();

        expect(form.safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER });
        calls[0]!.resolve(ok(view({ name: "Pierwsza", version: 4 })));
        await running;
        expect(onLateSaved).toHaveBeenCalledTimes(1);
        expect(form.form.value.name).toBe("Buty biegowe");
        expect(form.view.value?.version).toBe(3);
        expect(form.dirty.value).toBe(false);
      });
    });

    describe("B4 (l): a new product", () => {
      const UNKNOWN_NEW = "Nie wiemy, czy produkt został utworzony. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.";
      const TAKEN = "Ten slug ma już inny produkt. Podaj własny slug.";

      async function startNew() {
        const result = await open(null, { id: null });
        result.form.form.value.name = "Nowy";
        return result;
      }

      async function fillNew() {
        const result = await startNew();
        const posts = holdCalls(mocks.post);
        const saving = result.form.save();
        return { ...result, posts, saving };
      }

      it("unlocks with „Odblokuj formularz”, says the product may still be created, and a changed field is kept", async () => {
        const { form, posts, saving } = await fillNew();
        await advance(10_000);
        expect(form.safety.unknown.value).toMatchObject({ pending: true, created: true, text: UNKNOWN });
        expect(form.locked.value).toBe(true);

        form.safety.unblock();

        expect(form.locked.value).toBe(false);
        expect(form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
        form.form.value.slug = "inny-slug";
        posts[0]!.resolve(ok(view({ id: "created", name: "Nowy", version: 1 }), 201));
        await saving;
        expect(form.form.value.slug).toBe("inny-slug");
      });

      it("says the same after an error or a 5xx, with the draft unlocked", async () => {
        const { form } = await startNew();
        mocks.post.mockResolvedValue(problem(502));

        await form.save();

        expect(form.safety.unknown.value?.text).toBe(UNKNOWN_NEW);
        expect(form.error.value).toBeNull();
        expect(form.locked.value).toBe(false);
        expect(form.form.value.name).toBe("Nowy");
      });

      it("shows a toast for the late success and does not change the fields or the route", async () => {
        const { form, posts, saving, onCreated, onLateSaved } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        form.form.value.slug = "inny-slug";

        posts[0]!.resolve(ok(view({ id: "created", name: "Nowy", version: 1 }), 201));
        await saving;

        expect(onLateSaved).toHaveBeenCalledWith(LATE);
        expect(onCreated).not.toHaveBeenCalled();
        expect(form.view.value).toBeNull();
        expect(form.form.value.slug).toBe("inny-slug");
        expect(form.form.value.name).toBe("Nowy");
      });

      it("adds that the earlier save may have created it to a 409 slug-taken that follows", async () => {
        const { form } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        mocks.post.mockResolvedValue(conflict("slug-taken"));

        await form.save();

        expect(form.errorsAt("slug")).toEqual([`${TAKEN} Możliwe, że utworzył go Twój wcześniejszy zapis.`]);
      });

      it("adds the same note after an earlier save that ended with an error, not only after „Odblokuj formularz”", async () => {
        const { form } = await startNew();
        mocks.post.mockRejectedValueOnce(new TypeError("network down"));
        await form.save();
        mocks.post.mockResolvedValue(conflict("slug-taken"));

        await form.save();

        expect(form.errorsAt("slug")).toEqual([`${TAKEN} Możliwe, że utworzył go Twój wcześniejszy zapis.`]);
      });

      it("adds no such note to a 409 when no earlier save ended unknown", async () => {
        const { form } = await startNew();
        mocks.post.mockResolvedValue(conflict("slug-taken"));

        await form.save();

        expect(form.errorsAt("slug")).toEqual([TAKEN]);
      });

      it("says nothing when the earlier creation answers after a later one made the product and the route moved to it", async () => {
        const { form, id, posts, saving, onLateSaved } = await fillNew();
        await advance(10_000);
        form.safety.unblock();
        form.form.value.name = "Drugi";
        const second = form.save();
        posts[1]!.resolve(ok(view({ id: "p-b", name: "Drugi", version: 1 }), 201));
        await second;
        serveReads(view({ id: "p-b", name: "Drugi", version: 1 }));
        id.value = "p-b";
        await flushPromises();

        posts[0]!.resolve(ok(view({ id: "p-a", name: "Nowy", version: 1 }), 201));
        await saving;

        expect(onLateSaved).not.toHaveBeenCalled();
        expect(form.view.value?.id).toBe("p-b");
        expect(form.form.value.name).toBe("Drugi");
      });
    });
  });
});
