# ABUSE-LIMITS: jeden anonimowy użytkownik nie zatrzyma sprzedaży

Dwa audyty z 2026-11-18 (`docs/reports/app-audit-2026-11-18.md`, R2 i B6, sekcja 7;
`docs/reports/security-audit-2026-11-18.md`, sekcja 14, epik 2) zgodnie wskazują, że jeden anonimowy gość może
zatrzymać sprzedaż w publicznym demo: zarezerwować cały stan nieopłaconymi zamówieniami, zgadywać kody rabatowe bez
ograniczenia, zakładać koszyki bez limitu, zablokować API równoległymi rozpoczęciami płatności albo dużymi i wolnymi
treściami żądań, zapełnić dysk węzła cache ISR i oślepić Sentry. Epik zamyka te drogi limitami po stronie API, sklepu
i ingressu. Każdy limit ma test prób obejścia (zasada projektu dla zadań ustawiających reguły).

Issues: #332, #406, #407, #421
Zakres: `apps/api/src/Shared/Infrastructure/Http`, `apps/api/src/Cart/Domain`, `apps/api/src/Cart/UI/Http`, `apps/api/src/Ordering`, `apps/api/src/Payment`, `apps/api/config`, `apps/api/migrations`, `apps/api/docker/frankenphp`, `apps/api/Dockerfile`, `apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/symfony.lock`, `apps/api/tests`, `apps/api/openapi.json`, `apps/store/src/api/schema.d.ts`, `apps/store/src/cart`, `apps/store/src/checkout`, `apps/store/src/catalog`, `apps/store/src/api/client.ts`, `apps/store/app/api`, `apps/store/app/p`, `apps/store/next.config.ts`, `apps/store/Dockerfile`, `apps/store/tests`, `infra/keycloak/realms`, `infra/k8s/charts/shop`, `infra/k8s/tests`, `infra/k8s/smoke`, `load/lib`, `e2e/tests`, `apps/api/CLAUDE.md`, `docs/features/hardening/spec.md`, `docs/features/cart/spec.md`, `docs/features/checkout/spec.md`, `docs/features/discount-codes/spec.md`, `docs/features/payment/spec.md`, `docs/features/product-page/spec.md`

