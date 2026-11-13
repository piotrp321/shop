import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, nextTick, ref } from "vue";
import ConfirmDialog from "@/components/ConfirmDialog.vue";
import { focusAfterAction } from "@/lib/focus";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

/**
 * A card with a heading (`tabindex=-1`), the button that opens the dialog and the dialog. `triggerGone` removes the
 * button while the dialog is open, like an action that changes the set of buttons.
 */
const Host = defineComponent({
  components: { ConfirmDialog },
  props: { destructive: { type: Boolean, default: true } },
  emits: ["confirm"],
  setup(_props, { expose }) {
    const open = ref(false);
    const triggerShown = ref(true);
    const heading = ref<HTMLElement | null>(null);
    expose({ triggerGone: () => (triggerShown.value = false), open });
    return { open, triggerShown, heading };
  },
  template: `
    <section>
      <h2 ref="heading" tabindex="-1">Akcje</h2>
      <button v-if="triggerShown" type="button" data-testid="trigger" @click="open = true">Anuluj zamówienie</button>
      <ConfirmDialog
        v-model:open="open"
        title="Anulować zamówienie R-100002?"
        description="Zamówienie zostanie anulowane, a klient dostanie zwrot."
        confirm-label="Anuluj zamówienie"
        :destructive="destructive"
        :fallback-focus="heading"
        @confirm="$emit('confirm')"
      />
    </section>
  `,
});

async function openDialog(props: { destructive?: boolean } = {}) {
  wrapper = mount(Host, { props, attachTo: document.body });
  const trigger = wrapper.get("[data-testid=trigger]");
  (trigger.element as HTMLButtonElement).focus();
  await trigger.trigger("click");
  await flushPromises();
  return wrapper;
}

function dialog() {
  return document.body.querySelector<HTMLElement>("[role=alertdialog]");
}

function button(name: string) {
  return [...document.body.querySelectorAll<HTMLButtonElement>("[role=alertdialog] button")].find((candidate) => candidate.textContent?.trim() === name);
}

function pressEscape() {
  (document.activeElement ?? document.body).dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
}

describe("ConfirmDialog", () => {
  it("is an alert dialog named by the title with the object and described by the consequence", async () => {
    await openDialog();

    const root = dialog();
    expect(root).not.toBeNull();
    const title = document.getElementById(root?.getAttribute("aria-labelledby") ?? "");
    const description = document.getElementById(root?.getAttribute("aria-describedby") ?? "");
    expect(title?.textContent).toBe("Anulować zamówienie R-100002?");
    expect(description?.textContent).toBe("Zamówienie zostanie anulowane, a klient dostanie zwrot.");
  });

  it("has a red button with the effect for a destructive action and „Wróć”", async () => {
    await openDialog();

    expect(button("Anuluj zamówienie")?.className).toContain("bg-destructive");
    expect(button("Wróć")).toBeDefined();
  });

  it("has a blue button with the effect for an action that is not destructive", async () => {
    await openDialog({ destructive: false });

    expect(button("Anuluj zamówienie")?.className).toContain("bg-interface");
    expect(button("Anuluj zamówienie")?.className).not.toContain("bg-destructive");
  });

  it("puts the focus on „Wróć” when it opens, so Enter does not run the action", async () => {
    await openDialog();

    expect(document.activeElement).toBe(button("Wróć"));
  });

  it("closes on Escape and gives the focus back to the button that opened it", async () => {
    const host = await openDialog();

    pressEscape();
    await flushPromises();

    expect(dialog()).toBeNull();
    expect(document.activeElement).toBe(host.get("[data-testid=trigger]").element);
  });

  it("closes on „Wróć” without confirming and gives the focus back to the button", async () => {
    const host = await openDialog();

    button("Wróć")?.click();
    await flushPromises();

    expect(dialog()).toBeNull();
    expect(host.emitted("confirm")).toBeUndefined();
    expect(document.activeElement).toBe(host.get("[data-testid=trigger]").element);
  });

  it("confirms once on the button with the effect and closes", async () => {
    const host = await openDialog();

    button("Anuluj zamówienie")?.click();
    await flushPromises();

    expect(host.emitted("confirm")).toHaveLength(1);
    expect(dialog()).toBeNull();
  });

  it("gives the focus to the given heading when the button that opened it is gone", async () => {
    const host = await openDialog();
    (host.vm as unknown as { triggerGone(): void }).triggerGone();
    await nextTick();

    pressEscape();
    await flushPromises();

    expect(dialog()).toBeNull();
    expect(document.activeElement).toBe(host.get("h2").element);
  });

  it("gives the focus to the given heading after the action when the button is gone by then", async () => {
    const host = await openDialog();
    (host.vm as unknown as { triggerGone(): void }).triggerGone();
    await nextTick();

    button("Anuluj zamówienie")?.click();
    await flushPromises();

    expect(document.activeElement).toBe(host.get("h2").element);
  });
});

describe("focusAfterAction", () => {
  function setup() {
    document.body.innerHTML = `<h2 id="heading" tabindex="-1">Status</h2><button id="trigger">Opublikuj</button><button id="other">Inny</button>`;
    return {
      heading: document.getElementById("heading") as HTMLElement,
      trigger: document.getElementById("trigger") as HTMLButtonElement,
    };
  }

  it("focuses the trigger when it is still there", () => {
    const { heading, trigger } = setup();

    focusAfterAction(trigger, heading);

    expect(document.activeElement).toBe(trigger);
  });

  it("focuses the heading when the trigger left the page", () => {
    const { heading, trigger } = setup();
    trigger.remove();

    focusAfterAction(trigger, heading);

    expect(document.activeElement).toBe(heading);
  });

  it("focuses the heading when the trigger is disabled and cannot take the focus", () => {
    const { heading, trigger } = setup();
    trigger.disabled = true;

    focusAfterAction(trigger, heading);

    expect(document.activeElement).toBe(heading);
  });

  it("focuses the heading when there was no trigger", () => {
    const { heading } = setup();

    focusAfterAction(null, heading);

    expect(document.activeElement).toBe(heading);
  });

  it("does nothing when there is neither", () => {
    setup();
    (document.getElementById("other") as HTMLElement).focus();

    focusAfterAction(null, null);

    expect(document.activeElement).toBe(document.getElementById("other"));
  });
});
