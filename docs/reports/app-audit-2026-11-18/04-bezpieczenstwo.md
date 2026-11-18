# 04. Bezpieczeństwo aplikacji (API, sklep, panel, konfiguracja usług)

Audyt statyczny migawki `main` (`ef204620`). Przegląd obronny własnego kodu, bez uruchamiania czegokolwiek.

## Zakres i metoda

Przeczytane w całości albo w istotnej części: ok. 110 plików, do tego przeszukanie wzorcami (`grep`) całego
`apps/api/src` (1290 plików PHP), `apps/store`, `apps/admin` i `infra`.

- **API, konfiguracja**: `config/packages/security.yaml`, `framework.yaml`, `nelmio_cors.yaml`, `rate_limiter.yaml`,
  `monolog.yaml`, `sentry.yaml`, `messenger.yaml`, `nelmio_api_doc.yaml` (nagłówek), `routes.yaml`, `bundles.php`,
  `public/index.php`, `Dockerfile`, `docker/php/conf.d/*.ini`, `docker/frankenphp/Caddyfile`, `.dockerignore`.
- **API, kod**: pełna lista tras (`#[Route]`, 74 trasy) i pokrycie `IsGranted` na każdym kontrolerze `/api/v1/admin`;
  `Shared/Infrastructure/Http/*` (adres klienta, limity, problem details, nagłówki), `Shared/Infrastructure/Security/*`,
  dostawcy użytkowników i votery (`Backoffice`, `Customer`, `Ordering`, `Pricing`), kontrolery i DTO koszyka, zamówień,
  płatności i webhooków, `PlaceOrderHandler`, `StartPaymentHandler`, `HandleProviderWebhookHandler`, adaptery dostawców
  płatności, symulator płatności (`PaymentSimulator/Http/*`), upload zdjęć (`UploadProductImageController`,
  `FlysystemProductImageStorage`, `ImgproxyUrlGenerator`), zapytania DBAL z dynamicznym `ORDER BY`, budowa zapytań
  Elasticsearch (`ProductSearchQuery`), wszystkie wywołania loggera, źródła losowości.
- **Sklep**: `next.config.ts`, `src/auth/*` (ciasteczka, sesje, OIDC, odświeżanie, `returnTo`, limit logowania),
  `app/auth/*/route.ts`, BFF koszyka, kasy i płatności, `app/api/revalidate`, `app/api/site`, strony z `cookies()`,
  wszystkie użycia `dangerouslySetInnerHTML`, przekierowania.
- **Panel**: `src/auth/*`, przechowywanie tokenów, `v-html`/`innerHTML`/storage (brak), `docker/nginx.conf`,
  `security-headers.conf`, `40-admin-config.sh`.
- **Infra**: `infra/docker-compose.yml`, `infra/keycloak/realms/*.json`, `infra/varnish/default.vcl`,
  `infra/scripts/init-env.sh`, `infra/garage/*`, chart Helm (`values.yaml`, `environments/prod/values.yaml`, `ingress`,
  `middlewares`, `secret`, `rbac`, `_helpers.tpl`, `_keycloak.tpl`, `api`, `store`, `keycloak`, `imgproxy`, `varnish`,
  `redis`, `elasticsearch`, `mailpit`, `rabbitmq`), `argocd/install/traefik.yaml`, reguły maskowania logów w
  `argocd/apps/monitoring.yaml`, fragmenty runbooka `infra/k8s/README.md`.
- **Specyfikacje**: `docs/vision.md` („Bezpieczeństwo”), `docs/features/hardening/spec.md` (całość),
  `docs/features/discount-codes/spec.md` (zakres, decyzje), fragmenty `deploy/spec.md`.

Każde znalezisko sprawdziłem pod kątem tego, czy nie rozwiązuje go inna warstwa (firewall, subscriber, VCL, ingress,
Cloudflare) i czy specyfikacja nie przyjmuje go świadomie. Świadome decyzje są oznaczone.

## Ocena ogólna obszaru: 8/10

