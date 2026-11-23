import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { OTHER_VERSION_MESSAGE, type ReadResult } from "@/composables/formRequests";
import { useFormSafety } from "@/composables/useFormSafety";
import { formatDateTime } from "@/prices/format";
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
  /**
   * Called with „Promocja zakończona.” when the promotion that „Zakończ teraz” left active is read as ended, which is
   * after `endNow` has returned. Not called when the answer of the PUT was already `ended`: the view says that from the
   * result of the call.
   */
  onEnded?: (message: string) => void;
  /**
   * Called with „Wcześniejszy zapis został wykonany.” when the answer of an earlier save, which the staff member left behind
   * with „Sprawdź stan” or „Odblokuj formularz”, turns out to be a success: the view says so in a toast. Nothing else changes.
   */
  onLateSaved?: (message: string) => void;
}

/** How an action ended; null when it did not start or its answer came after the form was left. */
export type ActionResult = "success" | "failure" | null;

const PROBLEM_PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const FIELD_ROOTS = new Set(["name", "percentOff", "startsAt", "endsAt", "variantIds"]);

/** The version of a promotion changed while the staff member edited it: the draft stays. */
export const MODIFIED_MESSAGE = "Promocja została w międzyczasie zmieniona. Twoje zmiany zostają w formularzu.";
/** The same after an action that shows the promotion again („Zakończ teraz”, „Usuń”). */
export const MODIFIED_RELOADED_MESSAGE = "Promocja została w międzyczasie zmieniona – pokazujemy jej aktualny stan.";
const MISSING_END = "Podaj koniec promocji.";
const MISSING_START = "Podaj początek promocji albo wybierz „od teraz”.";
const IMPOSSIBLE_TIME = "Ta godzina nie istnieje w czasie polskim (zmiana czasu) albo data jest niepoprawna.";
const PERCENT_NOT_WHOLE = "Podaj procent jako liczbę całkowitą.";
const ENDED_MESSAGE = "Promocja zakończona.";
const END_READ_FAILED = "Nie udało się odczytać promocji po zakończeniu. Odśwież, żeby zobaczyć jej stan.";

/** The API sets the end a moment ahead of the browser: the view waits for it and one second more, then reads again. */
const END_READ_GRACE_MS = 1000;
/** A read that still says „active” (the clock of the browser differs from the server's) is repeated this often... */
const END_READ_RETRY_MS = 1000;
/** ...and this many times at most, after the first read. */
const END_READ_RETRIES = 5;

const STARTED_MESSAGE = "Promocja jest aktywna.";
const START_READ_FAILED = "Nie udało się odczytać statusu promocji. Odśwież, żeby zobaczyć jej stan.";
/** A save „od teraz” starts at the next whole second: the form reads one second after the start the API set... */
const START_READ_GRACE_MS = 1000;
/** ...but never later than this after the answer of the save, whatever the clock of the browser says... */
const START_READ_LATEST_MS = 3000;
/** ...and again this often while the answer is still „planned”, this many times at most after the first read... */
const START_READ_RETRY_MS = 1000;
const START_READ_RETRIES = 5;
/** ...and gives up this long after the answer of the save, also while a read has no answer (only the reads, not the save). */
const START_WAIT_LIMIT_MS = 10_000;

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

type Report = (path: string, title: string) => void;

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
 * The draft is only changed by the staff member (spec „Etap 2”). A save is run by `safety` (`useFormSafety`): a 409 of
 * a save (`promotion-overlap`, or the version changed, or the status does not allow the change) shows its message and
 * keeps the draft, the base and the version; `promotion-overlap` marks the variants of `conflictingVariantIds`, any other
 * 409 offers „Odrzuć moje zmiany i pokaż stan z serwera” (`safety.restore`) and reads nothing by itself. No answer in 10 s,
 * an error before the answer and a 5xx are an unknown result (`safety.unknown`), never a message that the save failed.
 * „Zakończ teraz” and „Usuń” show the promotion again after a 409, as before.
 */
