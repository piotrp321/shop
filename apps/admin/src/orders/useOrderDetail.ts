import { computed, type MaybeRefOrGetter, onMounted, onUnmounted, ref, toValue, watch } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { noteRequired, type OrderTransition, transitionLabel } from "./format";

export type BackofficeOrder = components["schemas"]["BackofficeOrder"];

/** The only field of the confirmation dialogs that a 422 of the API can point at. */
export type ChangeField = "note";

/** How a change of the status or the completion of the refund ended; the messages are for the staff member. */
export type OrderChange =
  | { status: "saved" }
  | { status: "invalid"; fields: Partial<Record<ChangeField, string>>; message: string | null }
  | { status: "conflict"; message: string }
  | { status: "failed"; message: string };

export const CONFLICT_MESSAGE = "Zamówienie zostało w międzyczasie zmienione – sprawdź jego stan.";
export const NOT_FOUND_MESSAGE = "Nie znaleźliśmy zamówienia.";
export const FORBIDDEN_MESSAGE = "Nie masz dostępu do zamówień.";
const CHANGE_FORBIDDEN_MESSAGE = "Nie masz uprawnień do tej zmiany.";
const NOTE_REQUIRED_MESSAGE = "Podaj notatkę.";

/** A button of the order: the change of the status the API allows now. */
export interface OrderAction {
  transition: OrderTransition;
  label: string;
  noteRequired: boolean;
}

/** What differs between the two kinds of change in the messages. */
interface ChangeTexts {
  rejected: string;
  failed: string;
}

const STATUS_TEXTS: ChangeTexts = {
  rejected: "API odrzuciło zmianę statusu (HTTP 422).",
  failed: "Nie udało się zmienić statusu zamówienia",
};
const REFUND_TEXTS: ChangeTexts = {
  rejected: "API odrzuciło wykonanie zwrotu (HTTP 422).",
  failed: "Nie udało się oznaczyć zwrotu jako wykonanego",
};

function problemDetail(body: unknown): string | null {
  const detail = typeof body === "object" && body !== null ? (body as { detail?: unknown }).detail : undefined;
  return typeof detail === "string" && detail !== "" ? detail : null;
}

/**
 * A 422 of the API: a violation of `note` goes to the note field; a violation of any other path and the `detail` of
 * a 422 without violations are the dialog's message.
 */
function invalidChange(body: unknown, rejected: string): OrderChange {
  const fields: Partial<Record<ChangeField, string[]>> = {};
  const rest: string[] = [];
  const violations = typeof body === "object" && body !== null ? (body as { violations?: unknown }).violations : undefined;
  if (Array.isArray(violations)) {
    for (const violation of violations) {
      const { propertyPath, title } = (violation ?? {}) as { propertyPath?: unknown; title?: unknown };
      if (typeof title !== "string" || title === "") {
        continue;
      }
      if (propertyPath === "note") {
        (fields.note ??= []).push(title);
      } else {
        rest.push(title);
      }
    }
  }
  const hasViolations = fields.note !== undefined || rest.length > 0;
  return {
    status: "invalid",
    fields: fields.note ? { note: fields.note.join(" ") } : {},
    message: hasViolations ? (rest.length > 0 ? rest.join(" ") : null) : (problemDetail(body) ?? rejected),
  };
}

function loadFailureMessage(status: number): string {
  return status === 403 ? FORBIDDEN_MESSAGE : `Nie udało się pobrać zamówienia (HTTP ${status}).`;
}

function blankToNull(note: string | null): string | null {
  return note === null || note.trim() === "" ? null : note;
}

/**
 * One order of the panel. The buttons are what the API allows the staff member now (`allowedTransitions`,
 * `canCompleteRefund`); the panel decides nothing about statuses or roles.
 *
 * `changeStatus` and `completeRefund` send the `version` of the order that is shown and put the order the API answers
 * with in its place. A 409 (the order changed, the status does not allow the change, no refund to do) reloads the
 * order, so the next attempt is based on its current state, and reports the conflict. A note is required to cancel and
 * to register a return, which the dialog checks before it sends; the API checks it again. Only one change is sent at
 * a time.
 */
