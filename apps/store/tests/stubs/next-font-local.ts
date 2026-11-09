// `next/font/local` is replaced by a compiler transform in Next.js; outside it (Vitest) the call has no meaning. The stub
// returns the shape of its result so that modules that load fonts (`src/styles/fonts.ts`) can be imported in tests.
export default function localFont(options: { variable?: string }) {
  return { className: "font-test", style: { fontFamily: "Outfit" }, variable: options.variable ? `${options.variable.slice(2)}-test` : "" };
}
