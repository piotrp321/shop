/**
 * A page of a list as a test builds it: the `total` of the real answer comes with the first page only, so most pages
 * of the tests leave it out (the pager takes a missing one as „not known”).
 */
export type WithOptionalTotal<T extends { total: unknown }> = Omit<T, "total"> & { total?: T["total"] };
