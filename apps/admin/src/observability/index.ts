import { getClient, init, setUser } from "@sentry/vue";
import type { App } from "vue";
import type { Router } from "vue-router";
import { readSentryConfig, readUrl } from "@/config";
import { buildSentryOptions, type SentryOptions } from "./options";

/**
 * Starts the SDK of Sentry, only with a DSN in window.__ADMIN_CONFIG__ (spec: docs/features/sentry/spec.md, D1): without
 * one nothing of the SDK runs and nothing is sent. Called from main.ts before the router is installed (the integration of
 * the router needs to see the first navigation) and before `mount`. `overrides` is for tests (a transport without network).
 * Returns whether the SDK was started.
 */
export function initSentry(app: App, router: Router, overrides: SentryOptions = {}): boolean {
  const config = readSentryConfig();
  if (config === null) {
    return false;
  }
  init({ ...buildSentryOptions(config, readUrl("VITE_API_URL"), router), app, ...overrides });
  return true;
}

/**
 * Sets the staff member of the events to the id (the `sub`) and nothing else, or clears it with null (P5): never the login or
 * the e-mail. Without a started SDK it does nothing.
 */
export function setSentryUser(id: string | null): void {
  if (getClient() === undefined) {
    return;
  }
  setUser(id === null ? null : { id });
}
