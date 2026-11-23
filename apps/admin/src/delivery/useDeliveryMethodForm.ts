import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { OTHER_VERSION_MESSAGE, type ReadResult } from "@/composables/formRequests";
import { useFormSafety } from "@/composables/useFormSafety";
import { groszeToInput } from "@/discount-codes/format";
import { parseGross } from "@/prices/parseGross";

export type DeliveryMethodView = components["schemas"]["DeliveryMethodResponse"];

/** What the staff member edits: amounts are the typed text in złoty, the rest is text as typed. */
export interface DeliveryMethodFormData {
  name: string;
  description: string;
  price: string;
  /** Złoty; empty: no threshold. */
  freeFrom: string;
  active: boolean;
  position: string;
  /** Empty: no template. */
  trackingUrlTemplate: string;
}

export interface DeliveryMethodFormOptions {
  /** The id of the method being edited, null for a new one. Read again when the route changes. */
  methodId: () => string | null;
  /** Called after a save succeeded, so that the view can go back to the list. */
  onSaved: () => void;
  /**
   * Called with „Wcześniejszy zapis został wykonany.” when the answer of an earlier save, which the staff member left behind
   * with „Sprawdź stan” or „Odblokuj formularz”, turns out to be a success: the view says so in a toast. Nothing else changes.
   */
  onLateSaved?: (message: string) => void;
}

/** How an action ended; null when it did not start or its answer came after the form was left. */
export type ActionResult = "success" | "failure" | null;

const PROBLEM_PREFIX = "tag:piotrp321.github.io,2026:shop/problems/";
const FIELD_ROOTS = new Set(["name", "description", "priceGross", "freeFromGross", "active", "position", "trackingUrlTemplate"]);

export const SAVED_MESSAGE = "Metoda dostawy zapisana.";
/** The version of a method changed while the staff member edited it: the draft stays. */
export const MODIFIED_MESSAGE = "Metoda dostawy została w międzyczasie zmieniona. Twoje zmiany zostają w formularzu.";
const NAME_TAKEN_MESSAGE = "Metoda dostawy o tej nazwie już istnieje.";
const LIMIT_REACHED_MESSAGE = "Aktywnych metod dostawy może być najwyżej 20. Wyłącz inną metodę.";
const POSITION_MESSAGE = "Kolejność musi być liczbą całkowitą od 0 do 999.";

type Report = (path: string, title: string) => void;

function emptyForm(): DeliveryMethodFormData {
  return { name: "", description: "", price: "", freeFrom: "", active: true, position: "0", trackingUrlTemplate: "" };
}

