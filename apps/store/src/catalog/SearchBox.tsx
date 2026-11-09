"use client";

import Form from "next/form";
import { usePathname, useSearchParams } from "next/navigation";
import { SearchIcon } from "lucide-react";
import { type FocusEvent, type KeyboardEvent, Suspense, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DEPARTMENT_PANEL_OPENED } from "./header-panels";

const FIELD_ID = "header-search";
const FORM_ID = "header-search-form";
const TOGGLE_ID = "header-search-toggle";

// 1024 to 1279 px: the row of the header has no room for the field (the departments, the cart and the account of a customer
// take it), so the form is a panel under the header that a button opens. Without JavaScript the button cannot open it, so
// the form is then a row of its own under the first one, as on a phone.
const COMPACT_CSS = `@media (min-width: 1024px) and (max-width: 1279.98px) { #${FORM_ID} { display: flex !important; position: static !important; grid-area: 2 / 1 / 3 / -1 !important; padding: 0 !important; border: 0 !important; background: transparent !important; } #${TOGGLE_ID} { display: none !important; } }`;

// A submit from the open panel that goes to `/szukaj` from another page replaces the page of the `@search` slot, so the box
// is mounted again and the focus that `submit` gave the button is lost with the old button. The marker outlives the old box:
// the new one takes it on mounting and gives the focus to its own button. A submit that mounts no new box (a search from
// `/szukaj` to `/szukaj` keeps it) leaves the marker set, so the box drops it at the next change of the address: a box that
// is mounted by a later navigation (e.g. back to a page before `/szukaj`) must not take the focus.
let focusToggleOnMount = false;

/**
 * Puts the phrase of `/szukaj` into the field, which is otherwise uncontrolled. It renders nothing, so the field is one
 * element from the page HTML on: what the user typed before the hydration stays. The field is written only when the
 * phrase changes (a new search, leaving `/szukaj`), not when other parameters do, and on the first run only when empty.
 */
function CurrentPhrase() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const phrase = pathname === "/szukaj" ? (searchParams.get("q") ?? "") : "";
  const address = `${pathname}?${searchParams.toString()}`;
  // A box that stays across the next change of the address is not the one the marker waits for. `null` until the first run.
  const firstAddress = useRef<string | null>(null);
  useEffect(() => {
    if (firstAddress.current !== null && firstAddress.current !== address) focusToggleOnMount = false;
    firstAddress.current = address;
  }, [address]);
  // `null` until the first run.
  const applied = useRef<string | null>(null);
  useEffect(() => {
    const input = document.getElementById(FIELD_ID) as HTMLInputElement | null;
    if (!input || applied.current === phrase) return;
    if (applied.current !== null || input.value === "") input.value = phrase;
    applied.current = phrase;
  }, [phrase]);
  return null;
}

/**
 * The search box of the header: a GET form to `/szukaj`. `phrase` is the phrase of `/szukaj` in the server HTML (the
 * `@search` slot of the root layout); elsewhere the HTML is the same for everybody (product pages are ISR) and the field
 * is empty. After that the browser keeps the field in step with the address. The form and the field are outside
 * `Suspense`, which only holds the part that reads the address.
 *
 * Renders two children of the header grid, in the order they have on the screen: a button (`aria-expanded`) and the form.
 * The button is only there from 1024 to 1279 px, where the form is the panel it opens (focus goes to the field; Escape, a
 * submit, a click outside or the focus leaving close it, and Escape and a submit give the focus back to the button, also
 * when the submit mounts the box again). Below 1024 px the
 * form is the second row of the header and from 1280 px the last cell of the one row; the button is then `display: none`.
 * The form is in the HTML once, so there is no second copy to skip.
 */
export function SearchBox({ phrase }: { phrase?: string }) {
  const [open, setOpen] = useState(false);
  const toggle = useRef<HTMLButtonElement>(null);
  const form = useRef<HTMLFormElement>(null);
  const focusField = useRef(false);

  // The box was mounted by the submit of the one before it: its button gets the focus.
  useEffect(() => {
    if (!focusToggleOnMount) return;
    focusToggleOnMount = false;
    toggle.current?.focus();
  }, []);

  useEffect(() => {
    if (open && focusField.current) document.getElementById(FIELD_ID)?.focus();
    focusField.current = false;
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const closeOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      if (!form.current?.contains(target) && !toggle.current?.contains(target)) setOpen(false);
    };
    document.addEventListener("mousedown", closeOutside);
    return () => document.removeEventListener("mousedown", closeOutside);
  }, [open]);

  // The panel of a department opens in the same place (by hover too, which moves neither the focus nor the mouse down).
  useEffect(() => {
    if (!open) return;
    const close = () => setOpen(false);
    document.addEventListener(DEPARTMENT_PANEL_OPENED, close);
    return () => document.removeEventListener(DEPARTMENT_PANEL_OPENED, close);
  }, [open]);

  // A submit from the open panel hides the form with the focused field, so the focus goes to the button, as on Escape. When
  // the navigation mounts the box again, the new box does it (see `focusToggleOnMount`).
  const submit = () => {
    if (!open) return;
    setOpen(false);
    toggle.current?.focus();
    focusToggleOnMount = true;
  };

  const closeOnEscape = (event: KeyboardEvent) => {
    if (event.key !== "Escape" || !open) return;
    setOpen(false);
    toggle.current?.focus();
  };

  // Only a move of the focus to an element outside the button and the form closes the panel.
  const closeOnLeave = (event: FocusEvent) => {
    const next = event.relatedTarget;
    if (next instanceof Node && !form.current?.contains(next) && !toggle.current?.contains(next)) setOpen(false);
  };

  return (
    <>
      <Button
        ref={toggle}
        id={TOGGLE_ID}
        type="button"
        variant="outline"
        size="icon"
        aria-expanded={open}
        aria-controls={FORM_ID}
        className="hidden [grid-area:search] lg:max-xl:ml-2 lg:max-xl:inline-flex"
        onClick={() => {
          focusField.current = !open;
          setOpen(!open);
        }}
        onKeyDown={closeOnEscape}
        onBlur={closeOnLeave}
      >
        <SearchIcon aria-hidden />
        <span className="sr-only">Wyszukiwarka</span>
      </Button>
      <Form
        ref={form}
        id={FORM_ID}
        action="/szukaj"
        role="search"
        data-state={open ? "open" : "closed"}
        className={
          "flex min-w-0 items-center gap-2 [grid-area:search] " +
          "lg:max-xl:absolute lg:max-xl:inset-x-0 lg:max-xl:top-full lg:max-xl:z-20 lg:max-xl:border-b lg:max-xl:border-border lg:max-xl:bg-card lg:max-xl:px-gutter lg:max-xl:py-3 lg:max-xl:data-[state=closed]:hidden"
        }
        onSubmit={submit}
        onKeyDown={closeOnEscape}
        onBlur={closeOnLeave}
      >
        <label htmlFor={FIELD_ID} className="sr-only">
          Szukaj produktów
        </label>
        <Input id={FIELD_ID} type="search" name="q" defaultValue={phrase} placeholder="Szukaj produktów" className="flex-1" />
        <Button type="submit" variant="outline" size="icon">
          <SearchIcon aria-hidden />
          <span className="sr-only">Szukaj</span>
        </Button>
        <noscript>
          <style>{COMPACT_CSS}</style>
        </noscript>
        <Suspense>
          <CurrentPhrase />
        </Suspense>
      </Form>
    </>
  );
}
