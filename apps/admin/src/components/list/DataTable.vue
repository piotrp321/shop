<script lang="ts">
export interface ListColumn<Row> {
  key: string;
  header: string;
  /**
   * The width below which the column is hidden: `lg` is 1024 px, `md` 900 px. Its value then shows as a line of the main
   * cell, with the header as the label, so no information is lost on a narrow screen.
   */
  hideBelow?: "lg" | "md";
  /** The text of the line in the main cell; required for a column that can be hidden. */
  text?: (row: Row) => string;
  align?: "right";
  /** The column of the main cell (the first one when none says so): it carries the lines of the hidden columns. */
  main?: boolean;
}
</script>

<script setup lang="ts" generic="Row">
import { useResizeObserver } from "@vueuse/core";
import { computed, nextTick, onMounted, ref } from "vue";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { focusAfterAction } from "@/lib/focus";

/**
 * The table of a list on the `Table` of shadcn-vue (docs/features/admin-design/spec.md, „Standard listy”). The cells are
 * the slots `cell-<key>` (`row`), the empty list is the slot `empty`; the main cell may also have a `lead` (a thumbnail)
 * before its text, and under the text it shows the lines of the columns that are hidden. It also shows what the list is in: a skeleton of
 * rows for the first load, the table marked `aria-busy` for the later ones, and the error (`role="alert"`) with
 * „Spróbuj ponownie” (none for a 403). The error of a list that never loaded stands alone, without a table; the error
 * of a neighbouring page stands over the page that is shown. A retry ends with the focus where it can be: on
 * the button while it is still there, otherwise on the alert, the table or the empty state, never on the body (#337).
 *
 * A table wider than its card scrolls in its own region (`role="region"` named by the list, `tabindex="0"`), with a
 * shadow at the edge that has more behind it (WCAG 1.4.10); the page itself never scrolls sideways.
 */
const props = withDefaults(
  defineProps<{
    /** The name of the scrolling region, e.g. „Lista produktów”. */
    label: string;
    columns: ListColumn<Row>[];
    rows: Row[];
    rowKey: (row: Row) => string;
    rowTestid?: string;
    loading: boolean;
    loaded: boolean;
    error: string | null;
    forbidden: boolean;
    retry: () => Promise<void>;
  }>(),
  { rowTestid: "row" },
);

// The hide classes are written out in full so that Tailwind finds them.
const HIDDEN_CELL = { lg: "hidden lg:table-cell", md: "hidden min-[900px]:table-cell" } as const;
const HIDDEN_LINE = { lg: "lg:hidden", md: "min-[900px]:hidden" } as const;

const mainKey = computed(() => (props.columns.find((column) => column.main) ?? props.columns[0])?.key);
const hiddenColumns = computed(() => props.columns.filter((column) => column.hideBelow !== undefined));

const cellClass = (column: ListColumn<Row>) =>
  cn("px-3 py-3", column.align === "right" && "text-right", column.hideBelow && HIDDEN_CELL[column.hideBelow], column.key === mainKey.value && "whitespace-normal");

const alertElement = ref<HTMLElement | null>(null);
const retryButton = ref<InstanceType<typeof Button> | null>(null);
const region = ref<HTMLElement | null>(null);
const table = ref<HTMLElement | null>(null);
const emptyRegion = ref<HTMLElement | null>(null);

async function retryAndRefocus() {
  const trigger = (retryButton.value?.$el as HTMLElement | undefined) ?? null;
  await props.retry();
  await nextTick();
  focusAfterAction(trigger, alertElement.value ?? region.value ?? emptyRegion.value);
}

// Which edges of the scrolling region have more behind them.
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
useResizeObserver(table, measure);
onMounted(measure);
</script>

<template>
  <div class="flex flex-col">
    <div
      v-if="error"
      ref="alertElement"
      role="alert"
      tabindex="-1"
      data-testid="list-error"
      class="m-4 flex flex-wrap items-center gap-3 rounded-lg border border-destructive px-4 py-3 text-sm text-destructive"
    >
      <span>{{ error }}</span>
      <Button
        v-if="!forbidden"
        ref="retryButton"
        type="button"
        variant="outline"
        size="sm"
        data-testid="retry"
        @click="retryAndRefocus"
      >
        Spróbuj ponownie
      </Button>
    </div>

    <template v-if="loaded">
      <div
        v-if="rows.length === 0"
        ref="emptyRegion"
        tabindex="-1"
        data-testid="empty-region"
        class="focus:outline-none"
      >
        <slot name="empty" />
      </div>
      <div
        v-else
        class="relative"
      >
        <div
          ref="region"
          role="region"
          :aria-label="label"
          tabindex="0"
          :aria-busy="loading"
          class="overflow-x-auto"
          @scroll="measure"
        >
          <table
            ref="table"
            class="w-full caption-bottom text-sm"
          >
            <TableHeader>
              <TableRow class="bg-muted hover:bg-muted">
                <TableHead
                  v-for="column in columns"
                  :key="column.key"
                  scope="col"
                  :class="
                    cn(
                      'px-3 text-xs font-bold text-heading uppercase',
                      column.align === 'right' && 'text-right',
                      column.hideBelow && HIDDEN_CELL[column.hideBelow],
                    )
                  "
                >
                  {{ column.header }}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow
                v-for="row in rows"
                :key="rowKey(row)"
                :data-testid="rowTestid"
              >
                <TableCell
                  v-for="column in columns"
                  :key="column.key"
                  :class="cellClass(column)"
                >
                  <div
                    v-if="column.key === mainKey"
                    class="flex items-center gap-3"
                  >
                    <slot
                      name="lead"
                      :row="row"
                    />
                    <div class="min-w-0 flex-1">
                      <slot
                        :name="`cell-${column.key}`"
                        :row="row"
                      />
                      <div
                        v-if="hiddenColumns.length > 0"
                        class="mt-1 flex flex-wrap gap-x-3 text-xs text-muted-foreground"
                      >
                        <span
                          v-for="hidden in hiddenColumns"
                          :key="hidden.key"
                          :data-testid="`line-${hidden.key}`"
                          :class="HIDDEN_LINE[hidden.hideBelow!]"
                        >{{ hidden.header }}: {{ hidden.text?.(row) }}</span>
                      </div>
                    </div>
                  </div>
                  <slot
                    v-else
                    :name="`cell-${column.key}`"
                    :row="row"
                  />
                </TableCell>
              </TableRow>
            </TableBody>
          </table>
        </div>
        <div
          v-show="edges.start"
          aria-hidden="true"
          class="pointer-events-none absolute inset-y-0 left-0 w-px shadow-md"
        />
        <div
          v-show="edges.end"
          aria-hidden="true"
          class="pointer-events-none absolute inset-y-0 right-0 w-px shadow-md"
        />
      </div>
    </template>
    <div
      v-else-if="loading && !error"
      data-testid="list-loading"
      class="flex flex-col gap-2 p-4"
    >
      <Skeleton
        v-for="index in 5"
        :key="index"
        class="h-12"
      />
    </div>
  </div>
</template>
