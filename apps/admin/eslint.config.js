import { defineConfig, globalIgnores } from "eslint/config";
import betterTailwindcss from "eslint-plugin-better-tailwindcss";
import pluginVue from "eslint-plugin-vue";
import tseslint from "typescript-eslint";

/**
 * Files not yet moved off PrimeVue (docs/features/admin-design/spec.md, „Tokeny i typografia”): the colour, style and
 * import rules below do not apply to them. Explicit paths only, no patterns or directories. Every migration removes its
 * files from the list and no new file joins it: tests/lint/color-rules.test.ts checks that every entry exists and
 * imports PrimeVue. ADMIN-SCREENS removes the list together with PrimeVue.
 */
export const LEGACY_FILES = [
  "src/main.ts",
  "src/primevue.ts",
  "src/promotions/PromotionVariantPicker.vue",
  "src/views/DiscountCodeFormView.vue",
  "src/views/DiscountCodesView.vue",
  "src/views/InventoryView.vue",
  "src/views/OrderDetailView.vue",
  "src/views/OrdersView.vue",
  "src/views/PricesView.vue",
  "src/views/ProductFormView.vue",
  "src/views/PromotionFormView.vue",
  "src/views/PromotionsView.vue",
];

// Every CSS named colour (CSS Color Module Level 4), so that bg-[rebeccapurple] is caught as well as bg-[red].
const NAMED_COLORS = [
  "aliceblue", "antiquewhite", "aqua", "aquamarine", "azure", "beige", "bisque", "black", "blanchedalmond", "blue",
  "blueviolet", "brown", "burlywood", "cadetblue", "chartreuse", "chocolate", "coral", "cornflowerblue", "cornsilk",
  "crimson", "cyan", "darkblue", "darkcyan", "darkgoldenrod", "darkgray", "darkgreen", "darkgrey", "darkkhaki",
  "darkmagenta", "darkolivegreen", "darkorange", "darkorchid", "darkred", "darksalmon", "darkseagreen", "darkslateblue",
  "darkslategray", "darkslategrey", "darkturquoise", "darkviolet", "deeppink", "deepskyblue", "dimgray", "dimgrey",
  "dodgerblue", "firebrick", "floralwhite", "forestgreen", "fuchsia", "gainsboro", "ghostwhite", "gold", "goldenrod",
  "gray", "green", "greenyellow", "grey", "honeydew", "hotpink", "indianred", "indigo", "ivory", "khaki", "lavender",
  "lavenderblush", "lawngreen", "lemonchiffon", "lightblue", "lightcoral", "lightcyan", "lightgoldenrodyellow",
  "lightgray", "lightgreen", "lightgrey", "lightpink", "lightsalmon", "lightseagreen", "lightskyblue", "lightslategray",
  "lightslategrey", "lightsteelblue", "lightyellow", "lime", "limegreen", "linen", "magenta", "maroon",
  "mediumaquamarine", "mediumblue", "mediumorchid", "mediumpurple", "mediumseagreen", "mediumslateblue",
  "mediumspringgreen", "mediumturquoise", "mediumvioletred", "midnightblue", "mintcream", "mistyrose", "moccasin",
  "navajowhite", "navy", "oldlace", "olive", "olivedrab", "orange", "orangered", "orchid", "palegoldenrod", "palegreen",
  "paleturquoise", "palevioletred", "papayawhip", "peachpuff", "peru", "pink", "plum", "powderblue", "purple",
  "rebeccapurple", "red", "rosybrown", "royalblue", "saddlebrown", "salmon", "sandybrown", "seagreen", "seashell",
  "sienna", "silver", "skyblue", "slateblue", "slategray", "slategrey", "snow", "springgreen", "steelblue", "tan", "teal",
  "thistle", "tomato", "turquoise", "violet", "wheat", "white", "whitesmoke", "yellow", "yellowgreen",
].join("|");

// A colour written out: a hex value or a colour function. Case-insensitive, like CSS.
const COLOR_VALUE = String.raw`#[0-9a-f]{3}|\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch|color|color-mix)\(`;
// An arbitrary Tailwind value or property holding such a colour: bg-[#fff], text-[rgb(0,0,0)], [background-color:#fff].
const ARBITRARY_COLOR = String.raw`\[[^\]]*(${COLOR_VALUE})`;
// A named colour as a member of an arbitrary value or property: bg-[red], [color:red], shadow-[0_0_0_2px_red],
// bg-[linear-gradient(red,blue)]. It is delimited by [ _ , ( : ) ], so a token name (var(--kpi-blue)) is not a member.
const NAMED_ARBITRARY_COLOR = String.raw`\[[^\]]*(?<=[\[_,(:])(${NAMED_COLORS})(?=[_,):\]])`;
// A colour anywhere in a string of a bound style: '#fff', 'rgba(…)', 'red', but not a token name like var(--kpi-blue).
const STYLE_COLOR = String.raw`${COLOR_VALUE}|(?<![\w-])(${NAMED_COLORS})(?![\w-])`;
// Style properties that carry a colour, in camelCase or kebab-case.
const COLOR_STYLE_KEY = String.raw`^(background|fill|stroke|box-?shadow|text-?shadow|outline|border([-a-z]*)?|[a-z-]*colou?r)$`;

