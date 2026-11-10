# Przegląd bezpieczeństwa 2026-11-10

Raport z przeglądu bezpieczeństwa sklepu, API i panelu (HARDENING-7; spec: `docs/features/hardening/spec.md`, D2, D3, D5, D10, D12,
D13, D16, D19, sekcje „Rate limiting”, „Weryfikacja originu na serwerze”, „Nagłówki bezpieczeństwa” i „Przegląd bezpieczeństwa”).
Po zapisaniu się nie zmienia: kolejny przegląd to nowy plik. Przegląd niczego nie naprawia i nie zakłada zgłoszeń: znaleziska
i propozycje zgłoszeń (sekcja na końcu) trafiają do użytkownika. Dowodem jest plik z numerem linii albo komenda ze skróconym
wynikiem, który faktycznie wypisała; wyliczenia własne i oceny są tak oznaczone. W raporcie nie ma wartości sekretów ani tokenów.

## Nagłówek

| | |
|---|---|
| Data | 2026-11-10 (przegląd około 14:40–15:10 UTC) |
| Rewizja | `5f2d5531613c3d05e94f252342c3f97f077a8c2c` (`epic/hardening` po HARDENING-1…6; gałąź zadania `task/HARDENING-7`, drzewo robocze czyste przed zapisem raportu) |
| Zakres | `apps/api`, `apps/store`, `apps/admin`, `infra/k8s/charts/shop` w profilu `prod`, `infra/varnish`, `infra/keycloak/realms`, obrazy produkcyjne budowane z tej rewizji; lista 11 punktów ze specyfikacji |
| Środowisko | stos Docker Compose tego worktree w trybie `APP_ENV=dev` (sklep: `next dev`, panel: Vite dev) plus render chartu Helm (`prod`, `ci`) w kontenerach z `infra/k8s/scripts/tools.sh` (helm `alpine/helm:3.22.1`, Kubernetes 1.36.6). Do prób w trybie produkcyjnym: dodatkowe kontenery z obrazu `dev` usługi `api` uruchomione z `APP_ENV=prod APP_DEBUG=0` (limity produkcyjne z `rate_limiter.yaml`, produkcyjny Monolog) na Redisie, Postgresie i Keycloaku tego stosu, oraz trzy obrazy produkcyjne zbudowane z rewizji (`docker build --target prod`) do kontroli zawartości i nagłówków panelu |
| Nie użyto | klastra k3s ani serwera, Cloudflare, GitHuba |
| Metoda | przegląd kodu i konfiguracji + próby na działającym stosie (`curl`, skrypt `python3` ze standardową biblioteką do pełnego logowania w sklepie, `kcadm` w kontenerze Keycloaka do tymczasowych kont i klienta testowego), testy automatyczne z `make -C apps/api test PHPUNIT_ARGS=…` i `vitest` w kontenerze sklepu, `composer audit` i `npm audit` w kontenerach |

### Które wyniki pochodzą skąd

- **Stos dev (APP_ENV=dev):** wszystko, co dotyczy punktów 3, 4, 5, 6, 10 (odpowiedzi i nagłówki sklepu `next dev`, API, Keycloak,
  imgproxy, Garage). Limity `when@dev` są tam wysokie (10000), więc na stosie dev nie da się pokazać 429 przy małej liczbie żądań.
- **Kontenery `APP_ENV=prod` z obrazu dev:** próby limitów (punkt 1), odpowiedzi 5xx bez szczegółów (punkt 10). Konfiguracja
  limitów i Monologa jest produkcyjna; to nie jest obraz `prod`, ale ten sam kod i konfiguracja.
- **Obrazy `prod` zbudowane z rewizji:** zawartość (użytkownik, zmienne, pliki, brak wartości sekretów), nagłówki panelu (nginx),
  odpowiedź błędu sklepu bez API.
- **Render chartu `prod`:** wszystko o ekspozycji (punkt 8), sekretach w podach (punkt 7), `APP_ENV`, `CLIENT_IP_HEADER`, odmowa
  renderu (punkt 1). Nie sprawdzano działającego klastra.
- **Z kodu bez próby na żywo:** wyraźnie oznaczone „(kod)” albo „(test)” przy dowodzie.

Dane testowe: w stosie dev powstały przez przegląd: kilka tysięcy zapytań, 10 zamówień gościa z rezerwacjami, ~520 koszyków gościa,
kod rabatowy `SR7X`, kilka obrazów w Garage, klucze Redisa limitera. Tymczasowy klient `sr7probe` i konta `sr7-*` w Keycloaku
(realmy `shop` i `backoffice`: logowanie hasłem bez TOTP, tylko do pobrania tokenów) zostały usunięte po przeglądzie. Stos jest jednorazowy.

## Skala wag

| Waga | Znaczenie w tym raporcie |
|---|---|
| Critical | zdalne przejęcie danych, kont albo infrastruktury bez warunków wstępnych |
| High | nadużycie bez uwierzytelnienia, tanie, z poważnym skutkiem dla działania sklepu lub danych |
| Medium | realne ryzyko, ale wymaga dodatkowego warunku (drugiej luki, wielkiej liczby żądań) albo skutek jest ograniczony |
| Low | utwardzenie, rozbieżność z zasadą, brak bezpośredniego skutku |

Wagę oceniam dla publicznego demo (płatności symulowane, `customerAccounts: false`); tam, gdzie dla prawdziwego sklepu byłaby wyższa, jest to napisane.

## Podsumowanie

Critical: 0. High: 1 (SR-16). Medium: 6 (SR-4, SR-7, SR-8, SR-14, SR-17, SR-18). Low: 11.

| # | Punkt | Wynik | Najwyższa waga | Znaleziska |
|---|---|---|---|---|
| 1 | Rate limiting, klucz i odporność adresu IP | OK dla zakresu D5 i odporności na podrobienie; znaleziska poza zakresem; próba ominięcia Cloudflare na serwerze niezweryfikowana (bloker D19) | Low | SR-1 (patrz też SR-17, SR-18) |
| 2 | Walidacja wejścia na granicy API | OK (33 trasy zapisu, 0 odpowiedzi 5xx), drobne znaleziska | Low | SR-2, SR-3 |
| 3 | CORS | OK | brak | brak |
| 4 | Uprawnienia panelu | znalezisko: 11 tras GET bez `#[IsGranted]`, token bez roli personelu dostaje 200 na 14 odczytach; zapisy i rozdzielenie realmów OK | Medium | SR-4 |
| 5 | Upload zdjęć | OK, drobne znalezisko | Low | SR-5 |
| 6 | Nagłówki i ciasteczka sklepu, CSRF BFF | OK, drobne znalezisko | Low | SR-6 |
| 7 | Sekrety | znalezisko | Medium | SR-7 |
| 8 | Ekspozycja w profilu `prod` | znaleziska | Medium | SR-8, SR-9, SR-10, SR-11, SR-12, SR-13 |
| 9 | Zależności | znaleziska | Medium | SR-14, SR-15 |
| 10 | Błędy i przekierowania | OK | brak (patrz SR-2, SR-6) | brak |
| 11 | Nadużycia stanów i koszyków | znaleziska | High | SR-16, SR-17, SR-18 |

## 1. Rate limiting: klucz i odporność adresu IP na podrobienie

**Co sprawdzono.** Endpointy z wizji (`docs/vision.md`, „Bezpieczeństwo”): złożenie zamówienia, rozpoczęcie płatności, webhook,
logowanie w sklepie. Klucz limitu (`ClientIp`, D4). Odporność na podrobienie `CF-Connecting-IP` w API i w sklepie, render `prod`
i odmowa renderu.

**Kod.**
- `apps/api/src/Shared/Infrastructure/Http/ClientIp.php:35-51`: z `CLIENT_IP_HEADER` tylko pojedynczy poprawny adres IP,
  inaczej `Request::getClientIp()` (adres połączenia, bez `trusted_proxies`; w kodzie nie ma `trusted_proxies` ani `trusted_hosts`).
- `apps/api/src/Shared/Infrastructure/Http/RateLimitSubscriber.php:58` (`consume()` przed kontrolerem); trasy z limitem:
  `GuestOrderController.php:27`, `CustomerOrderController.php:30`, `GuestPaymentController.php:30`,
  `CustomerPaymentController.php:30`, `WebhookController.php:40`. Wartości: `apps/api/config/packages/rate_limiter.yaml` (domyślne 10 / 20 / 300,
  `when@dev` 10000, `when@test` 10).
- Sklep: `apps/store/app/auth/login/route.ts:16` (licznik przed zapisem stanu logowania), klucz
  `apps/store/src/auth/login-rate-limit.ts:48` (adres z `CLIENT_IP_HEADER`, bez zmiennej albo przy nagłówku niepoprawnym wspólny klucz
  `shared`).

**Próba na działającym stosie: własny `CF-Connecting-IP` bez konfiguracji nagłówka.** Kontener `api` z `APP_ENV=prod`, bez
`CLIENT_IP_HEADER` (`printenv CLIENT_IP_HEADER` puste), limity produkcyjne, żądania z tego samego hosta z innym podrobionym
`CF-Connecting-IP` w każdym:

```
13x POST /api/v1/orders {} , CF-Connecting-IP: 203.0.113.<n>   ->  422 422 422 422 422 422 422 422 422 422 429 429 429
429: Retry-After: 901, Cache-Control: no-store, private
     {"type":"about:blank","title":"Too Many Requests","status":429,"detail":"Zbyt wiele prób. Spróbuj ponownie za kilka minut."}
22x POST /api/v1/orders/{id}/payments, różne podrobione adresy  ->  20x 422, potem 429 429
303x POST /api/v1/payments/webhooks/demo-card, różne podrobione adresy -> 300x 400, 3x 429
POST /api/v1/payments/webhooks/demo-transfer z tego samego adresu     -> 400 (klucz osobny dla dostawcy)
```

Limit liczy adres połączenia: 11. żądanie to 429 mimo 13 różnych podrobionych nagłówków (D5: 10 / 15 min, 20 / 15 min, 300 / min).
Limit jest zużywany także przez żądania odrzucone walidacją (422, D6).

**Próba kontrolna: tak jak w profilu `prod` (`CLIENT_IP_HEADER=CF-Connecting-IP`).** Drugi kontener `prod` z tą zmienną:

