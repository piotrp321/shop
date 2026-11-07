import { SearchBox } from "@/catalog/SearchBox";

/** The search box of every page but `/szukaj`: an empty field, so the page HTML does not depend on the address (ISR). */
export default function DefaultSearch() {
  return <SearchBox />;
}
