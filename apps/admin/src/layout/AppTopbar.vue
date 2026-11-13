<script setup lang="ts">
import { ChevronDown, ExternalLink, LogOut, Menu, Search } from "lucide-vue-next";
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import { Button } from "@/components/ui/button";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { readUrl } from "@/config";
import { useAuthStore } from "@/stores/auth";
import { initialsOf, roleLabel } from "./account";

const emit = defineEmits<{ "open-menu": [] }>();

/** The phrase of the product search: 2 to 100 characters once trimmed, counted in code points like the API does. */
const MIN_LENGTH = 2;
const MAX_LENGTH = 100;
const HINT = "Wpisz od 2 do 100 znaków.";
const HINT_ID = "topbar-search-hint";

const auth = useAuthStore();
const router = useRouter();
const storeUrl = readUrl("VITE_STORE_URL");

const phrase = ref("");
const hinted = ref(false);
const menuButton = ref<InstanceType<typeof Button> | null>(null);

const roles = computed(() => auth.staff?.roles.map(roleLabel).join(", ") ?? "");
const accountLabel = computed(() => (auth.staff ? `Konto: ${auth.staff.username}, ${roles.value}` : "Konto"));

/** Control characters and line or paragraph separators count as spaces, like the white space that `trim` removes. */
function normalized(text: string): string {
  return text.replace(/[\p{Cc}\p{Zl}\p{Zp}]/gu, " ").trim();
}

function search() {
  const term = normalized(phrase.value);
  if (term === "") {
    hinted.value = false;
    return;
  }
  const length = [...term].length;
  if (length < MIN_LENGTH || length > MAX_LENGTH) {
    hinted.value = true;
    return;
  }
  hinted.value = false;
  void router.push({ name: "products", query: { q: term } });
}

defineExpose({ focusMenuButton: () => (menuButton.value?.$el as HTMLElement | undefined)?.focus() });
</script>

<template>
  <header class="sticky top-0 z-30 flex h-17.5 shrink-0 items-center gap-3 bg-card px-4 shadow-sm lg:px-6">
    <Button
      ref="menuButton"
      variant="outline"
      size="icon"
      class="lg:hidden"
      aria-label="Menu"
      @click="emit('open-menu')"
    >
      <Menu aria-hidden="true" />
    </Button>

    <form
      role="search"
      class="relative min-w-0 max-w-md flex-1"
      @submit.prevent="search"
    >
      <Search
        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden="true"
      />
      <Input
        v-model="phrase"
        type="search"
        class="rounded-full bg-background pl-9"
        placeholder="Szukaj produktu…"
        aria-label="Szukaj produktu"
        :aria-describedby="hinted ? HINT_ID : undefined"
        @input="hinted = false"
      />
      <!-- The region is always there, so the hint is announced when its text appears. -->
      <p
        :id="HINT_ID"
        role="status"
        :class="hinted ? 'absolute top-full left-0 z-10 mt-1 w-max max-w-[calc(100vw-2rem)] rounded-md border bg-card px-3 py-1.5 text-xs font-semibold text-status-danger shadow-md' : 'sr-only'"
      >
        {{ hinted ? HINT : "" }}
      </p>
    </form>

    <div class="ml-auto flex items-center gap-1 sm:gap-3">
      <a
        :href="storeUrl"
        target="_blank"
        rel="noopener"
        aria-label="Sklep (otwiera się w nowej karcie)"
        class="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
      >
        Sklep
        <ExternalLink
          class="size-4"
          aria-hidden="true"
        />
      </a>

      <DropdownMenu>
        <DropdownMenuTrigger
          data-testid="account"
          :aria-label="accountLabel"
          class="flex items-center gap-3 rounded-md p-1 text-left transition-colors hover:bg-accent"
        >
          <span
            data-testid="initials"
            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-primary to-primary-hover text-sm font-bold text-primary-foreground"
            aria-hidden="true"
          >
            {{ auth.staff ? initialsOf(auth.staff.username) : "?" }}
          </span>
          <span class="hidden flex-col leading-tight sm:flex">
            <template v-if="auth.staff">
              <span
                data-testid="username"
                class="text-sm font-bold text-heading"
              >{{ auth.staff.username }}</span>
              <span
                data-testid="roles"
                class="text-xs text-muted-foreground"
              >{{ roles }}</span>
            </template>
            <span
              v-else
              class="text-sm font-bold text-heading"
            >Konto</span>
          </span>
          <ChevronDown
            class="hidden size-4 text-muted-foreground sm:block"
            aria-hidden="true"
          />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem @select="auth.logout()">
            <LogOut aria-hidden="true" />
            Wyloguj
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  </header>
</template>
