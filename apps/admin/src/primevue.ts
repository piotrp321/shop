import Aura from "@primeuix/themes/aura";
import type { PrimeVueConfiguration } from "primevue/config";

export const primeVueOptions: PrimeVueConfiguration = {
  theme: {
    preset: Aura,
    // The panel has a single light appearance.
    options: { darkModeSelector: "none" },
  },
};
