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

- `app/` – trasy App Router, w tym route handlery logowania w `app/auth/`, koszyka w `app/api/cart/`, kasy
  w `app/api/checkout/` (płatność zamówienia w `app/api/checkout/payment/`) i rewalidacji ISR w `app/api/revalidate/`.
- `src/api/` – `client.ts` (jedyne miejsce rozmowy z API i jedyne miejsce wywołań `/auth/session`, `/api/cart/*`,
  `/api/checkout` i `/api/checkout/payment` z przeglądarki) i wygenerowany `schema.d.ts` (nie edytuj ręcznie).
- `src/account/` – komponent konta w nagłówku (Client Component).
- `src/cart/` – BFF koszyka (wybór koszyka, ciasteczka `store_cart` i `store_cart_notice`, scalanie koszyka gościa
  w `merge.ts`, wywołania API, mapowanie błędów) oraz komponenty koszyka: `AddToCartButton`, `CartBadge` i `CartPage`.
- `src/checkout/` – BFF kasy (złożenie zamówienia i rozpoczęcie płatności, ciasteczko `store_order_confirmation`),
  BFF płatności zamówienia (`payment-bff.ts`), wywołania API płatności (`payment-api.ts`), strona kasy `CheckoutPage`
  i strona potwierdzenia `PaymentConfirmation`.
- `src/auth/` – BFF: OIDC, sesje w Redisie, odświeżanie tokenów, walidacja `returnTo`.
- `src/catalog/` – komponenty i ładowanie danych katalogu, w tym `formatPrice` (formatowanie kwoty z API, `pl-PL`),
  `load-product.ts` (odczyty strony produktu), `ProductDetailsView`, `VariantChoice` z `variant-choice.ts` (wybór wariantu),
  `product-metadata.ts` i `product-json-ld.ts`.
- `app/p/[slug]/` – strona produktu (ISR).
- `src/components/ui/` – komponenty shadcn/ui.
- `src/styles/tokens.css` – jedyne miejsce tokenów wyglądu.

## Dane i API

- Dane wyłącznie z API przez wygenerowanego klienta (`src/api/client.ts`). ESLint blokuje globalny `fetch`
  poza tym plikiem.
- Żadnej logiki biznesowej: ceny, sumy, dostępność i filtrowanie liczy API, sklep je wyświetla.
- Oferty listy (warianty z etykietami, cena „od”, poziom produktu i wariantów, `purchasable`) `loadCatalog` pobiera jednym
  wywołaniem `GET /api/v1/product-offers` po pobraniu strony produktów. Błąd ofert nie psuje listy: kafelki są bez ceny
  („Cena niedostępna”), bez etykiety dostępności, bez wyboru wariantu i bez przycisku, a błąd trafia do logu serwera.
  `ProductTile` pokazuje etykietę według `level` z API („Dostępny”, „Ostatnie sztuki”, „Niedostępny”), bez liczenia na
  froncie (API nie zwraca liczby sztuk); liczba wariantów, ich kolejność i etykiety pochodzą tylko z oferty.
- Sklep woła API tylko z serwera, pod jednym z dwóch adresów w sieci compose:
  - `API_CATALOG_URL` (`http://varnish`): odczyty katalogu `loadCatalog` (lista, kategorie, oferty) oraz `loadProduct` (szczegóły i oferta
    strony produktu) idą przez Varnish,
    który trzyma je według `Cache-Control` API; brak zmiennej to błąd, bez obejścia na adres bezpośredni;
  - `API_INTERNAL_URL` (`http://api`): koszyk, kasa, scalanie koszyka i logowanie (`/api/v1/me`) idą do API
    bezpośrednio (klient z `getAuthDeps()`), nigdy przez Varnish.
- Klient API (`createApiClient`) przyjmuje `tags`: trafiają do fetch jako `next: { tags }` (przez `requestInitExt`
  openapi-fetch, bo biblioteka wywołuje fetch gotowym `Request`). Tylko tak odczyty dostają tagi cache Next.js.
