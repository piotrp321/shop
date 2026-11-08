import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { instantToWarsaw, warsawToInstant } from "./time";

export type PromotionView = components["schemas"]["PromotionResponse"];
export type PromotionVariant = components["schemas"]["PromotionVariantResponse"];

/** What the staff member edits. Times are Polish local times (`YYYY-MM-DDTHH:mm`), the percent is the typed text. */
export interface PromotionFormData {
  name: string;
  percentOff: string;
  /** „Od teraz”: the promotion starts when it is saved, whatever `startsAt` says. */
  startNow: boolean;
  startsAt: string;
  endsAt: string;
  variants: PromotionVariant[];
}

export interface PromotionFormOptions {
  /** The id of the promotion being edited, null for a new one. Read again when the route changes. */
  promotionId: () => string | null;
  /** Whether the staff member may change promotions (admin or marketing); the API checks it too. */
  canManage: () => boolean;
  /** Called with the id of a promotion just created, so that the view can move to its page. */
  onCreated: (id: string) => void;
  /** Called after a promotion was deleted, so that the view can leave its page. */
  onDeleted: () => void;
}

/** How an action ended; null when it did not start or its answer came after the form was left. */
export type ActionResult = "success" | "failure" | null;

const PROBLEM_PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const FIELD_ROOTS = new Set(["name", "percentOff", "startsAt", "endsAt", "variantIds"]);

export const MODIFIED_MESSAGE = "Promocja została w międzyczasie zmieniona – pokazujemy jej aktualny stan.";
const MISSING_END = "Podaj koniec promocji.";
const MISSING_START = "Podaj początek promocji albo wybierz „od teraz”.";
const IMPOSSIBLE_TIME = "Ta godzina nie istnieje w czasie polskim (zmiana czasu) albo data jest niepoprawna.";
const PERCENT_NOT_WHOLE = "Podaj procent jako liczbę całkowitą.";

function emptyForm(): PromotionFormData {
  return { name: "", percentOff: "", startNow: false, startsAt: "", endsAt: "", variants: [] };
}

function fromView(view: PromotionView): PromotionFormData {
  return {
    name: view.name,
    percentOff: String(view.percentOff),
    startNow: false,
    startsAt: instantToWarsaw(view.startsAt),
    endsAt: instantToWarsaw(view.endsAt),
    variants: view.variants.map((variant) => ({ ...variant })),
  };
}

function signature(form: PromotionFormData): string {
  return JSON.stringify({ ...form, variants: form.variants.map((variant) => variant.variantId) });
}

function problemField(body: unknown, field: "detail" | "type"): string | null {
  const value = typeof body === "object" && body !== null ? (body as Record<string, unknown>)[field] : undefined;
  return typeof value === "string" && value !== "" ? value : null;
}

