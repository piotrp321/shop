import { withCartDeps } from "@/cart/responses";
import { runPaymentStart, runPaymentStatus } from "@/checkout/payment-bff";

/** Starts a payment of the order of the confirmation cookie: `Idempotency-Key` header and `{ provider }` in the body. */
export const POST = withCartDeps((request, deps) => runPaymentStart(request, deps));

/** The payment status of the order of the confirmation cookie, for the confirmation page to refresh. */
export const GET = withCartDeps((request, deps) => runPaymentStatus(request, deps));
