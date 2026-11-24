/**
 * What every form of the panel shares about its requests (docs/features/admin-ux-fixes/spec.md, „Etap 2”): the one limit
 * of 10 s, the verdict on the answer of a write, the comparison of two requests and the texts of the messages. The state
 * that goes with them is in `useFormSafety`.
 */

/** A read that locks a form and the message of a save in progress give up after this long. */
export const REQUEST_LIMIT_MS = 10_000;

export const READING_MESSAGE = "Odświeżamy…";
export const READ_FAILED_MESSAGE = "Nie udało się odczytać stanu. Spróbuj ponownie.";
export const UNKNOWN_RESULT_MESSAGE = "Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.";
/** The unknown result after the draft was discarded: the changes no longer stay in the form. */
export const UNKNOWN_DISCARDED_MESSAGE = "Nie wiemy, czy zapis się powiódł.";
/** The unknown result of an earlier save, said again after a newer save was refused: the newer one is not the one in question. */
export const UNKNOWN_EARLIER_MESSAGE = "Nie wiemy, czy wcześniejszy zapis się powiódł. Twoje zmiany zostają w formularzu.";
/** The same after the draft was discarded: nothing stays in the form, so nothing is promised. */
export const UNKNOWN_EARLIER_DISCARDED_MESSAGE = "Nie wiemy, czy wcześniejszy zapis się powiódł.";
export const NOT_ON_SERVER_MESSAGE = "Zapisu nie widać na serwerze. Może jeszcze dotrzeć.";
export const NOT_ON_SERVER_EARLIER_MESSAGE = "Wcześniejszego zapisu nie widać na serwerze. Może jeszcze dotrzeć.";
export const OTHER_VERSION_MESSAGE = "Na serwerze jest inna wersja niż ta, którą edytujesz.";
export const MATCHES_MESSAGE = "Stan na serwerze odpowiada formularzowi.";
export const LATE_SAVE_MESSAGE = "Wcześniejszy zapis został wykonany.";
export const RESTORE_LABEL = "Odrzuć moje zmiany i pokaż stan z serwera";
/** After the message of a new object: the staff member must look at the list before saving again. */
export const CHECK_LIST_MESSAGE = "Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.";

/** The answer of the API client to a write: `data` for a 2xx with a body, `error` for the problem of a 4xx or 5xx. */
export interface WriteOutcome<T> {
  data?: T;
  error?: unknown;
  response: { status: number };
}

/** What a read gives: the object, the status of a refusal, or null when the request did not reach the API. */
export type ReadResult<T> = { data: T } | { status: number } | null;

export type WriteVerdict<T> = { kind: "success"; data: T } | { kind: "refused"; status: number; body: unknown } | { kind: "unknown" };

/**
 * Says what an answer proves. A 2xx with data confirms the write; a 4xx refuses it (the API did not do it). Everything
 * else, an error before an answer, a 5xx and a 2xx without data, proves nothing: the write may or may not have been done.
 */
export async function settleWrite<T>(request: Promise<WriteOutcome<T>>): Promise<WriteVerdict<T>> {
  try {
    const outcome = await request;
    const status = outcome.response.status;
    if (status >= 200 && status < 300 && outcome.data !== undefined) {
      return { kind: "success", data: outcome.data };
    }
    if (status >= 400 && status < 500) {
      return { kind: "refused", status, body: outcome.error };
    }
    return { kind: "unknown" };
  } catch {
    return { kind: "unknown" };
  }
}

export interface RequestComparison {
  /** Names of the fields that hold a moment in time: two texts are the same when they are the same moment. */
  instants?: readonly string[];
  /**
   * Names of the fields the server fills in (an id of a row that was new): a null in the draft equals a value there that
   * the base of the draft did not have (`knownServerValues`). Only on the draft side: a null on the server is never taken
   * for a draft with a value.
   */
  serverAssigned?: readonly string[];
  /**
   * The values of `serverAssigned` fields that the base of the draft already had (the ids of its rows). A row of the draft
   * without an id is a new row, so it is not the row of the server that carries one of them: the server would have given
   * it a new id and dropped the old one.
   */
  knownServerValues?: readonly unknown[];
}

/**
 * Whether the request built from the draft is the request built from the object on the server: strictly, field by field,
 * lists in the order of the draft. `instants` and `serverAssigned` name the only exceptions.
 */
export function sameRequest(draft: unknown, served: unknown, options: RequestComparison = {}): boolean {
  return same(draft, served, undefined, options);
}

function same(draft: unknown, served: unknown, key: string | undefined, options: RequestComparison): boolean {
  if (key !== undefined && options.serverAssigned?.includes(key) && draft === null && served !== undefined) {
    return !(options.knownServerValues ?? []).includes(served);
  }
  if (Array.isArray(draft) || Array.isArray(served)) {
    return (
      Array.isArray(draft) &&
      Array.isArray(served) &&
      draft.length === served.length &&
      draft.every((item, index) => same(item, served[index], key, options))
    );
  }
  if (isRecord(draft) || isRecord(served)) {
    if (!isRecord(draft) || !isRecord(served)) {
      return false;
    }
    const names = new Set([...definedKeys(draft), ...definedKeys(served)]);
    return [...names].every((name) => name in draft && name in served && same(draft[name], served[name], name, options));
  }
  if (key !== undefined && options.instants?.includes(key) && typeof draft === "string" && typeof served === "string") {
    const left = Date.parse(draft);
    const right = Date.parse(served);
    if (!Number.isNaN(left) && !Number.isNaN(right)) {
      return left === right;
    }
  }
  return draft === served;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function definedKeys(record: Record<string, unknown>): string[] {
  return Object.keys(record).filter((name) => record[name] !== undefined);
}
