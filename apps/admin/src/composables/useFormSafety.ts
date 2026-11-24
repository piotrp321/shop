import { type Ref, computed, ref, shallowRef, watch } from "vue";
import {
  CHECK_LIST_MESSAGE,
  LATE_SAVE_MESSAGE,
  NOT_ON_SERVER_MESSAGE,
  OTHER_VERSION_MESSAGE,
  READING_MESSAGE,
  MATCHES_MESSAGE,
  REQUEST_LIMIT_MS,
  UNKNOWN_DISCARDED_MESSAGE,
  UNKNOWN_RESULT_MESSAGE,
  type ReadResult,
  type RequestComparison,
  type WriteOutcome,
  sameRequest,
  settleWrite,
} from "./formRequests";

export interface FormSafetyOptions<TView extends { version: number }, TBody> {
  /** The id of the object the form shows, null for a new one. Read when a request starts and when it ends. */
  id: () => string | null;
  /** Reads the object as the API has it. Must not throw; null means the request did not reach the API. */
  read: (id: string) => Promise<ReadResult<TView>>;
  /** The version the form edits (the base of its draft), null for a new object. */
  version: () => number | null;
  /**
   * The request the form would send for its draft right now, built without touching the messages of the form; null when
   * none can be built (a value cannot be sent, or it is one the server sets: „Od teraz”, an empty slug).
   */
  draftRequest: () => TBody | null;
  /** The request the same function builds for an object read from the API (without the version); null when none can. */
  servedRequest: (view: TView) => TBody | null;
  /** Shows the object: replaces the draft, its base and the version, and clears the messages of the form. */
  adopt: (view: TView) => void;
  /** Tells the staff member that an earlier save was done after all (a toast); gets `LATE_SAVE_MESSAGE`. */
  onLateSuccess: (message: string) => void;
  /**
   * The names of the fields that hold moments and of the ones the server fills in, for the comparison of requests; a
   * function when it depends on the base of the draft, read when the comparison is made.
   */
  compare?: RequestComparison | (() => RequestComparison);
  /**
   * Whether the form has a request of its own that adds to the draft when it ends (the upload of a file). A read that may
   * replace the draft does not start meanwhile (`check`, `restore`, `retryRead`; `readBlocked` is true for the buttons): the
   * answer of the request would otherwise be dropped with the draft or added to the object the read brought. Read when a read
   * starts and reactively for `readBlocked`.
   */
  hasOwnRequest?: () => boolean;
  /** The texts for a new object: „Nie wiemy, czy <obiekt> został utworzony.” and „Możliwe, że utworzył go Twój wcześniejszy zapis.”. */
  newObject: { unknownMessage: string; ownNote: string };
}

/** What the form says about a write that has no answer it can trust. */
export interface UnknownResult {
  /** The request is still running (it passed the limit): the fields stay locked. */
  pending: boolean;
  /** `unknown`: nothing is known; `missing` and `otherVersion`: what „Sprawdź stan” found. */
  kind: "unknown" | "missing" | "otherVersion";
  /** The write was the creation of an object: there is no „Sprawdź stan”, only the list. */
  created: boolean;
  /** The draft was discarded after this was said: the message no longer promises that the changes stay. */
  discarded: boolean;
  text: string;
}

/** How a write ended for the one who started it; null when its answer is not the form's any more. */
export type WriteResult = "success" | "refused" | "unknown" | null;

interface WriteHandlers<TView> {
  /** The write is confirmed: show the object, say so, move on. Called only for the newest write of the form that is shown. */
  onSuccess: (data: TView) => void;
  /** The API refused the write (4xx): show the reason. Called only for the newest write. */
  onRefused: (status: number, body: unknown) => void;
  /** For a write whose effect is more than the request (publishing): whether the object on the server shows it was done. */
  confirmedBy?: (served: TView) => boolean;
}

