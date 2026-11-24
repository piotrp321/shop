<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import type { RouteLocationRaw } from "vue-router";
import { useRouter } from "vue-router";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { READ_FAILED_MESSAGE, RESTORE_LABEL } from "@/composables/formRequests";
import type { FormSafetyState } from "@/composables/useFormSafety";

/**
 * What a form says about its requests (docs/features/admin-ux-fixes/spec.md, „B3” and „B4”), from the state of its
 * `useFormSafety`: the status region („Odświeżamy…”, „Stan na serwerze odpowiada formularzowi.”), the message of a save
 * with an unknown result with its buttons („Sprawdź stan”, „Odblokuj formularz”, a link to the list of a new object), the
 * message of a read that failed or ran out of time with „Spróbuj ponownie”, and „Odrzuć moje zmiany i pokaż stan z
 * serwera” (inside the message of a version that differs, else alone, under the message of the 409 of the form).
 * The focus goes to a message when it appears. Put it in the form, above the cards. `restored` is emitted after the
 * button of the server state replaced the draft: the button is gone, so the form puts the focus where its standard says.
 */
const props = defineProps<{
  safety: FormSafetyState;
  /** The list of the objects, which a new object whose creation is unknown asks the staff member to look at. */
  listTo: RouteLocationRaw;
  /** The name of that link, e.g. „Lista promocji”. */
  listLabel: string;
}>();
const emit = defineEmits<{ restored: [] }>();

const router = useRouter();
const listHref = computed(() => router.resolve(props.listTo).href);

const unknown = computed(() => props.safety.unknown.value);
const readFailed = computed(() => props.safety.readFailed.value);
const restoreOffered = computed(() => props.safety.restoreOffered.value);
const restoreNote = computed(() => props.safety.restoreNote.value);
const readBlocked = computed(() => props.safety.readBlocked.value);
const status = computed(() => props.safety.status.value);

// The label of „Odrzuć moje zmiany i pokaż stan z serwera” is long: it wraps inside the message instead of sticking out of it
// at 320 px (WCAG 1.4.10), and the button grows with its lines.
const RESTORE_BUTTON = "h-auto min-h-8 max-w-full whitespace-normal py-1 text-left";

const ERROR_ALERT = "border-status-danger text-status-danger *:data-[slot=alert-description]:text-status-danger";

const statusRegion = ref<HTMLElement | null>(null);
const unknownAlert = ref<InstanceType<typeof Alert> | null>(null);
const readFailedAlert = ref<InstanceType<typeof Alert> | null>(null);
const restoreNoteAlert = ref<InstanceType<typeof Alert> | null>(null);

// A message that is not focusable by itself (-1: a target for the script, not a stop for the keyboard).
async function focusLater(target: () => HTMLElement | null | undefined): Promise<void> {
  await nextTick();
  const element = target();
  if (element) {
    element.focus({ preventScroll: true });
    element.scrollIntoView({ block: "center" });
  }
}

// Every time a message appears or is said again (also with the same text), not only when its value changes.
watch(
  () => props.safety.focusRequest.value,
  (request) => {
    if (request?.kind === "unknown") {
      void focusLater(() => unknownAlert.value?.$el as HTMLElement | undefined);
    } else if (request?.kind === "restoreNote") {
      void focusLater(() => restoreNoteAlert.value?.$el as HTMLElement | undefined);
    } else if (request?.kind === "readFailed") {
      void focusLater(() => readFailedAlert.value?.$el as HTMLElement | undefined);
    } else if (request?.kind === "matched" || request?.kind === "reading") {
      void focusLater(() => statusRegion.value);
    }
  },
);

// The button that was pressed is gone when the object arrives: the form says where the focus goes (`restored`).
async function restore(): Promise<void> {
  if (await props.safety.restore()) {
    emit("restored");
  }
}

async function retryRead(): Promise<void> {
  if (await props.safety.retryRead()) {
    emit("restored");
  }
}
</script>

<template>
  <div class="contents">
    <!-- A status region that is there before its text: what appears in it is announced. -->
    <div
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="safety-status"
      class="text-sm empty:hidden"
    >
      {{ status }}
    </div>

    <Alert
      v-if="unknown"
      ref="unknownAlert"
      tabindex="-1"
      data-testid="unknown-result"
      data-form-error
      variant="destructive"
      :class="ERROR_ALERT"
    >
      <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{{ unknown.text }}</span>
        <Button
          v-if="!unknown.created"
          type="button"
          variant="outline"
          size="sm"
          data-testid="check-state"
          :disabled="readBlocked"
          @click="safety.check()"
        >
          Sprawdź stan
        </Button>
        <Button
          v-else-if="unknown.pending"
          type="button"
          variant="outline"
          size="sm"
          data-testid="unblock-form"
          @click="safety.unblock()"
        >
          Odblokuj formularz
        </Button>
        <a
          v-else
          :href="listHref"
          target="_blank"
          rel="noopener"
          data-testid="list-link"
          class="text-sm font-semibold underline"
        >
          {{ listLabel }}<span class="sr-only"> (otwiera się w nowej karcie)</span>
        </a>
        <Button
          v-if="restoreOffered"
          type="button"
          variant="outline"
          size="sm"
          data-testid="restore-state"
          :class="RESTORE_BUTTON"
          :disabled="readBlocked"
          @click="restore"
        >
          {{ RESTORE_LABEL }}
        </Button>
      </AlertDescription>
    </Alert>
    <Alert
      v-else-if="restoreNote"
      ref="restoreNoteAlert"
      tabindex="-1"
      data-testid="restore-note"
      variant="destructive"
      :class="ERROR_ALERT"
    >
      <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{{ restoreNote }}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          data-testid="restore-state"
          :class="RESTORE_BUTTON"
          :disabled="readBlocked"
          @click="restore"
        >
          {{ RESTORE_LABEL }}
        </Button>
      </AlertDescription>
    </Alert>
    <div v-else-if="restoreOffered">
      <Button
        type="button"
        variant="outline"
        size="sm"
        data-testid="restore-state"
        :class="RESTORE_BUTTON"
        :disabled="readBlocked"
        @click="restore"
      >
        {{ RESTORE_LABEL }}
      </Button>
    </div>

    <Alert
      v-if="readFailed"
      ref="readFailedAlert"
      tabindex="-1"
      data-testid="read-failed"
      variant="destructive"
      :class="ERROR_ALERT"
    >
      <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{{ READ_FAILED_MESSAGE }}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          data-testid="retry-read"
          :disabled="readBlocked"
          @click="retryRead"
        >
          Spróbuj ponownie
        </Button>
      </AlertDescription>
    </Alert>
  </div>
</template>