```
12x z jedną wartością 198.51.100.10                  -> 422 x10, 429 x2
inny adres 198.51.100.11                             -> 422
11x w jednym prefiksie IPv6 /64 (2001:db8:abcd:1::n) -> 422 x10, 429
"198.51.100.2n, 203.0.113.9" (dwie wartości) x12     -> 429 x12 (adres połączenia, wyczerpany wcześniejszą próbą)
dwie linie nagłówka                                  -> 429 ; "not-an-ip" x3 -> 429 x3
X-Forwarded-For: 198.51.100.8n x12                   -> 429 x12 (ignorowany)
```

Wartości z wieloma adresami, podwójny nagłówek, śmieci i `X-Forwarded-For` wracają do klucza adresu połączenia (tego, który
wcześniejsza próba wyczerpała, stąd 429), a jeden poprawny adres w nagłówku ma własny licznik. Ufanie nagłówkowi jest bezpieczne
tylko za `ipAllowList` (niżej).

**Testy automatyczne (uruchomione):** `make -C apps/api test PHPUNIT_ARGS="--filter 'RateLimit|ClientIp'"` → `OK (50 tests, 672 assertions)`
(limity każdego limitera, klucz gościa i klienta po `sub`, `CF-Connecting-IP` z i bez `CLIENT_IP_HEADER`, 429 i nagłówki).
Sklep: `docker compose exec store npx vitest run src/auth/login-rate-limit.test.ts src/auth/return-to.test.ts app/auth/login/route.test.ts`
→ 3 pliki, 66 testów przeszło. Na stosie dev `STORE_LOGIN_RATE_LIMIT=100000` i brak `CLIENT_IP_HEADER`
(`docker compose exec store sh -c 'echo …'`), więc 429 logowania pokazują tylko testy.

**Render `prod`.** Render z `infra/k8s/scripts/tools.sh` (helm w kontenerze, te same argumenty co `scripts/render.sh`: profil
`environments/prod/values.yaml`, `image.tag` i cztery digesty):

```
prod render exit 0   (47 dokumentów: 5 Ingress, 3 Middleware, 12 Service ClusterIP, 8 Deployment, 5 StatefulSet, 5 Job, 1 CronJob)
Ingress store/admin/api/auth/img: wszystkie z adnotacją
  traefik.ingress.kubernetes.io/router.middlewares = shop-ipallowlist@kubernetescrd,shop-hsts@kubernetescrd,shop-noindex@kubernetescrd
Middleware ipallowlist: 22 zakresy (173.245.48.0/20 … 2c0f:f248::/32); `make -C infra/k8s cloudflare-ips CHECK=1`
  -> "update-cloudflare-ips: the list is up to date (22 ranges)"
CLIENT_IP_HEADER=CF-Connecting-IP w `api` i `store` (nie ma w workerze ani Jobach)
```

`clientIpHeaderWithoutAllowList` nie występuje w `environments/prod/values.yaml` (domyślnie `false`, `charts/shop/values.yaml:77`); ma go
tylko `environments/load/values.yaml:13`.

**Odmowa renderu.**

```
prod + --set clientIpHeaderWithoutAllowList=true                    -> exit 1
  Error: values don't meet the specifications of the schema(s) in the following chart(s):
  shop: - at '': 'allOf' failed  - at '/clientIpHeaderWithoutAllowList': value must be false
prod + --set ipAllowList.enabled=false                              -> exit 1
  ... at '/clientIpHeaderWithoutAllowList': value must be true
prod + ipAllowList.enabled=false + clientIpHeaderWithoutAllowList=true -> exit 1  (value must be false: z digestami obrazów potwierdzenie jest zakazane)
ci + --set clientIpHeader=CF-Connecting-IP (bez allow list)          -> exit 1  (value must be true)
```

Reguły: `charts/shop/values.schema.json` (sekcja `allOf`, `clientIpHeaderWithoutAllowList`). Komunikat jest angielski i techniczny (schemat),
ale dla operatora wystarczający.

**Próba ominięcia Cloudflare na serwerze: niezweryfikowana.** Nie ma serwera ani wdrożenia; sprawdza to krok 5 runbooka
(`infra/k8s/README.md:155-176`). Do czasu wykonania tego kroku zaufanie do `CF-Connecting-IP` jest założeniem, nie faktem. To
**bloker D19** (sekcja „Blokery publicznego demo”). Z kodu i renderu wynika tylko, że: `ipAllowList` jest na każdym ingressie,
Traefik ma `externalTrafficPolicy: Local` (`infra/k8s/argocd/install/traefik.yaml:12`), a `ufw` nie obejmuje 80/443 (świadomie,
`infra/k8s/scripts/server-bootstrap.sh:134`).

**Wynik: OK** dla odporności adresu na podrobienie (próba, render, odmowa renderu) i dla pokrycia endpointów z wizji.

**Znaleziska.**

- **SR-1 (Low): klucz IPv6 to prefiks /64.** Atakujący z prefiksem /48 ma 65 536 niezależnych limitów, z /32 miliardy; z IPv4
  to botnet. Limit chroni przed pojedynczym klientem, nie przed rozproszonym (D4 i „Ryzyka” spec to dopuszczają; reguły limitów w
  Cloudflare są poza epikiem). Waga Low, bo jest zgodna z decyzją, a ochrona rozproszona należy do Cloudflare.
- Poza listą z wizji, ale związane z limitami: brak limitu na zakładaniu koszyków i sprawdzaniu kodów rabatowych (SR-17, SR-18).

## 2. Walidacja wejścia na granicy API

**Co sprawdzono.** Lista tras z `docker compose exec api bin/console debug:router --format=json`: 67 tras aplikacji, w tym **33 trasy
zapisu** (POST/PUT/PATCH/DELETE): 13 publicznych i klienta, 5 symulatora płatności, 15 panelu. Dla każdej: DTO z walidacją albo
jawny powód.

| Grupa | Trasy | Walidacja |
|---|---|---|
| Koszyk gościa i klienta | `POST /guest-carts` (bez treści), `POST/PUT/DELETE …/items`, `POST/PUT/DELETE /me/cart/items`, `POST /me/cart/merge` | `#[MapRequestPayload]` na DTO z `Assert` (`AddCartItemRequest`, `ChangeCartItemQuantityRequest`, `MergeGuestCartRequest`), 422 z polem; `DELETE` bez treści, identyfikatory w ścieżce |
| Zamówienia i płatności | `POST /orders`, `POST /me/orders`, `POST …/payments` (x2) | DTO (`PlaceGuestOrderRequest` itd.), znaki sterujące odrzucone (`ControlCharacters::PATTERN`), 422 po polsku |
| Webhook | `POST /payments/webhooks/{provider}` | jawny powód: podpis HMAC dostawcy sprawdzany `hash_equals` przed odczytem treści (`DemoTransferProvider.php:91`, `DemoCardProvider.php:136`); zły podpis albo treść → 400 |
| Symulator | `POST /payment-simulator/…` (4) + strona płatności | ręczne `Fields::*` (`PaymentSimulator/Http/Fields.php`, URL tylko `http`/`https`, kwoty, długości) po uwierzytelnieniu kluczem (`ApiAuthentication`, `hash_equals`) |
| Panel | produkty (4), promocje (3), kody (2), cena, stan (2), zamówienia (2), `product-images` | `#[MapRequestPayload]` albo `reader->read($request, DTO::class)` (kody rabatowe), upload osobno (punkt 5) |

**Próby na stosie (kilkadziesiąt przypadków, skrypt `curl` poza repo, wyniki skrócone):** pusta treść, `not json`, `[]`, `"str"`, `null`, urwany JSON,
`{"cartId":123}`, 70 kB tekstu, NUL, zły `Content-Type` (`text/plain`, formularz), zły UUID w ścieżce, ilość tekstem / `-1` /
`99999999999999999999`, zły `provider`, NUL w nazwie produktu, kod rabatowy: 500 znaków, NBSP, NUL, duplikat różniący się wielkością liter.

```
POST /orders: {} 422, not json 400, [] 422, "str" 422, null 422, {"cartId":123} 422, 70 kB 422, NUL 422, text/plain 415, form 415
POST /guest-carts/{x}/items: zły UUID 404, ilość tekstem 422, ilość 1e20 422 ; PUT ilość -1 422
POST /admin/discount-codes: "sr7x" 201 ; "SR7X" 409 (problem details, "Taki kod już istnieje.") ; NBSP, NUL, 500 znaków 422 z polem `code`
POST /admin/products: NUL 422, 70 kB nazwy 422, text/plain 415, [] 422, urwany JSON 400
JSON 30 MB do POST /orders: 422 w 0,23 s ; zagnieżdżenie 100 000 poziomów: 400 ; nieprawidłowy UTF-8: 400 ; kontener nadal zdrowy
```

Żadnych 5xx. Naruszenia mają `propertyPath`, komunikaty po polsku (`Kod ma 3–32 znaki…`, `Identyfikator koszyka musi być UUID.`).
Testy: `make -C apps/api test PHPUNIT_ARGS="--filter 'CorsTest|SecurityHeadersTest|ProblemDetailsTest|AccessControl|Authentication|BackofficeAuthorization|Forbidden'"`
→ `OK (80 tests, 611 assertions)`.

**Wynik: OK** (każda trasa zapisu ma DTO z walidacją albo jawny powód; zły wpis daje 4xx, nigdy 500).

**Znaleziska.**

- **SR-2 (Low): komunikaty frameworka po angielsku.** Odpowiedzi błędów, które tworzy Symfony, a nie nasze DTO: `The request is invalid.`
  (400 przy złym JSON), `Resource not found.`, `Method not allowed.`, `Unsupported media type.`, `Authentication is required.` (401),
  `The Idempotency-Key header is required.`, oraz `violations[].title` przy złym typie parametru zapytania:
  `GET /api/v1/products?sort[]=x` → `{"propertyPath":"sort","title":"This value should be of type null|string."}`. Komunikaty widoczne
  dla użytkownika mają być po polsku (lista kontrolna). Nie wyciekają danych, więc Low.
