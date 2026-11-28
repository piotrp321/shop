/**
 * A copy of the rule of texts of the API (docs/features/api-input-contract/spec.md, „Etap 2: reguła tekstu”, T1–T3;
 * `App\Shared\Domain\Text::hasForbiddenCharacters()` and `normalize()`) for ONE use: the comparison of „Sprawdź stan”
 * after a save of a product with no answer (docs/features/product-data/spec.md, „Porównanie „Sprawdź stan” z normalizacją
 * API”). The form never sends, shows or stores the result: a text goes to the API as the staff member typed it, and only
 * the API trims it. The two rules can drift apart (the classes of characters of PCRE and of JavaScript differ, e.g. U+180E
 * and U+0085), so the test of the real API answers, `e2e/tests/catalog-admin.spec.ts`, is the one that tells them apart.
 *
 * T1: a control character (Unicode Cc), U+2028 and U+2029 are forbidden anywhere in the value as it came; a field of
 * paragraphs allows U+000A. T2: the ends lose Unicode white space and U+200B, U+2060, U+FEFF and U+00AD, nothing inside.
 * T3: a text without a visible character (only characters of the categories Z, Cc, Cf and default ignorable code points
 * left) is empty.
 */

/** A one-line text, or a text of paragraphs, which allows the line feed. */
export type ContentTextKind = "line" | "paragraphs";

// Surrogates: a lone one cannot be sent in a JSON body, so no state of the server comes of it.
const FORBIDDEN = /[\p{Cc}\u{2028}\u{2029}\p{Cs}]/u;
// `\s` and `\p{Z}` of PCRE with the Unicode properties, which JavaScript does not give the same: it lacks U+180E and U+0085.
const EDGE = "[\\s\\p{Z}\\u180E\\u0085\\u200B\\u2060\\uFEFF\\u00AD]";
const EDGES = new RegExp(`^${EDGE}+|${EDGE}+$`, "gu");
const INVISIBLE_ONLY = /^[\p{Z}\p{Cc}\p{Cf}\p{Default_Ignorable_Code_Point}]*$/u;

/**
 * The text the API would store of `value` (T1, then T2, then T3), or null when T1 forbids the value, so that no state of
 * the server is the one the form would have produced.
 */
export function normalizeContentText(value: string, kind: ContentTextKind = "line"): string | null {
  const checked = kind === "paragraphs" ? value.replaceAll("\n", "") : value;
  if (FORBIDDEN.test(checked)) {
    return null;
  }
  const trimmed = value.replace(EDGES, "");
  return INVISIBLE_ONLY.test(trimmed) ? "" : trimmed;
}

/**
 * The SKU the API would store of `value`: T1, T2 and T3 as above, then the letters of the ASCII turned into capitals (the API
 * writes the code in capitals; a letter of another alphabet is no letter of the SKU and stays as it is). "" for no code, null
 * when T1 forbids the value. Like `normalizeContentText` it serves the comparison of „Sprawdź stan” only.
 */
export function normalizeSku(value: string): string | null {
  const text = normalizeContentText(value);
  return text === null ? null : upperAscii(text);
}

/** The EAN the API would store of `value`: T1, T2 and T3, nothing else ("" for no code, null when T1 forbids the value). */
export function normalizeEan(value: string): string | null {
  return normalizeContentText(value);
}

/** The letters a to z turned into capitals; every other character, also a letter of another alphabet, stays. */
export function upperAscii(value: string): string {
  return value.replace(/[a-z]/g, (letter) => letter.toUpperCase());
}