const colorMessage =
  "Colours come only from the tokens of src/styles/tokens.css: use a token class (e.g. bg-card, text-muted-foreground).";
const styleMessage = "Colours in a bound style are only allowed as a token: var(--token).";
const styleObjectMessage = "A bound style must be an object (or an array of objects) with plain keys; colours are only allowed as var(--token).";

/** `no-restricted-syntax` entries for string literals and template literal parts with the given regexes. */
const literalSelectors = (patterns, message, scope = "") =>
  patterns.flatMap((pattern) => [
    { selector: `${scope}Literal[value=/${pattern}/i]`, message },
    { selector: `${scope}TemplateElement[value.raw=/${pattern}/i]`, message },
  ]);

// Class strings in template expressions that vue/no-restricted-class does not see: inside a call (cn, cva),
// a condition, or an attribute other than :class (e.g. a class prop of a component).
const TEMPLATE_CLASS_CONTEXT = ":matches(CallExpression *, ConditionalExpression *, LogicalExpression *, VAttribute[directive=true]:not([key.argument.name='class']) *)";
const BOUND_STYLE_ATTRIBUTE = "VAttribute[directive=true][key.name.name='bind'][key.argument.name='style']";
const BOUND_STYLE = `${BOUND_STYLE_ATTRIBUTE} `;

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
  {
    // The look comes only from the tokens (src/styles/tokens.css) through Tailwind classes and the components of
    // src/components/ui: no colour written out, no palette class, no style attribute or <style> block, no PrimeVue.
    files: ["src/**/*.{ts,vue}"],
    ignores: LEGACY_FILES,
    plugins: { "better-tailwindcss": betterTailwindcss },
    settings: {
      "better-tailwindcss": { entryPoint: "src/styles/tokens.css" },
    },
    rules: {
      "better-tailwindcss/no-unknown-classes": "error",
      "no-restricted-syntax": ["error", ...literalSelectors([ARBITRARY_COLOR, NAMED_ARBITRARY_COLOR], colorMessage)],
      "vue/no-restricted-class": ["error", `/${ARBITRARY_COLOR}/i`, `/${NAMED_ARBITRARY_COLOR}/i`],
      "vue/no-restricted-syntax": [
        "error",
        ...literalSelectors([ARBITRARY_COLOR, NAMED_ARBITRARY_COLOR], colorMessage).map(({ selector, message }) => ({
          selector: `${selector}${TEMPLATE_CLASS_CONTEXT}`,
          message,
        })),
        ...literalSelectors([STYLE_COLOR], styleMessage, BOUND_STYLE),
        // A bound style is an object (or an array of objects) with plain keys: any other form (a template literal,
        // a concatenation, a variable, a computed key, a spread) hides the colour from the checks above.
        {
          selector: `${BOUND_STYLE_ATTRIBUTE} > VExpressionContainer > :not(ObjectExpression, ArrayExpression)`,
          message: styleObjectMessage,
        },
        {
          selector: `${BOUND_STYLE_ATTRIBUTE} > VExpressionContainer > ArrayExpression > :not(ObjectExpression)`,
          message: styleObjectMessage,
        },
        { selector: `${BOUND_STYLE}ObjectExpression > :not(Property)`, message: styleObjectMessage },
        { selector: `${BOUND_STYLE}Property[computed=true]`, message: styleObjectMessage },
        {
          // A colour property with a computed value cannot be checked: only a literal var(--token) is allowed.
          selector: `${BOUND_STYLE}Property:matches([key.name=/${COLOR_STYLE_KEY}/i], [key.value=/${COLOR_STYLE_KEY}/i]):not([value.type='Literal'])`,
          message: styleMessage,
        },
      ],
      "vue/no-restricted-static-attribute": [
        "error",
        { key: "style", message: "Inline styles are not allowed. Use Tailwind classes backed by the tokens." },
      ],
      "vue/no-restricted-block": [
        "error",
        { element: "style", message: "<style> blocks are not allowed. Use Tailwind classes and src/styles/tokens.css." },
      ],
      "no-restricted-imports": [
        "error",
        {
          patterns: [
            {
              group: ["primevue", "primevue/*", "@primeuix/*", "primeicons", "primeicons/*"],
              message: "PrimeVue is being removed: use the components of src/components/ui (shadcn-vue) and Lucide icons.",
            },
          ],
        },
      ],
    },
  },
  {
    // The shadcn-vue components are named after the element they wrap (Button, Dialog): single words by design.
    // Only these names are exempt, and only inside src/components/ui; every other component keeps the rule.
    files: ["src/components/ui/**/*.vue"],
    rules: {
      "vue/multi-word-component-names": [
        "error",
        {
          ignores: [
            "Alert", "Badge", "Breadcrumb", "Button", "Card", "Checkbox", "Combobox", "Dialog", "Input", "Label",
            "Select", "Sheet", "Skeleton", "Sonner", "Switch", "Table", "Textarea", "Tooltip",
          ],
        },
      ],
    },
  },
  globalIgnores(["dist/**", "coverage/**"]),
]);
