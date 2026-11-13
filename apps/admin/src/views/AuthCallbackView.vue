<script setup lang="ts">
import { Loader2, LogIn } from "lucide-vue-next";
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import BrandMark from "@/components/BrandMark.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
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
  <main class="flex min-h-screen items-center justify-center bg-background px-4 py-10">
    <Card class="w-full max-w-md">
      <CardContent class="flex flex-col items-center gap-5 text-center">
        <BrandMark />
        <template v-if="failed">
          <Alert
            data-testid="callback-error"
            variant="destructive"
          >
            <AlertDescription>Logowanie nie powiodło się.</AlertDescription>
          </Alert>
          <Button
            data-testid="retry"
            @click="retry"
          >
            <LogIn aria-hidden="true" />
            Zaloguj ponownie
          </Button>
        </template>
        <p
          v-else
          role="status"
          class="flex items-center gap-2 text-sm text-muted-foreground"
        >
          <Loader2
            class="size-4 animate-spin"
            aria-hidden="true"
          />
          Logowanie…
        </p>
      </CardContent>
    </Card>
  </main>
</template>
