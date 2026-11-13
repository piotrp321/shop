<script setup lang="ts">
import { LogIn } from "lucide-vue-next";
import BrandMark from "@/components/BrandMark.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { clearLoginProblem, LOGIN_SERVER_MESSAGE, loginProblem, reportLoginProblem } from "@/auth/loginProblem";
import { clearSigninAttempt } from "@/auth/signinAttempt";
import { getUserManager } from "@/auth/userManager";

// A manual sign-in starts a new login cycle, so the one-shot marker of the previous one no longer applies.
async function signInAgain() {
  clearLoginProblem();
  clearSigninAttempt();
  try {
    await getUserManager().signinRedirect({ state: { returnTo: `${location.pathname}${location.search}` } });
  } catch {
    reportLoginProblem(LOGIN_SERVER_MESSAGE);
  }
}
</script>

<template>
  <div
    v-if="loginProblem"
    class="flex justify-center bg-background px-4 py-10"
  >
    <Card
      data-testid="login-problem-card"
      class="w-full max-w-md"
    >
      <CardContent class="flex flex-col items-start gap-5">
        <BrandMark />
        <Alert
          data-testid="login-problem"
          variant="destructive"
        >
          <AlertDescription>{{ loginProblem }}</AlertDescription>
        </Alert>
        <Button
          data-testid="login-retry"
          @click="signInAgain"
        >
          <LogIn aria-hidden="true" />
          Zaloguj ponownie
        </Button>
      </CardContent>
    </Card>
  </div>
  <RouterView />
</template>
