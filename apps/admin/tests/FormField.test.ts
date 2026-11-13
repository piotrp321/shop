import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import FormField from "@/components/FormField.vue";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
});

function render(props: Record<string, unknown> = {}, hint?: string) {
  wrapper = mount(FormField, {
    props: { label: "Nazwa", controlId: "name", ...props },
    slots: {
      default: `<template #default="{ describedBy, invalid }"><input id="name" :aria-describedby="describedBy" :aria-invalid="invalid ? 'true' : undefined"></template>`,
      ...(hint === undefined ? {} : { hint }),
    },
    attachTo: document.body,
  });
  return wrapper;
}

describe("FormField", () => {
  it("puts the label above the control and ties them with for", () => {
    const field = render();

    const label = field.get("label");
    expect(label.text()).toBe("Nazwa");
    expect(label.attributes("for")).toBe("name");
  });

  it("points the control at its hint", () => {
    const field = render({}, "<span>pusty: wyliczony z nazwy</span>");

    const hint = field.get("#name-hint");
    expect(hint.text()).toBe("pusty: wyliczony z nazwy");
    expect(field.get("input").attributes("aria-describedby")).toBe("name-hint");
    expect(field.get("input").attributes("aria-invalid")).toBeUndefined();
  });

  it("marks the control invalid and points it at every error and the hint", () => {
    const field = render({ errors: ["Podaj nazwę.", "Za krótka."], errorTestid: "error-name" }, "<span>wskazówka</span>");

    const errors = field.findAll("[data-testid=error-name]");
    expect(errors.map((error) => error.text())).toEqual(["Podaj nazwę.", "Za krótka."]);
    expect(errors.every((error) => error.attributes("data-form-error") !== undefined)).toBe(true);
    expect(field.get("input").attributes("aria-invalid")).toBe("true");
    expect(field.get("input").attributes("aria-describedby")).toBe(`name-hint ${errors.map((error) => error.attributes("id")).join(" ")}`);
    expect(new Set(errors.map((error) => error.attributes("id"))).size).toBe(2);
  });

  it("has no label when the control draws its own", () => {
    const field = render({ label: undefined });

    expect(field.find("label").exists()).toBe(false);
  });
});
