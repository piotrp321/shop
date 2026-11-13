import type { InjectionKey, Ref } from "vue";

/** What AppMenu shares with its items: the current leaf, the open groups and what to do on a choice. */
export interface MenuContext {
  currentId: Ref<string | null>;
  isOpen: (groupId: string) => boolean;
  toggle: (groupId: string) => void;
  /** The `id` of the region of a group's items: unique per menu, as the sidebar and the drawer can both be in the DOM. */
  regionId: (groupId: string) => string;
  /** The staff member chose an item (also the current one): the drawer closes. */
  chosen: () => void;
}

export const MENU_CONTEXT: InjectionKey<MenuContext> = Symbol("menu-context");
