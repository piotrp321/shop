import { computed, onUnmounted, ref, watch } from "vue";

/** The API searches names of 2 to 100 characters (code points, as it counts them), not UTF-16 units. */
export const MIN_NAME_SEARCH_LENGTH = 2;
export const MAX_NAME_SEARCH_LENGTH = 100;
/** The pause in typing after which the term is applied. */
export const NAME_SEARCH_DEBOUNCE_MS = 300;

export const NAME_TOO_SHORT_HINT = "Wpisz co najmniej 2 znaki, aby wyszukać.";
export const NAME_TOO_LONG_HINT = "Wpisz najwyżej 100 znaków, aby wyszukać.";

function characters(text: string): number {
  return [...text].length;
}

/** The trimmed term when it is a search the API takes (2 to 100 code points), otherwise null. */
export function validNameTerm(text: string): string | null {
  const term = text.trim();
  const length = characters(term);
  return length >= MIN_NAME_SEARCH_LENGTH && length <= MAX_NAME_SEARCH_LENGTH ? term : null;
}

export interface NameSearchOptions {
  /** The term the field starts with (e.g. from the address); it is not applied. */
  initial?: string | null;
  /** Receives the term after the pause: a valid trimmed term, or null for an empty search (no filter). */
  apply: (term: string | null) => void;
}

/**
 * The search by name of the lists (docs/features/admin-design/spec.md, „Standard listy”): the one place of its rules.
 * The term, trimmed, has 2 to 100 characters; after a 300 ms pause in typing it goes to `apply`, and an empty one (also
 * only spaces) is `null`, no filter. A term that is too short or too long is not applied: the list stays as it is and `hint`
 * says why. `reset` empties the field and drops the pending pause; `setFromAddress` puts a value that came from outside
 * (entering the page, Back) into the field without `apply`, because it is the applied state already. The pause is also
 * dropped when the component is unmounted. Call it in `setup`.
 */
export function useNameSearch(options: NameSearchOptions) {
  /** What is typed in the field; only a term that passes the rules, after the pause, reaches `apply`. */
  const search = ref(options.initial ?? "");
  // The term that is already applied (or came from outside): typing it again is no change to apply.
  let synced = search.value.trim();
  let timer: ReturnType<typeof setTimeout> | undefined;

  const tooShort = computed(() => {
    const length = characters(search.value.trim());
    return length > 0 && length < MIN_NAME_SEARCH_LENGTH;
  });
  const hint = computed(() => {
    if (tooShort.value) {
      return NAME_TOO_SHORT_HINT;
    }
    return characters(search.value.trim()) > MAX_NAME_SEARCH_LENGTH ? NAME_TOO_LONG_HINT : null;
  });

  watch(search, (value) => {
    clearTimeout(timer);
    if (value.trim() === synced) {
      return;
    }
    timer = setTimeout(() => {
      const term = search.value.trim();
      const length = characters(term);
      if (length > 0 && (length < MIN_NAME_SEARCH_LENGTH || length > MAX_NAME_SEARCH_LENGTH)) {
        // Not a search the API takes: the list stays as it is and the hint says why.
        return;
      }
      synced = term;
      options.apply(length === 0 ? null : term);
    }, NAME_SEARCH_DEBOUNCE_MS);
  });

  function setFromAddress(term: string | null): void {
    clearTimeout(timer);
    search.value = term ?? "";
    synced = search.value.trim();
  }

  function reset(): void {
    setFromAddress(null);
  }

  onUnmounted(() => {
    clearTimeout(timer);
  });

  return { search, tooShort, hint, reset, setFromAddress };
}
