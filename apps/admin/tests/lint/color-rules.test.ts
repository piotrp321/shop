// @vitest-environment node
import { readFileSync } from "node:fs";
import { ESLint } from "eslint";
import { describe, expect, it } from "vitest";
import { LEGACY_FILES } from "../../eslint.config.js";

const eslint = new ESLint();

async function lint(code: string, filePath: string) {
  const results = await eslint.lintText(code, { filePath });
  return (results[0]?.messages ?? []).filter((message) => message.severity === 2);
}

const ruleIds = (errors: Awaited<ReturnType<typeof lint>>) => errors.map((error) => error.ruleId);

const VUE = "src/components/ExampleWidget.vue";
const TS = "src/lib/example.ts";

// Assembled at run time so that this file does not contain the arbitrary colour values it tests against.
const arbitrary = (utility: string, value: string) => `${utility}-[${value}]`;

const template = (body: string, script = "") =>
  `<script setup lang="ts">\n${script}\n</script>\n\n<template>\n  ${body}\n</template>\n`;
const withCn = 'import { cn } from "@/lib/utils";';

/** The four arbitrary colours of criterion 3 of the spec and the attempts to get around the rule. */
const ARBITRARY_COLOURS: [string, string][] = [
  ["hex", arbitrary("bg", "#fff")],
  ["rgb()", arbitrary("text", "rgb(0,0,0)")],
  ["a named colour with a type hint", arbitrary("border", "color:red")],
  ["oklch()", arbitrary("bg", "oklch(0.5_0.1_50)")],
  ["upper-case hex", arbitrary("bg", "#FFF")],
  ["eight-digit hex", arbitrary("bg", "#ffffff80")],
  ["rgba()", arbitrary("bg", "rgba(0,0,0,0.5)")],
  ["upper-case RGB()", arbitrary("text", "RGB(0,0,0)")],
  ["hsl()", arbitrary("border", "hsl(0_0%_0%)")],
  ["color-mix()", arbitrary("bg", "color-mix(in_srgb,var(--card),var(--interface))")],
  ["a named colour", arbitrary("bg", "red")],
  ["an upper-case named colour", arbitrary("text", "Navy")],
  ["a named colour outside the short list", arbitrary("bg", "rebeccapurple")],
  ["an arbitrary property with a hex", "[background-color:#fff]"],
  ["an arbitrary property with a named colour", "[color:red]"],
  ["a modifier", `hover:${arbitrary("bg", "#fff")}`],
];

