import { defineConfig, globalIgnores } from "eslint/config";
import pluginVue from "eslint-plugin-vue";
import tseslint from "typescript-eslint";

export default defineConfig([
  ...tseslint.configs.recommended,
  ...pluginVue.configs["flat/recommended"],
  {
    files: ["**/*.vue"],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
  },
  {
    files: ["**/*.{ts,vue}"],
    rules: {
      "no-restricted-globals": [
        "error",
        {
          name: "fetch",
          message: "Use the generated API client from src/api/client.ts instead of global fetch.",
        },
      ],
      "no-restricted-properties": [
        "error",
        ...["globalThis", "window", "self"].map((object) => ({
          object,
          property: "fetch",
          message: "Use the generated API client from src/api/client.ts instead of fetch.",
        })),
      ],
    },
  },
  {
    // The API client is the only place that talks to the network.
    files: ["src/api/client.ts"],
    rules: { "no-restricted-globals": "off", "no-restricted-properties": "off" },
  },
  globalIgnores(["dist/**", "coverage/**"]),
]);
