import { invalidBody, lineMissing, readJsonObject, refuseForeignWrite, runCartCommand } from "@/cart/bff";
import { isUuid } from "@/cart/cookies";
import { withCartDeps } from "@/cart/responses";

type Context = { params: Promise<{ variantId: string }> };

/** Sets the quantity of the line of the variant to `{ quantity }`. */
export const PATCH = withCartDeps<Context>(async (request, deps, { params }) => {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, true);
  if (refused) {
    return refused;
  }
  const { variantId } = await params;
  if (!isUuid(variantId)) {
    return lineMissing();
  }
  const body = await readJsonObject(request);
  if (!body || typeof body.quantity !== "number") {
    return invalidBody();
  }
  return runCartCommand(request, deps, { kind: "change", variantId, quantity: body.quantity });
});

/** Removes the line of the variant; a cart without it is answered as it is. */
export const DELETE = withCartDeps<Context>(async (request, deps, { params }) => {
  const refused = refuseForeignWrite(request, deps.config.storeUrl, false);
  if (refused) {
    return refused;
  }
  const { variantId } = await params;
  if (!isUuid(variantId)) {
    return lineMissing();
  }
  return runCartCommand(request, deps, { kind: "remove", variantId });
});