export function usePromotionForm(options: PromotionFormOptions) {
  const form = ref<PromotionFormData>(emptyForm());
  const view = ref<PromotionView | null>(null);
  const baseline = ref(signature(emptyForm()));
  const loaded = ref(false);
  const loadError = ref<string | null>(null);
  // „Zakończ teraz” or „Usuń” runs (they do not lock the fields); a save is `safety.writing`.
  const actionBusy = ref(false);
  const message = ref<string | null>(null);
  const error = ref<string | null>(null);
  const fieldErrors = ref<Record<string, string[]>>({});
  const conflicting = ref<Set<string>>(new Set());
  // „Zakończ teraz” left the promotion active (the end is a moment ahead): the form waits and reads it again.
  const ending = ref(false);
  // Where the wait for the end stopped: the end the API set, or why the promotion could not be read.
  const endNotice = ref<string | null>(null);
  const endError = ref<string | null>(null);
  let endTimer: ReturnType<typeof setTimeout> | undefined;
  // A save „od teraz” answered „planned”: the form stays locked and reads the promotion again once it has started (#580).
  const starting = ref(false);
  // Where the wait for the start stopped: the promotion is active, it has not started yet, or it could not be read.
  const startNotice = ref<string | null>(null);
  const startError = ref<string | null>(null);
  let startTimer: ReturnType<typeof setTimeout> | undefined;
  let startLimit: ReturnType<typeof setTimeout> | undefined;

  // Only the newest load may change the state; an older one that finishes late is ignored.
  let latest = 0;
  // Set when the form is left: an answer that arrives later must not change it or send the staff member anywhere.
  let disposed = false;

  // The requests that may replace the draft: a save, and the reads that lock the form (`safety`). Opening another
  // promotion, or the form of a new one, resets it, and the answer of a request sent for the previous one is then
  // ignored; moving to the page of the promotion just created does not (`open`).
  const safety = useFormSafety<PromotionView, Body>({
    id: () => view.value?.id ?? options.promotionId(),
    read: fetchPromotion,
    version: () => view.value?.version ?? null,
    // „Od teraz” is a start the server sets: no request equals it.
    draftRequest: () => (form.value.startNow ? null : buildBody(form.value, view.value, null)),
    servedRequest: (served) => buildBody(fromView(served), served, null),
    adopt: (served) => {
      apply(served);
      clearFeedback();
    },
    onLateSuccess: (text) => options.onLateSaved?.(text),
    compare: { instants: ["startsAt", "endsAt"] },
    newObject: { unknownMessage: "Nie wiemy, czy promocja została utworzona.", ownNote: "Możliwe, że utworzył ją Twój wcześniejszy zapis." },
  });

  const busy = computed(() => actionBusy.value || safety.writing.value);
  const status = computed(() => view.value?.status ?? null);
  const readOnly = computed(() => !options.canManage() || status.value === "ended");
  const scheduleLocked = computed(() => status.value === "active");
  const dirty = computed(() => signature(form.value) !== baseline.value);
  const idle = computed(() => !busy.value && loaded.value && !safety.reading.value);
  /**
   * Whether the fields and the controls of the variants are off: while a save runs (also past the limit, until the
   * answer or „Sprawdź stan”), while a read replaces the form („Odśwież”, „Sprawdź stan”, „Odrzuć moje zmiany…”) and
   * while the form waits for the start.
   */
  const locked = computed(() => safety.locked.value || starting.value);
  const canSave = computed(() => idle.value && !ending.value && !starting.value && !readOnly.value && (view.value === null || dirty.value));
  const canEndNow = computed(() => idle.value && !ending.value && options.canManage() && status.value === "active" && !dirty.value);
  const canDelete = computed(() => idle.value && !starting.value && options.canManage() && status.value === "planned" && !dirty.value);

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

  function stopEnding(): void {
    clearTimeout(endTimer);
    endTimer = undefined;
    ending.value = false;
  }

  function stopStarting(): void {
    clearTimeout(startTimer);
    clearTimeout(startLimit);
    startTimer = undefined;
    startLimit = undefined;
    starting.value = false;
  }

  function clearFeedback(): void {
    message.value = null;
    error.value = null;
    endNotice.value = null;
    endError.value = null;
    startNotice.value = null;
    startError.value = null;
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

  async function fetchPromotion(id: string): Promise<ReadResult<PromotionView>> {
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
    // A request for the previous promotion no longer holds this one: its answer is ignored (`safety`, `perform`, `remove`).
    safety.reset();
    actionBusy.value = false;
    stopEnding();
    stopStarting();
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
    safety.dispose();
    stopEnding();
    stopStarting();
  });
  watch(
    () => options.promotionId(),
    (id) => open(id),
  );

  /**
   * „Odśwież”: discards the changes and shows the promotion as the API has it now (`safety.restore`). The fields stay
   * locked until the answer, so nothing typed meanwhile is replaced by it; after 10 s, an error or an answer other than
   * 200 the form stays as it was, unlocked, with `safety.readFailed`. An answer that comes after another promotion was
   * opened, the form was left or the limit passed is ignored.
   */
  async function refresh(): Promise<void> {
    stopEnding();
    stopStarting();
    // The reads of the end and of the start are stale now, and the messages of their failure are what „Odśwież” answers.
    latest++;
    endNotice.value = null;
    endError.value = null;
    startNotice.value = null;
    startError.value = null;
    await safety.restore();
  }

  /** Throws away the unsaved changes: the form goes back to the promotion as the API last sent it, without a request. */
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
  function moment(typed: PromotionFormData, current: PromotionView | null, path: "startsAt" | "endsAt", missing: string, report: Report | null): string | null {
    const local = typed[path];
    const stored = current?.[path];
    if (stored !== undefined && local === instantToWarsaw(stored)) {
      return stored;
    }
    if (local.trim() === "") {
      report?.(path, missing);
      return null;
    }
    const converted = warsawToInstant(local);
    if (converted === null) {
      report?.(path, IMPOSSIBLE_TIME);
    }
    return converted;
  }

  /**
   * What the form sends for `typed` shown on `current`, or null when a typed value cannot be sent at all; `report`
   * gets the error and its field (null: ask without saying anything, as „Sprawdź stan” does). The same function builds the
   * request from the draft and from an object read from the API, which is how they are compared.
   */
  function buildBody(typed: PromotionFormData, current: PromotionView | null, report: Report | null): Body | null {
    const endsAt = moment(typed, current, "endsAt", MISSING_END, report);
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
      report?.("percentOff", PERCENT_NOT_WHOLE);
      valid = false;
    }
    let startsAt: string | null = null;
    if (!typed.startNow) {
      startsAt = moment(typed, current, "startsAt", MISSING_START, report);
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

  /** Returns whether the promotion should be shown again as the API has it. `keepsDraft`: the 409 answers a save. */
  function conflictFailure(body: unknown, keepsDraft: boolean): boolean {
    const type = problemField(body, "type")?.replace(PROBLEM_PREFIX, "") ?? null;
    const detail = problemField(body, "detail");
    switch (type) {
      case "promotion-overlap":
        conflicting.value = new Set(conflictingIds(body));
        error.value = safety.withDuplicateNote(detail ?? "Część wariantów jest już w innej promocji w tym okresie.");
        return false;
      case "promotion-not-editable":
        error.value = detail ?? "Tej promocji nie można teraz zmienić.";
        return true;
      case "about:blank":
        error.value = keepsDraft ? MODIFIED_MESSAGE : MODIFIED_RELOADED_MESSAGE;
        return true;
      default:
        error.value = detail ?? "Nie udało się wykonać operacji (HTTP 409).";
        return true;
    }
  }

  /** Returns whether the promotion should be shown again as the API has it. `keepsDraft`: the answer is to a save. */
  function failure(status: number, body: unknown, keepsDraft = false): boolean {
    switch (status) {
      case 409:
        return conflictFailure(body, keepsDraft) && view.value !== null;
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

  /**
   * Sends a change that is not a save („Zakończ teraz”) and shows its answer. The fields are not locked. The answer is
   * ignored when the form was left or shows another promotion by then; the request also makes older reads stale (`latest`).
   */
  async function perform(
    allowed: boolean,
    request: () => Promise<Outcome> | null,
    success: string | ((saved: PromotionView) => string | null),
  ): Promise<ActionResult> {
    if (!allowed) {
      return null;
    }
    clearFeedback();
    const pending = request();
    if (pending === null) {
      return "failure";
    }
    const context = safety.context();
    latest++;
    actionBusy.value = true;
    try {
      const result = await pending;
      if (safety.stale(context)) {
        return null;
      }
      if (result.data) {
        apply(result.data);
        message.value = typeof success === "string" ? success : success(result.data);
        return "success";
      }
      if (failure(result.response.status, result.error)) {
        await reloadQuietly();
      }
      return "failure";
    } catch {
      if (safety.stale(context)) {
        return null;
      }
      error.value = "Nie udało się wykonać operacji. Sprawdź połączenie z API.";
      return "failure";
    } finally {
      if (!safety.stale(context)) {
        actionBusy.value = false;
      }
    }
  }

  /**
   * Saves the form through `safety.write`; a save „od teraz” that answers „planned” then waits for the start
   * (`watchStart`). A 409 and a 422 show their message and keep the draft; an unknown result is `safety.unknown`.
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
    const startsNow = body.startsAt === null;
    // The request makes older reads stale.
    latest++;
    const result = await safety.write(
      () =>
        current === null
          ? getApiClient().POST("/api/v1/admin/promotions", { body })
          : getApiClient().PUT("/api/v1/admin/promotions/{id}", { params: { path: { id: current.id } }, body: { ...body, version: current.version } }),
      {
        onSuccess: (saved) => {
          const created = view.value === null;
          apply(saved);
          if (startsNow && saved.status === "planned") {
            watchStart(saved);
          }
          message.value = "Promocja zapisana.";
          if (created) {
            options.onCreated(saved.id);
          }
        },
        onRefused: (refusedStatus, refusedBody) => {
          if (failure(refusedStatus, refusedBody, true)) {
            // What this 409 says about the server, for the button that stays after „Odrzuć”.
            const ofVersion = problemField(refusedBody, "type") === "about:blank";
            safety.offerRestore(ofVersion || error.value === null ? OTHER_VERSION_MESSAGE : error.value);
          }
        },
      },
    );
    return result === null ? null : result === "success" ? "success" : "failure";
  }

  /**
   * A save „od teraz” starts the promotion at the next whole second, so its answer is still „planned” (#580). The form
   * stays locked and reads the promotion one second after the start the API set by the clock of the browser, but never
   * later than 3 s after the answer of the save, then every second while it is still „planned”, at most 5 more times.
   * Any other answer (or the last „planned” one) is shown as it is, unlocks the form and says what it is in
   * `startNotice`; a failed read is `startError`. Whatever happens, the wait ends 10 s after the answer of the save with
   * `startError`, and a read that answers later is ignored (`latest`). Leaving the form, opening another promotion and
   * „Odśwież” stop it. The status always comes from the API: the clock only decides when to read.
   */
  function watchStart(saved: PromotionView): void {
    starting.value = true;
    let retries = 0;

    const read = async (): Promise<void> => {
      startTimer = undefined;
      const request = ++latest;
      const result = await fetchPromotion(saved.id);
      if (disposed || request !== latest || !starting.value) {
        return;
      }
      if (result === null || !("data" in result)) {
        stopStarting();
        startError.value = START_READ_FAILED;
        return;
      }
      if (result.data.status === "planned" && retries < START_READ_RETRIES) {
        retries++;
        startTimer = setTimeout(() => void read(), START_READ_RETRY_MS);
        return;
      }
      stopStarting();
      // Nothing could be typed while the form waited, so the answer replaces nothing of the staff member's.
      apply(result.data);
      if (result.data.status === "active") {
        startNotice.value = STARTED_MESSAGE;
      } else if (result.data.status === "planned") {
        startNotice.value = `Promocja jeszcze się nie rozpoczęła. Początek: ${formatDateTime(result.data.startsAt)}.`;
      }
    };

    startLimit = setTimeout(() => {
      stopStarting();
      // The read that is still running is stale now, like after a newer request of the form.
      latest++;
      startError.value = START_READ_FAILED;
    }, START_WAIT_LIMIT_MS);
    // One second after the start by the clock of the browser (at once when that has passed), at most 3 s from now.
    const untilRead = Date.parse(saved.startsAt) + START_READ_GRACE_MS - Date.now();
    const delay = Number.isFinite(untilRead) ? Math.min(Math.max(untilRead, 0), START_READ_LATEST_MS) : START_READ_LATEST_MS;
    startTimer = setTimeout(() => void read(), delay);
  }

  /**
   * Reads the promotion again once its end has passed (the end the API set, and one second more) and, while it is still
   * active because the clock of the browser differs from the server's, every second, at most 5 more times. An `ended`
   * answer shows the promotion as ended (the form becomes read-only) and says so through `onEnded`; the last `active`
   * one leaves it active with `endNotice` and shows that answer (the form, the view and its version); a failed read is
   * `endError`. While it waits, `ending` disables saving and ending. Leaving the form stops it. An `active` answer
   * before the last one does not replace what the staff member typed meanwhile.
   */
  function watchEnd(saved: PromotionView): void {
    ending.value = true;
    let retries = 0;

    const read = async (): Promise<void> => {
      endTimer = undefined;
      const request = ++latest;
      const result = await fetchPromotion(saved.id);
      if (disposed || request !== latest) {
        return;
      }
      if (result === null || !("data" in result)) {
        ending.value = false;
        endError.value = END_READ_FAILED;
        return;
      }
      if (result.data.status === "ended") {
        apply(result.data);
        ending.value = false;
        message.value = ENDED_MESSAGE;
        options.onEnded?.(ENDED_MESSAGE);
        return;
      }
      if (retries < END_READ_RETRIES) {
        retries++;
        endTimer = setTimeout(() => void read(), END_READ_RETRY_MS);
        return;
      }
      ending.value = false;
      // The state of the last answer (the form, the view and its version), also over what was typed meanwhile.
      apply(result.data);
      endNotice.value = `Koniec promocji ustawiony na ${formatDateTime(result.data.endsAt)}.`;
    };

    endTimer = setTimeout(() => void read(), Math.max(Date.parse(saved.endsAt) - Date.now(), 0) + END_READ_GRACE_MS);
  }

  /**
   * Ends an active promotion: sends the stored promotion with the end set to the time of the browser. The API sets the
   * end a moment ahead, so its answer is usually still `active`: the form then waits and reads the promotion again
   * (`watchEnd`, #304 F7). An answer that is already `ended` ends it at once.
   */
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
      (saved) => {
        if (saved.status === "active") {
          watchEnd(saved);
          return null;
        }
        return ENDED_MESSAGE;
      },
    );
  }

  async function remove(): Promise<ActionResult> {
    const current = view.value;
    if (!canDelete.value || current === null) {
      return null;
    }
    clearFeedback();
    const context = safety.context();
    actionBusy.value = true;
    try {
      const result = await getApiClient().DELETE("/api/v1/admin/promotions/{id}", {
        params: { path: { id: current.id }, query: { version: current.version } },
      });
      if (safety.stale(context)) {
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
      if (safety.stale(context)) {
        return null;
      }
      error.value = "Nie udało się wykonać operacji. Sprawdź połączenie z API.";
      return "failure";
    } finally {
      if (!safety.stale(context)) {
        actionBusy.value = false;
      }
    }
  }

  /** Adds variants once each, after the ones already chosen. Only while the variants can change. */
  function addVariants(variants: PromotionVariant[]): void {
    if (readOnly.value || scheduleLocked.value || locked.value) {
      return;
    }
    const known = new Set(form.value.variants.map((variant) => variant.variantId));
    const added = variants.filter((variant) => !known.has(variant.variantId) && known.add(variant.variantId));
    form.value.variants = [...form.value.variants, ...added];
  }

  function removeVariant(variantId: string): void {
    if (readOnly.value || scheduleLocked.value || locked.value) {
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
    locked,
    safety,
    dirty,
    status,
    readOnly,
    scheduleLocked,
    canSave,
    canEndNow,
    canDelete,
    message,
    error,
    ending,
    endNotice,
    endError,
    starting,
    startNotice,
    startError,
    errorsAt,
    isConflicting,
    save,
    endNow,
    remove,
    refresh,
    discard,
    addVariants,
    removeVariant,
  };
}
