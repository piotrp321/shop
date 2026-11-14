export type CountInput = { ok: true; value: number } | { ok: false; message: string };

// ASCII digits only: `\d` without the `u` flag does not match the digits of other scripts or full-width ones.
const WHOLE_NUMBER = /^-?\d+$/;

/**
 * Reads the quantity of a receipt or the count of a correction from the text of its field, as a whole number. The text
 * is trimmed (also of no-break spaces); an empty one is the `missing` message of the field. It only checks that the text
 * is a whole number: the allowed range is the API's decision (it answers 422), so a negative number or 0 is passed on.
 */
export function parseCount(text: string, missing: string): CountInput {
  const compact = text.trim();
  if (compact === "") {
    return { ok: false, message: missing };
  }
  if (!WHOLE_NUMBER.test(compact)) {
    return { ok: false, message: "Podaj liczbę całkowitą." };
  }
  const value = Number(compact);
  if (!Number.isSafeInteger(value)) {
    return { ok: false, message: "Liczba jest za duża." };
  }
  return { ok: true, value };
}
