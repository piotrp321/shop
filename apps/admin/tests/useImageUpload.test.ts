import { flushPromises } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import type { components } from "@/api/schema";
import { type FormImage, MAX_IMAGES, useImageUpload } from "@/products/useImageUpload";

type ImageUpload = components["schemas"]["ImageUploadResponse"];

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock("@/api/client", () => ({ getApiClient: () => ({ POST: mocks.post }) }));

const UPLOAD = "/api/v1/admin/product-images";

function stored(key: string): ImageUpload {
  return {
    key,
    preview: { size: "thumbnail", aspectRatio: "1:1", variants: { webp: [{ width: 96, url: `https://img.test/${key}` }] } },
  };
}

function file(name: string): File {
  return new File(["content"], name, { type: "image/jpeg" });
}

function existing(key: string): FormImage {
  return { key, alt: key, variants: {} };
}

function created(key: string) {
  return { data: stored(key), response: { status: 201 } };
}

function rejected(status: number, body?: unknown) {
  return { data: undefined, error: body, response: { status } };
}

function setup(initial: FormImage[] = [], name = "Buty biegowe") {
  const images = ref<FormImage[]>(initial);
  const productName = ref(name);
  const upload = useImageUpload(images, () => productName.value);
  return { images, upload, productName };
}

function statuses(upload: ReturnType<typeof useImageUpload>): [string, string][] {
  return upload.entries.value.map((entry) => [entry.fileName, entry.status]);
}

