import { nextTick, reactive } from "vue";
import { type Mock, afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  LATE_SAVE_MESSAGE,
  MATCHES_MESSAGE,
  NOT_ON_SERVER_MESSAGE,
  OTHER_VERSION_MESSAGE,
  READING_MESSAGE,
  REQUEST_LIMIT_MS,
  UNKNOWN_RESULT_MESSAGE,
  type ReadResult,
  sameRequest,
  settleWrite,
} from "@/composables/formRequests";
import { useFormSafety } from "@/composables/useFormSafety";

interface Doc {
  id: string;
  version: number;
  name: string;
}

interface Body {
  name: string;
}

function ok(data: Doc, status = 200) {
  return { data, response: { status } };
}

function refused(status: number, error: unknown = {}) {
  return { data: undefined, error, response: { status } };
}

/** A promise the test settles by hand. */
function held<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return { promise, resolve, reject };
}

describe("settleWrite", () => {
  it("is a success for a 2xx answer with data", async () => {
    expect(await settleWrite(Promise.resolve(ok({ id: "d", version: 1, name: "A" }, 201)))).toEqual({
      kind: "success",
      data: { id: "d", version: 1, name: "A" },
    });
  });

  it.each([400, 403, 404, 409, 422])("is a refusal for a %s answer, with its status and body", async (status) => {
    expect(await settleWrite(Promise.resolve(refused(status, { detail: "x" })))).toEqual({ kind: "refused", status, body: { detail: "x" } });
  });

  it.each([500, 502, 503, 504])("is an unknown result for a %s answer", async (status) => {
    expect(await settleWrite(Promise.resolve(refused(status)))).toEqual({ kind: "unknown" });
  });

  it("is an unknown result for a request that did not reach the API", async () => {
    expect(await settleWrite(Promise.reject(new TypeError("network down")))).toEqual({ kind: "unknown" });
  });

  it("is an unknown result for a 2xx answer without data, which confirms nothing", async () => {
    expect(await settleWrite(Promise.resolve({ data: undefined, response: { status: 200 } }))).toEqual({ kind: "unknown" });
  });
});