interface Run {
  context: number;
  /** `confirmedCreations` when it started: a creation confirmed since then makes its answer silent. */
  confirmedCreations: number;
  created: boolean;
  timer?: ReturnType<typeof setTimeout>;
}

interface UnknownState<TView> {
  pending: boolean;
  kind: UnknownResult["kind"];
  created: boolean;
  discarded?: boolean;
  confirmedBy?: (served: TView) => boolean;
}

/**
 * The part of a form that keeps the draft of the staff member safe from its own requests (spec „Etap 2”, B3 and B4). The
 * form keeps its fields, its messages and the version; this keeps the state of the requests:
 *
 * - `write` runs a save and sorts its answer: a confirmed success and a refusal reach the handlers of the form, anything
 *   else (no answer in 10 s, an error before the answer, a 5xx) is an unknown result (`unknown`) that leaves the draft.
 *   The request is never aborted: after the limit the fields stay locked (`locked`) and the answer that comes is an
 *   ordinary result, unless the staff member chose „Sprawdź stan” (`check`) or „Odblokuj formularz” (`unblock`) before it:
 *   then it is late, and a late success is only said in a toast (`onLateSuccess`). A late answer never changes the form,
 *   never takes the lock of a newer write and never says anything about itself.
 * - `check` reads the object and takes it over only when the request built from the draft at the moment of the answer
 *   equals the one built from the object (`sameRequest`); otherwise the draft, its base and the version stay.
 * - `restore` is „Odrzuć moje zmiany i pokaż stan z serwera” (and „Odśwież”): the draft is replaced after the answer 200.
 * - Every read that locks the form gives up after `REQUEST_LIMIT_MS` (`readFailed`, `retryRead`); its late answer is ignored.
 * - A read starts nothing while the form has a request of its own (`hasOwnRequest`); `write` starts nothing while a read locks the form (`reading`); a creation confirmed after an earlier one that was given up
 *   makes the answer of the earlier one silent (the form shows the new object). `draftDiscarded` is for „Odrzuć” of the
 *   draft: what the form knows about the server stays, only the promise that the changes stay goes; a late answer is still only a toast.
 * - `reset` is called when the form moves to another object (or to the form of a new one), `dispose` when it is left: the
 *   answers still on their way are ignored then, without a message, and nothing stays locked.
 *
 * Nothing here sends a write by itself: only the form's „Zapisz” does, through `write`.
 */
