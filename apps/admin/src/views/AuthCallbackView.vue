<script setup lang="ts">
import Button from "primevue/button";
import Message from "primevue/message";
import ProgressSpinner from "primevue/progressspinner";
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { getUserManager } from "@/auth/userManager";

const router = useRouter();
const failed = ref(false);

/** Only a path inside the panel is a valid target; anything else (an absolute URL, `//host`) falls back to "/". */
function safeTarget(state: unknown): string {
  const target = (state as { returnTo?: unknown } | undefined)?.returnTo;
  return typeof target === "string" && /^\/(?![/\\])/.test(target) ? target : "/";
}

async function retry() {
  await getUserManager().signinRedirect();
}

onMounted(async () => {
  try {
    const user = await getUserManager().signinRedirectCallback();
    await router.replace(safeTarget(user.state));
  } catch {
    // No automatic redirect here: a failing callback would loop between the panel and Keycloak.
    failed.value = true;
  }
});
</script>

<template>
  <main class="callback">
    <Message
      v-if="failed"
      data-testid="callback-error"
      severity="error"
    >
      Logowanie nie powiodło się.
    </Message>
    <Button
      v-if="failed"
      data-testid="retry"
      label="Zaloguj ponownie"
      icon="pi pi-sign-in"
      @click="retry"
    />
    <ProgressSpinner v-else />
  </main>
</template>

<style scoped>
.callback {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  padding: 4rem 1rem;
}
</style>