export function useOrderDetail(orderId: MaybeRefOrGetter<string>) {
  const order = ref<BackofficeOrder | null>(null);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);
  const notFound = ref(false);
  const saving = ref(false);

  // Only the newest request may change the state; an older one that finishes late is ignored.
  let latest = 0;

  const actions = computed<OrderAction[]>(() =>
    (order.value?.allowedTransitions ?? []).map((transition) => ({
      transition,
      label: transitionLabel(transition),
      noteRequired: noteRequired(transition),
    })),
  );
  const canCompleteRefund = computed(() => order.value?.canCompleteRefund ?? false);

  async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    try {
      const result = await getApiClient().GET("/api/v1/admin/orders/{orderId}", { params: { path: { orderId: toValue(orderId) } } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        order.value = result.data;
        loaded.value = true;
        error.value = null;
        forbidden.value = false;
        notFound.value = false;
      } else if (result.response.status === 404) {
        order.value = null;
        error.value = null;
        forbidden.value = false;
        notFound.value = true;
      } else {
        error.value = loadFailureMessage(result.response.status);
        forbidden.value = result.response.status === 403;
        notFound.value = false;
      }
    } catch {
      if (request === latest) {
        error.value = "Nie udało się pobrać zamówienia. Sprawdź połączenie z API.";
        forbidden.value = false;
        notFound.value = false;
      }
    } finally {
      if (request === latest) {
        loading.value = false;
      }
    }
  }

  function changeFailure(status: number, body: unknown, texts: ChangeTexts): OrderChange {
    switch (status) {
      case 422:
        return invalidChange(body, texts.rejected);
      case 403:
        return { status: "failed", message: CHANGE_FORBIDDEN_MESSAGE };
      case 404:
        return { status: "failed", message: NOT_FOUND_MESSAGE };
      default:
        return { status: "failed", message: `${texts.failed} (HTTP ${status}).` };
    }
  }

  /** Runs one change; `send` posts it and answers with the result of the request. */
  async function change(
    texts: ChangeTexts,
    send: (current: BackofficeOrder) => Promise<{ data?: BackofficeOrder; error?: unknown; response: { status: number } }>,
  ): Promise<OrderChange> {
    const current = order.value;
    if (current === null || saving.value) {
      return { status: "failed", message: current === null ? NOT_FOUND_MESSAGE : "Poprzednia zmiana jest jeszcze zapisywana." };
    }
    saving.value = true;
    try {
      const result = await send(current);
      if (result.data) {
        // An older read must not bring back the order as it was before this change.
        latest++;
        loading.value = false;
        order.value = result.data;
        error.value = null;
        return { status: "saved" };
      }
      if (result.response.status === 409) {
        await load();
        return { status: "conflict", message: CONFLICT_MESSAGE };
      }
      return changeFailure(result.response.status, result.error, texts);
    } catch {
      return { status: "failed", message: `${texts.failed}. Sprawdź połączenie z API.` };
    } finally {
      saving.value = false;
    }
  }

  function changeStatus(transition: OrderTransition, note: string | null): Promise<OrderChange> {
    const current = order.value;
    if (current !== null && !current.allowedTransitions.includes(transition)) {
      return Promise.resolve({ status: "failed", message: "Ta zmiana nie jest teraz dostępna dla tego zamówienia." });
    }
    const sent = blankToNull(note);
    if (noteRequired(transition) && sent === null) {
      return Promise.resolve({ status: "invalid", fields: { note: NOTE_REQUIRED_MESSAGE }, message: null });
    }
    return change(STATUS_TEXTS, (shown) =>
      getApiClient().POST("/api/v1/admin/orders/{orderId}/status-changes", {
        params: { path: { orderId: shown.orderId } },
        body: { transition, version: shown.version, note: sent },
      }),
    );
  }

  function completeRefund(note: string | null): Promise<OrderChange> {
    if (order.value !== null && !order.value.canCompleteRefund) {
      return Promise.resolve({ status: "failed", message: "Ta zmiana nie jest teraz dostępna dla tego zamówienia." });
    }
    const sent = blankToNull(note);
    return change(REFUND_TEXTS, (shown) =>
      getApiClient().POST("/api/v1/admin/orders/{orderId}/refund-completion", {
        params: { path: { orderId: shown.orderId } },
        body: { version: shown.version, note: sent },
      }),
    );
  }

  watch(
    () => toValue(orderId),
    () => {
      order.value = null;
      loaded.value = false;
      void load();
    },
  );

  onMounted(() => {
    void load();
  });

  onUnmounted(() => {
    latest++;
  });

  return {
    order,
    loading,
    loaded,
    error,
    forbidden,
    notFound,
    notFoundMessage: NOT_FOUND_MESSAGE,
    saving,
    actions,
    canCompleteRefund,
    retry: load,
    changeStatus,
    completeRefund,
  };
}
