import { invalidBody, lineMissing, readJsonObject, refuseForeignWrite, runCartCommand } from "@/cart/bff";
import { isUuid } from "@/cart/cookies";
import { withCartDeps } from "@/cart/responses";

type Context = { params: Promise<{ productId: string }> };

/** Sets the quantity of the line of the product to `{ quantity }`. */
export const PATCH = withCartDeps<Context>(async (request, deps, { params }) => {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, true);
  if (refused) {
    return refused;
  }
  const { productId } = await params;
  if (!isUuid(productId)) {
    return lineMissing();
  }
  const body = await readJsonObject(request);
  if (!body || typeof body.quantity !== "number") {
    return invalidBody();
  }
  return runCartCommand(request, deps, { kind: "change", productId, quantity: body.quantity });
});

/** Removes the line of the product; a cart without it is answered as it is. */
export const DELETE = withCartDeps<Context>(async (request, deps, { params }) => {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, false);
  if (refused) {
    return refused;
  }
  const { productId } = await params;
  if (!isUuid(productId)) {
    return lineMissing();
  }
  return runCartCommand(request, deps, { kind: "remove", productId });
});