- **SR-3 (Low): brak limitu rozmiaru treści JSON.** Publiczne endpointy JSON przyjmują treść do `post_max_size` = 70 MB (wspólny
  limit z uploadem; `docker compose exec api php -r 'echo ini_get("post_max_size");'` → `70M`). 30 MB tekstu w `cartId` kończy się 422
  w 0,23 s i kontener pozostaje zdrowy, więc przy pojedynczym żądaniu skutek to przepustowość i pamięć, nie awaria. Nie sprawdzano żądań
  równoległych: `memory_limit` PHP to 256M na żądanie (`apps/api/docker/php/conf.d/app.ini:2`), limit poda `api` w `prod` to 2Gi
  (`infra/k8s/environments/prod/values.yaml:77`), więc skutek wielu równoczesnych dużych treści (pamięć poda, kolejka procesów) pozostaje
  niezmierzony; limit liczby żądań (punkt 1) mają tylko trzy endpointy. Low; limit rozmiaru na ścieżkach innych niż upload (Caddy
  `request_body`) zamknąłby to jedną linią.

## 3. CORS

**Kod.** `apps/api/config/packages/nelmio_cors.yaml`: `allow_origin: ['%env(ADMIN_URL)%']` dla `^/api/v1`, `origin_regex: false`,
`allow_headers: [Content-Type, Authorization, Idempotency-Key]`. W chartcie `ADMIN_URL=https://admin.<domena>` w `api`, workerze
i Jobach (render `prod`).

**Próby na działającym API (stos dev, `ADMIN_URL=http://localhost:26901`).** Preflight (`OPTIONS` + `Access-Control-Request-Method: POST`)
i `GET` publicznego endpointu z różnymi `Origin`:

```
Origin http://localhost:26901 (ADMIN_URL)        -> Access-Control-Allow-Origin: http://localhost:26901, Vary: Origin
Origin http://evil.example                       -> bez Access-Control-Allow-Origin
Origin null                                      -> bez Access-Control-Allow-Origin
Origin http://localhost:26900 (sklep)            -> bez Access-Control-Allow-Origin
Origin http://localhost:26901.evil.example       -> bez Access-Control-Allow-Origin
Origin http://localhost:269011                   -> bez Access-Control-Allow-Origin
GET /api/v1/products z Origin evil               -> 200 bez Access-Control-Allow-Origin (przeglądarka nie odczyta)
```

Preflight z cudzego originu dostaje 200 z `Allow-Methods`/`Allow-Headers`, ale bez `Allow-Origin`, więc przeglądarka go odrzuci.
Nie ma `*` ani odbijania originu. Test: `CorsTest` w zestawie z punktu 2.

**Wynik: OK.** Brak znalezisk.

## 4. Uprawnienia panelu

**Kod.** `apps/api/config/packages/security.yaml`: dwie zapory bezstanowe (`^/api/v1/admin`: tylko tokeny realmu `backoffice`,
`audience: backoffice-api`; reszta: realm `shop`, `shop-api`), RS256, `access_control` z `ROLE_STAFF` dla `^/api/v1/admin(/|$)`
(linia 40) i `ROLE_CUSTOMER` dla reszty (linia 56). Dalej `#[IsGranted]` z voterami (`ProductVoter`, `StockVoter`, `OrderVoter`,
`PriceVoter`, `PromotionVoter`, `DiscountCodeVoter`, `DashboardVoter`).

**Próba na stosie.** Tymczasowy klient `sr7probe` z `directAccessGrants` i mapperem audience oraz konta `sr7-admin`, `sr7-support`,
`sr7-warehouse`, `sr7-marketing`, `sr7-norole` (bez żadnej roli personelu) w realmie `backoffice`, i użytkownik demo realmu `shop`
(token klienta). Wszystkie zostały usunięte. Kody HTTP; kolumny: bez tokenu / klient (realm `shop`) / konto bez roli / marketing /
support / warehouse / admin.

Odczyty:

```
GET /admin/categories, /me, /prices, /products, /products/{id}, /promotions, /promotions/{id},
    /discount-codes, /stock, /tax-classes, /variants/{id}/price-history, /variants/{id}/stock-history, /dashboard
      401 401 200 200 200 200 200
GET /admin/orders       401 401 403 403 200 200 200
GET /admin/discount-codes/{id}, /admin/orders/{orderId}   (powtórka na tym samym stosie, trzy kolumny: bez tokenu / bez roli / admin)
      /discount-codes/{id}  401 200 200
      /orders/{orderId}     401 403 200
```

Dwa ostatnie odczyty (`/discount-codes/{id}`, `/orders/{orderId}`) sprawdzono w drugim podejściu, tym samym sposobem: ten sam realm
`backoffice`, tymczasowy klient `sr7probe` (`directAccessGrants`, mapper audience `backoffice-api`) i konta `sr7-norole` (bez roli) oraz `sr7-admin`
(rola `admin`) założone `kcadm`, z wyczyszczonym wymaganym działaniem `CONFIGURE_TOTP` (bez TOTP nie da się pobrać tokenu hasłem); po próbie
konta i klient zostały usunięte (`kcadm get users -q search=sr7` i `get clients -q clientId=sr7probe` → `[ ]`). Kolumn marketing, support
i warehouse dla tych dwóch tras nie sprawdzano na stosie: z kodu wynika to samo co dla list (`/orders/{orderId}` ma
`#[IsGranted(VIEW_ORDERS)]`, `BackofficeOrderController.php:56`, ten sam atrybut co lista, `BackofficeOrdersController.php:64`, więc marketing 403,
support, warehouse i admin 200; `/discount-codes/{id}` nie ma `#[IsGranted]`, `DiscountCodeController.php:19`, więc każdy pracownik 200).

Zapisy (treść `{}`; 403 przed walidacją, 422 po przejściu autoryzacji):

```
POST /admin/discount-codes, PUT /discount-codes/{id}                    401 401 403 422/404 403 403 422/404
POST /admin/products, PUT /products/{id}, POST …/publish, …/unpublish,
POST /admin/promotions, PUT/DELETE /promotions/{id}, POST /product-images 401 401 403 422/400 403 403 422/400
PUT /admin/variants/{id}/price                                           401 401 403 403 403 403 422
POST /admin/variants/{id}/stock/corrections, /receipts                   401 401 403 403 403 422 422
POST /admin/orders/{id}/status-changes                                   401 401 403 403 422 422 422
POST /admin/orders/{id}/refund-completion                                401 401 403 403 422 403 422
```

Dodatkowe: token `backoffice` na `/api/v1/me` i `/api/v1/me/orders` → 401, token klienta na `/api/v1/admin/*` → 401; token śmieciowy
`abc.def.ghi` → 401; token `alg: none` z rolą `admin` → 401; `HEAD` na chronionych trasach: bez tokenu 401, klient 401, konto bez roli 403,
admin 200 (tak samo jak `GET`); `/index.php/api/v1/admin/me` → 401, `//api/v1/admin/me` → 404. Odpowiedź 401 ma
`WWW-Authenticate: Bearer`, `application/problem+json`.

**Wynik: znalezisko.** Kryterium ze specyfikacji („każdy endpoint `/api/v1/admin` ma voter; token klienta i token bez roli dostają 401/403”)
nie jest spełnione dla odczytów. Spełnione dla: zapisów (każdy ma `#[IsGranted]` albo sprawdzenie w kontrolerze i zachowuje się zgodnie z macierzą
ról), zamówień (voter `VIEW_ORDERS`: konto bez roli 403) i tokenu klienta (401 na każdej trasie panelu). Niespełnione dla tokenu bez roli
personelu: dostaje 200 na 14 odczytach (lista wyżej: 13 z pierwszej macierzy plus `/discount-codes/{id}`), a 11 tras GET panelu nie ma żadnego
`#[IsGranted]` (`grep` po `apps/api/src/**/UI/Http` na `/api/v1/admin` i `IsGranted`; wszystkie z `ROLE_STAFF` z `access_control` jako jedyną ochroną):

- `Backoffice/UI/Http/DashboardController.php:36` (`isGranted` na `:68` tylko wyłącza część „system” dla ról innych niż `admin`, niczego nie odmawia),
- `Backoffice/UI/Http/StaffMeController.php:17`,
- `Catalog/UI/Http/BackofficeCategoryTreeController.php:30`,
- `Inventory/UI/Http/BackofficeStockController.php:34`, `Inventory/UI/Http/StockHistoryController.php:37`,
- `Pricing/UI/Http/BackofficePricesController.php:34`, `Pricing/UI/Http/PriceHistoryController.php:37`,
- `Pricing/UI/Http/DiscountCode/ListDiscountCodesController.php:37`, `Pricing/UI/Http/DiscountCode/DiscountCodeController.php:19`,
- `Pricing/UI/Http/Promotion/ListPromotionsController.php:35`, `Pricing/UI/Http/Promotion/PromotionController.php:19`.

Trzy dalsze odczyty (`/products`, `/products/{id}`, `/tax-classes`) mają `#[IsGranted(VIEW_PRODUCTS)]`, ale voter przepuszcza każdego
`AuthenticatedStaffMember`, więc dla tokenu bez roli też kończą się 200. Rozdzielenie realmów jest w porządku: token klienta na `/api/v1/admin/*` to 401.

**Znalezisko.**

- **SR-4 (Medium): token realmu `backoffice` bez żadnej roli personelu czyta dane panelu (11 tras GET bez `#[IsGranted]`, reszta odczytów z voterem
  przepuszczającym każdego pracownika).** `StaffUser::getRoles()` zawsze zwraca `['ROLE_STAFF']`
  (`apps/api/src/Backoffice/Infrastructure/Security/StaffUser.php:44-47`), a 14 odczytów (lista wyżej) ma tylko wymaganie `ROLE_STAFF` albo
  voter `VIEW_PRODUCTS`, który przepuszcza każdego `AuthenticatedStaffMember` (`ProductVoter.php`: `VIEW_PRODUCTS === $attribute || …`). Konto
  `sr7-norole` odczytało katalog, ceny, historię cen i stanów, stany magazynowe, promocje i **wszystkie kody rabatowe** (200). Jest to
  zgodne z komentarzem w voterach („every staff member reads”), ale konto bez roli nie jest pracownikiem, a kryterium specyfikacji tego
  wprost nie dopuszcza. Skutek bezpośredni: ujawnienie kodów rabatowych (każdy, kto je zna, użyje ich w sklepie; kody procentowe do 90%),
  cen i ich historii, stanów oraz zaplanowanych promocji. Warunek: konto w realmie `backoffice` bez roli, czyli nowe konto przed nadaniem
  roli albo pracownik, któremu rolę odebrano (z kodu: `getRoles()` nie zależy od ról w tokenie, więc takie konto nadal czyta; na stosie sprawdzono tylko konto założone bez roli). Konta w realmie zakłada administrator (rejestracja wyłączona),
  stąd Medium, nie High: bez takiego konta nie ma dostępu, a zapisy i zamówienia są niedostępne (403). Odczyty roli `support` i `warehouse`
  (np. kody rabatowe, `DiscountCodeVoter`: „every staff member reads them”) to decyzja produktowa, nie błąd; błędem jest brak sprawdzenia
  jakiejkolwiek roli.

