import "primeicons/primeicons.css";
import "@/styles/tokens.css";
import { createPinia } from "pinia";
import PrimeVue from "primevue/config";
import { createApp } from "vue";
import App from "@/App.vue";
import { initSentry } from "@/observability";
import { primeVueOptions } from "@/primevue";
import { createAppRouter } from "@/router";

const app = createApp(App);
const router = createAppRouter();
// Before the router is installed, so that the SDK sees the first navigation; without a DSN it does nothing.
initSentry(app, router);
app.use(createPinia()).use(router).use(PrimeVue, primeVueOptions).mount("#app");
