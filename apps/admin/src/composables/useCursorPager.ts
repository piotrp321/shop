import { computed, onUnmounted, type Ref, ref, shallowRef, watch } from "vue";

/** What a list asks of its page source: the query it shows and the cursor of the page (`after`, null for the first). */
export interface PageRequest<Q> {
  query: Q;
  after: string | null;
}

/**
 * The answer of the page source: the rows and the cursor of the next page (null on the last page), or the message of a
 * failure. A failure with `forbidden` (a 403) is not worth a retry. The list composable owns the requests and the
 * messages; the pager only paces them.
 */
export type PageResult<Row> = { items: Row[]; next: string | null } | { error: string; forbidden?: boolean };

export interface CursorPagerOptions {
  /** The pager asks for nothing while this is false (e.g. until an address parameter is confirmed); true when omitted. */
  ready?: Ref<boolean>;
}

const FALLBACK_MESSAGE = "Nie udało się pobrać danych. Sprawdź połączenie z API.";

type Kind = "query" | "page";

/**
 * The paging of a list by the cursors of the API (docs/features/admin-design/spec.md, „Standard listy”): the one
 * implementation for every list. The panel keeps the stack of the cursors it has visited: `cursors[i]` is the `after` of
 * page i (null for the first), so „previous” is the cursor below the current one; the position is only in memory.
 *
 * - A new query (including a new `perPage`, which `query` must carry) starts again from the first page and drops the
 *   cursors, because a cursor belongs to the query it came from. The old rows stay (the view marks the table busy)
 *   until the answer, and a failed answer removes them: rows and cursors of another query are never shown or paired
 *   with the new one (#251 F10), `loaded` is false and only `error` is left until a retry succeeds. While it runs, the
 *   error of the old query is gone (a retry keeps its error until the answer) and there is no `range`, as the rows on
 *   the screen are not a page of the new query. `loading` is also true while the pager waits for `ready` before its
 *   first answer, so that the view shows its skeleton.
 * - A failed change of the page, or of a refresh of the current page, keeps the page that is shown and its pager.
 * - Only the newest request may change the state; an older one that finishes late is ignored (`latest`).
 * - `retry` repeats the request that failed; `refresh` loads the current page again (after a write that moved it).
 */
export function useCursorPager<Row, Q extends { perPage: number }>(
  fetchPage: (request: PageRequest<Q>) => Promise<PageResult<Row>>,
  query: Ref<Q>,
  options: CursorPagerOptions = {},
) {
  const ready = options.ready ?? ref(true);
  const items = shallowRef<Row[]>([]);
  const next = ref<string | null>(null);
  const cursors = ref<(string | null)[]>([null]);
  const requesting = ref(false);
  const loaded = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);
  // The page and the page size of the rows on the screen, for „Pokazano X–Y”.
  const shown = ref<{ page: number; perPage: number } | null>(null);

  let attempted: { stack: (string | null)[]; kind: Kind } = { stack: [null], kind: "query" };
  let latest = 0;

  // True also while the pager waits for `ready` before its first answer, so the list shows its skeleton, not an empty card.
  const loading = computed(() => requesting.value || (!ready.value && !loaded.value && error.value === null));
  const hasPrevious = computed(() => cursors.value.length > 1);
  const hasNext = computed(() => next.value !== null);
  const pageNumber = computed(() => cursors.value.length);
  const range = computed(() => {
    if (shown.value === null || items.value.length === 0) {
      return null;
    }
    const from = (shown.value.page - 1) * shown.value.perPage + 1;
    return { from, to: from + items.value.length - 1 };
  });

  async function load(stack: (string | null)[], kind: Kind, fresh = false): Promise<void> {
    const request = ++latest;
    attempted = { stack, kind };
    requesting.value = true;
    if (kind === "query") {
      // The cursors belong to the old query: dropping them now means that a failed request cannot be paged with them,
      // and the old rows are not numbered as a page of it.
      cursors.value = [null];
      next.value = null;
      shown.value = null;
    }
    if (fresh) {
      // A new query is not a retry: the failure of the old one is not its state.
      error.value = null;
      forbidden.value = false;
    }
    const current = query.value;
    let result: PageResult<Row>;
    try {
      result = await fetchPage({ query: current, after: stack[stack.length - 1] ?? null });
    } catch {
      result = { error: FALLBACK_MESSAGE };
    }
    if (request !== latest) {
      return;
    }
    requesting.value = false;
    if ("error" in result) {
      error.value = result.error;
      forbidden.value = result.forbidden === true;
      if (kind === "query") {
        items.value = [];
        loaded.value = false;
        shown.value = null;
      }
      return;
    }
    items.value = result.items;
    next.value = result.next;
    cursors.value = stack;
    shown.value = { page: stack.length, perPage: current.perPage };
    loaded.value = true;
    error.value = null;
    forbidden.value = false;
  }

  watch(
    () => (ready.value ? JSON.stringify(query.value) : null),
    (key) => {
      if (key !== null) {
        void load([null], "query", true);
      }
    },
    { immediate: true },
  );

  function goNext(): Promise<void> {
    return next.value === null ? Promise.resolve() : load([...cursors.value, next.value], "page");
  }

  function goPrevious(): Promise<void> {
    return hasPrevious.value ? load(cursors.value.slice(0, -1), "page") : Promise.resolve();
  }

  function retry(): Promise<void> {
    return load(attempted.stack, attempted.kind);
  }

  function refresh(): Promise<void> {
    return loaded.value ? load(cursors.value, "page") : load([null], "query");
  }

  onUnmounted(() => {
    latest++;
  });

  return { items, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range, goNext, goPrevious, retry, refresh };
}
