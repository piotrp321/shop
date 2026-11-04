import AxeBuilder from "@axe-core/playwright";
import { test as base, expect, type Page } from "@playwright/test";

type RuntimeError =
  | { kind: "console"; page: string; text: string }
  | { kind: "pageerror"; page: string; message: string }
  | { kind: "requestfailed"; page: string; url: string; method: string; errorText: string }
  | { kind: "response"; page: string; url: string; method: string; status: number };

// Extends the base test with an automatic fixture that records runtime problems of every page of the context
// (also pages opened later) and attaches them to the test as `runtime-errors` (an empty list too).
export const test = base.extend<{ runtimeErrors: void }>({
  runtimeErrors: [
    async ({ context }, use, testInfo) => {
      const errors: RuntimeError[] = [];

      const watch = (page: Page): void => {
        page.on("console", (message) => {
          if (message.type() === "error") {
            errors.push({ kind: "console", page: page.url(), text: message.text() });
          }
        });
        page.on("pageerror", (error) => {
          errors.push({ kind: "pageerror", page: page.url(), message: error.message });
        });
        page.on("requestfailed", (request) => {
          errors.push({
            kind: "requestfailed",
            page: page.url(),
            url: request.url(),
            method: request.method(),
            errorText: request.failure()?.errorText ?? "",
          });
        });
        page.on("response", (response) => {
          if (response.status() >= 500) {
            errors.push({
              kind: "response",
              page: page.url(),
              url: response.url(),
              method: response.request().method(),
              status: response.status(),
            });
          }
        });
      };

      context.pages().forEach(watch);
      context.on("page", watch);

      await use();

      await testInfo.attach("runtime-errors", {
        body: JSON.stringify(errors, null, 2),
        contentType: "application/json",
      });
    },
    { auto: true },
  ],
});

export { expect };

// Runs axe-core with the WCAG 2.1 A and AA rules, attaches the violations as `a11y-<name>` and returns them.
export async function checkA11y(page: Page, name: string) {
  const results = await new AxeBuilder({ page })
    .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"])
    .analyze();
  const violations = results.violations.map((violation) => ({
    id: violation.id,
    impact: violation.impact,
    help: violation.help,
    helpUrl: violation.helpUrl,
    nodes: violation.nodes.map((node) => node.target),
  }));
  await test.info().attach(`a11y-${name}`, {
    body: JSON.stringify(violations, null, 2),
    contentType: "application/json",
  });
  return results.violations;
}

// Full-page screenshot saved in the test output directory and attached to the test; returns its path.
export async function snap(page: Page, name: string): Promise<string> {
  const path = test.info().outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: true });
  await test.info().attach(name, { path, contentType: "image/png" });
  return path;
}
