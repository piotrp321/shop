# CLAUDE.md – apps/admin

Vue 3 (Composition API), Vite, TypeScript, Pinia, Vue Router, PrimeVue 4 (preset Aura), PrimeIcons.
Panel administracyjny dla pracowników. Zasady wspólne dla całego repo są w głównym `CLAUDE.md`,
opis domeny w `docs/vision.md`.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/admin <cel>`:
- `check` – ESLint i `vue-tsc --noEmit`
- `test` – wszystkie testy (Vitest)
- `shell` – powłoka w kontenerze `admin`

Klienta API generuje `make api-client` z katalogu głównego.

## Struktura docelowa

Na razie istnieją `src/router/` (bez guardu), `src/views/` i `src/primevue.ts`. Pozostałe katalogi i elementy
(`src/api/`, `src/auth/`, `src/stores/`, `src/layout/`, `src/dashboard/`, `src/composables/`, guard, `oidc-client-ts`,
`InMemoryWebStorage`, `useIdleLogout`) są docelowe i powstają w kolejnych zadaniach.


- `src/api/` – `client.ts` (jedyne miejsce rozmowy z API) i wygenerowany `schema.d.ts` (nie edytuj ręcznie).
- `src/auth/` – logowanie OIDC (`oidc-client-ts`), tokeny w pamięci.
- `src/stores/` – store'y Pinia (np. `auth` z pracownikiem z `GET /api/v1/admin/me`).
- `src/router/` – trasy i guard wymagający zalogowania.
- `src/layout/` – menu boczne i topbar.
- `src/views/` – widoki przypisane do tras.
- `src/dashboard/` – komponenty i ładowanie danych dashboardu.
- `src/composables/` – współdzielona logika reaktywna (np. `useIdleLogout`).
- `src/primevue.ts` – konfiguracja PrimeVue i motywu Aura.

## Dane i API

- Dane wyłącznie z API przez wygenerowanego klienta (`src/api/client.ts`). ESLint blokuje globalny `fetch`
  (także `globalThis.fetch`, `window.fetch`, `self.fetch`) poza tym plikiem.
- Żadnej logiki biznesowej: ceny, sumy, dostępność i liczniki liczy API, panel je wyświetla.
- Role i uprawnienia pochodzą z API (`/me`, pola odpowiedzi), nigdy z dekodowania tokena. Front tylko ukrywa
  elementy interfejsu. Autoryzację egzekwuje API.

## Logowanie

- Tokeny OIDC trzymaj wyłącznie w pamięci (`InMemoryWebStorage`). Nigdy w `localStorage`, `sessionStorage`
  ani w ciasteczkach.

## Wygląd

- Komponenty PrimeVue zamiast własnych odpowiedników (`Button`, `DataTable`, `Tag`, `Message`, `Skeleton`
  i pozostałe). Własny komponent powstaje tylko wtedy, gdy PrimeVue nie ma odpowiednika.
- Ikony z PrimeIcons. Kolory i odstępy z motywu Aura, bez kolorów „na sztywno”.
- Teksty interfejsu po polsku.

## Testy

- Vitest, `@vue/test-utils` i jsdom. Wszystkie testy to testy jednostkowe bez usług stosu.
- Testy leżą w `tests/` (reguły lintera w `tests/lint/`) lub obok kodu jako `*.test.ts`.
- Logika w composables i store'ach (np. `useIdleLogout`, obsługa 401, guard routera) ma testy.
- Test reguły ESLint sprawdza identyfikator reguły i liczbę błędów, nie tylko to, że coś zostało zgłoszone.
