import type { UserManager } from "oidc-client-ts";
import { createRouter, createWebHistory, type RouterHistory } from "vue-router";
import { getUserManager } from "@/auth/userManager";
import AppLayout from "@/layout/AppLayout.vue";
import AuthCallbackView from "@/views/AuthCallbackView.vue";
import DashboardView from "@/views/DashboardView.vue";

type SessionManager = Pick<UserManager, "getUser" | "signinRedirect">;

export function createAppRouter(userManager: SessionManager = getUserManager(), history: RouterHistory = createWebHistory()) {
  const router = createRouter({
    history,
    routes: [
      // The only route reachable without a user: it completes the sign-in.
      { path: "/auth/callback", name: "auth-callback", component: AuthCallbackView, meta: { public: true } },
      {
        path: "/",
        component: AppLayout,
        children: [
          { path: "", name: "dashboard", component: DashboardView },
          { path: ":pathMatch(.*)*", redirect: "/" },
        ],
      },
    ],
  });

  router.beforeEach(async (to) => {
    if (to.meta.public === true) {
      return true;
    }
    const user = await userManager.getUser();
    if (user && !user.expired) {
      return true;
    }
    // After a reload the memory is empty; an active SSO session in Keycloak returns the user without a password.
    await userManager.signinRedirect({ state: { returnTo: to.fullPath } });
    return false;
  });

  return router;
}
