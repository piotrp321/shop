import { createRouter, createWebHistory } from "vue-router";
import HomeView from "@/views/HomeView.vue";

export function createAppRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [{ path: "/", name: "home", component: HomeView }],
  });
}
