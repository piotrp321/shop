import type { UserManager } from "oidc-client-ts";
import { createRouter, createWebHistory, type RouterHistory } from "vue-router";
import { LOGIN_SERVER_MESSAGE, reportLoginProblem } from "@/auth/loginProblem";
import { getUserManager } from "@/auth/userManager";
import { canManageDiscountCodes } from "@/discount-codes/permissions";
import AppLayout from "@/layout/AppLayout.vue";
import { canViewOrders } from "@/orders/permissions";
import { canManageProducts } from "@/products/permissions";
import { canManagePromotions } from "@/promotions/permissions";
import { useAuthStore } from "@/stores/auth";
import AuthCallbackView from "@/views/AuthCallbackView.vue";
import DashboardView from "@/views/DashboardView.vue";
import DiscountCodeFormView from "@/views/DiscountCodeFormView.vue";
import DiscountCodesView from "@/views/DiscountCodesView.vue";
import InventoryView from "@/views/InventoryView.vue";
import OrderDetailView from "@/views/OrderDetailView.vue";
import OrdersView from "@/views/OrdersView.vue";
import PricesView from "@/views/PricesView.vue";
import ProductFormView from "@/views/ProductFormView.vue";
import ProductsView from "@/views/ProductsView.vue";
import PromotionFormView from "@/views/PromotionFormView.vue";
import PromotionsView from "@/views/PromotionsView.vue";

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
          { path: "products", name: "products", component: ProductsView },
          {
            path: "products/new",
            name: "product-new",
            component: ProductFormView,
            // Only the staff who may change products create one; the roles come from /me, which the layout loads only after the route resolves.
            beforeEnter: async () => {
              const auth = useAuthStore();
              if (auth.staff === null) {
                await auth.load();
              }
              return canManageProducts(auth.staff) ? true : { name: "products" };
            },
          },
          { path: "products/:id", name: "product", component: ProductFormView },
          { path: "prices", name: "prices", component: PricesView },
          {
            // One path for the list and the pages, so the menu item of the promotions stays active on a promotion.
            path: "promotions",
            children: [
              { path: "", name: "promotions", component: PromotionsView },
              {
                path: "new",
                name: "promotion-new",
                component: PromotionFormView,
                // Only the staff who may change promotions create one; the roles come from /me, as for a new product.
                beforeEnter: async () => {
                  const auth = useAuthStore();
                  if (auth.staff === null) {
                    await auth.load();
                  }
                  return canManagePromotions(auth.staff) ? true : { name: "promotions" };
                },
              },
              { path: ":id", name: "promotion", component: PromotionFormView },
            ],
          },
          {
            // One path for the list and the pages, so the menu item of the discount codes stays active on a code.
            path: "discount-codes",
            children: [
              { path: "", name: "discount-codes", component: DiscountCodesView },
              {
                path: "new",
                name: "discount-code-new",
                component: DiscountCodeFormView,
                // Only the staff who may change discount codes create one; the roles come from /me, as for a new product.
                beforeEnter: async () => {
                  const auth = useAuthStore();
                  if (auth.staff === null) {
                    await auth.load();
                  }
                  return canManageDiscountCodes(auth.staff) ? true : { name: "discount-codes" };
                },
              },
              { path: ":id", name: "discount-code", component: DiscountCodeFormView },
            ],
          },
          { path: "inventory", name: "inventory", component: InventoryView },
          {
            // One path for the list and the detail, so the menu item of the orders stays active on a detail.
            path: "orders",
            // Only the staff with access to the orders open them; the others land on the dashboard with a message. When
            // /me cannot be read the route is let through: the API checks the access and the view shows the 403.
            beforeEnter: async () => {
              const auth = useAuthStore();
              if (auth.staff === null) {
                await auth.load();
              }
              return auth.staff === null || canViewOrders(auth.staff) ? true : { name: "dashboard", state: { denied: true } };
            },
            children: [
              { path: "", name: "orders", component: OrdersView },
              { path: ":orderId", name: "order-detail", component: OrderDetailView },
            ],
          },
          { path: ":pathMatch(.*)*", redirect: "/" },
        ],
      },
    ],
  });

  router.beforeEach(async (to) => {
    if (to.meta.public === true) {
      return true;
    }
    try {
      const user = await userManager.getUser();
      if (user && !user.expired) {
        return true;
      }
      // After a reload the memory is empty; an active SSO session in Keycloak returns the user without a password.
      await userManager.signinRedirect({ state: { returnTo: to.fullPath } });
    } catch {
      // Keycloak unreachable: App.vue shows the problem with a retry action instead of a blank page.
      reportLoginProblem(LOGIN_SERVER_MESSAGE);
    }
    return false;
  });

  return router;
}
