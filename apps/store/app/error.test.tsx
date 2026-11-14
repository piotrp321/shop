// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import ErrorPage from "./error";

/**
 * The error page (spec: docs/features/sentry/spec.md, D8): it reports an error of the browser through the lazy module
 * of Sentry, only once the SDK is started and only an error without `digest` (an error with `digest` comes from the
 * server, which reported it in `onRequestError`); it looks as before.
 */

const browser = vi.hoisted(() => ({ loaded: vi.fn(), initBrowserSentry: vi.fn(), reportBrowserError: vi.fn() }));

vi.mock("@/observability/browser", () => {
  browser.loaded();
  return { initBrowserSentry: browser.initBrowserSentry, reportBrowserError: browser.reportBrowserError };
});

// The lazy module keeps the started SDK for the page, so every test gets a fresh one.
async function lazyModule() {
  vi.resetModules();
  return import("@/observability/lazy");
}

const MARKUP =
  '<main class="mx-auto flex max-w-content flex-col items-start gap-6 px-gutter py-16 lg:py-28"><h1 class="text-4xl font-semibold tracking-tight lg:text-6xl">Nie udało się wczytać katalogu</h1><p class="text-lg text-muted-foreground">Sklep jest chwilowo niedostępny. Spróbuj ponownie za chwilę.</p><button data-slot="button" data-variant="default" data-size="lg" class="group/button inline-flex shrink-0 items-center justify-center rounded-md border font-semibold whitespace-nowrap transition-colors select-none disabled:cursor-not-allowed aria-invalid:border-destructive [&amp;_svg]:pointer-events-none [&amp;_svg]:shrink-0 [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-5 border-primary bg-primary text-primary-foreground hover:bg-primary/85 disabled:border-border disabled:bg-muted disabled:text-muted-foreground h-14 gap-2 px-6 text-base">Spróbuj ponownie</button></main>';

const config = { dsn: "https://public@o1.ingest.de.sentry.io/2", environment: "demo", release: "abc123" };

beforeEach(() => {
  browser.loaded.mockClear();
  browser.initBrowserSentry.mockClear();
  browser.reportBrowserError.mockClear();
});

afterEach(cleanup);

describe("the error page", () => {
  it("looks as before", () => {
    expect(renderToStaticMarkup(<ErrorPage error={new Error("x")} retry={() => {}} />)).toBe(MARKUP);
  });

  it("retries on its button", () => {
    const retry = vi.fn();
    render(<ErrorPage error={new Error("x")} retry={retry} />);
    fireEvent.click(screen.getByRole("button", { name: "Spróbuj ponownie" }));
    expect(retry).toHaveBeenCalledTimes(1);
  });

  it("reports an error of the browser without digest once the SDK is started", async () => {
    const { startBrowserSentry } = await lazyModule();
    const { default: Page } = await import("./error");
    await startBrowserSentry(config);
    const error = new TypeError("x is undefined");

    render(<Page error={error} retry={() => {}} />);

    await waitFor(() => expect(browser.reportBrowserError).toHaveBeenCalledWith(error));
    expect(browser.reportBrowserError).toHaveBeenCalledTimes(1);
  });

  it("does not report an error with digest: the server reported it", async () => {
    const { startBrowserSentry } = await lazyModule();
    const { default: Page } = await import("./error");
    await startBrowserSentry(config);

    render(<Page error={Object.assign(new Error("An error occurred in the Server Components render."), { digest: "3482800910" })} retry={() => {}} />);

    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(browser.reportBrowserError).not.toHaveBeenCalled();
  });

  it("reports nothing and loads no SDK when the SDK is not started (no DSN or a failed read of /api/site)", async () => {
    await lazyModule();
    const { default: Page } = await import("./error");

    render(<Page error={new TypeError("x is undefined")} retry={() => {}} />);

    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(browser.loaded).not.toHaveBeenCalled();
    expect(browser.reportBrowserError).not.toHaveBeenCalled();
  });
});