- Domyślnie Server Components. Client Component tylko tam, gdzie potrzebna jest interakcja.
- `generateMetadata` i strona pobierają dane katalogu przez `loadCatalogForRequest` (`React.cache`), więc jedno wejście
  to jedno żądanie na endpoint. Metadane katalogu (`src/catalog/page-metadata.ts`) opierają się na `STORE_URL`.
- HTML katalogu nie zależy od sesji. To, co zależy od zalogowania, ładuje Client Component przez `/auth/session`.

## Strona produktu `/p/[slug]`

- ISR: `revalidate = 3600` (siatka bezpieczeństwa), `generateStaticParams` zwraca `[]` (strony powstają przy pierwszym
  wejściu, nie przy buildzie). HTML nie zależy od sesji. Cache ISR istnieje tylko w buildzie produkcyjnym
  (`STORE_MODE=production`); `next dev` renderuje przy każdym żądaniu.
- Dane (`loadProduct` w `src/catalog/load-product.ts`, wspólne dla `generateMetadata` i strony przez `React.cache`
  jako `loadProductForRequest`): `GET /api/v1/products/{slug}`, potem `GET /api/v1/product-offers?productId=<id>`, oba
  przez `API_CATALOG_URL`. Tagi cache Next to te same nazwy, które API wystawia w `xkey`: szczegóły `catalog`, oferta
  `catalog` i `product-<id>` (UUID małymi literami). Strona dziedziczy sumę tagów odczytów, więc `revalidateTag`
  tych nazw unieważnia jej HTML.
- 404 szczegółów → `notFound()` (strona 404 z `noindex`; `generateMetadata` zwraca wtedy `{}`). Każdy inny błąd
  szczegółów albo oferty (status, sieć, nieoczekiwana treść, brak oferty produktu) to wyjątek, czyli `app/error.tsx`,
  którego ISR nie cache'uje; strona bez ceny nigdy nie powstaje.
- `ProductDetailsView` (Server Component): ścieżka „Wszystkie produkty” › kategorie (linki `/?category=<id>`), `h1`,
  zdjęcia jedno pod drugim przez `ProductPhoto` (pierwsze `priority`, bez zdjęć `PhotoPlaceholder`), krótki opis,
  cena „od X zł” (gdy `hasPriceRange`) albo „X zł” i etykieta `level` produktu; produkt bez wariantu możliwego do kupienia
  (`fromGross = null`): „Niedostępny” bez ceny, wyboru i przycisku. Wartości pochodzą z oferty, front niczego nie liczy.
- Wybór wariantu (`VariantChoice`, Client Component osadzony w `ProductDetailsView`; reguły w czystych funkcjach
  `variant-choice.ts`, z testami): jedna grupa przycisków (`fieldset`, `aria-pressed`) na oś z `variantAxes`, wartości
  w kolejności pierwszego wystąpienia w `variants` szczegółów. Wariant ze szczegółów jest możliwy do kupienia tylko, gdy
  oferta ma go (po `variantId`) z `purchasable = true`; cena, poziom i `purchasable` pochodzą z oferty, front tylko
  dopasowuje wybrane wartości do wariantu. Dostępność wartości jest hierarchiczna, w kolejności osi: wartość pierwszej
  osi jest wyłączona (z dopiskiem „niedostępny”) tylko wtedy, gdy żaden wariant możliwy do kupienia jej nie ma; wartość
  kolejnej osi liczy się względem wyborów osi wcześniejszych (wybory osi późniejszych się nie liczą); oś późniejsza jest
  wyłączona (bez dopiska), dopóki wcześniejsze nie mają wyboru. Zmiana wartości osi czyści wybory osi późniejszych, które
  przestały pasować, więc wybór nigdy się nie blokuje. Kliknięcie wybranej wartości niczego nie zmienia (bez odznaczania).
  Wariant możliwy do kupienia, jeśli jest jedyny (także spośród wielu), jest wybrany od pierwszego renderu (stan
  początkowy zależy tylko od danych, więc HTML ISR jest taki sam dla każdego). Produkt z jednym wariantem pokazuje
  wartości osi jako tekst („Kolor: czarne”), bez wyboru. Do pełnego wyboru strona pokazuje cenę i poziom produktu
  (`children` z serwera), po nim cenę i poziom wybranego wariantu. `AddToCartButton` jest wyłączony do pełnego wyboru
  i dodaje 1 sztukę wybranego wariantu (ilość zmienia się w koszyku). Wariantu nie ma w URL.
