/**
 * A page of a list as a test builds it: the `total` of the real answer comes with the first page only, so most pages
 * of the tests leave it out (the pager takes a missing one as „not known”).
 */
export type WithOptionalTotal<T extends { total: unknown }> = Omit<T, "total"> & { total?: T["total"] };

/** The abort signal that a list gave to the mocked client `GET` for `path` (first such request), or undefined when it gave none. */
export function requestSignal(get: { mock: { calls: unknown[][] } }, path: string): AbortSignal | undefined {
  const call = get.mock.calls.find(([calledPath]) => calledPath === path);
  return (call?.[1] as { signal?: AbortSignal } | undefined)?.signal;
}
