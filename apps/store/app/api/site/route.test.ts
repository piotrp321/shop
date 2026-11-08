import { afterEach, describe, expect, it, vi } from "vitest";
import { GET } from "./route";

afterEach(() => {
  vi.unstubAllEnvs();
});

const read = async (env: { demo?: string; accounts?: string }) => {
  vi.stubEnv("STORE_DEMO_NOTICE", env.demo);
  vi.stubEnv("STORE_CUSTOMER_ACCOUNTS", env.accounts);
  const response = await GET();
  return { response, body: await response.json() };
};

describe("GET /api/site", () => {
  it("defaults to no demo notice and customer accounts on when the variables are not set", async () => {
    const { response, body } = await read({});
    expect(response.status).toBe(200);
    expect(body).toEqual({ demoNotice: false, customerAccounts: true });
  });

  it.each([
    ["true", "true", true, true],
    ["true", "false", true, false],
    ["false", "true", false, true],
    ["false", "false", false, false],
  ])("STORE_DEMO_NOTICE=%s and STORE_CUSTOMER_ACCOUNTS=%s", async (demo, accounts, demoNotice, customerAccounts) => {
    const { body } = await read({ demo, accounts });
    expect(body).toEqual({ demoNotice, customerAccounts });
  });

  it("reads each variable on its own", async () => {
    expect((await read({ demo: "true" })).body).toEqual({ demoNotice: true, customerAccounts: true });
    expect((await read({ accounts: "false" })).body).toEqual({ demoNotice: false, customerAccounts: false });
  });

  it("ignores case and surrounding spaces of true and false", async () => {
    expect((await read({ demo: " TRUE ", accounts: "False" })).body).toEqual({ demoNotice: true, customerAccounts: false });
  });

  it.each(["", "1", "0", "yes", "no", "on", "tru"])("falls back to the default for the unknown value %j", async (value) => {
    expect((await read({ demo: value, accounts: value })).body).toEqual({ demoNotice: false, customerAccounts: true });
  });

  it("is never cached", async () => {
    const { response } = await read({});
    expect(response.headers.get("Cache-Control")).toBe("no-store");
    expect(response.headers.get("Content-Type")).toContain("application/json");
  });

  it("reads the variables on every call", async () => {
    expect((await read({ demo: "true" })).body.demoNotice).toBe(true);
    expect((await read({ demo: "false" })).body.demoNotice).toBe(false);
  });
});
