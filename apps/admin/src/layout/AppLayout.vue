<script setup lang="ts">
import Message from "primevue/message";
import { onBeforeUnmount, onMounted } from "vue";
import { RouterView } from "vue-router";
import { useIdleLogout } from "@/composables/useIdleLogout";
import { useAuthStore } from "@/stores/auth";
import AppMenu from "./AppMenu.vue";
import AppTopbar from "./AppTopbar.vue";

const IDLE_TIMEOUT_MS = 15 * 60 * 1000;

const auth = useAuthStore();
let idle: { stop(): void } | undefined;

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

onMounted(() => {
  startIdleLogout();
  void auth.load();
});

onBeforeUnmount(() => idle?.stop());
</script>

<template>
  <div class="layout">
    <aside class="sidebar">
      <AppMenu />
    </aside>
    <div class="main">
      <AppTopbar />
      <main class="content">
        <Message
          v-if="auth.error"
          data-testid="staff-error"
          severity="error"
        >
          {{ auth.error }}
        </Message>
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: flex;
  min-height: 100vh;
  background: var(--p-surface-100);
}

.sidebar {
  width: 14rem;
  flex-shrink: 0;
  background: var(--p-content-background);
  border-right: 1px solid var(--p-content-border-color);
}

.main {
  flex: 1;
  min-width: 0;
}

.content {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.5rem;
}
</style>
