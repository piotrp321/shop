import { computed, type Ref, ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";

/** Signed URLs of an image by output format (the file extension), widths ascending, as the API gives them. */
export type ImageVariants = components["schemas"]["ImagePreviewResponse"]["variants"];

/** An image of the product form: the key of the stored original, its alt text and the preview URLs. */
export interface FormImage {
  key: string;
  alt: string;
  variants: ImageVariants;
}

export type UploadStatus = "pending" | "uploading" | "done" | "failed";

/** The upload of one file, as the form lists it. `retryable` is false for a file the product already has. */
export interface UploadEntry {
  id: number;
  fileName: string;
  status: UploadStatus;
  message: string | null;
  retryable: boolean;
}

/** The limit of images of a product; the API enforces it too. */
export const MAX_IMAGES = 10;

export const LIMIT_MESSAGE = `Produkt może mieć najwyżej ${MAX_IMAGES} zdjęć.`;
export const DUPLICATE_MESSAGE = "To zdjęcie już jest w produkcie.";

/** The message for a rejected upload; the `detail` of a 422 is Polish text from the API. */
function failureMessage(status: number, body: unknown): string {
  if (status === 422) {
    const detail = typeof body === "object" && body !== null ? (body as { detail?: unknown }).detail : undefined;
    if (typeof detail === "string" && detail !== "") {
      return detail;
    }
  }
  if (status === 503) {
    return "Magazyn zdjęć jest chwilowo niedostępny. Spróbuj ponownie.";
  }
  if (status === 403) {
    return "Nie masz uprawnień do dodawania zdjęć.";
  }
  return `Nie udało się wysłać zdjęcia (HTTP ${status}).`;
}

/**
 * Uploads image files for the product form, one at a time in the order chosen, each with its own status. A stored
 * image is appended to `images` with `defaultAlt()` as its alt text. A file that failed does not stop the others and
 * can be sent again. The API stores an original once per content, so a key that `images` already holds is not added a
 * second time. Files that do not fit in the free slots (counting the ones still waiting or being sent) are not sent.
 *
 * `abandon()` is for a form that moves to another product: it drops the queue and the list, and the answer of a file
 * that was already being sent is thrown away, so a stored image never reaches a form it was not sent for.
 */
export function useImageUpload(images: Ref<FormImage[]>, defaultAlt: () => string) {
  const entries = ref<UploadEntry[]>([]);
  const files = new Map<number, File>();
  const queue: number[] = [];
  let nextId = 1;
  let running = false;
  // Raised by `abandon()`: a send or a drain that started under an older one has no say any more.
  let generation = 0;

  const uploading = computed(() => entries.value.some((entry) => entry.status === "pending" || entry.status === "uploading"));
  const inFlight = computed(() => entries.value.filter((entry) => entry.status === "pending" || entry.status === "uploading").length);
  const canAddMore = computed(() => images.value.length + inFlight.value < MAX_IMAGES);

  function update(id: number, changes: Partial<UploadEntry>): void {
    entries.value = entries.value.map((entry) => (entry.id === id ? { ...entry, ...changes } : entry));
  }

  function fail(id: number, message: string, retryable = true): void {
    update(id, { status: "failed", message, retryable });
  }

  async function send(id: number): Promise<void> {
    const mine = generation;
    const file = files.get(id);
    if (file === undefined) {
      return;
    }
    if (images.value.length >= MAX_IMAGES) {
      fail(id, LIMIT_MESSAGE, false);
      return;
    }
    update(id, { status: "uploading", message: null });
    try {
      const result = await getApiClient().POST("/api/v1/admin/product-images", {
        // The generated type of a binary field is a string; the body serializer turns the File into multipart.
        body: { file: file as unknown as string },
        bodySerializer: (body) => {
          const form = new FormData();
          form.append("file", body.file as unknown as Blob);
          return form;
        },
      });
      if (mine !== generation) {
        return;
      }
      if (!result.data) {
        fail(id, failureMessage(result.response.status, result.error));
        return;
      }
      const { key, preview } = result.data;
      if (images.value.some((image) => image.key === key)) {
        fail(id, DUPLICATE_MESSAGE, false);
        return;
      }
      images.value = [...images.value, { key, alt: defaultAlt(), variants: preview.variants }];
      files.delete(id);
      update(id, { status: "done", message: null });
    } catch {
      if (mine === generation) {
        fail(id, "Nie udało się wysłać zdjęcia. Sprawdź połączenie z API.");
      }
    }
  }

  async function drain(): Promise<void> {
    if (running) {
      return;
    }
    running = true;
    const mine = generation;
    try {
      for (let id = queue.shift(); id !== undefined && mine === generation; id = queue.shift()) {
        await send(id);
      }
    } finally {
      // A drain of an abandoned generation must not mark the newer one as stopped.
      if (mine === generation) {
        running = false;
      }
    }
  }

  function abandon(): void {
    generation++;
    queue.length = 0;
    files.clear();
    entries.value = [];
    running = false;
  }

  function addFiles(chosen: File[]): void {
    // The finished uploads of an earlier selection are history; the failed ones stay for a retry.
    entries.value = entries.value.filter((entry) => entry.status !== "done");
    let free = MAX_IMAGES - images.value.length - inFlight.value;
    for (const file of chosen) {
      const id = nextId++;
      if (free > 0) {
        free--;
        files.set(id, file);
        entries.value = [...entries.value, { id, fileName: file.name, status: "pending", message: null, retryable: true }];
        queue.push(id);
      } else {
        entries.value = [...entries.value, { id, fileName: file.name, status: "failed", message: LIMIT_MESSAGE, retryable: false }];
      }
    }
    void drain();
  }

  function retry(id: number): void {
    const entry = entries.value.find((candidate) => candidate.id === id);
    if (entry === undefined || entry.status !== "failed" || !entry.retryable || !files.has(id)) {
      return;
    }
    update(id, { status: "pending", message: null });
    queue.push(id);
    void drain();
  }

  function dismiss(id: number): void {
    const entry = entries.value.find((candidate) => candidate.id === id);
    if (entry === undefined || entry.status === "pending" || entry.status === "uploading") {
      return;
    }
    files.delete(id);
    entries.value = entries.value.filter((candidate) => candidate.id !== id);
  }

  return { entries, uploading, canAddMore, addFiles, retry, dismiss, abandon };
}
