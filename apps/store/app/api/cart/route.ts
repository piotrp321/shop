import { runCartCommand } from "@/cart/bff";
import { withCartDeps } from "@/cart/responses";

/** The `CartView` of the guest cart (cookie `store_cart`) or, with a session, of the customer's cart. */
export const GET = withCartDeps((request, deps) => runCartCommand(request, deps, { kind: "read" }));