- Metadane (`buildProductMetadata`): tytuł `<nazwa> | Rytm`, opis = krótki opis (bez opisu: brak), kanoniczny
  `<STORE_URL>/p/<slug>`, Open Graph (tytuł, opis, adres, obraz = pierwsze zdjęcie w największej szerokości; bez zdjęć
  bez obrazu).
- JSON-LD (`product-json-ld.ts`, dwa `<script type="application/ld+json">`): `Product` z `Offer` tylko dla wariantów
  z ceną (`gross` ≠ `null`; `name` = etykieta, `price` w złotych z kropką, `availability` z `level`) i `BreadcrumbList`
  z adresami bezwzględnymi. `serializeJsonLd` zamienia każdy `<` na ucieczkę Unicode, więc tekst produktu nie zamknie
  elementu `script`.
- Kafelek listy (`ProductTile`): nazwa i zdjęcie to linki do `/p/<slug>`; link zdjęcia jest `aria-hidden` i poza
  kolejnością tabulatora, nazwa jest linkiem dla klawiatury i czytników.

## Rewalidacja (`POST /api/revalidate`)

- Woła ją tylko worker API (`StorefrontCache`) po purge Varnisha: po zmianie ceny albo poziomu dostępności z tagiem
  `product-<id>`, w `storefront:cache:purge-all` (koniec `make seed`) z tagiem `catalog`. Przeglądarka jej nie woła.
- `Authorization: Bearer <STORE_REVALIDATE_SECRET>` (z `.env.local`), porównany w stałym czasie (skróty SHA-256
  i `timingSafeEqual`, więc nie zdradza też długości); treść JSON `{ tags }`, 1–100 tagów, każdy `catalog` albo
  `product-<uuid małymi literami>` (słownik tagów API).
- Każdy tag: `revalidateTag(tag, { expire: 0 })`: następne wejście czeka na świeży render zamiast dostać starą stronę
  (P5). Odpowiedź 200 `{ revalidated: <liczba tagów> }`.
- Kolejność sprawdzeń: brak sekretu w środowisku 503, brak lub zły sekret 401, `Content-Type` inny niż
  `application/json`, zły JSON albo zła lista tagów 400 (bez rewalidacji). Wszystkie odpowiedzi `private, no-store`.
  Sekret nie trafia do logów.

## Koszyk

- Przeglądarka rozmawia z koszykiem wyłącznie przez BFF (`/api/cart`, `/api/cart/summary`, `/api/cart/items`,
  `/api/cart/items/[variantId]`); API woła serwer sklepu. Route handlery są owinięte w `withCartDeps`
  (`src/cart/responses.ts`; zależności z `getCartDeps()` w `src/cart/deps.ts`, brak zależności daje 503 z `no-store`).
  Cała logika jest w `runCartCommand` (`src/cart/bff.ts`), testy podmieniają API dublerem z `src/cart/test-support.ts`.
- Koszyk wybiera sesja: zalogowany klient ma koszyk `/api/v1/me/cart` z tokenem z `freshAccessToken`, gość koszyk
  z ciasteczka `store_cart`. Ciasteczko zawiera id koszyka gościa podpisane (HMAC, `STORE_SESSION_SECRET`), nie
  szyfrowane; `HttpOnly`, `SameSite=Lax`, 30 dni odnawiane przy udanym zapisie. Niepoprawny podpis lub wartość spoza
  UUID jest usuwana jak brak ciasteczka (także przy sesji, wtedy bez scalania).
