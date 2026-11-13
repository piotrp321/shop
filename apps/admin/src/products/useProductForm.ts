import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
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

export const MAX_AXES = 2;
export const MAX_VARIANTS = 100;

export const MODIFIED_MESSAGE = "Produkt został w międzyczasie zmieniony – odśwież";
export const SLUG_TAKEN_MESSAGE = "Ten slug ma już inny produkt. Podaj własny slug.";
export const NOT_PUBLISHABLE_MESSAGE = "Dodaj co najmniej jedno zdjęcie przed publikacją.";
export const LAST_IMAGE_MESSAGE = "Opublikowany produkt musi mieć co najmniej jedno zdjęcie.";

type Action = "save" | "publish" | "unpublish";

/** How an action ended; null when it did not start or its answer came after the form was left. */
export type ActionResult = "success" | "failure" | null;

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
 * staff member typed. The variant editor changes the axes and the rows; it keeps the `variantId` of every row that
 * exists (null for a new row) and never computes the comparison key or the uniqueness of the combinations, the API
 * does.
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
  // The product as the API last sent it, which „discard” restores without a request.
  let source: ProductEditView | null = null;
  // Set when the form is left: an answer that arrives later must not change it or send the staff member back to it.
  let disposed = false;

  const readOnly = computed(() => !options.canManage());
  const dirty = computed(() => JSON.stringify(toBody(form.value)) !== baseline.value);
  const idle = computed(() => !readOnly.value && !busy.value && !upload.uploading.value);
  const canSave = idle;
  const canPublish = computed(() => idle.value && !dirty.value && view.value?.status === "draft");
  const canUnpublish = computed(() => idle.value && !dirty.value && view.value?.status === "published");
  /** The messages for the list of images as a whole (`images`), not for a single image. */
  const imagesErrors = computed(() => fieldErrors.value.images ?? []);
  /** After the first publication the axes cannot be added, removed or moved (the API enforces it as well). */
  const axesLocked = computed(() => view.value?.firstPublishedAt != null);
  /** The messages for the variants as a whole; the ones for an axis, a row or a value are shown there. */
  const variantsErrors = computed(() => [
    ...(variantsConflict.value === null ? [] : [variantsConflict.value]),
    ...errorsExactly("variantAxes"),
    ...errorsExactly("variants"),
  ]);

  /** The messages for a field path and everything under it (`images[1]` includes `images[1].alt`). */
  function errorsAt(path: string): string[] {
    return Object.entries(fieldErrors.value)
      .filter(([key]) => key === path || key.startsWith(`${path}[`) || key.startsWith(`${path}.`))
      .flatMap(([, titles]) => titles);
  }

  /** The messages for exactly this path, without the ones under it. */
  function errorsExactly(path: string): string[] {
    return fieldErrors.value[path] ?? [];
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
    source = data;
    form.value = fromView(data);
    view.value = stateOf(data);
    baseline.value = JSON.stringify(toBody(form.value));
  }

  function startNew(): void {
    source = null;
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
  onBeforeUnmount(() => {
    disposed = true;
    latest++;
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

  /** Throws away the unsaved changes: the form goes back to the product as the API last sent it, without a request. */
  function discard(): void {
    if (source === null || busy.value) {
      return;
    }
    form.value = fromView(source);
    clearFeedback();
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

  async function perform(action: Action, allowed: boolean, request: () => Promise<Outcome>, success: string): Promise<ActionResult> {
    if (!allowed) {
      return null;
    }
    clearFeedback();
    busy.value = true;
    try {
      const result = await request();
      if (disposed) {
        return null;
      }
      if (result.data) {
        const created = view.value === null;
        apply(result.data);
        message.value = success;
        if (created) {
          options.onCreated(result.data.id);
        }
        return "success";
      }
      failure(action, result.response.status, result.error);
      return "failure";
    } catch {
      if (disposed) {
        return null;
      }
      error.value = "Nie udało się wykonać operacji. Sprawdź połączenie z API.";
      return "failure";
    } finally {
      busy.value = false;
    }
  }

  function save(): Promise<ActionResult> {
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

  function publish(): Promise<ActionResult> {
    const current = view.value;
    return perform(
      "publish",
      canPublish.value && current !== null,
      () => getApiClient().POST("/api/v1/admin/products/{id}/publish", { params: { path: { id: current!.id } }, body: { version: current!.version } }),
      "Produkt opublikowany.",
    );
  }

  function unpublish(): Promise<ActionResult> {
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

  // The paths of the messages count axes and rows by position, which adding, moving or removing changes.
  function clearVariantErrors(): void {
    fieldErrors.value = Object.fromEntries(Object.entries(fieldErrors.value).filter(([key]) => !key.startsWith("variant")));
  }

  function changeRows(change: (values: string[]) => string[]): void {
    form.value.variants = form.value.variants.map((row) => ({ variantId: row.variantId, values: change(row.values) }));
  }

  const canEditAxes = () => !readOnly.value && !axesLocked.value;

  /** Appends an axis with an empty name and an empty value in every row. */
  function addAxis(): void {
    if (!canEditAxes() || form.value.variantAxes.length >= MAX_AXES) {
      return;
    }
    form.value.variantAxes = [...form.value.variantAxes, ""];
    changeRows((values) => [...values, ""]);
    clearVariantErrors();
  }

  function setAxis(index: number, name: string): void {
    if (readOnly.value || index < 0 || index >= form.value.variantAxes.length) {
      return;
    }
    form.value.variantAxes = form.value.variantAxes.map((axis, position) => (position === index ? name : axis));
  }

  /**
   * Removes an axis and the values at its position. Without axes the product has one default variant, so the first
   * row stays (with its id, without values) and the others go.
   */
  function removeAxis(index: number): void {
    if (!canEditAxes() || index < 0 || index >= form.value.variantAxes.length) {
      return;
    }
    form.value.variantAxes = form.value.variantAxes.filter((_, position) => position !== index);
    if (form.value.variantAxes.length === 0) {
      form.value.variants = form.value.variants.slice(0, 1).map((row) => ({ variantId: row.variantId, values: [] }));
    } else {
      changeRows((values) => values.filter((_, position) => position !== index));
    }
    clearVariantErrors();
  }

  /** Moves an axis one place left (-1) or right (1), with the values of every row. */
  function moveAxis(index: number, direction: -1 | 1): void {
    const target = index + direction;
    const axes = form.value.variantAxes;
    if (!canEditAxes() || index < 0 || index >= axes.length || target < 0 || target >= axes.length) {
      return;
    }
    const swap = <T>(list: T[]): T[] => {
      const moved = [...list];
      [moved[index], moved[target]] = [moved[target]!, moved[index]!];
      return moved;
    };
    form.value.variantAxes = swap(axes);
    changeRows(swap);
    clearVariantErrors();
  }

  /** Appends a new row (no id) with an empty value per axis; a product without axes has only the default variant. */
  function addVariant(): void {
    if (readOnly.value || form.value.variantAxes.length === 0 || form.value.variants.length >= MAX_VARIANTS) {
      return;
    }
    form.value.variants = [...form.value.variants, { variantId: null, values: form.value.variantAxes.map(() => "") }];
    clearVariantErrors();
  }

  /** Removes a row; the product keeps at least one. Asking for confirmation before an existing variant goes is up to the view. */
  function removeVariant(index: number): void {
    if (readOnly.value || form.value.variants.length <= 1 || index < 0 || index >= form.value.variants.length) {
      return;
    }
    form.value.variants = form.value.variants.filter((_, position) => position !== index);
    clearVariantErrors();
  }

  function setValue(row: number, position: number, value: string): void {
    const target = form.value.variants[row];
    if (readOnly.value || !target || position < 0 || position >= target.values.length) {
      return;
    }
    target.values = target.values.map((current, index) => (index === position ? value : current));
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
    imagesErrors,
    variantsErrors,
    axesLocked,
    errorsAt,
    errorsExactly,
    upload,
    save,
    publish,
    unpublish,
    refresh,
    discard,
    moveImage,
    removeImage,
    addAxis,
    setAxis,
    removeAxis,
    moveAxis,
    addVariant,
    removeVariant,
    setValue,
  };
}
