import { beforeEach, describe, expect, it, vi } from "vitest";

/**
 * The lazy entry of Sentry in the browser (spec: docs/features/sentry/spec.md, P2, D8): the module of the SDK
 * (`./browser`, its own chunk) is loaded only when `/api/site` gave a configuration; until the SDK is started errors
 * are not reported, and an error with `digest` never is.
 */

const browser = vi.hoisted(() => ({ loaded: vi.fn(), initBrowserSentry: vi.fn(), reportBrowserError: vi.fn() }));

vi.mock("./browser", () => {
  browser.loaded();
  return { initBrowserSentry: browser.initBrowserSentry, reportBrowserError: browser.reportBrowserError };
});

const config = { dsn: "https://public@o1.ingest.de.sentry.io/2", environment: "demo", release: "abc123" };

async function lazyModule() {
  vi.resetModules();
  return import("./lazy");
}

beforeEach(() => {
  browser.loaded.mockClear();
  browser.initBrowserSentry.mockClear();
  browser.reportBrowserError.mockClear();
});

describe("startBrowserSentry", () => {
  it("loads the module of the SDK and starts it with the configuration, once per page", async () => {
    const { startBrowserSentry } = await lazyModule();

    await Promise.all([startBrowserSentry(config), startBrowserSentry(config)]);

    expect(browser.loaded).toHaveBeenCalledTimes(1);
    expect(browser.initBrowserSentry).toHaveBeenCalledTimes(1);
    expect(browser.initBrowserSentry).toHaveBeenCalledWith(config);
  });

  it("leaves the page working when the module cannot be loaded or started", async () => {
    const { reportBrowserError, startBrowserSentry } = await lazyModule();
    browser.initBrowserSentry.mockImplementationOnce(() => {
      throw new Error("invalid DSN");
    });

    await expect(startBrowserSentry(config)).resolves.toBeUndefined();
    reportBrowserError(new Error("x"));
    expect(browser.reportBrowserError).not.toHaveBeenCalled();
  });
});

describe("reportBrowserError", () => {
  it("reports nothing and loads nothing before the SDK is started", async () => {
    const { reportBrowserError } = await lazyModule();

    reportBrowserError(new Error("x"));

    expect(browser.loaded).not.toHaveBeenCalled();
    expect(browser.reportBrowserError).not.toHaveBeenCalled();
  });

  it("reports an error without digest once the SDK is started", async () => {
    const { reportBrowserError, startBrowserSentry } = await lazyModule();
    await startBrowserSentry(config);
    const error = new Error("x");

    reportBrowserError(error);
    reportBrowserError("a thrown string");

    expect(browser.reportBrowserError).toHaveBeenNthCalledWith(1, error);
    expect(browser.reportBrowserError).toHaveBeenNthCalledWith(2, "a thrown string");
  });

  it("never reports an error with digest", async () => {
    const { reportBrowserError, startBrowserSentry } = await lazyModule();
    await startBrowserSentry(config);

    reportBrowserError(Object.assign(new Error("An error occurred in the Server Components render."), { digest: "1" }));
    reportBrowserError({ digest: "2" });

    expect(browser.reportBrowserError).not.toHaveBeenCalled();
  });
});
