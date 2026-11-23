import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { OTHER_VERSION_MESSAGE, type ReadResult } from "@/composables/formRequests";
import { useFormSafety } from "@/composables/useFormSafety";
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
  /**
   * Called with „Wcześniejszy zapis został wykonany.” when the answer of an earlier save, which the staff member left behind
   * with „Sprawdź stan” or „Odblokuj formularz”, turns out to be a success: the view says so in a toast. Nothing else changes.
   */
  onLateSaved?: (message: string) => void;
}

/** How an action ended; null when it did not start or its answer came after the form was left. */
export type ActionResult = "success" | "failure" | null;

const PROBLEM_PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const FIELD_ROOTS = new Set(["code", "kind", "value", "minimumOrderGross", "startsAt", "endsAt", "usageLimit"]);

/** The version of a code changed while the staff member edited it: the draft stays. */
export const MODIFIED_MESSAGE = "Kod rabatowy został w międzyczasie zmieniony. Twoje zmiany zostają w formularzu.";
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

type Report = (path: string, title: string) => void;

interface Body {
  kind: Kind;
  value: number;
  minimumOrderGross: number | null;
  startsAt: string;
  endsAt: string | null;
  usageLimit: number | null;
  enabled: boolean;
}

/** Whether a code has started: only its end, `enabled` and a limit it already has can change then. */
function hasStarted(current: DiscountCodeView | null): boolean {
  if (current === null) {
    return false;
  }
  return current.status === "disabled" ? Date.parse(current.startsAt) <= Date.now() : current.status === "active" || current.status === "expired";
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
 * decides which fields are offered (a wrong guess is answered by a 409 with a message). A locked field is
 * sent as stored, whatever it holds. Times are typed and shown in Polish time and sent as UTC moments; a time that was
 * not edited is sent as the API had it, so its seconds are not lost. Amounts are typed in złoty and sent in grosze.
 *
 * The draft is only changed by the staff member (spec „Etap 2”). A save is run by `safety` (`useFormSafety`): the fields
 * are locked from the save to its answer (`locked`); a 409 `discount-code-taken` is shown at the code field and keeps what
 * was typed; any other 409 (the version changed, the change is not allowed) shows its message, keeps the draft, the base
 * and the version, offers „Odrzuć moje zmiany i pokaż stan z serwera” (`safety.restore`) and reads nothing by itself. No
 * answer in 10 s, an error before the answer and a 5xx are an unknown result (`safety.unknown`), never a message that the
 * save failed.
 */
export function useDiscountCodeForm(options: DiscountCodeFormOptions) {
  const form = ref<DiscountCodeFormData>(emptyForm());
  const view = ref<DiscountCodeView | null>(null);
  const baseline = ref(JSON.stringify(emptyForm()));
  const loaded = ref(false);
  const loadError = ref<string | null>(null);
  const message = ref<string | null>(null);
  const error = ref<string | null>(null);
  const fieldErrors = ref<Record<string, string[]>>({});

  // Only the newest load may change the state; an older one that finishes late is ignored.
  let latest = 0;

  // The requests that may replace the draft: a save, and the reads that lock the form (`safety`). Opening another code, or
  // the form of a new one, resets it, and the answer of a request sent for the previous one is then ignored; moving to
  // the page of the code just created does not (`open`).
  const safety = useFormSafety<DiscountCodeView, Body>({
    id: () => view.value?.id ?? options.discountCodeId(),
    read: fetchCode,
    version: () => view.value?.version ?? null,
    draftRequest: () => buildBody(form.value, view.value, null),
    servedRequest: (served) => buildBody(fromView(served), served, null),
    adopt: (served) => {
      apply(served);
      clearFeedback();
    },
    onLateSuccess: (text) => options.onLateSaved?.(text),
    compare: { instants: ["startsAt", "endsAt"] },
    newObject: { unknownMessage: "Nie wiemy, czy kod rabatowy został utworzony.", ownNote: "Możliwe, że utworzył go Twój wcześniejszy zapis." },
  });

  const busy = computed(() => safety.writing.value);
  const status = computed(() => view.value?.status ?? null);
  const readOnly = computed(() => !options.canManage());
  const codeLocked = computed(() => view.value !== null);
  const termsLocked = computed(() => hasStarted(view.value));
  const limitLocked = computed(() => hasStarted(view.value) && view.value?.usageLimit === null);
  const dirty = computed(() => JSON.stringify(form.value) !== baseline.value);
  const idle = computed(() => !busy.value && loaded.value && !safety.reading.value);
  /** Whether the fields are off: while a save runs (also past the limit, until the answer or „Sprawdź stan”) and while a read replaces the form. */
  const locked = computed(() => safety.locked.value);
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

  async function fetchCode(id: string): Promise<ReadResult<DiscountCodeView>> {
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

  function open(id: string | null): void {
    // The code just created is already shown; the route only moved to its page.
    if (id !== null && view.value?.id === id) {
      return;
    }
    // A request for the previous code no longer holds this one: its answer is ignored (`safety`).
    safety.reset();
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
    latest++;
    safety.dispose();
  });
  watch(
    () => options.discountCodeId(),
    (id) => open(id),
  );

  /**
   * Discards the changes and shows the code as the API has it now (`safety.restore`). The fields stay locked until the
   * answer, so nothing typed meanwhile is replaced by it; after 10 s, an error or an answer other than 200 the form stays
   * as it was, unlocked, with `safety.readFailed`.
   */
  async function refresh(): Promise<void> {
    await safety.restore();
  }

  /** Throws away the unsaved changes: the form goes back to the code as the API last sent it, without a request. */
  function discard(): void {
    const current = view.value;
    if (current === null || busy.value || locked.value) {
      return;
    }
    form.value = fromView(current);
    clearFeedback();
    safety.draftDiscarded();
  }

  /** A time as the API gets it: the stored moment when the text is what it shows, else the typed time as UTC. */
  function moment(typed: DiscountCodeFormData, current: DiscountCodeView | null, path: "startsAt" | "endsAt", report: Report | null): string | null {
    const local = typed[path];
    const stored = current?.[path];
    if (typeof stored === "string" && local === instantToWarsaw(stored)) {
      return stored;
    }
    const converted = warsawToInstant(local);
    if (converted === null) {
      report?.(path, IMPOSSIBLE_TIME);
    }
    return converted;
  }

  /** An amount typed in złoty as grosze, or null (with the error at the field). */
  function grosze(path: string, text: string, report: Report | null): number | null {
    const parsed = parseGross(text);
    if (!parsed.ok) {
      report?.(path, parsed.message);
      return null;
    }
    return parsed.grosze;
  }

  function wholeNumber(path: string, text: string, notWhole: string, report: Report | null): number | null {
    const trimmed = text.trim();
    if (!/^\d+$/.test(trimmed) || !Number.isSafeInteger(Number(trimmed))) {
      report?.(path, notWhole);
      return null;
    }
    return Number(trimmed);
  }

  /**
   * What the form sends for `typed` shown on `current`, or null when a typed value cannot be sent at all; `report` gets
   * the error and its field (null: ask without saying anything, as „Sprawdź stan” does). The same function builds the
   * request from the draft and from an object read from the API, which is how they are compared.
   */
  function buildBody(typed: DiscountCodeFormData, current: DiscountCodeView | null, report: Report | null): Body | null {
    const started = hasStarted(current);
    let valid = true;

    let endsAt: string | null = null;
    if (typed.endsAt.trim() !== "") {
      endsAt = moment(typed, current, "endsAt", report);
      valid = valid && endsAt !== null;
    }

    let usageLimit: number | null;
    if (started && current?.usageLimit === null) {
      usageLimit = null;
    } else if (typed.usageLimit.trim() === "") {
      usageLimit = null;
    } else {
      usageLimit = wholeNumber("usageLimit", typed.usageLimit, LIMIT_NOT_WHOLE, report);
      valid = valid && usageLimit !== null;
    }

    if (current !== null && started) {
      return valid ? { kind: current.kind, value: current.value, minimumOrderGross: current.minimumOrderGross, startsAt: current.startsAt, endsAt, usageLimit, enabled: typed.enabled } : null;
    }

    const value = typed.kind === "percent" ? wholeNumber("value", typed.value, PERCENT_NOT_WHOLE, report) : grosze("value", typed.value, report);
    const minimum = typed.minimum.trim() === "" ? null : grosze("minimumOrderGross", typed.minimum, report);
    valid = valid && value !== null && (typed.minimum.trim() === "" || minimum !== null);
    let startsAt: string | null = null;
    if (typed.startsAt.trim() === "") {
      report?.("startsAt", MISSING_START);
      valid = false;
    } else {
      startsAt = moment(typed, current, "startsAt", report);
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

  /** Returns whether the staff member may ask for the code as the API has it (`safety.offerRestore`). */
  function conflictFailure(body: unknown): boolean {
    const type = problemField(body, "type")?.replace(PROBLEM_PREFIX, "") ?? null;
    const detail = problemField(body, "detail");
    if (type === "discount-code-taken") {
      addFieldError("code", safety.withDuplicateNote(detail ?? "Taki kod już istnieje."));
      return false;
    }
    error.value = type === "about:blank" ? MODIFIED_MESSAGE : (detail ?? "Nie udało się wykonać operacji (HTTP 409).");
    return true;
  }

  /** Returns whether the staff member may ask for the code as the API has it. Only a 4xx reaches it: the rest is an unknown result. */
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
      default:
        error.value = `Nie udało się wykonać operacji (HTTP ${status}).`;
        return false;
    }
  }

  /**
   * Saves the form through `safety.write`. A 409 and a 422 show their message and keep the draft; an unknown result is
   * `safety.unknown`; the answer of a save for a code the form no longer shows is ignored (`null`).
   */
  async function save(): Promise<ActionResult> {
    if (!canSave.value) {
      return null;
    }
    const current = view.value;
    clearFeedback();
    const body = buildBody(form.value, current, addFieldError);
    if (body === null) {
      return "failure";
    }
    const result = await safety.write(
      () =>
        current === null
          ? getApiClient().POST("/api/v1/admin/discount-codes", { body: { ...body, code: form.value.code } })
          : getApiClient().PUT("/api/v1/admin/discount-codes/{id}", { params: { path: { id: current.id } }, body: { ...body, version: current.version } }),
      {
        onSuccess: (saved) => {
          const created = view.value === null;
          apply(saved);
          message.value = "Kod rabatowy zapisany.";
          if (created) {
            options.onCreated(saved.id);
          }
        },
        onRefused: (refusedStatus, refusedBody) => {
          if (failure(refusedStatus, refusedBody)) {
            // What this 409 says about the server, for the button that stays after „Odrzuć”.
            const ofVersion = problemField(refusedBody, "type") === "about:blank";
            safety.offerRestore(ofVersion || error.value === null ? OTHER_VERSION_MESSAGE : error.value);
          }
        },
      },
    );
    return result === null ? null : result === "success" ? "success" : "failure";
  }

  return {
    form,
    view,
    loaded,
    loadError,
    busy,
    locked,
    safety,
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
    discard,
  };
}
