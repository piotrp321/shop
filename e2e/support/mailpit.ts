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
