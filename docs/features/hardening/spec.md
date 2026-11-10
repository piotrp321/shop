# HARDENING: limity nadużyć, correlation ID, przegląd bezpieczeństwa i testy obciążeniowe

Wymagania niefunkcjonalne z `docs/vision.md` („Bezpieczeństwo”, „Obserwowalność”, „Skala docelowa”) przed publicznym
demo: rate limiting na logowaniu, składaniu zamówień, płatnościach i webhookach z adresem IP klienta przekazanym przez
sklep i Cloudflare, correlation ID od wejścia HTTP przez RabbitMQ do logów, przegląd bezpieczeństwa sklepu, API
i panelu oraz testy obciążeniowe krytycznych ścieżek z raportem.

Issues: #267, #116
Zakres: `apps/api/src/Shared`, `apps/api/src/Ordering/UI`, `apps/api/src/Payment/UI`, `apps/api/src/PaymentSimulator`, `apps/api/tests/Functional/Shared`, `apps/api/tests/Functional/Ordering`, `apps/api/tests/Functional/Payment`, `apps/api/tests/Functional/PaymentSimulator`, `apps/api/tests/Unit/Shared`, `apps/api/tests/Integration/Shared`, `apps/api/config`, `apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/symfony.lock`, `apps/api/phpunit.dist.xml`, `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `apps/store/src/checkout`, `apps/store/src/auth`, `apps/store/app/auth/login`, `apps/store/next.config.ts`, `apps/admin/docker`, `infra/varnish`, `infra/docker-compose.yml`, `infra/k8s/charts/shop`, `infra/k8s/environments`, `infra/k8s/scripts`, `infra/k8s/tests`, `infra/k8s/Makefile`, `load`, `Makefile`, `.github/workflows/ci.yml`, `CLAUDE.md`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `docs/vision.md`, `docs/features/checkout/spec.md`, `docs/features/payment/spec.md`, `docs/features/deploy/spec.md`, `infra/k8s/README.md`, `docs/reports`

**Zależności.** CHECKOUT (#90) i PAYMENT (#91): endpointy złożenia zamówienia, rozpoczęcia płatności i webhooków.
DEPLOY (#175): chart, profil `prod` za Cloudflare (`ipAllowList`, Traefik z `externalTrafficPolicy: Local`) i test
dymny na k3d (`smoke.sh`). Funkcja nie zależy od wyglądu sklepu.

## Cel i zakres

- **Adres IP klienta** wyznaczany w jednym miejscu API i w sklepie, odporny na podrobienie w środowisku za Cloudflare.
- **Rate limiting** (Symfony RateLimiter, Redis): złożenie zamówienia (gość i klient), rozpoczęcie płatności, webhooki
  dostawców; w sklepie rozpoczęcie logowania (`/auth/login`). Odpowiedź 429 z `Retry-After` i komunikat w sklepie.
- **Correlation ID**: nadawany na wejściu HTTP API, zwracany w nagłówku odpowiedzi, przekazywany w stemplu wiadomości
  Messengera (outbox, RabbitMQ, transporty Doctrine) i dopisywany do każdego wpisu logu JSON API i workera.
- **Nagłówki bezpieczeństwa** sklepu, panelu i API (D9).
- **Przegląd bezpieczeństwa** sklepu, API i panelu według listy kontrolnej, z raportem (D10).
- **Testy obciążeniowe** katalogu, strony produktu, wyszukiwarki, koszyka i checkoutu na obrazach produkcyjnych,
  z raportem (D12–D15).

## Poza zakresem

- Obciążenie na skalę docelową (10 mln produktów, 60 tys. użytkowników) i testy na serwerze demo przez Cloudflare.
- Sentry i trace'y (#180), tryb publicznego demo (#179), monitoring i kopie zapasowe (DEPLOY-OPS, #178).
- Pozostałe punkty #181: blokowanie stanów fikcyjnymi zamówieniami poza samym limitem liczby zamówień (D16), polityka
  prywatności, włączenie rejestracji (`customerAccounts`) i wysyłki przez Brevo (`orderMail`). Issue #181 zostaje
  otwarte.
- RODO i anonimizacja zamówień (#119) oraz limit rezerwowanego towaru na zamówienie gościa (D11, D16): blokery trybu publicznego demo (D19).
- Limity na koszyku gościa (zakładanie koszyków, dodawanie linii) i na pozostałych publicznych endpointach: przegląd
  bezpieczeństwa ocenia ich ryzyko i ewentualnie proponuje zgłoszenie.
- Przeniesienie symulatora płatności do osobnej usługi (D14).
- Reguły rate limiting w Cloudflare (konfiguracja konta poza repo).
- Zmiana ustawień ochrony przed zgadywaniem haseł w Keycloaku (D17).

## Decyzje

| ID | Decyzja |
|---|---|
| D1 | Limity liczy Symfony RateLimiter (`symfony/rate-limiter`, nowa zależność, zgoda użytkownika) na puli cache `cache.rate_limiter` w Redisie (`REDIS_HOST`, rozszerzenie `redis` jest już w obrazie; DSN z limitami czasu połączenia i odczytu 1 s). Bez `symfony/lock` (`lock_factory: null`): przy równoległych żądaniach limit może zostać przekroczony o liczbę żądań w locie, co wystarcza do ochrony przed nadużyciem. |
| D2 | Adres IP klienta (`ClientIp`, `Shared\Infrastructure\Http`): gdy zmienna `CLIENT_IP_HEADER` jest ustawiona, adres z tego nagłówka, jeśli jest jednym poprawnym adresem IP; inaczej `Request::getClientIp()` (adres połączenia, bez `trusted_proxies`). W profilu `prod` nagłówek to `CF-Connecting-IP`: Cloudflare zawsze go nadpisuje, a ingress przyjmuje ruch tylko z adresów Cloudflare (`ipAllowList`), więc z zewnątrz nie da się go podrobić. Sklep kopiuje ten sam nagłówek z żądania przeglądarki do wywołań API. Ufamy wywołującym wewnątrz klastra. Bez wspólnego sekretu sklep–API (decyzja użytkownika). Odporność na podrobienie sprawdzają testy renderu (D3), testy API i przegląd (punkt 1); próbę ominięcia Cloudflare na serwerze opisuje runbook i jest blokerem trybu publicznego demo (D19). |
| D3 | Chart: wartość `clientIpHeader` (domyślnie pusta) trafia jako `CLIENT_IP_HEADER` do `api` i `store`. Render kończy się błędem, gdy `clientIpHeader` jest ustawione przy `ipAllowList.enabled: false`, chyba że profil jawnie ustawia `clientIpHeaderWithoutAllowList: true` (tylko profil pomiaru `load`). `clientIpHeaderWithoutAllowList: true` razem z `image.requireDigests: true` (obrazy wdrożenia) też kończy render błędem, więc w produkcji nie jest dopuszczalne. Testy renderu: `prod` ma `CF-Connecting-IP` i `ipAllowList` na każdym ingressie (także `api` i `auth`), `ci` nie ma nagłówka, `prod` z `clientIpHeaderWithoutAllowList: true` się nie renderuje. |
| D4 | Klucz limitu: adres IPv4 albo prefiks /64 adresu IPv6 (gość, webhook, logowanie w sklepie); `sub` z tokena dla endpointów klienta (`/api/v1/me/...`). Osobne przestrzenie kluczy limitera (`guest:`, `customer:`). |
| D5 | Wartości limitów, okno przesuwne (decyzja użytkownika): złożenie zamówienia 10 na 15 min (gość na adres, klient na konto); rozpoczęcie płatności 20 na 15 min (tak samo); webhook 300 na minutę na parę (dostawca, adres); `/auth/login` w sklepie 30 na 10 min na adres (okno stałe). Wartości API są w `config/packages/rate_limiter.yaml`: domyślne (produkcja), `when@dev` wysokie (stos compose i E2E: wszystkie żądania sklepu mają tam jeden adres kontenera), `when@test` małe dla testów limitów. Limit sklepu w zmiennej `STORE_LOGIN_RATE_LIMIT` (domyślnie 30; compose wysoki; chart jej nie ustawia, więc obowiązuje domyślna). |
| D6 | Każde żądanie limitowanego endpointu zużywa limit, także powtórzenie z tym samym `Idempotency-Key` i żądanie odrzucone walidacją. Limit jest sprawdzany przed kontrolerem, po firewallu. 429 przy złożeniu zamówienia nie zakłada zamówienia, więc przeglądarka zachowuje swój `Idempotency-Key` do ponowienia. |
| D7 | Redis niedostępny: żądanie przechodzi bez limitu (fail open; decyzja użytkownika), ale awaria nie jest cicha. Fail open daje adapter cache Symfony: przy błędzie Redisa zwraca brak wpisu i zapisuje `warning` w kanale Monologa `cache`. Monolog w `prod` ma dla kanału `cache` osobny handler JSON na stderr od poziomu `warning`, poza `fingers_crossed`, więc każdy błąd puli limitera trafia do logu także przy udanym żądaniu. Health check API (`RedisProbe`) zgłasza Redisa jako niedostępnego; alerty z logów i health checku należą do monitoringu (DEPLOY-OPS, #178). |
| D8 | 429: problem details (`type` `about:blank`, `title` „Too Many Requests”, `detail` „Zbyt wiele prób. Spróbuj ponownie za kilka minut.”), nagłówek `Retry-After` w sekundach, `Cache-Control: private, no-store`. OpenAPI opisuje 429 na limitowanych endpointach. |
| D9 | Correlation ID: API zawsze nadaje własny (UUIDv7) i ignoruje nagłówek z żądania. Odpowiedź ma `X-Correlation-ID`; Varnish usuwa go z odpowiedzi, które zapisuje w cache, żeby trafienie nie zwracało cudzego identyfikatora. Komenda konsoli ma jeden identyfikator na uruchomienie, wiadomość Messengera niesie `CorrelationIdStamp`, a obsługa wiadomości ustawia identyfikator ze stempla (wiadomość bez stempla: nowy). Jedno wejście HTTP to jeden identyfikator: złożenie zamówienia, płatność i webhook mają różne. Wizja dostaje brzmienie „jedno żądanie da się prześledzić przez wszystkie procesy, które uruchomiło” (decyzja użytkownika). |
| D10 | Nagłówki bezpieczeństwa (w zakresie, decyzja użytkownika): sklep i panel `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, CSP `frame-ancestors 'none'; object-src 'none'; base-uri 'self'`; panel dodatkowo `script-src 'self'`. Sklep bez ograniczenia `script-src`: CSP z nonce wymusza renderowanie dynamiczne, co wyłączyłoby ISR stron produktów. API: `X-Content-Type-Options: nosniff` na każdej odpowiedzi. HSTS (`max-age=31536000`, bez `includeSubDomains` i `preload`) w middleware Traefika tylko przy `ingress.tls`. |
| D11 | RODO (#119) poza epikiem. Checkout gościa zbiera prawdziwe dane osobowe niezależnie od rejestracji, więc #119 jest blokerem trybu publicznego demo (D19). |
| D12 | Przegląd bezpieczeństwa (decyzja użytkownika): zadanie w ostatniej fali sprawdza listę kontrolną i zapisuje raport. Przegląd niczego nie naprawia: raport zawiera propozycje zgłoszeń (`bug`/`tech-debt` z `agent`), które użytkownik zakłada po odbiorze epiku. Znalezisko `Critical` albo `High` nie blokuje odbioru epiku, ale jego zgłoszenie jest blokerem trybu publicznego demo (#179, D19) do czasu poprawki; raport wymienia je w osobnej sekcji „Blokery publicznego demo”. |
| D13 | Raporty (przeglądu i pomiaru) są przypięte do rewizji jak w `docs/experiments/`: nagłówek z datą i SHA, po zapisaniu się nie zmieniają; kolejny pomiar to nowy plik. Miejsce: `docs/reports/` z `README.md` jak w `docs/experiments/` (decyzja użytkownika). |
| D14 | Testy obciążeniowe: k6 (obraz `grafana/k6` przypięty tagiem i digestem, uruchamiany w kontenerze, bez instalacji na hoście ani w zależnościach aplikacji; nowa zależność, zgoda użytkownika). Skrypty w `load/`. |
| D15 | Środowisko pomiaru (decyzja użytkownika): klaster k3d jak w teście dymnym, z obrazami produkcyjnymi zbudowanymi lokalnie i profilem `environments/load` (`ci` plus zasoby podów z `prod`, `clientIpHeader: CF-Connecting-IP`, `clientIpHeaderWithoutAllowList: true`); `make -C infra/k8s load`. Stos deweloperski (`APP_ENV=dev`) nie nadaje się do pomiaru. Pomiar wykonuje zadanie AO w ostatniej fali; przed pomiarem zatrzymuje stos compose swojego worktree, a raport zapisuje obciążenie hosta (inne kontenery, średnie obciążenie). Użytkownik może powtórzyć pomiar tą samą komendą (nowy raport, D13). |
| D16 | Blokowanie stanów fikcyjnymi zamówieniami: epik ogranicza tylko liczbę zamówień (D5). Jedno zamówienie gościa może zarezerwować do 50 linii po 99 sztuk (`Cart::MAX_LINES`, `MAX_QUANTITY`) na 35 min. Limit sztuk albo linii na zamówienie gościa zmienia reguły Cart i Ordering, więc jest osobnym zgłoszeniem, założonym przy publikacji tej specyfikacji i będącym blokerem trybu publicznego demo (D19): publiczne demo nie może pozwalać na masowe blokowanie stanów. |
| D17 | Logowanie hasłem odbywa się w Keycloaku. Oba realmy mają `bruteForceProtected: true` z wartościami domyślnymi Keycloaka (blokada konta po 30 nieudanych próbach, czas rosnący do 15 min). Epik ich nie zmienia (decyzja użytkownika); `docs/features/deploy/spec.md` („Keycloak i konta”) to odnotowuje. |
| D18 | Checkout w teście obciążeniowym przechodzi przez `POST /api/checkout` sklepu, czyli złożenie zamówienia i rozpoczęcie płatności w symulatorze, który działa w kontenerze API (ryzyko samoblokady wątków FrankenPHP). Scenariusz checkoutu ma równoległość ograniczoną poniżej połowy wątków API, a raport to opisuje (decyzja użytkownika). Wynik checkoutu przy tej równoległości nie jest benchmarkiem checkoutu ani przepustowości systemu (sekcja „Testy obciążeniowe”). |
| D19 | Blokery trybu publicznego demo (#179), dopisane do issue przy publikacji tej specyfikacji: RODO dla danych gości (#119); limit rezerwowanego towaru na zamówienie gościa (nowe issue, D16); weryfikacja na serwerze, że origin odrzuca ruch z pominięciem Cloudflare (runbook `infra/k8s/README.md`); zgłoszenia znalezisk `Critical` i `High` z raportu przeglądu (dopisuje użytkownik po odbiorze epiku). Epik niczego z tej listy nie implementuje. |

## Adres IP klienta

- API: serwis `ClientIp::of(Request): string` (D2, D4) używany wyłącznie przez rate limiting. Wartość nagłówka
  z wieloma adresami, pusta albo niepoprawna jest ignorowana (adres połączenia).
- Zmienna `CLIENT_IP_HEADER`: compose jej nie ustawia, chart według D3. Domyślna wartość w API przez
  `%env(default::CLIENT_IP_HEADER)%`.
- Sklep: wywołania API z BFF kasy i płatności (`src/checkout/`) dostają nagłówek `CLIENT_IP_HEADER` skopiowany
  z żądania przeglądarki (gdy zmienna jest ustawiona i nagłówek jest w żądaniu). Logowanie w sklepie (`/auth/login`)
  bierze adres z tego samego nagłówka; bez niego wszystkie żądania mają wspólny klucz.

## Rate limiting

### API

Subscriber `kernel.request` w `Shared\Infrastructure\Http` po firewallu czyta z trasy domyślną wartość
`RateLimit::ROUTE_DEFAULT` (nazwę limitera), wzorem `PublicReadErrorCacheSubscriber::ROUTE_DEFAULT`. Limitery
(`config/packages/rate_limiter.yaml`):

| Limiter | Trasy | Klucz |
|---|---|---|
| `order_placement` | `POST /api/v1/orders` (gość), `POST /api/v1/me/orders` (klient) | `guest:<adres>` albo `customer:<sub>` |
| `payment_start` | `POST /api/v1/orders/{id}/payments`, `POST /api/v1/me/orders/{id}/payments` | jak wyżej |
| `payment_webhook` | `POST /api/v1/payments/webhooks/{provider}` | `<provider>:<adres>` |

- Przekroczenie: 429 (D8) przed kontrolerem, więc bez odczytu treści, sprawdzenia podpisu ani komendy.
- Redis niedostępny: D7.
- Symulator płatności (`DeliverSimulatedWebhook`) traktuje 429 jak 5xx (ponowienie transportu `payment_simulator`),
  a nie jak 4xx kończące dostawę. Inne 4xx bez zmian.

### Sklep: rozpoczęcie logowania

`GET /auth/login` zapisuje w Redisie stan logowania (10 min) przy każdym wywołaniu, więc bez limitu można zapełniać
Redis. Limit: licznik w Redisie sklepu (`INCR` z `EXPIRE` przy pierwszym trafieniu, okno stałe 10 min, bez nowej
zależności), limit `STORE_LOGIN_RATE_LIMIT` (D5). Przekroczenie: 429 z `Retry-After` (sekundy do końca okna) i prostą
stroną „Zbyt wiele prób logowania. Spróbuj ponownie za kilka minut.”, bez zapisu stanu logowania. Redis niedostępny:
dotychczasowe zachowanie trasy (503).

### Sklep: komunikaty

- Kasa: 429 z API przy złożeniu zamówienia → BFF zwraca 429 z `detail` API i `Retry-After`; strona kasy pokazuje
  „Zbyt wiele prób złożenia zamówienia. Spróbuj ponownie za kilka minut.”, nie czyści formularza i zachowuje
  `Idempotency-Key` (D6).
- 429 przy rozpoczęciu płatności po złożeniu zamówienia: zamówienie stoi, `redirectUrl` jest `null`, a strona
  potwierdzenia proponuje płatność ponownie (jak przy innym błędzie rozpoczęcia płatności).
- Ponowienie płatności ze strony potwierdzenia: 429 → „Zbyt wiele prób płatności. Spróbuj ponownie za kilka minut.”

## Correlation ID

- `CorrelationId` (`Shared\Infrastructure\Observability`): bieżący identyfikator procesu (żądanie, komenda konsoli,
  obsługa wiadomości), reset po zakończeniu (`kernel.reset`), żeby długo działający proces (FrankenPHP worker,
  `messenger:consume`) nie przenosił identyfikatora między żądaniami.
- Wejście HTTP: subscriber `kernel.request` (main request) nadaje nowy UUIDv7, `kernel.response` ustawia
  `X-Correlation-ID` na każdej odpowiedzi, także 429 i błędach.
- Varnish (`infra/varnish/default.vcl`): `X-Correlation-ID` usuwany z odpowiedzi zapisywanej w cache (test `.vtc`).
- Konsola: `ConsoleEvents::COMMAND` nadaje identyfikator, chyba że to `messenger:consume` (identyfikator per wiadomość).
- Messenger: middleware na `command.bus`, `query.bus` i `event.bus`. Wysyłka bez stempla dodaje `CorrelationIdStamp`
  z bieżącym identyfikatorem (brak bieżącego: nowy). Obsługa wiadomości odebranej z transportu (`ReceivedStamp`)
  ustawia bieżący identyfikator ze stempla na czas obsługi i przywraca poprzedni. Worker (`messenger:consume`,
  `messenger:failed:retry`) ustawia go już przy odebraniu wiadomości (`WorkerMessageReceivedEvent`) i przywraca poprzedni
  dopiero po jej obsłudze (`WorkerRunningEvent`), więc wpisy listenerów ponowienia i kolejki `failed` też go mają. Przekaźnik outboxa
  (`RelayDomainEventToBroker`) wysyła samo zdarzenie, więc zdarzenie w RabbitMQ dostaje identyfikator bieżący, czyli
  ten z wiadomości outboxa. Stempel przechodzi przez serializer transportu `events` (`symfony_serializer`, nagłówki
  AMQP) i transporty Doctrine.
- Logi: procesor Monologa dopisuje `extra.correlation_id` do każdego wpisu, gdy identyfikator jest ustawiony.
  W `prod` Monolog zapisuje bufor żądania tylko przy błędzie (`fingers_crossed`), więc identyfikator łączy wpisy
  błędów i ich kontekst, a nie udane żądania.

## Weryfikacja originu na serwerze

Runbook `infra/k8s/README.md` dostaje krok „zgoda użytkownika” po pierwszym wdrożeniu: żądanie HTTPS na adres IP
serwera z nagłówkiem `Host` domeny sklepu, API i panelu z pominięciem Cloudflare kończy się odmową (403 Traefika albo
odrzucone połączenie), a żądanie przez Cloudflare z własnym `CF-Connecting-IP` przechodzi bez zmiany limitu innego
adresu. Wykonanie tego kroku jest blokerem #179 (D19), nie częścią epiku.

## Nagłówki bezpieczeństwa

Według D10: sklep w `next.config.ts` (`headers()`, każda trasa), panel w `apps/admin/docker/nginx.conf` (każda
lokalizacja, `add_header … always`), API w subscriberze `kernel.response`, HSTS w middleware Traefika chartu przy
`ingress.tls`. Nagłówki nie zmieniają `Cache-Control` ani tagów cache. Panel w compose działa na Vite dev, więc
nagłówki i CSP panelu sprawdza test dymny (`smoke.sh`, obraz produkcyjny): nagłówki odpowiedzi panelu i załadowanie
panelu w przeglądarce bez błędów CSP w konsoli (strona logowania Keycloaka po przekierowaniu).

## Przegląd bezpieczeństwa

Zadanie w ostatniej fali (po limitach i nagłówkach) sprawdza kod i działający stos według listy i zapisuje raport
`docs/reports/security-review-<data>.md` (D12, D13):

1. Rate limiting: endpointy z wizji, klucz i odporność adresu IP na podrobienie: próba z własnym `CF-Connecting-IP`
   bez konfiguracji nagłówka, render `prod` (lista adresów Cloudflare na każdym ingressie, brak
   `clientIpHeaderWithoutAllowList`), próba wyrenderowania `prod` z tym przełącznikiem. Próba bezpośredniego dostępu do
   originu na serwerze jest poza zasięgiem epiku: raport zapisuje ją jako niezweryfikowaną i bloker D19.
2. Walidacja wejścia na granicy API: każdy endpoint zapisu ma DTO z walidacją albo jawny powód (webhooki).
3. CORS: tylko `ADMIN_URL`, próba z innego originu.
4. Uprawnienia panelu: każdy endpoint `/api/v1/admin` ma voter; token klienta i token bez roli dostają 401/403.
5. Upload zdjęć: typ po zawartości, limity, bucket prywatny, oryginał nieserwowany.
6. Nagłówki bezpieczeństwa i ciasteczka sklepu (`HttpOnly`, `Secure`, `SameSite`), ochrona zapisów BFF przed CSRF.
7. Sekrety: brak w repo, obrazach i logach; `PURGE` Varnisha, rewalidacja ISR i API symulatora wymagają sekretu.
8. Ekspozycja: co jest osiągalne z internetu w profilu `prod` (ingress, ścieżki Keycloaka, symulator, health check).
9. Zależności: `composer audit` i `npm audit` (store, admin, e2e) w chwili przeglądu.
10. Błędy: odpowiedzi 4xx/5xx bez danych wewnętrznych; przekierowania (`returnTo`) bez otwartego przekierowania.
11. Nadużycia stanów i koszyków: rezerwacje fikcyjnymi zamówieniami (D16), zakładanie koszyków gościa.

Raport: dla każdego punktu wynik (OK / znalezisko), dowód (plik albo komenda z wynikiem) i waga znaleziska
(Critical, High, Medium, Low); na końcu sekcja „Blokery publicznego demo” (każde znalezisko Critical i High oraz
niezweryfikowana próba ominięcia Cloudflare) i propozycje zgłoszeń.

## Testy obciążeniowe

- Skrypty k6 w `load/`: scenariusze przez sklep (HTTP, jak przeglądarka): lista `/` i kategoria `/c/<ścieżka>`,
  strona produktu `/p/<slug>`, wyszukiwarka `/szukaj?q=…` z filtrami, koszyk (dodanie przez `/api/cart/*`, strona
  `/cart`), checkout gościa (`POST /api/checkout`). Dane wejściowe (slugi, ścieżki, frazy, warianty) pobierane na
  początku przebiegu z publicznego API, bez list wpisanych na sztywno.
- Każda iteracja wirtualnego użytkownika ma nowy adres w `CF-Connecting-IP` (kolejne adresy z puli testowej), więc
  limity działają na adres klienta jak w produkcji, a pomiar nie trafia w limit jednego adresu. 429 jest liczony
  osobno; każdy 429 w pomiarze to znalezisko raportu.
- Checkout: losowy wariant możliwy do kupienia, ilość 1; 409 braku stanu liczony osobno jako wynik biznesowy.
  Przed pomiarem `make -C infra/k8s load` podnosi stan fizyczny każdego wariantu ze stanem > 0 o 100 000 sztuk
  bezpośrednim SQL w bazie klastra pomiaru (klaster jednorazowy, usuwany po pomiarze; wyjątek od zapisu przez
  komendy Inventory tylko dla danych pomiaru), więc checkout nie wyczerpuje stanów. Raport podaje udział 409.
  Równoległość checkoutu według D18.
- `make -C infra/k8s load` (D15): klaster, dane demo, podniesienie stanów, rozgrzanie, przebieg stopniowo
  rosnącego obciążenia do nasycenia (p95 powyżej 2 s albo błędy 5xx i sieci powyżej 1%) i przebieg stały na 70%
  nasycenia, zbiór wyników do katalogu poza repo (`LOAD_OUT`, wymagany), usunięcie klastra przy każdym wyjściu. Kod
  wyjścia: 0 – pomiar wykonany (bez progów zaliczenia), 2 – błąd środowiska (brak `LOAD_OUT`, za mało zasobów, pody
  niegotowe), z komunikatem.
- Przebieg rosnący do nasycenia obejmuje scenariusze odczytu i koszyka, bez checkoutu: to jest maksymalna zmierzona
  przepustowość systemu. Checkout biegnie osobno, ze stałą, ograniczoną równoległością (D18), równolegle do ruchu
  odczytu na 70% nasycenia.
- Profil `environments/load` przechodzi render i `kubeconform` w `make -C infra/k8s check`.
- Raport `docs/reports/load-test-<data>.md` (D13): data i SHA, host (CPU, RAM, system, zasoby VM Dockera), wersje
  obrazów, zasoby podów, dane (liczba produktów i wariantów), scenariusze i ich udział, wyniki (przepustowość,
  p50/p95/p99, błędy, 409, 429) dla każdego scenariusza, w dwóch osobnych tabelach: maksymalna przepustowość systemu
  (odczyt i koszyk) oraz „checkout przy ograniczonej równoległości (D18)” z opisem ograniczenia i zdaniem, że nie jest
  to przepustowość checkoutu ani benchmark ścieżki zakupu, zużycie CPU i pamięci podów, obserwowane wąskie gardła,
  obciążenie hosta w chwili pomiaru i sekcja „Czego z tego nie wnioskujemy” (skala docelowa, sprzęt serwera,
  Cloudflare, katalog 10 mln).
- CI: zadanie `e2e` po Playwrighcie uruchamia skrypty k6 na stosie compose z jednym użytkownikiem i jedną iteracją
  każdego scenariusza (`make load-smoke`, do 30 s), żeby skrypty nie przestały działać. Bez pomiaru i progów
  wydajności. Checkout kupuje 1 sztukę, a uruchomienie po Playwrighcie nie zabiera stanów testom E2E.

## Zmiany dokumentacji (razem z implementacją)

- `docs/vision.md`: „Bezpieczeństwo” – limity także na rozpoczęciu płatności i logowaniu w sklepie, adres klienta
  z `CF-Connecting-IP`; „Obserwowalność” – brzmienie correlation ID (D9); testy obciążeniowe k6 i raporty w `docs/reports/`.
- `CLAUDE.md`: `make load-smoke`, `make -C infra/k8s load`, katalog `load/`, `docs/reports/`, `clientIpHeader` w chartcie.
- `apps/api/CLAUDE.md`: limity, `ClientIp`, correlation ID, 429; zachowanie symulatora przy 429.
- `apps/store/CLAUDE.md`: nagłówek adresu klienta w BFF, limit `/auth/login`, nagłówki bezpieczeństwa.
- `docs/features/checkout/spec.md`: decyzja T2 i ryzyko braku limitów zastąpione odwołaniem do tej specyfikacji.
- `docs/features/payment/spec.md`: 429 webhooka i ponowienie symulatora.
- `docs/features/deploy/spec.md`: `clientIpHeader`, HSTS, ochrona przed zgadywaniem haseł w Keycloaku (D17).
- `infra/k8s/README.md`: krok weryfikacji originu (sekcja „Weryfikacja originu na serwerze”).

## Wymagane przed startem

- Zgoda na zależności `symfony/rate-limiter` i obraz `grafana/k6` – D1, D14 – istnieje: decyzja użytkownika w przygotowaniu epiku.
- Redis dla API – istnieje: usługa `redis` w compose i chartcie, `REDIS_HOST` w API.
- Cloudflare przed ingressem i `ipAllowList` w `prod` – istnieje: `infra/k8s/environments/prod/values.yaml`.
- Sekrety: brak nowych (D2 bez wspólnego sekretu).
- Zasoby Dockera na maszynie z AO do pomiaru (D15): VM Dockera co najmniej 10 vCPU i 20 GiB RAM (zasoby `prod` około
  5 vCPU i 8,5 GiB plus stosy compose innych zadań) – użytkownik: ustawienia Docker Desktop.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Gość przekracza limit złożenia zamówienia | 429 z `Retry-After`, komunikat w kasie, koszyk i klucz bez zmian |
| Klient przekracza limit, inny klient z tego samego adresu | pierwszy 429, drugi bez ograniczeń |
| `CF-Connecting-IP` podany przy `CLIENT_IP_HEADER` pustym | ignorowany, klucz z adresu połączenia |
| `CF-Connecting-IP` z dwoma adresami albo niepoprawny | adres połączenia |
| Redis niedostępny | żądanie bez limitu, `warning` kanału `cache` w logu `prod` (D7) |
| Zalew webhooków z jednego adresu | 429 przed sprawdzeniem podpisu |
| Symulator dostaje 429 webhooka | ponowienie transportu, potem `failed` |
| Wiadomość bez stempla (sprzed wdrożenia) | obsługa z nowym identyfikatorem |
| `/auth/login` ponad limit | 429, bez stanu w Redisie |
| `clientIpHeader` bez `ipAllowList` i bez potwierdzenia | render chartu kończy się błędem |
| `make -C infra/k8s load` bez `LOAD_OUT` albo z podami w `Pending` | kod 2, klaster usunięty |

## Kryteria akceptacji

1. 11. złożenie zamówienia gościa z jednego adresu w 15 min (wartości produkcyjne) to 429 z `Retry-After`; inny adres
   przechodzi.
2. Limit klienta liczy się po `sub`, niezależnie od adresu.
3. Render `prod` ma `CLIENT_IP_HEADER=CF-Connecting-IP` w `api` i `store`, `ci` nie ma; `clientIpHeader` bez
   `ipAllowList` i bez potwierdzenia się nie renderuje.
4. Kasa pokazuje komunikat 429 i nie traci formularza; zamówienie z 429 płatności można opłacić później.
5. Każda odpowiedź API ma `X-Correlation-ID`, odpowiedź z cache Varnisha go nie ma; wpisy logu żądania, wiadomości
   outboxa i konsumenta zdarzenia mają ten sam `correlation_id` (test integracyjny na prawdziwych transportach).
6. Nagłówki z D10 są w odpowiedziach sklepu i API (testy) oraz panelu (test dymny, panel ładuje się bez błędów CSP);
   HSTS tylko w renderze z `ingress.tls`.
7. Raport przeglądu ma wynik każdego z 11 punktów z dowodem.
8. Raport pomiaru ma wszystkie sekcje z „Czego z tego nie wnioskujemy”; `make load-smoke` przechodzi w CI.
9. Awaria Redisa: żądanie przechodzi, a log ma wpis `warning` kanału `cache` (test integracyjny).
10. Raport pomiaru ma osobne tabele maksymalnej przepustowości systemu i checkoutu przy ograniczonej równoległości.
11. `make check`, testy api, store, admin, `make varnish-test` i E2E przechodzą.

## Testy

- API funkcjonalne: limity każdego limitera (`when@test`: małe limity, pula `cache.adapter.array`), klucz gościa
  i klienta, `CF-Connecting-IP` z `CLIENT_IP_HEADER` i bez, 429 i jego nagłówki.
- API integracyjne: fail open przy rzeczywistej awarii Redisa: prawdziwy adapter Redis wskazujący adres, na którym
  Redis nie działa (żądanie przechodzi, czas odpowiedzi w granicy limitów czasu DSN, wpis `warning` kanału `cache`);
  konfiguracja `prod` Monologa kieruje kanał `cache` od `warning` poza `fingers_crossed`; stempel correlation ID przez outbox i RabbitMQ (`test_messaging`).
- API jednostkowe: `ClientIp` (IPv4, IPv6 /64, wiele adresów, śmieci), middleware correlation ID (ustawienie
  i przywrócenie).
- Symulator: 429 webhooka ponawiany, inne 4xx bez zmian.
- Varnish: `.vtc` – `X-Correlation-ID` nie trafia do odpowiedzi z cache.
- Sklep (Vitest): przekazanie nagłówka adresu, limit `/auth/login` (licznik w Redisie, okno, 429), mapowanie 429
  w BFF i na stronie kasy, nagłówki w `next.config.ts`.
- Chart: testy renderu D3 i HSTS; profil `load` w `check`; test dymny: nagłówki i CSP panelu.
- `make -C infra/k8s load`: ścieżka błędu (brak `LOAD_OUT`) w `check` na atrapach `docker`/`k3d`, jak testy skryptów
  serwera.
- E2E: istniejące scenariusze przechodzą z limitami `when@dev` i `STORE_LOGIN_RATE_LIMIT` compose.

## Ryzyka i ograniczenia

- Adres klienta wewnątrz klastra jest zaufany: pod w klastrze może podać dowolny `CF-Connecting-IP`.
- Klienci za wspólnym NAT (firma, operator komórkowy) dzielą limit gościa.
- Fail open (D7) wyłącza limity na czas awarii Redisa; widać to w logu i health checku, alert należy do #178.
- Limit liczby zamówień nie chroni przed rezerwacją dużych ilości jednym zamówieniem (D16).
- W `prod` logi udanych żądań nie są zapisywane, więc correlation ID pomaga tylko przy błędach.
- `CorrelationIdStamp` jest nadawany od wersji, która go wprowadza, wbrew zasadzie „Zgodność wersji”
  w `apps/api/CLAUDE.md`, bo przed tą wersją nie było wdrożenia. Gdyby przy wymianie podów działał worker bez tej klasy,
  nie zdekoduje on nowych wiadomości: wracają przez ponowienia transportu, a te z kolejki `failed` wznawia
  `messenger:failed:retry`.
- Pomiar na komputerze dewelopera nie mówi o wydajności serwera demo ani o skali docelowej; dane demo to ~200 produktów
  i ~1000 wariantów, a inne stosy na tej samej maszynie zaszumiają wynik.
- CSP sklepu bez `script-src` nie chroni przed XSS skryptami inline.
- Epik ma trzy niezależne ścieżki (limity i nagłówki, correlation ID, pomiar i przegląd), co zwiększa końcowy PR.
