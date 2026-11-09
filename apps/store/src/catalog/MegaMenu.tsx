"use client";

import { ArrowRightIcon, MenuIcon } from "lucide-react";
import Link from "next/link";
import { type FocusEvent, type PointerEvent, useEffect, useId, useRef, useState } from "react";
import type { components } from "@/api/schema";
import { categoryHref } from "./catalog-href";

type CategoryNode = components["schemas"]["CategoryNode"];

// The links of the list of the narrow screen (`lg:hidden`): 44 px tall rows (36 px for a deeper level).
const linkClass = "block min-h-9 px-1 py-2 text-ui hover:underline";

// The menu shows the department (level 1) and levels 2 and 3; deeper categories are reached from the category page.
const LAST_LEVEL = 3;

/** The category (at `level`, a department is 1) and its descendants down to the last level as nested lists of links. */
function Branch({ category, level, onNavigate }: { category: CategoryNode; level: number; onNavigate: () => void }) {
  return (
    <li className={level === 1 ? "border-b border-border py-1 last:border-b-0" : undefined}>
      <Link href={categoryHref(category.path)} onClick={onNavigate} className={level === 1 ? `${linkClass} font-semibold` : linkClass}>
        {category.name}
      </Link>
      {level < LAST_LEVEL && category.children.length > 0 && (
        <ul className="ml-3 border-l border-input pl-2.5">
          {category.children.map((child) => (
            <Branch key={child.id} category={child} level={level + 1} onNavigate={onNavigate} />
          ))}
        </ul>
      )}
    </li>
  );
}

