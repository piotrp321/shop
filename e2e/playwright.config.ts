import { defineConfig, devices } from "@playwright/test";
import { urls } from "./support/urls";

export default defineConfig({
  testDir: "./tests",
  outputDir: "./test-results",
  timeout: 60_000,
  expect: { timeout: 10_000 },
  // Tests share one stack and one Keycloak; they run one after another.
  workers: 1,
  retries: 0,
  reporter: [["list"]],
  use: {
    baseURL: urls.store,
    locale: "pl-PL",
    navigationTimeout: 20_000,
    actionTimeout: 10_000,
    trace: "retain-on-failure",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
