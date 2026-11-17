import { onMounted, ref } from "vue";
import { getApiClient } from "@/api/client";
import { type CategoryProblem, categoryProblem } from "./problem";
import { type CategoryRow, findRow, type MoveCommand, toRows } from "./tree";

/** How a change of the tree ended. `message` of a saved change is the text of the toast. */
export type CategoryOutcome =
  | { status: "saved"; message: string; created?: { id: string; parentId: string | null }; moved?: { id: string; parentId: string | null } }
  | { status: "problem"; problem: CategoryProblem };

export interface CategoryInput {
  name: string;
  slug: string;
}

const REDIRECT_NOTE = "Stary adres przekierowuje na nowy.";

const problem = (kind: "failed" | "missing", message: string): CategoryOutcome => ({ status: "problem", problem: { kind, message } });

/** What the response of a change carries that the screen needs: the new path (to tell whether the address changed). */
interface Changed {
  id: string;
  path: string;
  parentId: string | null;
}

/**
 * The state and the commands of the category screen: the tree with the product counts (`withCounts=true`, which the
 * filters of the lists do not ask for: they use `useCategoryTree`), and the changes of it. The API decides on every
 * rule (depth, cycles, slugs, versions, an empty category); a command answers with its outcome and a refusal is
 * given back in words, never thrown. Every change reloads the tree (the versions have changed); a version conflict
 * and a category that is gone reload it too. One change runs at a time (`busy`).
 */
export function useCategoryScreen() {
  const rows = ref<CategoryRow[]>([]);
  const loading = ref(false);
  /** Turns true after the first successful load and stays: a later failure keeps the tree it has. */
  const loaded = ref(false);
  const error = ref<string | null>(null);
  const busy = ref(false);
  let latest = 0;

  async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    let failure: string | null = null;
    try {
      const result = await getApiClient().GET("/api/v1/admin/categories", { params: { query: { withCounts: "true" } } });
      if (request !== latest) {
        return;
      }
      if (result.data) {
        rows.value = toRows(result.data);
        loaded.value = true;
      } else {
        failure = `Nie udało się pobrać kategorii (HTTP ${result.response.status}).`;
      }
    } catch {
      if (request !== latest) {
        return;
      }
      failure = "Nie udało się pobrać kategorii. Sprawdź połączenie z API.";
    }
    error.value = failure;
    loading.value = false;
  }

  onMounted(load);

  /** Runs one change: the request, then the reload that the outcome needs. */
  async function change(send: () => Promise<{ error?: unknown; response: { status: number } }>, saved: () => CategoryOutcome): Promise<CategoryOutcome> {
    if (busy.value) {
      return problem("failed", "Poprzednia zmiana jeszcze trwa. Spróbuj za chwilę.");
    }
    busy.value = true;
    try {
      let result;
      try {
        result = await send();
      } catch {
        return problem("failed", "Nie udało się zapisać zmiany kategorii. Sprawdź połączenie z API.");
      }
      if (result.response.status >= 200 && result.response.status < 300) {
        const outcome = saved();
        await load();
        return outcome;
      }
      const refusal = categoryProblem(result.response.status, result.error);
      if (refusal.kind === "modified" || refusal.kind === "missing") {
        await load();
      }
      return { status: "problem", problem: refusal };
    } finally {
      busy.value = false;
    }
  }

  const missing = () => problem("missing", "Ta kategoria już nie istnieje.");

  async function create(input: CategoryInput & { parentId: string | null }): Promise<CategoryOutcome> {
    let created: Changed | undefined;
    return change(
      async () => {
        const result = await getApiClient().POST("/api/v1/admin/categories", { body: { name: input.name, slug: input.slug, parentId: input.parentId } });
        created = result.data;
        return result;
      },
      () => ({ status: "saved", message: `Dodano kategorię: ${input.name}`, created: { id: created!.id, parentId: input.parentId } }),
    );
  }

  async function revise(id: string, input: CategoryInput): Promise<CategoryOutcome> {
    const before = findRow(rows.value, id)?.row;
    if (before === undefined) {
      await load();
      return missing();
    }
    let revised: Changed | undefined;
    return change(
      async () => {
        const result = await getApiClient().PUT("/api/v1/admin/categories/{id}", {
          params: { path: { id } },
          body: { name: input.name, slug: input.slug, version: before.version },
        });
        revised = result.data;
        return result;
      },
      () => ({ status: "saved", message: `Zapisano kategorię: ${input.name}${revised!.path === before.path ? "" : `. ${REDIRECT_NOTE}`}` }),
    );
  }

  async function move(command: MoveCommand): Promise<CategoryOutcome> {
    const before = findRow(rows.value, command.id)?.row;
    if (before === undefined) {
      await load();
      return missing();
    }
    let moved: Changed | undefined;
    return change(
      async () => {
        const result = await getApiClient().POST("/api/v1/admin/categories/{id}/move", {
          params: { path: { id: command.id } },
          body: { parentId: command.parentId, position: command.position, version: before.version },
        });
        moved = result.data;
        return result;
      },
      () => ({
        status: "saved",
        message: moved!.path === before.path ? `Zmieniono kolejność: ${before.name}` : `Przeniesiono kategorię: ${before.name}. ${REDIRECT_NOTE}`,
        moved: { id: command.id, parentId: command.parentId },
      }),
    );
  }

  async function remove(id: string): Promise<CategoryOutcome> {
    const before = findRow(rows.value, id)?.row;
    if (before === undefined) {
      await load();
      return missing();
    }
    return change(
      () => getApiClient().DELETE("/api/v1/admin/categories/{id}", { params: { path: { id }, query: { version: before.version } } }),
      () => ({ status: "saved", message: `Usunięto kategorię: ${before.name}` }),
    );
  }

  return { rows, loading, loaded, error, busy, load, create, revise, move, remove };
}
