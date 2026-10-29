<script setup lang="ts">
import Button from "primevue/button";
import Message from "primevue/message";
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
    class="login-problem"
  >
    <Message
      data-testid="login-problem"
      severity="error"
    >
      {{ loginProblem }}
    </Message>
    <Button
      data-testid="login-retry"
      label="Zaloguj ponownie"
      icon="pi pi-sign-in"
      @click="signInAgain"
    />
  </div>
  <RouterView />
</template>

<style scoped>
.login-problem {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 1rem 1.5rem;
}
</style>
