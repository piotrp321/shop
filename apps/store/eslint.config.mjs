import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";
import betterTailwindcss from "eslint-plugin-better-tailwindcss";

// Colour functions and hex values inside an arbitrary Tailwind value, e.g. bg-[#fff] or text-[rgb(0,0,0)].
const ARBITRARY_COLOR = String.raw`-\[[^\]]*(#[0-9a-fA-F]{3}|\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix)\()`;
const arbitraryColorMessage =
  "Arbitrary colour values are not allowed. Use a semantic token from src/styles/tokens.css (e.g. bg-background, text-foreground).";

// Style properties that carry a colour.
const COLOR_STYLE_KEY = String.raw`^(background|fill|stroke|boxShadow|textShadow|[a-z]*[A-Z]?[a-z]*[Cc]olor)$`;
const styleColorMessage =
  "Colours in the style attribute are not allowed. Use a Tailwind class backed by a semantic token.";

export default defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    files: ["**/*.{ts,tsx}"],
    plugins: { "better-tailwindcss": betterTailwindcss },
    settings: {
      "better-tailwindcss": { entryPoint: "src/styles/tokens.css" },
    },
    rules: {
      "better-tailwindcss/no-unknown-classes": "error",
      "no-restricted-globals": [
        "error",
        {
          name: "fetch",
          message: "Use the generated API client from src/api/client.ts instead of global fetch.",
        },
      ],
      "no-restricted-syntax": [
        "error",
        { selector: `Literal[value=/${ARBITRARY_COLOR}/]`, message: arbitraryColorMessage },
        { selector: `TemplateElement[value.raw=/${ARBITRARY_COLOR}/]`, message: arbitraryColorMessage },
        {
          selector: `JSXAttribute[name.name='style'] Property[key.name=/${COLOR_STYLE_KEY}/]`,
          message: styleColorMessage,
        },
        {
          selector: `JSXAttribute[name.name='style'] Property[key.value=/${COLOR_STYLE_KEY}|^(background|border|outline)-/]`,
          message: styleColorMessage,
        },
      ],
    },
  },
  {
    // The API client is the only place that talks to the network.
    files: ["src/api/client.ts"],
    rules: { "no-restricted-globals": "off" },
  },
  globalIgnores([".next/**", "out/**", "build/**", "next-env.d.ts", "coverage/**"]),
]);
