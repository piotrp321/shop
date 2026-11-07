import { computed, onMounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { type FormImage, useImageUpload } from "./useImageUpload";

export type ProductEditView = components["schemas"]["ProductEditViewResponse"];
export type TaxClass = components["schemas"]["TaxClassResponse"];

/** A variant row of the form. `variantId` is null for a row that is new. */
export interface VariantRow {
  variantId: string | null;
  values: string[];
}

/** The fields of the product the form edits; everything in it is what the API sent or what the staff member typed. */
export interface ProductFormData {
  name: string;
  slug: string;
  shortDescription: string;
  mainCategoryId: string | null;
  additionalCategoryIds: string[];
  taxClass: string | null;
  images: FormImage[];
  variantAxes: string[];
  variants: VariantRow[];
}

/** The state the API keeps about the product; the form shows it and never computes it. */
export interface ProductState {
  id: string;
  version: number;
  status: ProductEditView["status"];
  firstPublishedAt: string | null;
  slugLocked: boolean;
  updatedAt: string;
}

export interface ProductFormOptions {
  /** The id of the product being edited, null for a new one. Read again when the route changes. */
  productId: () => string | null;
  /** Whether the staff member may change products (admin or marketing); the API checks it too. */
  canManage: () => boolean;
  /** Called with the id of a product just created, so that the view can move to its page. */
  onCreated: (id: string) => void;
}

const PROBLEM_PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const FIELD_ROOTS = new Set(["name", "slug", "shortDescription", "mainCategoryId", "additionalCategoryIds", "taxClass", "images", "variantAxes", "variants"]);

export const MODIFIED_MESSAGE = "Produkt został w międzyczasie zmieniony – odśwież";
export const SLUG_TAKEN_MESSAGE = "Ten slug ma już inny produkt. Podaj własny slug.";
export const NOT_PUBLISHABLE_MESSAGE = "Dodaj co najmniej jedno zdjęcie przed publikacją.";
export const LAST_IMAGE_MESSAGE = "Opublikowany produkt musi mieć co najmniej jedno zdjęcie.";

type Action = "save" | "publish" | "unpublish";

interface Outcome {
  data?: ProductEditView;
  error?: unknown;
  response: { status: number };
}

function emptyForm(): ProductFormData {
  return {
    name: "",
    slug: "",
    shortDescription: "",
    mainCategoryId: null,
    additionalCategoryIds: [],
    taxClass: null,
    images: [],
    variantAxes: [],
    // A product without axes has exactly one variant row without values.
    variants: [{ variantId: null, values: [] }],
  };
}

function fromView(view: ProductEditView): ProductFormData {
  return {
    name: view.name,
    slug: view.slug,
    shortDescription: view.shortDescription,
    mainCategoryId: view.mainCategoryId,
    additionalCategoryIds: [...view.additionalCategoryIds],
    taxClass: view.taxClass,
    images: view.images.map((image) => ({ key: image.key, alt: image.alt, variants: image.preview.variants })),
    variantAxes: [...view.variantAxes],
    variants: view.variants.map((variant) => ({ variantId: variant.variantId, values: [...variant.values] })),
  };
}

function stateOf(view: ProductEditView): ProductState {
  return {
    id: view.id,
    version: view.version,
    status: view.status,
    firstPublishedAt: view.firstPublishedAt,
    slugLocked: view.slugLocked,
    updatedAt: view.updatedAt,
  };
}

/** What the API gets of the form; the previews are not part of it, so they do not count as a change either. */
function toBody(form: ProductFormData) {
  return {
    name: form.name,
    slug: form.slug,
    shortDescription: form.shortDescription,
    mainCategoryId: form.mainCategoryId,
    additionalCategoryIds: form.additionalCategoryIds,
    taxClass: form.taxClass,
    images: form.images.map((image) => ({ key: image.key, alt: image.alt })),
    variantAxes: form.variantAxes,
    variants: form.variants.map((variant) => ({ variantId: variant.variantId, values: variant.values })),
  };
}

function problemField(body: unknown, field: "detail" | "type"): string | null {
  const value = typeof body === "object" && body !== null ? (body as Record<string, unknown>)[field] : undefined;
  return typeof value === "string" && value !== "" ? value : null;
}

function violationsOf(body: unknown): { propertyPath: string; title: string }[] {
  const violations = typeof body === "object" && body !== null ? (body as { violations?: unknown }).violations : undefined;
  if (!Array.isArray(violations)) {
    return [];
  }
  return violations.filter(
    (violation): violation is { propertyPath: string; title: string } =>
      typeof violation?.propertyPath === "string" && typeof violation?.title === "string",
  );
}

/**
 * The product form: loads a product (or starts an empty one), tracks unsaved changes, saves, publishes and withdraws
 * with the version of the view it shows, and turns the answers of the API into messages at the right place. The
 * API computes everything (the slug, `slugLocked`, the status, the version); the form only holds and sends what the
 * staff member typed. Variants are held and sent unchanged for now.
 *
 * Every action is off while a request or an image upload runs, and publishing or withdrawing is off while there are
 * unsaved changes (the API acts on the saved product). Without the permission to manage products the form is read-only.
 */
export function useProductForm(options: ProductFormOptions) {
  const form = ref<ProductFormData>(emptyForm());
  const view = ref<ProductState | null>(null);
  const baseline = ref(JSON.stringify(toBody(emptyForm())));
  const taxClasses = ref<TaxClass[]>([]);
  const taxClassesError = ref<string | null>(null);
  const loaded = ref(false);
  const loadError = ref<string | null>(null);
  const busy = ref(false);
  const message = ref<string | null>(null);
  const error = ref<string | null>(null);
  const conflict = ref(false);
  const fieldErrors = ref<Record<string, string[]>>({});
  const variantsConflict = ref<string | null>(null);

  const images = computed({
    get: () => form.value.images,
    set: (value: FormImage[]) => {
      form.value.images = value;
    },
  });
  const upload = useImageUpload(images, () => form.value.name);

  // Only the newest load may change the state; an older one that finishes late is ignored.
  let latest = 0;

  const readOnly = computed(() => !options.canManage());
  const dirty = computed(() => JSON.stringify(toBody(form.value)) !== baseline.value);
  const idle = computed(() => !readOnly.value && !busy.value && !upload.uploading.value);
  const canSave = idle;
  const canPublish = computed(() => idle.value && !dirty.value && view.value?.status === "draft");
  const canUnpublish = computed(() => idle.value && !dirty.value && view.value?.status === "published");
  const variantsErrors = computed(() => [
    ...(variantsConflict.value === null ? [] : [variantsConflict.value]),
    ...errorsAt("variantAxes"),
    ...errorsAt("variants"),
  ]);

  /** The messages for a field path and everything under it (`images[1]` includes `images[1].alt`). */
  function errorsAt(path: string): string[] {
    return Object.entries(fieldErrors.value)
      .filter(([key]) => key === path || key.startsWith(`${path}[`) || key.startsWith(`${path}.`))
      .flatMap(([, titles]) => titles);
  }

  function addFieldError(path: string, title: string): void {
    fieldErrors.value = { ...fieldErrors.value, [path]: [...(fieldErrors.value[path] ?? []), title] };
  }

  function clearFeedback(): void {
    message.value = null;
    error.value = null;
    conflict.value = false;
    fieldErrors.value = {};
    variantsConflict.value = null;
  }

  function apply(data: ProductEditView): void {
    form.value = fromView(data);
    view.value = stateOf(data);
    baseline.value = JSON.stringify(toBody(form.value));
  }

  function startNew(): void {
    form.value = emptyForm();
    view.value = null;
    baseline.value = JSON.stringify(toBody(form.value));
    loadError.value = null;
    loaded.value = true;
  }

  async function loadProduct(id: string): Promise<void> {
    const request = ++latest;
    loadError.value = null;
    try {
      const result = await getApiClient().GET("/api/v1/admin/products/{id}", { params: { path: { id } } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        apply(result.data);
        loaded.value = true;
      } else {
        loadError.value = result.response.status === 404 ? "Ten produkt nie istnieje." : `Nie udało się pobrać produktu (HTTP ${result.response.status}).`;
      }
    } catch {
      if (request === latest) {
        loadError.value = "Nie udało się pobrać produktu. Sprawdź połączenie z API.";
      }
    }
  }

  async function loadTaxClasses(): Promise<void> {
    try {
      const result = await getApiClient().GET("/api/v1/admin/tax-classes");
      if (result.data) {
        taxClasses.value = result.data;
        taxClassesError.value = null;
      } else {
        taxClassesError.value = `Nie udało się pobrać klas podatkowych (HTTP ${result.response.status}).`;
      }
    } catch {
      taxClassesError.value = "Nie udało się pobrać klas podatkowych. Sprawdź połączenie z API.";
    }
  }

  function open(id: string | null): void {
    // The product just created is already shown; the route only moved to its page.
    if (id !== null && view.value?.id === id) {
      return;
    }
    clearFeedback();
    if (id === null) {
      latest++;
      startNew();
    } else {
      loaded.value = false;
      void loadProduct(id);
    }
  }

  onMounted(() => {
    open(options.productId());
    void loadTaxClasses();
  });
  watch(
    () => options.productId(),
    (id) => open(id),
  );

  /** Discards the changes and shows the product as the API has it now. */
  async function refresh(): Promise<void> {
    const id = view.value?.id ?? options.productId();
    clearFeedback();
    if (id !== null) {
      await loadProduct(id);
    }
  }

  function failure(action: Action, status: number, body: unknown): void {
    switch (status) {
      case 409:
        conflictFailure(action, body);
        return;
      case 422:
        validationFailure(body, status);
        return;
      case 403:
        error.value = "Nie masz uprawnień do zmiany produktów.";
        return;
      case 404:
        error.value = "Ten produkt już nie istnieje.";
        return;
      case 503:
        error.value = "Usługa jest chwilowo niedostępna. Spróbuj ponownie.";
        return;
      default:
        error.value = `Nie udało się wykonać operacji (HTTP ${status}).`;
    }
  }

  function validationFailure(body: unknown, status: number): void {
    const violations = violationsOf(body);
    const unplaced: string[] = [];
    for (const { propertyPath, title } of violations) {
      if (FIELD_ROOTS.has(propertyPath.split(/[.[]/)[0] ?? "")) {
        addFieldError(propertyPath, title);
      } else {
        unplaced.push(title);
      }
    }
    if (violations.length === 0) {
      error.value = problemField(body, "detail") ?? `API odrzuciło dane (HTTP ${status}).`;
    } else if (unplaced.length > 0) {
      error.value = unplaced.join(" ");
    }
  }

  function conflictFailure(action: Action, body: unknown): void {
    const type = problemField(body, "type")?.replace(PROBLEM_PREFIX, "") ?? null;
    const detail = problemField(body, "detail");
    switch (type) {
      case "product-modified":
        conflict.value = true;
        error.value = MODIFIED_MESSAGE;
        return;
      case "slug-taken":
        addFieldError("slug", SLUG_TAKEN_MESSAGE);
        return;
      case "slug-locked":
        addFieldError("slug", detail ?? "Slug nie może się zmienić po pierwszej publikacji.");
        return;
      case "product-not-publishable":
        error.value = action === "publish" ? NOT_PUBLISHABLE_MESSAGE : LAST_IMAGE_MESSAGE;
        return;
      case "variant-locked":
      case "variant-in-use":
        variantsConflict.value = detail ?? "Tej zmiany wariantów nie można zapisać.";
        return;
      default:
        error.value = detail ?? "Nie udało się wykonać operacji (HTTP 409).";
    }
  }

  async function perform(action: Action, allowed: boolean, request: () => Promise<Outcome>, success: string): Promise<void> {
    if (!allowed) {
      return;
    }
    clearFeedback();
    busy.value = true;
    try {
      const result = await request();
      if (result.data) {
        const created = view.value === null;
        apply(result.data);
        message.value = success;
        if (created) {
          options.onCreated(result.data.id);
        }
      } else {
        failure(action, result.response.status, result.error);
      }
    } catch {
      error.value = "Nie udało się wykonać operacji. Sprawdź połączenie z API.";
    } finally {
      busy.value = false;
    }
  }

  function save(): Promise<void> {
    const current = view.value;
    const body = toBody(form.value);
    return perform(
      "save",
      canSave.value,
      () =>
        current === null
          ? getApiClient().POST("/api/v1/admin/products", { body })
          : getApiClient().PUT("/api/v1/admin/products/{id}", { params: { path: { id: current.id } }, body: { version: current.version, ...body } }),
      "Produkt zapisany.",
    );
  }

  function publish(): Promise<void> {
    const current = view.value;
    return perform(
      "publish",
      canPublish.value && current !== null,
      () => getApiClient().POST("/api/v1/admin/products/{id}/publish", { params: { path: { id: current!.id } }, body: { version: current!.version } }),
      "Produkt opublikowany.",
    );
  }

  function unpublish(): Promise<void> {
    const current = view.value;
    return perform(
      "unpublish",
      canUnpublish.value && current !== null,
      () => getApiClient().POST("/api/v1/admin/products/{id}/unpublish", { params: { path: { id: current!.id } }, body: { version: current!.version } }),
      "Produkt wycofany z publikacji.",
    );
  }

  /** Moves the image at `index` one place up (-1) or down (1) in the presentation order; the first one is the main image. */
  function moveImage(index: number, direction: -1 | 1): void {
    const target = index + direction;
    const list = form.value.images;
    if (index < 0 || index >= list.length || target < 0 || target >= list.length) {
      return;
    }
    const moved = [...list];
    [moved[index], moved[target]] = [moved[target]!, moved[index]!];
    form.value.images = moved;
    clearImageErrors();
  }

  function removeImage(index: number): void {
    form.value.images = form.value.images.filter((_, position) => position !== index);
    clearImageErrors();
  }

  // The paths of the messages count images by position, which a move or a removal changes.
  function clearImageErrors(): void {
    fieldErrors.value = Object.fromEntries(Object.entries(fieldErrors.value).filter(([key]) => !key.startsWith("images")));
  }

  return {
    form,
    view,
    taxClasses,
    taxClassesError,
    loaded,
    loadError,
    busy,
    dirty,
    readOnly,
    canSave,
    canPublish,
    canUnpublish,
    message,
    error,
    conflict,
    variantsErrors,
    errorsAt,
    upload,
    save,
    publish,
    unpublish,
    refresh,
    moveImage,
    removeImage,
  };
}
