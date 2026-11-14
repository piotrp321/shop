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

/** The bar above the header of a demo store. */
export function DemoNotice() {
  const { demoNotice } = useSiteSwitches();
  if (!demoNotice) {
    return null;
  }
  return (
    <div role="note" className="bg-foreground px-4 py-2 text-center text-sm text-background">
      Sklep demonstracyjny: płatności i zamówienia są symulowane, nic nie zostanie wysłane ani pobrane.
    </div>
  );
}