Rdzeń bezpieczeństwa aplikacji jest zrobiony wyraźnie powyżej przeciętnej dla projektu tej wielkości:
uwierzytelnianie (dwa realmy, dwa bezstanowe firewalle, RS256, issuer, audience), autoryzacja (votery na rolach,
test, który bierze trasy panelu z routera), ochrona przed IDOR (identyfikator klienta zawsze z tokena, gość z tokenem
256 bitów porównywanym w stałym czasie), walidacja wejścia (DTO bez rzutowania, wartości nigdy w komunikatach),
webhooki (HMAC, `hash_equals`, okno czasu, deduplikacja zdarzeń, kontrola kwoty), BFF sklepu (tokeny tylko w Redisie,
podpisane ciasteczka `HttpOnly`, PKCE, state, nonce, rotacja sesji, ochrona CSRF), sekrety (generowane, nigdy w repo,
Sealed Secrets), błędy bez wycieków. Nie znalazłem SQL injection, XSS, SSRF, open redirectu, IDOR ani obejścia
autoryzacji.

Punkty odjęte są za dwie rzeczy. Pierwsza to **nadużycia przepływów biznesowych** dostępnych bez logowania: blokowanie
stanów zamówieniami bez płatności (znane, odłożone) i zgadywanie kodów rabatowych bez żadnego limitu. Druga to
**brak obrony w głąb w klastrze**: jeden sekret ze wszystkimi hasłami w każdym podzie, brak `NetworkPolicy`, usługi
bez uwierzytelniania, API jako root. Żadna z nich nie jest podatnością wykorzystywalną z internetu sama w sobie, ale
razem sprawiają, że przejęcie dowolnego jednego poda oznacza przejęcie wszystkiego.

## Znaleziska

Legenda: **podatność** = da się wykorzystać dziś podaną ścieżką; **utwardzenie** = zmniejsza skutki innego błędu.

### 1. Gość może zablokować stany magazynowe zamówieniami bez płatności

- **Waga**: High. **Klasa**: RYZYKO (znane i świadomie odłożone: `hardening/spec.md` D16, D19). **Rodzaj**: podatność.
- **Dowód**:
  - `apps/api/src/Ordering/Domain/Order.php:30-31`: `MAX_LINES = 50`, `PAYMENT_TIME = '+30 minutes'`;
    `apps/api/src/Ordering/Domain/OrderLine.php:15`: `MAX_QUANTITY = 99`.
  - `apps/api/src/Ordering/Application/Command/PlaceOrderHandler.php:134`: `$this->reservations->reserve($id, $cartLines);`
    (rezerwacja w chwili złożenia, przed płatnością).
  - `apps/api/config/packages/rate_limiter.yaml:17-21`: `order_placement: … limit: 10, interval: '15 minutes'`, klucz
    `guest:<adres>`.
  - `docs/features/hardening/spec.md:59` (D16): „Jedno zamówienie gościa może zarezerwować do 50 linii po 99 sztuk […]
    na 35 min”.
- **Ścieżka ataku**: bez konta: `POST /api/v1/guest-carts`, 50× `POST …/items` (po 99 szt. albo tyle, ile jest),
  `POST /api/v1/orders` z dowolnym adresem e-mail. Zamówienie nie jest opłacane. Jeden adres IP robi to 10 razy na
  15 minut, czyli trzyma w rezerwacji do 500 pozycji wariantów naraz przez 35 minut i może to powtarzać bez końca.
  Kilka adresów (albo zakres IPv6 większy niż /64) blokuje cały katalog demo (ok. 1000 wariantów).
- **Konsekwencja**: klienci widzą „brak w magazynie” i nie mogą kupić; sprzedawca traci sprzedaż bez żadnego śladu
  płatności. Atak jest tani i nie wymaga żadnej wiedzy.
