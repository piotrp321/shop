import { computed, type MaybeRefOrGetter, nextTick, onBeforeUnmount, onMounted, ref, toValue, type WritableComputedRef } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";

export interface UnsavedChangesGuard {
  /** Whether the question „Masz niezapisane zmiany” is open; closing it (Escape) is the same as staying. */
  open: WritableComputedRef<boolean>;
  /** Stay on the form: the navigation is cancelled. */
  stay: () => void;
  /** Leave without saving: the navigation goes on. */
  leave: () => void;
}

/**
 * The guard of unsaved changes of a form (docs/features/admin-design/spec.md, „Standard formularza i okien”): a
 * navigation to another route of the panel while `dirty` is true waits for the answer of the staff member, given
 * through `open` (the dialog), `stay` and `leave`; closing the tab is warned by the browser (`beforeunload`), except for a
 * sign-out (`signingOut` of the auth store: holding it back, e.g. the idle one, would keep the session alive).
 *
 * It is a global guard of the router rather than `onBeforeRouteLeave`: after a record is created the same instance
 * moves from the route of the new one to the route of the created one, and a leave guard stays with the record it was
 * registered for.
 */
export function useUnsavedChangesGuard(dirty: MaybeRefOrGetter<boolean>): UnsavedChangesGuard {
  const router = useRouter();
  const auth = useAuthStore();
  const asking = ref(false);
  let pending: ((allow: boolean) => void) | null = null;

  function decide(allow: boolean): void {
    const resolve = pending;
    pending = null;
    asking.value = false;
    resolve?.(allow);
  }

  const removeGuard = router.beforeEach(() => {
    // A newer navigation replaces the question of the older one, which is cancelled, also when the form is clean by now
    // (a save finished meanwhile and moved to the page of the created record).
    decide(false);
    if (!toValue(dirty)) {
      return true;
    }
    return new Promise<boolean>((resolve) => {
      pending = resolve;
      asking.value = true;
    });
  });

  function warnBeforeUnload(event: BeforeUnloadEvent): void {
    if (toValue(dirty) && !auth.signingOut) {
      event.preventDefault();
      event.returnValue = "";
    }
  }

  onMounted(() => window.addEventListener("beforeunload", warnBeforeUnload));
  onBeforeUnmount(() => {
    removeGuard();
    window.removeEventListener("beforeunload", warnBeforeUnload);
    decide(false);
  });

  const open = computed({
    get: () => asking.value,
    set: (visible: boolean) => {
      if (!visible) {
        // The button that confirms closes the dialog and confirms in one click, in either order: the answer to the
        // closing alone is „stay”, given after the click is handled.
        void nextTick(() => decide(false));
      }
    },
  });

  return { open, stay: () => decide(false), leave: () => decide(true) };
}