**Zależności.** HARDENING (#267): `ClientIp`, `RateLimitSubscriber`, limitery w Redisie, przekazywanie
`CF-Connecting-IP` przez sklep i Cloudflare przed originem (`ipAllowList`). CHECKOUT, PAYMENT, DISCOUNT-CODES,
PRODUCT-PAGE, SENTRY: przepływy, które ograniczamy. SECURITY-PATCHING (epik 1 audytu bezpieczeństwa) może podnieść
obraz FrankenPHP; L8 wymaga wersji ≥ 1.13.5 i podnosi ją sam, jeśli na `main` jest starsza.

## Cel i skutek

Po epiku, przy wartościach produkcyjnych:

- jeden adres klienta (IPv4 albo prefiks /64 IPv6) trzyma najwyżej 5 nieopłaconych zamówień gościa (liczonych do ich
  wygaszenia, 35 min) po najwyżej 10 pozycji, czyli najwyżej 50 wariantów naraz (dziś ok. 23 zamówienia × 50 pozycji
  × 99 sztuk, cały katalog demo z jednego adresu); zablokowanie ok. 1000 wariantów demo wymaga co najmniej 20
  adresów, a przy stanach powyżej 10 sztuk proporcjonalnie więcej;
- zgadywanie kodów to najwyżej 20 prób na 15 minut z adresu (dziś ok. 50 prób na sekundę);
- koszyki gościa: najwyżej 30 nowych i 300 zmian na 15 minut z adresu, a pusty koszyk wygasa po dniu;
- limity nie przepuszczają setek żądań przy ruchu równoległym (#421);
- równoległe rozpoczęcia płatności jednego zamówienia nie zajmują wątków API;
- treść żądania ma limit rozmiaru i czasu w API, BFF sklepu i ingressie;
- cache ISR strony produktu nie rośnie bez ograniczenia na dysku poda;
- błędny token Bearer nie tworzy zdarzenia Sentry ani zrzutu logów;
- hasła w Keycloaku mają minimalną politykę.

Uczciwy klient sklepu z meblami i wyposażeniem nie powinien trafić w żaden z limitów; skutki są opisane przy każdym
punkcie. Limity nie zatrzymują atakującego z wieloma adresami (botnet, własna pula IPv6 /48): to granica podejścia
„na adres” (sekcja „Ryzyka i ograniczenia”).

## Poza zakresem

| Pozycja | Powód |
|---|---|
| Jeden komunikat dla wszystkich przyczyn odrzucenia kodu w podglądzie (R2) | Nie daje ochrony: kod z progiem i tak daje `applied` w koszyku powyżej progu, który atakujący składa sam, a `used_up` mówi tylko, że kod chwilowo nie działa. Klient traci informację, co zrobić („dodaj produkty za X zł”). Ochronę daje limit L3 (D4, do potwierdzenia). |
| Wyrocznia kodu przez 409 złożenia zamówienia | Złożenie ma limit `order_placement` (10 na 15 min na adres) i L6; to wolniej niż L3. |
| Limit wyszukiwarki i porcji mapy strony, katalog na hoście `api.` przez Varnish (SA-30, R2) | Varnish nie chroni przed unikalnymi frazami i parametrami, a ta sama droga istnieje przez stronę `/szukaj` sklepu, która czyta katalog przez Varnish bez adresu klienta. Skuteczny limit wymaga adresu klienta na odczytach katalogu za Varnishem i ISR, a koszt odczytu trzeba najpierw zmierzyć na skali (SA-30: Low, „do ponownej oceny”). Miejsce: SCALE-PROOF. Zamknięcie tras katalogu na hoście `api.` psuje test dymny i pomiar k6, które czytają przez nie dane. |
| Maile zamówienia z treścią atakującego (SA-31) | Poczta zamówień w `prod` nie wychodzi z klastra (`orderMail: mailpit`). Do zamknięcia przed włączeniem Brevo, razem z prawdziwą pocztą (SA-36). L6 ogranicza nieopłacone zamówienia gościa na jeden e-mail do 3 naraz. |
| Krótsza rezerwacja przed rozpoczęciem płatności, rozdzielenie użycia kodu „wstępnego” i „potwierdzonego” (SA-04 p. 3, R2) | Zmienia terminy płatności widoczne dla klienta, e-maile i cykl płatności (PAYMENT). Limity L5 i L6 ograniczają skutek bez tej zmiany. Do decyzji przy PAYMENTS-REAL. |
| Wywołanie operatora płatności poza transakcją bazy (SA-03 p. 1) i symulator poza procesem API | M–L, przebudowa `StartPayment`; epik robi szybką poprawkę SA-03 (L7). Miejsce: PAYMENT-OPERATOR. |
| Upload zdjęć z pominięciem API (podpisany adres Garage) | Trasa uploadu zostaje jedyną, która przyjmuje anonimowo treść do 70 MB przed firewallem (ryzyko resztkowe w „Modelu zagrożeń”); zmiana architektury uploadu to osobny epik. |
| CAPTCHA (Turnstile) w kasie gościa, reguły rate limiting w Cloudflare, alert na odsetek stanu zablokowanego przez zamówienia `new` | Nowa usługa zewnętrzna albo konfiguracja konta poza repo; alerty: SECURITY-SIGNALS. |
| Limity prób logowania w Keycloaku na adres, ROPC `admin-cli`, zdarzenia logowania, lista zakazanych haseł (SA-09…SA-11) | IDENTITY-HARDENING. Lista zakazanych haseł wymaga pliku w obrazie Keycloaka i w chartcie. |
| Polskie komunikaty błędów frameworka (#413, część SR-2) | Nie dotyczy nadużyć. Epik zamyka tylko część SR-3 (limit rozmiaru JSON) z #413, więc #413 nie jest w linii `Issues:`. |
| #181 (zbiorcze „przed publicznym startem”) | Obejmuje też politykę prywatności, rejestrację klientów i Brevo. Epik zamyka jego część o blokowaniu stanów i limitach. |
| Ustalenie dokładnego stanu do 99 sztuk dodaniami do koszyka (R2) | Ujawnia liczbę sztuk, nie blokuje sprzedaży; L2 spowalnia próby. |
| Limit zmian koszyka klienta | Klient ma konto i jeden koszyk; rejestracja w `prod` jest wyłączona. |
| Zwolnienie przez pracownika stanu zablokowanego zamówieniem `new` | Status `new` nie ma przejść pracownika (`OrderStatus.php:32`); zamówienie wygasa samo po 35 min. |

## Wymagane przed startem

Pozycje użytkownika:

- **Zgoda na nową zależność `symfony/lock`** (D1). Bez niej L1 wymaga własnego atomowego licznika w Redisie (skrypt
  Lua) zamiast blokady; specyfikacja zakłada zgodę.
- Potwierdzenie decyzji oznaczonych „do potwierdzenia” (D3–D6, D10–D12).

Sprawdzenia techniczne (mały eksperyment na początku zadania, wynik w raporcie zadania; gdy wynik przeczy założeniu,
zadanie się zatrzymuje i wraca z decyzją, zamiast obchodzić wymaganie):

| ID | Założenie | Zadanie |
|---|---|---|
| E1 | `RateLimiterFactory` 8.1 tworzy blokadę z domyślnym TTL 300 s i czeka na nią bez limitu (`acquire(true)`); ograniczone czekanie i krótki TTL daje własny dekorator `LockFactory` przekazany jako `lock_factory`. | L1 |
| E2 | Caddy w FrankenPHP 1.13.5: `request_body max_size` odrzuca treść ponad limit przed PHP także przy treści chunked; `handle_errors` zwraca problem JSON; `timeouts read_body` zamyka wolną treść. | L8 |
| E3 | Next 16.4.3: ograniczenie pamięci cache (`cacheMaxMemorySize`) i wyłączony zapis ISR na dysk (`experimental.isrFlushToDisk: false`) albo własny `cacheHandler` w pamięci nie zapisują ani wpisów ISR, ani cache `fetch` na dysk, a `revalidateTag` działa. | L9 |
| E4 | Middleware `buffering` Traefika z k3s buforuje też odpowiedź, więc jest tylko na trasach JSON (BFF sklepu `/api/`, host `api.`), nigdy na stronach HTML i RSC sklepu. | L8 |
| E5 | Keycloak z compose importuje realm z `passwordPolicy`, a `kcadm update realms/<realm>` ustawia ją w istniejącym realmie. | L11 |

## Model zagrożeń

**Aktywa.** Dostępność sprzedaży (stan magazynowy, kody z limitem użyć), dostępność API i sklepu (wątki FrankenPHP,
pamięć, dysk węzła wspólny z bazami), poufność aktywnych kodów rabatowych, kanał powiadomień o błędach (Sentry), konta
klientów i pracowników. **Strona:** anonimowy klient HTTP z jednym albo kilkoma adresami, przez sklep albo bezpośrednio
do hosta `api.` (zawsze przez Cloudflare: `CF-Connecting-IP` ustawia Cloudflare).

| Droga | Zamyka | Sprawdzenie |
|---|---|---|
| Rezerwacja stanu wielkimi zamówieniami gościa | L5 | funkcjonalny 409, E2E kasy |
| Rezerwacja stanu wieloma nieopłaconymi zamówieniami z jednego adresu | L6 (adres) | funkcjonalny, współbieżność z procesami |
| Wyczerpanie kodu z limitem nieopłaconymi zamówieniami | L6 (do 5 zamówień z adresu) | jak wyżej; resztkowo: kod z limitem ≤ 5 da się wyczerpać z jednego adresu na 35 min |
| Zgadywanie kodów przez podgląd koszyka | L3 | funkcjonalny z próbami obejścia, współbieżność L1 |
| Przekraczanie limitów ruchem równoległym | L1 | integracyjny na Redisie z procesami |
| Trzymanie wątków rywalizacją o blokadę limitera | L1 (czekanie ≤ 300 ms, potem 429) | integracyjny |
| Zakładanie koszyków i zapisy linii bez limitu | L2 | funkcjonalny |
| Samoblokada API równoległymi rozpoczęciami płatności | L7 | integracyjny z procesami |
| Duża treść JSON (pamięć PHP, proces sklepu) | L8, L4 | E2E na stosie, Vitest, test dymny |
| Wolna treść trzymająca wątki API | L8 (Traefik buforuje przed API; Caddy zamyka po 60 s) | test dymny przez ingress, E2E na compose |
| Zapełnienie dysku węzła cache ISR | L9 | test na buildzie produkcyjnym, render chartu |
| Oślepienie Sentry i zalew logów błędnymi tokenami | L10 | funkcjonalny z licznikiem zdarzeń |
| Słabe hasła kont | L11 | E2E rejestracji, render chartu |
| Blokada zakupów cudzej osobie przez jej e-mail (L6) | ograniczenie do zamówień gościa, limit 3, neutralny komunikat | ryzyko resztkowe: atakujący blokuje do 1 e-maila na adres na 35 min |

**Ryzyka resztkowe** (świadome): wiele adresów omija limity na adres; trasa uploadu przyjmuje anonimowo do 70 MB
(PHP parsuje `multipart` przed firewallem); adres wewnątrz klastra jest zaufany (HARDENING D2).

## Decyzje

„Do potwierdzenia” oznacza wartość albo zachowanie zaproponowane bez decyzji użytkownika; specyfikacja zakłada
proponowaną wartość.

| ID | Decyzja |
|---|---|
| D1 | **Blokada licznika limitera (#421; nowa zależność, „Wymagane przed startem”).** `symfony/lock` (komponent Symfony 8.1, magazyn blokad w tym samym Redisie co limitery, bez nowej zmiennej środowiska) jako `lock_factory` każdego limitera w `prod` i `dev` (`when@test` bez blokady, magazyn w pamięci jednego procesu). Blokada ma czas życia 5 s, a żądanie czeka na nią najwyżej 300 ms (dekorator `LockFactory`, E1). Rywalizacja dłuższa niż 300 ms to 429 jak przekroczenie limitu (rywalizacja nie może otwierać limitu). Błąd Redisa (połączenie, odczyt) przy blokadzie działa jak D7 HARDENING: żądanie przechodzi bez limitu, a log `prod` ma wpis `warning` poza `fingers_crossed`. |
| D2 | **Klucz nowych limiterów: adres klienta** (`guest:<adres>` z `ClientIp`), także na trasie klienta podglądu kodu, więc przełączanie trasy gościa i klienta nie zeruje licznika. |
| D3 | **Wartości limiterów (okno przesuwne, do potwierdzenia):** `guest_cart_create` 30 na 15 min (`POST /api/v1/guest-carts`); `guest_cart_write` 300 na 15 min (`POST …/items`, `PUT` i `DELETE …/items/{variantId}` koszyka gościa); `discount_preview` 20 na 15 min (`GET /api/v1/guest-carts/{id}` i `GET /api/v1/me/cart` z parametrem `discountCode`, L3). `when@dev` po 10 000 (stos compose i E2E mają jeden adres sklepu). `when@test`: wartości powyżej liczby żądań danego rodzaju w jednym istniejącym teście (zasada z komentarza `rate_limiter.yaml`); testy limitów wykonują limit + 1 żądań. Uczciwy klient zakłada koszyk raz na przeglądarkę, zmienia go kilkadziesiąt razy i sprawdza kod kilka razy. |
| D4 | **Bez jednego komunikatu odrzucenia kodu (do potwierdzenia).** Podgląd i 409 zamówienia zachowują `invalid`, `used_up` i `below_minimum` z tekstami jak dziś (powód: „Poza zakresem”). |
| D5 | **Wielkość zamówienia gościa (#332, do potwierdzenia):** najwyżej 10 pozycji (wariantów) i 10 sztuk jednej pozycji. Reguła domeny Ordering (obiekt reguły, np. `GuestOrderSize`), którą `PlaceOrder` sprawdza dla zamówienia gościa zaraz po blokadzie koszyka i sprawdzeniu powtórzenia, przed wyceną, użyciem kodu, numerem i rezerwacją. Kolejność odrzuceń: `CartEmpty`, wielkość gościa, potem jak dziś (`CartNotOrderable`, `DiscountCodeRejected`, `OrderTotalChanged`, niedobór). Zamówienie klienta i koszyki bez zmian (50 pozycji, 99 sztuk); koszyk gościa nadal przyjmuje 50 × 99, bo nie rezerwuje towaru, a kasa pokazuje komunikat przy złożeniu. Przekroczenie: 409 z nowym typem problemu. |
| D6 | **Nieopłacone zamówienia (SA-04 p. 1, do potwierdzenia):** liczone są zamówienia `new` do ich wygaszenia (`paymentDeadline` + `DELIVERY_WINDOW`, 35 min), także gdy worker spóźnia się z wygaszeniem. Każde złożenie (gość i klient): najwyżej 5 z jednego adresu klienta. Zamówienie gościa: najwyżej 3 na jeden e-mail (bez odstępów na brzegach, bez wielkości liter, bez części `+…` przed `@`). Zamówienie klienta: najwyżej 3 na konto (`sub`); e-mail klienta nie liczy się do limitu e-maila gościa i odwrotnie, więc cudzy e-mail podany przez gościa nie blokuje konta. Wszystkie przypadki to ten sam 409 i ten sam neutralny komunikat (nie mówi, czy chodzi o adres, e-mail, czy konto). Wartości w konfiguracji API: domyślne (produkcja), `when@dev` 10 000, `when@test` 20 (powyżej 10 równoległych złożeń z jednym e-mailem w `PlaceOrderConcurrencyTest`; testy limitu składają 21). |
| D7 | **Roszczenie nieopłaconego zamówienia.** Klucze limitu zapisuje osobna tabela Ordering (np. `ordering_unpaid_order_claim`: `order_id` PK i FK, klucz adresu, klucz tożsamości `email:<klucz>` albo `customer:<sub>`, `expires_at` = wygaszenie), przez rekord ORM, nie agregat `Order`. `PlaceOrder` zapisuje wiersz razem z zamówieniem; `markPaid` i anulowanie (`ExpireUnpaidOrder`) usuwają go w tej samej transakcji (port Ordering). Licznik bierze wiersze z `expires_at > now`. Surowy adres istnieje tylko do opłacenia albo wygaszenia (najwyżej 35 min); żadne API ani panel go nie zwraca. Adres nie wchodzi do odcisku żądania (`RequestFingerprint`), więc powtórzenie z innej sieci działa. Zamówienia `new` sprzed wdrożenia nie mają wiersza i nie liczą się (wygasną w 35 min); bez migracji danych. |
| D8 | **Szeregowanie limitu nieopłaconych zamówień.** `PlaceOrder` po sprawdzeniu wielkości (D5) bierze blokady doradcze transakcji (`pg_advisory_xact_lock`) na klucz adresu, potem na klucz tożsamości, i dopiero pod nimi liczy roszczenia. Jawny wyjątek DBAL od zasady „persystencja przez rekordy ORM”, jak `PostgresPromotionWriteLock` w Pricing: DBAL służy tylko do blokady, liczenie i zapis idą przez repozytorium. Kolejność blokad złożenia: koszyk, adres, tożsamość, kod rabatowy, stany. Powtórzenie złożenia (ten sam klucz i odcisk) wraca przed limitem i nigdy w niego nie trafia. |
| D9 | **Równoległe rozpoczęcia płatności (SA-03 p. 2).** `StartPayment` po sprawdzeniu dostępu i powtórzenia próbuje wziąć blokadę doradczą transakcji na zamówienie (`pg_try_advisory_xact_lock`, bez czekania); gdy jej nie dostanie, od razu kończy się istniejącym 409 `PaymentInProgress` („Płatność za to zamówienie jest w toku. Spróbuj za chwilę.”). Kontrakt API bez zmian. Jawny wyjątek DBAL w Payment, jak w D8. |
| D10 | **Treść żądania (SA-12, #413 SR-3, do potwierdzenia).** API (Caddy, działa też w compose): najwyżej 1 MB; wyjątek tylko `POST` na dokładnej ścieżce `/api/v1/admin/product-images` (z rozróżnieniem wielkości liter): 71 MB, czyli powyżej `post_max_size` 70 MB, więc za duże zdjęcie nadal daje dzisiejsze 422 z kontrolera (z nagłówkami CORS panelu); nagłówki w 10 s, treść w 60 s (FrankenPHP ≥ 1.13.5, GHSA-j45x-h238-h4xx). Przekroczenie 1 MB: 413 problem JSON z polskim `detail` z Caddy (`handle_errors`); żaden klient przeglądarkowy nie wysyła takich treści, więc 413 nie potrzebuje CORS. BFF sklepu: treść JSON najwyżej 16 KiB (`/api/revalidate`: 32 KiB, bo przyjmuje do 100 tagów), sprawdzana przed odczytem i w trakcie. Ingress (tylko chart): middleware Traefika `buffering` na hoście `api.` (1 MiB; osobna ścieżka uploadu 71 MiB) i na ścieżce `/api/` hosta sklepu (1 MiB), więc wolna treść nie dochodzi do wątków API; strony HTML i RSC sklepu bez buforowania (E4). |
| D11 | **Polityka haseł Keycloaka (B6, SA-08, do potwierdzenia):** oba realmy `length(12) and maxLength(128) and notUsername and notEmail`, `backoffice` dodatkowo `passwordHistory(5)`; bez listy zakazanych haseł (poza zakresem). Plik realmu ma `passwordPolicy`; chart kopiuje ją z pliku do `<realm>.settings.json`, więc Job `keycloak-config` ustawia ją przy każdym uruchomieniu także w istniejącym realmie. Istniejące hasła działają do następnej zmiany hasła. Compose importuje realm tylko przy pustym wolumenie Keycloaka: lokalny stos sprzed zmiany wymaga `make down` z usunięciem wolumenów. |
| D12 | **Cache ISR strony produktu (SA-13, do potwierdzenia: wartości):** cache ISR i `fetch` sklepu ograniczony rozmiarem w pamięci poda (128 MB, wypieranie najdawniej używanych), bez zapisu wpisów na dysk (E3); rewalidacja po tagach działa jak dziś. Slug spoza `isProductSlug` to 404 bez odczytu API. Kontener `store` w chartcie ma limit `ephemeral-storage` 1 Gi jako zabezpieczenie dysku węzła. Bez nowej zależności i bez Redisa. |
| D13 | **Błędny token bez zdarzenia Sentry i bez zrzutu logów (SA-18):** logi weryfikacji tokenu dostępu trafiają do kanału Monologa `security`, który pomija handler `sentry` i który nie uruchamia zrzutu `fingers_crossed` w `prod` (osobny handler JSON od `warning`, jak kanał `cache`). |
| D14 | **Sklep przekazuje adres klienta w każdym wywołaniu API koszyka** (`forwardingClientIp`, jak kasa i płatność), bo L2 i L3 liczą po adresie. Bez tego w `prod` wszystkie koszyki dzieliłyby jeden limit adresu sklepu. |
| D15 | **Pusty koszyk gościa wygasa po 1 dniu (#406, do potwierdzenia):** koszyk gościa bez linii ma `expiresAt` = ostatnia zmiana + 1 dzień, z liniami 30 dni jak dziś; reguła w `Cart::touch` i przy założeniu. Usuwa go istniejące `cart:purge-expired`. |

## L1. Blokada licznika limiterów (#421)

**Stan.** `apps/api/config/packages/rate_limiter.yaml:8,21,27,33`: `lock_factory: null`, okno przesuwne czytane
i zapisywane w Redisie bez blokady. #421: przy 24 równoległych strumieniach limit 10 000 przepuścił 10 519 i 10 817
żądań (8–30% zgubionych przyrostów). HARDENING D1 to akceptował; nowe limity (L2, L3) są małe, więc zgubione przyrosty
zmieniają ich sens.

**Wymagane.** D1 dla wszystkich limiterów (`order_placement`, `payment_start`, `payment_webhook` i nowych z L2, L3).

**Kryteria akceptacji.**
1. Integracyjny na prawdziwym Redisie: N równoległych żądań (co najmniej 20 procesów) do limitera o limicie L daje
   najwyżej L przyjętych; raport zadania pokazuje fazę RED tego testu przy `lock_factory: null`.
2. Rywalizacja: blokada klucza trzymana dłużej niż 300 ms (wstrzymany proces) — kolejne żądanie tego klucza dostaje
   429 po najwyżej ok. 300 ms, nie przechodzi; inny klucz nie czeka.
3. Porzucona blokada (proces przerwany) przestaje blokować klucz po 5 s.
4. Redis niedostępny: żądanie limitowanej trasy przechodzi, czas odpowiedzi mieści się w limitach czasu DSN, log ma
   wpis `warning` (rozszerzenie `RedisRateLimitTest`).
5. `RateLimitConfigurationTest` sprawdza blokadę każdego limitera w `prod` i `dev`.

**Skutek dla klienta.** Brak; dwa dodatkowe wywołania Redisa na limitowane żądanie.

## L2. Limity koszyka gościa (#406, SA-15)

**Stan.** `apps/api/src/Cart/UI/Http/GuestCartController.php:43` (`POST /api/v1/guest-carts`) i `:112`, `:142`,
`:174` (zapisy linii) nie mają `RateLimit::ROUTE_DEFAULT`; każdy koszyk żyje 30 dni od ostatniej zmiany
(`apps/api/src/Cart/Domain/Cart.php:20`), także pusty, i przyjmuje 50 linii (`:18`). Audyt: 300 koszyków w 12 s
z jednego adresu.

**Wymagane.** Limitery `guest_cart_create` i `guest_cart_write` (D2, D3) na trasach gościa; 429 jak D8 HARDENING
(problem, `Retry-After`, `private, no-store`), sprawdzany przed kontrolerem. OpenAPI opisuje 429 na tych trasach.
Odczyty koszyka (bez kodu) i trasy klienta bez limitu. D15 dla pustego koszyka gościa.

**Kryteria akceptacji.**
1. (wartości `when@test`) Żądanie ponad limit zakładania to 429 z `Retry-After`; inny adres przechodzi.
2. Zmiany koszyka ponad limit to 429; `POST`, `PUT` i `DELETE` liczą się do jednego licznika.
3. Próby obejścia: inny koszyk tego samego adresu (limit zmian jest na adres, nie na koszyk); adres IPv6 z tego samego
   /64; `CF-Connecting-IP` podany przy pustym `CLIENT_IP_HEADER` (ignorowany); nagłówek z dwoma adresami albo
   śmieciem (adres połączenia); wielkość liter identyfikatora koszyka w ścieżce; żądanie odrzucone walidacją (422)
   też zużywa limit.
4. 429 nie zakłada koszyka ani nie zmienia linii.
5. Jednostkowe (TDD): nowy koszyk gościa i koszyk po usunięciu ostatniej linii wygasają po 1 dniu, koszyk z linią po
   30 dniach; koszyk klienta bez zmian.
6. Test konfiguracji: wartości `prod` i `dev` z D3.

**Skutek dla klienta.** Brak przy zwykłym użyciu. Koszyk opróżniony i zostawiony na dłużej niż dzień zaczyna się od
nowa (był pusty). Wiele osób za jednym adresem (NAT firmy, operator komórkowy) dzieli limit.

## L3. Limit podglądu kodu rabatowego (#407, SA-14)

**Stan.** Podgląd kodu to odczyt koszyka z `?discountCode=` (`GuestCartController.php:65`,
`apps/api/src/Cart/UI/Http/CustomerCartController.php:41`) bez limitu w API i w BFF (`apps/store/app/api/cart/route.ts:9-10`).
Odpowiedź odróżnia kod nieznany (`invalid`) od istniejącego (`applied` z wartością, `used_up`, `below_minimum` z progiem;
`apps/api/src/Pricing/Domain/DiscountCode/DiscountCodeRejection.php:53-60`). Audyt: 300 prób w 6,3 s, oba kody seeda
znalezione słownikiem 80 haseł. `docs/features/discount-codes/spec.md:30` odkłada limit „przed publiczne demo”.

**Wymagane.** Limiter `discount_preview` (D2, D3) na obu trasach odczytu koszyka, zużywany, gdy żądanie ma parametr
`discountCode` o dowolnej wartości innej niż pusty napis (także same odstępy, odstępy Unicode i wartość nie będąca
napisem, np. `discountCode[]=`): limiter niczego nie przycina, więc nie może uznać za pusty kodu, który Pricing
uzna za niepusty. Odczyt bez parametru albo z `discountCode=` nie zużywa limitu. Warunek jest częścią mechanizmu
limiterów tras (`RateLimitSubscriber`), nie kontrolera. Treść odpowiedzi podglądu bez zmian (D4). OpenAPI opisuje
429 na obu trasach. Wpis o odłożonym limicie w specyfikacji kodów znika.

**Kryteria akceptacji.**
1. (`when@test`) Podgląd z kodem ponad limit to 429 przed wyceną; odczyt bez kodu z tego adresu nadal 200.
2. Próby obejścia, każda zużywa ten sam licznik: inny koszyk gościa; przełączanie tras gościa i klienta (`/me/cart`)
   z jednego adresu; kod małymi literami, z odstępami ASCII i NBSP na brzegach, ten sam kod powtórzony;
   `discountCode` jako tablica albo za długi (400); adres z tego samego /64; podrobiony `CF-Connecting-IP` jak w L2.
3. `?discountCode=` nie zużywa limitu i daje koszyk bez kodu jak dziś.
4. Równoległa seria podglądów nie przekracza limitu (L1).

**Skutek dla klienta.** 20 sprawdzeń kodu na 15 minut; kasa czyta koszyk z kodem przy zastosowaniu kodu i po konflikcie
złożenia, co daje kilka odczytów na zakup. Komunikat 429 w polu kodu (L4); koszyk i suma bez kodu nadal widoczne.

## L4. Sklep: adres klienta, 429 i limit treści w BFF

**Stan.** Route handlery koszyka (`apps/store/app/api/cart/**`) wołają API bez nagłówka adresu klienta; robią to tylko
BFF kasy i płatności (`apps/store/src/checkout/bff.ts:49`, `apps/store/src/checkout/payment-bff.ts:96,191`). 429 API
koszyka BFF zamienia na 503 (`apps/store/src/cart/bff.ts`, `toOutcome`; zakładanie koszyka w `runForGuest` każdy błąd),
a przeglądarka na „failed” (`apps/store/src/api/client.ts:108-137`). `readJsonObject`
(`apps/store/src/cart/bff.ts:51-58`) czyta całą treść bez limitu.

**Wymagane.**
- D14 dla wszystkich wywołań API z `runCartCommand` (także zakładania koszyka i scalenia).
- BFF koszyka: 429 API (także z zakładania koszyka) → 429 z `detail` i `Retry-After`; `callCart` w przeglądarce
  zwraca osobny wynik `rate_limited`.
- Komunikaty (sekcja „Komunikaty”): dodanie do koszyka i zmiana ilości pokazują komunikat zmian koszyka w miejscu
  dzisiejszych błędów; pole kodu w kasie pokazuje komunikat podglądu kodu, a kasa czyta koszyk bez kodu (jak przy
  kodzie odrzuconym 400).
- Limit treści BFF z D10: wspólny odczyt JSON w zapisach koszyka, kasie i płatności oraz w `/api/revalidate`:
  `Content-Length` ponad limit to 413 bez odczytu treści, treść bez `Content-Length` albo dłuższa niż deklarowana jest
  czytana do limitu i przerywana; 413 ma `{ detail }` z polskim tekstem i nie woła API.

**Kryteria akceptacji.**
1. Vitest: każda trasa koszyka (`GET`, `summary`, `POST`, `PATCH`, `DELETE`, zakładanie koszyka, gałąź klienta ze
   scaleniem) wysyła do API nagłówek z `CLIENT_IP_HEADER`, gdy zmienna jest ustawiona; bez zmiennej nie wysyła
   żadnego.
2. Vitest: 429 API (zapis, zakładanie, podgląd kodu) → 429 BFF z `Retry-After` i `detail`; strona koszyka, przycisk
   dodania i pole kodu pokazują komunikaty z sekcji „Komunikaty”; formularz kasy i koszyk zostają.
3. Vitest: treść o 1 bajt ponad limit (z `Content-Length`, bez niego strumieniem, z zaniżonym `Content-Length`) to
   413 bez wywołania API we wszystkich trasach z odczytem JSON i w `/api/revalidate`; treść równa limitowi
   przechodzi; `/api/revalidate` ze 100 tagami najdłuższego dozwolonego formatu przechodzi.

**Skutek dla klienta.** Brak poza komunikatami przy limitach.

## L5. Wielkość zamówienia gościa (#332, SA-04 p. 2)

**Stan.** Rezerwacja powstaje przy złożeniu, przed płatnością
(`apps/api/src/Ordering/Application/Command/PlaceOrderHandler.php:130,134`), na 30 + 5 minut
(`apps/api/src/Ordering/Domain/Order.php:31-32`). Zamówienie ma do 50 pozycji (`Order.php:30`) po 99 sztuk
(`apps/api/src/Ordering/Domain/OrderLine.php:15`). Specyfikacja HARDENING (D16, D19) zostawiła to #332 jako bloker
publicznego demo.

**Wymagane.** D5. 409 z typem `…/problems/guest-order-too-large` w `OrderConflictProblem` (OpenAPI, klient TS)
i polskim `detail`. Kasa traktuje go jak inne 409 koszyka: komunikat i odnośnik do koszyka, formularz zostaje (nowy
powód w `apps/store/src/checkout/problems.ts`, którego wymaga kompilator).

**Kryteria akceptacji.**
1. Jednostkowe (TDD): reguła przyjmuje 10 pozycji po 10 sztuk, odrzuca 11 pozycji i 11 sztuk jednej pozycji;
   `PlaceOrder` (adaptery in-memory) sprawdza ją dla gościa przed wyceną i rezerwacją, a dla klienta nie.
2. Funkcjonalne: złożenie gościa z koszyka 11 pozycji (także z kodem z limitem) to 409 z nowym typem; stan, użycia
   kodu i koszyk bez zmian, brak zamówienia i wiadomości `ExpireUnpaidOrder`; zamówienie klienta z 50 pozycjami po
   99 sztuk powstaje jak dziś.
3. Próby obejścia: koszyk 11 pozycji z jedną niedostępną (kolejność odrzuceń z D5); ten sam wariant nie trafia do
   zamówienia dwa razy (linie koszyka unikalne po wariancie, `Order::place` odrzuca powtórzenie); równoległe złożenia
   z dwóch koszyków każde sprawdza swój rozmiar.
4. E2E (`e2e/tests/checkout-guest-limits.spec.ts`): gość z 11 pozycjami w koszyku widzi komunikat w kasie i nic nie
   zostaje zamówione; istniejące scenariusze kasy mieszczą się w limitach.

**Skutek dla klienta.** Gość zamawia najwyżej 10 różnych produktów po 10 sztuk; komplet jadalni (stół i 8 krzeseł) to
2 pozycje i 9 sztuk. Większe zamówienie wymaga dwóch zamówień albo (gdy rejestracja będzie włączona) konta.

## L6. Nieopłacone zamówienia na adres, e-mail i konto (SA-04 p. 1)

**Stan.** Jedyny limit to `order_placement` 10 złożeń na 15 min na adres albo konto
(`apps/api/config/packages/rate_limiter.yaml:17-21`). Zamówienie żyje 35 min, więc jeden adres trzyma stale ok. 23
nieopłacone zamówienia; nie ma limitu na e-mail.

**Wymagane.** D6, D7, D8. Adres klienta wyznacza kontroler złożenia (`ClientIp`, jak limiter; docblock `ClientIp`
wymienia nowego użytkownika) i przekazuje w komendzie `PlaceOrder`. Przekroczenie: 409
`…/problems/too-many-unpaid-orders` (OpenAPI, klient TS, powód w `problems.ts`), nic nie jest zarezerwowane ani
zapisane. Indeksy tabeli roszczeń na kluczu adresu i kluczu tożsamości z `expires_at`. Skrypty k6 checkoutu używają
e-maila unikalnego w iteracji (dziś jeden na użytkownika wirtualnego, `load/lib/flows.js:96`), żeby pomiar na
wartościach `prod` nie trafiał w limit.

**Kryteria akceptacji.**
1. Jednostkowe (TDD): klucz e-maila (odstępy, wielkość liter, `+…`), klucz tożsamości gościa i klienta, reguła limitu
   z portem liczącym.
2. Funkcjonalne (`when@test`): (limit adresu + 1). złożenie z jednego adresu przy nieopłaconych poprzednich to 409;
   po opłaceniu (webhook) albo wygaśnięciu jednego z nich złożenie przechodzi; inny adres przechodzi.
3. Próby obejścia limitu e-maila: ten sam e-mail wielkimi literami, z odstępami, z `+tag`. Zamówienia gościa z e-mailem
   konta klienta nie liczą się do limitu konta (test: konto składa zamówienie mimo 3 nieopłaconych zamówień gościa
   z jego e-mailem).
4. Współbieżność (integracyjny, PostgreSQL, procesy równoległe): N równoległych złożeń z różnych koszyków, jednego
   adresu i różnych e-maili tworzy dokładnie tyle zamówień, ile pozwala limit adresu; to samo dla jednego e-maila
   i różnych adresów.
5. Powtórzenie złożenia (ten sam `Idempotency-Key` i treść) przy wyczerpanym limicie, także z innego adresu, zwraca
   istniejące zamówienie (200), nie 409.
6. Zamówienie `new` między terminem płatności a wygaszeniem liczy się; po `expires_at` nie liczy się, nawet gdy wciąż
   jest `new` (spóźniony worker).
7. Roszczenie istnieje dla zamówienia `new`, a po `markPaid` i po wygaśnięciu go nie ma; żaden widok API ani panelu nie
   zwraca adresu (test odpowiedzi).
8. Odpowiedź 409 jest taka sama dla limitu adresu, e-maila i konta (typ, `detail`).
9. Test plików k6 (`infra/k8s/tests/test_load_files.py`): e-mail checkoutu jest unikalny w iteracji; `make load-smoke`
   przechodzi.

**Skutek dla klienta.** Klient, któremu płatność się nie udała, ponawia ją na tym samym zamówieniu (strona
potwierdzenia), więc zwykle ma jedno nieopłacone zamówienie. Czwarte nieopłacone zamówienie gościa na ten sam e-mail
w ciągu 35 minut dostaje komunikat; szóste z jednego adresu też (NAT dzieli limit adresu, więc komunikat jest
neutralny).

## L7. Równoległe rozpoczęcia płatności jednego zamówienia (SA-03)

**Stan.** `apps/api/src/Payment/Application/Command/StartPaymentHandler.php:90-98`: w jednej transakcji blokada
oczekującej płatności (`:121`), zapis nowej i wywołanie operatora HTTP (5 s). Równoległe rozpoczęcie czeka na
unikalnym indeksie, trzymając wątek FrankenPHP i połączenie; operator (symulator) działa w tym samym API, więc przy
żądaniach w liczbie wątków całe API stoi (audyt: 40 żądań, ok. 36 s bez odpowiedzi, także `/health`).

**Wymagane.** D9. Blokada zaraz po sprawdzeniu powtórzenia, przed zakończeniem oczekującej płatności; zwalnia ją koniec
transakcji. Powtórzenie z tym samym kluczem, gdy pierwsze żądanie jeszcze trwa, też dostaje 409 (wcześniej czekało).
Opis 409 w OpenAPI już obejmuje „a concurrent start”.

**Kryteria akceptacji.**
1. Integracyjny (`StartPaymentConcurrencyTest`, procesy równoległe): przy rozpoczęciu trzymającym blokadę (operator
   wstrzymany) N równoległych rozpoczęć z różnymi kluczami kończy się 409 `PaymentInProgress` w czasie znacznie
   krótszym niż limit czasu operatora (pomiar w teście); powstaje jedna płatność `pending`.
2. Próby obejścia: ten sam klucz w locie; inny dostawca — oba 409 w trakcie pierwszego rozpoczęcia.
3. Po zakończeniu pierwszego rozpoczęcia kolejne działa jak dziś (zakończenie oczekującej płatności u operatora).
4. Rozpoczęcia płatności różnych zamówień nie blokują się nawzajem.
5. Scenariusze W4 i W5 specyfikacji płatności przechodzą z nowym zachowaniem.

**Skutek dla klienta.** Podwójne kliknięcie „Zapłać” daje drugiemu żądaniu od razu komunikat „Płatność za to
zamówienie jest w toku. Spróbuj za chwilę.” zamiast czekania.

## L8. Limit rozmiaru i czasu treści żądania (SA-12, #413 SR-3)

**Stan.** API: `apps/api/docker/frankenphp/Caddyfile:7-11` bez `request_body` i limitów czasu;
`apps/api/docker/php/conf.d/app.ini:10` (`post_max_size = 70M`) nie dotyczy `application/json`, które PHP czyta
w całości (audyt: JSON 150 MB wyczerpuje `memory_limit`, 40 wolnych treści blokuje `/health`). `php_server` przydziela
wątek PHP po nagłówkach, więc wolna treść trzyma wątek. Obraz `dunglas/frankenphp:1.13.4` (`apps/api/Dockerfile:8`)
ma lukę, przez którą limit czasu treści nie działa. Ingress (`infra/k8s/charts/shop/templates/ingress.yaml`,
`middlewares.yaml`) bez `buffering`.

**Wymagane.** D10 dla API (Caddy) i ingressu (część BFF: L4). Obraz FrankenPHP ≥ 1.13.5. Osobna reguła ingressu dla
ścieżki uploadu na hoście `api.` i dla `/api/` hosta sklepu.

**Kryteria akceptacji.**
1. Na stosie compose (`e2e/tests/request-body-limit.spec.ts`, wywołania API bez sklepu): JSON 1 MB + 1 bajt do
   `POST /api/v1/orders` to 413 problem z polskim `detail`, bez wpisu błędu PHP; 1 MB przechodzi do walidacji;
   zdjęcie 20 MB do `POST /api/v1/admin/product-images` przechodzi jak dziś, a 70 MB + 1 bajt daje dzisiejsze 422.
2. Próby obejścia: treść chunked bez `Content-Length`; `Content-Length` zaniżony; ścieżka uploadu inną wielkością
   liter, z dopisanym segmentem (`/api/v1/admin/product-images/x`) i metodą `PUT` dostaje limit 1 MB; `PUT` i `PATCH`
   innych tras mają limit 1 MB.
3. Compose: treść wysyłana wolniej niż limit kończy się zamknięciem połączenia najpóźniej po 60 s, a `/health` odpowiada
   najpóźniej po tym czasie.
4. Render chartu (`infra/k8s/tests`): ingress `api` ma `buffering` 1 MiB, a jego ścieżka uploadu 71 MiB; ścieżka
   `/api/` hosta sklepu ma `buffering` 1 MiB, a reszta hosta sklepu, `admin`, `auth` i `img` go nie mają.
5. Test dymny k3d (`infra/k8s/smoke/checks.py`): treść 2 MiB do `/api/checkout` sklepu i do `/api/v1/orders` przez
   ingress to 413; przy wolnych treściach w liczbie większej niż wątki API `/health` przez ingress odpowiada od razu;
   strona produktu przez ingress dalej przychodzi strumieniem (bez zmian w teście HTML).

**Skutek dla klienta.** Brak; największe treści sklepu mają kilka KB. Pracownik wysyła zdjęcie do 64 MB jak dziś.

## L9. Cache ISR strony produktu (SA-13)

**Stan.** `apps/store/app/p/[slug]/page.tsx:10,12-14`: ISR (`revalidate = 3600`, pusty `generateStaticParams`), 404
nieznanego sluga też trafia do cache. Next zapisuje każdy wpis na dysku poda bez sprzątania (audyt: ok. 88 KB na
unikalny slug, 100 żądań/s to ok. 30 GB/h na dysku wspólnym z bazami). Kontener `store` nie ma limitu
`ephemeral-storage` (`infra/k8s/charts/shop/values.yaml:194`).

**Wymagane.** D12. Rozmiar cache jest stałą konfiguracji sklepu, nie zmienną środowiska. Wpis 404 sluga odwiedzonego
przed publikacją dalej znika po rewalidacji tagu `product-slug-<slug>` (`docs/features/product-page/spec.md:23`).

**Kryteria akceptacji.**
1. Test na buildzie produkcyjnym sklepu: po wejściu na N unikalnych slugów (N tak dobrane, że wpisy przekraczają
   limit pamięci) katalog cache na dysku ma ten sam rozmiar co przed nimi, a pierwszy slug po przepełnieniu znów daje
   `x-nextjs-cache: MISS`.
2. Slug spoza `isProductSlug` (np. z wielkimi literami, kropką, za długi) to 404 bez wywołania API (test jednostkowy).
3. Istniejące testy świeżości strony produktu i rewalidacji po tagach (`product-page-freshness.spec.ts`, E2E
   produkcyjne w CI) przechodzą bez zmian.
4. Render chartu: kontener `store` ma `resources.limits.ephemeral-storage: 1Gi`, inne kontenery bez zmian.

**Skutek dla klienta.** Brak; po restarcie poda cache startuje pusty jak dziś. Pod atakiem popularne strony mogą
częściej renderować się od nowa.

## L10. Błędny token Bearer bez zdarzenia Sentry (SA-18)

**Stan.** Handler `sentry` (`apps/api/config/packages/monolog.yaml:10-13`) zamienia każdy log `error` kanału `app`
w zdarzenie; `OidcTokenHandler` (przez `apps/api/src/Shared/Infrastructure/Security/KeySourceOutageTokenHandler.php:30`)
loguje `error` przy każdym tokenie, którego nie da się zweryfikować. Kryterium SENTRY „401 nie daje zdarzeń” jest
prawdziwe tylko bez tokenu. Wysyłka jest synchroniczna (2 s) i zużywa limit zdarzeń planu darmowego; w `prod` ten sam
wpis uruchamia zrzut bufora `fingers_crossed` (`monolog.yaml:35-44`).

**Wymagane.** D13.

**Kryteria akceptacji.**
1. Funkcjonalny (licznik zdarzeń transportu Sentry jak w `SentryHttpTest`): `Authorization: Bearer x`, token z obcym
   podpisem, wygasły, z innym wystawcą i z `alg: none` na `/api/v1/me` i `/api/v1/admin/orders` dają 401 i zero
   zdarzeń.
2. Jawny log `error` kanału `app` nadal daje zdarzenie; niedostępny wystawca kluczy nadal daje 503 jak dziś.
3. Konfiguracja Monologa `prod` (test jak `RateLimitConfigurationTest`): kanał `security` jest poza handlerem `sentry`
   i poza `fingers_crossed`, z osobnym handlerem JSON od `warning`.

**Skutek dla klienta.** Brak.

## L11. Polityka haseł Keycloaka (B6, SA-08)

**Stan.** `infra/keycloak/realms/shop.json` i `backoffice.json` nie mają `passwordPolicy`; Job `keycloak-config`
ustawia przy każdym uruchomieniu tylko SMTP i, w `shop`, rejestrację i stałe `verifyEmail`
(`infra/k8s/charts/shop/templates/_keycloak.tpl:25-31`), a istniejący realm nie przyjmuje zmian z pliku (SA-10). Audyt:
rejestracja z hasłem `a` przechodzi. W `prod` rejestracja jest wyłączona (`customerAccounts: false`), więc dziś dotyczy
to kont pracowników (łagodzi TOTP).

**Wymagane.** D11.

**Kryteria akceptacji.**
1. E2E (`e2e/tests/registration.spec.ts`): rejestracja z hasłem 11-znakowym, z hasłem równym e-mailowi i z hasłem `a`
   kończy się komunikatem Keycloaka i bez konta; dotychczasowy scenariusz z hasłem 12+ znaków przechodzi.
2. Render chartu: `shop.settings.json` i `backoffice.settings.json` mają `passwordPolicy` równe wartości z plików
   realmów (test `infra/k8s/tests`), a test skryptu Joba na atrapie `kcadm` pokazuje, że wartość trafia do
   `update realms/<realm>`.
3. Konta demo z `make seed` logują się jak dziś (polityka nie dotyczy istniejących haseł).

**Skutek dla klienta.** Nowe hasła klientów i pracowników mają co najmniej 12 znaków i nie są loginem ani e-mailem.

## Komunikaty

| Sytuacja | Gdzie | Tekst |
|---|---|---|
| 429 zmian koszyka (L2) | strona koszyka, przycisk dodania | „Zbyt wiele zmian koszyka. Spróbuj ponownie za kilka minut.” |
| 429 podglądu kodu (L3) | pole kodu w kasie | „Zbyt wiele prób sprawdzenia kodu. Spróbuj ponownie za kilka minut.” |
| Zamówienie gościa za duże (L5) | kasa, `detail` 409 | „Zamówienie bez konta może mieć najwyżej 10 różnych produktów, po 10 sztuk każdego. Zmniejsz ilości w koszyku.” |
| Za dużo nieopłaconych zamówień (L6) | kasa, `detail` 409 | „Nie możemy teraz przyjąć kolejnego zamówienia, bo z tych danych albo z tej sieci czeka już kilka nieopłaconych. Spróbuj ponownie za ok. 35 minut.” |
| Równoległe rozpoczęcie płatności (L7) | strona potwierdzenia | istniejący „Płatność za to zamówienie jest w toku. Spróbuj za chwilę.” |
| Treść za duża (L4, L8) | API 413, BFF 413 | „Treść żądania jest za duża.” |

API zwraca 429 z istniejącym `detail` HARDENING („Zbyt wiele prób. Spróbuj ponownie za kilka minut.”); teksty sklepu
z tabeli wybiera sklep według miejsca, jak przy kasie.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Redis niedostępny (limiter albo blokada) | żądanie bez limitu, `warning` w logu `prod` (D1) |
| Blokada limitera zajęta dłużej niż 300 ms | 429 (D1) |
| Proces przerwany z blokadą limitera | klucz wolny najpóźniej po 5 s (D1) |
| Złożenie przy wyczerpanym limicie nieopłaconych, ten sam klucz idempotencji | 200 z istniejącym zamówieniem |
| Worker nie wygasza zamówień | roszczenia po `expires_at` nie liczą się do L6; stan zostaje zarezerwowany (R1 audytu, ASYNC-RELIABILITY) |
| `CLIENT_IP_HEADER` pusty (compose) | adres połączenia, wartości `when@dev` |
| Blokada płatności zajęta | 409 `PaymentInProgress` bez czekania |
| Treść ponad limit w BFF | 413 bez wywołania API |
| Slug spoza formatu | 404 bez wywołania API |

## Testy

- API: jednostkowe (TDD) dla reguł domeny i aplikacji (D15, L5, L6, klucze), funkcjonalne dla limiterów, 409 i 413
  (wartości `when@test`), integracyjne na prawdziwym Redisie (L1) i PostgreSQL z procesami równoległymi (L6, L7),
  test konfiguracji `prod` i `dev` (`RateLimitConfigurationTest`), testy kanałów Monologa (L10).
- Sklep (Vitest): L4, L9.
- Chart: testy renderu (`infra/k8s/tests`) dla L8, L9, L11; test plików k6 (L6); test dymny dla L8.
- E2E: `checkout-guest-limits.spec.ts` (L5), `request-body-limit.spec.ts` (L8), `registration.spec.ts` (L11);
  wszystkie istniejące scenariusze przechodzą na wartościach `when@dev`.
- Każde zadanie zmieniające regułę ma w raporcie wyniki fazy RED i GREEN; zmiany konfiguracji i infrastruktury
  weryfikują komendy z planu i wyniki eksperymentów E1–E5.

## Dokumentacja

Ta specyfikacja opisuje docelowe zachowanie, więc zadania nie opisują go drugi raz. `CLAUDE.md` aplikacji zmieniają
tylko dwie nowe reguły dla agentów, każdą jedno zadanie, jedną linią w `apps/api/CLAUDE.md`:

- „Każda nowa publiczna trasa zapisu bez logowania dostaje limiter (`RateLimit::ROUTE_DEFAULT`, wartości `prod`, `dev`
  i `test`) i test prób obejścia.” (L1–L3);
- „Blokada doradcza transakcji przez DBAL (`pg_advisory_xact_lock`, `pg_try_advisory_xact_lock`) do szeregowania jest
  dozwolonym wyjątkiem od persystencji przez rekordy ORM w każdym module; liczenie i zapis idą przez repozytorium.”
  (L6; korzysta z niej też L7).

Specyfikacje innych funkcji zmieniają się tylko tam, gdzie ich zdanie przeczy nowemu zachowaniu:

- `docs/features/hardening/spec.md`: D1 (bez `symfony/lock`), limity koszyka poza zakresem (L1–L3); D16 (L5, L6).
- `docs/features/discount-codes/spec.md`: limit podglądu poza zakresem i ryzyko zgadywania (L3); D7, kolejność blokad
  (L6).
- `docs/features/cart/spec.md`: decyzja 5 i rate limiting koszyków poza zakresem (L2); kody BFF dla przeglądarki (L4).
- `docs/features/checkout/spec.md`: tabela 409 złożenia (L5, L6).
- `docs/features/payment/spec.md`: równoległe rozpoczęcia i ryzyko samoblokady (L7).
- `docs/features/product-page/spec.md`: A6 i ryzyko wpisów ISR na dysku (L9).

## Ryzyka i ograniczenia

- Limity na adres nie zatrzymują atakującego z wieloma adresami; IPv6 liczy się prefiksem /64, a pula /48 daje 65 536
  kluczy. Następny krok to CAPTCHA w kasie gościa albo reguły Cloudflare (poza zakresem).
- Klienci za wspólnym NAT dzielą limity adresu (L2, L3, L6), jak limit złożenia z HARDENING.
- Limit e-maila gościa pozwala zablokować zamówienia gościa na cudzy e-mail na 35 min (najwyżej jeden e-mail na adres
  atakującego naraz); konto klienta jest od tego wolne.
- Kod z limitem użyć ≤ 5 da się wyczerpać z jednego adresu na 35 min (L6 nie rozdziela użycia wstępnego i opłaconego).
- Adres klienta wewnątrz klastra jest zaufany (HARDENING D2); `ClientIp` bez nagłówka daje adres połączenia.
- Blokada limitera dodaje dwa wywołania Redisa na limitowane żądanie; przy awarii Redisa limity nie działają (D1).
- Blokady doradcze L6 szeregują złożenia z jednego adresu i jednej tożsamości; złożenia z różnych adresów nie czekają
  na siebie. Wydajność checkoutu nie jest potwierdzona testem obciążeniowym po zmianie.
- Kolizja skrótu identyfikatora zamówienia w blokadzie L7 może dać 409 dla innego zamówienia; ponowienie przechodzi.
- Limit wielkości dotyczy tylko gościa; po włączeniu rejestracji konta mają limity L6, ale nie L5.
- Trasa uploadu przyjmuje anonimowo treść do 70 MB (PHP parsuje ją przed firewallem); buforuje ją Traefik.
- Cache ISR w pamięci jest osobny w każdym podzie (jak dziś na dysku) i znika przy restarcie.
- Polityka haseł nie wymusza zmiany słabych haseł już ustawionych.
