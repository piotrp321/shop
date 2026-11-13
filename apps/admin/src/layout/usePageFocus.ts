import { nextTick, onBeforeUnmount, type Ref } from "vue";
import { START_LOCATION, useRouter } from "vue-router";

/** How long the shell waits for the H1 of a page that loads its data first; after that the focus stays on <main>. */
const HEADING_WAIT_MS = 10_000;

/**
 * The focus after a change of page (docs/features/admin-design/spec.md, „Dostępność” and „Standard formularza i okien”):
 * it is never left on <body>, and an action that moved to another page leaves it on the H1 of that page.
 *
 * - A change of page is a change of the path of the route (another screen, another record). Only the query or the hash
 *   (filters, pager and search of a list, `router.replace` of `useListUrlState`) is the same page and moves nothing; so
 *   does a navigation that did not happen (a guard that stayed, a duplicate) and the first load of the panel (the layout
 *   mounts after it, and the first Tab goes to „Przejdź do treści”).
 * - The focus is taken only when it is free: on <body> (the link that had it is gone with the old page) or on <main>.
 *   Whatever has it otherwise keeps it: a view that moved it itself (a form that focuses its result and then moves to the
 *   route of the record it created, which keeps the same instance of the view), a field the staff member is in, the topbar
 *   or the menu they used. An element that has the focus and still exists is a trigger that stays.
 * - The H1 may appear only after the data has loaded: until then the focus goes to <main> (`tabindex="-1"`), and when
 *   the H1 appears and the focus is still free it moves there; a focus moved by the staff member meanwhile is not taken.
 * - The H1 of a screen that is not migrated yet has no `tabindex`: the shell gives it `-1`.
 *
 * The drawer is the exception: its item is gone as the drawer closes, and the focus would return to „Menu”. After
 * `choseInDrawer`, the next change of page takes the focus whatever has it, and `takenByChoice` tells the drawer that the
 * focus is already where it belongs.
 */
export function usePageFocus(main: Ref<HTMLElement | null>) {
  const router = useRouter();
  let choice: "none" | "pending" | "taken" = "none";
  let cancelCurrent: (() => void) | null = null;

  function isFree(area: HTMLElement): boolean {
    const active = document.activeElement;
    return active === null || active === document.body || active === area;
  }

  function focusHeading(area: HTMLElement): boolean {
    const heading = area.querySelector<HTMLElement>("h1");
    if (heading === null) {
      return false;
    }
    if (!heading.hasAttribute("tabindex")) {
      heading.setAttribute("tabindex", "-1");
    }
    heading.focus();
    return document.activeElement === heading;
  }

  function focusPage(force: boolean) {
    cancelCurrent?.();
    let live = true;
    let observer: MutationObserver | null = null;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const finish = () => {
      live = false;
      observer?.disconnect();
      clearTimeout(timer);
      if (cancelCurrent === finish) {
        cancelCurrent = null;
      }
    };
    cancelCurrent = finish;

    // The new view is rendered after the next tick; the focus of a link of the old page is on <body> by then.
    void nextTick().then(() => {
      const area = main.value;
      if (!live || area === null) {
        finish();
        return;
      }
      if (!force && !isFree(area)) {
        finish();
        return;
      }
      if (force) {
        choice = "taken";
      }
      if (focusHeading(area)) {
        finish();
        return;
      }
      area.focus();
      observer = new MutationObserver(() => {
        if (!isFree(area) || focusHeading(area)) {
          finish();
        }
      });
      observer.observe(area, { childList: true, subtree: true });
      timer = setTimeout(finish, HEADING_WAIT_MS);
    });
  }

  const removeHook = router.afterEach((to, from, failure) => {
    if (failure || from === START_LOCATION || to.path === from.path) {
      // The item chosen in the drawer led nowhere new: the focus returns to „Menu”.
      if (choice === "pending") {
        choice = "none";
      }
      return;
    }
    focusPage(choice === "pending");
  });

  onBeforeUnmount(() => {
    removeHook();
    cancelCurrent?.();
  });

  return {
    /** An item of the drawer was chosen: the next change of page takes the focus. */
    choseInDrawer: () => {
      choice = "pending";
    },
    /** The drawer was opened: an earlier choice no longer counts. */
    resetChoice: () => {
      choice = "none";
    },
    /** Whether the page the chosen item opened has already taken the focus; consumes the answer. */
    takenByChoice: () => {
      const taken = choice === "taken";
      if (taken) {
        choice = "none";
      }
      return taken;
    },
  };
}
