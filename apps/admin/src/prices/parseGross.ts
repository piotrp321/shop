export type GrossInput = { ok: true; grosze: number } | { ok: false; message: string };

// A space typed or pasted as a thousands separator: space, no-break space, narrow no-break space.
const SEPARATOR = "[ \\u00a0\\u202f]";
// Whole zlote: plain digits, or groups of 3 digits after the first 1-3, each pair of groups split by exactly one space.
const WHOLE = `(\\d+|\\d{1,3}(?:${SEPARATOR}\\d{3})+)`;
const AMOUNT = new RegExp(`^${WHOLE}(?:[.,](\\d{1,2}))?$`);
const TOO_MANY_DECIMALS = new RegExp(`^${WHOLE}[.,]\\d{3,}$`);
const SEPARATORS = new RegExp(SEPARATOR, "g");

/**
 * Turns a gross amount typed in złoty ("54,90", "54.90", "54", "1 234,5") into grosze, on the digits of the text
 * and integers only, never through a float. A space is accepted only between groups of 3 digits of the whole part
 * ("1 234 567"), at the edges it is trimmed. It only checks that the text is an amount: the allowed range is the API's
 * decision (it answers 422), so 0 is passed on as 0.
 */
export function parseGross(text: string): GrossInput {
  const compact = text.trim();
  if (compact === "") {
    return { ok: false, message: "Podaj kwotę brutto." };
  }
  if (compact.startsWith("-")) {
    return { ok: false, message: "Kwota nie może być ujemna." };
  }
  if (TOO_MANY_DECIMALS.test(compact)) {
    return { ok: false, message: "Kwota może mieć najwyżej 2 miejsca po przecinku." };
  }
  const match = AMOUNT.exec(compact);
  if (match === null) {
    return { ok: false, message: "Wpisz kwotę w złotych, np. 54,90." };
  }
  const [, whole = "", fraction = ""] = match;
  const grosze = BigInt(whole.replace(SEPARATORS, "")) * 100n + BigInt(fraction.padEnd(2, "0"));
  if (grosze > BigInt(Number.MAX_SAFE_INTEGER)) {
    return { ok: false, message: "Kwota jest za duża." };
  }
  return { ok: true, grosze: Number(grosze) };
}
