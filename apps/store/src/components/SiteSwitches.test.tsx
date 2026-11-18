// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loadSiteSwitches } from "@/api/client";
import { startBrowserSentry } from "@/observability/lazy";
import { DemoNotice, SiteSwitchesProvider, useSiteSwitches } from "./SiteSwitches";

vi.mock("@/api/client", async (importOriginal) => ({ ...(await importOriginal<typeof import("@/api/client")>()), loadSiteSwitches: vi.fn() }));
vi.mock("@/observability/lazy", () => ({ startBrowserSentry: vi.fn(async () => {}) }));

const NOTICE = "Sklep demonstracyjny: płatności i zamówienia są symulowane, nic nie zostanie wysłane ani pobrane.";

const HEIGHT_PROPERTY = "--demo-notice-height";

/** jsdom has no ResizeObserver: this one remembers the callbacks, so a test can report a new size of the bar. */
const resizeCallbacks = new Set<() => void>();
class FakeResizeObserver {
  private readonly callback: () => void;
  constructor(callback: () => void) {
    this.callback = callback;
  }
  observe() {
    resizeCallbacks.add(this.callback);
  }
  disconnect() {
    resizeCallbacks.delete(this.callback);
  }
}

beforeEach(() => {
  vi.stubGlobal("ResizeObserver", FakeResizeObserver);
  vi.mocked(loadSiteSwitches).mockReset();
  vi.mocked(startBrowserSentry).mockClear();
});

afterEach(() => {
  cleanup();
  resizeCallbacks.clear();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

function Probe() {
  const { customerAccounts, loaded } = useSiteSwitches();
  return <p data-testid="probe">{`${loaded}:${customerAccounts}`}</p>;
}

const page = () => (
  <SiteSwitchesProvider>
    <DemoNotice />
    <Probe />
  </SiteSwitchesProvider>
);

describe("DemoNotice", () => {
  it("shows the demo bar with the exact text when the store is a demo", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: true });
    render(page());

    expect((await screen.findByText(NOTICE)).textContent).toBe(NOTICE);
  });

  it("is pinned to the bottom of the window, out of the flow and under the dialogs (z-50)", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: true });
    render(page());

    const bar = await screen.findByRole("note");
    expect(bar.className).toContain("fixed");
    expect(bar.className).toContain("bottom-0");
    expect(bar.className).toContain("z-40");
  });

  it("publishes its height for the room at the end of the page and the scroll padding, follows a resize, and removes it", async () => {
    let height = 48;
    vi.spyOn(HTMLElement.prototype, "offsetHeight", "get").mockImplementation(() => height);
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: true });
    const { unmount } = render(page());
    await screen.findByRole("note");

    expect(document.documentElement.style.getPropertyValue(HEIGHT_PROPERTY)).toBe("48px");
    height = 72;
    resizeCallbacks.forEach((callback) => callback());
    expect(document.documentElement.style.getPropertyValue(HEIGHT_PROPERTY)).toBe("72px");
    unmount();
    expect(document.documentElement.style.getPropertyValue(HEIGHT_PROPERTY)).toBe("");
  });

  it("publishes no height when there is no bar", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true });
    render(page());

    await screen.findByText("true:true");
    expect(document.documentElement.style.getPropertyValue(HEIGHT_PROPERTY)).toBe("");
  });

  it("shows no bar when the store is not a demo", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true });
    render(page());

    await screen.findByText("true:true");
    expect(screen.queryByText(NOTICE)).toBeNull();
  });

  it("shows no bar while the switches are loading", () => {
    vi.mocked(loadSiteSwitches).mockReturnValue(new Promise(() => {}));
    render(page());

    expect(screen.queryByText(NOTICE)).toBeNull();
    expect(screen.getByTestId("probe").textContent).toBe("false:true");
  });

  it("shows no bar without a provider", () => {
    render(<DemoNotice />);
    expect(screen.queryByText(NOTICE)).toBeNull();
  });

  it("renders no bar on the server, so the HTML of the pages does not depend on the switches", () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: false });
    expect(renderToStaticMarkup(page())).not.toContain("Sklep demonstracyjny");
    expect(loadSiteSwitches).not.toHaveBeenCalled();
  });
});

describe("SiteSwitchesProvider", () => {
  it("reads the switches once for all the components under it", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: false });
    render(page());

    await screen.findByText("true:false");
    expect(screen.getByText(NOTICE)).toBeTruthy();
    expect(loadSiteSwitches).toHaveBeenCalledTimes(1);
  });

  it("is loaded with the default state, bar off and accounts on, when nothing is provided", () => {
    render(<Probe />);
    expect(screen.getByTestId("probe").textContent).toBe("true:true");
  });
});

describe("Sentry in the browser", () => {
  const sentry = { dsn: "https://public@o1.ingest.de.sentry.io/2", environment: "demo", release: "abc123" };

  it("starts the SDK with the configuration of /api/site, once, after reading it", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: true, customerAccounts: true, sentry });
    render(page());

    await screen.findByText(NOTICE);
    expect(startBrowserSentry).toHaveBeenCalledTimes(1);
    expect(startBrowserSentry).toHaveBeenCalledWith(sentry);
  });

  it("does not start the SDK without a configuration (no DSN or a failed read)", async () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true, sentry: null });
    render(page());

    await screen.findByText("true:true");
    expect(startBrowserSentry).not.toHaveBeenCalled();
  });

  it("does not start the SDK on the server", () => {
    vi.mocked(loadSiteSwitches).mockResolvedValue({ demoNotice: false, customerAccounts: true, sentry });
    renderToStaticMarkup(page());
    expect(startBrowserSentry).not.toHaveBeenCalled();
  });
});
