import "primeicons/primeicons.css";
import "@/styles.css";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { createApp } from "vue";
import App from "@/App.vue";
import { primeVueOptions } from "@/primevue";
import { createAppRouter } from "@/router";

createApp(App).use(createPinia()).use(createAppRouter()).use(PrimeVue, primeVueOptions).mount("#app");