## 5. Upload zdjęć

**Kod.** `apps/api/src/Catalog/UI/Http/UploadProductImageController.php:80-107` (`#[IsGranted(MANAGE_PRODUCTS)]`, sprawdzenie
`Content-Length` względem `post_max_size`), `apps/api/src/Catalog/Infrastructure/Image/FlysystemProductImageStorage.php:30-47`: limit
bajtów, typ po zawartości (`finfo`, `FILEINFO_MIME_TYPE`; JPEG/PNG/WebP/AVIF/HEIC), wymiary z nagłówka (`getimagesizefromstring`) i limit
pikseli, klucz `originals/<sha256>.<typ>`. Klucz w zapisach produktu: `ImageKey` (`^originals/[a-f0-9]{64}\.(typy)$`).
Imgproxy w compose: `IMGPROXY_ALLOWED_SOURCES`, `IMGPROXY_MAX_SRC_FILE_SIZE=67108864`, `IMGPROXY_MAX_SRC_RESOLUTION=50`.
Garage: `infra/garage/garage.toml` bez sekcji `s3_web`, `init.sh` nadaje uprawnienia tylko kluczowi `shop`.

**Próby (token admina, `POST /api/v1/admin/product-images`):**

```
ok.png (8x8 PNG)                            201, {"key":"originals/<sha256>.png","preview":{…podpisane adresy imgproxy…}}
PNG + doklejone <script>…</script>           201 (patrz SR-5)
plik HTML nazwany .png                       422 "Nieobsługiwany typ pliku. Wyślij zdjęcie JPEG, PNG, WebP, AVIF albo HEIC."
SVG ze skryptem                              422 (ten sam komunikat)
"<?php echo 1;" jako shell.php.png           422
PNG deklarujący 10000x10000 (100 MP)         422 "Zdjęcie ma za dużą rozdzielczość (najwyżej 50 megapikseli)."
plik 68 MB i 80 MB                           422 "Plik jest za duży."
marketing 201 ; warehouse 403 ; klient 401 ; bez tokenu 401
```

**Dostęp do oryginałów.**

```
imgproxy bez podpisu: /insecure/…, /unsafe/…, /AAAA/…, /_/… do s3://product-images/originals/<klucz>   -> 403 (każdy)
imgproxy /insecure/plain/http://api/health, local:///etc/passwd, /debug/pprof/                      -> 403
adres podpisany przez API: 200 image/webp ; zmieniony rozmiar (rs:fill:97:97) -> 403 ; dodana opcja dpr:3 -> 403
Garage anonimowo z sieci stosu: http://garage:3900/product-images/<klucz>, /product-images/, /  -> 403
Garage jest bez portu na hoście (docker compose ps: ports puste); w compose imgproxy, keycloak, api, store, admin, mailpit, rabbitmq
  są związane z 127.0.0.1
```

API nigdy nie wystawia adresu oryginału, tylko podpisane adresy rozmiarów z jednej listy (`ImageSize`); klucz podpisu nie jest w frontach.

**Wynik: OK.**

**Znalezisko.**

- **SR-5 (Low): dopuszczone doklejone dane po obrazie.** Plik, który zaczyna się jak poprawny PNG i ma na końcu dowolne bajty (tu
  `<script>`), przechodzi (`finfo` i `getimagesizefromstring` czytają tylko nagłówek) i trafia do Garage jako oryginał. Skutek jest
  ograniczony: oryginał nie jest serwowany, imgproxy dekoduje i zapisuje obraz na nowo, a upload mają tylko role `admin` i `marketing`.
  Low.

## 6. Nagłówki bezpieczeństwa, ciasteczka sklepu i CSRF zapisów BFF

**Nagłówki (próba).** Sklep (`next dev`), strona, 404, trasa BFF:
`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`,
`Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Content-Security-Policy: frame-ancestors 'none'; object-src 'none'; base-uri 'self'`
(`apps/store/next.config.ts:5-11`). Obraz produkcyjny sklepu (`node server.js`, uruchomiony bez API): te same nagłówki na 404, 500 i `/api/site`.
API: `X-Content-Type-Options: nosniff` na 200, 401, 404, 5xx; `X-Correlation-Id` na każdej odpowiedzi.
Panel (obraz produkcyjny nginx zbudowany z rewizji, `curl` na `/`, `/config.js`, `/assets/nope.js`, `/auth/callback`): wszystkie pięć
nagłówków, w tym `Content-Security-Policy: script-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'self'`, także na 404
(`apps/admin/docker/nginx.conf`, `security-headers.conf`, `add_header … always`). HSTS (`stsSeconds: 31536000`, bez `includeSubDomains`)
jest w middleware `shop-hsts` na każdym ingressie w renderze `prod` (`ingress.tls: true`). Panel na stosie compose to Vite dev i nie ma tych
nagłówków; sprawdza je test dymny (`smoke.sh`), którego nie uruchamiano (obraz `prod` sprawdzono ręcznie jak wyżej).
Zaakceptowane w specyfikacji: CSP sklepu bez `script-src` (D10, „Ryzyka”); wynik nie jest tu znaleziskiem.

**Ciasteczka.** `apps/store/src/auth/cookies.ts:32-39`: `httpOnly: true`, `sameSite: "lax"`, `path: "/"`, `secure` dla każdego hosta poza
lokalnym; wszystkie miejsca `cookies.set` przechodzą przez `cookieOptions` (`grep` po `app` i `src`: `auth/responses.ts`, `checkout/cookies.ts`,
`cart/bff.ts`, `cart/cookies.ts`, `auth/callback`, `auth/login`). Pełne logowanie na stosie (skrypt z cookie jar, Keycloak realm `shop`):

```
Set-Cookie: store_session=<wartość>; Path=/; HttpOnly; SameSite=lax
Set-Cookie: store_login=<wartość>; Path=/auth/callback; Max-Age=0; HttpOnly; SameSite=lax
Set-Cookie: store_cart=<wartość>; Path=/; Expires=…; Max-Age=2592000; HttpOnly; SameSite=lax
```

`Secure` nie ma, bo sklep stoi na `localhost` (to wyjątek kodu); dla hosta `https://…` ustawia go `cookieOptions` (test
`apps/store/src/auth/cookies.test.ts:41-57`, uruchomiony: `vitest run src/auth/cookies.test.ts` przechodzi). Wartości są podpisane HMAC
(`signValue`, `timingSafeEqual`).

**CSRF zapisów BFF.** `apps/store/src/cart/bff.ts:41-47` (`refuseForeignWrite`): wymaga `Origin` równego origin sklepu i, gdy jest treść,
`Content-Type: application/json`; wywołują go wszystkie zapisy (`app/api/cart/items`, `…/items/[variantId]`, `app/api/checkout`,
`app/api/checkout/payment`). Próba na stosie (`POST`, `PATCH`, `DELETE`):

```
bez Origin                      403 {}
Origin http://evil.example      403 {}
Origin panel (http://localhost:26901)  403 {}
Origin sklepu + text/plain      403 {} (DELETE bez treści: 200, bo Origin jest zgodny)
Origin sklepu + application/json  przechodzi (422 "Nieprawidłowe dane." dla pustej treści)
POST /auth/logout: Origin evil 403, Sec-Fetch-Site: cross-site 403 ; GET /auth/logout 405
POST /api/revalidate bez sekretu 401, z błędnym 401, GET 405
```

Odczyty BFF (`GET /api/cart`, `/api/account/orders`) nie zmieniają stanu. `GET /auth/login` zapisuje stan logowania, ale ma limit (punkt 1).
Brak `dangerouslySetInnerHTML` poza `<script type="application/ld+json">` z `serializeJsonLd` (zamienia każde `<` na `\u003c`,
`apps/store/src/catalog/product-json-ld.ts:67-69`); w panelu brak `v-html` i `innerHTML`.

**Wynik: OK** (nagłówki, ciasteczka, CSRF).

**Znalezisko.**

- **SR-6 (Low): `X-Powered-By: Next.js`.** Obraz produkcyjny sklepu wysyła nagłówek (`poweredByHeader` nie jest wyłączony w
  `next.config.ts`); API wysyła `Server: FrankenPHP Caddy`, panel `Server: nginx` (bez wersji, `server_tokens off`). Ujawnia technologię,
  niczego poza tym. Low.

## 7. Sekrety

**Repozytorium.** `git ls-files` po wzorcach `.env`, `*.pem`, `*.key`, `secret`, `kubeconfig`: tylko `.env.example` (same zakomentowane
zmienne portów), `apps/api/.env` (jedna zmienna: `APP_ENV=dev`), skrypty i testy `infra/k8s`, `infra/varnish/tests/init_secret.vtc`.
`.gitignore`: `/.env`, `.env.local`, `.env.*.local`, `*.pem`, `*.key` (wyjątek: publiczny certyfikat Sealed Secrets).
`git grep` na `BEGIN … PRIVATE KEY`, `AKIA…`, `ghp_`, `github_pat_`, `xox…`, `sk_live_`, `AIza…`, JWT: trafienia tylko w
`infra/k8s/scripts/seal-secrets.sh:266` (sprawdzenie nagłówka klucza) i fikcyjne wartości w testach. Wartości z `.env.local` stosu
(19 wartości o długości ≥ 12) nie występują w żadnym pliku śledzonym (`git grep -l -F -f <wartości>` → pusto).
`infra/k8s/environments/prod/secrets/` zawiera tylko `.gitkeep` i `README.md` (certyfikat Sealed Secrets i zaszyfrowane sekrety
powstaną przy pierwszym wdrożeniu; jawnego `Secret` w repo nie ma). Realm w chartcie jest składany bez sekcji `users`
(`infra/k8s/charts/shop/templates/_keycloak.tpl:22`), więc konta demo z hasłami z `infra/keycloak/realms/*.json` nie trafiają do klastra.
Hasła kont demo są w repo jawnie (realm dev); to dane demo, nie sekret, ale każdy, kto wdroży realmy z `users`, wdroży znane hasła.

