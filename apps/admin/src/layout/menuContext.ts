import type { InjectionKey, Ref } from "vue";

/** What AppMenu shares with its items: the current leaf, the open groups and what to do on a choice. */
export interface MenuContext {
  currentId: Ref<string | null>;
  isOpen: (groupId: string) => boolean;
  toggle: (groupId: string) => void;
  /** The staff member chose an item (also the current one): the drawer closes. */
  chosen: () => void;
}

export const MENU_CONTEXT: InjectionKey<MenuContext> = Symbol("menu-context");
