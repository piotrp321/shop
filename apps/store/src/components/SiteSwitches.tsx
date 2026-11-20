"use client";

import { createContext, type ReactNode, useContext, useEffect, useState } from "react";
import { DEFAULT_SITE_SWITCHES, loadSiteSwitches, type SiteSwitches } from "@/api/client";
import { startBrowserSentry } from "@/observability/lazy";

/** `loaded` is false until the read of `/api/site` ends (successfully or not); until then the switches are the defaults. */
type SiteSwitchesState = Omit<SiteSwitches, "sentry"> & { loaded: boolean };

// Without a provider (a component rendered alone) the state is the default one, already loaded.
const DEFAULT_STATE = { demoNotice: DEFAULT_SITE_SWITCHES.demoNotice, customerAccounts: DEFAULT_SITE_SWITCHES.customerAccounts };
const SiteSwitchesContext = createContext<SiteSwitchesState>({ ...DEFAULT_STATE, loaded: true });

/**
 * Reads the switches of the deployment once per page, in the browser (never on the server), so the static and ISR
 * HTML is the same in every deployment. A failed read leaves the defaults. With a configuration of Sentry it starts the
 * SDK of the browser, which is loaded only then (`@/observability/lazy`).
 */
export function SiteSwitchesProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<SiteSwitchesState>({ ...DEFAULT_STATE, loaded: false });

  useEffect(() => {
    let active = true;
    void loadSiteSwitches().then(({ sentry, ...switches }) => {
      if (sentry) void startBrowserSentry(sentry);
      if (active) setState({ ...switches, loaded: true });
    });
    return () => {
      active = false;
    };
  }, []);

  return <SiteSwitchesContext value={state}>{children}</SiteSwitchesContext>;
}

export const useSiteSwitches = () => useContext(SiteSwitchesContext);

/** The custom property that holds the height of the demo bar while it is shown; the layout and `globals.css` read it. */
const NOTICE_HEIGHT_PROPERTY = "--demo-notice-height";

/**
 * The bar of a demo store, pinned to the bottom of the window and out of the flow, so its late appearance (the switches
 * are read in the browser) moves nothing. While it is shown it publishes its measured height (it is 2 to 3 lines on a
 * phone) in `--demo-notice-height`: the layout leaves that much room at the end of the page and the document gets that
 * much `scroll-padding-bottom`, so neither the footer nor a focused element stays under it. It lies under the dialogs
 * of the store (`z-50`).
 */
export function DemoNotice() {
  const { demoNotice } = useSiteSwitches();
  const [bar, setBar] = useState<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!bar) {
      return;
    }
    const root = document.documentElement;
    const publish = () => root.style.setProperty(NOTICE_HEIGHT_PROPERTY, `${bar.offsetHeight}px`);
    publish();
    const observer = new ResizeObserver(publish);
    observer.observe(bar);
    return () => {
      observer.disconnect();
      root.style.removeProperty(NOTICE_HEIGHT_PROPERTY);
    };
  }, [bar]);

  if (!demoNotice) {
    return null;
  }
  return (
    <div
      ref={setBar}
      role="note"
      className="fixed inset-x-0 bottom-0 z-40 bg-foreground px-4 py-2 text-center text-sm text-background"
    >
      Sklep demonstracyjny: płatności i zamówienia są symulowane, nic nie zostanie wysłane ani pobrane.
    </div>
  );
}