- Scalanie koszyka gościa (`mergeGuestCart` i `applyGuestCartMerge` w `src/cart/merge.ts`, jedno miejsce dla BFF
  i `/auth/callback`): `/auth/callback` po utworzeniu sesji (token z właśnie uzyskanych tokenów) i BFF przed każdą
  operacją przy sesji wołają `POST /api/v1/me/cart/merge`, gdy jest `store_cart`. Każda odpowiedź 200 (`merged`,
  `already_merged`, `nothing_to_merge`) usuwa `store_cart`. Błąd scalenia zostawia `store_cart` (następne żądanie
  koszyka ponawia scalenie) i trafia do `logFailure`; w BFF operacja idzie dalej na koszyku klienta, w callbacku
  logowanie kończy się normalnie. 401 scalenia w BFF kończy sesję jak każdy 401 API (odpowiedź 401, `store_cart`
  zostaje), token, którego nie da się odświeżyć, daje 401 bez scalania, a chwilowy błąd odświeżenia 503 bez scalania.
- `truncated` scalenia ustawia `store_cart_notice`: znacznik `merge_truncated` podpisany jak `store_cart`, `HttpOnly`,
  `SameSite=Lax`, `Path=/`, `Max-Age` 5 minut. `GET /api/cart/summary` z odpowiedzią 200 zwraca `notice:
  "merge_truncated"` raz (z poprawnie podpisanego ciasteczka albo ze scalenia w tym samym żądaniu) i usuwa ciasteczko;
  inaczej `notice: null`. `CartBadge` pokazuje wtedy obok linku „Część produktów z koszyka gościa nie zmieściła się
  w koszyku”.
- Żądania zmieniające (`POST`, `PATCH`, `DELETE`) wymagają `Origin` równego `STORE_URL`, a `POST` i `PATCH` także
  `Content-Type: application/json`; inaczej 403 bez wywołania API.
- `variantId` spoza UUID w `/api/cart/items/[variantId]` (PATCH i DELETE) dostaje 404 „Tej pozycji nie ma w koszyku.”
  bez wywołania API i bez ruszania ciasteczek (API też odpowiedziałoby 404, który wyglądałby jak zniknięty koszyk).
- 404 w koszyku gościa może znaczyć „koszyk zniknął”, „brak pozycji” albo „brak wariantu”. BFF nie zgaduje po tekście
  `detail`. Odczyt i usunięcie pozycji: 404 oznacza koszyk, który zniknął, więc ciasteczko jest usuwane, a odpowiedzią
  jest pusty koszyk (bez sondy). Dodanie i zmiana pytają o `summary` koszyka: 404 oznacza koszyk, który zniknął
  (ciasteczko usuwane, dodanie zakłada nowy koszyk i powtarza się raz, zmiana zwraca 404 pozycji), w przeciwnym razie
  404 jest przekazywany.
- Zapisy koszyka z przeglądarki (`addCartItem`, `changeCartItemQuantity`, `removeCartItem` w `src/api/client.ts`) idą
  jeden po drugim we wszystkich kartach: kolejka na poziomie modułu (`queueWrite`) ustala kolejność zapisów karty
  (następny startuje po zakończeniu poprzedniego, także po błędzie), a każdy zapis z kolejki trzyma blokadę Web Locks
  API `store-cart-write` (`navigator.locks.request`, `exclusive`), gdy przeglądarka ją ma. Dzięki temu równoczesne
  pierwsze dodania gościa bez `store_cart` z kilku kart nie zakładają kilku koszyków. Odrzucony `request` daje `failed`
  bez wysłania żądania; funkcje zapisu nigdy nie odrzucają obietnicy. Bez `navigator.locks` (kontekst bez HTTPS poza
  `localhost`) działa sama kolejka karty i ta gwarancja obejmuje tylko jedną kartę. Odczyty nie czekają ani w kolejce,
  ani na blokadę. Testy ustawiają `navigator` przez `vi.stubGlobal` (Node ma własne `navigator.locks`).
