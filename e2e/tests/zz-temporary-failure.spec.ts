import { expect, test } from "@playwright/test";

test("temporary failure to check that the e2e check turns red", () => {
  expect(1).toBe(2);
});
