import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import UnsavedChangesDialog from "@/components/UnsavedChangesDialog.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

function button(name: string) {
  return [...document.body.querySelectorAll<HTMLButtonElement>("[role=alertdialog] button")].find((candidate) => candidate.textContent?.trim() === name);
}

async function open() {
  wrapper = mount(UnsavedChangesDialog, { props: { open: true }, attachTo: document.body });
  await flushPromises();
}

describe("UnsavedChangesDialog", () => {
  it("asks „Masz niezapisane zmiany” and puts the focus on „Zostań”", async () => {
    await open();

    expect(document.body.querySelector("[role=alertdialog]")?.textContent).toContain("Masz niezapisane zmiany");
    expect(document.activeElement).toBe(button("Zostań"));
  });

  it("has a red „Opuść bez zapisu” that emits leave", async () => {
    await open();

    expect(button("Opuść bez zapisu")?.className).toContain("destructive");
    button("Opuść bez zapisu")?.click();
    await flushPromises();

    expect(wrapper?.emitted("leave")).toHaveLength(1);
  });

  it("closes with „Zostań” without leaving", async () => {
    await open();

    button("Zostań")?.click();
    await flushPromises();

    expect(wrapper?.emitted("update:open")?.at(-1)).toEqual([false]);
    expect(wrapper?.emitted("leave")).toBeUndefined();
  });
});