**Obrazy.** `docker build --target prod` z rewizji, trzy obrazy:

```
api   : User= (root), ENV APP_ENV=prod APP_DEBUG=0 ; /app: .env (jedna zmienna APP_ENV), bin, config, fixtures, migrations, public (index.php), src, vendor, var ; bez .git, .env.local, kluczy
store : User=node, NODE_ENV=production ; standalone
admin : User=101 (nginx-unprivileged)
docker history --no-trunc | grep -i -E "secret|password|token|key=|\.env"  -> tylko GPG_KEYS z bazowego obrazu PHP i COPY *.envsh nginx (nie sekrety)
grep -r -l -F -f <wartości .env.local> /app (api, store) i /usr/share/nginx/html (admin) oraz /etc -> 0 plików w każdym z trzech obrazów
```

**Logi.** `docker compose logs` stosu (42 241 linii, wszystkie usługi): 0 linii z `Bearer ey…`, 0 z tokenem JWT, 0 z `password=`, 0 z wartością
ciasteczka `store_session`/`store_cart`; jedyna wartość z `.env.local` w logach to identyfikator klucza Garage
(`GARAGE_ACCESS_KEY_ID`, 237 linii w logu Garage; sam identyfikator bez części tajnej). Logi kontenerów `prod` z próbami: 0 wartości sekretów.

**Chronione operacje (próba na stosie, bez sekretu):**

```
PURGE Varnisha (z kontenera api): bez tokenu 403, zły token 403, PURGE z tagiem bez tokenu 403 ; z poprawnym tokenem i bez xkey-purge 400
  (infra/varnish/default.vcl:26-47: bez VARNISH_PURGE_SECRET VCL się nie ładuje; ACL purgers tylko adresy prywatne)
POST /api/revalidate (sklep): bez sekretu 401, zły sekret 401 (porównanie przez sha256 + timingSafeEqual, route.ts:24-29)
API symulatora (demo-card Basic, demo-transfer Bearer): bez klucza 401, zły klucz 401 (create, expire, cancel); webhooki bez
  podpisu / ze złym podpisem 400 ; strona płatności dla losowego id 404
GET /api/v1/admin/me przez Varnish bez tokenu 401
```

**Render `prod`.** Żadna zmienna o nazwie sekretu nie ma `value:` w podach; sekrety idą przez `envFrom: secretRef: shop-secrets`
(`infra/k8s/charts/shop/templates/api.yaml:114` kontener `api`, `:134` worker, `:103` initContainer `wait-for-migrate`; `store.yaml:30`) i `shop-brevo-smtp`; `Secret` nie jest renderowany w `prod`
(tworzy go SealedSecret poza chartem).

**Wynik: znalezisko.** Sekrety nie wyciekają do repo, obrazów ani logów; chronione operacje wymagają sekretu.

**Znalezisko.**

- **SR-7 (Medium): jeden wspólny `Secret` we wszystkich podach (`envFrom`).** Render `prod`: `shop-secrets` jest wstrzykiwany jako
  zmienne środowiska do `api`, `worker`, `store`, `keycloak`, `imgproxy`, `varnish`, `garage`, `postgres`, `rabbitmq` i do Jobów
  (nie dostają go tylko `admin`, `mailpit`, `elasticsearch`, `redis`). Pod wystawiony do internetu (`store`, `api`) ma więc m.in.
  hasło administratora Keycloaka (`KC_BOOTSTRAP_ADMIN_PASSWORD`), hasło superużytkownika Postgresa, `GARAGE_RPC_SECRET`,
  `GARAGE_ADMIN_TOKEN` i hasło RabbitMQ, choć potrzebuje ułamka. Przejęcie `store` albo `api` (np. RCE w zależności) daje więc dostęp
  administracyjny do Keycloaka (a z nim do kont personelu), bazy i magazynu obrazów, a nie tylko do własnej funkcji. Medium: wymaga
  osobnej luki wykonującej kod, ale skutek przejęcia jest wielokrotnie większy niż konieczny (w połączeniu z SR-8). W compose jest tak samo
  (`env_file: .env.local` w `api`, `store` i innych).

## 8. Ekspozycja w profilu `prod`

**Co jest osiągalne z internetu (render `prod`, po `ipAllowList` Cloudflare).** Pięć ingressów, wszystkie `websecure` z TLS, wszystkie z
`ipallowlist`, `hsts`, `noindex`; wszystkie 12 usług to `ClusterIP` (brak `NodePort` i `LoadBalancer` w chartcie):

| Host | Ścieżki | Backend |
|---|---|---|
| `<domena>` | `/` | `store:3000` |
| `admin.<domena>` | `/` | `admin:8080` (statyczny panel; dane za tokenem) |
| `api.<domena>` | `/` | `api:80` (całe `/api/v1`, `/health`, `/payment-simulator/*`) |
| `auth.<domena>` | `/realms/shop/`, `/realms/backoffice/`, `/resources/` | `keycloak:8080` |
| `img.<domena>` | `/` | `imgproxy:8080` |

Nieosiągalne z internetu (brak ingressu, tylko w klastrze): panel administracyjny Keycloaka (`/admin/…`), realm `master`,
`/health` i `/metrics` Keycloaka (port zarządzania 9000 nie jest routowany), Mailpit (8025), RabbitMQ management (15672), Elasticsearch,
Redis, Postgres, Garage (3900/3901/3903), Varnish. Argo CD nie ma ingressu (`argocd/install/argocd.yaml`: port-forward po SSH). Na hoście:
`ufw` otwiera tylko SSH i `6443/tcp` (`infra/k8s/scripts/server-bootstrap.sh:140-141`); 80/443 chroni Cloudflare i `ipAllowList` (D6).

**Próby na stosie dev (Keycloak, odpowiedniki ścieżek):** `/realms/shop/.well-known/openid-configuration` 200, `/realms/shop/account/` 200,
`/realms/shop/protocol/openid-connect/certs` 200; `/admin/realms/shop/users` 401; `/metrics` 404, `/health` 404 (na porcie 8080);
`/realms/shop/clients-registrations/openid-connect` anonimowo → `403 {"error":"insufficient_scope","error_description":"Policy 'Trusted Hosts'
rejected request to client-registration service. Details: Host not trusted."}`. imgproxy: `/health` 200, `/metrics`/`/version`/`/status` 404.
API: `/health` → `{"status":"ok"}` (bez szczegółów).

Usługi bez uwierzytelnienia w sieci stosu (z kontenera `api`): Elasticsearch `GET /_cat/indices` 200 (`xpack.security.enabled=false`),
Redis `PING` → `+PONG` bez hasła (`CONFIG GET requirepass` puste), Mailpit `/api/v1/messages` 200, Garage `/health` 200 (panel `GET /v2/…` 403),
RabbitMQ management 401. Redis zawiera sesje sklepu: klucze `session:*` mają pola `accessToken`, `idToken`, `refreshToken`, `sub`.
Chart odwzorowuje te ustawienia, a w renderze `prod` nie ma żadnego `NetworkPolicy`.

**Wynik: znaleziska.** Powierzchnia jest wąska i zgodna z projektem; poniższe to utwardzenie.

**Znaleziska.**

- **SR-8 (Medium): brak `NetworkPolicy`, usługi wewnętrzne bez uwierzytelnienia, tokeny sesji w Redisie bez hasła.** Dowolny pod
  (np. przejęty `store`) może sięgać do Redisa i czytać `accessToken` i `refreshToken` zalogowanych klientów, pisać do Elasticsearch,
  czytać zamówienia z Mailpita (w `prod` `orderMail: mailpit` trzyma prawdziwe adresy i treść zamówień), łączyć się z Postgresem i Keycloakiem
  (hasła z SR-7). Chart (47 dokumentów) nie zawiera ani jednego `NetworkPolicy`; k3s ma kontroler polityk (kube-router), więc polityki
  byłyby egzekwowane. Medium: wymaga wejścia do jednego poda, ale wtedy cały klaster jest płaski.
- **SR-9 (Low): brak `securityContext` w podach i obraz `api` działa jako root.** Render `prod`: żaden kontener nie ma `securityContext`
  (`runAsNonRoot`, `readOnlyRootFilesystem`, `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]`, `seccompProfile`); obraz
  `api` (FrankenPHP, `docker inspect`: `User=`) działa jako `uid=0`; `store` (`node`) i `admin` (101) nie. `automountServiceAccountToken: false`
  jest ustawione, co ogranicza skutek. Low: utwardzenie bez bezpośredniej luki.
- **SR-10 (Low): `api.<domena>` wystawia cały `/api/v1` i symulator płatności, w tym odczyty katalogu poza Varnishem.** Ingress `api`
  kieruje na usługę `api`, nie na Varnisha (z którego korzysta sklep, `API_CATALOG_URL`), więc `GET /api/v1/products?q=<losowe>` z internetu
  trafia za każdym razem do API i Elasticsearch (dev: 70–150 ms na zapytanie, nie jest to pomiar); `Cache-Control: max-age=60, public` pomaga
  tylko CDN, a Cloudflare domyślnie nie cache'uje JSON. `/payment-simulator/{provider}/pay/{id}` (strona HTML z przyciskami) jest publiczna
  i nie ma `X-Frame-Options` ani CSP (API wysyła tylko `nosniff`): to demo, ale dopóki jest w tym samym procesie co API (D14), jest na tej
  samej powierzchni. Low; pomaga reguła Cloudflare na `/api/v1/products` i ewentualnie wyłączenie symulatora poza demo.
