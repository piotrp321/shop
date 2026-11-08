import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { parseGross } from "@/prices/parseGross";
import { instantToWarsaw, warsawToInstant } from "@/promotions/time";
import { groszeToInput } from "./format";

export type DiscountCodeView = components["schemas"]["DiscountCodeResponse"];
type Kind = DiscountCodeView["kind"];

/** What the staff member edits. Times are Polish local times (`YYYY-MM-DDTHH:mm`), amounts are the typed text. */
export interface DiscountCodeFormData {
  code: string;
  kind: Kind;
  /** A whole percent for a percent code, złoty for an amount code. */
  value: string;
  /** Złoty; empty: no minimum. */
  minimum: string;
  startsAt: string;
  /** Empty: no end. */
  endsAt: string;
  /** Empty: no limit. */
  usageLimit: string;
  enabled: boolean;
}

export interface DiscountCodeFormOptions {
  /** The id of the code being edited, null for a new one. Read again when the route changes. */
  discountCodeId: () => string | null;
  /** Whether the staff member may change discount codes (admin or marketing); the API checks it too. */
  canManage: () => boolean;
  /** Called with the id of a code just created, so that the view can move to its page. */
  onCreated: (id: string) => void;
}

/** How an action ended; null when it did not start or its answer came after the form was left. */
export type ActionResult = "success" | "failure" | null;

const PROBLEM_PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const FIELD_ROOTS = new Set(["code", "kind", "value", "minimumOrderGross", "startsAt", "endsAt", "usageLimit"]);

export const MODIFIED_MESSAGE = "Kod rabatowy został w międzyczasie zmieniony – pokazujemy jego aktualny stan.";
const MISSING_START = "Podaj początek kodu.";
const IMPOSSIBLE_TIME = "Ta godzina nie istnieje w czasie polskim (zmiana czasu) albo data jest niepoprawna.";
const PERCENT_NOT_WHOLE = "Podaj procent jako liczbę całkowitą.";
const LIMIT_NOT_WHOLE = "Podaj limit jako liczbę całkowitą.";

function emptyForm(): DiscountCodeFormData {
  return { code: "", kind: "percent", value: "", minimum: "", startsAt: "", endsAt: "", usageLimit: "", enabled: true };
}