describe("colours only from tokens: classes in the template of a .vue file", () => {
  it.each(ARBITRARY_COLOURS)("rejects %s in a static class", async (_name, className) => {
    const errors = await lint(template(`<div class="p-2 ${className}" />`), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-class"]);
  });

  it.each(ARBITRARY_COLOURS)("rejects %s in a bound class", async (_name, className) => {
    const errors = await lint(template(`<div :class="['p-2', '${className}']" />`), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-class"]);
  });

  it("rejects an arbitrary colour as a key of a class object", async () => {
    const errors = await lint(template(`<div :class="{ '${arbitrary("bg", "#fff")}': true }" />`), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-class"]);
  });

  it.each(ARBITRARY_COLOURS)("rejects %s inside cn() in the template", async (_name, className) => {
    const errors = await lint(template(`<div :class="cn('p-2', '${className}')" />`, withCn), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-syntax"]);
  });

  it("rejects an arbitrary colour in a conditional class", async () => {
    const code = template(`<div :class="ok ? '${arbitrary("bg", "#fff")}' : 'p-2'" />`, "const ok = true;");
    expect(ruleIds(await lint(code, VUE))).toEqual(["vue/no-restricted-syntax"]);
  });

  it("rejects an arbitrary colour passed to a component as a class prop", async () => {
    const code = template(`<div :content-class="'${arbitrary("bg", "#fff")}'" />`);
    expect(ruleIds(await lint(code, VUE))).toEqual(["vue/no-restricted-syntax"]);
  });
});

describe("colours only from tokens: strings in <script> and .ts files", () => {
  it.each(ARBITRARY_COLOURS)("rejects %s in a string of <script>", async (_name, className) => {
    const errors = await lint(template(`<div :class="c" />`, `const c = "p-2 ${className}";`), VUE);
    expect(ruleIds(errors)).toEqual(["no-restricted-syntax"]);
  });

  it.each(ARBITRARY_COLOURS)("rejects %s in a string of a .ts file", async (_name, className) => {
    const errors = await lint(`export const c = "p-2 ${className}";\n`, TS);
    expect(ruleIds(errors)).toEqual(["no-restricted-syntax"]);
  });

  it("rejects an arbitrary colour in a template literal", async () => {
    const code = "export const c = (x: string) => `p-2 " + arbitrary("bg", "#abc") + " ${x}`;\n";
    expect(ruleIds(await lint(code, TS))).toEqual(["no-restricted-syntax"]);
  });

  it("rejects an arbitrary colour in a cva variant", async () => {
    const code = `import { cva } from "class-variance-authority";\nexport const v = cva("p-2", { variants: { tone: { x: "${arbitrary("bg", "#fff")}" } } });\n`;
    expect(ruleIds(await lint(code, TS))).toEqual(["no-restricted-syntax"]);
  });
});

describe("only classes that exist in tokens.css", () => {
  it.each([["bg-red-500"], ["text-white"], ["bg-black/50"], ["text-gray-700"], ["bg-palette-orange"]])(
    "rejects the palette class %s in the template",
    async (className) => {
      const errors = await lint(template(`<div class="p-2 ${className}" />`), VUE);
      expect(ruleIds(errors)).toEqual(["better-tailwindcss/no-unknown-classes"]);
    },
  );

  it("rejects bg-red-500 inside cn() in a .ts file", async () => {
    const code = `${withCn}\nexport const c = cn("p-2", "bg-red-500");\n`;
    expect(ruleIds(await lint(code, TS))).toEqual(["better-tailwindcss/no-unknown-classes"]);
  });

  it.each([["bg-card text-muted-foreground"], ["bg-primary text-primary-foreground hover:bg-primary-hover"], ["border-input-border bg-overlay"], ["bg-status-success-soft text-status-success rounded-sm shadow-sm"]])(
    "accepts the token classes %s",
    async (className) => {
      expect(await lint(template(`<div class="${className}" />`), VUE)).toEqual([]);
      expect(await lint(`${withCn}\nexport const c = cn("${className}");\n`, TS)).toEqual([]);
    },
  );
});

describe("styles only through classes", () => {
  it("rejects a static style attribute", async () => {
    const errors = await lint(template(`<div style="color: red" />`), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-static-attribute"]);
  });

  it("rejects a static style attribute without a colour", async () => {
    const errors = await lint(template(`<div style="width: 10px" />`), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-static-attribute"]);
  });

  it("rejects a <style> block", async () => {
    const code = `${template(`<div class="p-2" />`)}\n<style scoped>\n.x {\n  margin: 0;\n}\n</style>\n`;
    expect(ruleIds(await lint(code, VUE))).toEqual(["vue/no-restricted-block"]);
  });

  it.each([
    ["a named colour", `{ color: 'red' }`],
    ["a hex", `{ backgroundColor: '#fff' }`],
    ["a hex under a kebab-case key", `{ 'border-color': '#FFF' }`],
    ["rgba()", `{ boxShadow: '0 0 0 1px rgba(0,0,0,0.1)' }`],
    ["a fallback colour after a token", `{ color: 'var(--interface, #2563c9)' }`],
    ["a string of declarations", `'color: red'`],
    ["a template literal", "`color: #fff`"],
  ])("rejects a bound style with %s", async (_name, value) => {
    const errors = await lint(template(`<div :style="${value}" />`), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-syntax"]);
  });

  it.each([["color"], ["backgroundColor"], ["'border-color'"]])("rejects a computed value of %s in a bound style", async (key) => {
    const errors = await lint(template(`<div :style="{ ${key}: tone }" />`, 'const tone = "x";'), VUE);
    expect(ruleIds(errors)).toEqual(["vue/no-restricted-syntax"]);
  });

  it.each([[`{ color: 'var(--interface)' }`], [`{ '--progress': 'var(--kpi-blue)' }`], [`{ width: '10px' }`]])(
    "accepts the bound style %s",
    async (value) => {
      expect(await lint(template(`<div :style="${value}" />`), VUE)).toEqual([]);
    },
  );
});

describe("PrimeVue only in the files of LEGACY_FILES", () => {
  it.each([["primevue/button"], ["primevue"], ["@primeuix/themes/aura"], ["primeicons/primeicons.css"]])(
    "rejects an import of %s outside LEGACY_FILES",
    async (source) => {
      const errors = await lint(`import "${source}";\n`, TS);
      expect(ruleIds(errors)).toEqual(["no-restricted-imports"]);
    },
  );

  it("does not apply the colour rules to a file of LEGACY_FILES", async () => {
    const legacy = LEGACY_FILES.find((file) => file.endsWith(".vue"));
    expect(legacy).toBeDefined();
    const code = `<script setup lang="ts">\nimport Button from "primevue/button";\n</script>\n\n<template>\n  <Button class="${arbitrary("bg", "#fff")} bg-red-500" style="color: red" />\n</template>\n\n<style scoped>\n.x {\n  color: red;\n}\n</style>\n`;
    expect(await lint(code, legacy!)).toEqual([]);
  });

  it("applies the colour rules to a file next to one of LEGACY_FILES", async () => {
    const legacy = LEGACY_FILES.find((file) => file.endsWith(".vue"))!;
    const sibling = legacy.replace(/[^/]+$/, "NotLegacy.vue");
    expect(ruleIds(await lint(template(`<div class="bg-red-500" />`), sibling))).toEqual(["better-tailwindcss/no-unknown-classes"]);
  });

  it("lists explicit files only", () => {
    expect(LEGACY_FILES.length).toBeGreaterThan(0);
    for (const file of LEGACY_FILES) {
      expect(file).toMatch(/^src\/[\w/-]+\.(vue|ts)$/);
    }
  });

  it("every file of the list exists and imports primevue", () => {
    for (const file of LEGACY_FILES) {
      const source = readFileSync(new URL(`../../${file}`, import.meta.url), "utf8");
      expect(source, file).toMatch(/from\s+["'](primevue|@primeuix\/themes)(\/[\w/.-]+)?["']/);
    }
  });
});