- UI: `ProductTile` (Server Component: nazwa, zdjęcie, cena „od X zł” przy `hasPriceRange`, poziom produktu; produkt
  bez wariantu możliwego do kupienia, `fromGross = null`: „Niedostępny” bez ceny, listy i przycisku) z częścią kliencką
  `TileOffer`: produkt z jednym wariantem ma przycisk `AddToCartButton` dodający ten wariant, produkt z ≥ 2 wariantami
  listę wariantów (etykieta: nazwy osi) bez wyboru domyślnego, z wariantami `purchasable = false` opisanymi
  „niedostępny” i wyłączonymi; po wyborze kafelek pokazuje cenę i poziom wybranego wariantu, a przycisk (wyłączony do
  wyboru) dodaje 1 sztukę wybranego wariantu. `CartBadge` w nagłówku (licznik z `/api/cart/summary`) i strona `/cart` z `noindex`
  (`CartPage`, Client Component ładujący `/api/cart`), linia z nazwą produktu i etykietą wariantu, pod podsumowaniem
  przycisk „Przejdź do kasy” (link do `/checkout`; przy `hasExcludedLines` nieaktywny z tekstem „Usuń albo popraw
  oznaczone pozycje, żeby złożyć zamówienie.”). Po każdej zmianie koszyka komponent ogłasza `itemCount` z
  odpowiedzi zdarzeniem (`src/cart/events.ts`), a licznik się aktualizuje. Front niczego nie liczy: kwoty, sumy i stany
  linii pochodzą z ostatniej odpowiedzi API, a odrzucona zmiana zostawia ostatni widok.
- Odpowiedzi: 200 z danymi API, 404/409/422 z `{ detail }` API, 401 (sesja się skończyła: usunięta z Redisa i
  ciasteczka), 403, 503 (chwilowy błąd API, Redisa lub Keycloaka; sesja zostaje). Wszystkie z `private, no-store`.

## Kasa

- `POST /api/checkout` (`runCheckout` w `src/checkout/bff.ts`, owinięty w `withCartDeps`, testy na dublerze API
  z `src/cart/test-support.ts`) składa zamówienie całego koszyka i rozpoczyna jego płatność. Te same zabezpieczenia
  co zapisy koszyka (`Origin` równy `STORE_URL` i `Content-Type: application/json`, inaczej 403 bez API). Nagłówek
  `Idempotency-Key` wybiera przeglądarka; jego brak albo treść, która nie jest obiektem JSON, daje 422
  „Nieprawidłowe dane.” bez API. BFF przekazuje klucz do API bez zmian, także do rozpoczęcia płatności. Przeglądarka
  woła BFF przez `placeOrder(input, idempotencyKey)` w `src/api/client.ts` (wynik: `ok` z numerem i `redirectUrl`,
  `invalid` z `violations`, `conflict`, `not_found`, `unauthorized`, `failed`; funkcja nigdy nie odrzuca obietnicy).
- Dostawca płatności (`provider` w treści) jest sprawdzany jako pierwszy, przed scaleniem koszyka i złożeniem, z listą
  `GET /api/v1/payment-providers` (`listPaymentProviders` w `src/checkout/payment-api.ts`): brak albo kod spoza listy
  to 422 z naruszeniem pola `provider` („Wybierz sposób płatności.”), lista niedostępna to 503.
- Koszyk wybiera sesja, jak w BFF koszyka: klient `POST /api/v1/me/orders` z tokenem z `freshAccessToken` po scaleniu
  `store_cart` (`mergeGuestCart`), gość `POST /api/v1/orders` z `cartId` z `store_cart`. Bez `store_cart` (albo
  z niepoprawnym podpisem, wtedy ciasteczko jest usuwane) odpowiedź to 409 „Koszyk jest pusty.” bez API.
- Do API trafiają tylko pola kontraktu (`pickFields` w `src/checkout/api.ts`: gość `email`, `fullName`, `phone`,
  `street`, `postalCode`, `city`, `expectedTotalGross`, klient bez `email`), z wartościami, jakie przysłała przeglądarka:
  sprawdza je API. `cartId` pochodzi wyłącznie z ciasteczka; ceny, linie i inne pola przeglądarki są pomijane.
