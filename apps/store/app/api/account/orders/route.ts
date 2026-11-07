import { runOrderList } from "@/account/orders-bff";
import { withAuthDeps } from "@/auth/responses";

/** A page of the order history of the signed-in customer; `?after=` is the cursor of the next page. */
export const GET = withAuthDeps((request, deps) => runOrderList(request, deps));
