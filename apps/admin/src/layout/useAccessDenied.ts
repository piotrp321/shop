import { onMounted, ref } from "vue";

/**
 * Whether the route guard sent the staff member here because the page they asked for is not for their role
 * (`history.state.denied`). The mark is read once and cleared in the same history entry, because the browser keeps
 * `history.state` over a reload and the message must not come back then.
 */
export function useAccessDenied() {
  const denied = ref(window.history.state?.denied === true);

  onMounted(() => {
    if (denied.value) {
      window.history.replaceState({ ...window.history.state, denied: false }, "");
    }
  });

  return denied;
}