- Sukces (201 albo 200 przy powtórzeniu z tym samym kluczem): ciasteczko `store_order_confirmation` z `{orderId,
  number, email, totalGross, currency, accessToken}` z `OrderView` (gość ma `accessToken`, klient `null`; JSON
  w base64url, podpisany jak `store_cart`, `HttpOnly`, `SameSite=Lax`, `Path=/`, `Max-Age` 1 godzina; stare ciasteczko
  bez `orderId` jest niepoprawne). Potem BFF rozpoczyna płatność tym samym `Idempotency-Key` (gość `Order-Access-Token`,
  klient `/me/...` z tokenem sesji) i odpowiada `{ number, redirectUrl }`. Każdy błąd rozpoczęcia płatności (także 409
  `PaymentIdempotencyKeyReused`, gdy klient zmienił dostawcę przy ponowieniu z tym samym kluczem złożenia) daje
  `redirectUrl: null` i wpis w logu bez tokenu: zamówienie stoi, a klient płaci ze strony potwierdzenia. `store_cart`
  zostaje (koszyk jest pusty). `POST /auth/logout` usuwa to ciasteczko razem z sesją (zawiera e-mail zamówienia
  i token dostępu).
- Błędy: 404, 409 i 422 API z `{ detail, violations }`, 409 także z `type` bez zmian; własne 409 bez `store_cart` ma
  `type` `CartEmpty` (`src/checkout/problems.ts`); 404 gościa (koszyk zniknął) usuwa `store_cart`; 401 sesji
  kończy sesję jak w koszyku; 400, 415, 401 gościa, 5xx i niedostępne API dają 503. Wszystkie odpowiedzi
  z `private, no-store`.
- `POST` i `GET /api/checkout/payment` (`runPaymentStart` i `runPaymentStatus` w `src/checkout/payment-bff.ts`, ten
  sam `withCartDeps`): płatność zamówienia z `store_order_confirmation` (brak ciasteczka, zły podpis albo ciasteczko
  bez `orderId`: 404 „Nie znaleźliśmy zamówienia.” bez API). Endpoint wybiera ciasteczko, nie sesja: `accessToken`
  w ciasteczku → endpointy gościa z `Order-Access-Token` (także gdy gość zalogował się po złożeniu zamówienia),
  `accessToken: null` → `/me/...` z tokenem sesji (bez sesji albo bez odświeżalnego tokenu 401 bez API, token
  niepodlegający odświeżeniu kończy sesję). `POST` ma te same zabezpieczenia i `Idempotency-Key` co kasa, treść
  `{ provider }` (inne pola są pomijane, brak `provider` przekazuje API, które nazywa pole), odpowiedź `{ redirectUrl }`;
  404, 409 (z `type`) i 422 API przechodzą z `detail` i `violations`, 401 klienta (sesja) kończy sesję, reszta (także 400,
  415 i 401 endpointu gościa) to 503. `GET` odpowiada `PaymentStatusView` API: 404 bez ciasteczka i przy 404 API, 401 dla
  zamówienia klienta bez sesji (401 API kończy sesję), 503 przy każdym innym błędzie albo treści, która nie jest
  statusem. Strona potwierdzenia czyta status tą samą funkcją (`readPaymentStatus`). Przeglądarka woła je przez
  `startPayment(provider, idempotencyKey)` i `getPaymentStatus()`; wynik 409 to `conflict` z `reason` z `type`
  (`paymentConflictReason` w `src/checkout/problems.ts`, mapa sprawdzana kompilatorem względem
  `PaymentConflictProblem`).
- `/checkout` (`noindex, nofollow`, `CheckoutPage` jako Client Component z danymi serwera: `app/checkout/page.tsx`
  czyta listę dostawców API i numer zamówienia z `store_order_confirmation`, więc strona jest dynamiczna):
  podsumowanie z `/api/cart` (nazwa, etykieta wariantu, ilość, wartość linii, suma brutto z API), formularz (gość:
  pole e-mail i link „Zaloguj się” z `returnTo=/checkout`; klient: e-mail z `/auth/session` jako tekst, imię
  i nazwisko wstępnie z sesji, chyba że konto ma tylko e-mail), grupa radiowa „Sposób płatności” z dostawcami
  z API (wymagana, bez domyślnego wyboru; brak wyboru: „Wybierz sposób płatności.” przy grupie, bez żądania
  i z fokusem na pierwszym dostawcy), przycisk „Zamawiam z obowiązkiem zapłaty” zablokowany na czas żądania. Lista
  niedostępna albo pusta: „Płatności są chwilowo niedostępne. Spróbuj za chwilę.” zamiast przycisku. Pusty koszyk albo
  `hasExcludedLines`: komunikat i link do koszyka zamiast formularza; przy pustym koszyku i poprawnym ciasteczku
  potwierdzenia także link „Opłać zamówienie R-…” na `/checkout/confirmation` (klient cofnął się ze strony dostawcy;
  sklep nie sprawdza przy tym statusu). `expectedTotalGross` to `totals.gross` ostatnio wczytanego podsumowania.
