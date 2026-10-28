import { ESLint } from "eslint";
import { describe, expect, it } from "vitest";

const eslint = new ESLint({ cwd: process.cwd() });

async function lint(code: string, filePath: string) {
  const [result] = await eslint.lintText(code, { filePath });
  return result.messages.filter((message) => message.severity === 2);
}

const jsx = (className: string) => `export const A = () => <div className="${className}" />;\n`;
const tsx = "src/components/example.tsx";

// Assembled at run time so that this file does not contain the arbitrary colour values it tests against.
const arbitrary = (utility: string, value: string) => `${utility}-[${value}]`;

describe("colours only from tokens", () => {
  it.each([
    ["hex arbitrary value", arbitrary("bg", "#ff0000")],
    ["rgb arbitrary value", arbitrary("text", "rgb(0,0,0)")],
    ["oklch arbitrary value", arbitrary("bg", "oklch(0.5_0_0)")],
    ["hsl arbitrary value", arbitrary("border", "hsl(0_0%_0%)")],
  ])("rejects %s", async (_name, className) => {
    const errors = await lint(jsx(className), tsx);
    expect(errors).toHaveLength(1);
    expect(errors[0].ruleId).toBe("no-restricted-syntax");
  });

  it.each([
    ["inline style with a literal colour", `export const A = () => <div style={{ color: "red" }} />;\n`],
    ["inline style with a dynamic colour", `export const A = ({ x }: { x: string }) => <div style={{ backgroundColor: x }} />;\n`],
  ])("rejects %s", async (_name, code) => {
    const errors = await lint(code, tsx);
    expect(errors).toHaveLength(1);
    expect(errors[0].ruleId).toBe("no-restricted-syntax");
  });

  it.each([["bg-black/50"], ["text-red-500"], ["bg-black"]])("rejects the palette class %s", async (className) => {
    const errors = await lint(jsx(className), tsx);
    expect(errors).toHaveLength(1);
    expect(errors[0].ruleId).toBe("better-tailwindcss/no-unknown-classes");
  });

  it.each([["bg-background text-foreground border-border"], ["bg-primary/90"], ["text-muted-foreground rounded-lg"]])(
    "accepts the semantic classes %s",
    async (className) => {
      expect(await lint(jsx(className), tsx)).toHaveLength(0);
    },
  );
});

describe("fetch boundary", () => {
  const code = `export async function load() {\n  return fetch("/x");\n}\n`;

  it("rejects global fetch outside the API client", async () => {
    const errors = await lint(code, "src/catalog/x.ts");
    expect(errors).toHaveLength(1);
    expect(errors[0].ruleId).toBe("no-restricted-globals");
  });

  it("allows global fetch in src/api/client.ts", async () => {
    expect(await lint(code, "src/api/client.ts")).toHaveLength(0);
  });
});