- **SR-11 (Low): ingress `auth` wystawia całe `/realms/<realm>/`.** Prefiks obejmuje konsolę konta (`/realms/shop/account/`), punkt
  rejestracji klientów (`…/clients-registrations/…`, na stosie dev zablokowany polityką Trusted Hosts, więc nie działa; za Cloudflare i
  `KC_PROXY_HEADERS=xforwarded` ta polityka patrzy na adres z nagłówka, czego nie sprawdzano) i logowanie personelu
  (`/realms/backoffice/`, hasło + TOTP). Panel administracyjny i realm `master` nie są routowane (OK). Low; ścieżki można zawęzić do
  `…/protocol/openid-connect/`, `…/login-actions/` i potrzebnych zasobów.
- **SR-12 (Low): `ufw` otwiera `6443/tcp` (API Kubernetesa) i SSH dla całego internetu.** Świadoma decyzja (README, D6: do `kubeconfig`, `rerun`
  i `create-admin`); dostęp wymaga certyfikatu klienta, ale wystawia powierzchnię API klastra. Low; ograniczenie do adresu operatora
  usuwa ryzyko, jeśli adres jest stały.
- **SR-13 (Low): brak polityki haseł w realmach.** `infra/keycloak/realms/shop.json` i `backoffice.json` nie mają `passwordPolicy`
  (domyślnie dowolne hasło). Ochrona przed zgadywaniem (`bruteForceProtected: true`) jest (D17), personel ma obowiązkowy TOTP
  (`CONFIGURE_TOTP`), a `customerAccounts: false` w `prod`, więc dziś skutek jest znikomy; po włączeniu rejestracji klienci ustawią
  dowolnie słabe hasła. Low (wzrasta do Medium przy `customerAccounts: true`).

## 9. Zależności

**`composer audit` (api, Composer z obrazu API, `composer.lock` rewizji, stan na 2026-11-10):** 18 advisories w 11 pakietach, **wszystkie w
zależnościach produkcyjnych** (`composer audit --locked --no-dev`: też 18 w 11). Zainstalowane wersje (`apps/api/composer.lock`): 8.1.9
(`http-foundation`, `http-kernel`, `routing`, `validator`, `filesystem`, `yaml`, `framework-bundle`), 8.1.10 (`security-http`, `serializer`, `http-client`)
i 8.1.5 (`config`); w każdym advisory zakres dla gałęzi 8.1 to `>=8.1.0,<8.1.11`, więc wszystkie poprawia 8.1.11. Composer nie podaje wagi (pole `severity` puste), więc waga
poniżej jest moja.

| Pakiet (zainstalowana) | Advisory | Dotyczy nas? |
|---|---|---|
| `symfony/http-foundation` 8.1.9 | HEAD omija `methods` w `access_control` (CVE-2026-118514) | nie: reguły z `methods` mają `PUBLIC_ACCESS`, reszta jest bez `methods`; próba `HEAD` na chronionych trasach zachowuje się jak `GET` (401/403/200, punkt 4) |
| `symfony/http-foundation` 8.1.9 | reguły przed PHP omijalne przez dopisanie ścieżki do skryptu | nie dla API (`/index.php/api/v1/admin/me` → 401); reguły przed PHP to ingress i VCL |
| `symfony/http-foundation` 8.1.9 (2) | `private_ranges` trusted proxies; wygenerowane URL-e z hostem klienta | nie: brak `trusted_proxies`/`trusted_hosts` w konfiguracji; adresy publiczne z konfiguracji |
| `symfony/routing` 8.1.9 | placeholdery hosta w ścieżce, obejście kontroli dostępu po ścieżce (CVE-2026-118516) | nie: brak tras z hostem |
| `symfony/security-http` 8.1.10 | X509 (CVE-2026-118513); logowanie programowe pomija `checkPostAuth()` | nie: brak X509 i logowania programowego |
| `symfony/serializer` 8.1.10 (3) | `DataUriNormalizer`, `UploadedFile` z danych żądania (CVE-2026-118515), `#[Ignore]` przy kluczach z kropką | nie ustalono w kodzie: DTO mają typy `mixed`/skalarne, brak `UploadedFile` w DTO, brak `#[Ignore]` |
| `symfony/validator` 8.1.9 | `Ip` public/private przyjmuje IPv4 w IPv6 | nie: brak `Assert\Ip` |
| `symfony/http-client` 8.1.10 (2) | `ScopingHttpClient`, `NoPrivateNetworkHttpClient` | nie: brak użycia (`grep`) |
| `symfony/http-kernel` 8.1.9, `config` 8.1.5, `filesystem` 8.1.9, `yaml` 8.1.9, `framework-bundle` 8.1.9 | fragmenty inline, XXE w `XmlUtils`, ścieżki `://`, parser YAML, `private_ranges` trusted proxies | nie: nie używamy; `yaml` czyta tylko własne pliki; brak `trusted_proxies` |

`grep -rn "Assert\\Ip\|ScopingHttpClient\|NoPrivateNetwork\|#\[Ignore\]\|DataUriNormalizer\|trusted_proxies\|trusted_hosts\|XmlUtils" apps/api/src apps/api/config`
trafia tylko w `config/reference.php` (wygenerowany opis).

**`npm audit` (kontener `node:24.22.1-alpine`, `npm audit --package-lock-only` na `package.json`/`package-lock.json` każdej aplikacji):**

| Aplikacja | Wszystkie | `--omit=dev` | Co |
|---|---|---|---|
| `apps/store` | 7 moderate (0 high, 0 critical) | **0** | `minimatch` (GHSA-g3hv-75wg-m249, DoS przez amplifikację) przez `typescript-eslint` |
| `apps/admin` | 11 moderate (0 high, 0 critical) | **0** | jw. plus `glob` (GHSA-3g3m-2fjr-6pw4) i `@vue/test-utils` → `js-beautify` |
| `e2e` | **0** | **0** | |

**Wynik: znaleziska** (Symfony), reszta OK.

**Znaleziska.**

- **SR-14 (Medium): 18 advisories w 11 pakietach Symfony, wszystkie w kodzie produkcyjnym, poprawka w 8.1.11.** Ocena własna: po przejrzeniu
  zakresów i kodu żaden nie jest osiągalny w naszej konfiguracji (tabela), ale część dotyczy bezpośrednio kontroli dostępu
  (`access_control`, `security-http`, `http-foundation`), wszystkie są z jednego dnia (2026-11-04) i naprawia je jedna aktualizacja
  mniejsza. Medium: bez wykorzystywalnej ścieżki nie jest High, ale zostawienie ich na publicznym demo jest zbędnym ryzykiem.
- **SR-15 (Low): 7 i 11 podatności moderate w zależnościach deweloperskich sklepu i panelu** (narzędzia lintera i testów; w obrazach
  produkcyjnych ich nie ma, `--omit=dev` → 0). Low.

Nie sprawdzano: podatności w obrazach bazowych (`dunglas/frankenphp`, `node`, `nginx`, `postgres`, `keycloak`, `varnish`, `imgproxy` itd.) ani w bibliotekach
systemowych (np. libvips w imgproxy).

## 10. Błędy i przekierowania

**Odpowiedzi błędów w trybie produkcyjnym.** Kontener `api` z `APP_ENV=prod APP_DEBUG=0` i celowo zepsutymi zależnościami
(`SHOP_DB_HOST=nonexistent`, `ELASTICSEARCH_URL=http://nonexistent:9200`):

```
GET /api/v1/products, /categories, /products/baton, POST /guest-carts
  -> 500 {"type":"about:blank","title":"Internal Server Error","status":500}   (bez detail, bez stack trace, ścieżek i SQL)
nagłówki 5xx: Content-Type: application/problem+json, X-Content-Type-Options: nosniff, X-Correlation-Id
GET /nie-ma -> 404 {"…","detail":"Resource not found."} ; DELETE /api/v1/products -> 405 ; GET /health -> {"status":"ok"}
```

SQLSTATE i nazwa hosta trafiają tylko do logu (z `correlation_id`), nie do odpowiedzi. Dla 4xx na stosie: żadna z ~60 prób punktów 2–5 nie
zwróciła 5xx ani danych wewnętrznych (komunikaty to polskie `detail`/`violations` albo ogólne angielskie, SR-2). Sklep (obraz
produkcyjny, API niedostępne): `/p/nie-ma` → 500 z generyczną stroną Next (`digest`, 0 trafień na `.next/server`, `node_modules`,
`ECONNREFUSED`, `fetch failed`, `/app/`), `/auth/login` i `/api/cart` → 503 bez treści. `APP_ENV`/`APP_DEBUG`: render `prod` ustawia
`APP_ENV=prod` w `api`, `worker`, Jobach i CronJobie; `APP_DEBUG` nie jest w chartcie, ale obraz `prod` ma `ENV APP_DEBUG=0`
(`docker inspect`); `NODE_ENV=production` w obrazie sklepu. `apps/api/.env` zawiera tylko `APP_ENV=dev`, nadpisywane przez zmienne środowiska.
`APP_SECRET` nie jest używany (brak w konfiguracji i środowisku).

**Przekierowania.** Pełne logowanie sklepu na stosie (skrypt: `GET /auth/login?returnTo=…`, logowanie w Keycloaku, callback, nagłówek
`Location` callbacku):

```
returnTo=/konto                              -> 303 Location: http://localhost:26900/konto
returnTo=https://evil.example                -> 303 Location: http://localhost:26900/
returnTo=//evil.example                      -> .../
returnTo=/\evil.example                      -> .../
returnTo=/%5Cevil.example                    -> .../%5Cevil.example   (ścieżka na własnym origin, nie zmienia hosta)
returnTo=javascript:alert(1)                 -> .../
returnTo=///evil.example                     -> .../
returnTo=http://localhost:26900.evil.example -> .../
returnTo=/%2F%2Fevil.example                 -> .../%2F%2Fevil.example   (ścieżka na własnym origin)
returnTo=/a/../../evil                       -> .../evil   (znormalizowana ścieżka sklepu)
returnTo=/zam%C3%B3wienie?x=1#h              -> .../zam%C3%B3wienie?x=1#h
```

