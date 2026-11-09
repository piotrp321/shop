/**
 * Fired on `document` when the panel of a department of the menu opens. The compact search panel (1024 to 1279 px) lies
 * in the same place under the header, so `SearchBox` closes on it: a hover opens a department without a click and
 * without moving the focus, and the two panels must not be open together.
 */
export const DEPARTMENT_PANEL_OPENED = "header-department-panel-opened";