- **Rekomendacja**: limit sztuk i linii na zamówienie gościa (to, co opisuje #332) to minimum. Skuteczniejsze przy
  docelowej skali: limit łącznej liczby nieopłaconych zamówień na adres i na e-mail, krótszy czas na płatność dla gościa
  i reguła Cloudflare (challenge) na `POST /api/checkout`.
- **Pewność**: wysoka. Sprawdziłem handler złożenia, limiter i specyfikację; specyfikacja sama uznaje to za bloker trybu
  publicznego demo. Zgłaszam, bo to najpoważniejsza wykorzystywalna słabość obszaru, a nie nowe odkrycie.

### 2. Kody rabatowe można zgadywać bez żadnego limitu (publiczna wyrocznia)

- **Waga**: Medium. **Klasa**: BŁĄD (specyfikacja kodów odkłada limit „przed publicznym demo”, ale limitu nie ma w
  kodzie ani w konfiguracji). **Rodzaj**: podatność.
- **Dowód**:
  - `apps/api/config/packages/security.yaml:52`: `- { path: ^/api/v1/guest-carts(/|$), roles: PUBLIC_ACCESS }`.
  - `apps/api/src/Cart/UI/Http/GuestCartController.php:65`: trasa `cart_guest_get` bez `RateLimit::ROUTE_DEFAULT`
    (limiter mają tylko trasy zamówień, płatności i webhooków: `grep RateLimit::` zwraca 5 tras).
  - `apps/api/src/Pricing/Domain/DiscountCode/DiscountCode.php:137-146`: odpowiedź rozróżnia `invalid`, `used_up`
    i `below_minimum` (z kwotą minimum); kod poprawny zwraca rabat (`AppliedCartDiscount`).
  - `docs/features/discount-codes/spec.md:30`: poza zakresem „rate limiting sprawdzania kodów (T2 CHECKOUT, przed
    publicznym demo)”; Q4: kod to 3–32 znaki `[A-Z0-9-]`, nadawany przez pracownika (dane demo: `WITAJ10`, `MINUS50`).
- **Ścieżka ataku**: gość zakłada koszyk i dodaje jedną pozycję, potem w pętli
  `GET /api/v1/guest-carts/{id}?discountCode=<kandydat>` (bezpośrednio na `api.<domena>` albo przez sklep:
  `GET /api/cart?discountCode=`). Odpowiedź od razu mówi, czy kod istnieje, czy jest wyczerpany i jakie ma minimum.
  Kody marketingowe są słownikowe (`LATO15`, `BLACK20`), więc słownik kilku tysięcy kombinacji wystarcza.
- **Konsekwencja**: sprzedawca traci marżę na kodach, które miały trafić do wybranej grupy (kod bez limitu użyć działa
  dla każdego); kod z limitem użyć można wyczerpać albo zablokować zamówieniami z punktu 1.
- **Rekomendacja**: limiter na odczyt koszyka **z parametrem `discountCode`** (np. 20 prób na 15 minut na adres, ten
  sam mechanizm `RateLimit::ROUTE_DEFAULT`; sklep musi wtedy przekazywać adres klienta także w BFF koszyka, tak jak w
  kasie). Dodatkowo jeden komunikat dla `invalid` i `used_up` w podglądzie.
- **Pewność**: wysoka. Sprawdziłem wszystkie użycia `RateLimit::`, subscriber limitów, VCL (ścieżki koszyka nie
  przechodzą przez Varnish) i BFF sklepu (brak własnego limitu). Nie zweryfikowałem reguł Cloudflare (poza repo).

### 3. Jeden sekret ze wszystkimi hasłami trafia do każdego poda

- **Waga**: Medium. **Klasa**: RYZYKO. **Rodzaj**: utwardzenie (ogranicza skutki przejęcia jednego procesu).
- **Dowód**:
  - `infra/k8s/charts/shop/templates/_helpers.tpl:49-53`:
    ```
    {{- define "shop.envFromSecret" -}}
    envFrom:
      - secretRef:
          name: {{ .Values.secrets.name }}
    ```
  - użycia: `store.yaml:34`, `varnish.yaml:5`, `imgproxy.yaml:33`, `garage.yaml:2`, `rabbitmq.yaml:5`,
    `postgres.yaml:5`, `keycloak.yaml:35`, `api.yaml:107,118,139`.
  - zawartość sekretu (`secret.yaml:16-34`): `POSTGRES_PASSWORD` (superużytkownik), `KC_BOOTSTRAP_ADMIN_PASSWORD`,
    `IMGPROXY_KEY`/`SALT`, `STORE_CLIENT_SECRET`, `STORE_SESSION_SECRET`, sekrety webhooków płatności, klucze Garage.
  - to samo w compose: każda usługa ma `env_file: ./.env.local`.
- **Warunki**: wykonanie kodu albo odczyt środowiska w jednym z procesów wystawionych do internetu: serwer Next.js
  sklepu, imgproxy (dekoduje obrazy), Keycloak. Prawdopodobieństwo niskie, ale niezerowe (błędy tej klasy w Next.js
  i bibliotekach obrazów zdarzają się regularnie).
- **Konsekwencja**: błąd w sklepie, który dziś dałby napastnikowi sesje klientów, daje mu też hasło superużytkownika
  bazy, hasło administratora Keycloaka (realm `master`, czyli oba realmy), sekrety webhooków (oznaczanie zamówień jako
  opłacone) i klucz podpisu imgproxy. To jest też sprzeczne z zasadą z `CLAUDE.md`: „Klucz podpisu nigdy nie trafia do
  frontów” (proces sklepu ma `IMGPROXY_KEY` w środowisku, choć go nie używa).
- **Rekomendacja**: zamiast `envFrom` całego sekretu: `env` z `secretKeyRef` tylko dla kluczy, których usługa
  potrzebuje (sklep: 3 klucze; Varnish: 1; imgproxy: 4). Zmiana mechaniczna, sprawdzalna testem renderu. `make check`
  już pilnuje listy kluczy, więc można dopisać test „usługa X nie widzi klucza Y”.
- **Pewność**: wysoka co do faktu (szablony); skutek zależy od innego błędu.

### 4. Brak segmentacji sieci w klastrze, a Redis, Elasticsearch i Mailpit nie mają uwierzytelniania

- **Waga**: Medium. **Klasa**: RYZYKO. **Rodzaj**: utwardzenie.
- **Dowód**:
  - w `infra/k8s/charts/shop/templates/` nie ma żadnego `NetworkPolicy` (`grep -ri networkpolicy`: brak wyników).
  - `elasticsearch.yaml:10-11`: `xpack.security.enabled: "false"`; `redis.yaml`: brak hasła;
    `store.yaml:17-18`: `REDIS_URL: redis://redis:6379`.
  - `apps/store/src/auth/session-store.ts:67`: `redis.set(sessionKey(id), JSON.stringify(data), …)`, gdzie `data` to
    `accessToken`, `refreshToken`, `idToken` klienta otwartym tekstem.
  - `environments/prod/values.yaml`: `orderMail: mailpit`, czyli potwierdzenia zamówień (imię, adres, pozycje) leżą w
    Mailpicie bez hasła.
- **Warunki**: jak w punkcie 3: przejęty dowolny pod (także Varnish, imgproxy, Mailpit).
- **Konsekwencja**: z dowolnego poda da się odczytać sesje wszystkich zalogowanych klientów (i działać w ich imieniu
  przez refresh token), wyzerować liczniki limitów, podmienić dokumenty w indeksie wyszukiwarki (nazwy i ceny „od” na
  listach) i przeczytać pocztę z danymi osobowymi. Ten sam Redis służy sklepowi (sesje) i API (limity).
- **Rekomendacja**: `NetworkPolicy` domyślnie „deny” w namespace `shop` i reguły według rzeczywistych połączeń (k3s
  egzekwuje je bez dodatków); `requirepass` dla Redisa. Dla ES wystarczy polityka sieciowa (tylko `api`, `worker`,
  joby). To kilkadziesiąt linii YAML, bez zmian w aplikacjach.
- **Pewność**: wysoka co do konfiguracji; nie sprawdziłem, czy klaster ma dodatkowe polityki poza chartem (w
  `argocd/` ich nie ma).

### 5. Publiczne endpointy zapisu i kosztownego odczytu bez limitu; limit ciała żądania 70 MB dla wszystkich tras

- **Waga**: Low. **Klasa**: RYZYKO (limity koszyka gościa są jawnie poza zakresem HARDENING). **Rodzaj**: podatność
  na DoS o małej dźwigni.
- **Dowód**:
  - `apps/api/src/Cart/UI/Http/GuestCartController.php:43`: `POST /api/v1/guest-carts` bez limitera; koszyk gościa
    żyje 30 dni (`Cart.php:20`), sprzątanie co godzinę usuwa tylko wygasłe (`jobs.yaml:257-277`).
  - `apps/api/docker/php/conf.d/app.ini:9-10`: `upload_max_filesize = 64M`, `post_max_size = 70M` (globalnie);
    `docker/frankenphp/Caddyfile`: brak `request_body { max_size }`.
  - `GET /api/v1/products?q=` (zapytanie rozmyte do ES) bez limitu po stronie aplikacji.
- **Warunki**: napastnik wysyła ruch bezpośrednio na `api.<domena>`; ochrona zależy wtedy wyłącznie od Cloudflare.
- **Konsekwencja**: zapełnianie tabel koszyka wierszami na 30 dni; żądania JSON po kilkadziesiąt MB do publicznych tras
  (`…/items`), z których każde zajmuje wątek FrankenPHP i do 256 MB pamięci, zanim walidacja je odrzuci.
- **Rekomendacja**: w Caddyfile limit ciała np. 64 KB dla wszystkiego poza `/api/v1/admin/product-images`; limiter
  zakładania koszyków na adres (ten sam mechanizm); reguła rate limiting Cloudflare dla `api.` jako druga linia.
- **Pewność**: średnia. Konfigurację przeczytałem; nie mierzyłem, ile pamięci faktycznie zajmuje duże ciało ani ile
  wątków ma FrankenPHP w `prod`.

### 6. Realmy Keycloaka nie mają polityki haseł

- **Waga**: Low. **Klasa**: BŁĄD (brak ustawienia). **Rodzaj**: utwardzenie.
- **Dowód**: `infra/keycloak/realms/shop.json` i `backoffice.json` nie zawierają `passwordPolicy` (Keycloak przyjmuje
  wtedy dowolne hasło, także jednoznakowe); `keycloak-config.sh` jej nie ustawia.
- **Konsekwencja**: klient może ustawić hasło `1`; ochronę daje tylko `bruteForceProtected: true`. W panelu skutki
  łagodzi obowiązkowy TOTP (`browser-otp`) i Cloudflare Access na `admin.<domena>`.
- **Rekomendacja**: `passwordPolicy` w obu plikach (np. `length(12) and notUsername and notEmail` dla `shop`, mocniejsza
  dla `backoffice`) oraz krok w jobie `keycloak-config`, bo istniejącego realmu job nie nadpisuje.
- **Pewność**: wysoka (pliki realmów i skrypt joba).

### 7. API i worker działają jako root, bez `securityContext`

- **Waga**: Low. **Klasa**: RYZYKO. **Rodzaj**: utwardzenie.
- **Dowód**: `apps/api/Dockerfile:62-68` (etap `prod` bez `USER`); `_helpers.tpl:236-280` (`shop.deployment` bez
  `securityContext`; jedyny w chartcie to `appArmorProfile: Unconfined` RabbitMQ, `rabbitmq.yaml:17-19`). Dla
  porównania sklep ma `USER node` (`apps/store/Dockerfile:52`), panel `nginx-unprivileged`.
- **Konsekwencja**: wykonanie kodu w API daje roota w kontenerze (zapis do kodu aplikacji, łatwiejsza eskalacja przy
  błędzie środowiska uruchomieniowego kontenerów).
- **Rekomendacja**: `USER` w obrazie (FrankenPHP wspiera pracę bez roota na porcie >1024) oraz w `shop.deployment`:
  `runAsNonRoot`, `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]`, `seccompProfile: RuntimeDefault`.
- **Pewność**: wysoka co do braku ustawień; nie uruchamiałem obrazu, żeby potwierdzić efektywny UID.

### 8. Sklep wysyła na dowolny adres e-mail treść wpisaną przez gościa

- **Waga**: Low. **Klasa**: RYZYKO (dziś nieaktywne: `orderMail: mailpit`). **Rodzaj**: podatność po włączeniu Brevo.
- **Dowód**: `apps/api/src/Ordering/Application/OrderConfirmationText.php:29,40-41`:
  `'Dzień dobry '.$order->fullName.','`, dalej `$order->fullName`, `$address->street`; adres odbiorcy to `email`
  z ciała żądania gościa (`PlaceGuestOrderRequest`), bez weryfikacji. Pola mają do 100 znaków i dopuszczają adresy URL.
- **Ścieżka ataku**: `fullName = "odbierz zwrot: https://zly.example/…"`, `email = ofiara`. Ofiara dostaje wiadomość
  z domeny sklepu (poprawne SPF/DKIM) z linkiem napastnika, 10 na 15 minut z jednego adresu.
- **Konsekwencja**: reputacja domeny nadawczej i phishing „od sklepu”.
- **Rekomendacja**: przed włączeniem `orderMail: brevo` odrzucać `://` i `www.` w imieniu i ulicy (albo nie umieszczać
  imienia w powitaniu) i dodać limit zamówień na adres e-mail odbiorcy.
- **Pewność**: wysoka co do treści wiadomości; ocena skutku zależy od konfiguracji poczty.

### 9. Drobne utwardzenia (bez wykazanej szkody)

- **Waga**: Low. **Klasa**: PREFERENCJA.
- **CSP sklepu bez `script-src`** (`apps/store/next.config.ts:14`). Świadomy kompromis z ISR (D10). W kodzie nie ma
  dziś żadnego ujścia XSS (`dangerouslySetInnerHTML` tylko dla JSON-LD z ucieczką `<`), więc ryzyko jest teoretyczne;
  warto wrócić do tematu, gdy pojawi się treść HTML od pracowników (opisy produktów).
- **`redirectUris` klienta `admin` z gwiazdką** (`infra/keycloak/realms/backoffice.json:108`: `"${ADMIN_URL}/*"`).
  Panel używa tylko `/auth/callback`; PKCE ogranicza skutki, ale dokładny adres nic nie kosztuje.
- **Token dostępu gościa do zamówienia** jest w bazie otwartym tekstem i nie wygasa
  (`DoctrineOrderRepository.php:215`). Daje tylko rozpoczęcie płatności i status bez danych osobowych, więc wystarczy
  to odnotować; skrót SHA-256 w bazie zamknąłby też wyciek z kopii zapasowej.
- **Origin ufa samym adresom IP Cloudflare** (`environments/prod/values.yaml`, `ipAllowList`). Nie ma Authenticated
  Origin Pulls (mTLS) ani tunelu, więc ruch z innej strefy Cloudflare też przechodzi listę adresów. Nie wykazałem
  ścieżki ataku (router Traefika wymaga właściwego `Host`), dlatego to preferencja, nie ryzyko.

## OWASP Top 10 (2021)

| Kategoria | Stan |
|---|---|
| A01 Broken Access Control | W porządku: votery, identyfikator klienta z tokena, test wszystkich tras panelu. |
| A02 Cryptographic Failures | W porządku: RS256, HMAC-SHA256, CSPRNG, TLS na ingressie; uwaga w znalezisku 9 (token gościa). |
| A03 Injection | W porządku: parametry wiązane, kolumny sortowania z białej listy, zapytania ES jako struktury. |
| A04 Insecure Design | Znaleziska 1 i 2 (przepływy biznesowe bez ochrony przed nadużyciem). |
| A05 Security Misconfiguration | Znaleziska 3, 4, 5 i 7. |
| A06 Vulnerable Components | Nie zweryfikowano (bez `composer audit` i `npm audit`); wersje i obrazy są przypięte. |
| A07 Identification and Authentication Failures | Znalezisko 6; poza tym w porządku (TOTP, PKCE, rotacja sesji). |
| A08 Software and Data Integrity Failures | W porządku: obrazy po digestach, podpisane webhooki, JSON w RabbitMQ. |
| A09 Logging and Monitoring Failures | W porządku w zakresie maskowania i correlation ID; alerty na zdarzenia bezpieczeństwa: patrz „Braki”. |
| A10 SSRF | W porządku: żaden adres od użytkownika nie jest pobierany; imgproxy tylko `s3://` z podpisem. |

## OWASP API Security Top 10 (2023)

| Kategoria | Stan |
|---|---|
| API1 Broken Object Level Authorization | W porządku. |
| API2 Broken Authentication | W porządku; znalezisko 6 jako utwardzenie. |
| API3 Broken Object Property Level Authorization | W porządku: DTO tylko z jawnych pól, nieznane klucze ignorowane. |
| API4 Unrestricted Resource Consumption | Znaleziska 2 i 5. |
| API5 Broken Function Level Authorization | W porządku. |
| API6 Unrestricted Access to Sensitive Business Flows | Znaleziska 1 i 2. |
| API7 SSRF | W porządku. |
| API8 Security Misconfiguration | Znaleziska 3, 4 i 7. |
| API9 Improper Inventory Management | W porządku: dwa dokumenty OpenAPI, brak tras dokumentacji; symulator poza `/api/v1` jest opisany. |
| API10 Unsafe Consumption of APIs | W porządku: odpowiedzi dostawców ściśle walidowane, limity czasu 5 s. |

## Mocne strony

- **Walidacja JWT kompletna i przetestowana na obejścia.** `security.yaml:13-36`: dwa firewalle `stateless`, osobny
  issuer i audience na realm, `algorithms: ['RS256']`. `tests/Functional/Customer/MeEndpointTest.php:60-74,146-163`
  sprawdza token innego realmu, brak i złą audience, wygaśnięcie, obcy klucz, pomylenie algorytmów (HS256 kluczem
  publicznym) i `alg: none`.
- **Każda trasa panelu wymaga roli, a pilnuje tego test czytający router.** `security.yaml:43-44` (`\z` zamiast `$`,
  z komentarzem dlaczego) i `tests/Functional/Backoffice/AdminRouteAccessTest.php`: nowa trasa bez `ROLE_STAFF`
  psuje test bez jego zmiany. Votery rozróżniają role per operacja i per przejście statusu (`OrderVoter.php`).
- **IDOR zamknięty u źródła.** Klient: `GetCustomerOrderHandler.php:30-37` (obce zamówienie, zamówienie gościa
  i zły UUID dają ten sam wynik). Gość: token 32 bajtów z CSPRNG (`PlaceOrderHandler.php:151-154`), porównanie
  `hash_equals` (`AccessToken.php:36`), brak nagłówka to ten sam 404 (`GuestPaymentController.php:93-98`). Status
  płatności gościa nie zawiera danych osobowych.
- **Webhooki płatności.** `DemoCardProvider.php:114-148`: pusty sekret odrzuca wszystko, znacznik czasu ±300 s,
  `hash_equals`; `HandleProviderWebhookHandler.php:50-76`: deduplikacja `eventId` pod blokadą wiersza, kontrola kwoty,
  limiter przed odczytem ciała. Przyczyna odrzucenia trafia do logu, nie do odpowiedzi.
- **BFF sklepu.** Tokeny nigdy nie trafiają do przeglądarki (`session-store.ts`), ciasteczka `HttpOnly`, `SameSite=Lax`,
  `Secure` poza localhostem, podpisane HMAC i sprawdzane `timingSafeEqual` (`cookies.ts:11-27`); nowa sesja przy każdym
  logowaniu (`app/auth/callback/route.ts:28-33`); wylogowanie tylko `POST` ze sprawdzeniem `Origin` i `Sec-Fetch-Site`;
  zapisy BFF wymagają `Origin` sklepu i `application/json` (`cart/bff.ts:41-48`); `returnTo` odrzuca `//`, `\`
  i znaki sterujące (`return-to.ts`).
- **Panel.** Tokeny tylko w pamięci (`userManager.ts:8`), brak `v-html`, CSP `script-src 'self'`
  (`security-headers.conf`), wylogowanie po bezczynności, Cloudflare Access przed panelem (runbook).
- **Upload.** Typ po zawartości (`finfo`), wymiary z nagłówka, limit pikseli, nazwa pliku to SHA-256 treści, nigdy
  nazwa od użytkownika (`FlysystemProductImageStorage.php:30-58`); imgproxy z tymi samymi limitami i tylko `s3://`.
- **Błędy bez wycieków.** `ProblemDetailsSubscriber.php`: stałe teksty dla wyjątków frameworka, 5xx bez `detail`,
  także w trybie debug; `zend.exception_ignore_args = On` w `prod.ini`.
- **Sekrety i ekspozycja.** `init-env.sh` generuje wszystko losowo, `umask 077`; w repo są tylko konta demo, które
  chart usuwa z realmów (`_keycloak.tpl`: `unset $document "users"`); compose wiąże porty z `127.0.0.1`; ingress
  wystawia z Keycloaka tylko `/realms/shop/`, `/realms/backoffice/` i `/resources/`; render chartu odmawia
  `clientIpHeader` bez listy adresów.
- **Cache.** VCL przepuszcza do cache tylko publiczne odczyty katalogu, `PURGE` wymaga tokenu, koszyk, zamówienia
  i płatności dostają `private, no-store` w subscriberach o niskim priorytecie; strony ISR nie czytają ciasteczek.
- **Logi.** Kilkanaście reguł maskowania w Alloy przed wysyłką do Grafana Cloud (JWT, `Bearer`, hasła, parametry SQL,
  dane wiersza z błędu Postgresa), filtr zdarzeń Sentry po stronie SDK, Mailpit wyłączony ze zbierania.

## Zbędna złożoność

Nie stwierdziłem. Mechanizmy bezpieczeństwa są proporcjonalne; jedyne miejsce, gdzie kod jest wyraźnie dłuższy, niż
wymaga funkcja (parsowanie adresu IPv6 do /64 w dwóch językach: `ClientIp.php` i `login-rate-limit.ts`), wynika
z decyzji D4 i ma testy.

## Braki

- **Limit prób dla kodów rabatowych i limit rezerwacji gościa** (znaleziska 1 i 2): oba opisane w specyfikacjach jako
  „przed publicznym demo”, żaden niezaimplementowany.
- **RODO dla danych gości** (#119 według `hardening/spec.md` D11): brak retencji i anonimizacji zamówień gości; poza
  moim obszarem, ale to dane osobowe zbierane bez konta.
- **Alerty na zdarzenia bezpieczeństwa**: nie znalazłem metryki ani alertu na liczbę odpowiedzi 429, odrzuconych
  webhooków czy odmów 403 w panelu (alerty z treści logów są jawnie poza zakresem DEPLOY-OPS). Atak z punktu 1 albo 2
  nie zostawi sygnału, który ktoś zobaczy.
- **Wyłącznik symulatora płatności**: trasy `/payment-simulator/*` są zawsze zarejestrowane, także w obrazie `prod`.
  Dziś to funkcja demo (każdy z linkiem „płaci”), ale przed podłączeniem prawdziwego dostawcy potrzebny będzie
  przełącznik, który je usuwa.

## Czego nie zweryfikowano

- **Zachowania w działającym systemie**: niczego nie uruchamiałem. W szczególności: rzeczywista odpowiedź originu
  z pominięciem Cloudflare, nagłówki odpowiedzi, efektywny UID kontenera API, zachowanie FrankenPHP przy ciele 70 MB.
- **Kod bibliotek** (`vendor`, `node_modules` nie ma w eksporcie): przyjąłem udokumentowane zachowanie tokenu OIDC
  Symfony (osobne klucze cache discovery na firewall, sprawdzanie `exp`/`nbf`/`iat`) i `oidc-client-ts`. Oba firewalle
  dzielą pulę `cache.oidc`; nawet gdyby klucze się mieszały, issuer i audience nadal rozdzielają realmy.
- **Podatności zależności** (`composer audit`, `npm audit`) i zawartość obrazów bazowych.
- **Konfiguracja poza repo**: reguły WAF i rate limiting Cloudflare, Cloudflare Access, ustawienia Keycloaka zmienione
  ręcznie w klastrze (job nie nadpisuje istniejącego realmu), domyślne wartości Keycloaka dla czasu życia tokenu
  dostępu (plik realmu go nie ustawia, więc 5 minut).
- **Zaszyfrowane sekrety** (`environments/prod/secrets/`): nie oceniałem ich zawartości ani siły wartości.
- **Workflowy GitHub Actions** i łańcuch budowania obrazów: poza zakresem tego obszaru.
- **Pełna treść** wszystkich 195 plików warstwy UI: DTO zapisu panelu (produkty, promocje, kategorie, stany, ceny)
  sprawdziłem wyrywkowo i wzorcami, nie linia po linii.
