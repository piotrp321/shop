<script setup lang="ts">
import Button from "primevue/button";
import Tag from "primevue/tag";
import { useAuthStore } from "@/stores/auth";

const auth = useAuthStore();
</script>

<template>
  <header class="topbar">
    <strong class="title">Panel administracyjny</strong>
    <div class="user">
      <template v-if="auth.staff">
        <span data-testid="username">{{ auth.staff.username }}</span>
        <Tag
          v-for="role in auth.staff.roles"
          :key="role"
          data-testid="role-tag"
          severity="secondary"
          :value="role"
        />
      </template>
      <Button
        label="Wyloguj"
        icon="pi pi-sign-out"
        severity="secondary"
        text
        @click="auth.logout()"
      />
    </div>
  </header>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1.5rem;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
}

.user {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
</style>