export function useFormSafety<TView extends { version: number }, TBody>(options: FormSafetyOptions<TView, TBody>) {
  let generation = 0;
  let disposed = false;
  // The write that holds the lock; a write that was given up (`abandon`) or answered is not it.
  const active = shallowRef<Run | null>(null);
  const reading = ref(false);
  const readFailed = ref(false);
  const matched = ref(false);
  // The draft „Sprawdź stan” found equal to the server: the message is true only for it.
  let matchedDraft: string | null = null;
  const restoreOffered = ref(false);
  // What the 409 that offered the server state says about it; shown once the draft is discarded and the 409's own message goes.
  let restoreContext: string | null = null;
  const restoreNote = ref<string | null>(null);
  // A message appeared or was said again: the one who shows it moves the focus to it. A new object each time, so the focus
  // does not depend on the text or the state changing (the same text twice is still a message the staff member must find).
  const focusRequest = shallowRef<{ kind: "unknown" | "restoreNote" | "readFailed" | "matched" | "reading" } | null>(null);
  const unknownState = shallowRef<UnknownState<TView> | null>(null);
  // An earlier creation ended without a trusted answer: the next conflict may be the object that creation made.
  const earlierCreationUnknown = ref(false);
  // Creations confirmed so far: after one, the form shows that object, so an earlier creation that answers late says nothing.
  let confirmedCreations = 0;
  let readSequence = 0;
  let readTimer: ReturnType<typeof setTimeout> | undefined;
  // What „Spróbuj ponownie” repeats; true when it brought the object and replaced the draft.
  let retry: (() => Promise<boolean>) | null = null;

  // A change of the draft the staff member makes after a match ends the message: the form no longer is what the server has.
  watch(
    () => (matched.value ? JSON.stringify(options.draftRequest()) : null),
    (draft) => {
      if (draft !== null && draft !== matchedDraft) {
        matched.value = false;
      }
    },
  );

  const writing = computed(() => active.value !== null);
  const locked = computed(() => writing.value || reading.value);
  /** The buttons that start a read are off: a read runs, or the form has a request of its own. */
  const readBlocked = computed(() => reading.value || (options.hasOwnRequest?.() ?? false));
  const status = computed(() => (reading.value ? READING_MESSAGE : matched.value ? MATCHES_MESSAGE : ""));
  const unknown = computed<UnknownResult | null>(() => {
    const state = unknownState.value;
    if (state === null) {
      return null;
    }
    let text = UNKNOWN_RESULT_MESSAGE;
    if (state.kind === "missing") {
      text = NOT_ON_SERVER_MESSAGE;
    } else if (state.kind === "otherVersion") {
      text = OTHER_VERSION_MESSAGE;
    } else if (state.created && !state.pending) {
      text = `${options.newObject.unknownMessage} ${CHECK_LIST_MESSAGE}`;
    } else if (state.discarded) {
      text = UNKNOWN_DISCARDED_MESSAGE;
    }
    return { pending: state.pending, kind: state.kind, created: state.created, discarded: state.discarded === true, text };
  });

  function announce(kind: "unknown" | "restoreNote" | "readFailed" | "matched" | "reading"): void {
    focusRequest.value = { kind };
  }

  function clearNotices(): void {
    unknownState.value = null;
    matched.value = false;
    restoreOffered.value = false;
    restoreContext = null;
    restoreNote.value = null;
    readFailed.value = false;
  }

  /** The write that holds the lock is given up: its answer is late from now on. */
  function abandon(): void {
    const run = active.value;
    if (run === null) {
      return;
    }
    clearTimeout(run.timer);
    active.value = null;
    if (run.created) {
      earlierCreationUnknown.value = true;
    }
    if (unknownState.value?.pending) {
      unknownState.value = { ...unknownState.value, pending: false };
      announce("unknown");
    }
  }

  /** The number to remember before a request of the form that the form may have left by the time it ends. */
  function context(): number {
    return generation;
  }

  /** Whether the form was left or moved to another object since `context()` gave this number. */
  function stale(remembered: number): boolean {
    return disposed || remembered !== generation;
  }

  /** The form moves to another object or to the form of a new one: nothing of the old one may come back. */
  function reset(): void {
    generation++;
    abandon();
    readSequence++;
    clearTimeout(readTimer);
    reading.value = false;
    clearNotices();
    earlierCreationUnknown.value = false;
    retry = null;
  }

  /**
   * „Odrzuć” of the draft: the form shows its base again. What the form knows about the server stays true and stays:
   * an unknown result (without the promise that the changes stay; „Sprawdź stan” then compares the restored base), what
   * „Sprawdź stan” found and the way to the server state. After a 409 only the way stays, with the context the 409 gave
   * (`restoreNote`), because its own message goes with the draft; nothing is unknown then. Nothing about the requests in flight changes: a late answer is still only a toast.
   * Returns whether a message about the server took the focus (`FormSafetyNotices`); when it did not, the form puts the focus
   * where its standard says (the H1: the save bar went away with „Odrzuć”).
   */
  function draftDiscarded(): boolean {
    const current = unknownState.value;
    if (current !== null) {
      unknownState.value = { ...current, discarded: true };
      announce("unknown");
      return true;
    }
    if (restoreOffered.value) {
      restoreNote.value = restoreContext;
      announce("restoreNote");
      return true;
    }
    return false;
  }

  /** The form is left. */
  function dispose(): void {
    disposed = true;
    reset();
  }

  /**
   * Runs a write. `request` is called at once and must return the answer of the API client. Returns how it ended for the
   * form that started it, or null when its answer is late or the form was left (nothing was done with it).
   */
  async function write(request: () => Promise<WriteOutcome<TView>>, handlers: WriteHandlers<TView>): Promise<WriteResult> {
    if (reading.value) {
      // The form is locked by a read whose answer may replace it: nothing is sent meanwhile.
      return null;
    }
    abandon();
    clearNotices();
    const run: Run = { context: generation, confirmedCreations, created: options.id() === null };
    active.value = run;
    run.timer = setTimeout(() => {
      if (active.value === run) {
        unknownState.value = { pending: true, kind: "unknown", created: run.created, confirmedBy: handlers.confirmedBy };
        announce("unknown");
      }
    }, REQUEST_LIMIT_MS);
    let pending: Promise<WriteOutcome<TView>>;
    try {
      pending = request();
    } catch (error) {
      pending = Promise.reject(error);
    }
    const verdict = await settleWrite(pending);
    clearTimeout(run.timer);
    if (disposed || run.context !== generation) {
      return null;
    }
    if (active.value !== run) {
      if (verdict.kind === "success" && run.confirmedCreations === confirmedCreations) {
        options.onLateSuccess(LATE_SAVE_MESSAGE);
      }
      return null;
    }
    active.value = null;
    unknownState.value = null;
    if (verdict.kind === "success") {
      if (run.created) {
        earlierCreationUnknown.value = false;
        confirmedCreations++;
      }
      handlers.onSuccess(verdict.data);
      return "success";
    }
    if (verdict.kind === "refused") {
      handlers.onRefused(verdict.status, verdict.body);
      return "refused";
    }
    unknownState.value = { pending: false, kind: "unknown", created: run.created, confirmedBy: handlers.confirmedBy };
    announce("unknown");
    if (run.created) {
      earlierCreationUnknown.value = true;
    }
    return "unknown";
  }

  /**
   * Reads the object with the fields locked and hands it to `onData`. After `REQUEST_LIMIT_MS`, on an error and on any
   * answer but 200 the lock goes away and `readFailed` is set; `again` is what `retryRead` repeats. The answer of a read
   * that was given up, replaced or left behind by the form is ignored. The button that started it is gone or off while it
   * runs, so the focus moves to the status region („Odświeżamy…”) at once and to the result when it comes. Returns whether
   * `onData` got the object.
   */
  async function readState(onData: (view: TView) => void, again: () => Promise<boolean>): Promise<boolean> {
    const id = options.id();
    if (id === null || options.hasOwnRequest?.()) {
      return false;
    }
    const sequence = ++readSequence;
    const context = generation;
    clearTimeout(readTimer);
    reading.value = true;
    readFailed.value = false;
    retry = again;
    announce("reading");
    const limit = new Promise<"limit">((resolve) => {
      readTimer = setTimeout(() => resolve("limit"), REQUEST_LIMIT_MS);
    });
    let started: Promise<ReadResult<TView>>;
    try {
      started = options.read(id);
    } catch {
      started = Promise.resolve(null);
    }
    const answer = started.then(
      (result) => result,
      () => null,
    );
    const result = await Promise.race([answer, limit]);
    if (sequence !== readSequence || context !== generation || disposed) {
      return false;
    }
    clearTimeout(readTimer);
    reading.value = false;
    if (result === "limit") {
      // The request goes on, its answer is ignored: the sequence moves on.
      readSequence++;
      readFailed.value = true;
      announce("readFailed");
      return false;
    }
    if (result !== null && "data" in result) {
      onData(result.data);
      return true;
    }
    readFailed.value = true;
    announce("readFailed");
    return false;
  }

  /** „Odrzuć moje zmiany i pokaż stan z serwera” and „Odśwież”: the draft is replaced after the answer 200 only. */
  function restore(): Promise<boolean> {
    return readState(
      (view) => {
        options.adopt(view);
        clearNotices();
      },
      restore,
    );
  }

  /** „Sprawdź stan”: see the description above. Only after an unknown result of a write to an existing object. */
  async function check(): Promise<void> {
    const state = unknownState.value;
    // Nothing is given up before the read is sure to start: a read that does not start leaves the write as it was.
    if (state === null || state.created || options.id() === null || options.hasOwnRequest?.()) {
      return;
    }
    abandon();
    matched.value = false;
    await readState((view) => {
      const current = unknownState.value;
      if (current === null) {
        return;
      }
      const draft = options.draftRequest();
      const served = options.servedRequest(view);
      const confirmed = current.confirmedBy?.(view) ?? true;
      if (draft !== null && served !== null && confirmed && sameRequest(draft, served, typeof options.compare === "function" ? options.compare() : options.compare)) {
        options.adopt(view);
        clearNotices();
        matched.value = true;
        matchedDraft = JSON.stringify(options.draftRequest());
        announce("matched");
      } else if (view.version === options.version()) {
        unknownState.value = { ...current, pending: false, kind: "missing" };
        announce("unknown");
      } else {
        unknownState.value = { ...current, pending: false, kind: "otherVersion" };
        announce("unknown");
        restoreOffered.value = true;
      }
    }, async () => {
      await check();
      // It says what it found itself (the status region, the message): there is nothing to move to the heading.
      return false;
    });
  }

  /** „Odblokuj formularz” (a new object): the write that has no answer is given up; its answer is late from now on. */
  function unblock(): void {
    abandon();
  }

  /** Repeats the read that failed (the button of the message); true when it brought the object and replaced the draft. */
  async function retryRead(): Promise<boolean> {
    return (await retry?.()) ?? false;
  }

  /**
   * A 409 that the staff member can answer with „Odrzuć moje zmiany i pokaż stan z serwera”. `context` is what that 409
   * says about the server (for the version „Na serwerze jest inna wersja niż ta, którą edytujesz.”, for another 409 its
   * own message); it stays visible with the button after the draft is discarded.
   */
  function offerRestore(context: string): void {
    restoreOffered.value = true;
    restoreContext = context;
  }

  /** The message of a conflict, with the note that an earlier unknown creation may have made the object. */
  function withDuplicateNote(text: string): string {
    return earlierCreationUnknown.value ? `${text} ${options.newObject.ownNote}` : text;
  }

  return {
    writing,
    reading,
    readBlocked,
    locked,
    status,
    unknown,
    readFailed,
    restoreOffered,
    restoreNote,
    focusRequest,
    write,
    check,
    unblock,
    restore,
    retryRead,
    offerRestore,
    withDuplicateNote,
    context,
    stale,
    reset,
    draftDiscarded,
    dispose,
  };
}

/** What `FormSafetyNotices` needs from the safety of any form. */
export interface FormSafetyState {
  unknown: Readonly<Ref<UnknownResult | null>>;
  readFailed: Readonly<Ref<boolean>>;
  restoreOffered: Readonly<Ref<boolean>>;
  restoreNote: Readonly<Ref<string | null>>;
  /** Set to a new object whenever a message appears or is said again; `FormSafetyNotices` moves the focus to it. */
  focusRequest: Readonly<Ref<{ kind: "unknown" | "restoreNote" | "readFailed" | "matched" | "reading" } | null>>;
  reading: Readonly<Ref<boolean>>;
  /** The buttons that start a read are off: a read runs, or the form has a request of its own. */
  readBlocked: Readonly<Ref<boolean>>;
  status: Readonly<Ref<string>>;
  check: () => Promise<void>;
  unblock: () => void;
  restore: () => Promise<boolean>;
  retryRead: () => Promise<boolean>;
}
