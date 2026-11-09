"use client";

import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";

// A swipe or a smooth scroll sends many `scroll` events; the position counts when they stop for this long.
const SETTLE_MS = 100;
// A smooth scroll started by a button or a key counts as the position for this long, so quick clicks add up.
const PENDING_MS = 600;

/**
 * A horizontal strip of photos, each as wide as the strip (CSS `scroll-snap`, so the browser does the swiping): the index
 * of the photo it shows once it settles, and the means to scroll it. Scrolling is smooth unless the visitor asked for
 * reduced motion. The strip is the only owner of the position; nothing here changes the address.
 *
 * `ref` is a callback ref: when the strip appears (after hydration, or when the enlargement opens in a portal) the hook
 * scrolls it to `initial` (only when it is not 0) and reads the position the strip really has, so a strip the visitor
 * scrolled before the page was hydrated shows the right photo.
 */
export function usePhotoStrip(count: number, initial = 0) {
  const element = useRef<HTMLDivElement | null>(null);
  const [index, setIndex] = useState(initial);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const pending = useRef<{ target: number; timer: ReturnType<typeof setTimeout> } | null>(null);

  useEffect(
    () => () => {
      clearTimeout(timer.current);
      clearTimeout(pending.current?.timer);
    },
    [],
  );

  /** The photo the strip is on now, read from its position. */
  const read = useCallback((): number | null => {
    const strip = element.current;
    if (strip === null || strip.clientWidth === 0) return null;
    return Math.min(count - 1, Math.max(0, Math.round(strip.scrollLeft / strip.clientWidth)));
  }, [count]);

  const ref = useCallback(
    (strip: HTMLDivElement | null) => {
      element.current = strip;
      if (strip === null) return;
      if (initial > 0) strip.scrollLeft = initial * strip.clientWidth;
      const position = read();
      if (position !== null) setIndex(position);
    },
    [initial, read],
  );

  function onScroll() {
    clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      const position = read();
      if (position !== null) setIndex(position);
    }, SETTLE_MS);
  }

  function goTo(target: number) {
    const strip = element.current;
    if (!strip) return;
    const reduced = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
    clearTimeout(pending.current?.timer);
    pending.current = { target, timer: setTimeout(() => (pending.current = null), PENDING_MS) };
    strip.scrollTo({ left: target * strip.clientWidth, behavior: reduced ? "auto" : "smooth" });
  }

  /** The photo to go on from: the target of a scroll still running, else the one the strip is on (not the debounced `index`). */
  const current = () => pending.current?.target ?? read() ?? index;

  /** The neighbour of `from`, wrapping around at the ends. */
  const neighbour = (from: number, step: 1 | -1) => (from + step + count) % count;

  /** ← and → move to the neighbour of the photo `from`; returns the target, or null for any other key. */
  function arrowTarget(event: KeyboardEvent, from: number): number | null {
    if (count < 2 || event.altKey || event.ctrlKey || event.metaKey) return null;
    if (event.key === "ArrowRight") return neighbour(from, 1);
    if (event.key === "ArrowLeft") return neighbour(from, -1);
    return null;
  }

  return { ref, index, goTo, onScroll, current, neighbour, arrowTarget };
}