- `Idempotency-Key` (`crypto.randomUUID`, więc kasa wymaga bezpiecznego kontekstu: HTTPS albo `localhost`) to klucz
  jednej logicznej próby. Powstaje przy wejściu na stronę i wraca przy ponowieniu niepewnego wyniku (błąd sieci, 403,
  5xx) oraz po 409 `CartEmpty`, `CartNotOrderable`, `InsufficientStockForOrder` i nieznanym; nowy klucz po zmianie
  pola formularza, po 409 `IdempotencyKeyReused` i po 409 `OrderTotalChanged` (inna kwota w żądaniu, nic nie zostało
  złożone). Zmiana dostawcy nie zmienia klucza (dostawca nie należy do odcisku zamówienia). Znany skutek: gdy wynik
  poprzedniej próby był niepewny (sieć, 5xx, 503, 403), a zamówienie jednak powstało, zmiana pola i ponowne
  kliknięcie dają 409 `CartEmpty` (pusty koszyk), a nie potwierdzenie; drugiego zamówienia nie ma, bo koszyk jest
  pusty. Formularz ma `noValidate`: dane sprawdza API, a komunikaty 422 (`violations`) są przy polach, także
  komunikat o dostawcy przy grupie.
- Odpowiedzi kasy w UI: sukces ogłasza licznik 0 (`src/cart/events.ts`) i przechodzi na stronę dostawcy
  (`navigateTo(redirectUrl)` z `src/checkout/navigate.ts`, `window.location.assign`), a bez `redirectUrl` na
  `/checkout/confirmation`; przycisk zostaje zablokowany. Przyczynę 409 wyznacza wyłącznie `type` (`placeOrder` zwraca
  `reason` z `conflictReason`, mapa typów w `src/checkout/problems.ts` jest sprawdzana kompilatorem względem schematu),
  nigdy tekst `detail`:
  `OrderTotalChanged` pokazuje `detail` bez linku do koszyka i wczytuje podsumowanie ponownie (nowa kwota, formularz
  zostaje); `CartEmpty`, `CartNotOrderable`, `InsufficientStockForOrder` i nieznany 409 (inny `type` albo brak; bez
  `detail` tekst „Nie udało się złożyć zamówienia. Spróbuj ponownie.”) pokazują `detail` z linkiem „Wróć do koszyka”
  i też wczytują podsumowanie; `IdempotencyKeyReused` pokazuje `detail` bez linku i bez wczytywania. Pusty koszyk albo
  `hasExcludedLines` po przeładowaniu zastępuje formularz komunikatem z własnym linkiem, wtedy komunikat błędu nie ma
  drugiego. Żadne odrzucenie nie ponawia zamówienia samo. 404: „Koszyk nie jest już dostępny.” z linkiem do sklepu;
  401: „Twoja sesja wygasła. Zaloguj się ponownie.” z `returnTo=/checkout`; 403, 503, 5xx i błąd sieci: „Nie udało
  się złożyć zamówienia. Spróbuj ponownie.”. Po każdym odrzuceniu fokus idzie na komunikat (`role="alert"`,
  `tabIndex={-1}`, bo przycisk jest zablokowany na czas żądania), a po 422 z błędami pól na pierwsze błędne pole.
