<script setup lang="ts">
import { useMediaQuery } from "@vueuse/core";
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { RouterView } from "vue-router";
import BrandMark from "@/components/BrandMark.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { Toaster } from "@/components/ui/sonner";
import { readUrl } from "@/config";
import { useIdleLogout } from "@/composables/useIdleLogout";
import { useAuthStore } from "@/stores/auth";
import AppMenu from "./AppMenu.vue";
import AppTopbar from "./AppTopbar.vue";

const IDLE_TIMEOUT_MS = 15 * 60 * 1000;

const auth = useAuthStore();
const storeUrl = readUrl("VITE_STORE_URL");
const year = new Date().getFullYear();
let idle: { stop(): void } | undefined;

const main = ref<HTMLElement | null>(null);
const topbar = ref<InstanceType<typeof AppTopbar> | null>(null);
const drawerOpen = ref(false);

// The sidebar is fixed from 1024 px; a drawer left open when the window grows would hide nothing but duplicate the menu.
const wide = useMediaQuery("(min-width: 1024px)");
watch(wide, (isWide) => {
  if (isWide) {
    drawerOpen.value = false;
  }
});

function startIdleLogout() {
  idle = useIdleLogout({ timeoutMs: IDLE_TIMEOUT_MS, onIdle: signOutIdleUser });
}

async function signOutIdleUser() {
  await auth.logout();
  // useIdleLogout stops itself once it fires. If the sign-out failed the user stays here, so arm it again.
  if (auth.logoutFailed) {
    startIdleLogout();
  }
}

/** The link „Przejdź do treści”: the focus goes to <main>, without changing the address. */
function skipToContent() {
  main.value?.focus();
}

/** The drawer has no trigger inside it, so Reka does not know where to return the focus: it goes back to „Menu”. */
function returnFocusToMenuButton(event: Event) {
  event.preventDefault();
  topbar.value?.focusMenuButton();
}

onMounted(() => {
  startIdleLogout();
  void auth.load();
});

onBeforeUnmount(() => idle?.stop());
</script>

<template>
  <div class="min-h-screen bg-background text-foreground lg:flex">
    <a
      href="#main"
      class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-card focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-foreground focus:shadow-lg"
      @click.prevent="skipToContent"
    >
      Przejdź do treści
    </a>

    <aside
      data-surface="sidebar"
      class="hidden w-65 shrink-0 flex-col bg-sidebar lg:sticky lg:top-0 lg:flex lg:h-screen"
    >
      <div class="flex h-17.5 shrink-0 items-center px-6">
        <BrandMark inverted />
      </div>
      <div class="min-h-0 flex-1 overflow-y-auto">
        <AppMenu />
      </div>
    </aside>

    <Sheet v-model:open="drawerOpen">
      <SheetContent
        side="left"
        data-surface="sidebar"
        class="w-65 max-w-[85vw] gap-0 border-sidebar-border bg-sidebar p-0 sm:max-w-65"
        close-class="text-sidebar-foreground hover:text-sidebar-active"
        @close-auto-focus="returnFocusToMenuButton"
      >
        <SheetHeader class="sr-only">
          <SheetTitle>Menu</SheetTitle>
          <SheetDescription>Menu główne panelu administracyjnego</SheetDescription>
        </SheetHeader>
        <div class="flex h-17.5 shrink-0 items-center px-6">
          <BrandMark inverted />
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
          <AppMenu @navigate="drawerOpen = false" />
        </div>
      </SheetContent>
    </Sheet>

    <div class="flex min-w-0 flex-1 flex-col">
      <AppTopbar
        ref="topbar"
        @open-menu="drawerOpen = true"
      />
      <main
        id="main"
        ref="main"
        tabindex="-1"
        class="flex flex-1 flex-col gap-6 p-4 focus:outline-none sm:p-6"
      >
        <Alert
          v-if="auth.error"
          data-testid="staff-error"
          variant="destructive"
        >
          <AlertDescription>{{ auth.error }}</AlertDescription>
        </Alert>
        <RouterView />
      </main>
      <footer class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 border-t border-separator px-4 py-4 text-xs text-muted-foreground sm:px-6">
        <p>© {{ year }} Worf · Panel administracyjny</p>
        <a
          :href="storeUrl"
          target="_blank"
          rel="noopener"
          class="rounded-sm font-semibold text-interface underline-offset-4 hover:underline"
          aria-label="Sklep (otwiera się w nowej karcie)"
        >Sklep</a>
      </footer>
    </div>

    <Toaster :duration="5000" />
  </div>
</template>
