import { invalidBody, readJsonObject, refuseForeignWrite, runCartCommand } from "@/cart/bff";
import { withCartDeps } from "@/cart/responses";

/** Adds `{ variantId, quantity }` to the cart; the first add of a guest creates the guest cart. */
export const POST = withCartDeps(async (request, deps) => {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, true);
  if (refused) {
    return refused;
  }
  const body = await readJsonObject(request);
  if (!body || typeof body.variantId !== "string" || typeof body.quantity !== "number") {
    return invalidBody();
  }
  return runCartCommand(request, deps, { kind: "add", variantId: body.variantId, quantity: body.quantity });
});
