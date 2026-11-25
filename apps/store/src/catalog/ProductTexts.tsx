import { Fragment } from "react";
import type { components } from "@/api/schema";

type ProductParameter = components["schemas"]["ProductParameter"];

/**
 * The parts of a description: separated by an empty line (a line of white space counts as empty), without empty parts.
 * Each part is its lines: inside a part a single line end is a line break.
 */
function paragraphsOf(description: string): string[][] {
  return description
    .split(/\n\s*\n/)
    .map((part) => part.split("\n"))
    .filter((lines) => lines.some((line) => line.trim() !== ""));
}

/**
 * The „Opis” section: the long description of the API as plain text, a paragraph for each part separated by an empty
 * line and a line break for a single line end. Markup is shown as typed (React escapes the text), never interpreted.
 * Nothing for a product without a description.
 */
export function ProductDescription({ description }: { description: string }) {
  const paragraphs = paragraphsOf(description);
  if (paragraphs.length === 0) {
    return null;
  }
  return (
    <section data-testid="product-description" aria-labelledby="product-description-heading" className="mt-4 flex min-w-0 flex-col gap-3 lg:mt-0">
      <h2 id="product-description-heading" className="text-2xl font-semibold tracking-tight">
        Opis
      </h2>
      <div className="flex max-w-[70ch] flex-col gap-4 text-base [overflow-wrap:anywhere]">
        {paragraphs.map((lines, index) => (
          <p key={index}>
            {lines.map((line, lineIndex) => (
              <Fragment key={lineIndex}>
                {lineIndex > 0 && <br />}
                {line}
              </Fragment>
            ))}
          </p>
        ))}
      </div>
    </section>
  );
}

/**
 * The „Parametry” section: a description list (name, value) in the order of the API. Nothing for a product without
 * parameters.
 */
export function ProductParameters({ parameters }: { parameters: ProductParameter[] }) {
  if (parameters.length === 0) {
    return null;
  }
  return (
    <section aria-labelledby="product-parameters-heading" className="mt-4 flex min-w-0 flex-col gap-3 lg:mt-0">
      <h2 id="product-parameters-heading" className="text-2xl font-semibold tracking-tight">
        Parametry
      </h2>
      <dl data-testid="product-parameters" className="max-w-[70ch] text-base">
        {parameters.map((parameter, index) => (
          <div key={index} className="grid grid-cols-1 gap-x-6 gap-y-0.5 border-b border-border py-2 [overflow-wrap:anywhere] sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <dt className="text-muted-foreground">{parameter.name}</dt>
            <dd>{parameter.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
