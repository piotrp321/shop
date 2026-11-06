import { urls } from "./urls";

const TIMEOUT_MS = 30_000;
const POLL_INTERVAL_MS = 500;

async function getJson<T>(path: string): Promise<T> {
  const response = await fetch(`${urls.mailpit}${path}`);
  if (!response.ok) {
    throw new Error(`Mailpit ${path} answered ${response.status}`);
  }
  return (await response.json()) as T;
}

/** Waits (up to 30 s) for a message addressed to `to` and returns its HTML body. */
export async function waitForEmail(to: string): Promise<{ html: string }> {
  const deadline = Date.now() + TIMEOUT_MS;
  while (Date.now() < deadline) {
    const found = await getJson<{ messages: { ID: string }[] }>(`/api/v1/search?query=${encodeURIComponent(`to:${to}`)}`);
    const first = found.messages[0];
    if (first) {
      const message = await getJson<{ HTML: string }>(`/api/v1/message/${first.ID}`);
      return { html: message.HTML };
    }
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
  }
  throw new Error(`No e-mail to ${to} arrived in Mailpit within ${TIMEOUT_MS / 1000} s`);
}

/**
 * Waits (up to 30 s) for the order confirmation to `to` with `number` in the subject and returns its subject and text.
 * Searching by both matters: the same address (a demo account) gets an e-mail for every order of every run.
 */
export async function waitForOrderEmail(to: string, number: string): Promise<{ subject: string; text: string }> {
  const query = `to:"${to}" subject:"${number}"`;
  const deadline = Date.now() + TIMEOUT_MS;
  while (Date.now() < deadline) {
    const found = await getJson<{ messages: { ID: string }[] }>(`/api/v1/search?query=${encodeURIComponent(query)}`);
    const first = found.messages[0];
    if (first) {
      const message = await getJson<{ Subject: string; Text: string }>(`/api/v1/message/${first.ID}`);
      return { subject: message.Subject, text: message.Text };
    }
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
  }
  throw new Error(`No order e-mail to ${to} with ${number} arrived in Mailpit within ${TIMEOUT_MS / 1000} s`);
}
