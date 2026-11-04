import { runCartCommand } from "@/cart/bff";
import { withCartDeps } from "@/cart/responses";

/** `{ itemCount, notice }` for the counter in the header. */
export const GET = withCartDeps((request, deps) => runCartCommand(request, deps, { kind: "summary" }));