describe("sameRequest", () => {
  it("is true for equal requests and false for any difference in a field", () => {
    expect(sameRequest({ name: "A", percent: 20 }, { name: "A", percent: 20 })).toBe(true);
    expect(sameRequest({ name: "A", percent: 20 }, { name: "A", percent: 21 })).toBe(false);
    expect(sameRequest({ name: "A", percent: 20 }, { name: "A", percent: "20" })).toBe(false);
    expect(sameRequest({ name: "A" }, { name: "B" })).toBe(false);
  });

  it("is false for a trimmed space and for a different case", () => {
    expect(sameRequest({ name: "A " }, { name: "A" })).toBe(false);
    expect(sameRequest({ name: "a" }, { name: "A" })).toBe(false);
  });

  it("compares lists in the order of the draft, and by length", () => {
    expect(sameRequest({ ids: ["a", "b"] }, { ids: ["a", "b"] })).toBe(true);
    expect(sameRequest({ ids: ["a", "b"] }, { ids: ["b", "a"] })).toBe(false);
    expect(sameRequest({ ids: ["a"] }, { ids: ["a", "b"] })).toBe(false);
  });

  it("is false when a field is missing on either side", () => {
    expect(sameRequest({ name: "A", extra: 1 }, { name: "A" })).toBe(false);
    expect(sameRequest({ name: "A" }, { name: "A", extra: 1 })).toBe(false);
    expect(sameRequest({ name: "A", note: null }, { name: "A" })).toBe(false);
  });

  it("compares the fields named as instants as moments in time, and no other field", () => {
    const options = { instants: ["startsAt"] };
    expect(sameRequest({ startsAt: "2026-11-10T23:00:00Z" }, { startsAt: "2026-11-11T00:00:00+01:00" }, options)).toBe(true);
    expect(sameRequest({ startsAt: "2026-11-10T23:00:00Z" }, { startsAt: "2026-11-10T23:00:00.000+00:00" }, options)).toBe(true);
    expect(sameRequest({ startsAt: "2026-11-10T23:00:00Z" }, { startsAt: "2026-11-10T23:00:01Z" }, options)).toBe(false);
    expect(sameRequest({ startsAt: null }, { startsAt: null }, options)).toBe(true);
    expect(sameRequest({ startsAt: null }, { startsAt: "2026-11-10T23:00:00Z" }, options)).toBe(false);
    expect(sameRequest({ note: "2026-11-10T23:00:00Z" }, { note: "2026-11-11T00:00:00+01:00" }, options)).toBe(false);
  });

  it("finds the fields named as instants inside lists and objects", () => {
    const options = { instants: ["at"] };
    expect(sameRequest({ rows: [{ at: "2026-11-10T23:00:00Z" }] }, { rows: [{ at: "2026-11-11T00:00:00+01:00" }] }, options)).toBe(true);
  });

  it("is false for an instant that is not a date", () => {
    expect(sameRequest({ at: "yesterday" }, { at: "yesterday" }, { instants: ["at"] })).toBe(true);
    expect(sameRequest({ at: "yesterday" }, { at: "today" }, { instants: ["at"] })).toBe(false);
  });

  it("takes a null in the draft for a field the server assigns as equal to any value, and only there", () => {
    const options = { serverAssigned: ["variantId"] };
    expect(sameRequest({ rows: [{ variantId: null, values: ["S"] }] }, { rows: [{ variantId: "v-9", values: ["S"] }] }, options)).toBe(true);
    expect(sameRequest({ rows: [{ variantId: null, values: ["S"] }] }, { rows: [{ variantId: "v-9", values: ["M"] }] }, options)).toBe(false);
    expect(sameRequest({ rows: [{ variantId: "v-1", values: ["S"] }] }, { rows: [{ variantId: "v-9", values: ["S"] }] }, options)).toBe(false);
    expect(sameRequest({ rows: [{ variantId: "v-9", values: ["S"] }] }, { rows: [{ variantId: null, values: ["S"] }] }, options)).toBe(false);
    expect(sameRequest({ name: null }, { name: "x" }, options)).toBe(false);
  });

  it("takes a null in the draft for a value the server assigned only when the base did not have that value", () => {
    const options = { serverAssigned: ["variantId"], knownServerValues: ["v-1", "v-2"] };
    expect(sameRequest({ rows: [{ variantId: null, values: ["S"] }] }, { rows: [{ variantId: "v-3", values: ["S"] }] }, options)).toBe(true);
    expect(sameRequest({ rows: [{ variantId: null, values: ["S"] }] }, { rows: [{ variantId: "v-2", values: ["S"] }] }, options)).toBe(false);
    expect(sameRequest({ rows: [{ variantId: "v-1", values: ["S"] }] }, { rows: [{ variantId: "v-1", values: ["S"] }] }, options)).toBe(true);
  });
});

