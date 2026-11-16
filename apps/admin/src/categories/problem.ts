const PROBLEM_TYPES = "tag:piotrp321.github.io,2026:shop/problems/";

export const MODIFIED_MESSAGE = "Kategoria została w międzyczasie zmieniona – odśwież";

/** Why a change of the tree was refused, in words for the staff member and with the place the message belongs to. */
export type CategoryProblem =
  /** A sibling has the slug: said at the field. */
  | { kind: "slug-taken"; message: string }
  /** The category changed since the version the screen showed: the tree is reloaded. */
  | { kind: "modified"; message: string }
  /** Too deep, a cycle or not empty: the `detail` of the API, said in the window. */
  | { kind: "blocked"; message: string }
  /** A field is invalid (422): the titles of the violations by field; `message` is what belongs to no field. */
  | { kind: "invalid"; fields: { name?: string[]; slug?: string[] }; message: string | null }
  | { kind: "missing"; message: string }
  | { kind: "forbidden"; message: string }
  | { kind: "failed"; message: string };

const BLOCKED_FALLBACK: Record<string, string> = {
  "category-too-deep": "Drzewo kategorii może mieć najwyżej 4 poziomy.",
  "category-cycle": "Nie można przenieść kategorii do niej samej ani do jej podkategorii.",
  "category-not-empty": "Przenieś produkty do innej kategorii, zanim usuniesz tę.",
};
const SLUG_TAKEN_FALLBACK = "Ten adres jest już zajęty przez inną kategorię na tym poziomie.";

function detailOf(body: unknown, fallback: string): string {
  const detail = typeof body === "object" && body !== null ? (body as { detail?: unknown }).detail : undefined;
  return typeof detail === "string" && detail !== "" ? detail : fallback;
}

function conflict(body: unknown, status: number): CategoryProblem {
  const type = typeof body === "object" && body !== null ? (body as { type?: unknown }).type : undefined;
  const name = typeof type === "string" && type.startsWith(PROBLEM_TYPES) ? type.slice(PROBLEM_TYPES.length) : "";
  if (name === "category-modified") {
    return { kind: "modified", message: MODIFIED_MESSAGE };
  }
  if (name === "category-slug-taken") {
    return { kind: "slug-taken", message: detailOf(body, SLUG_TAKEN_FALLBACK) };
  }
  if (name in BLOCKED_FALLBACK) {
    return { kind: "blocked", message: detailOf(body, BLOCKED_FALLBACK[name]!) };
  }
  return { kind: "failed", message: `Nie udało się zapisać zmiany kategorii (HTTP ${status}).` };
}

function invalid(body: unknown, status: number): CategoryProblem {
  const violations = typeof body === "object" && body !== null ? (body as { violations?: unknown }).violations : undefined;
  const fields: { name?: string[]; slug?: string[] } = {};
  const general: string[] = [];
  for (const violation of Array.isArray(violations) ? violations : []) {
    const { propertyPath, title } = (violation ?? {}) as { propertyPath?: unknown; title?: unknown };
    if (typeof title !== "string" || title === "") {
      continue;
    }
    if (propertyPath === "name" || propertyPath === "slug") {
      (fields[propertyPath] ??= []).push(title);
    } else {
      general.push(title);
    }
  }
  const message = general.length > 0 ? general.join(" ") : Object.keys(fields).length > 0 ? null : `API odrzuciło dane kategorii (HTTP ${status}).`;
  return { kind: "invalid", fields, message };
}

/** The reason of a refused request of the category endpoints, from its status and its problem details body. The API decides; the panel only says it. */
export function categoryProblem(status: number, body: unknown): CategoryProblem {
  switch (status) {
    case 409:
      return conflict(body, status);
    case 422:
      return invalid(body, status);
    case 404:
      return { kind: "missing", message: "Ta kategoria już nie istnieje." };
    case 403:
      return { kind: "forbidden", message: "Nie masz uprawnień do zmiany kategorii." };
    default:
      return { kind: "failed", message: `Nie udało się zapisać zmiany kategorii (HTTP ${status}).` };
  }
}