- `/checkout/confirmation` (`noindex, nofollow`, Server Component): czyta `store_order_confirmation` (`cookies()`
  i `readOrderConfirmation`, sekret z `readAuthConfig`). Bez ciasteczka, z niepoprawnym podpisem albo treścią:
  „Nie znaleźliśmy informacji o zamówieniu.” i link „Wróć do sklepu”. Inaczej czyta status płatności (`readPaymentStatus`:
  gość tokenem z ciasteczka, klient sesją) i listę dostawców, a do Client Componentu `PaymentConfirmation`
  przekazuje tylko numer, e-mail, kwotę, status i dostawców (nigdy `accessToken` ani `orderId`). Stany wyznacza API:
  `paid` („Płatność przyjęta. Potwierdzenie wysłaliśmy na adres …”), `cancelled`, `new` ze `canStartPayment = false`
  (po terminie, okno przed anulowaniem: „Minął termin płatności…”, bez przycisków płatności), `new` z oczekującą
  płatnością (link „Wróć do płatności” na `resumeUrl`, „Czekamy na potwierdzenie płatności…”) i `new` bez oczekującej
  płatności (wybór dostawcy i „Zapłać”, przy `failed` „Płatność nie powiodła się.”); odczyt nieudany (numer i kwota
  z ciasteczka, „Sprawdź ponownie”), 401 (link do logowania z `returnTo=/checkout/confirmation`) i 404. Strona nie
  używa zegara przeglądarki do stanów; godzinę terminu formatuje `formatDeadlineTime` jawnie w `Europe/Warsaw`.
  Oczekiwanie odświeża status co 2 s przez 30 s (nieudane odświeżenie zostawia widok, 401 i 404 go zastępują); potem
  „Nie mamy jeszcze potwierdzenia płatności.”, „Sprawdź ponownie” (jedno odczytanie) i „Zapłać innym sposobem”.
  „Zapłać”: klucz nowy przy każdej próbie i po zmianie dostawcy, ten sam przy ponowieniu niepewnego wyniku (błąd
  sieci, 5xx, 503, nieznany 409) z tym samym dostawcą. Wyniki: `redirectUrl` → strona dostawcy, 409 `PaymentInProgress`
  → `detail`, odczyt statusu i ponowne 30 s odświeżania, 409 `OrderAlreadyPaid` i `PaymentDeadlinePassed` → odczyt statusu
  (komunikat tylko, gdy zamówienie nie jest opłacone ani anulowane), 409 `PaymentIdempotencyKeyReused` → `detail` i nowy
  klucz, 404 → „Nie znaleźliśmy zamówienia.”, 422 → komunikat przy grupie, 401 → link do logowania, reszta →
  „Nie udało się rozpocząć płatności. Spróbuj ponownie.”. Po odrzuceniu fokus idzie na komunikat (`role="alert"`).

## Logowanie

- Tokeny OIDC nigdy nie trafiają do przeglądarki. Są tylko w sesji w Redisie, a ciasteczko zawiera wyłącznie
  id sesji podpisane kluczem `STORE_SESSION_SECRET`.
- Route handlery `/auth/*` są owinięte w `withAuthDeps` (`src/auth/responses.ts`), które daje im zależności
  (Redis, OIDC, klient API) z `getAuthDeps()` w `src/auth/deps.ts`, a przy ich braku odpowiada 503 z `no-store`.
  `getCartDeps()` bierze z nich sesje, `freshAccessToken`, klienta API i konfigurację. Testy podmieniają zależności
  dublerami z `src/auth/test-support.ts` (domyślny klient API odrzuca każde wywołanie).
- Nie loguj obiektów błędów openid-client (ich `cause` zawiera odpowiedzi z tokenami). Użyj `logFailure`.
- `GET /auth/session` zwraca `{ name, email }` zalogowanego klienta (z `GET /api/v1/me`; `name` to e-mail, gdy konto
  nie ma imienia) albo `null`. Korzystają z niego konto w nagłówku i kasa (e-mail klienta, imię i nazwisko).
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
- Logika w komponentach (np. budowa `srcset`, aktywna kategoria), cały `src/auth/`, cały `src/cart/` (BFF, przyciski,
  strona koszyka) i cały `src/checkout/` (BFF kasy i płatności, strona kasy, `PaymentConfirmation`) oraz strony
  `/checkout` i `/checkout/confirmation` mają testy. Testy czasu (odświeżanie co 2 s przez 30 s) używają fałszywych
  zegarów Vitest (`vi.useFakeTimers`); wywołanie `window.location.assign` jest za `navigateTo`, które testy podmieniają.
