import type { AccountSession } from "@/api/client";

const SESSION_READ = "store:session-read";

/**
 * After a page read who is signed in again (`null`: nobody), with what it read, so that the header shows the same
 * session without a request of its own: the checkout after a changed buyer, and after a 401 that ended the session.
 */
export function announceSession(session: AccountSession) {
  window.dispatchEvent(new CustomEvent<AccountSession>(SESSION_READ, { detail: session }));
}

/** Returns the function that unsubscribes. */
export function subscribeToSession(listener: (session: AccountSession) => void): () => void {
  const handle = (event: Event) => listener((event as CustomEvent<AccountSession>).detail);
  window.addEventListener(SESSION_READ, handle);
  return () => window.removeEventListener(SESSION_READ, handle);
}
