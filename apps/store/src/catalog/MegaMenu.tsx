"use client";

import Link from "next/link";
import { type FocusEvent, type PointerEvent, useEffect, useId, useRef, useState } from "react";
import type { components } from "@/api/schema";
import { categoryHref } from "./catalog-href";

type CategoryNode = components["schemas"]["CategoryNode"];

const linkClass = "block rounded-md px-2 py-1 text-sm hover:bg-muted";

// The menu shows the department (level 1) and levels 2 and 3; deeper categories are reached from the category page.
const LAST_LEVEL = 3;

/** The category (at `level`, a department is 1) and its descendants down to the last level as nested lists of links. */
function Branch({ category, level, onNavigate }: { category: CategoryNode; level: number; onNavigate: () => void }) {
  return (
    <li>
      <Link href={categoryHref(category.path)} onClick={onNavigate} className={linkClass}>
        {category.name}
      </Link>
      {level < LAST_LEVEL && category.children.length > 0 && (
        <ul className="ml-3 border-l border-border pl-2">
          {category.children.map((child) => (
            <Branch key={child.id} category={child} level={level + 1} onNavigate={onNavigate} />
          ))}
        </ul>
      )}
    </li>
  );
}

/**
 * The menu of the departments (the top categories) from the category tree. Wide screens show a bar of department
 * buttons; the panel of a department (its levels 2 and 3 and „Wszystko z działu”) opens with a click, Enter, Space or
 * hover and closes with Escape (wherever the focus is), a click outside, the focus leaving the menu, a link or another department. Narrow
 * screens show a „Menu” button with the list of all three levels. Every link is in the HTML while the menu is closed
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
  // do not focus a button on click. The focus goes back to the button that opened the panel.
  useEffect(() => {
    if (!anythingOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      const focusTarget = openId !== null ? departmentButtons.current.get(openId) : menuButton.current;
      closeAll();
      focusTarget?.focus();
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
    <nav ref={navRef} aria-label="Działy" onBlur={onBlur} className="relative mx-auto max-w-7xl px-4">
      <div className="hidden md:block">
        <ul className="flex flex-wrap gap-1 py-1">
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
                  className="rounded-md px-3 py-2 text-sm hover:bg-muted aria-expanded:bg-muted"
                >
                  {department.name}
                </button>
                <div id={panelId} hidden={!open} className="absolute inset-x-4 top-full z-20 rounded-md border border-border bg-background p-4 shadow-md">
                  <Link href={categoryHref(department.path)} onClick={closeAll} className="mb-3 inline-block text-sm font-medium underline-offset-4 hover:underline">
                    Wszystko z działu
                  </Link>
                  <ul className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {department.children.map((category) => (
                      <Branch key={category.id} category={category} level={2} onNavigate={closeAll} />
                    ))}
                  </ul>
                </div>
              </li>
            );
          })}
        </ul>
      </div>
      <div className="py-2 md:hidden">
        <button
          type="button"
          ref={menuButton}
          aria-expanded={menuOpen}
          aria-controls={`${id}-list`}
          onClick={() => {
            setOpenId(null);
            setMenuOpen(!menuOpen);
          }}
          className="rounded-md px-3 py-2 text-sm hover:bg-muted aria-expanded:bg-muted"
        >
          Menu
        </button>
        <ul id={`${id}-list`} hidden={!menuOpen} className="mt-2 flex flex-col gap-0.5">
          {categories.map((department) => (
            <Branch key={department.id} category={department} level={1} onNavigate={closeAll} />
          ))}
        </ul>
      </div>
    </nav>
  );
}
