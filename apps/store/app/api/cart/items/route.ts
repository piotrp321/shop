import { invalidBody, readJsonObject, refuseForeignWrite, runCartCommand } from "@/cart/bff";
import { withCartDeps } from "@/cart/responses";

/** Adds `{ productId, quantity }` to the cart; the first add of a guest creates the guest cart. */
export const POST = withCartDeps(async (request, deps) => {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, true);
  if (refused) {
    return refused;
  }
  const body = await readJsonObject(request);
  if (!body || typeof body.productId !== "string" || typeof body.quantity !== "number") {
    return invalidBody();
  }
  return runCartCommand(request, deps, { kind: "add", productId: body.productId, quantity: body.quantity });
});
