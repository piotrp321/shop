# Biblioteka komponentów panelu: Tailwind CSS 4 i shadcn-vue

## Kontekst

Panel (`apps/admin`) używał PrimeVue z domyślnym motywem Aura. Wyglądał jak niedostosowany szablon, miał kontrasty poniżej
WCAG AA, a defekty nazw dostępnych siedziały w wewnętrznych elementach komponentów biblioteki (`TreeSelect`, `Dialog`),
których nie dało się poprawić bez obejść. Zakresy z daszkiem w `package.json` wciągnęły `@primeuix/themes` 3.1.0 i
`primeicons` 8.0.2, które nie są już na licencji MIT. Sklep (`apps/store`) używa Tailwind CSS 4 i shadcn/ui, więc oba fronty
mogą dzielić rodzaj komponentów i tokenów. Specyfikacja: `docs/features/admin-design/spec.md`.

## Decyzja

- Panel używa **Tailwind CSS 4**, komponentów **shadcn-vue** (kod w repo, `apps/admin/src/components/ui/`, na prymitywach
  **Reka UI**) i ikon **Lucide**. Komponenty są naszym kodem, zmieniamy je jak każdy inny; teksty dostępne mają po polsku
  („Zamknij”), a kolory tylko z tokenów.
- Wygląd definiuje jeden plik `apps/admin/src/styles/tokens.css` (`@theme` z `--color-*: initial`, więc klas domyślnej
  palety Tailwinda nie ma). Kolor „na sztywno”, statyczny `style` i bloki `<style>` blokuje ESLint; wyjątkiem są pliki
  z listy `LEGACY_FILES` (jawne ścieżki ekranów jeszcze na PrimeVue). Kontrast każdej pary tokenów sprawdza
  `tests/tokens.test.ts`.
- Krój **Nunito** jest w repo jako pliki WOFF2 (licencja SIL OFL 1.1, wpis w `apps/admin/THIRD_PARTY_NOTICES.md`), nie jako
  pakiet npm i nie z hosta zewnętrznego.
- PrimeVue, `@primeuix/themes` i `primeicons` zostają na czas migracji i działają obok. Usuwa je ADMIN-SCREENS (#462),
  gdy znikną ostatnie ekrany na PrimeVue.
- `@tanstack/vue-table` nie wchodzi: listy są stronicowane w API, a model tabeli nic nie daje przed pierwszym sortowaniem
  i zaznaczaniem (ADMIN-FEATURES).

### Pakiety i licencje

Wersje rozwiązane w `apps/admin/package-lock.json`; licencja każdej to pole `license` pakietu w tym pliku.

| Pakiet | Rodzaj | Wersja | Licencja |
|---|---|---|---|
| `tailwindcss` | dev | 4.3.5 | MIT |
| `@tailwindcss/vite` | dev | 4.3.5 | MIT |
| `tw-animate-css` | dev | 1.4.0 | MIT |
| `eslint-plugin-better-tailwindcss` | dev | 4.11.0 | MIT |
| `reka-ui` | runtime | 2.11.0 | MIT |
| `class-variance-authority` | runtime | 0.7.1 | Apache-2.0 |
| `clsx` | runtime | 2.1.1 | MIT |
| `tailwind-merge` | runtime | 3.7.0 | MIT |
| `@vueuse/core` | runtime | 15.0.0 | MIT |
| `lucide-vue-next` | runtime | 1.0.0 | ISC |
| `vue-sonner` | runtime | 2.0.9 | MIT |

Nowy pakiet z innej licencji niż MIT, ISC albo Apache-2.0 wymaga zgody właściciela.

## Uzasadnienie

- Ten sam rodzaj komponentów i tokenów co sklep: jedna wiedza, jedna reguła koloru, jedna lista rozmiarów i odstępów.
- Pełna kontrola nad dostępnością i wyglądem: kod komponentów jest w repo, więc nazwę dostępną, fokus i kontrast poprawia
  się w miejscu, a nie obejściem nad biblioteką.
- Licencje rozwiązanych wersji są dopuszczalne i sprawdzone w lockfile; wiadomo, co panel wciąga.

## Konsekwencje

- Do ADMIN-SCREENS w panelu żyją dwie biblioteki. Style PrimeVue są poza warstwami CSS Tailwinda, więc na starych ekranach
  wygrywają z preflightem; ekrany niezmigrowane wyglądają jak dziś.
- Komponenty Reka UI ustawiają style inline przy pozycjonowaniu, więc `style-src` w nagłówku CSP panelu dalej nie jest
  ograniczony (komentarz w `apps/admin/docker/security-headers.conf`).
- Testy komponentów w jsdom potrzebują atrap API przeglądarki (`tests/setup/jsdom-mocks.ts`).
- Aktualizacja komponentów shadcn-vue to zwykła zmiana kodu w `src/components/ui/`, bez automatycznej synchronizacji.
