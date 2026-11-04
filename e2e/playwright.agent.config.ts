import { defineConfig, devices } from "@playwright/test";
import { urls } from "./support/urls";

// Configuration for throwaway agent scripts (`make e2e-agent`). The agent directory is mounted at /app/agent:
// scripts are read from agent/tests, screenshots, traces and the JSON report are written to agent/results.
export default defineConfig({
  testDir: "./agent/tests",
  outputDir: "./agent/results",
  timeout: 60_000,
  expect: { timeout: 10_000 },
  workers: 1,
  retries: 0,
  reporter: [["list"], ["json", { outputFile: "agent/results/report.json" }]],
  use: {
    baseURL: urls.store,
    locale: "pl-PL",
    navigationTimeout: 20_000,
    actionTimeout: 10_000,
    trace: "on",
    screenshot: "on",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"] } },
    {
      name: "mobile",
      use: {
        ...devices["Desktop Chrome"],
        viewport: { width: 375, height: 812 },
        isMobile: true,
        hasTouch: true,
      },
    },
  ],
});
