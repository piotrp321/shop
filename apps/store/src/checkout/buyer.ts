/**
 * The `buyer` the checkout sends when it shows the guest form (the e-mail field); with an account it sends the tag of
 * `/auth/session` instead. Apart from `src/auth/buyer.ts`, which uses `node:crypto`, so that the page can import it.
 */
export const GUEST_BUYER = "guest";
