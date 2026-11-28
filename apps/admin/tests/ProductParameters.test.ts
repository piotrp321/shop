import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, describe, expect, it } from "vitest";
import { defineComponent, ref } from "vue";
import ProductParameters from "@/products/ProductParameters.vue";
import { type ParameterRow, MAX_PARAMETERS } from "@/products/useProductForm";

let wrapper: VueWrapper | undefined;

afterEach(() => {
  wrapper?.unmount();
  wrapper = undefined;
  document.body.innerHTML = "";
});

const row = (name: string, value: string): ParameterRow => ({ name, value });
const THREE = [row("Waga", "2,4 kg"), row("Liczba osób", "4"), row("Kolor", "zielony")];

interface Options {
  parameters?: ParameterRow[];
  readOnly?: boolean;
  errors?: string[];
  errorsAt?: Record<string, string[]>;
}

/** The form around the section: adding, removing and moving change the props, like `ProductFormView` does. */
function mountSection(options: Options = {}) {
  const Host = defineComponent({
    components: { ProductParameters },
    setup() {
      const parameters = ref(options.parameters ?? THREE.map((parameter) => ({ ...parameter })));
      const at = (path: string) => Object.entries(options.errorsAt ?? {}).filter(([key]) => key === path || key.startsWith(`${path}.`)).flatMap(([, titles]) => titles);
      const exactly = (path: string) => options.errorsAt?.[path] ?? [];
      return {
        parameters,
        readOnly: options.readOnly ?? false,
        errors: options.errors ?? [],
        at,
        exactly,
        canAdd: () => parameters.value.length < MAX_PARAMETERS,
        add: () => parameters.value.push(row("", "")),
        remove: (index: number) => parameters.value.splice(index, 1),
        move: (index: number, direction: -1 | 1) => {
          const target = index + direction;
          [parameters.value[index], parameters.value[target]] = [parameters.value[target]!, parameters.value[index]!];
        },
        set: (index: number, field: "name" | "value", value: string) => (parameters.value[index]![field] = value),
      };
    },
    template: `<ProductParameters :parameters="parameters" :read-only="readOnly" :can-add="canAdd()" :errors="errors" :errors-at="at" :errors-exactly="exactly" @add="add" @remove="remove" @move="move" @set="set" />`,
  });
  wrapper = mount(Host, { attachTo: document.body });
  return wrapper;
}

const section = () => document.body.querySelector<HTMLElement>("[data-testid=parameters]")!;
const rows = () => [...document.body.querySelectorAll<HTMLElement>("[data-testid=parameter-row]")];
const names = () => [...document.body.querySelectorAll<HTMLInputElement>("[data-testid=parameter-name]")];
const values = () => [...document.body.querySelectorAll<HTMLInputElement>("[data-testid=parameter-value]")];
const addButton = () => document.body.querySelector<HTMLButtonElement>("[data-testid=add-parameter]")!;
const buttonOf = (kind: "up" | "down" | "remove", index: number) => rows()[index]!.querySelector<HTMLButtonElement>(`[data-testid=parameter-${kind}]`)!;
const nameTexts = () => names().map((input) => input.value);

