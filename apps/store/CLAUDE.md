# CLAUDE.md – apps/store

Next.js (App Router), React, TypeScript, Tailwind CSS 4, shadcn/ui. Sklep dla klientów.
Zasady wspólne dla całego repo są w głównym `CLAUDE.md`, opis domeny i kierunek wyglądu w `docs/vision.md`.

## Wersja Next.js

Ta wersja Next.js ma zmiany łamiące: API, konwencje i struktura plików mogą się różnić od tego, co znasz.
Przed pisaniem kodu przeczytaj odpowiedni przewodnik w `node_modules/next/dist/docs/` (w kontenerze `store`,
`make -C apps/store shell`) i zwracaj uwagę na ostrzeżenia o wycofywanych funkcjach.
`next dev` nie generuje `AGENTS.md`, bo `next.config.ts` ma `agentRules: false`. Nie usuwaj tej opcji.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/store <cel>`:
- `check` – ESLint i `tsc --noEmit` (po `next typegen`, który generuje typy tras do `.next/types`)
- `test` – wszystkie testy (Vitest), także integracyjne na usługach stosu
- `test-unit` – tylko testy jednostkowe, bez usług
- `shell` – powłoka w kontenerze `store`

Klienta API generuje `make api-client` z katalogu głównego.

## Struktura

- `app/` – trasy App Router, w tym route handlery logowania w `app/auth/`.
- `src/api/` – `client.ts` (jedyne miejsce rozmowy z API i jedyne wywołanie `/auth/session` z przeglądarki)
  i wygenerowany `schema.d.ts` (nie edytuj ręcznie).
- `src/account/` – komponent konta w nagłówku (Client Component).
- `src/auth/` – BFF: OIDC, sesje w Redisie, odświeżanie tokenów, walidacja `returnTo`.
- `src/catalog/` – komponenty i ładowanie danych katalogu.
- `src/components/ui/` – komponenty shadcn/ui.
- `src/styles/tokens.css` – jedyne miejsce tokenów wyglądu.

## Dane i API

- Dane wyłącznie z API przez wygenerowanego klienta (`src/api/client.ts`). ESLint blokuje globalny `fetch`
  poza tym plikiem.
- Żadnej logiki biznesowej: ceny, sumy, dostępność i filtrowanie liczy API, sklep je wyświetla.
- Sklep woła API tylko z serwera, pod adresem wewnętrznym.
- Domyślnie Server Components. Client Component tylko tam, gdzie potrzebna jest interakcja.
- HTML katalogu nie zależy od sesji. To, co zależy od zalogowania, ładuje Client Component przez `/auth/session`.

## Logowanie

- Tokeny OIDC nigdy nie trafiają do przeglądarki. Są tylko w sesji w Redisie, a ciasteczko zawiera wyłącznie
  id sesji podpisane kluczem `STORE_SESSION_SECRET`.
- Route handlery `/auth/*` są owinięte w `withAuthDeps` (`src/auth/responses.ts`), które daje im zależności
  (Redis, OIDC, API) z `getAuthDeps()` w `src/auth/deps.ts`, a przy ich braku odpowiada 503 z `no-store`. Testy
  podmieniają zależności dublerami z `src/auth/test-support.ts`.
- Nie loguj obiektów błędów openid-client (ich `cause` zawiera odpowiedzi z tokenami). Użyj `logFailure`.
- `returnTo` przechodzi zawsze przez `sanitizeReturnTo`.
- Odpowiedzi `/auth/*` mają `Cache-Control: private, no-store`.

## Wygląd

- Komponenty na shadcn/ui. Nowy komponent dodawaj CLI shadcn w kontenerze (`make -C apps/store shell`,
  potem `npx shadcn@latest add <komponent>`) i od razu dopasuj go do tokenów.
- Kolory, typografia, promienie i cienie wyłącznie z tokenów w `src/styles/tokens.css`. Żadnych kolorów
  „na sztywno”: bez wartości arbitralnych (`bg-[#…]`, `text-[rgb(…)]`), bez kolorów w `style` i bez klas spoza
  tokenów (`bg-black`, `text-red-500`). Domyślna paleta Tailwinda jest wyłączona, a ESLint to pilnuje.
- Komponenty używają warstwy semantycznej (zmienne motywu shadcn/ui: `background`, `foreground`, `primary`,
  `muted`, `border` i pozostałe), nigdy palety bezpośrednio.
- Obecny motyw jest tymczasowy i neutralny. Docelowy wygląd powstaje w kroku „Projekt wizualny sklepu”.

## Obrazy

- Zdjęcia wyłącznie z wariantów zwróconych przez API (`image.variants`), przez komponent `ProductPhoto`.
  Sklep nie podpisuje adresów i nie dodaje rozmiarów. Nowy rozmiar dopisuje się do listy w API.
- `<Image>` z `next/image` bez loadera wariantów nie zadziała: globalny loader celowo rzuca błąd.

## Komponenty i układy z vercel/shop

- Z github.com/vercel/shop (licencja MIT, „Copyright (c) 2025 Vercel, Inc.”) wolno przenosić komponenty i układy.
- Każdy przeniesiony plik ma w nagłówku komentarz `// Adapted from vercel/shop (MIT): <ścieżka w repo źródłowym>`.
  `THIRD_PARTY_NOTICES.md` w tym katalogu zawiera pełny tekst licencji MIT i listę przeniesionych plików.
- Przy przenoszeniu: dane tylko z naszego API przez wygenerowanego klienta, bez kodu, typów i zapytań Shopify;
  obrazy przez `ProductPhoto` zamiast `next/image` z CDN Shopify; kolory i odstępy przepisane na nasze tokeny.

## Testy

- Vitest i Testing Library. `*.test.ts(x)` to testy jednostkowe bez usług, `*.integration.test.ts` działają
  na usługach stosu (Redis).
- Logika w komponentach (np. budowa `srcset`, aktywna kategoria) i cały `src/auth/` mają testy.