Dodatkowo `data:`, `@evil.example`, `/a%0d%0aSet-Cookie:x=1` zostały przyjęte przy starcie logowania (zawsze 303 do Keycloaka; wartość
trafia tylko do transakcji w Redisie i jest czyszczona w callbacku przez `sanitizeReturnTo`: tylko ścieżka zaczynająca się od jednego `/`,
bez `\` i znaków sterujących, a wynik musi mieć origin sklepu: `apps/store/src/auth/return-to.ts:9-26`; test jednostkowy
`return-to.test.ts` przeszedł). Wylogowanie nie przyjmuje `returnTo` (stały cel, `app/auth/logout/route.ts`), `POST` z kontrolą origin (punkt 6).
Po stronie Keycloaka adresy po wylogowaniu i `redirectUris` są ustawiane z domeny (Job `keycloak-config`), a klient `admin` ma
`redirectUris: ${ADMIN_URL}/*`.

**Wynik: OK.** Brak otwartego przekierowania i wycieku danych wewnętrznych (angielskie komunikaty: SR-2; `X-Powered-By`: SR-6).

## 11. Nadużycia stanów i koszyków

**Rezerwacje fikcyjnymi zamówieniami (D16).** Kod: `Cart::MAX_LINES = 50`, `MAX_QUANTITY = 99`
(`apps/api/src/Cart/Domain/Cart.php:18-19`, `Ordering/Domain/Order.php:30`, `OrderLine.php:15`), zamówienie gościa nie wymaga konta ani płatności,
nieopłacone zamówienie trzyma rezerwację 30 + 5 min (`Order::PAYMENT_TIME = '+30 minutes'`, `DELIVERY_WINDOW = '+5 minutes'`).
Próba na stosie dev (limity wysokie; liczba zamówień dobrana tak, jak pozwala produkcyjny limit 10 na 15 min z jednego adresu):
skrypt zakłada koszyk gościa, dodaje 50 wariantów z ilością równą dostępnej (demo ma 1006 wariantów ze stanem, dostępność 1–50, średnio 35;
żaden nie ma 99), składa zamówienie z `Idempotency-Key`:

```
zamówienie 1: 50 linii, HTTP 201            -> 50 z 1006 wariantów w pełni zarezerwowanych, 1674 sztuk
po 10 zamówieniach (10 zamówień = limit D5 na 15 min): 500 z 1006 wariantów w pełni zarezerwowanych, 17 771 sztuk
```

Wyliczenie własne (nie pomiar): przy 21 zamówieniach (1006 / 50) rezerwowany jest cały katalog; produkcyjny limit 10 na 15 min z jednego
adresu przy rezerwacji 35 min pozwala utrzymać około 23 zamówień naraz (okno przesuwne), więc **jeden adres zablokuje sprzedaż całego
katalogu demo w około 30–35 min i utrzyma blokadę**, dopóki wysyła zamówienia; kilka adresów robi to szybciej. Kosztu atakującego
praktycznie nie ma (bez konta, bez płatności, tylko poprawny e-mail). Limit D5 ogranicza liczbę zamówień, nie rezerwowaną ilość (D16, „Ryzyka”).

**Koszyki gościa.** `POST /api/v1/guest-carts` (`GuestCartController.php:43`) nie ma limitu (żadna trasa koszyka nie ma `RateLimit::ROUTE_DEFAULT`):

```
500x POST /api/v1/guest-carts, 8 równolegle z jednego hosta: 500x 201 w 12 s (~42/s), wiersze: 12 -> 512
pg_total_relation_size('cart_cart'): 80 kB (12 wierszy) -> 152 kB (512) = ~145 B na pusty koszyk wraz z indeksem
```

Wyliczenie własne: przy ~42/s to ~3,6 mln koszyków na dobę, ~520 MB na dobę pustych (więcej z liniami: do 50 linii na koszyk, `cart_line`).
TTL koszyka gościa to 30 dni od ostatniej zmiany (opis operacji), czyści go CronJob `cart-purge-expired`, więc rozmiar rośnie przez 30 dni, zanim
zacznie spadać. Cloudflare w chartcie nie ma żadnej reguły (poza repo).

**Sprawdzanie kodów rabatowych.** `GET /api/v1/guest-carts/{id}?discountCode=…` zwraca różne wyniki dla istniejącego i nieistniejącego kodu:

```
discountCode=SR7X     -> 200 {"discount":{"status":"applied","code":"SR7X","amountGross":1745,"kind":"percent","value":5}}
discountCode=NIEMA123 -> 200 {"discount":{"status":"rejected","reason":"invalid","detail":"Ten kod jest nieprawidłowy albo nieaktywny."}}
400 prób zgadywania, 8 równolegle: 400x 200 w 10,6 s (~38/s), bez limitu
```

Przy kodzie 3–32 znaków `[A-Z0-9-]` (spec kodów rabatowych, Q4) kody krótkie i słownikowe (`LATO10`) da się odgadnąć. Brak limitu na sprawdzaniu kodów
jest znaną pozycją „przed publicznym demo” (`docs/features/discount-codes/spec.md:30`).

**Wynik: znaleziska.**

**Znaleziska.**

- **SR-16 (High): jeden nieuwierzytelniony adres blokuje stany całego katalogu zamówieniami bez płatności.** Dowód i wyliczenie wyżej: 10
  zamówień (dozwolone przez limit) rezerwuje połowę wariantów, ~21 całość. Waga High: bez uwierzytelnienia, tanio, skutek to brak możliwości
  zakupu w całym sklepie (sprzedaż jest główną funkcją), a limit D5 tego nie ogranicza. Znane (D16, D19), dlatego jest w sekcji
  „Blokery publicznego demo”. Dla prawdziwego sklepu waga byłaby taka sama.
- **SR-17 (Medium): brak limitu na zakładaniu i zapisach koszyków gościa.** 42 koszyki na sekundę z jednego hosta, ~145 B każdy bez linii,
  nic nie ogranicza liczby ani rozmiaru (do 50 linii × 99 szt.), TTL 30 dni. Medium: rośnie baza i obciążenie, ale bez utraty
  danych i bez dostępu do cudzych danych; zadziała na miesiące, nie minuty. Podobnie bez limitu są odczyty katalogu (SR-10).
- **SR-18 (Medium): brak limitu na sprawdzaniu kodów rabatowych, które odpowiedzią wskazuje, czy kod istnieje.** Pokazane wyżej
  (~38 prób na sekundę z jednego hosta). Medium: na demo bez prawdziwych pieniędzy skutek jest mały; w prawdziwym sklepie byłby
  wyższy (kody procentowe do 90%), więc zgłoszenie z tej samej pozycji specyfikacji kodów.

## Blokery publicznego demo

Tryb publicznego demo (#179, D19) nie powinien się zaczynać, dopóki:

1. **SR-16 (High): limit rezerwowanego towaru na zamówienie gościa** (blokada stanów całego katalogu jednym adresem; D16, D19):
   zgłoszenie już istnieje, #332 „Limit rezerwowanego towaru na zamówienie gościa” (otwarte), i jest na liście blokerów w #179. Dowód SR-16
   ma trafić komentarzem do #332 (propozycja nr 1), bez nowego zgłoszenia.
2. **Weryfikacja na serwerze, że origin odrzuca ruch z pominięciem Cloudflare (niezweryfikowana, D19).** Bez niej limity z punktu 1
   opierają się na założeniu, że `CF-Connecting-IP` nie da się podrobić; krok 5 runbooka `infra/k8s/README.md:155-176` nie był wykonany
   (brak serwera). Wynik próby ma trafić do komentarza w #179.

Znalezisk **Critical** nie ma. Inne blokery z D19 (RODO dla danych gości #119) są poza zakresem tego przeglądu. Znaleziska Medium (SR-4,
SR-7, SR-8, SR-14, SR-17, SR-18) nie są blokerami według D12; SR-4 warto poprawić przed pierwszym wdrożeniem (voter na 11 trasach to mała zmiana), podobnie SR-7 i SR-8: wchodzą w grę
dopiero razem i kosztują dużo mniej przed produkcją niż po niej.

## Propozycje zgłoszeń

Do triage użytkownika; niczego nie założono ani nie dopisano w GitHubie. Pozycje 2–11 to nowe zgłoszenia z etykietami: typ (`bug` albo `tech-debt`)
i `agent`; pozycja 1 to komentarz do istniejącego #332, nie nowe zgłoszenie. Kolejność według wagi.

| # | Tytuł | Etykiety | Waga | Znaleziska |
|---|---|---|---|---|
| 1 | Dopisać dowód SR-16 komentarzem do #332 „Limit rezerwowanego towaru na zamówienie gościa” (blokada stanów całego katalogu) | komentarz do istniejącego zgłoszenia (bez nowych etykiet) | High | SR-16 |
| 2 | Limit na zakładaniu i zapisach koszyków gościa | `bug`, `agent` | Medium | SR-17 |
| 3 | Limit na sprawdzaniu kodów rabatowych | `bug`, `agent` | Medium | SR-18 |
| 4 | Osobne sekrety dla każdej usługi zamiast jednego `envFrom` | `tech-debt`, `agent` | Medium | SR-7 |
| 5 | `NetworkPolicy`, hasło Redisa i uwierzytelnianie usług wewnętrznych | `tech-debt`, `agent` | Medium | SR-8 |
| 6 | Aktualizacja Symfony do 8.1.11 | `tech-debt`, `agent` | Medium | SR-14 |
| 7 | Odczyty panelu tylko dla pracownika z rolą (`#[IsGranted]` na 11 trasach GET, `ROLE_STAFF` tylko z rolą personelu) | `bug`, `agent` | Medium | SR-4 |
| 8 | `securityContext` podów i obraz `api` bez roota | `tech-debt`, `agent` | Low | SR-9 |
| 9 | Zawężenie ekspozycji: `/realms/<realm>/`, API poza Varnishem, symulator, `6443` | `tech-debt`, `agent` | Low | SR-10, SR-11, SR-12 |
| 10 | Polskie komunikaty błędów frameworka i limit rozmiaru treści JSON | `bug`, `agent` | Low | SR-2, SR-3 |
| 11 | Drobne utwardzenie: `X-Powered-By`, dane za obrazem, polityka haseł Keycloaka, klucz IPv6, `npm audit` dev | `tech-debt`, `agent` | Low | SR-1, SR-5, SR-6, SR-13, SR-15 |

**1. Dopisać dowód SR-16 komentarzem do #332** (komentarz do istniejącego zgłoszenia „Limit rezerwowanego towaru na zamówienie gościa”, otwarte, jest na liście blokerów #179; High; SR-16).
Opis: jedno zamówienie gościa rezerwuje do 50 linii po 99 szt. na 35 min, a limit 10 zamówień na 15 min z adresu nie ogranicza ilości. #332 ma wprowadzić limit
sztuk (albo linii) na zamówienie gościa albo na adres w oknie; zmienia reguły `Cart` i `Ordering` (D16), więc najpierw decyzja produktowa. Do komentarza: wyliczenie, że bez limitu ilości
jeden adres blokuje cały katalog demo w około 30–35 min.
Dowód: 10 zamówień po 50 linii z ilością równą dostępnej → 500 z 1006 wariantów w pełni zarezerwowanych, 17 771 sztuk (stos dev, skrypt `python3` poza repo opisany w punkcie 11).

**2. Limit na zakładaniu i zapisach koszyków gościa** (`bug`, `agent`; Medium; SR-17).
Opis: `POST /api/v1/guest-carts` i zapisy linii nie mają limitu; dodać limiter (np. na adres, jak `order_placement`) i skrócić albo ograniczyć
TTL pustych koszyków; sklep (BFF) już przekazuje adres w `CF-Connecting-IP`.
Dowód: 500 koszyków w 12 s z jednego hosta (~42/s), ~145 B na pusty koszyk, brak `RateLimit::ROUTE_DEFAULT` na trasach koszyka (punkt 11).

**3. Limit na sprawdzaniu kodów rabatowych** (`bug`, `agent`; Medium; SR-18).
Opis: `GET /api/v1/guest-carts/{id}?discountCode=…` i `/me/cart` odróżniają kod istniejący od nieistniejącego bez limitu; limit na adres (i na `sub` dla klienta),
ewentualnie jednolita odpowiedź. Pozycja „rate limiting sprawdzania kodów” jest już w `docs/features/discount-codes/spec.md:30`.
Dowód: 400 prób w 10,6 s (~38/s), `applied` dla istniejącego, `rejected/invalid` dla nieistniejącego kodu (punkt 11).

**4. Osobne sekrety dla każdej usługi** (`tech-debt`, `agent`; Medium; SR-7).
Opis: `shop-secrets` w `envFrom` trafia do `store` i `api` razem z hasłem administratora Keycloaka, hasłem Postgresa, sekretami Garage i RabbitMQ.
Rozdzielić `Secret` (lub `secretKeyRef` tylko dla potrzebnych kluczy) i zmienić `infra/scripts/init-env.sh`/chart/`seal-secrets.sh` w jednym PR (zasada chartu w `CLAUDE.md`).
Dowód: render `prod`, `envFrom: secretRef: shop-secrets` w 15 z 19 kontenerów głównych (`store.yaml:30`, `api.yaml:114` i `:134`); lista w punkcie 7.

**5. `NetworkPolicy`, hasło Redisa i uwierzytelnianie usług wewnętrznych** (`tech-debt`, `agent`; Medium; SR-8).
Opis: domyślne `deny` w przestrzeni nazw i polityki zezwalające tylko na potrzebne połączenia (store→api, varnish, redis; api→postgres, redis, es,
rabbitmq, garage, keycloak…; Traefik→usługi z ingressów); `requirepass` w Redisie z sesjami sklepu; sprawdzić, że test dymny przechodzi.
Dowód: 0 `NetworkPolicy` w 47 dokumentach renderu `prod`; z kontenera `api`: Redis `PING` bez hasła, Elasticsearch i Mailpit 200 bez uwierzytelnienia, `session:*` z `refreshToken` (punkt 8).

**6. Aktualizacja Symfony do 8.1.11** (`tech-debt`, `agent`; Medium; SR-14).
Opis: `composer update "symfony/*" --with-all-dependencies` w obrębie 8.1, ponowne `composer audit`, testy `api`.
Dowód: `composer audit` → 18 advisories w 11 pakietach, wszystkie `<8.1.11` (punkt 9).

**7. Odczyty panelu tylko dla pracownika z rolą** (`bug`, `agent`; Medium; SR-4).
Opis: dodać `#[IsGranted]` z voterem „czyta pracownik z rolą `admin`/`support`/`warehouse`/`marketing`” na 11 trasach GET bez ochrony (lista w punkcie 4) i/albo
zwracać z `StaffUser::getRoles()` `ROLE_STAFF` tylko dla konta z rolą personelu (zmiana `VIEW_PRODUCTS`, który dziś przepuszcza każdego); dodać test
funkcjonalny po wszystkich trasach GET `/api/v1/admin/*` z routera: token bez roli → 403, token klienta → 401, żeby nowa trasa bez ochrony go łamała.
Dowód: konto bez roli: 200 na 14 odczytach (m.in. `/admin/products`, `/stock`, `/prices`, `/promotions`, `/discount-codes`, `/discount-codes/{id}`, `/dashboard`); 11 tras GET bez `#[IsGranted]` (punkt 4).

**8. `securityContext` podów i obraz `api` bez roota** (`tech-debt`, `agent`; Low; SR-9).
Opis: `runAsNonRoot`, `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]`, `seccompProfile: RuntimeDefault`, tam gdzie się da `readOnlyRootFilesystem`;
`USER` w obrazie `api` (FrankenPHP na porcie 80 wymaga `setcap` albo portu > 1024).
Dowód: `docker inspect` obrazu `prod` zbudowanego z rewizji → `User=` puste (uid 0); render `prod` bez `securityContext` w żadnym kontenerze (punkt 8).

**9. Zawężenie ekspozycji** (`tech-debt`, `agent`; Low; SR-10, SR-11, SR-12).
Opis: (a) ingress `auth` tylko do ścieżek potrzebnych przeglądarce zamiast całego `/realms/<realm>/` (wyłączyć `clients-registrations`);
(b) `api.<domena>`: odczyty katalogu przez Varnisha albo reguła Cloudflare, symulator poza `api.<domena>` albo za wyłącznikiem w profilu `prod`
(D14); (c) `6443` w `ufw` tylko z adresu operatora, jeśli jest stały.
Dowód: render `prod` (ingress `auth`, `api`), `server-bootstrap.sh:141`, `clients-registrations` → 403 Trusted Hosts (punkt 8).

**10. Polskie komunikaty błędów frameworka i limit rozmiaru JSON** (`bug`, `agent`; Low; SR-2, SR-3).
Opis: `ProblemDetailsSubscriber` mapuje `detail` 400/401/404/405/415 i `violations[].title` z deserializacji/`MapQueryString` na polskie; Caddy `request_body max_size`
(np. 1 MB) dla tras innych niż upload.
Dowód: `The request is invalid.`, `Resource not found.`, `Authentication is required.`, `This value should be of type null|string.`; JSON 30 MB → 422 (punkty 2 i 10).

**11. Drobne utwardzenie** (`tech-debt`, `agent`; Low; SR-1, SR-5, SR-6, SR-13, SR-15).
Opis: `poweredByHeader: false`; odrzucać dane za końcem obrazu przy uploadzie (albo przepisywać obraz przed zapisem); `passwordPolicy` w realmach
(min. długość, nie hasło z nazwy użytkownika); rozważyć klucz limitu /56 albo /48 dla IPv6; `npm audit fix` dla zależności deweloperskich sklepu i panelu.
Dowód: `X-Powered-By: Next.js` na obrazie `prod`; PNG z doklejonym `<script>` → 201; brak `passwordPolicy` w `shop.json`/`backoffice.json`; `npm audit`: 7 i 11 moderate (dev).

## Czego przegląd nie obejmuje

- **Origin na serwerze i Cloudflare:** brak serwera i wdrożenia, więc próba ominięcia Cloudflare, reguły Cloudflare (rate limiting, WAF, cache,
  Access), certyfikat Origin CA i TLS nie były sprawdzane (D19, krok 5 runbooka).
- **Działający klaster:** chart zbadano z renderu; nie uruchamiano k3s, Traefika, Sealed Secrets ani Argo CD (nie powtarzano testu dymnego
  `make -C infra/k8s smoke`; nagłówki panelu sprawdzono ręcznie na obrazie `prod`). `environments/prod/secrets` jest jeszcze puste, więc
  zaszyfrowane sekrety nie były oceniane.
- **Pełna wersja produkcyjna stosu:** sklep w trybie `next dev`, API w `APP_ENV=dev` (próby limitów i błędów w `APP_ENV=prod` na osobnych
  kontenerach z obrazu dev); wydajność i odporność na obciążenie (raport pomiaru: `load-test-2026-11-10.md`).
- **Keycloak:** tylko pliki realmów i próby na stosie dev; nie sprawdzano wymuszenia TOTP (tokeny z tymczasowych kont bez TOTP przez `directAccessGrants`,
  usunięte), maili, ochrony przed zgadywaniem haseł w działaniu (D17), ani zachowania polityki Trusted Hosts za Cloudflare.
- **Płatności:** podpisy webhooków czytane w kodzie (`hash_equals`), ale nie próbowano poprawnie podpisanych żądań ani powtórzeń
  (`timestamp` karty); symulator ocenia tylko jego uwierzytelnianie i ekspozycję.
- **Nieobjęte kategorie:** RODO i anonimizacja (#119), integralność łańcucha dostaw (workflowy GitHub, klucz `DEPLOY_KEY`, pinowanie akcji, skan
  obrazów bazowych), kopie zapasowe, monitoring, dostęp przez SSH, Brevo, e-maile transakcyjne, logowanie społecznościowe.
- **Testy z ręki:** nie wykonano pełnego skanowania DAST ani fuzzingu poza ~60 próbami z punktów 2–5 i 10; XSS sprawdzono statycznie (`grep`),
  nie w przeglądarce. Odpowiedzi na IDOR zamówień klienta i płatności gościa zakładam za testami funkcjonalnymi
  (`make -C apps/api test PHPUNIT_ARGS="--filter 'CustomerOrder|CustomerPayment|GuestPayment'"` → `OK (183 tests, 2997 assertions)`), nie
  powtórzono ich ręcznie na stosie.