function conflictingIds(body: unknown): string[] {
  const ids = typeof body === "object" && body !== null ? (body as { conflictingVariantIds?: unknown }).conflictingVariantIds : undefined;
  return Array.isArray(ids) ? ids.filter((id): id is string => typeof id === "string") : [];
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

interface Body {
  name: string;
  percentOff: number;
  startsAt: string | null;
  endsAt: string;
  variantIds: string[];
}

/**
 * The promotion form: loads a promotion (or starts an empty one), tracks unsaved changes, saves, ends and deletes with
 * the version of the view it shows, and turns the answers of the API into messages at the right place. The status,
 * the version and the prices come from the API; the form only holds and sends what the staff member typed.
 *
 * The status decides which fields can change (the rules are the API's, the form follows them): a planned promotion
 * changes everything, an active one only its name and its end (`scheduleLocked`: the percent, the start and the
 * variants), an ended one nothing. For an active promotion the form sends the stored percent, start and variants
 * whatever the fields hold. Times are typed and shown in Polish time and sent as UTC moments; a time that was not
 * edited is sent as the API had it, so its seconds are not lost. `endNow` sends the stored promotion with the end set
 * to the time of the browser.
 *
 * A 409 `promotion-overlap` marks the variants of `conflictingVariantIds` and keeps what was typed. Any other 409 (the
 * version changed, the status does not allow the change) shows its message and reloads the promotion.
 */
export function usePromotionForm(options: PromotionFormOptions) {
  const form = ref<PromotionFormData>(emptyForm());
  const view = ref<PromotionView | null>(null);
  const baseline = ref(signature(emptyForm()));
  const loaded = ref(false);
  const loadError = ref<string | null>(null);
  const busy = ref(false);
  const message = ref<string | null>(null);
  const error = ref<string | null>(null);
  const fieldErrors = ref<Record<string, string[]>>({});
  const conflicting = ref<Set<string>>(new Set());

  // Only the newest load may change the state; an older one that finishes late is ignored.
  let latest = 0;
  // Set when the form is left: an answer that arrives later must not change it or send the staff member anywhere.
  let disposed = false;

  const status = computed(() => view.value?.status ?? null);
  const readOnly = computed(() => !options.canManage() || status.value === "ended");
  const scheduleLocked = computed(() => status.value === "active");
  const dirty = computed(() => signature(form.value) !== baseline.value);
  const idle = computed(() => !busy.value && loaded.value);
  const canSave = computed(() => idle.value && !readOnly.value && (view.value === null || dirty.value));
  const canEndNow = computed(() => idle.value && options.canManage() && status.value === "active" && !dirty.value);
  const canDelete = computed(() => idle.value && options.canManage() && status.value === "planned" && !dirty.value);

  /** The messages for a field path and everything under it (`variantIds[1]` is under `variantIds`). */
  function errorsAt(path: string): string[] {
    return Object.entries(fieldErrors.value)
      .filter(([key]) => key === path || key.startsWith(`${path}[`) || key.startsWith(`${path}.`))
      .flatMap(([, titles]) => titles);
  }

  function addFieldError(path: string, title: string): void {
    fieldErrors.value = { ...fieldErrors.value, [path]: [...(fieldErrors.value[path] ?? []), title] };
  }

  function isConflicting(variantId: string): boolean {
    return conflicting.value.has(variantId);
  }

  function clearFeedback(): void {
    message.value = null;
    error.value = null;
    fieldErrors.value = {};
    conflicting.value = new Set();
  }

  function apply(data: PromotionView): void {
    form.value = fromView(data);
    view.value = data;
    baseline.value = signature(form.value);
  }

  function startNew(): void {
    form.value = emptyForm();
    view.value = null;
    baseline.value = signature(form.value);
    loadError.value = null;
    loaded.value = true;
  }

  async function fetchPromotion(id: string): Promise<{ data: PromotionView } | { status: number } | null> {
    try {
      const result = await getApiClient().GET("/api/v1/admin/promotions/{id}", { params: { path: { id } } });
      return result.data ? { data: result.data } : { status: result.response.status };
    } catch {
      return null;
    }
  }

  async function loadPromotion(id: string): Promise<void> {
    const request = ++latest;
    loadError.value = null;
    const result = await fetchPromotion(id);
    if (request !== latest) {
      return;
    }
    if (result !== null && "data" in result) {
      apply(result.data);
      loaded.value = true;
    } else if (result === null) {
      loadError.value = "Nie udało się pobrać promocji. Sprawdź połączenie z API.";
    } else {
      loadError.value = result.status === 404 ? "Ta promocja nie istnieje." : `Nie udało się pobrać promocji (HTTP ${result.status}).`;
    }
  }

  /** Shows the promotion as the API has it, keeping the message that explains why; a failed reload changes nothing. */
  async function reloadQuietly(): Promise<void> {
    const id = view.value?.id;
    if (id === undefined) {
      return;
    }
    const request = ++latest;
    const result = await fetchPromotion(id);
    if (request === latest && !disposed && result !== null && "data" in result) {
      apply(result.data);
    }
  }

  function open(id: string | null): void {
    // The promotion just created is already shown; the route only moved to its page.
    if (id !== null && view.value?.id === id) {
      return;
    }
    clearFeedback();
    if (id === null) {
      latest++;
      startNew();
    } else {
      loaded.value = false;
      view.value = null;
      void loadPromotion(id);
    }
  }

  onMounted(() => open(options.promotionId()));
  onBeforeUnmount(() => {
    disposed = true;
    latest++;
  });
  watch(
    () => options.promotionId(),
    (id) => open(id),
  );

  /** Discards the changes and shows the promotion as the API has it now. */
  async function refresh(): Promise<void> {
    const id = view.value?.id ?? options.promotionId();
    clearFeedback();
    if (id !== null) {
      await loadPromotion(id);
    }
  }

  /** A time as the API gets it: the stored moment when the text is what it shows, else the typed time as UTC. */
  function moment(path: "startsAt" | "endsAt", missing: string): string | null {
    const local = form.value[path];
    const stored = view.value?.[path];
    if (stored !== undefined && local === instantToWarsaw(stored)) {
      return stored;
    }
    if (local.trim() === "") {
      addFieldError(path, missing);
      return null;
    }
    const converted = warsawToInstant(local);
    if (converted === null) {
      addFieldError(path, IMPOSSIBLE_TIME);
    }
    return converted;
  }

  /** What the form sends, or null (with the errors at the fields) when a typed value cannot be sent at all. */
  function buildBody(): Body | null {
    const current = view.value;
    const typed = form.value;
    const endsAt = moment("endsAt", MISSING_END);
    if (current !== null && current.status === "active") {
      return endsAt === null
        ? null
        : {
            name: typed.name,
            percentOff: current.percentOff,
            startsAt: current.startsAt,
            endsAt,
            variantIds: current.variants.map((variant) => variant.variantId),
          };
    }
    let valid = endsAt !== null;
    const percentText = typed.percentOff.trim();
    if (!/^\d+$/.test(percentText)) {
      addFieldError("percentOff", PERCENT_NOT_WHOLE);
      valid = false;
    }
    let startsAt: string | null = null;
    if (!typed.startNow) {
      startsAt = moment("startsAt", MISSING_START);
      valid = valid && startsAt !== null;
    }
    if (!valid || endsAt === null) {
      return null;
    }
    return {
      name: typed.name,
      percentOff: Number(percentText),
      startsAt,
      endsAt,
      variantIds: typed.variants.map((variant) => variant.variantId),
    };
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

  /** Returns whether the promotion should be shown again as the API has it. */
  function conflictFailure(body: unknown): boolean {
    const type = problemField(body, "type")?.replace(PROBLEM_PREFIX, "") ?? null;
    const detail = problemField(body, "detail");
    switch (type) {
      case "promotion-overlap":
        conflicting.value = new Set(conflictingIds(body));
        error.value = detail ?? "Część wariantów jest już w innej promocji w tym okresie.";
        return false;
      case "promotion-not-editable":
        error.value = detail ?? "Tej promocji nie można teraz zmienić.";
        return true;
      case "about:blank":
        error.value = MODIFIED_MESSAGE;
        return true;
      default:
        error.value = detail ?? "Nie udało się wykonać operacji (HTTP 409).";
        return true;
    }
  }

  /** Returns whether the promotion should be shown again as the API has it. */
  function failure(status: number, body: unknown): boolean {
    switch (status) {
      case 409:
        return conflictFailure(body) && view.value !== null;
      case 422:
        validationFailure(body, status);
        return false;
      case 403:
        error.value = "Nie masz uprawnień do zmiany promocji.";
        return false;
      case 404:
        error.value = "Ta promocja już nie istnieje.";
        return false;
      case 503:
        error.value = "Usługa jest chwilowo niedostępna. Spróbuj ponownie.";
        return false;
      default:
        error.value = `Nie udało się wykonać operacji (HTTP ${status}).`;
        return false;
    }
  }

  type Outcome = { data?: PromotionView; error?: unknown; response: { status: number } };

  async function perform(allowed: boolean, request: () => Promise<Outcome> | null, success: string): Promise<ActionResult> {
    if (!allowed) {
      return null;
    }
    clearFeedback();
    const pending = request();
    if (pending === null) {
      return "failure";
    }
    busy.value = true;
    try {
      const result = await pending;
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
      if (failure(result.response.status, result.error)) {
        await reloadQuietly();
      }
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
    return perform(
      canSave.value,
      () => {
        const body = buildBody();
        if (body === null) {
          return null;
        }
        return current === null
          ? getApiClient().POST("/api/v1/admin/promotions", { body })
          : getApiClient().PUT("/api/v1/admin/promotions/{id}", { params: { path: { id: current.id } }, body: { ...body, version: current.version } });
      },
      "Promocja zapisana.",
    );
  }

  /** Ends an active promotion: sends the stored promotion with the end set to the time of the browser. */
  function endNow(): Promise<ActionResult> {
    const current = view.value;
    return perform(
      canEndNow.value && current !== null,
      () =>
        getApiClient().PUT("/api/v1/admin/promotions/{id}", {
          params: { path: { id: current!.id } },
          body: {
            name: current!.name,
            percentOff: current!.percentOff,
            startsAt: current!.startsAt,
            endsAt: new Date().toISOString(),
            variantIds: current!.variants.map((variant) => variant.variantId),
            version: current!.version,
          },
        }),
      "Promocja zakończona.",
    );
  }

  async function remove(): Promise<ActionResult> {
    const current = view.value;
    if (!canDelete.value || current === null) {
      return null;
    }
    clearFeedback();
    busy.value = true;
    try {
      const result = await getApiClient().DELETE("/api/v1/admin/promotions/{id}", {
        params: { path: { id: current.id }, query: { version: current.version } },
      });
      if (disposed) {
        return null;
      }
      if (result.response.status === 204) {
        options.onDeleted();
        return "success";
      }
      if (failure(result.response.status, result.error)) {
        await reloadQuietly();
      }
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

  /** Adds variants once each, after the ones already chosen. Only while the variants can change. */
  function addVariants(variants: PromotionVariant[]): void {
    if (readOnly.value || scheduleLocked.value) {
      return;
    }
    const known = new Set(form.value.variants.map((variant) => variant.variantId));
    const added = variants.filter((variant) => !known.has(variant.variantId) && known.add(variant.variantId));
    form.value.variants = [...form.value.variants, ...added];
  }

  function removeVariant(variantId: string): void {
    if (readOnly.value || scheduleLocked.value) {
      return;
    }
    form.value.variants = form.value.variants.filter((variant) => variant.variantId !== variantId);
    if (conflicting.value.has(variantId)) {
      const rest = new Set(conflicting.value);
      rest.delete(variantId);
      conflicting.value = rest;
    }
  }

  return {
    form,
    view,
    loaded,
    loadError,
    busy,
    dirty,
    status,
    readOnly,
    scheduleLocked,
    canSave,
    canEndNow,
    canDelete,
    message,
    error,
    errorsAt,
    isConflicting,
    save,
    endNow,
    remove,
    refresh,
    addVariants,
    removeVariant,
  };
}
