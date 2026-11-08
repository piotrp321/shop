import { expect, type APIRequestContext } from "@playwright/test";
import { urls } from "./urls";

export type NewDiscountCode = {
  kind: "percent" | "amount";
  /** The percent, or the amount in grosze. */
  value: number;
  /** In grosze; required by the API for an amount code. */
  minimumOrderGross: number | null;
};

/**
 * An amount the way the API writes it in its texts and e-mails: grosze as "1 234,50 zł", groups of thousands separated
 * by a plain space (the store formats with the non-breaking one, see `formatPln`).
 */
export const apiAmount = (grosze: number): string =>
  `${Math.floor(grosze / 100)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, " ")},${String(grosze % 100).padStart(2, "0")} zł`;

/** A code text no other run (and no demo code) has: 3 to 32 characters of A-Z, 0-9 and the dash. */
export const uniqueCodeText = (): string => `E2E-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`.toUpperCase();

/**
 * Creates an enabled discount code with no end and no usage limit through the API of the panel, valid from a minute
 * ago, as the staff member the token belongs to (`marketing` and `admin` may write). The text is unique per call, so
 * runs on one stack never meet each other's code. Returns the text as the API stores it (upper case).
 */
export async function createDiscountCode(request: APIRequestContext, token: string, code: NewDiscountCode): Promise<string> {
  const text = uniqueCodeText();
  const response = await request.post(`${urls.api}/api/v1/admin/discount-codes`, {
    headers: { Authorization: `Bearer ${token}` },
    data: {
      code: text,
      kind: code.kind,
      value: code.value,
      minimumOrderGross: code.minimumOrderGross,
      startsAt: new Date(Date.now() - 60_000).toISOString(),
      endsAt: null,
      usageLimit: null,
      enabled: true,
    },
  });
  expect(response.status(), `creating the discount code ${text}: ${await response.text()}`).toBe(201);
  const created = (await response.json()) as { code: string };
  return created.code;
}
