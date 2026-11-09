"use client";

import { useEffect, useRef, useState, type KeyboardEvent } from "react";

// A swipe or a smooth scroll sends many `scroll` events; the position counts when they stop for this long.
const SETTLE_MS = 100;

/**
 * A horizontal strip of photos, each as wide as the strip (CSS `scroll-snap`, so the browser does the swiping): the index
 * of the photo it shows once it settles, and the means to scroll it. Scrolling is smooth unless the visitor asked for
 * reduced motion. The strip is the only owner of the position; nothing here changes the address.
 */
export function usePhotoStrip(count: number, initial = 0) {
  const ref = useRef<HTMLDivElement | null>(null);
  const [index, setIndex] = useState(initial);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => () => clearTimeout(timer.current), []);

  function onScroll() {
    clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      const strip = ref.current;
      if (strip && strip.clientWidth > 0) {
        setIndex(Math.min(count - 1, Math.max(0, Math.round(strip.scrollLeft / strip.clientWidth))));
      }
    }, SETTLE_MS);
  }

  function goTo(target: number) {
    const strip = ref.current;
    if (!strip) return;
    const reduced = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
    strip.scrollTo({ left: target * strip.clientWidth, behavior: reduced ? "auto" : "smooth" });
  }

  /** The neighbour of `from`, wrapping around at the ends. */
  const neighbour = (from: number, step: 1 | -1) => (from + step + count) % count;

  /** ← and → move to the neighbour of the photo `from`; returns the target, or null for any other key. */
  function arrowTarget(event: KeyboardEvent, from: number): number | null {
    if (count < 2 || event.altKey || event.ctrlKey || event.metaKey) return null;
    if (event.key === "ArrowRight") return neighbour(from, 1);
    if (event.key === "ArrowLeft") return neighbour(from, -1);
    return null;
  }

  return { ref, index, goTo, onScroll, neighbour, arrowTarget };
}
