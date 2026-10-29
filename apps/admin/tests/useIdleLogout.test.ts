import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useIdleLogout } from "@/composables/useIdleLogout";

const MINUTE = 60_000;
const TIMEOUT = 15 * MINUTE;

/** A channel stand-in: tests deliver "other tab" messages with `receive`. */
function fakeChannel() {
  const target = new EventTarget();
  return {
    postMessage: vi.fn(),
    addEventListener: target.addEventListener.bind(target),
    removeEventListener: target.removeEventListener.bind(target),
    close: vi.fn(),
    receive(data: unknown) {
      target.dispatchEvent(new MessageEvent("message", { data }));
    },
  };
}

describe("useIdleLogout", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("calls onIdle exactly once after 15 minutes without activity", () => {
    const onIdle = vi.fn();
    useIdleLogout({ timeoutMs: TIMEOUT, onIdle, channel: fakeChannel() });

    vi.advanceTimersByTime(TIMEOUT - 1);
    expect(onIdle).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    expect(onIdle).toHaveBeenCalledTimes(1);

    vi.advanceTimersByTime(TIMEOUT * 2);
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it.each([
    ["keydown", () => window.dispatchEvent(new KeyboardEvent("keydown", { key: "a" }))],
    ["pointerdown", () => window.dispatchEvent(new Event("pointerdown"))],
    ["wheel", () => window.dispatchEvent(new Event("wheel"))],
    ["visibilitychange", () => document.dispatchEvent(new Event("visibilitychange"))],
  ])("%s in minute 14 moves the logout to minute 29", (_name, fire) => {
    const onIdle = vi.fn();
    const handle = useIdleLogout({ timeoutMs: TIMEOUT, onIdle, channel: fakeChannel() });

    vi.advanceTimersByTime(14 * MINUTE);
    fire();

    vi.advanceTimersByTime(15 * MINUTE - 1);
    expect(onIdle).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    expect(onIdle).toHaveBeenCalledTimes(1);
    handle.stop();
  });

  it("a message from another tab in minute 14 also moves the logout", () => {
    const onIdle = vi.fn();
    const channel = fakeChannel();
    useIdleLogout({ timeoutMs: TIMEOUT, onIdle, channel });

    vi.advanceTimersByTime(14 * MINUTE);
    channel.receive(Date.now());

    vi.advanceTimersByTime(15 * MINUTE - 1);
    expect(onIdle).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it("ignores malformed channel messages", () => {
    const onIdle = vi.fn();
    const channel = fakeChannel();
    useIdleLogout({ timeoutMs: TIMEOUT, onIdle, channel });

    vi.advanceTimersByTime(14 * MINUTE);
    channel.receive("now");
    channel.receive(null);
    channel.receive(Number.NaN);
    channel.receive(Date.now() + 10 * TIMEOUT);

    vi.advanceTimersByTime(MINUTE);
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it("shares the time of the user's own activity with other tabs, throttled", () => {
    const channel = fakeChannel();
    useIdleLogout({ timeoutMs: TIMEOUT, onIdle: vi.fn(), channel });

    vi.advanceTimersByTime(5_000);
    window.dispatchEvent(new Event("pointerdown"));
    window.dispatchEvent(new Event("pointerdown"));
    window.dispatchEvent(new Event("keydown"));

    expect(channel.postMessage).toHaveBeenCalledTimes(1);
    expect(channel.postMessage).toHaveBeenCalledWith(Date.now());
  });

  it("does not echo a message from another tab back to the channel", () => {
    const channel = fakeChannel();
    useIdleLogout({ timeoutMs: TIMEOUT, onIdle: vi.fn(), channel });

    vi.advanceTimersByTime(5_000);
    channel.receive(Date.now());

    expect(channel.postMessage).not.toHaveBeenCalled();
  });

  it("stop() prevents the logout and removes the listeners", () => {
    const onIdle = vi.fn();
    const channel = fakeChannel();
    const handle = useIdleLogout({ timeoutMs: TIMEOUT, onIdle, channel });

    handle.stop();
    window.dispatchEvent(new Event("keydown"));
    channel.receive(Date.now());
    vi.advanceTimersByTime(TIMEOUT * 2);

    expect(onIdle).not.toHaveBeenCalled();
    expect(channel.postMessage).not.toHaveBeenCalled();
  });

  it("logs out when the tab becomes visible after the timeout passed while timers were suspended", () => {
    const onIdle = vi.fn();
    useIdleLogout({ timeoutMs: TIMEOUT, onIdle, channel: fakeChannel() });

    // The clock jumps (a sleeping tab or laptop) without the timer having fired.
    vi.setSystemTime(Date.now() + 20 * MINUTE);
    document.dispatchEvent(new Event("visibilitychange"));

    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it("uses the injected clock", () => {
    const onIdle = vi.fn();
    let current = 1_000;
    useIdleLogout({ timeoutMs: TIMEOUT, onIdle, channel: fakeChannel(), now: () => current });

    current += TIMEOUT;
    vi.advanceTimersByTime(TIMEOUT);

    expect(onIdle).toHaveBeenCalledTimes(1);
  });
});
