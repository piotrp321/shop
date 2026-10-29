import { ref } from "vue";

/** A sign-in or sign-out problem the user has to see; shown by App.vue with a manual "sign in again" action. */
export const loginProblem = ref<string | null>(null);

export const SESSION_REJECTED_MESSAGE =
  "Nie udało się potwierdzić sesji: API odrzuciło token wydany przez serwer logowania. " +
  "Zaloguj się ponownie. Jeśli problem się powtarza, skontaktuj się z administratorem.";
export const LOGIN_SERVER_MESSAGE = "Nie udało się połączyć z serwerem logowania. Spróbuj ponownie za chwilę.";
export const LOGOUT_MESSAGE = "Nie udało się wylogować. Spróbuj ponownie za chwilę.";

export function reportLoginProblem(message: string): void {
  loginProblem.value = message;
}

export function clearLoginProblem(): void {
  loginProblem.value = null;
}