/** A column of the wide panel: the category of level 2 as a heading link and its level 3 links under it. */
function MegaColumn({ category, onNavigate }: { category: CategoryNode; onNavigate: () => void }) {
  return (
    <li>
      <Link href={categoryHref(category.path)} onClick={onNavigate} className="mb-1.5 inline-block py-1 text-lg font-semibold underline-offset-4 hover:underline">
        {category.name}
      </Link>
      {category.children.length > 0 && (
        <ul>
          {category.children.map((child) => (
            <li key={child.id}>
              <Link href={categoryHref(child.path)} onClick={onNavigate} className="inline-block py-1.5 text-ui underline-offset-4 hover:underline">
                {child.name}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </li>
  );
}

/**
 * The menu of the departments (the top categories) from the category tree. From `lg` the menu is a bar of department
 * buttons; the panel of a department (its levels 2 and 3 and „Wszystko z działu”) opens with a click, Enter, Space or
 * hover and closes with Escape (wherever the focus is), a click outside, the focus leaving the menu, a link or another department. Below it a „Menu” button with the list of all three levels. Every link is in the HTML while the menu is closed
 * (the panels are `hidden`), so crawlers see the whole tree; JavaScript only opens and closes them.
 */
export function MegaMenu({ categories }: { categories: CategoryNode[] }) {
  const id = useId();
  const navRef = useRef<HTMLElement>(null);
  const departmentButtons = useRef(new Map<string, HTMLButtonElement>());
  const menuButton = useRef<HTMLButtonElement>(null);
  // A department opened by hover stays open for the click that follows, so the click does not close it at once.
  const openedByHover = useRef(false);
  const [openId, setOpenId] = useState<string | null>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  const anythingOpen = openId !== null || menuOpen;

  const closeAll = () => {
    openedByHover.current = false;
    setOpenId(null);
    setMenuOpen(false);
  };

  useEffect(() => {
    if (!anythingOpen) return;
    const closeOutside = (event: MouseEvent) => {
      if (!navRef.current?.contains(event.target as Node)) closeAll();
    };
    document.addEventListener("mousedown", closeOutside);
    return () => document.removeEventListener("mousedown", closeOutside);
  }, [anythingOpen]);

  // Escape closes the menu wherever the focus is: a panel opened by hover leaves it on the body, and Safari and Firefox
  // do not focus a button on click. The focus goes back to the button that opened the panel only when it was in the menu
  // or nowhere; a field elsewhere on the page keeps it. An Escape that something else already handled is left alone.
  useEffect(() => {
    if (!anythingOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== "Escape" || event.defaultPrevented) return;
      const active = document.activeElement;
      const focusBelongsToMenu = active === null || active === document.body || !!navRef.current?.contains(active);
      const focusTarget = openId !== null ? departmentButtons.current.get(openId) : menuButton.current;
      closeAll();
      if (focusBelongsToMenu) focusTarget?.focus();
    };
    document.addEventListener("keydown", closeOnEscape);
    return () => document.removeEventListener("keydown", closeOnEscape);
  }, [anythingOpen, openId]);

  // Only a move of the focus to an element outside the menu closes it: a null target (a click on a non-focusable area,
  // a browser that does not focus buttons on click) is left to the click-outside handler.
  const onBlur = (event: FocusEvent) => {
    const next = event.relatedTarget;
    if (next instanceof Node && !navRef.current?.contains(next)) closeAll();
  };

  const clickDepartment = (departmentId: string) => {
    setMenuOpen(false);
    if (openedByHover.current && openId === departmentId) {
      openedByHover.current = false;
      return;
    }
    setOpenId(openId === departmentId ? null : departmentId);
  };

  const hoverDepartment = (event: PointerEvent, departmentId: string) => {
    if (event.pointerType !== "mouse") return;
    setMenuOpen(false);
    if (openId !== departmentId) {
      openedByHover.current = true;
      setOpenId(departmentId);
    }
  };

  return (
    <nav ref={navRef} aria-label="Działy" onBlur={onBlur} className="min-w-0 [grid-area:nav]">
      {/* From `lg`: the bar of departments; the panel of the open one spans the whole header (it is positioned
          against the `header`, so this `nav` is not positioned). */}
      <div className="hidden lg:block">
        <ul className="flex flex-wrap">
          {categories.map((department) => {
            const panelId = `${id}-panel-${department.id}`;
            const open = openId === department.id;
            return (
              <li key={department.id}>
                <button
                  type="button"
                  ref={(element) => {
                    if (element) departmentButtons.current.set(department.id, element);
                    return () => {
                      departmentButtons.current.delete(department.id);
                    };
                  }}
                  aria-expanded={open}
                  aria-controls={panelId}
                  onClick={() => clickDepartment(department.id)}
                  onPointerEnter={(event) => hoverDepartment(event, department.id)}
                  className="relative h-11 rounded-md px-3 text-ui font-medium whitespace-nowrap hover:bg-muted aria-expanded:bg-card aria-expanded:after:absolute aria-expanded:after:inset-x-3 aria-expanded:after:bottom-0.5 aria-expanded:after:h-[3px] aria-expanded:after:bg-primary"
                >
                  {department.name}
                </button>
                <div
                  id={panelId}
                  hidden={!open}
                  className="absolute inset-x-0 top-full z-20 border-y border-border bg-card pt-9 pb-11"
                >
                  <div className="mx-auto max-w-content px-gutter">
                    <Link
                      href={categoryHref(department.path)}
                      onClick={closeAll}
                      className="mb-5 inline-flex items-center gap-2 py-1 font-semibold text-link underline-offset-4 hover:underline"
                    >
                      Wszystko z działu
                      <ArrowRightIcon aria-hidden className="size-4" />
                    </Link>
                    <ul className="grid grid-cols-4 gap-x-12 gap-y-7">
                      {department.children.map((category) => (
                        <MegaColumn key={category.id} category={category} onNavigate={closeAll} />
                      ))}
                    </ul>
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      </div>
      {/* Below `lg`: the „Menu” button in the first row of the header; the list of all three levels opens as a panel
          under the header. */}
      <div className="lg:hidden">
        <button
          type="button"
          ref={menuButton}
          aria-expanded={menuOpen}
          aria-controls={`${id}-list`}
          onClick={() => {
            setOpenId(null);
            setMenuOpen(!menuOpen);
          }}
          className="inline-flex h-11 items-center gap-2.5 rounded-md border border-input bg-card px-4 font-semibold hover:bg-muted aria-expanded:bg-muted"
        >
          <MenuIcon aria-hidden className="size-5" />
          Menu
        </button>
        <ul
          id={`${id}-list`}
          hidden={!menuOpen}
          className="absolute inset-x-gutter top-full z-40 max-h-[460px] overflow-auto rounded-md border border-input bg-card px-3 py-2"
        >
          {categories.map((department) => (
            <Branch key={department.id} category={department} level={1} onNavigate={closeAll} />
          ))}
        </ul>
      </div>
    </nav>
  );
}
