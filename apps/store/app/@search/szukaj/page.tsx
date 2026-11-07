import { SearchBox } from "@/catalog/SearchBox";

/**
 * The search box on `/szukaj`, a slot of the root layout: a layout gets no `searchParams`, a slot page does. `/szukaj` is
 * rendered per request anyway, so the field has the phrase in the server HTML and works without JavaScript.
 */
export default async function SearchSlot({ searchParams }: PageProps<"/szukaj">) {
  const { q } = await searchParams;
  // The first value of a repeated `q`, like `useSearchParams().get("q")` in the box.
  return <SearchBox phrase={Array.isArray(q) ? q[0] : q} />;
}
