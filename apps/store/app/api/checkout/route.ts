import { withCartDeps } from "@/cart/responses";
import { runCheckout } from "@/checkout/bff";

/** Places the order of the cart: `Idempotency-Key` header and the contact, address and expected total in the body. */
export const POST = withCartDeps((request, deps) => runCheckout(request, deps));
