import { computed, ref, watch } from "vue";
import { type LocationQuery, type LocationQueryRaw, useRoute, useRouter } from "vue-router";

/**
 * One parameter of the address of a list: the search (`q`), a filter (`category`, `status`, `refund`) or the page size
 * (`perPage`).
 */
export interface ListParam<T, D extends T | null> {
  /** Reads the text of the address: the value, or null when it is not valid (the parameter is then skipped). */
  parse: (raw: string) => T | null;
  /** The value of a parameter that the address does not have, and the one that is not written to the address. */
  fallback: D;
  /** The text for the address; `String(value)` when omitted. */
  format?: (value: T) => string;
  /**
   * For a value that only a source loaded later can confirm (a category of the tree): true when it exists, false when it
   * does not (also when the source failed), undefined while that is not known. Read reactively: the list waits for it
   * (`ready`) and skips a value that is not confirmed.
   */
  confirm?: (value: T) => boolean | undefined;
}

/** A parameter of any type, for the bound of a schema that holds parameters of different types. */
interface AnyListParam {
  parse: (raw: string) => unknown;
  fallback: unknown;
  format?: (value: never) => string;
  confirm?: (value: never) => boolean | undefined;
}

export type ListSchema = Record<string, AnyListParam>;

/** The query of a list: the value of each parameter of the schema, its fallback when the address does not have one. */
export type ListQuery<S extends ListSchema> = {
  [K in keyof S]: S[K] extends ListParam<infer T, infer D> ? (null extends D ? T | null : T) : never;
};

type Raws<S extends ListSchema> = Record<keyof S, string | null>;

/** The text of a parameter of the address; an empty or repeated parameter is no value. */
function rawOf(value: LocationQuery[string] | undefined): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

/**
 * The search, the filters and the page size of a list live in its address (docs/features/admin-design/spec.md,
 * „Standard listy”): read once on entering, written with `router.replace` (no new history entry per change) and watched,
 * so that a change from outside (the menu, the search of the topbar, Back and Forward) sets them again. The position in
 * the paging is not in the address.
 *
 * `query` is the one reactive query of the list: pass it to `useCursorPager`, which asks once for every distinct value,
 * from the first page. A value of the address that is not valid is skipped and the address corrected. `address` holds
 * the text of each parameter as the address had it the last time it was read (entering, a change from outside), so the
 * view can show a search that is too short to filter by. `ready` is false while a `confirm` waits for its source.
 * A write of the list itself is told from a change from outside by the signature of the address it wrote.
 */
export function useListUrlState<S extends ListSchema>(schema: S) {
  const route = useRoute();
  const router = useRouter();
  // The view is mounted while the route already is the next one: only an address of its own route is read.
  const routeName = route.name;
  const keys = Object.keys(schema) as (keyof S & string)[];

  function rawsOf(address: LocationQuery): Raws<S> {
    return Object.fromEntries(keys.map((key) => [key, rawOf(address[key])])) as Raws<S>;
  }

  function parseRaws(raws: Raws<S>): ListQuery<S> {
    return Object.fromEntries(
      keys.map((key) => {
        const raw = raws[key];
        return [key, (raw === null ? null : schema[key]!.parse(raw)) ?? schema[key]!.fallback];
      }),
    ) as ListQuery<S>;
  }

  /** What the address says of a query: only the values that are not the fallback. */
  function serialize(values: ListQuery<S>): Raws<S> {
    return Object.fromEntries(
      keys.map((key) => {
        const param = schema[key]!;
        const value = values[key] as unknown;
        const same = value === null || value === param.fallback;
        return [key, same ? null : (param.format ?? String)(value as never)];
      }),
    ) as Raws<S>;
  }

  const signature = (raws: Raws<S>) => JSON.stringify(keys.map((key) => raws[key]));

  const initial = rawsOf(route.query);
  const address = ref(initial);
  const query = ref(parseRaws(initial));
  // The signature of the address as the list knows it: read from it or written to it last.
  let known = signature(initial);
  // The signatures of the writes that the router has not reported yet; their echo is not a change from outside.
  const pending: string[] = [];

  /**
   * Writes the query to the address. `correcting` also writes when the address says the same by its signature but has
   * an empty or repeated parameter of the list, which is skipped when read.
   */
  function write(correcting = false): void {
    const raws = serialize(query.value);
    const sig = signature(raws);
    const own = Object.fromEntries(keys.filter((key) => raws[key] !== null).map((key) => [key, raws[key]]));
    const clean = !correcting || keys.every((key) => (own[key] ?? undefined) === route.query[key]);
    if ((sig === known && clean) || route.name !== routeName) {
      return;
    }
    known = sig;
    pending.push(sig);
    const others = Object.fromEntries(Object.entries(route.query).filter(([key]) => !(keys as string[]).includes(key)));
    const settle = () => {
      const index = pending.indexOf(sig);
      if (index >= 0) {
        pending.splice(index, 1);
      }
    };
    router
      .replace({ query: { ...others, ...own } as LocationQueryRaw })
      .catch(() => undefined)
      .finally(() => void Promise.resolve().then(settle));
  }

  function set(patch: Partial<ListQuery<S>>): void {
    query.value = { ...query.value, ...patch };
    write();
  }

  function clear(): void {
    query.value = Object.fromEntries(keys.map((key) => [key, schema[key]!.fallback])) as ListQuery<S>;
    write();
  }

  watch(
    () => route.query,
    (current) => {
      if (route.name !== routeName) {
        return;
      }
      const raws = rawsOf(current);
      const sig = signature(raws);
      const echo = pending.indexOf(sig);
      if (echo >= 0) {
        pending.splice(0, echo + 1);
        return;
      }
      if (sig === known) {
        write(true);
        return;
      }
      known = sig;
      address.value = raws;
      query.value = parseRaws(raws);
      // Corrects the address when it had a value that is skipped.
      write(true);
    },
  );

  const confirmations = computed(() =>
    keys.map((key) => {
      const param = schema[key]!;
      const value = query.value[key] as unknown;
      if (param.confirm === undefined || value === null) {
        return "yes";
      }
      const confirmed = param.confirm(value as never);
      return confirmed === undefined ? "wait" : confirmed ? "yes" : "no";
    }),
  );
  const ready = computed(() => confirmations.value.every((state) => state === "yes"));

  // A value that its source does not know is skipped, like an invalid one, and the address is corrected.
  watch(
    confirmations,
    (states) => {
      const refused = keys.filter((_key, index) => states[index] === "no");
      if (refused.length > 0) {
        query.value = { ...query.value, ...Object.fromEntries(refused.map((key) => [key, schema[key]!.fallback])) };
        write();
      }
    },
    { immediate: true },
  );

  // Corrects the address of an entry that had a value that is skipped.
  write(true);

  return { query, address, ready, set, clear };
}
