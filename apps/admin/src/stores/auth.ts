import { defineStore } from "pinia";
import { ref } from "vue";
import { getApiClient } from "@/api/client";
import type { components } from "@/api/schema";
import { getUserManager } from "@/auth/userManager";

export type StaffMe = components["schemas"]["StaffMeResponse"];

export const useAuthStore = defineStore("auth", () => {
  /** The signed-in staff member as the API reports them; the roles come from the API, never from the token. */
  const staff = ref<StaffMe | null>(null);
  const error = ref<string | null>(null);

  async function load(): Promise<void> {
    error.value = null;
    try {
      const { data } = await getApiClient().GET("/api/v1/admin/me");
      if (data) {
        staff.value = data;
      } else {
        error.value = "Nie udało się pobrać danych pracownika.";
      }
    } catch {
      error.value = "Nie udało się pobrać danych pracownika.";
    }
  }

  async function logout(): Promise<void> {
    await getUserManager().signoutRedirect();
  }

  return { staff, error, load, logout };
});
