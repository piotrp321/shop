/** The part of BroadcastChannel the composable uses; tests pass a stand-in. */
export type ActivityChannel = Pick<BroadcastChannel, "postMessage" | "addEventListener" | "removeEventListener" | "close">;

export interface IdleLogoutOptions {
  timeoutMs: number;
  onIdle: () => void;
  /** Shares the time of the last activity between tabs. Defaults to a BroadcastChannel where available. */
  channel?: ActivityChannel;
  now?: () => number;
}

/** User events closer together than this count as one: bounds the timer resets and the broadcasts. */
const THROTTLE_MS = 1000;
const CHANNEL_NAME = "admin-activity";

/**
 * Calls `onIdle` once when neither this tab nor any other tab saw user activity for `timeoutMs`.
 * Only pointer, keyboard, wheel and a tab turning visible count; token renewal and data refreshes do not.
 */
export function useIdleLogout(options: IdleLogoutOptions): { stop(): void } {
  const now = options.now ?? Date.now;
  const channel = options.channel ?? (typeof BroadcastChannel === "undefined" ? undefined : new BroadcastChannel(CHANNEL_NAME));
  const ownsChannel = options.channel === undefined;

  let lastActivity = now();
  let timer: ReturnType<typeof setTimeout> | undefined;
  let stopped = false;

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(check, Math.max(0, lastActivity + options.timeoutMs - now()));
  }

  // Returns true when the user is idle and `onIdle` has been called.
  function check(): boolean {
    if (stopped) {
      return false;
    }
    if (now() - lastActivity >= options.timeoutMs) {
      stop();
      options.onIdle();
      return true;
    }
    schedule();
    return false;
  }

  function onActivity() {
    const at = now();
    if (at - lastActivity < THROTTLE_MS) {
      return;
    }
    lastActivity = at;
    schedule();
    channel?.postMessage(at);
  }

  function onVisibilityChange() {
    // Timers of a hidden tab may be suspended, so the timeout can have passed unnoticed.
    if (document.visibilityState === "visible" && !check()) {
      onActivity();
    }
  }

  function onMessage(event: Event) {
    const at = (event as MessageEvent<unknown>).data;
    // Ignore anything but a past timestamp newer than what this tab knows.
    if (typeof at === "number" && Number.isFinite(at) && at <= now() && at > lastActivity) {
      lastActivity = at;
      schedule();
    }
  }

  const windowEvents = ["pointerdown", "keydown", "wheel"] as const;
  for (const name of windowEvents) {
    window.addEventListener(name, onActivity, { passive: true });
  }
  document.addEventListener("visibilitychange", onVisibilityChange);
  channel?.addEventListener("message", onMessage);
  schedule();

  function stop() {
    stopped = true;
    clearTimeout(timer);
    for (const name of windowEvents) {
      window.removeEventListener(name, onActivity);
    }
    document.removeEventListener("visibilitychange", onVisibilityChange);
    channel?.removeEventListener("message", onMessage);
    if (ownsChannel) {
      channel?.close();
    }
  }

  return { stop };
}