function fromView(view: DeliveryMethodView): DeliveryMethodFormData {
  return {
    name: view.name,
    description: view.description,
    price: groszeToInput(view.priceGross),
    freeFrom: view.freeFromGross === null ? "" : groszeToInput(view.freeFromGross),
    active: view.active,
    position: String(view.position),
    trackingUrlTemplate: view.trackingUrlTemplate ?? "",
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
  name: string;
  description: string | null;
  priceGross: number;
  freeFromGross: number | null;
  active: boolean;
  position: number;
  trackingUrlTemplate: string | null;
}

/**
 * The delivery method form: loads a method (or starts an empty one), tracks unsaved changes, saves with the version of
 * the method it shows, and turns the answers of the API into messages at the right place. Amounts are typed in złoty and
 * sent in grosze (`parseGross`); an empty „Darmowa od”, description and link are sent as `null`. Every rule of the values
 * (lengths, characters, ranges, the form of the link) is the API's: its 422 answers land at the field they name. The
 * version comes from the API and the form only sends it back.
 *
 * Every 409 is shown above the form and keeps what was typed (spec „Etap 2”): the name that is taken and the limit of active
 * methods ask the staff member to change the name or to switch another method off; a changed version keeps the draft, the base
 * and the version, offers „Odrzuć moje zmiany i pokaż stan z serwera” (`safety.restore`) and reads nothing by itself.
 *
 * The draft is only changed by the staff member. A save is run by `safety` (`useFormSafety`): the fields are locked from the
 * save to its answer (`locked`). No answer in 10 s, an error before the answer and a 5xx are an unknown result
 * (`safety.unknown`), never a message that the save failed.
 */
export function useDeliveryMethodForm(options: DeliveryMethodFormOptions) {
  const form = ref<DeliveryMethodFormData>(emptyForm());
  const view = ref<DeliveryMethodView | null>(null);
  const baseline = ref(JSON.stringify(emptyForm()));
  const loaded = ref(false);
  const loadError = ref<string | null>(null);
  const message = ref<string | null>(null);
  const error = ref<string | null>(null);
  const fieldErrors = ref<Record<string, string[]>>({});

  // Only the newest load may change the state; an older one that finishes late is ignored.
  let latest = 0;

  // The requests that may replace the draft: a save, and the reads that lock the form (`safety`). Opening another method, or
  // the form of a new one, resets it, and the answer of a request sent for the previous one is then ignored.
  const safety = useFormSafety<DeliveryMethodView, Body>({
    id: () => view.value?.id ?? options.methodId(),
    read: fetchMethod,
    version: () => view.value?.version ?? null,
    draftRequest: () => buildBody(form.value, null),
    servedRequest: (served) => buildBody(fromView(served), null),
    adopt: (served) => {
      apply(served);
      clearFeedback();
    },
    onLateSuccess: (text) => options.onLateSaved?.(text),
    newObject: { unknownMessage: "Nie wiemy, czy metoda dostawy została utworzona.", ownNote: "Możliwe, że utworzył ją Twój wcześniejszy zapis." },
  });

  const busy = computed(() => safety.writing.value);
  /** Whether the fields are off: while a save runs (also past the limit, until the answer or „Sprawdź stan”) and while a read replaces the form. */
  const locked = computed(() => safety.locked.value);
  const dirty = computed(() => JSON.stringify(form.value) !== baseline.value);
  const canSave = computed(() => !busy.value && !safety.reading.value && loaded.value && (view.value === null || dirty.value));

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

  function apply(data: DeliveryMethodView): void {
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

  async function fetchMethod(id: string): Promise<ReadResult<DeliveryMethodView>> {
    try {
      const result = await getApiClient().GET("/api/v1/admin/delivery-methods/{id}", { params: { path: { id } } });
      return result.data ? { data: result.data } : { status: result.response.status };
    } catch {
      return null;
    }
  }

  async function loadMethod(id: string): Promise<void> {
    const request = ++latest;
    loadError.value = null;
    const result = await fetchMethod(id);
    if (request !== latest) {
      return;
    }
    if (result !== null && "data" in result) {
      apply(result.data);
      loaded.value = true;
    } else if (result === null) {
      loadError.value = "Nie udało się pobrać metody dostawy. Sprawdź połączenie z API.";
    } else if (result.status === 404) {
      loadError.value = "Ta metoda dostawy nie istnieje.";
    } else if (result.status === 403) {
      loadError.value = "Nie masz dostępu do metod dostawy.";
    } else {
      loadError.value = `Nie udało się pobrać metody dostawy (HTTP ${result.status}).`;
    }
  }

  function open(id: string | null): void {
    // A request for the previous method no longer holds this one: its answer is ignored (`safety`).
    safety.reset();
    clearFeedback();
    if (id === null) {
      latest++;
      startNew();
    } else {
      loaded.value = false;
      view.value = null;
      void loadMethod(id);
    }
  }

  onMounted(() => open(options.methodId()));
  onBeforeUnmount(() => {
    latest++;
    safety.dispose();
  });
  watch(
    () => options.methodId(),
    (id) => open(id),
  );

  /** Throws away the unsaved changes: the form goes back to the method as the API last sent it, without a request. */
  function discard(): void {
    const current = view.value;
    if (current === null || busy.value || locked.value) {
      return;
    }
    form.value = fromView(current);
    clearFeedback();
    safety.draftDiscarded();
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

  /**
   * What the form sends for `typed`, or null when a typed value cannot be sent at all; `report` gets the error and its field
   * (null: ask without saying anything, as „Sprawdź stan” does). The same function builds the request from the draft and
   * from an object read from the API, which is how they are compared.
   */
  function buildBody(typed: DeliveryMethodFormData, report: Report | null): Body | null {
    const priceGross = grosze("priceGross", typed.price, report);
    const freeFromGross = typed.freeFrom.trim() === "" ? null : grosze("freeFromGross", typed.freeFrom, report);
    const positionText = typed.position.trim();
    const position = /^\d+$/.test(positionText) && Number.isSafeInteger(Number(positionText)) ? Number(positionText) : null;
    if (position === null) {
      report?.("position", POSITION_MESSAGE);
    }
    if (priceGross === null || position === null || (typed.freeFrom.trim() !== "" && freeFromGross === null)) {
      return null;
    }
    return {
      name: typed.name,
      description: typed.description.trim() === "" ? null : typed.description,
      priceGross,
      freeFromGross,
      active: typed.active,
      position,
      trackingUrlTemplate: typed.trackingUrlTemplate.trim() === "" ? null : typed.trackingUrlTemplate,
    };
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

  /** Returns whether the staff member may ask for the method as the API has it (`safety.offerRestore`). */
  function conflictFailure(body: unknown): boolean {
    const type = problemField(body, "type")?.replace(PROBLEM_PREFIX, "") ?? null;
    const detail = problemField(body, "detail");
    switch (type) {
      case "delivery-method-name-taken":
        error.value = safety.withDuplicateNote(detail ?? NAME_TAKEN_MESSAGE);
        return false;
      case "delivery-method-limit-reached":
        error.value = detail ?? LIMIT_REACHED_MESSAGE;
        return false;
      case "about:blank":
        error.value = MODIFIED_MESSAGE;
        return true;
      default:
        error.value = detail ?? "Nie udało się zapisać metody dostawy (HTTP 409).";
        return false;
    }
  }

  /** Returns whether the staff member may ask for the method as the API has it. Only a 4xx reaches it: the rest is an unknown result. */
  function failure(status: number, body: unknown): boolean {
    switch (status) {
      case 409:
        return conflictFailure(body) && view.value !== null;
      case 422:
        validationFailure(body, status);
        return false;
      case 403:
        error.value = "Nie masz uprawnień do zmiany metod dostawy.";
        return false;
      case 404:
        error.value = "Ta metoda dostawy już nie istnieje.";
        return false;
      default:
        error.value = `Nie udało się zapisać metody dostawy (HTTP ${status}).`;
        return false;
    }
  }

  /**
   * Saves the form through `safety.write`. A 409 and a 422 show their message and keep the draft; an unknown result is
   * `safety.unknown`; the answer of a save for a method the form no longer shows is ignored (`null`).
   */
  async function save(): Promise<ActionResult> {
    if (!canSave.value) {
      return null;
    }
    const current = view.value;
    clearFeedback();
    const body = buildBody(form.value, addFieldError);
    if (body === null) {
      return "failure";
    }
    const result = await safety.write(
      () =>
        current === null
          ? getApiClient().POST("/api/v1/admin/delivery-methods", { body })
          : getApiClient().PUT("/api/v1/admin/delivery-methods/{id}", {
              params: { path: { id: current.id } },
              body: { ...body, version: current.version },
            }),
      {
        onSuccess: (saved) => {
          apply(saved);
          message.value = SAVED_MESSAGE;
          options.onSaved();
        },
        onRefused: (status, refusedBody) => {
          if (failure(status, refusedBody)) {
            // What this 409 says about the server, for the button that stays after „Odrzuć”.
            safety.offerRestore(OTHER_VERSION_MESSAGE);
          }
        },
      },
    );
    return result === null ? null : result === "success" ? "success" : "failure";
  }

  return { form, view, loaded, loadError, busy, locked, safety, dirty, canSave, message, error, errorsAt, save, discard };
}
