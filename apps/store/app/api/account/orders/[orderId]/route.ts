import { runOrderRead } from "@/account/orders-bff";
import { withAuthDeps } from "@/auth/responses";

/** An order of the signed-in customer. */
export const GET = withAuthDeps(async (request, deps, { params }: { params: Promise<{ orderId: string }> }) =>
  runOrderRead(request, deps, (await params).orderId),
);
