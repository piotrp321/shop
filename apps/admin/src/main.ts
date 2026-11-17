import "@/styles/tokens.css";
import { createPinia } from "pinia";
import { createApp } from "vue";
import App from "@/App.vue";
import { initSentry } from "@/observability";
import { createAppRouter } from "@/router";

const app = createApp(App);
const router = createAppRouter();
// Before the router is installed, so that the SDK sees the first navigation; without a DSN it does nothing.
initSentry(app, router);
app.use(createPinia()).use(router).mount("#app");