function fromView(view: DiscountCodeView): DiscountCodeFormData {
  return {
    code: view.code,
    kind: view.kind,
    value: view.kind === "percent" ? String(view.value) : groszeToInput(view.value),
    minimum: view.minimumOrderGross === null ? "" : groszeToInput(view.minimumOrderGross),
    startsAt: instantToWarsaw(view.startsAt),
    endsAt: view.endsAt === null ? "" : instantToWarsaw(view.endsAt),
    usageLimit: view.usageLimit === null ? "" : String(view.usageLimit),
    enabled: view.enabled,
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

interface Body {
  kind: Kind;
  value: number;
  minimumOrderGross: number | null;
  startsAt: string;
  endsAt: string | null;
  usageLimit: number | null;
  enabled: boolean;
}

/**
 * The discount code form: loads a code (or starts an empty one), tracks unsaved changes, saves with the version of the
 * view it shows, and turns the answers of the API into messages at the right place. The status, the active uses and the
 * version come from the API; the form only holds and sends what the staff member typed.
 *
 * What can change follows the rules of the API: the text of a code never changes (`codeLocked`, only a new code has it);
 * a planned code changes in everything else; once a code has started, only its end, `enabled` and a limit it already
 * has change (`termsLocked`: kind, value, minimum and start; `limitLocked`: a limit cannot be added). A started code is
 * `active` or `expired`; a disabled one is started when its start has passed by the clock of the browser, which only
 * decides which fields are offered (a wrong guess is answered by a 409 with a message and a reload). A locked field is
 * sent as stored, whatever it holds. Times are typed and shown in Polish time and sent as UTC moments; a time that was
 * not edited is sent as the API had it, so its seconds are not lost. Amounts are typed in złoty and sent in grosze.
 *
 * A 409 `discount-code-taken` is shown at the code field and keeps what was typed. Any other 409 (the version changed,
 * the change is not allowed) shows its message and reloads the code.
 */
export function useDiscountCodeForm(options: DiscountCodeFormOptions) {
  const form = ref<DiscountCodeFormData>(emptyForm());
  const view = ref<DiscountCodeView | null>(null);
  const baseline = ref(JSON.stringify(emptyForm()));
  const loaded = ref(false);
  const loadError = ref<string | null>(null);
  const busy = ref(false);
  const message = ref<string | null>(null);
  const error = ref<string | null>(null);
  const fieldErrors = ref<Record<string, string[]>>({});

  // Only the newest load may change the state; an older one that finishes late is ignored.
  let latest = 0;
  // Set when the form is left: an answer that arrives later must not change it or send the staff member anywhere.
  let disposed = false;

  const status = computed(() => view.value?.status ?? null);
  const readOnly = computed(() => !options.canManage());
  const codeLocked = computed(() => view.value !== null);
  const hasStarted = computed(() => {
    const current = view.value;
    if (current === null) {
      return false;
    }
    return current.status === "disabled" ? Date.parse(current.startsAt) <= Date.now() : current.status === "active" || current.status === "expired";
  });
  const termsLocked = computed(() => hasStarted.value);
  const limitLocked = computed(() => hasStarted.value && view.value?.usageLimit === null);
  const dirty = computed(() => JSON.stringify(form.value) !== baseline.value);
  const idle = computed(() => !busy.value && loaded.value);
  const canSave = computed(() => idle.value && !readOnly.value && (view.value === null || dirty.value));

  /** The messages for a field path. */
  function errorsAt(path: string): string[] {
    return fieldErrors.value[path] ?? [];
  }

  function addFieldError(path: string, title: string): void {
    fieldErrors.value = { ...fieldErrors.value, [path]: [...(fieldErrors.value[path] ?? []), title] };
  }

  function clearFeedback(): void {
    message.value = null;
    error.value = null;
    fieldErrors.value = {};
  }

  function apply(data: DiscountCodeView): void {
    form.value = fromView(data);
    view.value = data;
    baseline.value = JSON.stringify(form.value);
  }

  function startNew(): void {
    form.value = emptyForm();
    view.value = null;
    baseline.value = JSON.stringify(form.value);
    loadError.value = null;
    loaded.value = true;
  }

  async function fetchCode(id: string): Promise<{ data: DiscountCodeView } | { status: number } | null> {
    try {
      const result = await getApiClient().GET("/api/v1/admin/discount-codes/{id}", { params: { path: { id } } });
      return result.data ? { data: result.data } : { status: result.response.status };
    } catch {
      return null;
    }
  }

  async function loadCode(id: string): Promise<void> {
    const request = ++latest;
    loadError.value = null;
    const result = await fetchCode(id);
    if (request !== latest) {
      return;
    }
    if (result !== null && "data" in result) {
      apply(result.data);
      loaded.value = true;
    } else if (result === null) {
      loadError.value = "Nie udało się pobrać kodu rabatowego. Sprawdź połączenie z API.";
    } else {
      loadError.value =
        result.status === 404 ? "Ten kod rabatowy nie istnieje." : `Nie udało się pobrać kodu rabatowego (HTTP ${result.status}).`;
    }
  }

  /** Shows the code as the API has it, keeping the message that explains why; a failed reload changes nothing. */
  async function reloadQuietly(): Promise<void> {
    const id = view.value?.id;
    if (id === undefined) {
      return;
    }
    const request = ++latest;
    const result = await fetchCode(id);
    if (request === latest && !disposed && result !== null && "data" in result) {
      apply(result.data);
    }
  }

  function open(id: string | null): void {
    // The code just created is already shown; the route only moved to its page.
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
      void loadCode(id);
    }
  }

  onMounted(() => open(options.discountCodeId()));
  onBeforeUnmount(() => {
    disposed = true;
    latest++;
  });
  watch(
    () => options.discountCodeId(),
    (id) => open(id),
  );

  /** Discards the changes and shows the code as the API has it now. */
  async function refresh(): Promise<void> {
    const id = view.value?.id ?? options.discountCodeId();
    clearFeedback();
    if (id !== null) {
      await loadCode(id);
    }
  }

  /** A time as the API gets it: the stored moment when the text is what it shows, else the typed time as UTC. */
  function moment(path: "startsAt" | "endsAt"): string | null {
    const local = form.value[path];
    const stored = view.value?.[path];
    if (typeof stored === "string" && local === instantToWarsaw(stored)) {
      return stored;
    }
    const converted = warsawToInstant(local);
    if (converted === null) {
      addFieldError(path, IMPOSSIBLE_TIME);
    }
    return converted;
  }

  /** An amount typed in złoty as grosze, or null (with the error at the field). */
  function grosze(path: string, text: string): number | null {
    const parsed = parseGross(text);
    if (!parsed.ok) {
      addFieldError(path, parsed.message);
      return null;
    }
    return parsed.grosze;
  }

  function wholeNumber(path: string, text: string, notWhole: string): number | null {
    const trimmed = text.trim();
    if (!/^\d+$/.test(trimmed) || !Number.isSafeInteger(Number(trimmed))) {
      addFieldError(path, notWhole);
      return null;
    }
    return Number(trimmed);
  }

  /** What the form sends, or null (with the errors at the fields) when a typed value cannot be sent at all. */
  function buildBody(): Body | null {
    const current = view.value;
    const typed = form.value;
    let valid = true;

    let endsAt: string | null = null;
    if (typed.endsAt.trim() !== "") {
      endsAt = moment("endsAt");
      valid = valid && endsAt !== null;
    }

    let usageLimit: number | null;
    if (limitLocked.value) {
      usageLimit = null;
    } else if (typed.usageLimit.trim() === "") {
      usageLimit = null;
    } else {
      usageLimit = wholeNumber("usageLimit", typed.usageLimit, LIMIT_NOT_WHOLE);
      valid = valid && usageLimit !== null;
    }

    if (current !== null && termsLocked.value) {
      return valid ? { kind: current.kind, value: current.value, minimumOrderGross: current.minimumOrderGross, startsAt: current.startsAt, endsAt, usageLimit, enabled: typed.enabled } : null;
    }

    const value = typed.kind === "percent" ? wholeNumber("value", typed.value, PERCENT_NOT_WHOLE) : grosze("value", typed.value);
    const minimum = typed.minimum.trim() === "" ? null : grosze("minimumOrderGross", typed.minimum);
    valid = valid && value !== null && (typed.minimum.trim() === "" || minimum !== null);
    let startsAt: string | null = null;
    if (typed.startsAt.trim() === "") {
      addFieldError("startsAt", MISSING_START);
      valid = false;
    } else {
      startsAt = moment("startsAt");
      valid = valid && startsAt !== null;
    }
    if (!valid || value === null || startsAt === null) {
      return null;
    }
    return { kind: typed.kind, value, minimumOrderGross: minimum, startsAt, endsAt, usageLimit, enabled: typed.enabled };
  }

  function validationFailure(body: unknown, status: number): void {
    const violations = violationsOf(body);
    const unplaced: string[] = [];
    for (const { propertyPath, title } of violations) {
      if (FIELD_ROOTS.has(propertyPath)) {
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

  /** Returns whether the code should be shown again as the API has it. */
  function conflictFailure(body: unknown): boolean {
    const type = problemField(body, "type")?.replace(PROBLEM_PREFIX, "") ?? null;
    const detail = problemField(body, "detail");
    if (type === "discount-code-taken") {
      addFieldError("code", detail ?? "Taki kod już istnieje.");
      return false;
    }
    error.value = detail ?? (type === "about:blank" ? MODIFIED_MESSAGE : "Nie udało się wykonać operacji (HTTP 409).");
    return true;
  }

  /** Returns whether the code should be shown again as the API has it. */
  function failure(status: number, body: unknown): boolean {
    switch (status) {
      case 409:
        return conflictFailure(body) && view.value !== null;
      case 422:
        validationFailure(body, status);
        return false;
      case 403:
        error.value = "Nie masz uprawnień do zmiany kodów rabatowych.";
        return false;
      case 404:
        error.value = "Ten kod rabatowy już nie istnieje.";
        return false;
      case 503:
        error.value = "Usługa jest chwilowo niedostępna. Spróbuj ponownie.";
        return false;
      default:
        error.value = `Nie udało się wykonać operacji (HTTP ${status}).`;
        return false;
    }
  }

  async function save(): Promise<ActionResult> {
    if (!canSave.value) {
      return null;
    }
    const current = view.value;
    clearFeedback();
    const body = buildBody();
    if (body === null) {
      return "failure";
    }
    busy.value = true;
    try {
      const result =
        current === null
          ? await getApiClient().POST("/api/v1/admin/discount-codes", { body: { ...body, code: form.value.code } })
          : await getApiClient().PUT("/api/v1/admin/discount-codes/{id}", {
              params: { path: { id: current.id } },
              body: { ...body, version: current.version },
            });
      if (disposed) {
        return null;
      }
      if (result.data) {
        apply(result.data);
        message.value = "Kod rabatowy zapisany.";
        if (current === null) {
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

  return {
    form,
    view,
    loaded,
    loadError,
    busy,
    dirty,
    status,
    readOnly,
    codeLocked,
    termsLocked,
    limitLocked,
    canSave,
    message,
    error,
    errorsAt,
    save,
    refresh,
  };
}
