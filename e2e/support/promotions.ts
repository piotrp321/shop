import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { urls } from "./urls";
import { waitForBearerToken } from "./staff-login";

// The panel keeps the access token of the staff member in memory and Keycloak issues it for 5 minutes: a token older
// than this is taken again (a reload of the panel, which signs in again by the Keycloak session, without a password).
const TOKEN_MAX_AGE_MS = 180_000;
const ENDED_WAIT_MS = 15_000;

type PromotionItem = { id: string; name: string };
type PromotionDetails = PromotionItem & {
  percentOff: number;
  startsAt: string;
  endsAt: string;
  status: "planned" | "active" | "ended";
  version: number;
  variants: { variantId: string; productId: string | null; productName: string | null }[];
};
type PromotionList = { items: PromotionItem[]; next: string | null };

/** The offer of a product as `GET /api/v1/product-offers` has it, the part the test compares the store with. */
export type Offer = {
  fromGross: number | null;
  fromPromotion: { lowestPriorGross: number | null } | null;
  variants: { gross: number | null; regularGross: number | null; promotion: { lowestPriorGross: number | null } | null }[];
};

/** The bearer token of the panel API, with the moment it was seen. Refresh it before long waits. */
export class PanelToken {
  private constructor(
    private token: string,
    private seenAt: number,
  ) {}

  /** Call before the login (the first request of the panel after it carries the token), await the result after it. */
  static async from(pending: Promise<string>): Promise<PanelToken> {
    return new PanelToken(await pending, Date.now());
  }

  /** The token, taken again from the panel when it is old; the page has to be the one the staff member is logged in on. */
  async fresh(page: Page): Promise<string> {
    if (Date.now() - this.seenAt > TOKEN_MAX_AGE_MS) {
      const pending = waitForBearerToken(page);
      await page.goto(`${urls.admin}/promotions`);
      this.token = await pending;
      this.seenAt = Date.now();
    }
    return this.token;
  }
}

const authorized = (token: string) => ({ Authorization: `Bearer ${token}` });

async function json<T>(response: Awaited<ReturnType<APIRequestContext["get"]>>): Promise<T> {
  expect(response.status(), await response.text()).toBe(200);
  return (await response.json()) as T;
}

async function details(request: APIRequestContext, token: string, id: string): Promise<PromotionDetails> {
  return json<PromotionDetails>(await request.get(`${urls.api}/api/v1/admin/promotions/${id}`, { headers: authorized(token) }));
}

/** The details of a promotion of the panel (the id is in the address of its page). */
export const promotionDetails = details;

async function activePromotionIds(request: APIRequestContext, token: string): Promise<string[]> {
  const ids: string[] = [];
  let after: string | null = null;
  do {
    const query: string = after === null ? "status=active" : `status=active&after=${encodeURIComponent(after)}`;
    const page: PromotionList = await json<PromotionList>(await request.get(`${urls.api}/api/v1/admin/promotions?${query}`, { headers: authorized(token) }));
    ids.push(...page.items.map((item) => item.id));
    after = page.next;
  } while (after !== null);
  return ids;
}

/**
 * Ends every active promotion that has a variant of the product, through the API of the panel: the whole promotion is
 * sent again with the end set to now ("Zakończ teraz"), and the call returns once the API reports it ended. A promotion
 * of the demo data on other products is left alone. Returns how many it ended: a test that does not clean up after
 * itself (or an earlier run that was cut short) leaves one behind, and the next promotion of the product would overlap it.
 */
export async function endActivePromotionsOf(request: APIRequestContext, token: string, productName: string): Promise<number> {
  let ended = 0;
  for (const id of await activePromotionIds(request, token)) {
    const promotion = await details(request, token, id);
    if (!promotion.variants.some((variant) => variant.productName === productName)) continue;

    const response = await request.put(`${urls.api}/api/v1/admin/promotions/${id}`, {
      headers: authorized(token),
      data: {
        name: promotion.name,
        percentOff: promotion.percentOff,
        startsAt: promotion.startsAt,
        endsAt: new Date().toISOString(),
        variantIds: promotion.variants.map((variant) => variant.variantId),
        version: promotion.version,
      },
    });
    expect(response.status(), `ending the promotion "${promotion.name}": ${await response.text()}`).toBe(200);
    await expect
      .poll(async () => (await details(request, token, id)).status, { message: `the promotion "${promotion.name}" did not end within 15 s`, timeout: ENDED_WAIT_MS, intervals: [500] })
      .toBe("ended");
    ended++;
  }
  return ended;
}

/** The public offer of one product (no cache between the test and the API: `urls.api` is the API itself). */
export async function offerOf(request: APIRequestContext, productId: string): Promise<Offer> {
  const body = await json<{ items: Offer[] }>(await request.get(`${urls.api}/api/v1/product-offers?productId=${productId}`));
  const [offer] = body.items;
  if (offer === undefined) throw new Error(`no offer for the product ${productId}`);
  return offer;
}

/** A moment as a `datetime-local` input of the panel takes it: Polish time, `YYYY-MM-DDTHH:mm`. */
export function warsawLocal(ms: number): string {
  const parts = new Intl.DateTimeFormat("sv-SE", {
    timeZone: "Europe/Warsaw",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).format(new Date(ms));
  return parts.replace(" ", "T");
}