describe("ProductParameters", () => {
  describe("the rows", () => {
    it("is a card „Parametry” with a row of a name and a value for every parameter, in the order of the list", () => {
      mountSection();

      expect(section().querySelector("h2, h3")?.textContent?.trim()).toBe("Parametry");
      expect(rows()).toHaveLength(3);
      expect(nameTexts()).toEqual(["Waga", "Liczba osób", "Kolor"]);
      expect(values().map((input) => input.value)).toEqual(["2,4 kg", "4", "zielony"]);
    });

    it("names every control: the fields by their row, the buttons by the action and the number of the parameter", () => {
      mountSection();

      expect(names().map((input) => input.getAttribute("aria-label"))).toEqual(["Nazwa parametru 1", "Nazwa parametru 2", "Nazwa parametru 3"]);
      expect(values().map((input) => input.getAttribute("aria-label"))).toEqual(["Wartość parametru 1", "Wartość parametru 2", "Wartość parametru 3"]);
      expect(buttonOf("up", 1).getAttribute("aria-label")).toBe("Przesuń parametr 2 w górę");
      expect(buttonOf("down", 1).getAttribute("aria-label")).toBe("Przesuń parametr 2 w dół");
      expect(buttonOf("remove", 1).getAttribute("aria-label")).toBe("Usuń parametr 2");
      expect(addButton().textContent?.trim()).toBe("Dodaj parametr");
      const nameless = [...section().querySelectorAll("button, input")].filter((control) => (control.getAttribute("aria-label") ?? control.textContent ?? "").trim() === "");
      expect(nameless).toEqual([]);
    });

    it("limits the length of a name to 60 and of a value to 200 characters", () => {
      mountSection();

      expect(names()[0]!.getAttribute("maxlength")).toBe("60");
      expect(values()[0]!.getAttribute("maxlength")).toBe("200");
    });

    it("says that the product has no parameters yet when the list is empty", () => {
      mountSection({ parameters: [] });

      expect(rows()).toHaveLength(0);
      expect(section().querySelector("[data-testid=no-parameters]")?.textContent).toContain("nie ma jeszcze parametrów");
    });

    it("cannot move the first parameter up nor the last one down", () => {
      mountSection();

      expect(buttonOf("up", 0).disabled).toBe(true);
      expect(buttonOf("down", 2).disabled).toBe(true);
      expect(buttonOf("up", 1).disabled).toBe(false);
      expect(buttonOf("down", 1).disabled).toBe(false);
    });

    it("edits the draft as the staff member types, the name and the value", async () => {
      mountSection();

      await wrapperInput(names()[0]!, "Masa");
      await wrapperInput(values()[0]!, "3 kg");

      expect(nameTexts()[0]).toBe("Masa");
      expect(values()[0]!.value).toBe("3 kg");
    });
  });

  describe("adding", () => {
    it("adds an empty row at the end and puts the focus on its name", async () => {
      mountSection();

      addButton().click();
      await flushPromises();

      expect(rows()).toHaveLength(4);
      expect(document.activeElement).toBe(names()[3]);
      expect(names()[3]!.value).toBe("");
    });

    it("adds a row to an empty list too, with the focus on its name", async () => {
      mountSection({ parameters: [] });

      addButton().click();
      await flushPromises();

      expect(rows()).toHaveLength(1);
      expect(document.activeElement).toBe(names()[0]);
    });

    it("turns the button off at the limit of 30 and says so", async () => {
      mountSection({ parameters: Array.from({ length: 29 }, (_, index) => row(`Parametr ${index}`, "x")) });

      addButton().click();
      await flushPromises();

      expect(rows()).toHaveLength(30);
      expect(addButton().disabled).toBe(true);
      expect(section().textContent).toContain("Osiągnięto limit 30 parametrów.");
    });

    it("is operated from the keyboard: the button is a native button, and Enter and Space on it click it", () => {
      mountSection();

      expect(addButton().tagName).toBe("BUTTON");
      expect(addButton().getAttribute("type")).toBe("button");
    });
  });

  describe("removing", () => {
    it("removes the row and puts the focus on the name of the row that took its place", async () => {
      mountSection();
      buttonOf("remove", 1).focus();

      buttonOf("remove", 1).click();
      await flushPromises();

      expect(nameTexts()).toEqual(["Waga", "Kolor"]);
      expect(document.activeElement).toBe(names()[1]);
    });

    it("puts the focus on the name of the new first row when the first one was removed", async () => {
      mountSection();
      buttonOf("remove", 0).focus();

      buttonOf("remove", 0).click();
      await flushPromises();

      expect(document.activeElement).toBe(names()[0]);
      expect(nameTexts()).toEqual(["Liczba osób", "Kolor"]);
    });

    it("puts the focus on „Dodaj parametr” when the last row was removed", async () => {
      mountSection();
      buttonOf("remove", 2).focus();

      buttonOf("remove", 2).click();
      await flushPromises();

      expect(rows()).toHaveLength(2);
      expect(document.activeElement).toBe(addButton());
    });

    it("puts the focus on „Dodaj parametr” when the only row was removed", async () => {
      mountSection({ parameters: [row("Waga", "1 kg")] });
      buttonOf("remove", 0).focus();

      buttonOf("remove", 0).click();
      await flushPromises();

      expect(rows()).toHaveLength(0);
      expect(document.activeElement).toBe(addButton());
    });
  });

  describe("moving", () => {
    it("moves a row up and down and keeps the focus on the button that moved it", async () => {
      mountSection();
      buttonOf("down", 0).focus();

      buttonOf("down", 0).click();
      await flushPromises();

      expect(nameTexts()).toEqual(["Liczba osób", "Waga", "Kolor"]);
      expect(document.activeElement).toBe(buttonOf("down", 1));

      buttonOf("up", 1).focus();
      buttonOf("up", 1).click();
      await flushPromises();

      expect(nameTexts()).toEqual(["Waga", "Liczba osób", "Kolor"]);
      // The first row cannot move up any more, so the focus goes to the other arrow of the row instead of being lost.
      expect(document.activeElement).toBe(buttonOf("down", 0));
    });
  });

  describe("the messages of the API", () => {
    it("shows the message of the list above the rows", () => {
      mountSection({ errors: ["Produkt może mieć najwyżej 30 parametrów."] });

      const alert = section().querySelector("[data-testid=parameters-error]");
      expect(alert?.textContent).toContain("Produkt może mieć najwyżej 30 parametrów.");
      expect(alert?.hasAttribute("data-form-error")).toBe(true);
    });

    it("marks the name and the value with their messages invalid and points each at its message", () => {
      mountSection({ errorsAt: { "parameters[1].name": ["Parametr o tej nazwie już jest na liście."], "parameters[1].value": ["Wartość parametru musi mieć od 1 do 200 znaków."] } });

      const name = names()[1]!;
      const value = values()[1]!;
      expect(name.getAttribute("aria-invalid")).toBe("true");
      expect(value.getAttribute("aria-invalid")).toBe("true");
      expect(document.getElementById(name.getAttribute("aria-describedby")!)?.textContent).toBe("Parametr o tej nazwie już jest na liście.");
      expect(document.getElementById(value.getAttribute("aria-describedby")!)?.textContent).toBe("Wartość parametru musi mieć od 1 do 200 znaków.");
      expect(names()[0]!.getAttribute("aria-invalid")).toBeNull();
      expect(names()[0]!.getAttribute("aria-describedby")).toBeNull();
    });

    it("shows the message of a whole row (a parameter that is no object) at that row and marks both fields", () => {
      mountSection({ errorsAt: { "parameters[0]": ["Parametr musi mieć nazwę i wartość."] } });

      const message = rows()[0]!.querySelector("[data-form-error]");
      expect(message?.textContent).toBe("Parametr musi mieć nazwę i wartość.");
      expect(names()[0]!.getAttribute("aria-invalid")).toBe("true");
      expect(document.getElementById(names()[0]!.getAttribute("aria-describedby")!)?.textContent).toBe("Parametr musi mieć nazwę i wartość.");
    });
  });

  describe("without the permission to manage products", () => {
    it("shows the parameters read-only: the fields are read-only, still reachable, and there are no buttons", () => {
      mountSection({ readOnly: true });

      for (const input of [...names(), ...values()]) {
        expect(input.readOnly).toBe(true);
        expect(input.disabled).toBe(false);
        input.focus();
        expect(document.activeElement).toBe(input);
      }
      expect(section().querySelectorAll("button")).toHaveLength(0);
    });
  });

  describe("the layout at 375 px", () => {
    it("lets a row wrap and the fields shrink, so that the page does not scroll sideways", () => {
      mountSection();

      const first = rows()[0]!;
      expect(first.className).toContain("flex-wrap");
      expect(names()[0]!.closest("div")?.className).toContain("min-w-0");
      expect(section().querySelector("table")).toBeNull();
    });
  });
});

async function wrapperInput(input: HTMLInputElement, text: string) {
  input.value = text;
  input.dispatchEvent(new Event("input", { bubbles: true }));
  await flushPromises();
}