describe("useImageUpload", () => {
  beforeEach(() => {
    mocks.post.mockReset();
  });

  it("sends the files one by one, in order, and adds each stored image with the product name as alt", async () => {
    const { images, upload } = setup();
    const resolvers: ((value: unknown) => void)[] = [];
    mocks.post.mockImplementation(() => new Promise((resolve) => resolvers.push(resolve)));

    upload.addFiles([file("a.jpg"), file("b.jpg")]);
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledTimes(1);
    expect(statuses(upload)).toEqual([["a.jpg", "uploading"], ["b.jpg", "pending"]]);
    expect(upload.uploading.value).toBe(true);

    resolvers[0]!(created("originals/a.jpg"));
    await flushPromises();
    expect(mocks.post).toHaveBeenCalledTimes(2);
    expect(statuses(upload)).toEqual([["a.jpg", "done"], ["b.jpg", "uploading"]]);

    resolvers[1]!(created("originals/b.jpg"));
    await flushPromises();
    expect(statuses(upload)).toEqual([["a.jpg", "done"], ["b.jpg", "done"]]);
    expect(upload.uploading.value).toBe(false);
    expect(images.value.map((image) => [image.key, image.alt])).toEqual([
      ["originals/a.jpg", "Buty biegowe"],
      ["originals/b.jpg", "Buty biegowe"],
    ]);
    expect(images.value[0]!.variants).toEqual({ webp: [{ width: 96, url: "https://img.test/originals/a.jpg" }] });
  });

  it("sends each file as the file field of a multipart body", async () => {
    const { upload } = setup();
    mocks.post.mockResolvedValue(created("originals/a.jpg"));
    const photo = file("a.jpg");

    upload.addFiles([photo]);
    await flushPromises();

    const [path, options] = mocks.post.mock.calls[0]!;
    expect(path).toBe(UPLOAD);
    const body = options.bodySerializer(options.body) as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect(body.get("file")).toBe(photo);
  });

  it("goes on with the other files when one fails, shows the detail of a 422 and retries only the failed one", async () => {
    const { images, upload } = setup();
    mocks.post
      .mockResolvedValueOnce(created("originals/a.jpg"))
      .mockResolvedValueOnce(rejected(422, { detail: "Plik jest za duży." }))
      .mockResolvedValueOnce(created("originals/c.jpg"));

    upload.addFiles([file("a.jpg"), file("b.jpg"), file("c.jpg")]);
    await flushPromises();

    expect(statuses(upload)).toEqual([["a.jpg", "done"], ["b.jpg", "failed"], ["c.jpg", "done"]]);
    expect(upload.entries.value[1]!.message).toBe("Plik jest za duży.");
    expect(images.value.map((image) => image.key)).toEqual(["originals/a.jpg", "originals/c.jpg"]);

    mocks.post.mockResolvedValueOnce(created("originals/b.jpg"));
    upload.retry(upload.entries.value[1]!.id);
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledTimes(4);
    expect(statuses(upload)[1]).toEqual(["b.jpg", "done"]);
    expect(images.value.map((image) => image.key)).toEqual(["originals/a.jpg", "originals/c.jpg", "originals/b.jpg"]);
  });

  it.each([
    [503, { title: "Service Unavailable" }, "niedostępny"],
    [500, undefined, "HTTP 500"],
    [422, { violations: [] }, "HTTP 422"],
  ])("explains a %i without a usable detail with a generic message", async (status, body, expected) => {
    const { upload } = setup();
    mocks.post.mockResolvedValue(rejected(status, body));

    upload.addFiles([file("a.jpg")]);
    await flushPromises();

    expect(upload.entries.value[0]!.status).toBe("failed");
    expect(upload.entries.value[0]!.message).toContain(expected);
  });

  it("reports a network failure and lets the file be sent again", async () => {
    const { upload } = setup();
    mocks.post.mockRejectedValueOnce(new Error("offline"));

    upload.addFiles([file("a.jpg")]);
    await flushPromises();

    expect(upload.entries.value[0]!.status).toBe("failed");
    expect(upload.entries.value[0]!.message).toContain("połączenie");
    expect(upload.uploading.value).toBe(false);
  });

  it("does not add an image whose key is already in the product", async () => {
    const { images, upload } = setup([existing("originals/a.jpg")]);
    mocks.post.mockResolvedValueOnce(created("originals/a.jpg")).mockResolvedValueOnce(created("originals/b.jpg")).mockResolvedValueOnce(created("originals/b.jpg"));

    upload.addFiles([file("same.jpg"), file("b.jpg"), file("b-again.jpg")]);
    await flushPromises();

    expect(images.value.map((image) => image.key)).toEqual(["originals/a.jpg", "originals/b.jpg"]);
    expect(statuses(upload)).toEqual([["same.jpg", "failed"], ["b.jpg", "done"], ["b-again.jpg", "failed"]]);
    expect(upload.entries.value[0]!.message).toBe("To zdjęcie już jest w produkcie.");
    expect(upload.entries.value[2]!.message).toBe("To zdjęcie już jest w produkcie.");
  });

  it("sends only as many files as there are free slots and rejects the rest without a request", async () => {
    const { images, upload } = setup(Array.from({ length: 8 }, (_, index) => existing(`originals/${index}.jpg`)));
    mocks.post.mockImplementation(async (_path: string, options: { body: { file: File } }) => created(`originals/new-${options.body.file.name}`));

    upload.addFiles([file("a.jpg"), file("b.jpg"), file("c.jpg"), file("d.jpg")]);
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledTimes(2);
    expect(images.value).toHaveLength(MAX_IMAGES);
    expect(statuses(upload)).toEqual([["a.jpg", "done"], ["b.jpg", "done"], ["c.jpg", "failed"], ["d.jpg", "failed"]]);
    expect(upload.entries.value[2]!.message).toBe("Produkt może mieć najwyżej 10 zdjęć.");
    expect(upload.canAddMore.value).toBe(false);
  });

  it("counts the files that are waiting or being sent against the free slots", async () => {
    const { upload } = setup(Array.from({ length: 9 }, (_, index) => existing(`originals/${index}.jpg`)));
    mocks.post.mockImplementation(() => new Promise(() => undefined));

    upload.addFiles([file("a.jpg")]);
    upload.addFiles([file("b.jpg")]);
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledTimes(1);
    expect(statuses(upload)).toEqual([["a.jpg", "uploading"], ["b.jpg", "failed"]]);
    expect(upload.canAddMore.value).toBe(false);
  });

  it("does not let a failed file be retried into a full product", async () => {
    const { images, upload } = setup(Array.from({ length: 9 }, (_, index) => existing(`originals/${index}.jpg`)));
    mocks.post.mockResolvedValueOnce(rejected(500)).mockResolvedValueOnce(created("originals/new.jpg"));

    upload.addFiles([file("a.jpg")]);
    await flushPromises();
    images.value.push(existing("originals/other.jpg"));
    upload.retry(upload.entries.value[0]!.id);
    await flushPromises();

    expect(mocks.post).toHaveBeenCalledTimes(1);
    expect(upload.entries.value[0]!.message).toBe("Produkt może mieć najwyżej 10 zdjęć.");
  });

  it("has room for more while the product has fewer than ten images", () => {
    const { images, upload } = setup([existing("originals/a.jpg")]);
    expect(upload.canAddMore.value).toBe(true);

    images.value = Array.from({ length: MAX_IMAGES }, (_, index) => existing(`originals/${index}.jpg`));
    expect(upload.canAddMore.value).toBe(false);
  });

  it("forgets the finished uploads of an earlier selection when new files are chosen, and a dismissed entry", async () => {
    const { upload } = setup();
    mocks.post.mockResolvedValueOnce(created("originals/a.jpg")).mockResolvedValueOnce(rejected(500)).mockResolvedValueOnce(created("originals/c.jpg"));

    upload.addFiles([file("a.jpg"), file("b.jpg")]);
    await flushPromises();
    upload.addFiles([file("c.jpg")]);
    await flushPromises();
    expect(statuses(upload)).toEqual([["b.jpg", "failed"], ["c.jpg", "done"]]);

    upload.dismiss(upload.entries.value[0]!.id);
    expect(statuses(upload)).toEqual([["c.jpg", "done"]]);
  });
});
