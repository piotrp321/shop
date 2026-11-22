import { useResizeObserver } from "@vueuse/core";
import { onMounted, type Ref, ref } from "vue";

/**
 * Which edges of a horizontally scrolling region have more behind them (WCAG 1.4.10: a region that scrolls says so at
 * the edge). `region` is the element with `overflow-x-auto`, `table` its content; the edges are measured when the
 * region scrolls and when either of them changes its size. Shown by `ScrollEdgeShadows`; the owner of the region calls
 * `measure` from its `@scroll`.
 */
export function useScrollEdges(region: Ref<HTMLElement | null>, content: Ref<HTMLElement | null>) {
  const edges = ref({ start: false, end: false });

  function measure() {
    const element = region.value;
    if (element === null) {
      edges.value = { start: false, end: false };
      return;
    }
    edges.value = { start: element.scrollLeft > 0, end: element.scrollLeft + element.clientWidth < element.scrollWidth - 1 };
  }

  useResizeObserver(region, measure);
  useResizeObserver(content, measure);
  onMounted(measure);

  return { edges, measure };
}
