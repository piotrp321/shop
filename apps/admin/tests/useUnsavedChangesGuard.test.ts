import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import { useUnsavedChangesGuard } from "@/composables/useUnsavedChangesGuard";
import { useAuthStore } from "@/stores/auth";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});

/** A form that is dirty while `dirty` is true; the guard state is exposed to the test. */
async function mountGuard() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const dirty = ref(false);
  let guard!: ReturnType<typeof useUnsavedChangesGuard>;
  const Form = defineComponent({
    setup() {
      guard = useUnsavedChangesGuard(() => dirty.value);
      return () => null;
    },
  });
  const empty = { template: "<div />" };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: "/form", name: "form", component: Form },
      { path: "/other", name: "other", component: empty },
      { path: "/third", name: "third", component: empty },
    ],
  });
  await router.push("/form");
  wrapper = mount({ components: { RouterView }, template: "<RouterView />" }, { global: { plugins: [pinia, router] } });
  await flushPromises();
  return { dirty, router, guard, auth: useAuthStore() };
}

function beforeUnload(): Event {
  const event = new Event("beforeunload", { cancelable: true });
  window.dispatchEvent(event);
  return event;
}

describe("useUnsavedChangesGuard", () => {
  it("lets a clean form go without asking", async () => {
    const { router, guard } = await mountGuard();

    await router.push("/other");

    expect(guard.open.value).toBe(false);
    expect(router.currentRoute.value.name).toBe("other");
  });

  it("holds the navigation of a form with unsaved changes and opens the question", async () => {
    const { dirty, router, guard } = await mountGuard();
    dirty.value = true;

    const navigation = router.push("/other");
    await flushPromises();

    expect(guard.open.value).toBe(true);
    expect(router.currentRoute.value.name).toBe("form");
    guard.stay();
    await navigation;
  });

  it("stays on the form when the staff member stays", async () => {
    const { dirty, router, guard } = await mountGuard();
    dirty.value = true;

    const navigation = router.push("/other");
    await flushPromises();
    guard.stay();
    await navigation;

    expect(guard.open.value).toBe(false);
    expect(router.currentRoute.value.name).toBe("form");
  });

  it("goes on to the page when the staff member leaves", async () => {
    const { dirty, router, guard } = await mountGuard();
    dirty.value = true;

    const navigation = router.push("/other");
    await flushPromises();
    guard.leave();
    await navigation;

    expect(guard.open.value).toBe(false);
    expect(router.currentRoute.value.name).toBe("other");
  });

  it("treats closing the question (Escape) as staying", async () => {
    const { dirty, router, guard } = await mountGuard();
    dirty.value = true;

    const navigation = router.push("/other");
    await flushPromises();
    guard.open.value = false;
    await navigation;

    expect(router.currentRoute.value.name).toBe("form");
  });

  it("leaves when the dialog reports closing in the same tick as the confirmation", async () => {
    const { dirty, router, guard } = await mountGuard();
    dirty.value = true;

    const navigation = router.push("/other");
    await flushPromises();
    // The confirming button closes the dialog and confirms in one click, in either order.
    guard.open.value = false;
    guard.leave();
    await navigation;

    expect(router.currentRoute.value.name).toBe("other");
  });

  it("answers a second navigation by dropping the first question", async () => {
    const { dirty, router, guard } = await mountGuard();
    dirty.value = true;

    const first = router.push("/other");
    await flushPromises();
    const second = router.push("/third");
    await flushPromises();
    guard.leave();
    await Promise.all([first, second]);

    expect(router.currentRoute.value.name).toBe("third");
  });

  it("warns the browser when the tab is closed with unsaved changes only", async () => {
    const { dirty } = await mountGuard();

    expect(beforeUnload().defaultPrevented).toBe(false);
    dirty.value = true;
    expect(beforeUnload().defaultPrevented).toBe(true);
  });

  it("does not hold back a sign-out with the warning about unsaved changes", async () => {
    const { dirty, auth } = await mountGuard();
    dirty.value = true;
    auth.signingOut = true;

    expect(beforeUnload().defaultPrevented).toBe(false);
  });

  it("stops guarding when the form is left", async () => {
    const { dirty, router } = await mountGuard();
    dirty.value = true;
    wrapper?.unmount();
    wrapper = undefined;

    await router.push("/other");

    expect(router.currentRoute.value.name).toBe("other");
    expect(beforeUnload().defaultPrevented).toBe(false);
  });
});