describe("useFormSafety", () => {
  const JOIN = (message: string, note: string) => `${message} ${note}`;
  let state: { id: string | null; version: number | null; draft: Body | null };
  let adopted: Doc[];
  let late: string[];
  let read: Mock<(id: string) => Promise<ReadResult<Doc>>>;

  function create() {
    return useFormSafety<Doc, Body>({
      id: () => state.id,
      read,
      version: () => state.version,
      draftRequest: () => state.draft,
      servedRequest: (view) => ({ name: view.name }),
      adopt: (view) => {
        adopted.push(view);
        state.version = view.version;
        state.draft = { name: view.name };
      },
      onLateSuccess: (message) => late.push(message),
      newObject: { unknownMessage: "Nie wiemy, czy obiekt został utworzony.", ownNote: "Możliwe, że utworzył go Twój wcześniejszy zapis." },
    });
  }

  const doc = (name: string, version: number): Doc => ({ id: "d1", version, name });

  beforeEach(() => {
    vi.useFakeTimers();
    state = reactive({ id: "d1", version: 4, draft: { name: "Pierwsza" } });
    adopted = [];
    late = [];
    read = vi.fn();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  /** Starts a write the test settles by hand. */
  function startWrite(safety: ReturnType<typeof create>) {
    const request = held<ReturnType<typeof ok> | ReturnType<typeof refused>>();
    const onSuccess = vi.fn();
    const onRefused = vi.fn();
    const result = safety.write(() => request.promise as never, { onSuccess, onRefused });
    return { request, onSuccess, onRefused, result };
  }

  it("has a limit of 10 s", () => {
    expect(REQUEST_LIMIT_MS).toBe(10_000);
  });

  describe("a write", () => {
    it("locks until the answer, calls the success handler and leaves no message", async () => {
      const safety = create();
      const { request, onSuccess, result } = startWrite(safety);

      expect(safety.writing.value).toBe(true);
      expect(safety.locked.value).toBe(true);
      request.resolve(ok(doc("Pierwsza", 5)));

      expect(await result).toBe("success");
      expect(onSuccess).toHaveBeenCalledWith(doc("Pierwsza", 5));
      expect(safety.locked.value).toBe(false);
      expect(safety.unknown.value).toBeNull();
    });

    it("calls the refusal handler for a 4xx and leaves no message of an unknown result", async () => {
      const safety = create();
      const { request, onRefused, result } = startWrite(safety);

      request.resolve(refused(422, { violations: [] }));

      expect(await result).toBe("refused");
      expect(onRefused).toHaveBeenCalledWith(422, { violations: [] });
      expect(safety.locked.value).toBe(false);
      expect(safety.unknown.value).toBeNull();
    });

    it("keeps the lock after 10 s without an answer, says the result is unknown and does not abort the request", async () => {
      const safety = create();
      const { request, onSuccess, result } = startWrite(safety);

      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS - 1);
      expect(safety.unknown.value).toBeNull();
      await vi.advanceTimersByTimeAsync(1);

      expect(safety.unknown.value).toMatchObject({ pending: true, kind: "unknown", created: false, text: UNKNOWN_RESULT_MESSAGE });
      expect(safety.locked.value).toBe(true);
      expect(onSuccess).not.toHaveBeenCalled();

      request.resolve(ok(doc("Pierwsza", 5)));
      expect(await result).toBe("success");
      expect(onSuccess).toHaveBeenCalledTimes(1);
      expect(safety.unknown.value).toBeNull();
      expect(safety.locked.value).toBe(false);
      expect(late).toEqual([]);
    });

    it("gives a late 422 as an ordinary refusal, without the message of an unknown result", async () => {
      const safety = create();
      const { request, onRefused, result } = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);

      request.resolve(refused(422));

      expect(await result).toBe("refused");
      expect(onRefused).toHaveBeenCalledTimes(1);
      expect(safety.unknown.value).toBeNull();
      expect(safety.locked.value).toBe(false);
    });

    it.each([
      ["a request that does not reach the API", () => Promise.reject(new TypeError("network down"))],
      ["a 502", () => Promise.resolve(refused(502))],
      ["a 504", () => Promise.resolve(refused(504))],
      ["a 500", () => Promise.resolve(refused(500))],
    ])("says the result is unknown and unlocks after %s", async (_name, answer) => {
      const safety = create();
      const onSuccess = vi.fn();

      const result = await safety.write(() => answer() as never, { onSuccess, onRefused: vi.fn() });

      expect(result).toBe("unknown");
      expect(safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: UNKNOWN_RESULT_MESSAGE });
      expect(safety.locked.value).toBe(false);
      expect(onSuccess).not.toHaveBeenCalled();
    });

    it("clears the message of the previous write when a new one starts", async () => {
      const safety = create();
      await safety.write(() => Promise.reject(new TypeError("down")), { onSuccess: vi.fn(), onRefused: vi.fn() });
      expect(safety.unknown.value).not.toBeNull();

      startWrite(safety);

      expect(safety.unknown.value).toBeNull();
    });

    it("ignores the answer after the form was reset for another object, with no lock and no message", async () => {
      const safety = create();
      const { request, onSuccess, result } = startWrite(safety);

      safety.reset();
      expect(safety.locked.value).toBe(false);
      request.resolve(ok(doc("Pierwsza", 5)));

      expect(await result).toBeNull();
      expect(onSuccess).not.toHaveBeenCalled();
      expect(late).toEqual([]);
    });

    it("ignores a limit that comes after the reset", async () => {
      const safety = create();
      startWrite(safety);

      safety.reset();
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS * 2);

      expect(safety.unknown.value).toBeNull();
    });

    it("ignores the answer after the form was left", async () => {
      const safety = create();
      const { request, onSuccess, result } = startWrite(safety);

      safety.dispose();
      request.resolve(ok(doc("Pierwsza", 5)));

      expect(await result).toBeNull();
      expect(onSuccess).not.toHaveBeenCalled();
      expect(late).toEqual([]);
    });
  });

  describe("a late answer of an earlier write", () => {
    it("says so in a message, and changes nothing else, after „Sprawdź stan”", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      read.mockResolvedValue({ data: doc("Stara", 4) });
      await safety.check();
      expect(safety.locked.value).toBe(false);

      first.request.resolve(ok(doc("Pierwsza", 5)));

      expect(await first.result).toBeNull();
      expect(late).toEqual([LATE_SAVE_MESSAGE]);
      expect(first.onSuccess).not.toHaveBeenCalled();
      expect(adopted).toEqual([]);
      expect(state.version).toBe(4);
    });

    it("is ignored without a message when it is a refusal or an error", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      read.mockResolvedValue({ data: doc("Stara", 4) });
      await safety.check();

      first.request.resolve(refused(422));

      expect(await first.result).toBeNull();
      expect(first.onRefused).not.toHaveBeenCalled();
      expect(late).toEqual([]);
    });

    it("does not take the lock of a newer write nor clear its messages when it fails after that write started", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      read.mockResolvedValue({ data: doc("Stara", 4) });
      await safety.check();
      const second = startWrite(safety);
      expect(safety.writing.value).toBe(true);

      first.request.reject(new TypeError("network down"));
      await first.result;

      expect(safety.writing.value).toBe(true);
      expect(safety.locked.value).toBe(true);
      expect(safety.unknown.value).toBeNull();
      second.request.resolve(ok(doc("Druga", 6)));
      expect(await second.result).toBe("success");
    });

    it("keeps the lock of a newer write when it succeeds meanwhile, and leaves the answer of the newer one to its handler", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      read.mockResolvedValue({ data: doc("Stara", 4) });
      await safety.check();
      const second = startWrite(safety);

      first.request.resolve(ok(doc("Pierwsza", 5)));
      await first.result;

      expect(late).toEqual([LATE_SAVE_MESSAGE]);
      expect(safety.locked.value).toBe(true);
      expect(second.onSuccess).not.toHaveBeenCalled();
      second.request.resolve(refused(422, { violations: [] }));
      expect(await second.result).toBe("refused");
      expect(second.onRefused).toHaveBeenCalledTimes(1);
      expect(late).toEqual([LATE_SAVE_MESSAGE]);
    });

    it("says nothing when it comes after another object was opened", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      read.mockResolvedValue({ data: doc("Stara", 4) });
      await safety.check();

      safety.reset();
      first.request.resolve(ok(doc("Pierwsza", 5)));
      await first.result;

      expect(late).toEqual([]);
    });

    it("comes in the middle of „Sprawdź stan”, before the answer of the read: the draft stays and nothing stays locked", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      const reading = held<ReadResult<Doc>>();
      read.mockReturnValue(reading.promise);
      const checking = safety.check();
      expect(safety.reading.value).toBe(true);

      first.request.resolve(ok(doc("Pierwsza", 5)));
      await first.result;
      expect(late).toEqual([LATE_SAVE_MESSAGE]);
      expect(safety.locked.value).toBe(true);
      reading.resolve({ data: doc("Pierwsza", 5) });
      await checking;

      expect(safety.locked.value).toBe(false);
      expect(safety.writing.value).toBe(false);
      expect(state.draft).toEqual({ name: "Pierwsza" });
    });

    it("comes in the middle of „Sprawdź stan”, after the answer of the read: nothing stays locked", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      read.mockResolvedValue({ data: doc("Stara", 4) });
      await safety.check();

      first.request.resolve(ok(doc("Pierwsza", 5)));
      await first.result;

      expect(safety.locked.value).toBe(false);
      expect(safety.writing.value).toBe(false);
    });
  });

  describe("a read that locks the form", () => {
    it("says „Odświeżamy…” while it runs, locks, and unlocks with the answer", async () => {
      const safety = create();
      const reading = held<ReadResult<Doc>>();
      read.mockReturnValue(reading.promise);

      const restoring = safety.restore();

      expect(safety.reading.value).toBe(true);
      expect(safety.locked.value).toBe(true);
      expect(safety.status.value).toBe(READING_MESSAGE);
      reading.resolve({ data: doc("Z serwera", 7) });
      await restoring;

      expect(safety.reading.value).toBe(false);
      expect(safety.status.value).toBe("");
      expect(adopted).toEqual([doc("Z serwera", 7)]);
    });

    it("unlocks after 10 s without an answer with a message to try again, and the late answer changes nothing", async () => {
      const safety = create();
      const reading = held<ReadResult<Doc>>();
      read.mockReturnValue(reading.promise);
      const restoring = safety.restore();

      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS - 1);
      expect(safety.readFailed.value).toBe(false);
      await vi.advanceTimersByTimeAsync(1);

      expect(safety.locked.value).toBe(false);
      expect(safety.readFailed.value).toBe(true);
      state.draft = { name: "Zmieniona po limicie" };
      reading.resolve({ data: doc("Spóźniona", 9) });
      await restoring;
      expect(adopted).toEqual([]);
      expect(state.draft).toEqual({ name: "Zmieniona po limicie" });
      expect(safety.readFailed.value).toBe(true);
      expect(safety.locked.value).toBe(false);
    });

    it.each([
      ["a request that does not reach the API", () => Promise.reject(new TypeError("down"))],
      ["a read that failed before it was sent", () => Promise.resolve(null)],
      ["a 500", () => Promise.resolve({ status: 500 })],
    ])("unlocks and offers to try again after %s", async (_name, answer) => {
      const safety = create();
      read.mockImplementation(answer);

      await safety.restore();

      expect(safety.locked.value).toBe(false);
      expect(safety.readFailed.value).toBe(true);
      expect(adopted).toEqual([]);
    });

    it("tries again on retryRead, and the message goes away with a good answer", async () => {
      const safety = create();
      read.mockResolvedValueOnce(null).mockResolvedValueOnce({ data: doc("Z serwera", 7) });
      await safety.restore();
      expect(safety.readFailed.value).toBe(true);

      await safety.retryRead();

      expect(safety.readFailed.value).toBe(false);
      expect(adopted).toEqual([doc("Z serwera", 7)]);
    });

    it("ignores the answer after another object was opened and leaves no lock", async () => {
      const safety = create();
      const reading = held<ReadResult<Doc>>();
      read.mockReturnValue(reading.promise);
      const restoring = safety.restore();

      safety.reset();
      expect(safety.locked.value).toBe(false);
      reading.resolve({ data: doc("Spóźniona", 9) });
      await restoring;

      expect(adopted).toEqual([]);
      expect(safety.locked.value).toBe(false);
    });

    it("ignores the answer after the form was left, and a limit that comes after that", async () => {
      const safety = create();
      const reading = held<ReadResult<Doc>>();
      read.mockReturnValue(reading.promise);
      const restoring = safety.restore();

      safety.dispose();
      reading.resolve({ data: doc("Spóźniona", 9) });
      await restoring;
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS * 2);

      expect(adopted).toEqual([]);
      expect(safety.readFailed.value).toBe(false);
    });

    it("reads nothing for a new object", async () => {
      state.id = null;
      const safety = create();

      await safety.restore();

      expect(read).not.toHaveBeenCalled();
      expect(safety.reading.value).toBe(false);
    });
  });

  describe("„Sprawdź stan”", () => {
    async function unknownResult(safety: ReturnType<typeof create>) {
      await safety.write(() => Promise.resolve(refused(503)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
      expect(safety.unknown.value?.kind).toBe("unknown");
    }

    it("takes the object from the server when the draft equals it: version, base, no message, and sends nothing", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });

      await safety.check();

      expect(adopted).toEqual([doc("Pierwsza", 5)]);
      expect(state.version).toBe(5);
      expect(safety.unknown.value).toBeNull();
      expect(safety.status.value).toBe(MATCHES_MESSAGE);
      expect(safety.locked.value).toBe(false);
    });

    it("stops saying that the server matches the form as soon as the staff member changes the draft", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });
      await safety.check();
      expect(safety.status.value).toBe(MATCHES_MESSAGE);

      await nextTick();
      expect(safety.status.value).toBe(MATCHES_MESSAGE);
      state.draft = { name: "Druga" };
      await nextTick();

      expect(safety.status.value).toBe("");
    });

    it("keeps the message of a match while the draft is the one it was said for", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });
      await safety.check();

      state.draft = { name: "Pierwsza" };
      await nextTick();

      expect(safety.status.value).toBe(MATCHES_MESSAGE);
    });

    it("leaves the draft, the base and the version when the server has the same version and other values", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockResolvedValue({ data: doc("Stara", 4) });

      await safety.check();

      expect(adopted).toEqual([]);
      expect(state.version).toBe(4);
      expect(safety.unknown.value).toMatchObject({ kind: "missing", text: NOT_ON_SERVER_MESSAGE });
      expect(safety.restoreOffered.value).toBe(false);
      expect(safety.locked.value).toBe(false);
    });

    it("leaves them too, and offers the server state, when the server has another version", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockResolvedValue({ data: doc("Cudza", 6) });

      await safety.check();

      expect(adopted).toEqual([]);
      expect(state.version).toBe(4);
      expect(safety.unknown.value).toMatchObject({ kind: "otherVersion", text: OTHER_VERSION_MESSAGE });
      expect(safety.restoreOffered.value).toBe(true);
    });

    it("keeps the message of the unknown result when the read fails, and unlocks", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockRejectedValue(new TypeError("down"));

      await safety.check();

      expect(adopted).toEqual([]);
      expect(safety.unknown.value).toMatchObject({ pending: false, kind: "unknown", text: UNKNOWN_RESULT_MESSAGE });
      expect(safety.readFailed.value).toBe(true);
      expect(safety.locked.value).toBe(false);
    });

    it("keeps it too after the limit of the read", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockReturnValue(new Promise(() => {}));
      void safety.check();

      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);

      expect(safety.unknown.value).toMatchObject({ kind: "unknown", text: UNKNOWN_RESULT_MESSAGE });
      expect(safety.readFailed.value).toBe(true);
      expect(safety.locked.value).toBe(false);
    });

    it("compares the draft at the moment of the answer, not the one that was sent", async () => {
      const safety = create();
      await unknownResult(safety);
      state.draft = { name: "Druga" };
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });

      await safety.check();

      expect(adopted).toEqual([]);
      expect(state.draft).toEqual({ name: "Druga" });
      expect(state.version).toBe(4);
      expect(safety.status.value).toBe("");
      expect(safety.unknown.value).toMatchObject({ kind: "otherVersion" });
    });

    it("is not a match when no request can be built from the draft", async () => {
      const safety = create();
      await unknownResult(safety);
      state.draft = null;
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });

      await safety.check();

      expect(adopted).toEqual([]);
      expect(safety.unknown.value).toMatchObject({ kind: "otherVersion" });
    });

    it("is not a match when the write names a condition the answer does not meet", async () => {
      const safety = create();
      await safety.write(() => Promise.resolve(refused(503)) as never, {
        onSuccess: vi.fn(),
        onRefused: vi.fn(),
        confirmedBy: (served) => served.name === "Opublikowany",
      });
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });

      await safety.check();

      expect(adopted).toEqual([]);
      expect(safety.unknown.value).toMatchObject({ kind: "otherVersion" });
    });

    it("is a match when the write names a condition the answer meets", async () => {
      const safety = create();
      await safety.write(() => Promise.resolve(refused(503)) as never, {
        onSuccess: vi.fn(),
        onRefused: vi.fn(),
        confirmedBy: (served) => served.name === "Pierwsza",
      });
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });

      await safety.check();

      expect(adopted).toEqual([doc("Pierwsza", 5)]);
    });

    it("locks the fields while the read runs", async () => {
      const safety = create();
      await unknownResult(safety);
      const reading = held<ReadResult<Doc>>();
      read.mockReturnValue(reading.promise);

      const checking = safety.check();

      expect(safety.locked.value).toBe(true);
      expect(safety.status.value).toBe(READING_MESSAGE);
      reading.resolve({ data: doc("Stara", 4) });
      await checking;
    });

    it("does nothing when there is no unknown result to check", async () => {
      const safety = create();

      await safety.check();

      expect(read).not.toHaveBeenCalled();
    });

    it("sends nothing: only the read goes out", async () => {
      const safety = create();
      const request = vi.fn(() => Promise.resolve(refused(503)) as never);
      await safety.write(request, { onSuccess: vi.fn(), onRefused: vi.fn() });
      read.mockResolvedValue({ data: doc("Pierwsza", 5) });

      await safety.check();
      await safety.check();

      expect(request).toHaveBeenCalledTimes(1);
    });
  });

  describe("„Odrzuć moje zmiany i pokaż stan z serwera”", () => {
    it("keeps the draft until the answer, replaces it with the 200 answer and clears the messages", async () => {
      const safety = create();
      await safety.write(() => Promise.resolve(refused(503)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
      safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      const reading = held<ReadResult<Doc>>();
      read.mockReturnValue(reading.promise);

      const restoring = safety.restore();
      expect(adopted).toEqual([]);
      expect(state.draft).toEqual({ name: "Pierwsza" });
      reading.resolve({ data: doc("Z serwera", 7) });
      await restoring;

      expect(adopted).toEqual([doc("Z serwera", 7)]);
      expect(safety.unknown.value).toBeNull();
      expect(safety.restoreOffered.value).toBe(false);
    });

    it.each([
      ["an error", () => Promise.reject(new TypeError("down"))],
      ["a refusal", () => Promise.resolve({ status: 500 })],
    ])("keeps the draft and the messages after %s, and the button stays", async (_name, answer) => {
      const safety = create();
      await safety.write(() => Promise.resolve(refused(503)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
      safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      read.mockImplementation(answer);

      await safety.restore();

      expect(adopted).toEqual([]);
      expect(state.draft).toEqual({ name: "Pierwsza" });
      expect(safety.unknown.value).not.toBeNull();
      expect(safety.restoreOffered.value).toBe(true);
      expect(safety.locked.value).toBe(false);
    });

    it("keeps them after the limit too", async () => {
      const safety = create();
      safety.offerRestore("Na serwerze jest inna wersja niż ta, którą edytujesz.");
      read.mockReturnValue(new Promise(() => {}));
      void safety.restore();

      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);

      expect(adopted).toEqual([]);
      expect(safety.restoreOffered.value).toBe(true);
      expect(safety.locked.value).toBe(false);
    });
  });

  describe("a new object", () => {
    beforeEach(() => {
      state.id = null;
      state.version = null;
    });

    it("says after the limit that the result is unknown, and „Odblokuj formularz” unlocks with a message about the list", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      expect(safety.unknown.value).toMatchObject({ pending: true, created: true, text: UNKNOWN_RESULT_MESSAGE });

      safety.unblock();

      expect(safety.locked.value).toBe(false);
      expect(safety.unknown.value).toMatchObject({
        pending: false,
        created: true,
        text: "Nie wiemy, czy obiekt został utworzony. Wcześniejszy zapis może się jeszcze wykonać. Sprawdź listę przed ponownym zapisem.",
      });
      first.request.resolve(ok(doc("Pierwsza", 1)));
      await first.result;
      expect(late).toEqual([LATE_SAVE_MESSAGE]);
      expect(first.onSuccess).not.toHaveBeenCalled();
    });

    it("says it after an error too, and adds the note to the message of a duplicate after that", async () => {
      const safety = create();
      await safety.write(() => Promise.resolve(refused(502)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });

      expect(safety.unknown.value).toMatchObject({ pending: false, created: true });
      expect(safety.unknown.value?.text).toContain("Sprawdź listę przed ponownym zapisem.");
      expect(safety.withDuplicateNote("Taki kod już istnieje.")).toBe(JOIN("Taki kod już istnieje.", "Możliwe, że utworzył go Twój wcześniejszy zapis."));
    });

    it("adds no note when no earlier write ended unknown, or after a success", async () => {
      const safety = create();
      expect(safety.withDuplicateNote("Taki kod już istnieje.")).toBe("Taki kod już istnieje.");

      await safety.write(() => Promise.resolve(refused(502)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
      await safety.write(() => Promise.resolve(ok(doc("Pierwsza", 1), 201)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });

      expect(safety.withDuplicateNote("Taki kod już istnieje.")).toBe("Taki kod już istnieje.");
    });

    it("forgets the earlier unknown result when another form is opened", async () => {
      const safety = create();
      await safety.write(() => Promise.resolve(refused(502)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });

      safety.reset();

      expect(safety.unknown.value).toBeNull();
      expect(safety.withDuplicateNote("x")).toBe("x");
    });

    it("has no „Sprawdź stan” to do: the check reads nothing", async () => {
      const safety = create();
      await safety.write(() => Promise.resolve(refused(502)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });

      await safety.check();

      expect(read).not.toHaveBeenCalled();
    });

    it("says nothing about an earlier creation that answers after a later one was confirmed: the form shows that object now", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      safety.unblock();
      const second = startWrite(safety);
      second.request.resolve(ok(doc("Druga", 1), 201));
      expect(await second.result).toBe("success");
      state.id = "d2";

      first.request.resolve(ok(doc("Pierwsza", 1)));
      await first.result;

      expect(late).toEqual([]);
      expect(first.onSuccess).not.toHaveBeenCalled();
    });

    it("still toasts an earlier creation that answers before a later one is confirmed", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      safety.unblock();
      startWrite(safety);

      first.request.resolve(ok(doc("Pierwsza", 1)));
      await first.result;

      expect(late).toEqual([LATE_SAVE_MESSAGE]);
    });

    it("does not toast a late success after the form of the new object was left", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      safety.unblock();

      safety.reset();
      first.request.resolve(ok(doc("Pierwsza", 1)));
      await first.result;

      expect(late).toEqual([]);
    });
  });

  describe("after the draft is discarded", () => {
    async function unknownResult(safety: ReturnType<typeof create>) {
      await safety.write(() => Promise.resolve(refused(502)) as never, { onSuccess: vi.fn(), onRefused: vi.fn() });
    }

    it("keeps the unknown result of a save, without the sentence that the changes stay, with „Sprawdź stan” still possible", async () => {
      const safety = create();
      await unknownResult(safety);

      safety.draftDiscarded();

      expect(safety.unknown.value).toMatchObject({ kind: "unknown", pending: false, created: false, discarded: true, text: "Nie wiemy, czy zapis się powiódł." });
      state.draft = { name: "Stara" };
      read.mockResolvedValue({ data: doc("Stara", 4) });
      await safety.check();
      expect(read).toHaveBeenCalledTimes(1);
      expect(adopted).toEqual([doc("Stara", 4)]);
      expect(safety.unknown.value).toBeNull();
    });

    it("keeps what „Sprawdź stan” found about the server", async () => {
      const safety = create();
      await unknownResult(safety);
      read.mockResolvedValue({ data: doc("Cudza", 6) });
      await safety.check();

      safety.draftDiscarded();

      expect(safety.unknown.value).toMatchObject({ kind: "otherVersion", discarded: true, text: OTHER_VERSION_MESSAGE });
      expect(safety.restoreOffered.value).toBe(true);
    });

    it("keeps the way to the server state after a 409 with the context the 409 gave, and says nothing is unknown", () => {
      const safety = create();
      safety.offerRestore("Rozpoczętej promocji nie można zmienić.");
      expect(safety.restoreNote.value).toBeNull();

      safety.draftDiscarded();

      expect(safety.unknown.value).toBeNull();
      expect(safety.restoreNote.value).toBe("Rozpoczętej promocji nie można zmienić.");
      expect(safety.restoreOffered.value).toBe(true);
    });

    it("does nothing when there is nothing to say about the server", () => {
      const safety = create();

      safety.draftDiscarded();

      expect(safety.unknown.value).toBeNull();
      expect(safety.restoreNote.value).toBeNull();
      expect(safety.restoreOffered.value).toBe(false);
    });

    it("says whether a message about the server took the focus: after an unknown result and after a 409, not otherwise", async () => {
      const plain = create();
      expect(plain.draftDiscarded()).toBe(false);

      const unknown = create();
      await unknownResult(unknown);
      expect(unknown.draftDiscarded()).toBe(true);

      const conflict = create();
      conflict.offerRestore("Rozpoczętej promocji nie można zmienić.");
      expect(conflict.draftDiscarded()).toBe(true);
    });

    it("keeps a late success a toast that changes nothing", async () => {
      const safety = create();
      const first = startWrite(safety);
      await vi.advanceTimersByTimeAsync(REQUEST_LIMIT_MS);
      read.mockResolvedValue({ data: doc("Cudza", 6) });
      await safety.check();

      safety.draftDiscarded();
      first.request.resolve(ok(doc("Pierwsza", 5)));
      await first.result;

      expect(late).toEqual([LATE_SAVE_MESSAGE]);
      expect(first.onSuccess).not.toHaveBeenCalled();
      expect(adopted).toEqual([]);
    });
  });

  describe("a write while a read locks the form", () => {
    it("is not started: the request is not made", async () => {
      const safety = create();
      read.mockReturnValue(new Promise(() => {}));
      void safety.restore();
      const request = vi.fn();

      const result = await safety.write(request as never, { onSuccess: vi.fn(), onRefused: vi.fn() });

      expect(result).toBeNull();
      expect(request).not.toHaveBeenCalled();
      expect(safety.writing.value).toBe(false);
    });
  });

  describe("the context of the form", () => {
    it("changes with every reset and can be compared", () => {
      const safety = create();
      const before = safety.context();
      expect(safety.stale(before)).toBe(false);

      safety.reset();

      expect(safety.stale(before)).toBe(true);
      expect(safety.stale(safety.context())).toBe(false);
      safety.dispose();
      expect(safety.stale(safety.context())).toBe(true);
    });
  });
});
