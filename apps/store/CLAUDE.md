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

- `app/` – trasy App Router, w tym route handlery logowania w `app/auth/` i koszyka w `app/api/cart/`.
- `src/api/` – `client.ts` (jedyne miejsce rozmowy z API i jedyne miejsce wywołań `/auth/session` i `/api/cart/*`
  z przeglądarki) i wygenerowany `schema.d.ts` (nie edytuj ręcznie).
- `src/account/` – komponent konta w nagłówku (Client Component).
- `src/cart/` – BFF koszyka (wybór koszyka, ciasteczko `store_cart`, wywołania API, mapowanie błędów).
- `src/auth/` – BFF: OIDC, sesje w Redisie, odświeżanie tokenów, walidacja `returnTo`.
- `src/catalog/` – komponenty i ładowanie danych katalogu, w tym `formatPrice` (formatowanie kwoty z API, `pl-PL`).
- `src/components/ui/` – komponenty shadcn/ui.
- `src/styles/tokens.css` – jedyne miejsce tokenów wyglądu.

## Dane i API

- Dane wyłącznie z API przez wygenerowanego klienta (`src/api/client.ts`). ESLint blokuje globalny `fetch`
  poza tym plikiem.
- Żadnej logiki biznesowej: ceny, sumy, dostępność i filtrowanie liczy API, sklep je wyświetla.
- Ceny listy `loadCatalog` pobiera jednym wywołaniem `GET /api/v1/prices` po pobraniu strony produktów. Błąd cen nie psuje
  listy: produkty pokazują „Cena niedostępna”, a błąd trafia do logu serwera.
- Dostępność listy `loadCatalog` pobiera równolegle z cenami, jednym wywołaniem `GET /api/v1/availability` po pobraniu
  strony produktów. `ProductTile` pokazuje etykietę według `level` z API („Dostępny”, „Ostatnie sztuki”, „Niedostępny”),
  bez liczenia na froncie (API nie zwraca liczby sztuk). Błąd dostępności nie psuje listy ani cen: kafelki są bez
  etykiety, a błąd trafia do logu serwera.
- Sklep woła API tylko z serwera, pod adresem wewnętrznym.
- Domyślnie Server Components. Client Component tylko tam, gdzie potrzebna jest interakcja.
- `generateMetadata` i strona pobierają dane katalogu przez `loadCatalogForRequest` (`React.cache`), więc jedno wejście
  to jedno żądanie na endpoint. Metadane katalogu (`src/catalog/page-metadata.ts`) opierają się na `STORE_URL`.
- HTML katalogu nie zależy od sesji. To, co zależy od zalogowania, ładuje Client Component przez `/auth/session`.

## Koszyk

- Przeglądarka rozmawia z koszykiem wyłącznie przez BFF (`/api/cart`, `/api/cart/summary`, `/api/cart/items`,
  `/api/cart/items/[productId]`); API woła serwer sklepu. Route handlery są owinięte w `withCartDeps`
  (`src/cart/responses.ts`; zależności z `getCartDeps()` w `src/cart/deps.ts`, brak zależności daje 503 z `no-store`).
  Cała logika jest w `runCartCommand` (`src/cart/bff.ts`), testy podmieniają API dublerem z `src/cart/test-support.ts`.
- Koszyk wybiera sesja: zalogowany klient ma koszyk `/api/v1/me/cart` z tokenem z `freshAccessToken`, gość koszyk
  z ciasteczka `store_cart`. Ciasteczko zawiera id koszyka gościa podpisane (HMAC, `STORE_SESSION_SECRET`), nie
  szyfrowane; `HttpOnly`, `SameSite=Lax`, 30 dni odnawiane przy udanym zapisie. Niepoprawny podpis lub wartość spoza
  UUID jest usuwana jak brak ciasteczka.
- Żądania zmieniające (`POST`, `PATCH`, `DELETE`) wymagają `Origin` równego `STORE_URL`, a `POST` i `PATCH` także
  `Content-Type: application/json`; inaczej 403 bez wywołania API.
- `productId` spoza UUID w `/api/cart/items/[productId]` (PATCH i DELETE) dostaje 404 „Tej pozycji nie ma w koszyku.”
  bez wywołania API i bez ruszania ciasteczek (API też odpowiedziałoby 404, który wyglądałby jak zniknięty koszyk).
- 404 w koszyku gościa może znaczyć „koszyk zniknął”, „brak pozycji” albo „brak produktu”. BFF nie zgaduje po tekście
  `detail`. Odczyt i usunięcie pozycji: 404 oznacza koszyk, który zniknął, więc ciasteczko jest usuwane, a odpowiedzią
  jest pusty koszyk (bez sondy). Dodanie i zmiana pytają o `summary` koszyka: 404 oznacza koszyk, który zniknął
  (ciasteczko usuwane, dodanie zakłada nowy koszyk i powtarza się raz, zmiana zwraca 404 pozycji), w przeciwnym razie
  404 jest przekazywany.
- UI: `AddToCartButton` w `ProductTile` (tylko przy cenie i poziomie dostępności innym niż `out_of_stock`, przy braku
  etykiety dostępności widoczny), `CartBadge` w nagłówku (licznik z `/api/cart/summary`) i strona `/cart` z `noindex`
  (`CartPage`, Client Component ładujący `/api/cart`). Po każdej zmianie koszyka komponent ogłasza `itemCount` z
  odpowiedzi zdarzeniem (`src/cart/events.ts`), a licznik się aktualizuje. Front niczego nie liczy: kwoty, sumy i stany
  linii pochodzą z ostatniej odpowiedzi API, a odrzucona zmiana zostawia ostatni widok.
- Odpowiedzi: 200 z danymi API, 404/409/422 z `{ detail }` API, 401 (sesja się skończyła: usunięta z Redisa i
  ciasteczka), 403, 503 (chwilowy błąd API, Redisa lub Keycloaka; sesja zostaje). Wszystkie z `private, no-store`.

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
- Logika w komponentach (np. budowa `srcset`, aktywna kategoria), cały `src/auth/` i cały `src/cart/` (BFF, przyciski,
  strona koszyka) mają testy.
