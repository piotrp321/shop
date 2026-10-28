// @vitest-environment node
import { ESLint } from "eslint";
import { describe, expect, it } from "vitest";

const eslint = new ESLint();

async function lint(code: string, filePath: string) {
  const results = await eslint.lintText(code, { filePath });
  return (results[0]?.messages ?? []).filter((message) => message.severity === 2);
}

const ts = "src/views/example.ts";
const vue = (script: string) => `<script setup lang="ts">\n${script}\n</script>\n\n<template>\n  <div />\n</template>\n`;

describe("global fetch is forbidden outside src/api/client.ts", () => {
  it.each([["fetch('/x');"], ["void fetch('/x');"], ["export const f = fetch;"]])("rejects %s in a .ts file", async (code) => {
    const errors = await lint(code + "\n", ts);
    expect(errors).toHaveLength(1);
    expect(errors[0]?.ruleId).toBe("no-restricted-globals");
  });

  it.each([["globalThis"], ["window"], ["self"]])("rejects %s.fetch in a .ts file", async (object) => {
    const errors = await lint(`${object}.fetch('/x');\n`, ts);
    expect(errors).toHaveLength(1);
    expect(errors[0]?.ruleId).toBe("no-restricted-properties");
  });

  it("rejects global fetch in a .vue file", async () => {
    const errors = await lint(vue("const load = () => fetch('/x');\nload();"), "src/views/ExampleView.vue");
    expect(errors).toHaveLength(1);
    expect(errors[0]?.ruleId).toBe("no-restricted-globals");
  });

  it("rejects window.fetch in a .vue file", async () => {
    const errors = await lint(vue("const load = () => window.fetch('/x');\nload();"), "src/views/ExampleView.vue");
    expect(errors).toHaveLength(1);
    expect(errors[0]?.ruleId).toBe("no-restricted-properties");
  });

  it.each([["fetch('/x');"], ["globalThis.fetch('/x');"], ["window.fetch('/x');"], ["self.fetch('/x');"]])(
    "allows %s in src/api/client.ts",
    async (code) => {
      expect(await lint(code + "\n", "src/api/client.ts")).toHaveLength(0);
    },
  );

  it("does not exempt other files in src/api", async () => {
    const errors = await lint("fetch('/x');\n", "src/api/other.ts");
    expect(errors).toHaveLength(1);
    expect(errors[0]?.ruleId).toBe("no-restricted-globals");
  });

  it("allows a local variable that is called fetch", async () => {
    expect(await lint("const fetch = (url: string) => url;\nfetch('/x');\n", ts)).toHaveLength(0);
  });
});
