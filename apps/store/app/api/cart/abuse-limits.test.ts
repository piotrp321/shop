import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { sessionData, testConfig } from "@/auth/test-support";
import { getCartDeps } from "@/cart/deps";
import {
  cartRequest,
  cartTestDeps,
  confirmationCookie,
  emptyView,
  GUEST_CART_ID,
  mergeAnswer,
  NEW_GUEST_CART_ID,
  problem,
  sessionCookie,
  signedCartCookie,
  VARIANT_ID,
  viewWithOneLine,
} from "@/cart/test-support";
import { POST as startPayment } from "../checkout/payment/route";
import { POST as checkout } from "../checkout/route";
import { DELETE as removeItem, PATCH as changeItem } from "./items/[variantId]/route";
import { POST as addItem } from "./items/route";
import { GET as readCart } from "./route";
import { GET as readSummary } from "./summary/route";

vi.mock("@/cart/deps", () => ({ getCartDeps: vi.fn() }));

let setup: ReturnType<typeof cartTestDeps>;

beforeEach(() => {
  setup = cartTestDeps();
  vi.mocked(getCartDeps).mockResolvedValue(setup.deps);
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const GUEST_CART = `/api/v1/guest-carts/${GUEST_CART_ID}`;
const TOO_LARGE = "Treść żądania jest za duża.";
const LIMITED = "Zbyt wiele prób. Spróbuj ponownie za kilka minut.";
const LIMIT = 16 * 1024;

const limited = () =>
  Response.json(
    { type: "about:blank", title: "Too Many Requests", status: 429, detail: LIMITED },
    { status: 429, headers: { "Content-Type": "application/problem+json", "Retry-After": "120" } },
  );

/** Answers every call of the API the way a working API would. */
function apiWorks() {
  setup.on((call) => {
    if (call.path.endsWith("/merge")) return mergeAnswer("nothing_to_merge");
    if (call.path.endsWith("/summary")) return Response.json({ itemCount: 0 });
    if (call.method === "POST" && call.path === "/api/v1/guest-carts") return Response.json(emptyView(NEW_GUEST_CART_ID), { status: 201 });
    if (call.path.endsWith("/payment-providers")) return Response.json([{ code: "demo-card", name: "Karta" }]);
    return Response.json(viewWithOneLine(GUEST_CART_ID));
  });
}

const signedIn = async () => sessionCookie(await setup.deps.sessions.create(sessionData({ accessToken: "access-9" })));
const context = { params: Promise.resolve({ variantId: VARIANT_ID }) };

describe("the address of the client reaches every call of the API of the cart routes", () => {
  const withAddress = (method: string, path: string, options: Parameters<typeof cartRequest>[2] = {}) => {
    const request = cartRequest(method, path, options);
    request.headers.set("CF-Connecting-IP", "203.0.113.7");
    return request;
  };
  const guest = signedCartCookie(GUEST_CART_ID);
  const item = { variantId: VARIANT_ID, quantity: 1 };
  const guestRoutes: [string, () => Promise<Response>][] = [
    ["GET /api/cart", () => readCart(withAddress("GET", "/api/cart", { cookie: guest }))],
    ["GET /api/cart with a code", () => readCart(withAddress("GET", "/api/cart?discountCode=ABC", { cookie: guest }))],
    ["GET /api/cart/summary", () => readSummary(withAddress("GET", "/api/cart/summary", { cookie: guest }))],
    ["POST /api/cart/items", () => addItem(withAddress("POST", "/api/cart/items", { cookie: guest, body: item }))],
    ["POST /api/cart/items creating the cart", () => addItem(withAddress("POST", "/api/cart/items", { body: item }))],
    ["PATCH /api/cart/items/{id}", () => changeItem(withAddress("PATCH", `/api/cart/items/${VARIANT_ID}`, { cookie: guest, body: { quantity: 2 } }), context)],
    ["DELETE /api/cart/items/{id}", () => removeItem(withAddress("DELETE", `/api/cart/items/${VARIANT_ID}`, { cookie: guest }), context)],
  ];

  it.each(guestRoutes)("%s sends it to the API when CLIENT_IP_HEADER names it", async (_name, run) => {
    vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
    apiWorks();
    expect((await run()).status).toBe(200);

    expect(setup.calls.length).toBeGreaterThan(0);
    expect(setup.calls.map((call) => call.clientIp)).toEqual(setup.calls.map(() => "203.0.113.7"));
  });

  it.each(guestRoutes)("%s sends no address without CLIENT_IP_HEADER", async (_name, run) => {
    vi.stubEnv("CLIENT_IP_HEADER", "");
    apiWorks();
    await run();

    expect(setup.calls.length).toBeGreaterThan(0);
    expect(setup.calls.map((call) => call.clientIp)).toEqual(setup.calls.map(() => undefined));
  });

  it("sends it with the merge and the call of a customer", async () => {
    vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
    apiWorks();
    const cookie = `${await signedIn()}; ${guest}`;
    await addItem(withAddress("POST", "/api/cart/items", { cookie, body: item }));

    expect(setup.calls.map((call) => [call.path, call.clientIp])).toEqual([
      ["/api/v1/me/cart/merge", "203.0.113.7"],
      ["/api/v1/me/cart/items", "203.0.113.7"],
    ]);
  });
});

describe("a 429 of the API of the cart", () => {
  const ITEM = { body: { variantId: VARIANT_ID, quantity: 1 } };

  async function expectLimited(response: Response) {
    expect(response.status).toBe(429);
    expect(response.headers.get("retry-after")).toBe("120");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(await response.json()).toEqual({ detail: LIMITED });
  }

  it("is a 429 with detail and Retry-After on a guest's add", async () => {
    setup.answer("POST", `${GUEST_CART}/items`, limited);
    await expectLimited(await addItem(cartRequest("POST", "/api/cart/items", { ...ITEM, cookie: signedCartCookie(GUEST_CART_ID) })));
  });

  it("is a 429 on the creation of the guest cart, and nothing else is called or set", async () => {
    setup.answer("POST", "/api/v1/guest-carts", limited);
    const response = await addItem(cartRequest("POST", "/api/cart/items", ITEM));
    await expectLimited(response);

    expect(setup.calls.map((call) => call.path)).toEqual(["/api/v1/guest-carts"]);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("is a 429 on a guest's change and removal", async () => {
    setup.answer("PUT", `${GUEST_CART}/items/${VARIANT_ID}`, limited);
    setup.answer("DELETE", `${GUEST_CART}/items/${VARIANT_ID}`, limited);
    const cookie = signedCartCookie(GUEST_CART_ID);
    await expectLimited(await changeItem(cartRequest("PATCH", `/api/cart/items/${VARIANT_ID}`, { cookie, body: { quantity: 2 } }), context));
    await expectLimited(await removeItem(cartRequest("DELETE", `/api/cart/items/${VARIANT_ID}`, { cookie }), context));
  });

  it("is a 429 on a customer's add, change and removal", async () => {
    setup.answer("POST", "/api/v1/me/cart/items", limited);
    setup.answer("PUT", `/api/v1/me/cart/items/${VARIANT_ID}`, limited);
    setup.answer("DELETE", `/api/v1/me/cart/items/${VARIANT_ID}`, limited);
    setup.answer("POST", "/api/v1/me/cart/merge", () => mergeAnswer("nothing_to_merge"));
    const cookie = await signedIn();
    await expectLimited(await addItem(cartRequest("POST", "/api/cart/items", { ...ITEM, cookie })));
    await expectLimited(await changeItem(cartRequest("PATCH", `/api/cart/items/${VARIANT_ID}`, { cookie, body: { quantity: 2 } }), context));
    await expectLimited(await removeItem(cartRequest("DELETE", `/api/cart/items/${VARIANT_ID}`, { cookie }), context));
  });

  it("is a 429 on the preview of a code, of a guest and of a customer", async () => {
    setup.answer("GET", GUEST_CART, limited);
    setup.answer("GET", "/api/v1/me/cart", limited);
    await expectLimited(await readCart(cartRequest("GET", "/api/cart?discountCode=ABC", { cookie: signedCartCookie(GUEST_CART_ID) })));
    await expectLimited(await readCart(cartRequest("GET", "/api/cart?discountCode=ABC", { cookie: await signedIn() })));
  });

  it("keeps the other refusals as they were (a 409 has no Retry-After)", async () => {
    setup.answer("POST", `${GUEST_CART}/items`, () => problem(409, "Za mało sztuk."));
    const response = await addItem(cartRequest("POST", "/api/cart/items", { ...ITEM, cookie: signedCartCookie(GUEST_CART_ID) }));

    expect(response.status).toBe(409);
    expect(response.headers.get("retry-after")).toBeNull();
  });
});

describe("the limit of the JSON body (16 KiB)", () => {
  const encoder = new TextEncoder();
  type Body = string | ReadableStream<Uint8Array>;

  /** A JSON object of exactly `bytes` bytes. */
  function jsonOf(bytes: number, fields: Record<string, unknown>): string {
    const head = JSON.stringify({ ...fields, pad: "" });
    return head.slice(0, -2) + "x".repeat(bytes - encoder.encode(head).length) + '"}';
  }
  const stream = (text: string, onPull: () => void = () => {}) => {
    const bytes = encoder.encode(text);
    let sent = false;
    return new ReadableStream<Uint8Array>({
      pull(controller) {
        onPull();
        if (!sent) {
          sent = true;
          // Two chunks, so that a limit checked on the whole body only would not do.
          controller.enqueue(bytes.slice(0, bytes.length >> 1));
          controller.enqueue(bytes.slice(bytes.length >> 1));
        }
        controller.close();
      },
      // Nothing is pulled before somebody reads: a body that is refused unread must show as not pulled.
    }, { highWaterMark: 0 });
  };
  function raw(path: string, method: string, body: Body, headers: Record<string, string>) {
    return new NextRequest(`http://0.0.0.0:3000${path}`, {
      method,
      headers: { origin: testConfig.storeUrl, "content-type": "application/json", ...headers },
      body,
      ...(typeof body === "string" ? {} : { duplex: "half" }),
    } as ConstructorParameters<typeof NextRequest>[1]);
  }

  const cartCookie = signedCartCookie(GUEST_CART_ID);
  type Route = { fields: Record<string, unknown>; send: (body: Body, headers?: Record<string, string>) => Promise<Response> };
  const routes: Record<string, Route> = {
    "POST /api/cart/items": {
      fields: { variantId: VARIANT_ID, quantity: 1 },
      send: (body, headers) => addItem(raw("/api/cart/items", "POST", body, { cookie: cartCookie, ...headers })),
    },
    "PATCH /api/cart/items/{id}": {
      fields: { quantity: 2 },
      send: (body, headers) => changeItem(raw(`/api/cart/items/${VARIANT_ID}`, "PATCH", body, { cookie: cartCookie, ...headers }), context),
    },
    "POST /api/checkout": {
      fields: { provider: "demo-card" },
      send: (body, headers) => checkout(raw("/api/checkout", "POST", body, { cookie: cartCookie, "idempotency-key": "k-1", ...headers })),
    },
    "POST /api/checkout/payment": {
      fields: { provider: "demo-card" },
      send: (body, headers) => startPayment(raw("/api/checkout/payment", "POST", body, { cookie: confirmationCookie(), "idempotency-key": "k-1", ...headers })),
    },
  };

  async function expectTooLarge(response: Response) {
    expect(response.status).toBe(413);
    expect(await response.json()).toEqual({ detail: TOO_LARGE });
    expect(setup.calls).toEqual([]);
  }

  describe.each(Object.keys(routes))("%s", (name) => {
    const route = routes[name]!;

    it("answers 413 by the Content-Length alone, without reading the body", async () => {
      let pulled = false;
      await expectTooLarge(await route.send(stream(jsonOf(LIMIT + 1, route.fields), () => (pulled = true)), { "content-length": String(LIMIT + 1) }));

      expect(pulled).toBe(false);
    });

    it("answers 413 for a streamed body without Content-Length, one byte over", async () => {
      await expectTooLarge(await route.send(stream(jsonOf(LIMIT + 1, route.fields))));
    });

    it("answers 413 when Content-Length understates the body", async () => {
      await expectTooLarge(await route.send(stream(jsonOf(LIMIT + 1, route.fields)), { "content-length": "20" }));
    });

    it("answers 413 for a body of text over the limit", async () => {
      await expectTooLarge(await route.send(jsonOf(LIMIT + 1, route.fields), { "content-length": String(LIMIT + 1) }));
    });

    it("counts bytes, not characters", async () => {
      await expectTooLarge(await route.send(stream(JSON.stringify({ ...route.fields, pad: "ł".repeat(LIMIT / 2) }))));
    });

    it("takes a body of exactly the limit", async () => {
      apiWorks();
      const response = await route.send(stream(jsonOf(LIMIT, route.fields)));

      expect(response.status).not.toBe(413);
    });
  });

  it("takes a body of exactly the limit all the way to the API", async () => {
    apiWorks();
    const route = routes["POST /api/cart/items"]!;
    const response = await route.send(jsonOf(LIMIT, route.fields));

    expect(response.status).toBe(200);
    expect(setup.calls.length).toBeGreaterThan(0);
  });
});
