export type GrossInput = { ok: true; grosze: number } | { ok: false; message: string };

const AMOUNT = /^(\d+)(?:[.,](\d{1,2}))?$/;
const TOO_MANY_DECIMALS = /^\d+[.,]\d{3,}$/;
// Spaces a staff member may type or paste as thousands separators: space, no-break space, narrow no-break space.
const SPACES = /[\s  ]/g;

/**
 * Turns a gross amount typed in złoty ("54,90", "54.90", "54", "1 234,5") into grosze, on the digits of the text
 * and integers only, never through a float. It only checks that the text is an amount: the allowed range is the API's
 * decision (it answers 422), so 0 is passed on as 0.
 */
export function parseGross(text: string): GrossInput {
  const compact = text.replace(SPACES, "");
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
  const [, zlote, fraction = ""] = match;
  const grosze = BigInt(zlote) * 100n + BigInt(fraction.padEnd(2, "0"));
  if (grosze > BigInt(Number.MAX_SAFE_INTEGER)) {
    return { ok: false, message: "Kwota jest za duża." };
  }
  return { ok: true, grosze: Number(grosze) };
}
