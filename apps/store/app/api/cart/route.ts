import { runCartCommand } from "@/cart/bff";
import { withCartDeps } from "@/cart/responses";

/**
 * The `CartView` of the guest cart (cookie `store_cart`) or, with a session, of the customer's cart. The only
 * parameter is `discountCode`, the code of the checkout preview; any other one is dropped.
 */
export const GET = withCartDeps((request, deps) => {
  const discountCode = request.nextUrl.searchParams.get("discountCode");
  return runCartCommand(request, deps, discountCode === null ? { kind: "read" } : { kind: "read", discountCode });
});
