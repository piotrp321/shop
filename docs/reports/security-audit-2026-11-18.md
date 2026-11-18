# Audyt bezpieczeństwa sklepu 2026-11-18

## Nagłówek

- **Przedmiot:** aplikacja e-commerce `piotrp321/shop` (API Symfony, sklep Next.js z BFF, panel Vue, Keycloak,
  infrastruktura Compose i chart Helm na k3s), rewizja `origin/main` = `ef204620`.
- **Założenie oceny:** aplikacja ma zostać udostępniona publicznie i obsługiwać prawdziwych klientów, dane osobowe,
  płatności i zamówienia. Tam, gdzie ocena dla obecnego publicznego demo (profil `prod`: `customerAccounts: false`,
  płatności tylko symulowane, poczta zamówień w Mailpit) jest inna, raport podaje obie.
- **Poza zakresem:** Agentic Orchestrator, `.claude/`, `.ao/`, `tools/agent-security` i wszystko związane z Claude Code.
- **Metoda:** research standardów i advisories (stan na 2026-11-18), analiza statyczna, testy dynamiczne na
  odizolowanym lokalnym stosie `shop-secaudit` z danymi testowymi, kontrola zależności i historii Git, jeden
  niezależny przegląd najważniejszych znalezisk. Szczegóły i ograniczenia: załączniki A–C.
- **Czego nie robiono:** żadnych żądań do środowiska demo ani serwera, żadnych zmian w kodzie, konfiguracji,
  GitHubie; żadnych testów destrukcyjnych.
- **Skala poziomów:** Critical, High, Medium, Low, Informational. **Statusy:** `CONFIRMED` (potwierdzone testem albo
  jednoznaczną ścieżką w kodzie), `LIKELY`, `HYPOTHETICAL`, `HARDENING` (utwardzenie, nie podatność).
- **Identyfikatory:** `SA-NN` to pozycja tego raportu; w nawiasach podane są identyfikatory robocze audytorów
  obszarów (`AUTH`, `AUTHZ`, `BIZ`, `PAY`, `API`, `FE`, `INFRA`, `SC`, `PRIV`).

## 1. Executive summary

Rdzeń aplikacji jest napisany starannie. W testach dynamicznych nie udało się: odczytać ani zmienić
cudzego zasobu, obejść uwierzytelniania, wstrzyknąć SQL ani kodu, wykonać XSS, zmanipulować ceny, sumy, rabatu lub
statusu zamówienia, sprzedać ostatniej sztuki dwa razy, przekroczyć limitu kodu rabatowego współbieżnie, opłacić
zamówienia podrobionym albo powtórzonym webhookiem ani zwrócić pieniędzy dwa razy. Ceny i sumy liczy wyłącznie API,
reguły biznesowe są egzekwowane w agregatach i ograniczeniach bazy, a proces wdrożenia i workflowy CI mają dobrze
ograniczone uprawnienia. W historii Git nie ma prawdziwych sekretów.

Słabości leżą gdzie indziej: w **aktualności komponentów**, w **odporności na tanie nadużycia dostępności**,
w **izolacji wewnątrz klastra** i w tym, że **część funkcji potrzebnych prawdziwemu sklepowi jeszcze nie istnieje**
(operator płatności, usuwanie danych, kopie zapasowe w konfiguracji `prod`).

Znalezisk jest 42: 2 Critical, 4 High, 15 Medium, 20 Low i 1 zbiorcza pozycja informacyjna (poziomy przy założeniu
prawdziwej sprzedaży, po niezależnym przeglądzie). Około jedna trzecia pokrywa się z otwartymi zgłoszeniami
w backlogu (punkt 13). Najważniejsze:

| ID | Znalezisko | Prawdziwa sprzedaż | Demo dziś | Status |
|---|---|---|---|---|
| SA-01 | Keycloak 26.6.0: siedem miesięcy bez poprawek, m.in. krytyczna luka przejęcia konta przez reset hasła | Critical | Medium | wersja podatna `CONFIRMED`, wykorzystanie `LIKELY` (nieodtworzone lokalnie) |
| SA-02 | Jedynym operatorem płatności jest symulator bez wyłącznika; każdy „opłaca” własne zamówienie jednym kliknięciem | Critical | zamierzone | `CONFIRMED` |
| SA-03 | Kilkadziesiąt równoległych rozpoczęć płatności jednego zamówienia zatrzymuje całe API (36 s w teście) | High | High | `CONFIRMED` lokalnie, na produkcji `LIKELY` |
| SA-04 | Nieopłacone zamówienia gościa rezerwują towar na 35 min; jeden adres może uczynić cały katalog niedostępnym | High | Medium | `CONFIRMED` (skala wyliczona) |
| SA-05 | Jeden Secret z wszystkimi hasłami (superuser Postgresa, administrator Keycloaka…) w środowisku każdego poda | High | High | `CONFIRMED` |
| SA-06 | Brak NetworkPolicy; Redis z sesjami i tokenami OIDC bez hasła | High | Medium | `CONFIRMED` |
| SA-07 | Kopie zapasowe wyłączone w profilu `prod` | Medium | Low | `CONFIRMED` |
| SA-21 | Brak usuwania, anonimizacji, eksportu i retencji danych klientów | Medium | Medium | `CONFIRMED` |

### Odpowiedzi na pytania

**Czy udostępniłbym tę aplikację publicznie w obecnej postaci?**
Jako demo bez prawdziwych danych i pieniędzy: tak, pod warunkiem natychmiastowej aktualizacji Keycloaka (SA-01)
i ze świadomością, że każdy może tanio unieruchomić API (SA-03) albo „wyprzedać” katalog (SA-04). Jako działający
sklep: nie.

**Czy powierzyłbym jej rzeczywiste dane klientów?**
Nie. Blokują to: niezałatany dostawca tożsamości (SA-01), brak izolacji sekretów i sieci w klastrze, przez który
jedno przejęcie dowolnego komponentu daje dostęp do wszystkich danych i sesji (SA-05, SA-06), wyłączone kopie
zapasowe (SA-07), brak polityki haseł i limitów prób logowania (SA-08, SA-09) oraz brak mechanizmów usuwania
i retencji danych (SA-21).

**Czy dopuściłbym obsługę prawdziwych płatności?**
Nie. Prawdziwego operatora nie ma, a symulator nie ma wyłącznika (SA-02). Port płatności nie umie zapytać operatora
o status ani zlecić zwrotu, a pieniądze przyjęte bez opłaconego zamówienia są widoczne tylko w bazie i logu
(SA-22, SA-23). Sama obsługa webhooków (podpis, deduplikacja, zgodność kwoty, kolejność zdarzeń) jest natomiast
solidna i nadaje się na wzorzec dla prawdziwego adaptera.

**Jakie podatności bezwzględnie blokują wdrożenie produkcyjne?**
SA-01 (Keycloak), SA-02 (symulator jako operator), SA-03 (samoblokada API), SA-04 (blokowanie towaru), SA-05 z SA-06
(sekrety i sieć w klastrze), SA-07 (kopie zapasowe) i SA-21 (usuwanie i retencja danych: bloker procesowo-prawny,
istniejące zgłoszenie #119).

**Trzy największe zagrożenia biznesowe**

1. **Towar wydany bez pieniędzy albo pieniądze bez towaru.** Dziś każde zamówienie da się „opłacić” bez płatności
   (SA-02). Po podłączeniu operatora bez uzupełnień: podwójne i spóźnione płatności bez zwrotu, zapłacone
   zamówienia anulowane po utraconym webhooku (SA-22, SA-23).
2. **Przejęcie kont i wyciek danych osobowych.** Niezałatany Keycloak (SA-01) plus brak izolacji w klastrze
   (SA-05, SA-06): jedna luka w dowolnym komponencie wystawionym do internetu (Next.js, imgproxy, Keycloak) daje
   bazę, sesje wszystkich klientów i konto administratora tożsamości. Skutek: naruszenie RODO z obowiązkiem
   zgłoszenia w 72 godziny i brakiem kopii do odtworzenia (SA-07).
3. **Tanie zatrzymanie sprzedaży.** Jeden anonimowy użytkownik, bez botnetu: zatrzymuje API (SA-03), oznacza cały
   katalog jako niedostępny (SA-04), zapełnia dysk albo pamięć (SA-12, SA-13, SA-15), a przy okazji oślepia
   monitoring błędów (SA-18).

**Co naprawić natychmiast** (dni, nie tygodnie): aktualizacja Keycloaka do linii z poprawkami (SA-01); aktualizacja
Symfony do 8.1.11 i Next.js do 16.4.5+ (SA-17, SA-38; #411); włączenie alertów Dependabota i `composer audit` /
`npm audit` w CI (SA-16); odrzucanie równoległych rozpoczęć płatności bez czekania (SA-03); limit rezerwowanego
towaru i nieopłaconych zamówień gościa (SA-04, #332); włączenie kopii zapasowych (SA-07, #690).

**Co może poczekać:** wszystkie pozycje Low (SA-24…SA-42): prefiks `__Host-`, pełniejsze CSP, `securityContext`,
rotacja sekretów, role tylko do odczytu, enumeracja kont przez rejestrację, rotacja refresh tokenów, podpisywanie
obrazów. Warto je zebrać w jeden epik utwardzający przed startem sprzedaży, nie wcześniej.

**Co byłoby overengineeringiem:** SIEM i dodatkowa infrastruktura logów (wystarczą Loki, Prometheus i Sentry, które
już są, plus kilka alertów); WAF ponad Cloudflare; service mesh i mTLS wewnątrz jednowęzłowego klastra (wystarczy
NetworkPolicy i hasło Redisa); Vault albo HSM (wystarczą Sealed Secrets rozdzielone na usługi); 2FA dla klientów
sklepu; CAPTCHA na każdym formularzu (wystarczą limity na adres i na zasób); szyfrowanie kolumn w bazie;
cosign/SLSA przed startem sprzedaży; pełny DAST w każdym PR; osobny klaster Redis dla sesji.

## 2. Ogólna ocena bezpieczeństwa

| Obszar | Ocena | Uzasadnienie w jednym zdaniu |
|---|---|---|
| Uwierzytelnianie i sesje (kod) | dobra | BFF z `state`, `nonce`, PKCE, rotacją sesji i ochroną CSRF; walidacja JWT odporna na wszystkie próby podmiany algorytmu, klucza, realmu i typu tokenu. |
| Konfiguracja Keycloaka | słaba | Wersja z siedmiomiesięczną zaległością poprawek, brak polityki haseł, limitów prób, zdarzeń logowania; ustawienia z repo nie docierają do istniejącego realmu. |
| Autoryzacja | bardzo dobra | 74 trasy, 6 tożsamości, ok. 600 żądań: zero odczytów i zapisów cudzych zasobów; odmowa zawsze po stronie API. |
| Logika biznesowa | dobra | Ceny, rabaty, stany, przejścia statusów i idempotencja wytrzymują współbieżność; wyjątkiem jest blokowanie towaru nieopłaconymi zamówieniami. |
| Płatności | dobra obsługa webhooków, brak operatora | Podpis, deduplikacja i zgodność kwoty działają; nie ma prawdziwego operatora, rekoncyliacji ani zwrotów, a rozpoczęcie płatności potrafi zablokować API. |
| API (wstrzyknięcia, walidacja, ekspozycja) | dobra | Brak wstrzyknięć, SSRF, wycieków w błędach; braki w limitach: rozmiar żądania, koszyki gościa, zgadywanie kodów. |
| Frontendy | dobra | Brak XSS, open redirectów, sekretów w bundlach i przecieków między sesjami; dwa wektory wyczerpania zasobów i niepełne CSP. |
| Infrastruktura i wdrożenie | średnia | Dobre: brak portów na `0.0.0.0`, Sealed Secrets, wąski ingress Keycloaka. Słabe: wspólny Secret, brak NetworkPolicy, brak `securityContext`, kopie wyłączone. |
| Supply chain i CI/CD | dobra konstrukcja, brak kontroli podatności | Akcje przypięte do SHA, minimalny token, wdrożenie przez digest z zatwierdzeniem; żadnego skanu podatności, stąd zaległe Keycloak, Symfony, Next.js. |
| Prywatność i dane osobowe | średnia | Logi i Sentry nie zawierają danych osobowych ani tokenów; brak usuwania, anonimizacji, eksportu i retencji. |
| Monitoring i wykrywanie | słaba | Alerty tylko infrastrukturalne; brak logów dostępu, zdarzeń Keycloaka, sygnałów nadużyć; ślad audytowy cen i statusów jest, promocji i kodów nie. |

**Wniosek ogólny.** Jakość kodu aplikacji jest wyższa niż jakość jej otoczenia operacyjnego. Najdroższe do naprawy
klasy błędów (kontrola dostępu, integralność cen i płatności, wyścigi) są zaadresowane w projekcie i potwierdzone
testami. Otwarte ryzyka są w większości tanie do zamknięcia (aktualizacje, limity, konfiguracja klastra i Keycloaka),
a kilka wymaga decyzji produktowej (operator płatności, RODO). Do stanu „można powierzyć dane i pieniądze” brakuje
około czterech epików opisanych w punkcie 14.

## 3. Model zagrożeń aplikacji

### Zasoby

| Zasób | Gdzie | Dlaczego ważny |
|---|---|---|
| Dane osobowe klientów i gości (e-mail, imię i nazwisko, adres, telefon, historia zamówień) | PostgreSQL (`ordering_*`, `customer_*`), Keycloak, e-maile, kopie zapasowe | RODO, zaufanie klientów |
| Sesje i tokeny OIDC | Redis (sesje sklepu), przeglądarka personelu (panel) | przejęcie konta bez hasła |
| Integralność cen, promocji, kodów, stanów | PostgreSQL, Elasticsearch (projekcja) | bezpośrednia strata finansowa |
| Stan zamówień i płatności | PostgreSQL, kolejki RabbitMQ | wydanie towaru bez zapłaty, zatrzymane pieniądze klientów |
| Sekrety (bazy, Keycloak, Garage, RabbitMQ, podpisy imgproxy i webhooków) | Secret klastra, `.env.local` lokalnie | przejęcie całej platformy |
| Dostępność sprzedaży | API, sklep, Keycloak, pojedynczy węzeł k3s | przychód |
| Łańcuch wdrożenia | GitHub Actions, GHCR, gałęzie `deploy*`, Argo CD | wykonanie dowolnego kodu na produkcji |

### Aktorzy i ich możliwości

| Aktor | Dostęp początkowy | Najbardziej prawdopodobne cele |
|---|---|---|
| Anonimowy użytkownik internetu (bot, konkurent, wandal) | publiczne API, sklep, strony logowania Keycloaka | zatrzymanie sprzedaży, blokowanie towaru, zgadywanie kodów i haseł, skanowanie znanych CVE |
| Oszust-klient | konto klienta albo zamówienie gościa | towar bez zapłaty, nadużycie promocji, podwójny zwrot |
| Inny klient | konto klienta | dane i zamówienia cudzych kont |
| Pracownik o wąskiej roli albo przejęte konto personelu | panel, rola `support` / `warehouse` / `marketing` | dane klientów, zmiana cen i rabatów, zacieranie śladów |
| Napastnik z wykonaniem kodu w jednym kontenerze (luka w Next.js, imgproxy, Keycloaku, złośliwa zależność) | środowisko i sieć jednego poda | sekrety, baza, sesje, konto administratora tożsamości |
| Napastnik w łańcuchu dostaw | przejęta paczka npm/Composer, obraz bazowy | sekrety CI i aplikacji, kod na produkcji |
| Fałszywy operator płatności | adres webhooka | oznaczenie zamówienia jako opłacone |

### Granice zaufania

1. Internet → Cloudflare → Traefik → `store` (BFF), `api`, `admin` (statyczne SPA), `auth` (dwa realmy Keycloaka),
   `img` (imgproxy).
2. Przeglądarka klienta → BFF sklepu (ciasteczko sesji) → API (token użytkownika; BFF nie ma tokenu serwisowego).
3. Przeglądarka personelu → API panelu (token realmu `backoffice`, role w tokenie).
4. Operator płatności → API (webhook z podpisem HMAC).
5. API i worker → PostgreSQL, Redis, Elasticsearch, RabbitMQ, Garage, poczta.
6. GitHub Actions → GHCR → gałąź `deploy-images` → Argo CD → klaster.
7. Administrator → SSH, API Kubernetesa (6443), konsola Keycloaka (tylko z wnętrza klastra).

### Które granice trzymają, a które nie

- Granice 2, 3 i 4 **trzymają**: potwierdzone testami autoryzacji, JWT, CSRF i webhooków (punkt 6).
- Granica 6 **trzyma** konstrukcyjnie (digest, zatwierdzenie środowiska, rulesety), ale nie ma w niej kontroli
  podatności tego, co jest wdrażane (SA-16).
- Granica 5 **praktycznie nie istnieje**: każdy pod ma wszystkie sekrety i dostęp sieciowy do każdej usługi
  (SA-05, SA-06). To zamienia dowolne wykonanie kodu w pełne przejęcie.
- Granica 1 **nie ogranicza zużycia zasobów** na poziomie aplikacji (SA-03, SA-04, SA-12…SA-15); konfiguracja
  Cloudflare jest poza repozytorium i nie była sprawdzana.
- W granicy 1 stoi niezałatany komponent z własną powierzchnią publiczną: Keycloak (SA-01).

## 4. Mapa powierzchni ataku

Źródła: mapa endpointów z audytu autoryzacji (`bin/console debug:router` z działającego stosu porównane z `openapi.json`
i `openapi-admin.json`; 74 trasy, w tym 37 panelu), render chartu `prod` i przegląd frontendu. Firewalle API: `admin`
(`^/api/v1/admin(/|$)`, tylko tokeny realmu `backoffice`, `aud=backoffice-api`) i `shop` (`^/api/v1(/|$)`, tylko tokeny
realmu `shop`, `aud=shop-api`); `access_control` domyślnie odmawia (`security.yaml:39-62`); trasy poza `/api/v1` nie
mają firewalla.

### C.1 Grupy endpointów API

| Grupa | Liczba tras | Wymagany dostęp | Gdzie egzekwowany |
|---|---|---|---|
| Katalog publiczny: GET `products`, `products/{slug}`, `categories`, `categories/redirect`, `product-offers`, `sitemap/*`, `payment-providers` | 8 | anonim | `access_control` `PUBLIC_ACCESS` tylko GET/HEAD; tylko produkty opublikowane |
| Koszyk gościa: POST `guest-carts`; odczyt, summary, pozycje | 6 | anonim + id koszyka (UUID v4, 122 bity) | `PUBLIC_ACCESS`; `ReadableCarts.php:29`, `LockedCarts.php:37` (koszyk klienta jako koszyk gościa = 404) |
| Zamówienie gościa: POST `orders` | 1 | anonim + `cartId` w ciele | `PUBLIC_ACCESS`, limit zamówień na adres; `LockCartForOrderHandler` |
| Płatność gościa: POST `orders/{orderId}/payments`, GET `orders/{orderId}/payment` | 2 | anonim + nagłówek `Order-Access-Token` (32 bajty CSPRNG) | `Order::grantsAccess` (`Order.php:152`, `hash_equals`) |
| Webhooki: POST `payments/webhooks/{provider}` | 1 | podpis dostawcy | `PUBLIC_ACCESS`, adapter dostawcy |
| Klient: GET `me`; `me/cart*` (odczyt, summary, pozycje, `merge`); `me/orders*` i płatności | 12 | klient (`ROLE_CUSTOMER`) | reguła domyślna `access_control`; zasób wybierany po `sub`; `Order::grantsAccess`, brak dostępu = 404 |
| Panel, odczyt bez votera: `categories`, `stock`, historia stanów, `prices`, historia cen, `promotions*`, `discount-codes*` | 9 | `ROLE_STAFF` (każda rola) | `access_control` (`security.yaml:44`); por. SA-32 |
| Panel, `me` i `dashboard` | 2 | konto `backoffice` / `ROLE_STAFF` | `security.yaml:43`; `DashboardController` (`isGranted` per sekcja) |
| Panel, odczyt z voterem: `products*`, `tax-classes` (`VIEW_PRODUCTS`); `orders*` (`VIEW_ORDERS`: admin, support, warehouse); `customers*` (`VIEW_CUSTOMERS`: admin, support) | 7 | wg roli | `#[IsGranted]`, `ProductVoter`, `OrderVoter` |
| Panel, zapis: produkty i zdjęcia (5), kategorie (4), stany (2), cena (1), promocje (3), kody (2), zmiana statusu (1), zamknięcie zwrotu (1) | 19 | `MANAGE_*`, `CHANGE_PRICE` (tylko admin), `CHANGE_ORDER_STATUS`, `COMPLETE_REFUND` | `#[IsGranted]` i votery; `ChangeOrderStatusController.php:82` |
| Poza OpenAPI: GET `/health` | 1 | anonim | brak firewalla; odpowiada `{"status":"ok"}` |
| Poza OpenAPI: GET i POST `/payment-simulator/{provider}/pay/{transactionId}` | 2 | anonim + id transakcji (UUID v4); POST wymaga `_token` | brak firewalla; SA-02 |
| Poza OpenAPI: API dostawców symulatora (`demo-transfer`: transakcje, `cancel`; `demo-card`: `checkout-sessions`, `expire`) | 4 | klucz API (`Authorization: Bearer` / HTTP Basic) | `ApiAuthentication`; anonim 401 |

Brak tras debugowych: `/_profiler`, `/_wdt`, `/metrics`, `/api/doc`, `/api/doc.json`, `/.env`, `/index.php` → 404
(lokalnie obraz `dev` udostępnia panel SPX: SA-42).

### C.2 Route handlery BFF sklepu

Sklep ma 17 route handlerów (`app/api/**/route.ts`, `app/auth/**/route.ts`, `app/sitemap*/**/route.ts`), nie ma
`proxy.ts`/`middleware.ts` ani `"use server"`. BFF nie ma tokenu serwisowego: działa tokenem klienta z sesji (Redis)
albo anonimowo.

| Handler | Dostęp | Ochrona |
|---|---|---|
| `GET /auth/login` | anonim | limit 30 startów na adres na 10 min (`login-rate-limit.ts`); `returnTo` sanityzowane; `state`, `nonce`, PKCE S256 |
| `/auth/callback` | anonim + podpisane cookie `store_login` (`Path=/auth/callback`) | transakcja jednorazowa (`GETDEL`); nowy identyfikator sesji |
| `POST /auth/logout` | sesja | kontrola `Origin` i `Sec-Fetch-Site`; GET → 405 |
| `/auth/session` | sesja | zwraca tylko `name`, `email`; `private, no-store` |
| `GET /api/cart` (także `?discountCode=`) | anonim / sesja; koszyk z podpisanego cookie `store_cart` | bez limitu prób kodu (SA-14) |
| `POST /api/cart/items`, `PATCH`/`DELETE /api/cart/items/[variantId]` | anonim / sesja | `Origin` równy `STORE_URL` i `application/json` (`src/cart/bff.ts`); bez limitu ciała (SA-12) |
| `POST /api/checkout` | anonim / sesja | jw.; lista pól (`pickFields`) |
| `/api/checkout/payment` | cookie `store_order_confirmation` albo sesja | zapis: `Origin` (`payment-bff.ts:97`); token zamówienia gościa zostaje po stronie serwera |
| `/api/account/orders`, `/api/account/orders/{orderId}` | sesja klienta | `orderId` sprawdzany przez API względem `sub`; `private, no-store` |
| `/api/revalidate` | sekret `STORE_REVALIDATE_SECRET` | porównanie w stałym czasie; ciało czytane po autoryzacji |
| `/api/site` | publiczny | zwraca tylko przełączniki |
| `app/sitemap*/**` | publiczny | ucieczka XML |

Źródła nie wyliczają wszystkich 17 handlerów z metodami; tabela obejmuje handlery wymienione przez audytorów.

### C.3 Hosty publiczne w produkcji (chart `prod`)

Pięć hostów, każdy z Ingressem `websecure` i middleware `ipallowlist` (22 zakresy Cloudflare), HSTS (bez
`includeSubDomains`: SA-27), tryb Full (strict) z certyfikatem Origin CA; wszystkie Service to `ClusterIP`.

| Host | Usługa | Ścieżki | Uwierzytelnienie |
|---|---|---|---|
| `<domena>` | `store` (Next.js, BFF) | — (źródła nie podają ścieżek) | publiczny; sesja klienta w cookie (BFF, OIDC z realmem `shop`) |
| `admin.<domena>` | `admin` (nginx, SPA) | — (źródła nie podają ścieżek; statyczne pliki SPA i `/config.js`) | Cloudflare Access (poza repo); SPA bez sekretów, tokeny w pamięci |
| `api.<domena>` | `api` (FrankenPHP, bez Varnisha) | `/` → całe API: `/api/v1/*`, `/api/v1/admin/*`, `/health`, `/payment-simulator/*` | wg firewalli z C.1; API panelu i symulator publiczne (SA-35, SA-02) |
| `auth.<domena>` | `keycloak` | tylko `/realms/shop/`, `/realms/backoffice/`, `/resources/` | publiczne formularze i endpointy OIDC obu realmów; `master`, konsola i port zarządzania 9000 poza Ingressem |
| `img.<domena>` | `imgproxy` | — (źródła nie podają ścieżek; podpisane adresy obrazów) | podpis URL (`IMGPROXY_KEY`/`IMGPROXY_SALT`); źródła tylko `s3://product-images*` |

### C.4 Usługi wewnętrzne i ich uwierzytelnienie

- **PostgreSQL:** `scram-sha-256` dla połączeń sieciowych (`trust` tylko dla pętli zwrotnej kontenera); osobne role
  `shop` i `keycloak` bez superusera; rola `shop` nie czyta bazy `keycloak`. Hasło superusera jest w środowisku każdego
  poda (SA-05).
- **Redis:** bez hasła i ACL, `protected-mode no`; sesje sklepu z tokenami, transakcje logowania i liczniki limitów w
  jednej instancji (SA-06).
- **Elasticsearch:** `xpack.security.enabled: "false"`; jeden indeks produktów (SA-06).
- **RabbitMQ:** jedno konto `shop` (administrator) z losowym hasłem, `guest` wyłączony; wtyczka management (:15672)
  działa także w `prod`.
- **Garage:** API administracyjne (:3903) wymaga tokenu (bez tokenu 403); bucket prywatny, dostęp tylko kluczem S3;
  klucz współdzielony przez API i imgproxy z prawami `--read --write --owner`.
- **imgproxy:** wymaga podpisu adresu; dozwolone źródła `s3://product-images/` i (także w `prod`)
  `s3://product-images-test/`; limity 64 MB / 50 MP.
- **Varnish:** bez Ingressu; stoi tylko przed publicznymi odczytami katalogu wołanymi przez sklep (`API_CATALOG_URL`),
  resztę przepuszcza (`pass`); PURGE z ACL i tokenem.
- **Mailpit:** UI i API bez uwierzytelnienia; w profilu `prod` przyjmuje pocztę zamówień (`orderMail: mailpit`); bez
  Ingressu; logi nie są zbierane (SA-36).
- **Keycloak (administracja):** stały administrator realmu `master` z hasłem bootstrap; konsola i `master` dostępne
  tylko z sieci klastra (`keycloak:8080`, ruch po HTTP); klient `admin-cli` z direct grant w każdym realmie (SA-09).
- **Sieć:** brak `NetworkPolicy`; każdy pod łączy się z każdą usługą, także z przestrzeni `monitoring`, `argocd`,
  `kube-system` (SA-06).

### C.5 Płaszczyzna zarządzania

- **SSH:** port SSH otwarty w `ufw`; bootstrap nie konfiguruje `sshd` (SA-34); skrypty weryfikują klucz hosta
  (`BatchMode`, known_hosts).
- **API Kubernetesa (6443):** otwarte dla każdego adresu (`ufw allow 6443/tcp`); kubeconfig to certyfikat
  `system:masters` (SA-34).
- **Porty 80/443:** otwarte na poziomie TCP/TLS dla całego internetu (DNAT omija `ufw`); filtr dopiero w Traefiku
  (SA-35).
- **Argo CD:** bez publicznego adresu (port-forward po SSH); ciągnie gałąź `deploy-images` po digeście; charty platformy
  po numerze wersji (SA-38).
- **GitHub Actions / wdrożenie:** `deploy` tylko ręcznie (`workflow_dispatch` na `main`) z zatwierdzeniem w środowisku
  `production`; gałęzie `deploy`/`deploy-images` przesuwa wyłącznie job `deploy` (aktor bypass: jeden deploy key);
  sekrety `DEPLOY_KEY` i `SENTRY_AUTH_TOKEN` tylko w środowiskach z polityką gałęzi `main`; repozytorium jest publiczne.
- **Sealed Secrets:** jeden nierotowany klucz kontrolera odszyfrowuje wszystkie sekrety z repo (SA-37).
- **Cloudflare, Grafana Cloud, Sentry, R2, Brevo:** konfiguracja poza repo, nieoceniona.

## 5. Najważniejsze potwierdzone podatności

Pełne opisy z lokalizacją, dowodem i rekomendacją są w punkcie 7. Tu: osiem pozycji, które decydują o ocenie,
z wynikiem własnej weryfikacji koordynatora audytu i niezależnego reviewera.

### SA-01 — Keycloak 26.6.0 z niezałatanymi lukami (Critical przy sprzedaży, Medium w demo)

Obraz `quay.io/keycloak/keycloak:26.6.0` (kwiecień 2026) jest przypięty w `infra/docker-compose.yml:104`
i `infra/k8s/charts/shop/values.yaml:38`. Noty wydań 26.6.1–26.8.2 wymieniają około 165 pozycji CVE.
Najpoważniejsza, CVE-2026-18963 (ocena dostawcy: Critical), pozwala nieuwierzytelnionemu napastnikowi przejść
przepływ resetu hasła bez linku z e-maila. Zakres podatny według advisory (`>= 26.5.0, < 26.6.6`), bazy skanera i not
wydania 26.7.2 obejmuje 26.6.0. Przepływ resetu jest w realmie `shop` włączony i publicznie osiągalny, także
w konfiguracji produkcyjnej (job `keycloak-config` nie zmienia `resetPasswordAllowed`).

**Uczciwy status:** obecność podatnej wersji i osiągalność przepływu są `CONFIRMED`. Samego przejęcia konta **nie
odtworzono**: ani w trzech próbach koordynatora, ani w kilkunastu próbach reviewera (z krokiem opisanym
w zgłoszeniu upstream). Wykorzystanie ma więc status `LIKELY`. Brak reprodukcji nie jest dowodem odporności.
W realmie `backoffice` reset hasła jest wyłączony, więc ta luka nie dotyczy kont personelu; dotyczą ich inne
z tego samego zestawu (m.in. CVE-2026-7507, fiksacja sesji w przepływie logowania). W obecnym demo
(`customerAccounts: false`) kont klientów do przejęcia praktycznie nie ma, stąd Medium.

**Naprawa:** aktualizacja obrazu do wersji z poprawką (według advisory: 26.6.6 albo 26.7.2 i nowsze; najprościej
bieżąca linia 26.8.x, która zamyka też późniejsze pozycje), tag i digest w compose i chartcie, przejście E2E
logowania i rejestracji. Źródła różnią się co do tego, czy 26.6.6 jest dostępne jako obraz upstream: sprawdzić
przed wyborem wersji.

### SA-02 — Symulator płatności jako jedyny operator, bez wyłącznika (Critical przy sprzedaży)

To nie błąd implementacji, tylko brak funkcji (prawdziwego operatora) połączony z brakiem wyłącznika. Trasy
`/payment-simulator/*` są ładowane w każdym środowisku (`apps/api/config/routes.yaml:1-3`), a chart `prod` wystawia
je pod `api.<domena>`. Gość składa zamówienie, otwiera stronę symulatora, klika „Zapłać” i zamówienie przechodzi
w `paid` poprawnie podpisanym webhookiem. Odtworzone ponownie przez koordynatora (zamówienie `R-100114`, płatność
`succeeded`, bez użycia żadnego sekretu). Cudzego zamówienia opłacić się nie da.

W demo to zachowanie zamierzone. Ryzyko jest w tym, że nic w kodzie ani w `make -C infra/k8s check` nie zatrzyma
włączenia sprzedaży z aktywnym symulatorem.

**Naprawa:** wyłącznik (trasy, adaptery i transport `payment_simulator` rejestrowane warunkowo; test chartu; test
„404 przy wyłączonym symulatorze”) to nakład S. Prawdziwy operator to osobny epik.

### SA-03 — Samoblokada API przez równoległe rozpoczęcia płatności (High)

`StartPaymentHandler` w jednej transakcji bazy zapisuje oczekującą płatność i woła operatora przez HTTP. Operatorem
jest to samo API, więc każde żądanie potrzebuje drugiego wątku FrankenPHP, a równoległe żądania tego samego
zamówienia czekają na częściowym indeksie unikalnym zamiast dostać od razu 409. Gdy żądań jest co najmniej tyle,
ile wątków, wywołania symulatora kończą się po 5 s jedno po drugim. W teście 40 żądań jednego gościa zatrzymało
całe API (łącznie z `/health`, koszykiem i webhookami) na około 36 s i zostawiło 7 osieroconych sesji u operatora.

Limit produkcyjny (20 rozpoczęć na 15 min na adres, bez blokady licznika) ogranicza jedną serię z jednego adresu,
ale nie usuwa mechanizmu; kilka adresów sumuje się liniowo. Przy prawdziwym, zewnętrznym operatorze samoblokada
znika, zostaje wzmocnienie awarii: N żądań blokuje N wątków na czas odpowiedzi operatora.

**Naprawa:** szybka (S): odrzucać równoległe rozpoczęcia bez czekania (blokada z `NOWAIT` albo
`pg_try_advisory_xact_lock` → 409). Docelowa (M): wywołanie operatora poza transakcją bazy.

### SA-04 — Blokowanie towaru nieopłaconymi zamówieniami gościa (High przy sprzedaży)

Złożenie zamówienia rezerwuje towar i użycie limitowanego kodu przed płatnością, na 35 min. Jedno zamówienie
gościa może mieć 50 linii po 99 sztuk, a jedynym hamulcem jest 10 zamówień na 15 min na adres. Z wyliczenia: jeden
adres utrzymuje cały katalog demo jako niedostępny. Mechanizm i czas trzymania są zmierzone, skala wyliczona.
Zgłoszenie #332 jest otwarte od poprzedniego przeglądu (10.11) i było tam blokerem publicznego demo.

**Naprawa:** limit jednocześnie nieopłaconych zamówień na adres i limit sztuk albo wartości zamówienia gościa;
decyzja produktowa, czy rezerwować przed płatnością tak długo.

### SA-05 i SA-06 — Brak izolacji sekretów i sieci w klastrze (High)

Chart wstrzykuje cały Secret (19 kluczy) przez `envFrom` do wszystkich podów, także `store`, `imgproxy` i `varnish`
(`infra/k8s/charts/shop/templates/_helpers.tpl:49-53`). Nie ma żadnej NetworkPolicy, Redis z sesjami sklepu
(access, refresh i ID tokeny) nie ma hasła, Elasticsearch i Mailpit nie mają uwierzytelnienia. Dowód z kontenera
`store`: samym środowiskiem poda uzyskano token administratora realmu `master` Keycloaka oraz dostęp administratora
do RabbitMQ i Garage; z kontenera `varnish` odczytano sesje z Redis.

To nie jest samodzielna ścieżka ataku, tylko mnożnik: dowolne wykonanie kodu w jednym kontenerze (a Next.js
i imgproxy przetwarzają dane z internetu) oznacza przejęcie bazy, tożsamości i sesji wszystkich klientów. Zgłoszenia
#409, #410 i #485 są otwarte.

**Naprawa:** `secretKeyRef` tylko dla potrzebnych kluczy na usługę; domyślna odmowa NetworkPolicy z regułami według
zależności; `requirepass` w Redis. Natywne mechanizmy Kubernetesa, bez nowych usług.

### SA-07 — Kopie zapasowe wyłączone w profilu `prod` (Medium przy sprzedaży)

`infra/k8s/environments/prod/values.yaml:74-76` ma `backup.enabled: false` i adres repozytorium będący
wypełniaczem; render `prod` nie zawiera CronJobów kopii ani alertów o ich braku. Zgłoszenie #690 jest otwarte.

### SA-12…SA-15 — Tanie wyczerpanie zasobów (Medium)

Cztery niezależne wektory bez uwierzytelnienia: ciało żądania bez limitu rozmiaru i czasu w API i BFF (JSON 110 MB
przyjęty; 40 wolnych połączeń zablokowało API), zapis cache ISR na dysk dla dowolnego adresu `/p/…` (ok. 88 KB na
adres, bez usuwania), nieograniczone tworzenie koszyków gościa (wiersze żyją 30 dni) oraz podgląd kodu rabatowego
jako wyrocznia bez limitu prób (300 prób w 6 s znalazło oba kody z seeda; odtworzone przez koordynatora).
Cloudflare łagodzi część z nich, ale jego konfiguracja jest poza repozytorium.

### SA-21 — Brak usuwania, anonimizacji, eksportu i retencji danych (Medium)

Po usunięciu konta w Keycloaku zamówienia i rekord klienta zostają w bazie; nie ma eksportu danych, retencji
zamówień gości ani polityki prywatności w sklepie. To ocena techniczna, nie prawna; zgłoszenie #119 jest otwarte.

## 6. Wyniki testów dynamicznych

Środowisko: wszystkie testy dynamiczne wykonano wyłącznie na lokalnym stosie Compose `shop-secaudit` (`localhost:2410x`;
sklep `next start`, API w `APP_ENV=dev`, panel `vite dev`, Keycloak `start-dev`), więc limity żądań miały wartości
deweloperskie (10 000), nagłówki panelu z nginx, zachowanie za Cloudflare i Traefikiem, limity produkcyjne i klaster
oceniono z konfiguracji (chart, render `prod`, jądro `prod` uruchomione w kontenerze), a Sentry i Grafana Cloud nie były
podłączone.

### B.1 Potwierdzone testem

| ID | Test | Wynik | Skrypt |
|---|---|---|---|
| SA-01 | Skan obrazu Keycloaka, wersja w kontenerze, osiągalność flow resetu; trzy próby odtworzenia obejścia resetu przez koordynatora | `CRIT … 26.6.0 -> 26.4.15, 26.6.6, 26.7.2 CVE-2026-18963`; `kc.sh --version` → 26.6.0; `reset-credentials` → 200; obejścia nie odtworzono (nie doszło do formularza nowego hasła) | `work/sc/trivy/image-quay.io_keycloak_keycloak_26.6.0.json` |
| SA-02 | Gość składa zamówienie, rozpoczyna płatność i wysyła „Zapłać” na stronie symulatora, bez żadnego sekretu | `decide (303, …)`; zamówienie R-100001 `paid`, płatność `succeeded` (690 gr) | `work/pay/t01_simulator_selfpay.py` |
| SA-03 | 40 równoległych rozpoczęć płatności jednego zamówienia (T = 32) | 32 żądania o 09:25:20, potem siedem rund po 5 s; `/health` > 30 s; API nie odpowiadało ok. 36 s; 8 transakcji `open` (7 osieroconych) | `work/pay/t11_thread_starvation.py`, `work/pay/t11_evidence.txt` |
| SA-04 | Jedno nieopłacone zamówienie gościa na cały stan wariantu (28 szt.); kod z limitem 3 zajęty trzema zamówieniami; oczekiwanie na wygaśnięcie | stan `28 / 28 / 0`, inny kupujący 409; kod `used_up`; zwolnienie po 35 min 1 s; `1013 \| 1006` wariantów ze stanem 1–99 | `work/biz/t1_oversell.py`, `t2_code_limit.py`, `t11_expiry_wait.py` |
| SA-05 | Odczyt nazw zmiennych w kontenerach `store`, `imgproxy`, `varnish`; ruch boczny wyłącznie środowiskiem kontenera `store`; render `prod` | 19 sekretów w `store` (używa 3); `imgproxy`: 13 zmiennych wg wzorca; RabbitMQ 200 (administrator), Garage ListBuckets 200, token administratora `master` wydany, lista użytkowników `backoffice` 200; 20 kontenerów z całym sekretem | `work/infra/poc-lateral-from-store.mjs`, `work/infra/render-facts.py`, `work/infra/secret-env-names.txt` |
| SA-06 | Z kontenerów `store` i `varnish`: Redis, Elasticsearch, Mailpit bez poświadczeń; render `prod` | Redis `+PONG`, `requirepass` puste, `protected-mode no`, 5 kluczy sesji z polami tokenów; ES `/_cat/indices` 200; Mailpit 200 `total=14`; `NetworkPolicy objects: 0` | `work/infra/poc-lateral-from-store.mjs`, `work/infra/poc-redis-from-varnish.sh` |
| SA-07 | Render `prod` z wartościami z repo i z `backup.enabled=true` | bez zmiany: `CronJobs = ['cart-purge-expired']`, 3 alerty; kopie i 4 alerty dopiero po włączeniu | `work/infra/render/prod.ndjson`, `prod-backup.ndjson` |
| SA-08 | Rejestracja z hasłem jednoznakowym (`a`; osobno `1`), weryfikacja e-mail, logowanie | rejestracja 302 → `VERIFY_EMAIL`; logowanie udane; `'passwordPolicy': None` w obu realmach | `work/auth/t05_register.py`, `work/priv/02_customer.py` |
| SA-09 | ROPC przez `admin-cli`; 40 błędnych prób w 0,8 s; 33 próby co 1,1 s; 8 żądań resetu w 0,2 s; formularz Keycloaka bez BFF | ROPC 200 z tokenami (API: 401); żadnego 429; blokada dopiero po 30 próbach; 8 × 200 i 8 maili; formularz 200 | `work/auth/t07_bruteforce.py`, `t08_bruteforce_spaced.py`, `t10_reset_flood.py`, `t11_cookies_redirects.py` |
| SA-11 | Odczyt konfiguracji zdarzeń i tabel zdarzeń po testach wszystkich audytorów | `eventsEnabled: False`, `adminEventsEnabled: False`; `event_entity` i `admin_event_entity`: 0 i 0 | — (zapytania `kcadm` / SQL, `work/priv`) |
| SA-12 | JSON 75/110/150 MB i multipart 62 MB na publiczne endpointy API; 40 wolnych ciał przez 7 s; 30 MB na handlery BFF | 110 MB sparsowane (404), 150 MB → błąd pamięci, multipart 201; `/health` timeout przy 40 połączeniach (12 nie blokuje); BFF 422/404 po przyjęciu 31 457 319 bajtów | `work/api/bodysize_poc.sh`, `work/api/slow_body_poc.py`, `work/fe/body_limit.sh` |
| SA-13 | 50 unikalnych nieistniejących slugów `/p/…` w 2 s; drugie wejście | `delta=4400KB`, 88 KB na slug; 404 z `x-nextjs-cache: HIT` | `work/fe/isr_growth.sh` |
| SA-14 | 300 prób kodów przez API (słownik 80 haseł), 120 przez BFF, 300 kolejnych `KOD1..KOD300` | 298 `invalid`, 2 `applied`; znalezione `MINUS50`, `WITAJ10`; zero 429 w każdej serii | `work/biz/t9_code_guessing.py`, `work/biz/t14_bff.py`, `work/api/ratelimit_poc.sh` |
| SA-15 | 300 × `POST /api/v1/guest-carts` | 300 × 201 w 12 s; koszyki 25 → 371; TTL 30 dni | `work/api/ratelimit_poc.sh` |
| SA-17 | `composer audit --locked`; próby obejść z advisories (HEAD, nazwa skryptu, base URL) | 19 advisories z naprawą 8.1.11; `MapRequestPayload` w 25 plikach; obejścia: 401 / 401 / 404 | `work/sc/composer-audit*.json` |
| SA-18 | Żądania bez tokenu i z błędnym tokenem Bearer w jądrze z atrapą DSN i licznikiem zdarzeń | bez tokenu: brak zdarzenia; błędny token: `sentry_event=YES` na `/api/v1/me` i `/api/v1/admin/orders`; 86 wpisów `ERROR app` w logach stosu | `work/priv/04_sentry_401.php` |
| SA-19 | Przegląd logów 13 usług po pełnym przebiegu; symulacja maskowania Alloy | 0 wpisów dla 429 i 401 bez tokenu; wyjątki domeny jako `CRITICAL`; `user id of PostAuthenticationToken: 281→0` | `work/priv/06_alloy_mask_sim.py`, `work/priv/logs/` |
| SA-20 | Promocja 90% → 10% → usunięcie i kod 90% → 5% kontem `marketing`, potem ślad w bazie | wiersz promocji usunięty (0), ślad tylko w 4 komunikatach bez aktora; kod bez śladu wartości 90 | `work/priv/05_promo_trace.py` |
| SA-21 | Usunięcie własnego konta w Keycloaku i odczyt bazy `shop` | 204; `ordering_customer`, zamówienie `R-100003` i koszyk pozostają | — (opis w `priv.md`, `work/priv/02_customer.py`) |
| SA-22 | Druga płatność po opłaceniu zamówienia inną próbą; webhook z `paidAt` po terminie | druga płatność `succeeded \| duplicate`; `R-100019 \| new \| … \| succeeded \| paid_after_deadline`; brak odbiorcy oznaczenia | `work/pay/t05_event_order.py`, `work/pay/t10_expiry.py` |
| SA-23 | Wygaśnięcie zamówienia z oczekującą płatnością | zamówienie `cancelled`, płatność zostaje `pending` | `work/pay/t10_expiry.py` |
| SA-24 | Dwie błędne próby w ~100 ms na własnym koncie | `numFailures: 2, disabled: true`, poprawne hasło odrzucone przez 60 s | `work/auth/t06_enum.py` |
| SA-25 | Rejestracja z adresem istniejącego konta | 200 „E-mail już istnieje.” | `work/auth/t06_enum.py` |
| SA-26 | Access token po wylogowaniu w Keycloaku; dwukrotne użycie tego samego refresh tokenu; `kcadm logout` przy żywej sesji BFF | API 200 (exp za 300 s); drugi refresh 200; sesja BFF żyje ok. 270 s | `work/auth/t03_bff.py`, `t04_refresh.py`, `t09_misc.py` |
| SA-27 | Dwa cookies `store_session` w jednym żądaniu, w obu kolejnościach | wygrywa ostatnie w nagłówku | `work/auth/t11_cookies_redirects.py` |
| SA-28 | Dowolne ścieżki panelu jako `redirect_uri` i adres po wylogowaniu klienta `admin` | formularz logowania (200); 302 po wylogowaniu; klient `store`: 400 | `work/auth/t11_cookies_redirects.py` |
| SA-29 | Nagłówki odpowiedzi sklepu | CSP tylko `frame-ancestors`, `object-src`, `base-uri`; `X-Powered-By: Next.js` | — (`curl -sI`, `work/fe`) |
| SA-30 | 300 wyszukiwań z unikalną frazą; 300 porcji mapy strony; porcja z pełnym zakresem | 300 × 200 (9 s i 8 s); `items: 191 (cały katalog)` | `work/api/ratelimit_poc.sh` |
| SA-31 | Zamówienie gościa z treścią phishingową w `fullName` i adresie, odczyt maila w Mailpit (dwa niezależne testy) | potwierdzenie z nadawcą sklepu i tekstem atakującego w pierwszej linii; 17 wiadomości o anulowaniu z tym samym powitaniem | `work/biz/t12_mail.py`, `work/api/order.json` |
| SA-32 | Odczyty panelu rolą `warehouse`; macierz 37 tras × 6 tożsamości | kody rabatowe 200 (7 kodów), zamówienia `status=new` 200 (39 zamówień z danymi klientów), klienci 403 | `work/authz/matrix_admin.py`, `work/authz/reads_by_role.txt` |
| SA-33 | Użytkownik i `CapEff` procesu PID 1 w kontenerach; render `prod` | `api`, `worker`, `mailpit`: uid 0 z capabilities; 53 kontenery, 0 pól utwardzenia | `work/infra/container-users.txt`, `work/infra/render-facts.txt` |
| SA-36 | Render `prod`; odczyt Mailpita z sieci stosu | `MAILER_DSN=smtp://mailpit:1025?timeout=10`, `Deployment/mailpit` obecny; API Mailpita 200 bez poświadczeń | `work/infra/render/prod.yaml` |
| SA-39 | Reguły maskowania Alloy na rzeczywistych logach API | `guest cart id: 451→451`, `discountCode: 396→396`, `e-mail: 2→0`, `JWT: 85→0` | `work/priv/06_alloy_mask_sim.py` |
| SA-40 | Token formularza a sekret webhooka; webhook z numerem innego zamówienia; `paidAt` z 2001 r. | token równy HMAC-SHA256 id transakcji; A opłaca tylko A; zamówienie opłacone | `work/pay` (T04, T05, T07) |
| SA-42 | Wyszukiwanie binarne stanu przez koszyk; panel SPX i Mailpit z hosta; sesja w Redis | stan 36 w 7 żądaniach; SPX 200 ze stałym kluczem, porty tylko na `127.0.0.1`; sesja z jawnymi tokenami, TTL 1785 s | `work/biz/t17_stock_probe.py`, `work/infra/host-checks.txt` |

Bez testu dynamicznego (analiza kodu, konfiguracji albo ustawień repozytorium): SA-10, SA-16, SA-34, SA-35, SA-37, SA-38
(skany Trivy i `npm audit`, część G), SA-41.

### B.2 Hipotezy obalone

**Uwierzytelnianie i sesja** (`work/auth/t02_jwt.py`, `t03_bff.py`, `t04_refresh.py`, `t09_misc.py`,
`t11_cookies_redirects.py`; częściowo powtórzone w `work/authz/tokens_test.py` i `work/fe`)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| API przyjmie token `alg=none`/`None`, bez podpisu, ze zmienionym payloadem | 4 warianty na `/api/v1/me` i `/api/v1/admin/me` (macierz 25 wariantów JWT); osobno role przepisane na `admin`, `sub` innego klienta, inny schemat `Authorization` | 401 |
| Token podpisany własnym kluczem (ten sam `kid`, nagłówek `jwk`, `jku`/`x5u`) | RS256 kluczem audytora | 401; handler nie pobiera kluczy z nagłówka |
| HS256 z kluczem publicznym realmu jako sekretem (PEM i moduł) | 2 warianty | 401 (`algorithms: ['RS256']`) |
| Token przeterminowany jest akceptowany | tokeny `shop` i `backoffice` 90 s po `exp` | 401 (`allowedTimeDrift: 0`) |
| ID token albo refresh token zamiast access tokenu | ID token (`aud=store`), refresh (HS512), ID token `backoffice` | 401 |
| Token `shop` na `/api/v1/admin/*` i token `backoffice` na endpointach klienta | 4 żądania (potwierdzone też w macierzach autoryzacji i w `work/priv/03_staff.py`) | 401 w obie strony |
| Token z innego klienta realmu (`admin-cli`, ROPC) działa w API | token ROPC własnego konta na `/api/v1/me` | 401 (brak `aud: shop-api`) |
| Token w query `?access_token=` albo w body formularza | 2 żądania | 401 |
| Obejście firewalla ścieżką (`//`, `/./`, `%2f`, wielkość liter, `/index.php/…`, `%0a`, `..`) | 18 ścieżek bez tokenu | 401 albo 404, nigdy dane |
| API ufa nagłówkowi tożsamości z BFF albo proxy | przegląd wszystkich `headers->get()` w `apps/api/src` | brak takich nagłówków |
| CSRF na zapisach BFF (`POST/PATCH/DELETE /api/cart/items`, `POST /api/checkout`, `/api/checkout/payment`) | obcy `Origin`, origin panelu, `Origin: null`, brak `Origin`, `text/plain`, `X-Forwarded-Host`, method override; osobno 13 prób w `work/fe/csrf_matrix.sh` | 403 w każdym wariancie; brak nagłówków CORS |
| CSRF na wylogowaniu | obcy `Origin`, `Sec-Fetch-Site: same-site` / `cross-site`, `GET` | 403 / 405; sesja nienaruszona |
| Session fixation | cookie sesji „atakującego” w żądaniu callbacku | nowy identyfikator, stara sesja usunięta |
| Cookie sesji z podrobionym podpisem albo bez podpisu | 2 warianty | `null` + `Set-Cookie … Max-Age=0` |
| Cookie sesji działa po wylogowaniu | to samo cookie po `POST /auth/logout` | `null`, klucz w Redis usunięty |
| Refresh token działa po wylogowaniu; odświeżenie bez sekretu klienta | endpoint tokenów Keycloaka | `invalid_grant "Session not active"`; `401 unauthorized_client` |
| Callback z błędnym `state`, bez cookie logowania, powtórzony, po zużyciu transakcji | 4 warianty | 303 na `/` bez sesji; transakcja jednorazowa (`GETDEL`) |
| Logowanie bez PKCE bezpośrednio w Keycloaku | żądanie autoryzacji bez `code_challenge` | `invalid_request: Missing parameter: code_challenge_method` |
| Open redirect przez `returnTo` | 14 wartości przez BFF i 27 wariantów na `sanitizeReturnTo` uruchomionym w kontenerze sklepu (`//`, `\`, `/\/`, tabulacja, `%2F%2F`, `/..//`, schematy, U+2028) | zawsze ścieżka w originie sklepu; przekierowanie budowane od `STORE_URL`; walidacja także przy callbacku |
| `redirect_uri` / `post_logout_redirect_uri` poza originem (sufiks hosta, `user@host`, `..`) | oba klienty | 400; klient `store`: dopasowanie dokładne |
| ROPC w `backoffice` omija TOTP | `admin-cli`, samo hasło konta `marketing` | `invalid_grant`, `numSecondaryAuthFailures` rośnie |
| Enumeracja kont przez logowanie albo reset hasła | porównanie treści i czasu | identyczne komunikaty; czasy 25/29 ms i 19/11 ms (szum) |
| Detektor brute force nie działa | 33 próby co 1,1 s na własnym koncie | `numFailures: 30`, blokada, poprawne hasło odrzucane |
| Tokeny klienta trafiają do przeglądarki albo do Sentry | przegląd importów, odpowiedzi `/auth/session`, filtr Sentry | brak; `/auth/session` zwraca tylko `name`, `email` |
| Odpowiedzi osobiste są cache'owane albo czytelne między originami | nagłówki `/auth/*`, `/api/account/orders` | `Cache-Control: private, no-store`, brak nagłówków CORS |
| Checkout gościa z e-mailem istniejącego konta zdradza konto | przegląd kodu (API nie odpytuje Keycloaka o e-mail) | brak różnicy; nie testowano dynamicznie |

**Autoryzacja** (`work/authz/matrix_admin.py`, `matrix_shop.py`, `transitions.py`, `variants.py`, `massassign.py`,
`tokens_test.py`, `bff.py`; ok. 600 żądań, stan bazy przed i po macierzach identyczny)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| Klient B czyta albo opłaca zamówienie klienta A, znając jego UUID | macierz 36 scenariuszy IDOR/BOLA, wiersze 2–3, 9–11; osobno `work/pay` T06 | 404, bez zmian w bazie |
| Token gościa otwiera zamówienie konta, token konta otwiera zamówienie gościa | wiersze 4, 11–15, 25 | 404 w każdej kombinacji |
| Gość czyta zamówienie innego gościa (token innego zamówienia, obcięty, wydłużony, inna wielkość liter, w query, pusty, brak) | wiersze 17–24 | 404; token 256 bitów, `hash_equals` |
| Koszyk klienta dostępny przez `/guest-carts/{id}` (odczyt, zmiana, zamówienie gościa z cudzego koszyka) | wiersze 27–32, 34; `work/biz` T10 (c) | 404, koszyk A bez zmian |
| Przejęcie koszyka innego klienta przez `merge` z jego id; scalenie tego samego koszyka gościa przez dwóch klientów | wiersz 33, `massassign.py` pkt 8, `work/authz/merge_cleanup.txt` | `nothing_to_merge`; pierwszy `merged`, drugi `already_merged` |
| Anonim albo klient sklepu wywołuje trasy panelu | macierz 37 tras × 6 tożsamości | 401 na wszystkich 37 trasach, odczyt i zapis |
| Personel o niższej roli zmienia ceny, stany, promocje, kody, produkty, kategorie, zamówienia | macierz i przejścia statusów; osobno 22 endpointy × role `support`, `warehouse`, `marketing` (`work/fe`), `work/biz` T7, T16, T18 | 403, baza bez zmian |
| Obejście `access_control` wariantem ścieżki (końcowy `/`, wielkie litery, `//`, `/./`, `/../`, `%2F`, `%2e%2e`, `%61dmin`, `%0a`, `%00`, `;x=1`, `.json`, `/index.php/…`, absolutny URI, `\`) | 126 żądań (`variants.py`) i 30 (`misc.txt`) | wyłącznie 401/403/404/405 albo 301 na tę samą chronioną trasę |
| Nadpisanie metody (`X-HTTP-Method-Override`, `_method`), nieudokumentowane metody | `variants.py` | 405 albo kod właściwy dla roli; `http_method_override: false` |
| Nagłówki `X-Original-URL`, `X-Rewrite-URL`, `X-Forwarded-Prefix`, `X-Forwarded-Uri` | `variants.py` | ignorowane |
| Mass assignment przy zamówieniu gościa (`customerId`, `ownerType`, `status`, `paidAt`, `number`, `orderId`, `totals`, `lines`, `discount`, `accessToken`, `paymentDeadline`, `currency`, `country`) | `massassign.py` pkt 4 | 201, w bazie `new\|guest\|-\|3490\|PLN\|PL`; token i id nadane przez serwer |
| Zaniżona kwota (`expectedTotalGross: 1`) | pkt 3 | 409 `order-total-changed` |
| Zamówienie klienta z `email`, `customerId`, `cartId` klienta A w ciele | pkt 5; `work/biz` T10 (b) | zamówienie należy do B, e-mail z tokenu B |
| Cena albo właściciel w ciele pozycji koszyka; rozbieżność id ścieżka/ciało | pkt 1, 2, 7 | pola zignorowane, cena z Pricing |
| Start płatności z `amount`, `orderId`, `returnUrl`, `successUrl` w ciele | pkt 6; `work/pay` T06 | płatność własnego zamówienia; kwota i adres powrotu z serwera |
| Powtórka `Idempotency-Key` ujawnia cudze zamówienie albo płatność | analiza `PlaceOrderHandler.php:99-110`, `StartPaymentHandler`; `work/biz` T3, T10; `work/pay` T06 | 409 `idempotency-key-reused` bez danych; dostęp sprawdzany przed kluczem |
| BFF pozwala wskazać cudzy zasób | `bff.py`: zamówienie A i zamówienie gościa przez sesję B; podrobione albo przepisane cookies `store_session`, `store_cart`, `store_order_confirmation` | 404; 401 albo pusty koszyk; zapis bez `Origin` sklepu: 403 |
| BFF używa tokenu serwisowego do danych klienta | przegląd `src/auth/deps.ts`, `src/api/client.ts`, wszystkich `route.ts` | nie ma takiego tokenu |
| Token dostępu gościa wycieka do panelu albo e-maili | odpowiedzi `GET /admin/orders*`, treść maili w Mailpicie | brak |
| Produkt w wersji roboczej widoczny publicznie | przegląd zapytań (`published` w handlerach i sitemap) | filtrowane; bez testu dynamicznego |

**Logika biznesowa** (`work/biz`, 20 skryptów `t*.py`, wyniki `out_t*.txt`)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| Oversell ostatnich sztuk przy równoległych zamówieniach | T1: 20 koszyków × 28 szt. (stan 28) i 20 × 5 szt. równolegle | 1 × 201 i 19 × 409; druga seria 5 × 201 i 15 × 409; `reserved` nigdy ponad `physical` |
| Przekroczenie limitu użyć kodu współbieżnie | T2: 15 równoległych zamówień z kodem `usageLimit=3` | 3 × 201, 12 × 409 `discount-code-used-up`; `active_usages = 3` |
| Podwójne zamówienie z jednego koszyka | T3: 15 równoległych żądań z różnymi kluczami; 15 z tym samym kluczem | 1 × 201 + 14 × 409 `cart-empty`; 1 × 201 + 14 × 200 z tym samym zamówieniem; 1 wiersz w `ordering_order` |
| Klient przesyła cenę, sumę, rabat, walutę, status, właściciela | T4, T10, T14 (API i BFF) | zignorowane; cena z Pricing (`unit_gross 18900`, `status new`, `currency PLN`) |
| Zaniżona albo nieaktualna suma (`expectedTotalGross`) | T4: 0, 1, suma−1, −1, string, float, null | 409 `order-total-changed` albo 422 |
| Ilości ujemne, zerowe, ułamkowe, `1e1`, `2.0`, string, tablica, null, bool, obiekt, 2^31, 2^63, `1e400`, zduplikowany klucz | T4 (API), T14 (BFF) | 422 w każdym przypadku; `CHECK (quantity between 1 and 99)` |
| Przekroczenie limitu 50 pozycji i 99 sztuk koszyka, także równolegle | T20: 52 warianty; 20 + 20 szt. przy stanie 36; 20 równoległych dodań po 2 szt. | 51. pozycja 409; ilość linii nigdy ponad stan (36) ani 99 |
| Ilość ponad stan przez zmianę ilości | T4: PUT 99 przy stanie 40 | 409 |
| Zmiana koszyka po zobaczeniu sumy albo w trakcie zamówienia | T5 (a), (b): 12 wyścigów | zawsze 409 `order-total-changed`, koszyk kompletny |
| Rabat utrzymany po usunięciu warunku (próg kodu) | T5 (c): kod z minimum 200 zł, 3 warianty sumy | 409 `discount-code-below-minimum`; 0 użyć |
| Kod wygasły, wyłączony, zaplanowany, wstrzyknięcie w tekście kodu, tablica | T5 (d), T9 | `invalid` / 422 |
| Promocja albo cena zmieniona w trakcie checkoutu daje zamówienie po złej cenie | T8 (start i koniec promocji), T16 (zmiana ceny: w górę, w dół i z powrotem, 10 wyścigów) | 409 `order-total-changed`; w wyścigu 10 × 201 po cenie sprzed zmiany, `total = unit_gross = expected` |
| Dwie promocje na jednym wariancie, promocja > 90%, kod > 90%, kod kwotowy ≥ minimum, limit 0, zmiana wartości kodu po starcie | T13 | 422 / 409 (`promotion-overlap`, `discount-code-not-editable`) |
| Zakup produktu wycofanego (draft), nieznanego wariantu | T6 | dodanie 404; zamówienie 409 `cart-not-orderable` |
| Zmiana statusu przez klienta, gościa albo bez tokenu | T7 (1), T10 (d) | 401/403/404; brak endpointu klienta |
| Przejścia niedozwolone z panelu (wysyłka, dostarczenie, anulowanie, zwrot nieopłaconego; anulowanie wysłanego; wysyłka anulowanego; dostarczenie zwróconego) | T7 | 409 `order-transition-not-allowed` |
| Podwójne anulowanie, wysyłka ∥ anulowanie, podwójna wysyłka, zwrot, oznaczenie zwrotu pieniędzy | T7 (2)(3)(4), T7b: 6–12 równoległych żądań z tą samą wersją | dokładnie jedno 200, reszta 409; ruchy magazynu po jednym; zwrot pieniędzy 1 × 200, 5 × 409 |
| Scalanie koszyka ∥ zamówienie gościa dubluje pozycje | T10 (e): 8 wyścigów | zawsze dokładnie jedno (8 × scalenie wygrało, zamówienie 404) |
| Ponowne użycie koszyka po zamówieniu daje drugie zamówienie | T3 | koszyk pusty, 409 `cart-empty` |
| Rezerwacja nieopłaconego zamówienia nie jest zwalniana | T11 | `R-100021` anulowane 09:56:38 z `payment_timeout`; stan `28 \| 28 \| 0` → `28 \| 0 \| 28`; użycia kodu 3 → 0; kolejka `failed` pusta (zwolnienie dopiero po 35 min: SA-04) |
| Płatność wykonana po terminie opłaca zamówienie albo zostawia rezerwację | T15 | płatność zostaje `pending`, zamówienie `cancelled` (`payment_timeout`), rezerwacja `released`; gałąź `PaidAfterDeadline` tylko w kodzie |
| Przyjęcie albo korekta stanu z wartością ujemną, ułamkową, ponad limit, poniżej rezerwacji, ze starą wersją; powtórzony klucz przyjęcia | T18 (rola `warehouse`; `marketing`, `support`, brak tokenu) | 422 / 409; powtórzony klucz 200 bez drugiego przyjęcia; role 403, brak tokenu 401 |
| Niespójne sumy, VAT albo podział rabatu w zamówieniu z mieszanymi stawkami | T19: 3 linie (8% z promocją 15%, 2 × 23%), kod −50 zł | sumy zgodne (`lines_consistent = t`), suma = podgląd koszyka (71 279 gr) |
| Przepełnienie kwot przy dużych ilościach | przegląd: `GrossPrice::MAX = 99 999 999` gr, 50 × 99 szt., sumy `bigint`, alokacja w GMP | maks. suma ok. 4,95·10^11 gr mieści się w `int64`; brak arytmetyki zmiennoprzecinkowej |

**Płatności** (`work/pay`, 12 skryptów; webhooki podpisywane sekretami lokalnego stosu, bez sekretu żaden nie przeszedł)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| Webhook bez podpisu, z pustym, błędnym, wielkimi literami, z podpisem w query, podpisany pustym kluczem, stałym kluczem profilu `ci`, kluczem API albo sekretem drugiego dostawcy | T02, 13 wariantów | wszystkie 400, zamówienie `new`, płatność `pending`, 0 zdarzeń |
| Dwa nagłówki `X-Signature` (błędny i poprawny, w obu kolejnościach) | `curl` z dwoma nagłówkami | 400 w obu kolejnościach |
| Podpis liczony ze sparsowanych danych | T02: podpis ciała `REJECTED`, wysłane `PAID`; to samo ciało ze spacjami | 400; podpisane jest surowe ciało |
| `demo-card`: stary albo przyszły znacznik czasu, brak `t`, drugi `t=`, schemat `v0` | T02 | 400; poprawny `v1` obok błędnego przechodzi (rotacja) |
| Powtórzony poprawny webhook opłaca albo księguje wielokrotnie | T03: 20 sekwencyjnie, 40 równolegle (ten sam `eventId`), 30 równolegle (różne `eventId`) | 90 × 204; zamówienie `paid`, wersja 2, 1 e-mail płatności, 31 wierszy zdarzeń |
| Webhook z inną kwotą albo walutą opłaca zamówienie | T04: niedopłata, 1 grosz, nadpłata, EUR, kwota jako tekst/float, 0, ujemna, brak `paidAt` | 422 (kwota) albo 400 (format); 0 zdarzeń |
| Tania transakcja A opłaca droższe zamówienie B | T04: podmiana id, numeru, endpointu dostawcy | 422; A opłaca tylko A; 404 |
| „Odrzucone” po „opłacone” cofa opłacenie; „opłacone” po „odrzucone” gubi pieniądze | T05, C i D | `PAID` po `REJECTED` opłaca, `REJECTED` po `PAID` nic nie zmienia |
| Powrót na stronę potwierdzenia albo żądanie przeglądarki zmienia status | T12: `/checkout/confirmation?status=paid…`, `POST/PUT/PATCH/DELETE` na BFF i API, cookie potwierdzenia bez podpisu i z podrobionym podpisem, obcy `Origin` | status tylko z API; 404/405/403 |
| Klient albo gość rozpoczyna lub czyta płatność cudzego zamówienia | T06: klient B na zamówieniu A, gość z tokenem innego zamówienia, bez tokenu, pusty token, token ucięty i z dopiskiem, token klienta na zamówieniu gościa, token pracownika | 404 (jeden tekst) albo 401; 0 płatności |
| `Idempotency-Key` pozwala zmienić dostawcę albo zamówienie | T06 | 409 |
| Równoległe rozpoczęcia tworzą kilka oczekujących płatności | T06: 20 równolegle różnymi kluczami i 20 jednym kluczem | 1 × 201, 19 × 409; jedna płatność `pending` |
| Płatność za zamówienie opłacone, anulowane albo po terminie | T05 (E), T10 | 409, żadna płatność nie powstaje (po terminie: `payment-deadline-passed`) |
| Nowa próba równolegle z „Zapłać” daje dwie udane płatności | T08, 8 zamówień | w 8 z 8 prób wygrała decyzja: start 409, zamówienie `paid` jedną płatnością |
| API dostawców symulatora dostępne bez klucza, z kluczem drugiego dostawcy, kluczem `ci` albo pustym hasłem | T07; osobno `curl` w audycie autoryzacji | 401 |
| Decyzja na stronie symulatora bez tokenu, z błędnym tokenem, pod ścieżką drugiego dostawcy; podwójna decyzja; decyzja po wygaśnięciu sesji | T07: także 15 × „pay” i 15 × „reject” równolegle | 403; jedna decyzja, jeden webhook; druga decyzja tylko przekierowuje |
| Podwójny albo równoległy zwrot, zwrot nieopłaconego, zwrot przez rolę bez uprawnień | T09: 20 równoległych anulowań, 20 równoległych wykonań zwrotu | dokładnie jedno 200 w każdej serii; reszta 409; 403/401 |
| Pracownik może oznaczyć zamówienie jako opłacone z panelu | T09: przejścia `pay`, `paid` | 422 (poza enumem) |
| Późny webhook wskrzesza anulowane zamówienie albo rezerwację | T09, T10 | zamówienie zostaje `cancelled`, rezerwacja `released`; płatność oznaczona `order_cancelled` |
| Sekret deweloperski albo domyślny może trafić na produkcję | `init-env.sh`, `secret.yaml` (`required`), SealedSecret; stałe wartości tylko w profilu `ci` | brak wartości domyślnych w kodzie; pusty sekret odrzuca każdy webhook |
| Dane kartowe albo treść webhooków w bazie, logach, Sentry | schemat `payment_*`, wywołania loggera, `sentry.yaml` | brak; zapisywane są identyfikatory, kwoty, statusy |

**API** (`work/api`; ok. 1900 żądań)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| SQL Injection w filtrach, sortowaniu, kursorach | przegląd 53 plików z DBAL/DQL (`work/api/sqlgrep.sh`); payloady w `q`, `sort`, `status`, `after`, `customerId`, `category`, id w ścieżkach | wartości tylko jako parametry, kolumny z map stałych; 200 (0 wyników) albo 400, żadnego 500 |
| Wstrzyknięcie do Elasticsearch | `q=*:*`, `q=name:(a OR b) AND _exists_:id`, `option=_exists_:*`, cudzysłowy, backslash | `multi_match` i `term(s)`; operatory traktowane jak tekst |
| Głęboka paginacja / ogromne `limit` | `perPage=1000`, `1e2`, `1.5`, `page=99999999999999999999`, `page=209&perPage=48`; panel `perPage=100000` | `perPage` 1–48 (panel 1–100) → 400; okno 10 000; liczniki ograniczone |
| Command injection, `unserialize`, SSTI, XXE | grep wywołań; ciało XML z encją zewnętrzną | brak wywołań; XML → 415 (`acceptFormat: 'json'`) |
| SSRF | adresy wychodzące z env; imgproxy: `insecure/plain/https://…`, `local:///etc/passwd`, `http://169.254.169.254/…` | brak adresu z wejścia; imgproxy 403 |
| Upload: nie-obraz, SVG, HTML, GIF, bomba, traversal, nadpisanie | 19 wariantów na `POST /api/v1/admin/product-images` (token `marketing`) | SVG/HTML/GIF → 422; 100 MP → 422; 65 MB i 71 MB → 422; nazwa ignorowana; polyglot JPEG+HTML przyjęty, serwowany tylko jako przekodowany WebP; bez tokenu 401 |
| Podmiana opcji lub podpisu imgproxy | zmieniony podpis, `rs:fill:4000:4000` ze starym podpisem, `/info/…`; osobno w audytach autoryzacji i infrastruktury | 403 |
| CORS: wildcard, odbicie Origin, credentials | preflight i GET z obcym, podobnym, `null` i poprawnym Origin | `Access-Control-Allow-Origin` tylko dla dokładnego `ADMIN_URL`; brak `Allow-Credentials` |
| Zatrucie cache / cache deception w Varnishu | od środka sieci: Authorization, Cookie, Origin, Host, `..%2fme`, `/api/v1/me`, PURGE bez tokenu | Authorization/Cookie → pass; `me`, koszyki, panel nigdy nie buforowane; PURGE 403 |
| Host / `X-Forwarded-*` wpływa na adresy | `Host: evil.example`, `X-Forwarded-Host/Proto/Prefix`, `Forwarded` | `Location` względne; adresy absolutne z env; brak `trusted_proxies` |
| Obejście limitu przez `X-Forwarded-For` | `work/api/prod_ratelimit_probe.php` (jądro `prod`): 12 żądań z rotowanym XFF/Forwarded/X-Real-IP | `422×10, 429, 429`: klucz to adres połączenia |
| Wyciek wyjątków w produkcji | `work/api/prod_kernel_probe.sh`: jądro `prod` z nieosiągalną bazą | `500 {"type":"about:blank","title":"Internal Server Error","status":500}` |
| Swagger UI / profiler / `.env` publicznie | `GET /api/doc`, `/api/doc.json`, `/_profiler`, `/_wdt/x`, `/.env` | 404 |
| Typy i ciała JSON | string/tablica/float/`1e1`/ogromna liczba, duplikaty kluczy, `null`, niedomknięty JSON, 100 000 poziomów, `text/plain`, form-urlencoded | 422, 400, 415; nic nie jest rzutowane |
| Odbicie `X-Correlation-ID`, method override, tokeny w odpowiedziach 401 | nagłówek z CRLF/`<script>`/5 kB; `X-HTTP-Method-Override`, `_method`; zepsute JWT | identyfikator zawsze własny (UUIDv7); 405; 401 ze stałym tekstem |
| XSS/SSTI na stronie symulatora płatności | kod `PaymentPageHtml`, ścieżki z `<script>`, `_token[]` | 404/400/403, brak odbicia |
| Wstrzyknięcie nagłówków maila | `ControlCharacters`, `MailerAcceptsEmail`, Symfony Mime | niemożliwe (treść: SA-31) |

**Frontend** (`work/fe`; sklep jako `next start`, panel jako `vite dev`, Chromium przez Playwright w kontenerze)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| Stored XSS w sklepie przez nazwę, opis, osie i wartości wariantów, `alt` zdjęcia, nazwę kategorii | własny produkt i kategoria (`work/fe/xss_product.py`), surowy HTML i Chromium (`work/fe/pw/tests/xss.spec.ts`) | 0 okien dialogowych, 0 wstrzykniętych elementów |
| Zamknięcie `<script type="application/ld+json">` sekwencją `</script>` / `<!--` | payloady w nazwie, opisie, etykiecie wariantu, nazwie kategorii | `serializeJsonLd` zamienia każdy `<`; payload RSC też ma ucieczkę |
| Reflected XSS w `q`, `sort`, `cena-od`, `o`, `kategoria`, `category`, ścieżce 404 | `curl` z payloadami i Chromium | zakodowane |
| Stored XSS na administratorze przez dane zamówienia i katalogu | zamówienie gościa R-100010 z payloadami; panel w Chromium jako `admin` (pulpit, zamówienia, produkty, kategorie, ceny, magazyn) | 0 dialogów; brak `v-html` i `innerHTML` w `apps/admin/src` |
| Nazwa pliku zdjęcia jako wektor; XSS w e-mailu potwierdzenia | upload z nazwą zawierającą znacznik; Mailpit | klucz to skrót treści; wiadomość tekstowa |
| Open redirect frameworka (`//evil.example/`, `/\evil`, `/%5Cevil/`) i przez `Host`/`X-Forwarded-Host`; open redirect w panelu po logowaniu | `curl --path-as-is`; analiza `AuthCallbackView.vue` | 308 na ścieżkę względną; `redirect_uri` z `STORE_URL`; tylko ścieżka wewnętrzna routera |
| Ominięcie ochrony nagłówkiem `x-middleware-subrequest` | żądania do `/api/account/orders` z nagłówkiem | 401; sklep nie ma middleware/proxy |
| Dane jednej sesji podane innej (cache stron i BFF) | dwie sesje gości i klient bez cookies: `/checkout/confirmation`, `/checkout`, `/api/checkout/payment`, `/api/cart`, kolejno i 40 żądań równolegle | brak przecieku; `private, no-store` |
| Token dostępu zamówienia albo `orderId` w HTML/RSC strony potwierdzenia | przeszukanie HTML i odpowiedzi RSC | brak |
| Zatrucie cache wariantem RSC pod adresem HTML | `RSC: 1`, `Next-Router-Prefetch`, `Next-Router-Segment-Prefetch` bez poprawnego `_rsc` | 307 na adres z `_rsc` albo zwykły HTML |
| SSRF / dowolny odczyt przez optymalizator obrazów | `/_next/image?url=http://api/health…` | 404 (własny loader wyłącza endpoint) |
| Sekrety i adresy wewnętrzne w zasobach przeglądarki, publiczne mapy źródeł | `work/fe/bundle_secrets.sh` (19 wartości z `.env.local` w `.next/static` i prerenderach), `http://api`, `redis://`, `sourceMappingURL`, `*.js.map` | 0 trafień; brak `NEXT_PUBLIC_*`; panel ma tylko 4 publiczne adresy `VITE_*` |
| Tokeny lub dane osobowe w `localStorage`/`sessionStorage`/IndexedDB | Chromium po zalogowaniu do panelu i po kasie | sklep: puste; panel: puste po logowaniu, tokeny w pamięci |
| Traversal ścieżki w cache ISR przez `..%2F` w slugu | żądania i listing katalogu cache | nazwy zachowują kodowanie |
| Proxy „dowolna ścieżka do API” w BFF; UI panelu wysyła pola, którym backend ufa | przegląd wszystkich handlerów i wszystkich `POST/PUT/DELETE` panelu | brak; ścieżki stałe, identyfikatory jako UUID, pola z listy (`pickFields`); tylko pola biznesowe i `version` |
| Wycofany produkt zostaje w cache sklepu | wycofanie własnego produktu, odpytywanie co 2 s | 404 i brak w wyszukiwarce po ≤ 2 s |
| Wyciek szczegółów błędów | błędne kodowanie procentowe, zły JSON, skrajne parametry | ogólne 500/404/422 bez stosu |

**Infrastruktura** (`work/infra`; wyłącznie lokalny stos i render `prod`, testy tylko do odczytu)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| Compose montuje gniazdo Dockera albo uruchamia kontenery uprzywilejowane | `docker inspect` 13 kontenerów | brak `docker.sock`, `privileged=false`, bez `cap_add` |
| Usługi compose nasłuchują na `0.0.0.0` hosta | `docker ps` | wszystkie porty na `127.0.0.1`; Postgres, Redis, Elasticsearch, Garage, Varnish bez portu hosta |
| RabbitMQ ma konto `guest` albo słabe hasło | trzy typowe pary poświadczeń na :15672 z hosta i z sieci | 401; jedyne konto `shop` z losowym hasłem |
| Konsola / realm `master` Keycloaka z domyślnym hasłem | dwie typowe pary i hasło kont demo na `admin-cli` | `invalid_grant`; hasło bootstrap losowe (16 bajtów) |
| Prefiks Ingressu `/realms/shop/` da się obejść do `master` albo konsoli | 10 wariantów ścieżek (`..%2F`, `..;/`, `%2e%2e`, `..%252F`, przez `/resources/`) bezpośrednio na Keycloaku z `--path-as-is`; osobno 4 warianty w audycie uwierzytelniania | 400/404; Traefika nie testowano |
| Rola `shop` czyta bazę Keycloaka | `psql -U shop -d keycloak` | `permission denied`; role bez superusera, `CREATEDB`, `CREATEROLE` |
| Postgres przyjmuje połączenia sieciowe bez hasła | `pg_hba.conf` | `host all all all scram-sha-256`; `trust` tylko dla pętli zwrotnej |
| imgproxy przetwarza niepodpisane adresy albo obce źródła | `/unsafe/...`, `/insecure/.../plain/http://169.254.169.254/` | 403; źródła tylko `s3://product-images*` |
| Garage admin API bez tokenu; buckety publiczne | `GET :3903/v2/ListBuckets` bez nagłówka; `garage.toml` | 403; bucket prywatny |
| Varnish przyjmuje PURGE bez tokenu | `PURGE` z błędnym tokenem z sieci compose | 403; bez sekretu purge VCL się nie ładuje |
| Konta demo trafiają do klastra | render ConfigMapy `keycloak-realms` | brak `users`, haseł i sekretów |
| Keycloak w klastrze działa w `start-dev` albo z dynamicznym hostname | render `Deployment/keycloak` | `start`, `KC_HOSTNAME=https://auth.<domena>`, `KC_PROXY_HEADERS=xforwarded`, port 9000 poza Ingressem |
| Pody dostają token API Kubernetesa / szerokie RBAC | render | `automountServiceAccountToken: false` wszędzie; token tylko w initContainerach `kubectl` z minimalną rolą |
| NodePort/LoadBalancer/hostPort/hostPath w chartcie | render | 12 Service `ClusterIP`; brak `hostPath`, `hostNetwork`, `hostPort` |
| Sekrety jawnym tekstem w `values.yaml`, `environments/prod`, `argocd` | przegląd plików i SealedSecretów | tylko szyfrogramy (zakres strict) |
| Aplikacje mają wartości „dev” jako fallback sekretów | `apps/api/.env`, `config/*.yaml` | sekrety to wymagane `%env()%`; `default::` tylko dla Sentry i `CLIENT_IP_HEADER` |
| Obraz produkcyjny zawiera narzędzia deweloperskie albo sekrety w warstwach | Dockerfile + `.dockerignore` | etapy `prod` bez Composera, gita, SPX, npm i map źródeł (obrazu nie budowano) |
| API ufa `X-Forwarded-For`/`CF-Connecting-IP` od każdego | `ClientIp.php`, schemat chartu | nagłówek tylko z `CLIENT_IP_HEADER`; render odmawia go bez `ipAllowList` |

**Supply chain** (`work/sc`)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| Obejście autoryzacji metodą HEAD (advisory Symfony) | `HEAD /api/v1/admin/me`, `/api/v1/admin/products`, `/api/v1/me/orders` | 401 |
| Obejście reguł ścieżki przez nazwę skryptu | `/index.phpapi/v1/admin/me`; `/index.php/api/v1/products` | 401; 200 (trasa realna) |
| Przejęcie hosta przez base URL | `//evil.example/index.php/...` | 404 |
| ReDoS/XXE parserów YAML i XML | przegląd użyć | parsowanie tylko plików z repo |
| Sekrety w historii Git | Gitleaks na 326 commitach, ręczny przegląd 59 trafień | wszystkie fałszywe |
| Zatrucie GHCR z PR z forka | uprawnienia tokenu forka | token read-only, brak `packages: write`; cache GHA izolowany per ref |
| Wdrożenie dowolnego kodu przez push do gałęzi | rulesety, środowisko `production`, `verify-images.sh` (stan w GitHubie) | gałęzie `deploy`/`deploy-images` przesuwa wyłącznie job `deploy` po zatwierdzeniu |

**Prywatność i logi** (`work/priv`; logi 13 usług, Redis, RabbitMQ, bazy, Elasticsearch, Mailpit)

| Hipoteza | Jak sprawdzono | Wynik |
|---|---|---|
| Logi API, workera i sklepu zawierają dane adresowe, telefon, ciała żądań | `grep` znacznika, e-maila i telefonu po pełnym przebiegu | brak trafień poza query stringami wyszukiwania panelu i logiem Keycloaka (`username`) |
| Nagłówki `Authorization`, `Cookie`, `Order-Access-Token`, id sesji albo token zamówienia trafiają do logów | `grep` wartości | brak; jedyny JWT w logu to cudzy test z tokenem w query stringu |
| Token zamówienia gościa albo e-mail jest w adresie URL | kod BFF, przebieg `01_guest.py` | token tylko w nagłówku API i w podpisanym cookie `HttpOnly` (1 h) |
| Błędy walidacji zamówienia logują podane wartości | wpisy `UnprocessableEntityHttpException` | tylko ścieżka pola i komunikat |
| Odpowiedzi błędów ujawniają ślad stosu lub SQL | 13 sond | zawsze `application/problem+json`; nie udało się wymusić 5xx |
| Ponowna rejestracja usuniętego adresu e-mail daje dostęp do starych zamówień | usunięcie konta, rejestracja tego samego adresu | nowy `sub`; widoczne tylko nowe zamówienie |
| Rola `marketing` widzi klientów | `03_staff.py` | 403 na `/admin/customers` i `/admin/orders/{id}` |
| Zmiana ceny i statusu nie ma śladu, kto ją wykonał | zmiana ceny i anulowanie kontem `admin` | `pricing_price_history` i `ordering_order_status_change` mają id i nazwę pracownika |
| Zdarzenia domenowe, outbox i RabbitMQ niosą dane osobowe | `OrderPlaced`, `messenger_messages`, kolejka `events` | tylko `orderId` i czas |
| Elasticsearch zawiera dane klientów | `_cat/indices`, wyszukanie znacznika | jeden indeks produktów, 0 trafień |
| Własna kryptografia używa słabej losowości lub porównań | `grep` `mt_rand`, `uniqid`, `rand(`, `md5`, `sha1` | brak; `random_bytes(32)`, HMAC-SHA256, `hash_equals`, `timingSafeEqual` |
| Keycloak przechowuje hasła słabym skrótem | `GET …/users/{id}/credentials` | argon2id, pamięć 7168 KiB, 5 iteracji |
| Nieudane logowania nie są nigdzie rejestrowane | log Keycloaka | `WARN LOGIN_ERROR`; nietrwale (SA-11) |
| Seed zawiera dane prawdziwych osób; w repo są zrzuty baz lub HAR | domeny e-maili, `find` plików | wyłącznie `example.com` |
| Sklep ustawia cookies nieesencjonalne lub ładuje skrypty stron trzecich | nagłówki, `grep` | tylko pięć cookies sklepu; fonty lokalne; SDK Sentry tylko przy DSN |
| Mailpit i konsola RabbitMQ są dostępne z internetu w `prod` | `templates/ingress.yaml` | brak ingressu dla obu |
| Żądany zakres OIDC jest nadmiarowy | przekierowanie `/auth/login` | `scope=openid` |

## 7. Zestawienie wszystkich znalezisk

Poziom w tabeli dotyczy założenia audytu (prawdziwi klienci i płatności); tam, gdzie ocena dla obecnego demo
jest inna, podano obie. Pole „Niezależny przegląd” występuje przy pozycjach, które przeszły weryfikację reviewera
(załącznik B). Kolumna „Źródła” to identyfikatory robocze audytorów obszarów.

Rewizja kodu: `ef204620`. Identyfikatory `SA-NN` nadał koordynator; kolumna „Źródła” podaje identyfikatory audytorów.
Kategorie OWASP Top 10 są podane w edycji 2025 (kategorie audytorów z edycji 2021 przemapowano), API Security Top 10 w
edycji 2023. Ścieżki skryptów PoC mają postać `work/<obszar>/<plik>` względem katalogu
`research_notes/Audyt bezpieczeństwa sklepu/evidence/` (lokalny, poza repozytorium). W każdym opisie „lokalnie” oznacza obserwację na
stosie Compose `shop-secaudit`, a „chart `prod`” wniosek z konfiguracji produkcyjnej w repo (produkcji nie testowano).

| ID | Tytuł | Kategoria | Poziom | Status | Nakład | Źródła |
|---|---|---|---|---|---|---|
| SA-01 | Keycloak 26.6.0 z niezałatanymi lukami, w tym przejęciem konta przez reset hasła | A03:2025; CWE-1395, CWE-287 | Critical przy sprzedaży (ocena dostawcy); Medium w demo | wersja podatna CONFIRMED; wykorzystanie LIKELY (nieodtworzone) | M | SC-01 |
| SA-02 | Symulator jako jedyny operator płatności, bez wyłącznika, publiczny w profilu `prod` | A06:2025, A02:2025; API6:2023; CWE-489, CWE-840 | Critical przy sprzedaży; w demo zamierzone | CONFIRMED | S + L | PAY-01, INFRA-07 (część) |
| SA-03 | Równoległe rozpoczęcia płatności jednego zamówienia zatrzymują całe API | API4:2023; A06:2025; CWE-400, CWE-833, CWE-770 | High | CONFIRMED lokalnie; skala na produkcji LIKELY | M | PAY-02 |
| SA-04 | Blokowanie towaru i kodów nieopłaconymi zamówieniami gościa | API6:2023; A06:2025; CWE-770, CWE-799 | High przy sprzedaży; Medium w demo | CONFIRMED (skala wyliczona) | M | BIZ-01 |
| SA-05 | Jeden Secret ze wszystkimi hasłami w środowisku każdego poda | A02:2025, A06:2025; API8:2023; CWE-272, CWE-526, CWE-250 | High | CONFIRMED | M | INFRA-01, AUTH-07, FE-03, API-06 (części) |
| SA-06 | Brak NetworkPolicy; Redis, Elasticsearch i Mailpit bez uwierzytelnienia | A02:2025, A01:2025; CWE-306, CWE-923, CWE-312 | High przy sprzedaży; Medium w demo | CONFIRMED | M | INFRA-02, AUTH-07, PRIV-09 (części) |
| SA-07 | Kopie zapasowe wyłączone w `prod`; klucz z klastra może skasować kopie | A02:2025; CWE-693, CWE-284, CWE-1188 | Medium przy sprzedaży; Low w demo | CONFIRMED | S + M | INFRA-03, PRIV-08 |
| SA-08 | Brak polityki haseł w realmach `shop` i `backoffice` | A07:2025; API2:2023; CWE-521 | Medium przy sprzedaży; Low w demo | CONFIRMED | S | AUTH-01, PRIV-02 |
| SA-09 | Brak limitu prób na endpointach Keycloaka; ROPC przez `admin-cli`; zalew maili resetu | A07:2025; API2:2023, API4:2023; CWE-307, CWE-799 | Medium przy sprzedaży; Low w demo | CONFIRMED | M | AUTH-02 |
| SA-10 | Ustawienia bezpieczeństwa realmów z repo nie trafiają do istniejącego realmu | A02:2025; CWE-1188 | Low | HARDENING | M | AUTH-09 |
| SA-11 | Keycloak nie zapisuje zdarzeń logowania ani administracyjnych | A09:2025; CWE-778 | Medium | CONFIRMED | S | PRIV-03, AUTH-09 (część) |
| SA-12 | Brak limitu rozmiaru i czasu ciała żądania w API i w BFF sklepu | API4:2023; CWE-770, CWE-400 | Medium | CONFIRMED | S | API-01, FE-02 |
| SA-13 | Nieograniczony zapis cache ISR na dysk dla dowolnych adresów `/p/…` | A06:2025; API4:2023; CWE-400, CWE-770 | Medium | CONFIRMED | M | FE-01 |
| SA-14 | Podgląd kodu rabatowego jest wyrocznią bez limitu prób | API6:2023, API4:2023; A06:2025; CWE-307, CWE-204, CWE-799 | Medium | CONFIRMED | S | API-02, BIZ-02 |
| SA-15 | Nieograniczone tworzenie koszyków gościa | API4:2023; CWE-770 | Medium | CONFIRMED | S / M | API-03 |
| SA-16 | Brak stałej kontroli podatności zależności i obrazów w CI | A03:2025; CWE-1104, CWE-1395 | Medium | CONFIRMED | S | SC-02 |
| SA-17 | Symfony 8.1.9/8.1.10 z advisories z 2026-11-04 | A03:2025; CWE-1395, CWE-915 | Low (po przeglądzie) | LIKELY | S | SC-03 |
| SA-18 | Błędny token Bearer tworzy zdarzenie Sentry | A09:2025; API4:2023; CWE-779, CWE-400 | Medium | CONFIRMED | S | PRIV-01 |
| SA-19 | Brak sygnałów bezpieczeństwa: logów dostępu, metryk i alertów | A09:2025; CWE-778, CWE-223 | Medium | CONFIRMED | M | PRIV-04 |
| SA-20 | Luki śladu audytowego operacji personelu | A09:2025; CWE-778 | Medium | CONFIRMED | M | PRIV-05 |
| SA-21 | Brak usuwania, anonimizacji, eksportu i retencji danych klientów | A06:2025; CWE-459 | Medium | CONFIRMED | L | PRIV-06 |
| SA-22 | Płatność przyjęta bez opłaconego zamówienia widoczna tylko w bazie i logu | A06:2025, A09:2025; CWE-841, CWE-778 | Medium przy prawdziwym operatorze; Low dziś | CONFIRMED (zachowanie) | M | PAY-03 |
| SA-23 | Brak odpytania operatora i rekoncyliacji; wygaszanie zależne od jednej wiadomości | A06:2025, A09:2025; CWE-754, CWE-391, CWE-772 | Medium przy prawdziwym operatorze; Low dziś | HYPOTHETICAL (skutek); braki CONFIRMED | M | PAY-04, BIZ-04 |
| SA-24 | Celowa blokada cudzego konta dwiema błędnymi próbami | A07:2025; API4:2023; CWE-645 | Low | CONFIRMED | S | AUTH-03 |
| SA-25 | Enumeracja kont klientów przez formularz rejestracji | A07:2025; API2:2023; CWE-204 | Low | CONFIRMED | S / M | AUTH-04 |
| SA-26 | Wylogowanie zależne od przeglądarki; token ważny do 5 min; refresh tokeny nierotowane | A07:2025; API2:2023; CWE-613 | Low | CONFIRMED | S / M | AUTH-05 |
| SA-27 | Cookies sklepu bez `__Host-`; HSTS bez `includeSubDomains` | A07:2025, A02:2025; CWE-384, CWE-565, CWE-319 | Low | HARDENING | S | AUTH-06, FE-05 |
| SA-28 | Klient `admin` z wildcardem w `redirectUris` i dowolnym adresem po wylogowaniu | A02:2025, A07:2025; CWE-601, CWE-940 | Low | HARDENING | S | AUTH-08 |
| SA-29 | Niepełne CSP sklepu i panelu; `X-Powered-By` | A02:2025; CWE-693 | Low | HARDENING | M / S | FE-04 |
| SA-30 | Kosztowne publiczne odczyty bez limitu (wyszukiwarka, mapa strony) | API4:2023; CWE-770, CWE-405 | Low | LIKELY | S – M | API-04 |
| SA-31 | Maile zamówienia gościa z treścią atakującego na dowolny adres | A06:2025; API6:2023; CWE-20, CWE-451, CWE-799 | Low | CONFIRMED | S | API-05, BIZ-03 |
| SA-32 | Role panelu: szeroki odczyt dla każdej roli, brak roli tylko do odczytu | API5:2023; A01:2025, A06:2025; CWE-272, CWE-285, CWE-269 | Low | HARDENING | S | AUTHZ-01, AUTHZ-02 |
| SA-33 | Kontenery bez `securityContext`; obraz `prod` API jako root | A02:2025; CWE-250, CWE-732 | Low | HARDENING | M | INFRA-04, FE-03 (część) |
| SA-34 | Płaszczyzna zarządzania: 6443 otwarty, `sshd` nieutwardzony, niewycofywalny kubeconfig | A02:2025; CWE-284, CWE-668 | Low | HARDENING | S | INFRA-05 |
| SA-35 | Ochrona originu tylko listą adresów Cloudflare; Access obejmuje wyłącznie SPA panelu | A02:2025; CWE-290, CWE-693 | Low | HYPOTHETICAL / HARDENING | S / M | INFRA-06 |
| SA-36 | Profil `prod` jest profilem demo: poczta w Mailpit, seed, bucket testowy | A02:2025, A06:2025; CWE-1188, CWE-489, CWE-359 | Low | CONFIRMED | S | INFRA-07 (bez płatności), API-06 (część) |
| SA-37 | Brak procedury rotacji sekretów; klucz Sealed Secrets nierotowany | A04:2025, A02:2025; CWE-324, CWE-320 | Low | HARDENING | M | INFRA-08 |
| SA-38 | Obrazy bazowe przypięte tagiem; zaległe CVE obrazów i Next.js 16.4.3 | A08:2025, A03:2025; CWE-494, CWE-829, CWE-1395 | Low | CONFIRMED / LIKELY | S (+ M) | INFRA-09, SC-04, SC-05 |
| SA-39 | Identyfikatory koszyków gościa i kody rabatowe przechodzą przez maskowanie logów | A09:2025; CWE-532 | Low | CONFIRMED | S | PRIV-07 |
| SA-40 | Adaptery płatności jako wzorzec dla prawdziwego operatora: luki do zamknięcia | A04:2025, A08:2025; CWE-294, CWE-323, CWE-345 | Low | HARDENING | S | PAY-05 |
| SA-41 | Nagłówek `sentry-trace` wymusza śledzenie w Sentry | API4:2023; CWE-770 | Low | HARDENING | S | API-07 |
| SA-42 | Pozycje informacyjne (stan wariantu i numery zamówień; powierzchnie deweloperskie; dane w spoczynku) | API3:2023, A06:2025, A02:2025, A04:2025; CWE-203, CWE-489, CWE-312 | Informational | CONFIRMED / HARDENING | S / S – M | BIZ-05, INFRA-10, PRIV-09 (reszta) |

### SA-01 — Keycloak 26.6.0 z niezałatanymi lukami, w tym przejęciem konta przez reset hasła

- **Kategoria:** OWASP A03:2025 Software Supply Chain Failures; CWE-1395, CWE-287.
- **Poziom:** Critical przy sprzedaży (ocena dostawcy); Medium w demo.
- **Status:** wersja podatna CONFIRMED; wykorzystanie LIKELY (nieodtworzone).
- **Niezależny przegląd:** podtrzymane, status rozdzielony. Zakres podatny (`>= 26.5.0, < 26.6.6` według
  GHSA-4gv3-mc9p-5wqc, ocena Red Hat: Critical) obejmuje 26.6.0; przejęcia konta nie odtworzył ani koordynator (3
  próby), ani reviewer (kilkanaście prób z krokiem `tryAnotherWay`). W realmie `backoffice` reset hasła jest
  wyłączony, więc tej luki nie da się użyć przeciw personelowi; pozostałe CVE zestawu dotyczą przepływu logowania
  klientów i personelu.
- **Lokalizacja:** `infra/docker-compose.yml:104`, `infra/k8s/charts/shop/values.yaml:37` (obraz
  `quay.io/keycloak/keycloak:26.6.0`, przypięty digestem); `infra/keycloak/realms/shop.json`
  (`resetPasswordAllowed: true`).
- **Opis:** Stos przypina Keycloak 26.6.0 (wydany 2026-04-08). Skan obrazu pokazuje w `keycloak-services` CVE-2026-18963
  (Critical): nieuwierzytelnione ustawienie nowego hasła bez linku z e-maila (publiczny patch: Keycloak PR #51844,
  `ResetCredentialEmail.action()` wołało `context.success()` bez sprawdzenia tokenu akcji). Obok są pozycje High
  osiągalne w publicznym realmie: CVE-2026-7507 (session fixation), CVE-2026-7571 (ujawnienie access tokenu),
  CVE-2026-11800 (JWT algorithm confusion), CVE-2026-9086 (XSS), CVE-2026-7504 (open redirect przy wildcardzie w
  redirect URI; por. SA-28).
- **Wymagania wstępne ataku:** dostęp sieciowy do endpointów realmu `shop` (publiczne) i znajomość e-maila ofiary.
- **Scenariusz wykorzystania:** atakujący rozpoczyna reset hasła dla adresu ofiary i kończy flow bez linku z e-maila,
  ustawiając własne hasło.
- **Konsekwencje:** przejęcie kont klientów (dane osobowe, adresy, historia zamówień), nadużycia na zamówieniach.
- **Dowód:** lokalnie (pełny wynik: `work/sc/trivy/image-quay.io_keycloak_keycloak_26.6.0.json`):
  ```
  CRIT org.keycloak:keycloak-services 26.6.0 -> 26.4.15, 26.6.6, 26.7.2  CVE-2026-18963  reset-credentials flow bypass
  $ curl -s -o /dev/null -w '%{http_code}' http://localhost:24103/realms/shop/login-actions/reset-credentials  -> 200
  $ docker compose exec keycloak kc.sh --version  -> Keycloak 26.6.0
  ```
  Koordynator audytu trzykrotnie próbował odtworzyć obejście resetu hasła na lokalnym stosie na koncie testowym (ponowne
  wysłanie formularza; wywołanie kroku `reset-credential-email` bez tokenu akcji w pierwotnej i w rozwidlonej sesji) i
  nie doszedł do formularza nowego hasła.
- **Istniejące zabezpieczenia:** `bruteForceProtected: true`; ingress wystawia tylko ścieżki realmów (konsola i `master`
  niedostępne); obraz przypięty digestem. Żadne nie blokuje CVE-2026-18963. Profil `prod` ma dziś
  `customerAccounts: false` (rejestracja klientów wyłączona), a realm `backoffice` ma reset hasła wyłączony.
- **Rekomendacja:** podnieść obraz do linii z poprawką i zaktualizować tag oraz digest w compose i chartcie
  (`make check` wymusza zgodność). Audytor wskazuje 26.6.6+ albo 26.7.2+; według notatek researchu upstream zakończył
  linię 26.6 na 26.6.4, a bieżące wydanie to 26.8.2. Przetestować import realmów i logowanie w E2E. Stała kontrola:
  SA-16.
- **Nakład:** M — podmiana wersji jest prosta, ale migracja 26.6 → 26.7/26.8 wymaga testu importu realmów i flow
  logowania.
- **Pewność:** wysoka co do wersji i osiągalności: 26.6.0 mieści się w zakresie podatnym według bazy skanera (naprawa w
  26.4.15, 26.6.6, 26.7.2) i not wydania 26.7.2, a flow resetu w realmie `shop` jest publicznie osiągalny. Niska co do
  wykonalności ataku w tej konfiguracji (trzy nieudane próby odtworzenia).

### SA-02 — Symulator jako jedyny operator płatności, bez wyłącznika, publiczny w profilu `prod`

- **Kategoria:** OWASP A06:2025 Insecure Design, A02:2025 Security Misconfiguration; API6:2023; CWE-489, CWE-840,
  CWE-1188.
- **Poziom:** Critical przy prawdziwej sprzedaży; w demo zachowanie zamierzone i opisane (`docs/vision.md`).
- **Status:** CONFIRMED (test lokalnie; dla produkcji prześledzona konfiguracja chartu, bez żądań do serwera).
- **Niezależny przegląd:** podtrzymane; to brak funkcji (prawdziwego operatora) i brak wyłącznika, nie błąd
  implementacji. Dowód odtworzony ponownie przez koordynatora (zamówienie `R-100114`).
- **Lokalizacja:** `apps/api/config/routes.yaml:1-3`;
  `apps/api/src/PaymentSimulator/Http/PaymentPageController.php:41-79`;
  `apps/api/src/Payment/Domain/ProviderCode.php:12-13`; `infra/k8s/charts/shop/templates/api.yaml:80-86`, `:136`;
  `templates/ingress.yaml:10-12`.
- **Opis:** Symulator płatności jest częścią API i nie ma przełącznika: jego trasy są zarejestrowane w każdym
  środowisku, a innych operatorów nie ma (`demo-transfer`, `demo-card`). Chart `prod` kieruje adaptery
  (`PAYMENT_PROVIDER_BASE_URL=http://api`) i przeglądarkę klienta (`PAYMENT_SIMULATOR_PUBLIC_URL`) na ten sam symulator,
  a ingress `api.<domena>` wystawia go publicznie. `POST` z decyzją `pay` oznacza transakcję jako opłaconą i worker
  wysyła poprawnie podpisany webhook. Token formularza jest wypisany w treści strony, a jej adres dostaje właściciel
  zamówienia w `redirectUrl`.
- **Wymagania wstępne ataku:** żadne; gość bez konta, dowolne własne zamówienie.
- **Scenariusz wykorzystania:** gość składa zamówienie, rozpoczyna płatność, otwiera `redirectUrl` i wysyła formularz
  „Zapłać”; zamówienie przechodzi w `paid` i trafia do wysyłki.
- **Konsekwencje:** przy sprzedaży prawdziwego towaru każde zamówienie jest darmowe, a system nie odróżnia takiej
  płatności od prawdziwej. Cudzych zamówień nie dotyczy (id transakcji to losowy UUID v4).
- **Dowód:** lokalnie, `work/pay/t01_simulator_selfpay.py`, bez użycia sekretu:
  ```
  start 201 {... 'status': 'pending', 'redirectUrl': 'http://localhost:24102/payment-simulator/demo-transfer/pay/ae10f45b-…'}
  decide (303, 'http://localhost:24100/checkout/confirmation', …)
  status after (200, {'orderNumber': 'R-100001', 'orderStatus': 'paid', … 'latestPayment': {'status': 'succeeded'}})
  ```
  Chart `prod`: render zawiera `PAYMENT_SIMULATOR_PUBLIC_URL=https://api.worf.pl` (`api.yaml:83-84`).
- **Istniejące zabezpieczenia:** API dostawców symulatora i dostawa webhooków wymagają kluczy (`hash_equals`, pusty
  klucz odrzuca wszystko); sekrety produkcyjne losowe, w SealedSecret i wymagane przez chart; strona mówi „To
  symulacja”; profil `prod` to demo z `noindex` i paskiem informacyjnym.
- **Rekomendacja:** przed sprzedażą: (1) prawdziwy adapter za portem `PaymentProvider`; (2) symulator wyłączany
  konfiguracją, domyślnie wyłączony w `prod` (trasy przez `when@dev`/`when@test` albo osobny zasób tras, adaptery demo
  rejestrowane warunkowo); (3) test chartu, że profil ze sprzedażą nie ma `PAYMENT_SIMULATOR_PUBLIC_URL`, albo wartość
  `environment: demo|production` z błędem renderu przy aktywnym symulatorze; (4) test: przy wyłączonym symulatorze
  `/payment-simulator/*` → 404.
- **Nakład:** S dla wyłącznika i testów (kilka plików konfiguracji); L dla prawdziwego operatora (osobny epik).
- **Pewność:** wysoka.

### SA-03 — Równoległe rozpoczęcia płatności jednego zamówienia zatrzymują całe API

- **Kategoria:** OWASP API4:2023 Unrestricted Resource Consumption; A06:2025 Insecure Design; CWE-400, CWE-833, CWE-770.
- **Poziom:** High (dostępność całego API, atak anonimowy, kilkadziesiąt żądań).
- **Status:** CONFIRMED lokalnie (mechanizm i skutek); skala na produkcji LIKELY (stos ma limity deweloperskie).
- **Niezależny przegląd:** podtrzymane, High. Mechanizm potwierdzony w kodzie (`doctrine_transaction` na szynie
  komend, częściowy indeks unikalny); limit 20 / 15 min bez blokady ogranicza jeden adres, nie usuwa kaskady.
- **Lokalizacja:** `apps/api/src/Payment/Application/Command/StartPaymentHandler.php:96-97`, `:119-128`;
  `apps/api/src/Payment/Infrastructure/Provider/ProviderHttp.php:32`;
  `apps/api/src/Payment/Infrastructure/Persistence/Doctrine/Record/PaymentRecord.php:21`;
  `infra/k8s/charts/shop/templates/api.yaml:81-82`, `infra/docker-compose.yml:281`;
  `apps/api/docker/frankenphp/Caddyfile` (brak `num_threads`); `apps/api/config/packages/rate_limiter.yaml:8,27`.
- **Opis:** `StartPayment` w jednej transakcji bazy zapisuje oczekującą płatność i woła operatora przez HTTP (limit 5
  s). Operatorem jest to samo API, więc żądanie potrzebuje drugiego wątku FrankenPHP. Równoległe rozpoczęcia tego samego
  zamówienia nie dostają od razu 409: ich `INSERT` czeka na unikalnym indeksie, trzymając wątek i połączenie z bazą. Gdy
  żądań jest co najmniej tyle, ile wątków (T), wywołanie symulatora nie ma wolnego wątku, kończy się po 5 s, a kolejne
  czekające żądanie powtarza to samo. Całe API (także `/health`, koszyk, webhooki) stoi przez około (N − T) × 5 s.
- **Wymagania wstępne ataku:** jedno własne zamówienie gościa i N > T równoległych `POST /api/v1/orders/{id}/payments` z
  różnymi `Idempotency-Key`.
- **Scenariusz wykorzystania:** atakujący składa jedno zamówienie, wysyła jednocześnie kilkadziesiąt rozpoczęć płatności
  i powtarza serię po jej zakończeniu.
- **Konsekwencje:** pełna niedostępność API na czas serii (koszyk, kasa, panel, webhooki; przy dłuższym przestoju
  zapłacone zamówienia wygasają). Chart `prod`: przy 8 vCPU T ≈ 16 (do potwierdzenia), a limit 20 / 15 min jest większy
  niż T, więc jedna seria daje co najmniej (20 − 16) × 5 s = 20 s przestoju na adres i okno; licznik bez blokady
  (`lock_factory: null`) może zliczyć mniej. Przy zewnętrznym operatorze zostaje wzmocnienie awarii: N żądań blokuje N
  wątków na N × 5 s.
- **Dowód:** lokalnie, `work/pay/t11_thread_starvation.py 40` (40 żądań, zamówienie R-100093, T = 32), log w
  `work/pay/t11_evidence.txt`:
  ```
  żądania, które dostały wątek:  32 o 09:25:20, potem po 1 o 09:25:25, :30, :35, :40, :45, :50, 2 o 09:25:55
  09:25:25.445 CRITICAL … Handling "App\Payment\Application\Command\StartPayment" failed: demo-transfer did no[t answer]
  ```
  Wszystkie żądania i sonda `/health` przekroczyły 30 s (API nie odpowiadało ok. 36 s); po serii 8 transakcji `open` u
  symulatora (7 osieroconych).
- **Istniejące zabezpieczenia:** limit `payment_start` (20 / 15 min na adres gościa, IPv6 po /64) i limit składania
  zamówień ograniczają serię z jednego adresu, nie usuwają mechanizmu.
- **Rekomendacja:** (1) wywołanie operatora poza transakcją bazy (zapis `pending` i commit, `createSession`, krótka
  transakcja z `attachProviderSession`); (2) równoległe rozpoczęcia tego samego zamówienia odrzucać od razu
  (`SELECT … FOR UPDATE NOWAIT`, `pg_try_advisory_xact_lock` albo Symfony Lock z `acquire(false)` → 409); (3) symulator
  poza procesem API; (4) limiter z `lock_factory`; (5) jawne `num_threads` w Caddyfile i alert na czas `/health`.
- **Nakład:** M — przebudowa handlera na dwie transakcje z testami współbieżności; samo odrzucanie bez czekania to S i
  usuwa kaskadę.
- **Pewność:** wysoka co do mechanizmu; średnia co do liczby wątków na produkcji.

### SA-04 — Blokowanie towaru i kodów nieopłaconymi zamówieniami gościa

- **Kategoria:** OWASP API6:2023 Unrestricted Access to Sensitive Business Flows; A06:2025 Insecure Design; CWE-770,
  CWE-799.
- **Poziom:** High przy sprzedaży; Medium w demo.
- **Status:** CONFIRMED (mechanizm potwierdzony testem; skala wyliczona z limitów i danych seeda).
- **Niezależny przegląd:** podtrzymane. Skala jest arytmetyką na zmierzonych czasach; w demo wpływ biznesowy Medium.
- **Lokalizacja:** `apps/api/src/Ordering/Application/Command/PlaceOrderHandler.php:130,134`;
  `apps/api/src/Ordering/Domain/Order.php:30-32`; `apps/api/src/Cart/Domain/Cart.php:18-19`;
  `apps/api/src/Ordering/UI/Http/GuestOrderController.php:27`; `apps/api/config/packages/rate_limiter.yaml:17-21`;
  `apps/api/src/Pricing/Application/Command/RedeemDiscountCodeHandler.php:51-57`.
- **Opis:** Złożenie zamówienia rezerwuje towar i zajmuje użycie kodu z limitem w tej samej transakcji, bez płatności,
  konta i potwierdzenia e-maila. Rezerwacja trwa 35 minut (30 min na płatność + 5 min okna na webhook). Jedno zamówienie
  gościa może zarezerwować do 50 wariantów po 99 sztuk. Jedyne ograniczenie to 10 zamówień na 15 minut na adres IP
  (gość) albo konto (klient); nie ma limitu jednocześnie nieopłaconych zamówień ani zarezerwowanych sztuk na osobę,
  e-mail czy adres.
- **Wymagania wstępne ataku:** brak; publiczny `POST /api/v1/orders` (albo BFF `POST /api/checkout`) i jeden adres IP.
- **Scenariusz wykorzystania:** napastnik co 15 minut składa 10 zamówień gościa po 50 linii z całym stanem każdego
  wariantu; w stanie ustalonym aktywnych jest 20–30 zamówień, czyli 1000–1500 zablokowanych wariantów. Katalog demo ma
  1013 wariantów ze stanem ≤ 50 sztuk, więc jeden adres utrzymuje cały sklep jako „niedostępny”. N nieopłaconych
  zamówień z kodem o limicie N wyczerpuje kod.
- **Konsekwencje:** zatrzymanie sprzedaży wybranych produktów albo całego asortymentu, zablokowanie kampanii z
  limitowanym kodem, zaśmiecenie panelu, e-maile w imieniu sklepu (SA-31). Atak jest tani i trudny do odróżnienia od
  porzuconych koszyków.
- **Dowód:** lokalnie, `work/biz/t1_oversell.py`, `t2_code_limit.py`, `t11_expiry_wait.py` (wyniki `out_t*.txt`):
  ```
  before: physical 28 | reserved 0 | available 28
  after:  physical 28 | reserved 28 | available 0
  inny kupujący, POST /api/v1/guest-carts/{id}/items -> 409 "Ten produkt jest chwilowo niedostępny w sprzedaży."
  [after the expiry]     order R-100021: cancelled | payment_timeout | cancelled_at 09:56:38 | stock 28 | 0 | 28
  ```
  Rezerwacja i użycia kodu z limitem 3 trzymane 35 min 1 s bez płatności; w bazie `1013 | 1006` wariantów ze stanem
  1–99. Odpowiedzi 429 nie dało się wywołać (`when@dev`: limit 10 000). Specyfikacja zna ryzyko
  (`docs/features/hardening/spec.md`, D16).
- **Istniejące zabezpieczenia:** limit 10 zamówień / 15 min; rezerwacja wygasa sama po 35 min; koszyki nie rezerwują
  towaru; zamówienia są widoczne w panelu.
- **Rekomendacja:** (1) limit jednocześnie nieopłaconych zamówień na tożsamość (IP, e-mail, telefon, konto); (2) limit
  rezerwowanych sztuk i wartości zamówienia gościa; (3) krótsza rezerwacja przed rozpoczęciem płatności (np. 10 min),
  przedłużana po `StartPayment`; (4) `lock_factory` dla limitera, do rozważenia Turnstile w kasie gościa; (5) alert na
  odsetek stanu zablokowanego przez zamówienia `new`. Punkt odniesienia: OWASP OAT-021 Denial of Inventory; Saleor
  zwalnia alokację nieopłaconych zamówień po konfigurowalnym `expireOrdersAfter`.
- **Nakład:** M — reguła w domenie Ordering i Cart, migracja indeksu, testy współbieżności, zmiana komunikatów sklepu.
- **Pewność:** wysoka.

### SA-05 — Jeden Secret ze wszystkimi hasłami w środowisku każdego poda

- **Kategoria:** OWASP A02:2025 Security Misconfiguration, A06:2025 Insecure Design; API8:2023; CWE-272, CWE-526,
  CWE-250, CWE-522.
- **Poziom:** High (audytorzy uwierzytelniania, frontendu i API ocenili swoje części jako Low).
- **Status:** CONFIRMED (render `prod` i PoC lokalnie na tej samej konstrukcji).
- **Niezależny przegląd:** podtrzymane, High. To mnożnik skutków po przejęciu jednego komponentu, nie samodzielna
  ścieżka ataku.
- **Lokalizacja:** `infra/k8s/charts/shop/templates/_helpers.tpl:49-53` (`shop.envFromSecret`), użyte w `store.yaml:34`,
  `imgproxy.yaml:33`, `varnish.yaml:5`, `keycloak.yaml:35`, `garage.yaml:2`, `postgres.yaml:5`, `rabbitmq.yaml:5`,
  `api.yaml:107,118,139`, `jobs.yaml:34,80,117,166,198,226,280`, `restore.yaml:187`; `templates/secret.yaml:16-34`;
  `infra/k8s/tests/test_secrets.py:19-29`; compose: `env_file: ./.env.local` przy każdej usłudze.
- **Opis:** Sekret `shop-secrets` ma 19 kluczy: hasła Postgresa (superuser i obie bazy), hasło stałego administratora
  realmu `master`, hasło RabbitMQ, token i sekret RPC Garage, klucz S3, klucz i sól imgproxy, sekret klienta OIDC i
  sekret sesji sklepu, sekrety purge/revalidate, klucze i sekrety webhooków płatności. W renderze `prod` 20 kontenerów
  dostaje cały sekret przez `envFrom`, w tym wystawione do internetu `store` (potrzebuje 3 kluczy), `imgproxy` (4),
  `keycloak` (2) i `varnish` (1). Kontenery kopii zapasowej używają już `secretKeyRef` na pojedynczy klucz.
- **Wymagania wstępne ataku:** podatność dająca wykonanie kodu albo odczyt środowiska w jednym z podów (RCE w
  Next.js/React, parser obrazów imgproxy, SSRF/LFI, zrzut środowiska w błędzie). To mnożnik skutków pierwszego błędu,
  nie luka wykorzystywalna bezpośrednio.
- **Scenariusz wykorzystania:** atakujący przejmuje proces sklepu i dostaje hasło superusera Postgresa, hasło
  administratora `master` (dowolne konto panelu, wyłączenie TOTP), administratora RabbitMQ, token Garage i sekrety
  webhooków płatności; brak NetworkPolicy (SA-06) czyni te usługi osiągalnymi.
- **Konsekwencje:** pojedynczy błąd w najmniej zaufanym komponencie oznacza pełne przejęcie danych klientów, tożsamości
  i panelu; po incydencie trzeba wymienić wszystkie sekrety naraz (SA-37).
- **Dowód:** chart `prod`: `containers that get the WHOLE secret shop-secrets (envFrom): 20`
  (`work/infra/render-facts.py`). Lokalnie: 19 sekretów w środowisku `store` (`work/infra/secret-env-names.txt`), 13
  zmiennych wg wzorca w `imgproxy`; PoC `work/infra/poc-lateral-from-store.mjs` (wyłącznie środowisko kontenera
  `store`):
  ```
  rabbitmq shop:<RABBITMQ_DEFAULT_PASS from env of store>: 200 {"name":"shop","tags":["administrator"],...}
  keycloak master admin-cli password grant (admin + KC_BOOTSTRAP_ADMIN_PASSWORD of env): 200 access_token=ISSUED(751 chars)
  keycloak admin API: users of realm backoffice: 200 ["admin","marketing","support","warehouse"]
  ```
- **Istniejące zabezpieczenia:** Sealed Secrets (zakres `strict`); `automountServiceAccountToken: false`; role baz bez
  superusera; sekrety kopii, Brevo i Grafany w osobnych Secretach; żaden sekret nie trafia do zasobów przeglądarki.
- **Rekomendacja:** zastąpić `envFrom` listą `env[].valueFrom.secretKeyRef` per workload (`store`: 3 klucze, `imgproxy`:
  klucz, sól i osobny klucz S3 tylko do odczytu, `varnish`: 1, `keycloak`: 2, `api`/`worker`/joby bez haseł superusera i
  Keycloaka); to samo w compose; test `test_secrets.py` odwrócić na tabelę „workload → dozwolone klucze”; dla Joba
  `keycloak-config` konto serwisowe zamiast stałego administratora.
- **Nakład:** M — jeden helper i ok. 15 wywołań, tabela kluczy w teście, test dymny; bez nowej infrastruktury.
- **Pewność:** wysoka co do konfiguracji; ocena ryzyka zależy od podatności innych usług.

### SA-06 — Brak NetworkPolicy; Redis, Elasticsearch i Mailpit bez uwierzytelnienia

- **Kategoria:** OWASP A02:2025 Security Misconfiguration, A01:2025 Broken Access Control; API8:2023; CWE-306, CWE-923,
  CWE-312.
- **Poziom:** High przy sprzedaży; Medium w demo.
- **Status:** CONFIRMED (render: 0 obiektów `NetworkPolicy`; PoC lokalnie); ryzyko opisane jako zaakceptowane dla demo w
  `docs/adr/backups-observability.md:191-196`.
- **Niezależny przegląd:** podwyższone dla prawdziwej sprzedaży do High (tokeny sesji wszystkich klientów w Redis bez
  hasła, osiągalnym z każdego poda); w demo Medium.
- **Lokalizacja:** `infra/k8s/charts/shop/templates/` (brak `NetworkPolicy`); `redis.yaml:1-18`;
  `elasticsearch.yaml:10-11`; `mailpit.yaml:1-16`; `store.yaml:17-18`; `apps/store/src/auth/session-store.ts:8-10`;
  `scripts/server-bootstrap.sh:150-154`.
- **Opis:** Sieć klastra jest płaska: każdy pod każdej przestrzeni (`shop`, `monitoring`, `argocd`, `kube-system`) łączy
  się z każdą usługą. Redis działa bez hasła i z `protected-mode no`, a trzyma sesje sklepu z jawnymi `accessToken`,
  `refreshToken` i `idToken` oraz liczniki limitów API i logowania; migawki RDB zapisują tokeny na wolumenie.
  Elasticsearch ma wyłączone security, Mailpit (poczta zamówień) nie ma logowania, ruch wychodzący z podów jest
  nieograniczony.
- **Wymagania wstępne ataku:** wykonanie kodu albo SSRF w dowolnym podzie klastra, także w obcym obrazie (Varnish,
  imgproxy, kube-state-metrics, operator Alloy).
- **Scenariusz wykorzystania:** z poda `imgproxy` albo `varnish`, bez poświadczeń: `SCAN session:*` → tokeny klientów;
  `FLUSHALL` → wylogowanie wszystkich i wyzerowanie limitów; zapis do indeksu `products_*` → podmiana treści
  wyszukiwarki; odczyt Mailpita → dane osobowe z zamówień.
- **Konsekwencje:** przejęcie sesji wszystkich zalogowanych klientów i wyciek danych osobowych bez łamania hasła;
  manipulacja katalogiem; obejście limitów nadużyć.
- **Dowód:** chart `prod`: `NetworkPolicy objects: 0`. Lokalnie, `work/infra/poc-lateral-from-store.out`,
  `work/infra/poc-redis-from-varnish.out`:
  ```
  redis PING/requirepass/protected-mode/keyspace: "+PONG | ... requirepass | $0 | ... protected-mode | $2 | no | ... db0:keys=33,..."
  mailpit /api/v1/messages: 200 total=14 first.subject="Potwierdzenie zamówienia R-100007" to=1 recipient(s)
  fields of one session (names only): sub accessToken refreshToken idToken accessTokenExpiresAt refreshTokenExpiresAt
  ```
- **Istniejące zabezpieczenia:** żadna z tych usług nie ma Ingressu ani portu hosta (wszystkie Service to `ClusterIP`);
  RabbitMQ, Garage, Postgres i Keycloak wymagają poświadczeń (ale SA-05); pody nie mają tokenu API Kubernetesa.
- **Rekomendacja:** (1) `NetworkPolicy` z domyślną odmową ruchu przychodzącego w `shop` i regułami z istniejących
  zależności (do `redis` tylko `store` i `api`; do `postgres` tylko `api`, `worker`, `keycloak`, joby; z Traefika tylko
  do `store`, `admin`, `api`, `keycloak`, `imgproxy`), potem egress; k3s egzekwuje polityki wbudowanym kontrolerem; (2)
  Redis `--requirepass` albo ACL; (3) `xpack.security.enabled: true` z kontem aplikacji; (4) Mailpit: SA-36.
- **Nakład:** M — kilkanaście reguł w jednym szablonie, test renderu i test dymny; hasło Redisa to zmiana w trzech
  miejscach.
- **Pewność:** wysoka.

### SA-07 — Kopie zapasowe wyłączone w `prod`; klucz z klastra może skasować kopie

- **Kategoria:** OWASP A02:2025 Security Misconfiguration (odporność); CWE-693, CWE-284, CWE-1188.
- **Poziom:** Medium przy sprzedaży; Low w demo.
- **Status:** CONFIRMED (konfiguracja w repo; część o kasowaniu kopii to ryzyko udokumentowane w ADR).
- **Niezależny przegląd:** podtrzymane; w demo Low (dane jednorazowe), przy sprzedaży Medium.
- **Lokalizacja:** `infra/k8s/environments/prod/values.yaml:73-78`; `infra/k8s/charts/shop/values.yaml:143` (retencja
  `7d`); `charts/shop/files/scripts/backup.sh:140`; `charts/shop/templates/00-alerts.yaml`;
  `docs/adr/backups-observability.md:155-156`.
- **Opis:** (a) `backup.enabled: false`, a adres repozytorium to wypełniacz: render `prod` nie zawiera CronJobów
  `backup`, `backup-verify`, `restore` ani czterech alertów kopii, więc brak kopii niczego nie alarmuje (komentarz w
  pliku: stan przejściowy do założenia bucketu). Jedyną kopią jest backup VPS-a u tego samego dostawcy. (b) Po włączeniu
  klucz S3 z klastra ma prawo usuwania (`restic forget … --prune`), retencja to 7 dni, bez Object Lock i wersjonowania:
  kto przejmie klaster, kasuje wszystkie kopie. (c) Zrzuty baz leżą nieszyfrowane na wolumenie `backup-work` między
  przebiegami.
- **Wymagania wstępne ataku:** (a) awaria albo utrata VPS-a lub dysku, błędna migracja; (b) przejęcie klastra albo
  węzła.
- **Scenariusz wykorzystania:** atakujący po przejęciu węzła szyfruje wolumeny i jednym poleceniem usuwa repozytorium w
  R2; albo dziś pada dysk i nie ma kopii danych poza dostawcą.
- **Konsekwencje:** nieodwracalna utrata zamówień, kont klientów, realmów Keycloaka i zdjęć; przy prawdziwych klientach
  także naruszenie obowiązku odtwarzalności danych osobowych (RODO art. 32).
- **Dowód:** chart `prod`: `CronJobs = ['cart-purge-expired']`,
  `alerts = ['NodeDiskFull', 'ClusterSilent', 'WorkloadNotReady']`; dopiero `--set backup.enabled=true` daje `backup`,
  `backup-verify`, `restore` (`work/infra/render/prod.ndjson` i `prod-backup.ndjson`).
- **Istniejące zabezpieczenia:** mechanizm jest dojrzały, gdy działa: szyfrowanie restic, osobny dostawca, codzienna
  weryfikacja z odtworzeniem, alerty `Backup*`, odtwarzanie ćwiczone w teście dymnym, hasło restic także poza serwerem.
- **Rekomendacja:** przed prawdziwymi danymi włączyć kopie (runbook 3.3–3.5) i dodać test w `check`, że `prod` ma kopie
  i prawdziwy adres; do tego czasu alert „kopie wyłączone”. Niezmienność: blokada obiektów w R2 albo `forget --prune`
  spoza klastra osobnym kluczem. Dłuższa retencja; usuwanie `dumps/` po `snapshot`.
- **Nakład:** S (włączenie, test, alert; bucket i sekret to pozycja użytkownika) + M (rozdzielenie uprawnień).
- **Pewność:** wysoka.

### SA-08 — Brak polityki haseł w realmach `shop` i `backoffice`

- **Kategoria:** OWASP A07:2025 Authentication Failures; API2:2023 Broken Authentication; CWE-521.
- **Poziom:** Medium przy sprzedaży; Low w demo.
- **Status:** CONFIRMED.
- **Niezależny przegląd:** podtrzymane; przy `customerAccounts: false` nie dotyczy obecnego demo (rejestracja
  wyłączona, personel zakłada `create-admin`).
- **Lokalizacja:** `infra/keycloak/realms/shop.json:1-20`, `infra/keycloak/realms/backoffice.json:1-25` (brak
  `passwordPolicy`); `infra/k8s/charts/shop/templates/_keycloak.tpl:25-29`, `files/scripts/keycloak-config.sh:94` (job
  nie ustawia polityki).
- **Opis:** Żaden realm nie definiuje `passwordPolicy`, więc Keycloak przyjmuje dowolne niepuste hasło: bez minimalnej
  długości, listy haseł zabronionych i zakazu hasła równego loginowi. Dotyczy rejestracji klientów, resetu hasła i haseł
  pracowników ustawianych z linku `create-admin`.
- **Wymagania wstępne ataku:** rejestracja publiczna przy `customerAccounts: true` (domyślne w chartcie; w obecnym
  `prod` `false`); dla ataku na cudze konto e-mail ofiary.
- **Scenariusz wykorzystania:** klienci zakładają konta z hasłami typu `1`, `123456`; atakujący zgaduje je online (ok.
  30 prób na cykl blokady, SA-09) albo robi credential stuffing.
- **Konsekwencje:** przejęcie kont klientów (dane z historii zamówień, zamówienia na cudze konto); w `backoffice` skutek
  ogranicza obowiązkowe TOTP.
- **Dowód:** lokalnie, dwa niezależne testy: `work/auth/t05_register.py` (hasło `a`) i `work/priv/02_customer.py` (hasło
  `1`):
  ```
  register auth-weak-c70f14@example.com with password 'a' -> 302 .../login-actions/required-action?execution=VERIFY_EMAIL
  login with the 1-character password -> [303, 200, 302, 303] {"name":"Audyt Auth","email":"auth-weak-c70f14@example.com"}
  ```
  Konfiguracja obu realmów: `'passwordPolicy': None, 'bruteForceProtected': True, 'failureFactor': 30`.
- **Istniejące zabezpieczenia:** weryfikacja e-mail przy rejestracji; detektor brute force per konto; TOTP w
  `backoffice`; skrót argon2id.
- **Rekomendacja:** natywna polityka w obu realmach, np.
  `length(12) and notUsername and notEmail and passwordBlacklist(<plik>)`, dla `backoffice` także `passwordHistory(5)`;
  wpisać ją też do `<realm>.settings.json` joba, bo istniejący realm nie przyjmuje zmian z pliku (SA-10). Test:
  rejestracja z hasłem `a` kończy się błędem.
- **Nakład:** S — jedna linia w dwóch plikach realmów i w szablonie joba, test E2E/kcadm.
- **Pewność:** wysoka.

### SA-09 — Brak limitu prób na endpointach Keycloaka; ROPC przez `admin-cli`; zalew maili resetu

- **Kategoria:** OWASP A07:2025 Authentication Failures; API2:2023, API4:2023; CWE-307, CWE-799.
- **Poziom:** Medium przy sprzedaży; Low w demo.
- **Status:** CONFIRMED.
- **Niezależny przegląd:** podtrzymane i doprecyzowane: token z `admin-cli` nie daje dostępu do API (`/api/v1/me` →
  401), to wyrocznia haseł omijająca limit BFF; realm `backoffice` wymaga dodatkowo OTP.
- **Lokalizacja:** `apps/store/app/auth/login/route.ts:16`, `apps/store/src/auth/login-rate-limit.ts:48-52`;
  `infra/k8s/charts/shop/templates/ingress.yaml:13`; klient domyślny `admin-cli` (`directAccessGrantsEnabled: true`)
  tworzony przez Keycloak w obu realmach (nie ma go w plikach realmów).
- **Opis:** Jedyny własny limit logowań (30 startów na adres na 10 min) stoi przed `GET /auth/login` w BFF i chroni
  Redis. Hasła sprawdza Keycloak, dostępny publicznie i bez limitu per adres: (1) formularz logowania można wywoływać z
  pominięciem BFF; (2) `admin-cli` wystawia w obu realmach `grant_type=password`, bez formularza i PKCE; (3) reset hasła
  i rejestracja nie mają limitu ani CAPTCHA, a każde żądanie wysyła e-mail. Ochroną jest tylko detektor per konto
  (`failureFactor 30`, blokada 60 s rosnąca do 15 min), który nie widzi ataku „mało prób na wiele kont”.
- **Wymagania wstępne ataku:** brak; lista e-maili i haseł z wycieków albo e-mail ofiary.
- **Scenariusz wykorzystania:** (a) credential stuffing przez ROPC: jedna para na konto, tysiące kont na minutę z
  jednego adresu; (b) zgadywanie na jedno konto: rzędu 2–3 tys. prób na dobę; (c) zalew skrzynki ofiary e-mailami
  resetu.
- **Konsekwencje:** przejęcia kont klientów na skalę zależną od jakości haseł (SA-08); koszty i reputacja poczty
  (Brevo); brak sygnału w aplikacji.
- **Dowód:** lokalnie, `work/auth/t07_bruteforce.py`, `t08_bruteforce_spaced.py`, `t10_reset_flood.py`:
  ```
  ROPC admin-cli (shop realm), correct password, lock expired -> 200 ['access_token','expires_in',...,'refresh_token',...]
  40 wrong guesses in 0.8s with no throttling by address               # wszystkie 400, żadnego 429
  8 reset requests in 0.2s, statuses [200 x8]; reset mails delivered to the account's mailbox: 8
  ```
  Lokalnie `STORE_LOGIN_RATE_LIMIT=100000`; produkcja używa domyślnych 30 i `clientIpHeader: CF-Connecting-IP`.
- **Istniejące zabezpieczenia:** detektor brute force (blokada po 30 próbach potwierdzona); ROPC w `backoffice` wymaga
  OTP; tokeny `admin-cli` nie mają audience API (401); Cloudflare przed originem (reguł rate limiting nie ma w repo).
- **Rekomendacja:** (1) wyłączyć `directAccessGrantsEnabled` na `admin-cli` i nieużywane klienty domyślne (krok `kcadm`
  w jobie); (2) limit per adres dla `auth.<domena>` na `/protocol/openid-connect/token` i `/login-actions/*` (middleware
  Traefika `rateLimit` po `CF-Connecting-IP` albo reguła Cloudflare w runbooku); (3) reCAPTCHA/Turnstile w flow
  rejestracji i resetu; (4) do rozważenia `permanentLockout` dla `backoffice`.
- **Nakład:** M — zmiana joba i chartu (middleware, test); CAPTCHA wymaga kluczy i testu E2E.
- **Pewność:** wysoka (brak limitu potwierdzony lokalnie; skuteczność ewentualnych reguł Cloudflare nieznana).

### SA-10 — Ustawienia bezpieczeństwa realmów z repo nie trafiają do istniejącego realmu

- **Kategoria:** OWASP A02:2025 Security Misconfiguration; CWE-1188.
- **Poziom:** Low.
- **Status:** HARDENING.
- **Niezależny przegląd:** podtrzymane, Low.
- **Lokalizacja:** `infra/k8s/charts/shop/files/scripts/keycloak-config.sh:81-94`;
  `infra/k8s/charts/shop/templates/_keycloak.tpl:25-29`.
- **Opis:** Dla istniejącego realmu job robi `partialImport` z `ifResourceExists=SKIP` i nadpisuje tylko SMTP,
  `registrationAllowed`, `verifyEmail` oraz adresy i sekret klientów. Każde inne ustawienie pliku realmu (brute force,
  czasy sesji, polityka haseł, PKCE, `directAccessGrantsEnabled`) działa tylko przy pierwszym utworzeniu realmu.
- **Wymagania wstępne ataku:** brak ataku; ryzyko procesowe.
- **Scenariusz wykorzystania:** naprawa SA-08, SA-09 albo SA-26 wyłącznie w pliku realmu przechodzi review i CI, a
  produkcja zostaje przy starej wartości.
- **Konsekwencje:** dryf konfiguracji bezpieczeństwa między repo a produkcją i fałszywe poczucie naprawy.
- **Dowód:** skrypt joba (lokalizacja powyżej); zachowanie opisane w `CLAUDE.md` i testowane
  (`infra/k8s/tests/test_keycloak_config.py`).
- **Istniejące zabezpieczenia:** job przy każdym uruchomieniu ustawia adresy i sekret klientów (zmiana `redirectUris`
  dotrze na produkcję).
- **Rekomendacja:** przenieść ustawienia bezpieczeństwa do `<realm>.settings.json` (job robi
  `update realms/<realm> --merge`) i flagi klientów do `<realm>.client.<id>.json`; test dymny porównujący
  `kcadm get realms/<realm>` z plikiem realmu.
- **Nakład:** M — rozszerzenie szablonu i testów joba, test dymny na k3d.
- **Pewność:** wysoka.

### SA-11 — Keycloak nie zapisuje zdarzeń logowania ani administracyjnych

- **Kategoria:** OWASP A09:2025 Security Logging & Alerting Failures; CWE-778.
- **Poziom:** Medium.
- **Status:** CONFIRMED.
- **Lokalizacja:** `infra/keycloak/realms/shop.json`, `infra/keycloak/realms/backoffice.json` (brak `eventsEnabled`,
  `adminEventsEnabled`; bez numerów linii w źródle); `infra/k8s/charts/shop/files/scripts/keycloak-config.sh`.
- **Opis:** W obu realmach `eventsEnabled=false` i `adminEventsEnabled=false`. Jedyny listener to `jboss-logging`: błędy
  logowania trafiają na stdout jako `WARN`, udane logowania tylko na poziomie `DEBUG`, czyli nigdzie. Zdarzenia
  administracyjne (utworzenie konta personelu, nadanie roli `admin`, reset hasła, usunięcie TOTP, zmiana klienta) nie są
  rejestrowane.
- **Wymagania wstępne ataku:** dostęp do konsoli albo API administracyjnego Keycloaka (konto bootstrap, `kcadm` w
  podzie) lub przejęte konto personelu.
- **Scenariusz wykorzystania:** osoba z dostępem do Keycloaka nadaje wspólnikowi rolę `admin` w `backoffice`, ten
  wykonuje operacje w panelu, rola znika; nie da się ustalić, kto i kiedy ją nadał. Przy przejęciu konta klienta nie da
  się wskazać udanych logowań napastnika.
- **Konsekwencje:** brak materiału do odtworzenia incydentu na warstwie tożsamości i do oceny, czy doszło do naruszenia
  danych (art. 33 RODO).
- **Dowód:** lokalnie: `GET /admin/realms/{shop,backoffice}/events/config` → `{'eventsEnabled': False,
  'eventsListeners': ['jboss-logging'], 'adminEventsEnabled': False, …}`; po testach wszystkich audytorów `event_entity`
  i `admin_event_entity` mają 0 i 0 wierszy.
- **Istniejące zabezpieczenia:** nieudane logowania są w logu poda wysyłanym do Loki (retencja 14 dni wg runbooka;
  `username` i IP maskowane, `userId` zostaje); `master` i konsola poza ingressem.
- **Rekomendacja:** w plikach realmów i w `<realm>.settings.json` joba: `eventsEnabled: true`, `eventsExpiration` (np.
  90 dni), `enabledEventTypes` (logowania, rejestracje, zmiany hasła i TOTP oraz ich błędy), `adminEventsEnabled` i
  `adminEventsDetailsEnabled: true`.
- **Nakład:** S — konfiguracja realmów i krok joba (mechanizm natywny, bez nowej infrastruktury).
- **Pewność:** wysoka.

### SA-12 — Brak limitu rozmiaru i czasu ciała żądania w API i w BFF sklepu

- **Kategoria:** OWASP API4:2023 Unrestricted Resource Consumption; CWE-770, CWE-400.
- **Poziom:** Medium.
- **Status:** CONFIRMED (lokalnie: brak limitu, błąd pamięci przy 150 MB, blokada API 40 wolnymi połączeniami, 30 MB
  przyjęte przez BFF); skutek na produkcji (OOM poda) LIKELY, nie testowany.
- **Niezależny przegląd:** podtrzymane, Medium. Błąd pamięci przy 150 MB to zachowanie trybu `dev`; Cloudflare (limit
  ciała, buforowanie) łagodzi, nie usuwa.
- **Lokalizacja:** API: `apps/api/docker/frankenphp/Caddyfile:7-11`, `apps/api/docker/php/conf.d/app.ini:9-10`
  (`post_max_size = 70M`; `memory_limit = 256M` w linii 2). BFF: `apps/store/src/cart/bff.ts:51-58`,
  `app/api/cart/items/route.ts:10`, `app/api/cart/items/[variantId]/route.ts:17`, `src/checkout/bff.ts:55`,
  `src/checkout/payment-bff.ts:102`. Wspólne: `infra/k8s/charts/shop/templates/middlewares.yaml` (brak `buffering`),
  `ingress.yaml:9-14`.
- **Opis:** W API jedyny limit to `post_max_size = 70M` (pod upload zdjęć), który dotyczy tylko ciał formularzy:
  `application/json` jest czytane w całości do pamięci (ok. 120 MB na żądanie do granicy `memory_limit`). Caddy nie ma
  limitów czasu odczytu ciała, a wątków PHP jest 2 × CPU, więc wolne ciała trzymają wątki bez końca. W BFF handlery
  zapisujące buforują i parsują całe ciało JSON bez sprawdzenia `Content-Length`; Next i ingress Traefika nie nakładają
  limitu, a wymagany `Origin` i `Content-Type` klient spoza przeglądarki ustawia dowolnie.
- **Wymagania wstępne ataku:** żadne (publiczne endpointy koszyka gościa, zamówień, webhooków; w BFF bez sesji i
  koszyka).
- **Scenariusz wykorzystania:** (a) 8–10 równoległych żądań z JSON-em 100 MB: przy limicie poda `api` 2 Gi i 16 wątkach
  kernel zabija jedyną replikę; (b) tyle wolnych połączeń, ile wątków, blokuje każde żądanie, także `/health`; (c)
  kilka–kilkanaście `POST /api/checkout` po 100 MB zabija proces sklepu (limit 1536 Mi, jedna replika).
- **Konsekwencje:** niedostępność całego API albo frontu sklepu kosztem ok. 1 GB wysłanych danych; strony z cache
  działają, zakup nie.
- **Dowód:** lokalnie, `work/api/bodysize_poc.sh`, `work/api/slow_body_poc.py`, `work/fe/body_limit.sh`:
  ```
  JSON 110 MB -> 404 application/problem+json 0.58s
  JSON 150 MB -> 200 text/html  <b>Fatal error</b>: Allowed memory size of 268435456 bytes exhausted
  with 40 slow bodies open (2 s): /health -> timeout in 4.00s
  /api/checkout         422 time=0.19s uploaded=31457319
  ```
  Treść błędu PHP w odpowiedzi to zachowanie tylko `APP_ENV=dev`; 12 wolnych połączeń (poniżej 32 wątków lokalnie) nie
  blokuje `/health`.
- **Istniejące zabezpieczenia:** Cloudflare buforuje ciała przed originem (niezweryfikowane) i ogranicza żądanie do 100
  MB; `memory_limit` na żądanie; głębokość JSON 512; BFF bez poprawnego `Origin` odrzuca przed odczytem ciała.
- **Rekomendacja:** w `Caddyfile` `request_body { max_size 1MB }` z wyjątkiem dla `/api/v1/admin/product-images` (70 MB)
  i `timeouts` (`read_header 10s`, `read_body 30s`); w `readJsonObject` 413 powyżej kilkunastu KB i odczyt strumienia z
  limitem; middleware Traefika `buffering.maxRequestBodyBytes`; jawne `num_threads`. Notatki researchu odnotowują
  GHSA-j45x-h238-h4xx (FrankenPHP 1.13.0–1.13.4: `request_body_timeout` nie zatrzymuje wolnych ciał), więc limit czasu
  wdrożyć z aktualizacją do 1.13.5.
- **Nakład:** S — kilka linii konfiguracji, jedna funkcja wspólna dla czterech handlerów, jeden middleware i testy.
- **Pewność:** wysoka co do braku limitu; średnia co do skutku OOM i liczby potrzebnych żądań.

### SA-13 — Nieograniczony zapis cache ISR na dysk dla dowolnych adresów `/p/…`

- **Kategoria:** OWASP A06:2025 Insecure Design; API4:2023 Unrestricted Resource Consumption; CWE-400, CWE-770.
- **Poziom:** Medium.
- **Status:** CONFIRMED (mechanizm i tempo wzrostu); skutek „awaria węzła” LIKELY (nie testowano destrukcyjnie).
- **Niezależny przegląd:** podtrzymane, Medium; za Cloudflare wektor jest wolniejszy.
- **Lokalizacja:** `apps/store/app/p/[slug]/page.tsx:10`, `:12`, `:38`; `apps/store/Dockerfile:48-52`;
  `infra/k8s/charts/shop/templates/_helpers.tpl:233-282` (brak limitu `ephemeral-storage`); `templates/ingress.yaml`
  (brak limitu żądań).
- **Opis:** Strona produktu jest ISR renderowanym przy pierwszym wejściu (`revalidate = 3600`, pusty
  `generateStaticParams`). Next zapisuje wynik każdego adresu, także 404 dla nieistniejącego sluga, w pamięci (LRU) i na
  dysku w `.next/server/route-cache/`. Slug nie jest sprawdzany przed renderem, wpisów nikt nie usuwa, a jeden wpis 404
  zajmuje ok. 88 KB; każde chybienie to też odczyt produktu z API.
- **Wymagania wstępne ataku:** żadne; anonimowe GET. Cloudflare domyślnie nie buforuje HTML ani nie ogranicza liczby
  żądań (reguły poza repo).
- **Scenariusz wykorzystania:** skrypt wysyła `GET /p/<losowy-ciąg>`; 100 żądań/s to ok. 9 MB/s, czyli ok. 30 GB na
  godzinę. Na jednowęzłowym k3s dysk jest wspólny z wolumenami PostgreSQL, Elasticsearcha, RabbitMQ i Garage.
- **Konsekwencje:** zapełnienie dysku węzła (`DiskPressure`, eksmisja podów, błędy zapisu baz) albo restart sklepu w
  pętli. Mechanizm (ok. 150 KB na istniejący produkt) nie skaluje się też do docelowych 10 mln produktów
  (`docs/vision.md:228`).
- **Dowód:** lokalnie, `work/fe/isr_growth.sh`:
  ```
  before=1232KB after=5632KB delta=4400KB for 50 unique slugs in 2s => 88KB per slug
  HTTP/1.1 404 ... x-nextjs-cache: HIT ... Cache-Control: s-maxage=3600, stale-while-revalidate=31532400
  ```
- **Istniejące zabezpieczenia:** pamięciowa część cache jest LRU; nazwy plików zachowują kodowanie procentowe (brak
  wyjścia z katalogu); origin przyjmuje ruch tylko z Cloudflare, ale repo nie wymaga reguły limitu.
- **Rekomendacja:** (1) własny `cacheHandler` w `next.config.ts` z ograniczonym magazynem (Redis z TTL albo LRU z
  limitem), bez trwałych wpisów 404; (2) do tego czasu `resources.limits.ephemeral-storage` dla poda `store` i reguła
  rate limit w Cloudflare na `/p/*` zapisana w runbooku; (3) odrzucać slugi spoza `isProductSlug` przed renderem.
- **Nakład:** M — `cacheHandler` z testem integracyjnym i zmianą chartu; sam limit `ephemeral-storage` to S.
- **Pewność:** wysoka (wzrost zmierzony); średnia co do przebiegu awarii na węźle.

### SA-14 — Podgląd kodu rabatowego jest wyrocznią bez limitu prób

- **Kategoria:** OWASP API6:2023, API4:2023; A06:2025 Insecure Design; CWE-307, CWE-204, CWE-799.
- **Poziom:** Medium.
- **Status:** CONFIRMED.
- **Niezależny przegląd:** podtrzymane, Medium. Dowód odtworzony ponownie przez koordynatora (300 prób w 6,1 s, oba
  kody seeda znalezione, brak nagłówków limitu).
- **Lokalizacja:** `apps/api/src/Cart/UI/Http/GuestCartController.php:65`, `CustomerCartController.php:41`;
  `apps/store/app/api/cart/route.ts:9-10`; `apps/api/src/Pricing/Application/Query/QuoteBasketHandler.php:55-63`;
  `apps/api/src/Pricing/Domain/DiscountCode/DiscountCodeRejection.php:53-60`;
  `apps/api/src/Cart/Application/View/RejectedCartDiscount.php:8-21`;
  `apps/api/src/Pricing/Domain/DiscountCode/DiscountCodeValue.php:13-15`;
  `apps/api/config/packages/rate_limiter.yaml:14-33`.
- **Opis:** Podgląd kodu to zwykły odczyt koszyka (`GET …?discountCode=`, bez logowania), bez limitu prób w API i w BFF.
  Odpowiedź rozróżnia kod nieistniejący lub nieaktywny (`invalid`) od istniejącego: `applied` z kwotą i rodzajem rabatu,
  `used_up`, `below_minimum` z progiem. Kody są krótkie (od 3 znaków) i słownikowe, wielkość liter nie ma znaczenia, nie
  ma limitu użyć na klienta, a kod łączy się z promocją.
- **Wymagania wstępne ataku:** brak; własny koszyk gościa z jedną pozycją.
- **Scenariusz wykorzystania:** słownik typowych kodów kampanii albo pełne przeszukanie krótkich kodów, ok. 50 prób na
  sekundę. Znaleziony kod można stosować bez limitu i rozpowszechnić; kod z limitem można wyczerpać (SA-04).
- **Konsekwencje:** utrata marży, ujawnienie planu promocji (kody, wartości, progi), spalenie kampanii limitowanych i
  kodów dla wąskich grup.
- **Dowód:** lokalnie, `work/biz/t9_code_guessing.py`, `work/biz/t14_bff.py`, `work/api/ratelimit_poc.sh`:
  ```
  active                  -> {"status":"applied","code":"WITAJ10","amountGross":5990,"kind":"percent","value":10}
  300 requests in 6.3 s: {(200,'rejected','invalid'): 298, (200,'applied',None): 2}
  codes found: ['MINUS50', 'WITAJ10']      (słownik 80 haseł)
  BFF: 120 prób przez GET /api/cart?discountCode= w 2.5 s: 118 rejected, 2 applied, zero 429
  ```
  Osobna seria 300 prób `KOD1..KOD300` w 15 s: 300/300 bez 429. Specyfikacja to przyznaje
  (`docs/features/discount-codes/spec.md:469`).
- **Istniejące zabezpieczenia:** nieaktywny, wygasły, zaplanowany i nieznany kod dają tę samą odpowiedź; rabat ≤ 90%;
  złożenie zamówienia ma limit 10/15 min; użycia widoczne w panelu.
- **Rekomendacja:** limiter Symfony dla odczytu koszyka tylko z `discountCode` (warunek w `RateLimitSubscriber`), tak
  samo dla trasy gościa i klienta, z obsługą 429 w BFF; audytorzy proponują 10 prób / 10 min albo 20 prób / 15 min na
  adres i koszyk. Uzupełniająco kody o dużej entropii dla kampanii zamkniętych i alert na serie `invalid`. Punkt
  odniesienia: Adobe Commerce wymienia „Coupon Codes” wśród formularzy chronionych reCAPTCHA; domyślne limitery Shopware
  6.7 nie obejmują kodów.
- **Nakład:** S — jeden limiter, warunek w subskrybencie, obsługa 429 w BFF, testy jak `RateLimitEndpointTest`.
- **Pewność:** wysoka.

### SA-15 — Nieograniczone tworzenie koszyków gościa

- **Kategoria:** OWASP API4:2023 Unrestricted Resource Consumption; CWE-770.
- **Poziom:** Medium.
- **Status:** CONFIRMED (brak limitu); zapełnienie wolumenu w produkcji nie testowane.
- **Niezależny przegląd:** podtrzymane, Medium.
- **Lokalizacja:** `apps/api/src/Cart/UI/Http/GuestCartController.php:43`;
  `apps/api/src/Cart/Application/Command/CreateGuestCartHandler.php:26-32`; `apps/api/config/packages/security.yaml`
  (`^/api/v1/guest-carts` jako `PUBLIC_ACCESS`); `infra/k8s/charts/shop/values.yaml:162` (`postgres: 10Gi`).
- **Opis:** Każdy `POST /api/v1/guest-carts` zapisuje wiersz `cart_cart` z `expires_at` = +30 dni; do koszyka można
  dodać 50 linii. Nie ma limitu żądań, CAPTCHA ani powiązania z sesją sklepu, a API jest wystawione bezpośrednio pod
  `api.<domena>`. CronJob `cart:purge` usuwa tylko koszyki po terminie.
- **Wymagania wstępne ataku:** żadne.
- **Scenariusz wykorzystania:** skrypt tworzy koszyki i linie z kilku adresów (25–100/s na klienta); po dobie to miliony
  wierszy, które żyją 30 dni.
- **Konsekwencje:** wzrost tabel i indeksów, dłuższe kopie i czyszczenie; przy wytrwałym ataku zapełnienie wolumenu
  Postgresa (10 Gi, wspólny z zamówieniami i katalogiem), czyli zatrzymanie sklepu.
- **Dowód:** lokalnie, `work/api/ratelimit_poc.sh`:
  ```
  carts before: 25
  300 201  <- create guest cart (12 s)
  carts after: 371          # 300 z tej serii, reszta z równoległych testów
  ```
- **Istniejące zabezpieczenia:** wiersz koszyka jest mały; limit 50 linii i 99 sztuk; czyszczenie co godzinę; Cloudflare
  (reguły rate limiting poza repo).
- **Rekomendacja:** limiter na `POST /api/v1/guest-carts` i `POST …/items` kluczem `guest:<adres>` (np. 30 koszyków i
  300 zmian na 15 min); krótszy TTL pustego koszyka albo tworzenie wiersza dopiero przy pierwszej linii.
- **Nakład:** S (limiter) / M (leniwe tworzenie koszyka).
- **Pewność:** wysoka co do braku limitu; średnia co do wpływu na dostępność.

### SA-16 — Brak stałej kontroli podatności zależności i obrazów w CI

- **Kategoria:** OWASP A03:2025 Software Supply Chain Failures; CWE-1104, CWE-1395.
- **Poziom:** Medium.
- **Status:** CONFIRMED.
- **Lokalizacja:** `.github/workflows/ci.yml` (brak kroku skanującego); brak `.github/dependabot.yml` i Renovate;
  ustawienia repozytorium: `dependabot_security_updates: disabled`, `vulnerability-alerts: 404`, code scanning
  `not-configured`.
- **Opis:** Pipeline CI (`make check`, testy, E2E) nie uruchamia `composer audit`, `npm audit` ani skanera obrazów;
  Dependabot, alerty podatności i code scanning są wyłączone. Efekt jest widoczny: obraz Keycloaka ma 7 miesięcy i
  krytyczną lukę (SA-01), a lockfile Symfony jest o wersję patch za advisories z 2026-11-04 (SA-17), i nic tego nie
  sygnalizuje.
- **Wymagania wstępne ataku:** brak; to brak mechanizmu, nie pojedyncza podatność.
- **Scenariusz wykorzystania:** nowa CVE w zależności lub obrazie bazowym pozostaje niezauważona do ręcznego przeglądu;
  podatna paczka wchodzi z PR bez ostrzeżenia.
- **Konsekwencje:** dług bezpieczeństwa narasta po cichu; podatności trafiają i zostają na produkcji.
- **Dowód:** `gh api` na ustawieniach repozytorium → `dependabot_security_updates.status=disabled`,
  `…/vulnerability-alerts` → `404 Not Found`; `ci.yml` bez kroku audit/trivy (grep).
- **Istniejące zabezpieczenia:** secret scanning i push protection (chronią przed wyciekiem sekretów, nie przed
  podatnymi paczkami); obrazy i akcje przypięte.
- **Rekomendacja:** (1) Dependabot (composer, npm ×3, docker, github-actions) z security updates i alertami; (2)
  `composer audit` + `npm audit --omit=dev` w `make/*.mk`, blokujące tylko High/Critical w zależnościach produkcyjnych;
  (3) `trivy image` i `trivy config` w `CI_AFTER_E2E` albo okresowo, blokujące tylko Critical naprawialne; (4) CodeQL
  default setup.
- **Nakład:** S — alerty i Dependabot to ustawienia; krok audit to kilka linii.
- **Pewność:** wysoka.

### SA-17 — Symfony 8.1.9/8.1.10 z advisories z 2026-11-04

- **Kategoria:** OWASP A03:2025 Software Supply Chain Failures; CWE-1395; poszczególne: CWE-915, CWE-73/CWE-22, CWE-20.
- **Poziom:** Low (zgłoszone jako Medium, obniżone po przeglądzie).
- **Status:** LIKELY.
- **Niezależny przegląd:** obniżone do Low. DTO z `#[MapRequestPayload]` są płaskie i `readonly`, bez
  `OBJECT_TO_POPULATE`; żądanie z kluczem z kropką na koszyku gościa dało 500 bez zmiany stanu, więc CVE-2026-122053
  nie pozwala tu ustawić pól. Koordynator sprawdził dodatkowo, że treść YAML, XML i CSV jest odrzucana kodem 415
  (`POST /api/v1/guest-carts/{id}/items`), więc advisory parsera YAML nie jest osiągalne tą drogą. Aktualizacja do
  8.1.11 pozostaje zalecana.
- **Lokalizacja:** `apps/api/composer.lock` (`symfony/*` w 8.1.9–8.1.10), `apps/api/composer.json` (`"8.1.*"`).
- **Opis:** `composer audit --locked` zwraca 19 advisories na paczkach Symfony, wszystkie z 2026-11-04 i naprawione w
  8.1.11. Audytor zweryfikował osiągalność każdego (część G). Realne dziś jest przede wszystkim CVE-2026-122053
  (GHSA-hqp2-7rr8-hf26, klucze z kropką w `ObjectNormalizer`): 25 kontrolerów używa `#[MapRequestPayload]`. Advisories
  Serializera o odczycie pliku wymagają DTO z własnością typu `UploadedFile`/`SplFileInfo`, którego dziś nie ma;
  pozostałe są nieosiągalne w tej konfiguracji.
- **Wymagania wstępne ataku:** żądanie do kontrolera z `#[MapRequestPayload]` wiążącego obiekt z zagnieżdżeniem.
- **Scenariusz wykorzystania:** żądanie z kluczem w rodzaju `"customer.admin": true` obok `"customer": {...}` omija
  `#[Ignore]` albo ustawia nieprzewidziane pole zagnieżdżone.
- **Konsekwencje:** według advisory zapis nieprzewidzianych pól zagnieżdżonych albo wywołanie publicznej metody;
  w tym projekcie, po przeglądzie, najwyżej odpowiedź 500 na żądanie z kluczem z kropką (patrz „Niezależny
  przegląd”). Pozostałe pozycje to zapas na przyszłość.
- **Dowód:** `composer audit --locked` (19 advisories z naprawą `8.1.11`; `work/sc/composer-audit*.json`);
  `grep -rl MapRequestPayload apps/api/src` → 25 plików. Lokalnie obalono obejścia autoryzacji z tej serii: `HEAD` na
  trasach chronionych → 401, `/index.phpapi/v1/admin/me` → 401, `//evil.example/index.php/...` → 404.
- **Istniejące zabezpieczenia:** stateless OIDC na trasach chronionych; reguły chroniące w `access_control` bez
  `methods:`; `http_method_override: false`; brak ScopingHttpClient, X509 i ESI.
- **Rekomendacja:** `composer update "symfony/*" --with-all-dependencies` do 8.1.11 (bez zmian w kodzie) i commit
  lockfile; kontrola `composer audit` w CI (SA-16).
- **Nakład:** S — aktualizacja patch w ramach `8.1.*`.
- **Pewność:** wysoka co do wersji i braku wstrzyknięcia pól przy obecnych DTO (test reviewera); średnia co do
  pozostałych advisories, ocenionych statycznie.

### SA-18 — Błędny token Bearer tworzy zdarzenie Sentry

- **Kategoria:** OWASP A09:2025 Security Logging & Alerting Failures; API4:2023; CWE-779, CWE-400.
- **Poziom:** Medium.
- **Status:** CONFIRMED.
- **Niezależny przegląd:** podtrzymane, Medium (dla demo i sprzedaży).
- **Lokalizacja:** `apps/api/config/packages/monolog.yaml:10-13`; `apps/api/config/services.yaml:232-237`;
  `vendor/symfony/security-http/AccessToken/Oidc/OidcTokenHandler.php:126` (wywołanie z
  `apps/api/src/Shared/Infrastructure/Security/KeySourceOutageTokenHandler.php:30`).
- **Opis:** Handler Monolog `sentry` zamienia każdy log `error` z kanału `app` w zdarzenie Sentry, a `OidcTokenHandler`
  przy każdym tokenie, którego nie da się zweryfikować, loguje `error` na tym kanale. Kontekst zawiera `exception` jako
  nazwę klasy, nie `Throwable`, więc warunek pomijania w `LogToSentryIssueHandler` nie działa. Kryterium specyfikacji
  Sentry „401 nie daje zdarzeń” jest prawdziwe tylko dla żądania bez tokenu.
- **Wymagania wstępne ataku:** brak; dowolny klient HTTP. `/api/v1/me` i `/api/v1/admin/*` nie mają limitu żądań.
- **Scenariusz wykorzystania:** pętla żądań z `Authorization: Bearer x`; kilka tysięcy zużywa miesięczny limit zdarzeń
  darmowego planu Sentry, po czym zdarzenia giną do końca miesiąca. Wysyłka jest synchroniczna (limit 2 s) i trzyma
  workera FrankenPHP.
- **Konsekwencje:** Sentry jest jedynym kanałem powiadomień o błędach 5xx aplikacji; napastnik może go tanio oślepić
  przed właściwym atakiem albo zalać szumem.
- **Dowód:** lokalnie, `work/priv/04_sentry_401.php` (osobny proces w kontenerze `api`, DSN na zamknięty port, licznik
  zdarzeń w SDK):
  ```
  no token -> /api/v1/me                   status=401 sentry_event=no
  garbage Bearer -> /api/v1/me             status=401 sentry_event=YES: ["An error occurred while decoding and validating the token."]
  ```
  W logach stosu 86 takich wpisów `ERROR app` z testów audytorów; prawdziwej wysyłki nie obserwowano (brak DSN
  lokalnie).
- **Istniejące zabezpieczenia:** ochrona przed skokami w ustawieniach projektu Sentry (niesprawdzalna z kodu);
  Cloudflare przed originem; filtr Sentry usuwa dane z tych zdarzeń.
- **Rekomendacja:** własny kanał dla loggera handlera tokenów (tag `monolog.logger` z `channel: security` w
  `services.yaml`) i `'!security'` w `channels` handlera `sentry`; test „401 z błędnym tokenem nie daje zdarzenia”.
- **Nakład:** S — zmiana konfiguracji i jeden test.
- **Pewność:** wysoka.

### SA-19 — Brak sygnałów bezpieczeństwa: logów dostępu, metryk i alertów

- **Kategoria:** OWASP A09:2025 Security Logging & Alerting Failures; CWE-778, CWE-223.
- **Poziom:** Medium.
- **Status:** CONFIRMED (konfiguracja `prod` statycznie, zachowanie logowania dynamicznie w `dev`).
- **Lokalizacja:** `apps/api/config/packages/monolog.yaml:37-58`; `apps/api/docker/frankenphp/Caddyfile` (brak `log`);
  `apps/api/src/Shared/Infrastructure/Http/RateLimitSubscriber.php:48-62`; `apps/api/config/packages/framework.yaml`
  (brak `exceptions:`); `infra/k8s/charts/shop/templates/00-alerts.yaml`;
  `infra/k8s/argocd/apps/monitoring.yaml:176-179`.
- **Opis:** Suma braków: (1) żadna warstwa nie zapisuje logu dostępu, a API nie wystawia metryk, więc nie ma liczby
  odpowiedzi 401/403/404/429/5xx ani adresów klientów; (2) w `prod` `fingers_crossed` wypisuje wpisy tylko dla żądań z
  logiem `error`, więc skanowanie ścieżek i żądania bez tokenu nie zostawiają śladu; (3) `RateLimitSubscriber` zwraca
  429 bez logu; (4) wszystkie 7 reguł alertów dotyczy infrastruktury; (5) wyjątki domeny kończące się 4xx są logowane
  jako `CRITICAL`, jak prawdziwe awarie; (6) reguła Alloy maskuje identyfikator użytkownika we wpisie
  `Authenticator successful!` (klucz `token`), więc odmowa 403 nie ma aktora.
- **Wymagania wstępne ataku:** brak; dotyczy każdego ataku na API.
- **Scenariusz wykorzystania:** napastnik godzinami próbuje kodów rabatowych, enumeruje zamówienia, wysyła webhooki z
  błędnym podpisem albo testuje panel przejętym kontem; właściciel dowiaduje się ze skutków.
- **Konsekwencje:** brak wykrywania i danych do analizy po fakcie; z SA-11 i SA-20 przebieg incydentu da się odtworzyć
  tylko częściowo.
- **Dowód:** lokalnie, `work/priv/logs/api.log` (11 800 wpisów): 0 wpisów dla 429 i dla 401 bez tokenu; `CRITICAL
  request "Uncaught PHP Exception …HandlerFailedException: Handling …PlaceOrder failed: Koszyk jest pusty."`;
  `work/priv/06_alloy_mask_sim.py`: `user id of PostAuthenticationToken: 281→0`.
- **Istniejące zabezpieczenia:** błędy 5xx i logi `error` są zdarzeniami Sentry; każdy wpis ma `correlation_id` zwracany
  w `X-Correlation-ID`; Cloudflare ma własną analitykę ruchu.
- **Rekomendacja:** (1) log dostępu w jednym miejscu (Caddy `log` w JSON bez nagłówków `Authorization`, `Cookie`,
  `Order-Access-Token` i query stringu albo `accessLog` Traefika); (2) linia `warning` przy odmowie limitera; (3)
  `framework.exceptions` z `log_level` dla wyjątków 4xx; (4) wyjątek w regule Alloy dla kanału `security`; (5) alerty na
  Loki (odrzucone webhooki, `LOGIN_ERROR`, `AccessDeniedHttpException`, odmowy limitera) i na restarty kontenerów.
- **Nakład:** M — pięć małych zmian w trzech miejscach i reguły alertów z testem.
- **Pewność:** wysoka co do braków; średnia co do tego, czy Cloudflare nie daje właścicielowi części tych sygnałów.

### SA-20 — Luki śladu audytowego operacji personelu

- **Kategoria:** OWASP A09:2025 Security Logging & Alerting Failures; CWE-778.
- **Poziom:** Medium.
- **Status:** CONFIRMED.
- **Lokalizacja:** `apps/api/src/Pricing/Application/Command/DeletePromotionHandler.php:34-41`;
  `apps/api/migrations/Version20261108090000CreatePromotions.php`, `Version20261108190000CreateDiscountCodes.php`,
  `Version20261107120000AddCatalogProductStatus.php`; migracje kategorii (brak aktora);
  `apps/api/src/Ordering/UI/Http/BackofficeCustomer*Controller.php`.
- **Opis:** Ślad audytowy jest pełny dla cen, stanów, statusów zamówień i zamknięcia zwrotu. Dla pozostałych operacji
  nie: promocja ma tylko ostatni `updated_by`, a usunięcie kasuje wiersz bez śladu; kod rabatowy nie przechowuje
  poprzednich wartości; produkt ma tylko ostatni `updated_by`; kategorie nie mają aktora; wgląd w dane klientów i
  zamówienia nie jest zapisywany.
- **Wymagania wstępne ataku:** konto personelu z rolą `marketing` (promocje, kody) albo `admin`.
- **Scenariusz wykorzystania:** pracownik z rolą `marketing` tworzy promocję 90% na drogi wariant, wspólnik składa
  zamówienie, promocja zostaje usunięta; nikt nie ustali, kto ją utworzył i usunął.
- **Konsekwencje:** nadużycie wewnętrzne nierozliczalne; przy wycieku danych nie da się wskazać konta, które je
  przeglądało.
- **Dowód:** lokalnie, `work/priv/05_promo_trace.py` (konto `marketing`): `plan 201 … percentOff 90`,
  `revise 200 … percentOff 10`, `delete 204`; potem `select count(*) from pricing_promotion where id='01a1edfa-c302-…'`
  = 0. Kod `PRIVAUD77` po zmianie 90% → 5%: `PRIVAUD77|percent|5|f|marketing|marketing|…|2`, bez śladu wartości 90.
- **Istniejące zabezpieczenia:** role ograniczają operacje; zamówienie kopiuje nazwę i procent promocji oraz kod; panel
  nie ma endpointu zmiany wpisów historii.
- **Rekomendacja:** jedna tabela dopisywana `backoffice_audit_log` (kto, kiedy, akcja, obiekt, wartości przed i po,
  `correlation_id`), zapisywana w transakcji komendy przez middleware `command.bus`, także dla usunięć i odczytów kart
  klienta; rola bazy bez `UPDATE`/`DELETE` na tej tabeli. Punkt odniesienia: Adobe Commerce domyślnie loguje wszystkie
  akcje administratorów (Action Logs).
- **Nakład:** M — tabela, middleware, testy dla kilku komend.
- **Pewność:** wysoka.

### SA-21 — Brak usuwania, anonimizacji, eksportu i retencji danych klientów

- **Kategoria:** OWASP A06:2025 Insecure Design (OWASP Top 10 Privacy Risks P5, P7); CWE-459. Aspekty techniczne art. 5
  ust. 1 lit. e, art. 13, 17 i 20 RODO; to nie jest ocena zgodności prawnej.
- **Poziom:** Medium.
- **Status:** CONFIRMED.
- **Niezależny przegląd:** podtrzymane, Medium; ocena techniczna, nie prawna.
- **Lokalizacja:** `apps/api/src/Customer` (tylko `GET /api/v1/me`);
  `apps/api/src/Cart/UI/Console/PurgeExpiredCartsCommand.php` (jedyny mechanizm retencji); tabele `ordering_order`,
  `ordering_customer`; `apps/store/src/checkout/CheckoutPage.tsx:44-51`;
  `infra/k8s/environments/prod/values.yaml:22-23`.
- **Opis:** Nie ma usunięcia konta, anonimizacji zamówień, eksportu danych ani retencji zamówień; dane kontaktowe i
  adresowe są przechowywane bezterminowo. Usunięcie konta w Keycloaku niczego nie zmienia w API: zamówienia, wiersz
  `ordering_customer` i koszyk (ważny 180 dni) zostają. Sklep nie ma polityki prywatności ani klauzuli informacyjnej
  przy formularzu checkoutu (sześć pól danych), choć dane trafiają do procesorów (Sentry, Grafana Cloud, Cloudflare,
  Brevo). Demo przyjmuje zamówienia gości, więc odwiedzający może zostawić prawdziwe dane.
- **Wymagania wstępne ataku:** brak; to stan funkcjonalny systemu.
- **Scenariusz wykorzystania:** klient żąda usunięcia albo kopii danych; operator może to zrobić tylko ręcznym SQL na
  produkcji. Każdy wyciek bazy lub kopii obejmuje pełną historię wszystkich klientów.
- **Konsekwencje:** ryzyko regulacyjne i rosnący zakres danych w razie naruszenia.
- **Dowód:** lokalnie: `DELETE /admin/realms/shop/users/c24fe5f9-…` → 204, po czym w bazie nadal
  `ordering_customer|c24fe5f9-…|priv-weak-privmark7731@example.com|…`, zamówienie `R-100003` i koszyk; `grep` po
  `apps/store` za „polityk|prywatno|regulamin|RODO|zgod”: 0 trafień.
- **Istniejące zabezpieczenia:** minimalny zakres danych (bez daty urodzenia i danych płatniczych); wygasłe koszyki
  usuwa CronJob; kopie z retencją 7 dni, szyfrowane; ponowna rejestracja tego samego adresu nie daje dostępu do starych
  zamówień.
- **Rekomendacja:** komenda `customer:erase` (i odpowiednik dla gości) anonimizująca dane kontaktowe zamówień po okresie
  reklamacji, wywoływana też po usunięciu konta; retencja zamówień jako parametr i zadanie okresowe; eksport
  (`GET /api/v1/me/export`); polityka prywatności przy checkoutcie; na demo komunikat i okresowe czyszczenie zamówień.
- **Nakład:** L — decyzja o retencji, komendy z testami, strona informacyjna, procedura.
- **Pewność:** wysoka co do stanu technicznego; ocena prawna poza zakresem.

### SA-22 — Płatność przyjęta bez opłaconego zamówienia widoczna tylko w bazie i logu

- **Kategoria:** OWASP A06:2025 Insecure Design; A09:2025 Security Logging & Alerting Failures; CWE-841, CWE-778.
- **Poziom:** Medium przy prawdziwym operatorze; Low dziś.
- **Status:** CONFIRMED (zachowanie potwierdzone testami; skutek finansowy zależy od podłączenia operatora).
- **Niezależny przegląd:** podtrzymane jako ryzyko architektury: dziś bez skutku finansowego, bo płaci symulator.
- **Lokalizacja:** `apps/api/src/Payment/Application/Command/HandleProviderWebhookHandler.php:79-89`;
  `apps/api/src/Payment/Domain/Payment.php` (`refundRequiredReason`);
  `apps/api/src/Ordering/Application/Command/MarkOrderPaidHandler.php:52-66`, `ExpireUnpaidOrderHandler.php:65-73`.
- **Opis:** Trzy sytuacje kończą się udaną płatnością, która nie opłaca zamówienia: płatność po terminie
  (`paid_after_deadline`), po anulowaniu (`order_cancelled`) i druga udana płatność (`duplicate`). System zapisuje to
  poprawnie i atomowo, ale jedynym śladem jest kolumna `payment_payment.refund_required_reason` i log `error`: panel
  tego nie pokazuje (zero odczytów kolumny poza modułem Payment), zwrot nie jest zlecany. Drugą płatność umożliwia sam
  system, bo po opłaceniu albo wygaśnięciu zamówienia oczekująca sesja innej próby nie jest anulowana u operatora.
- **Wymagania wstępne ataku:** prawdziwy operator z opóźnionym księgowaniem albo nienatychmiastowym anulowaniem sesji,
  albo klient z dwiema otwartymi sesjami.
- **Scenariusz wykorzystania:** klient zaczyna płatność przelewem, wraca, wybiera kartę; operator księguje przelew,
  zamówienie jest opłacone, a sesja karty nadal przyjmuje płatność i klient płaci drugi raz.
- **Konsekwencje:** zatrzymane pieniądze klientów (reklamacje, chargebacki), wykrywane dopiero przez przegląd logów lub
  zapytanie SQL.
- **Dowód:** lokalnie, `work/pay/t05_event_order.py` (przypadki E i F), `work/pay/t10_expiry.py b`:
  ```
  page of payment 2 at the provider still offers to pay: True
   R-100018 | paid | 2 | demo-card     | succeeded | duplicate | pays=f
   R-100019 | new  | 1 | demo-transfer | succeeded | paid_after_deadline
  ```
- **Istniejące zabezpieczenia:** zapis atomowy i trwały; log `error` trafia do Sentry (gdy ustawiony DSN); specyfikacja
  wymienia to jako znane ryzyko (#221).
- **Rekomendacja:** (1) lista „Płatności do zwrotu” w panelu; (2) anulowanie oczekujących sesji u operatora po
  opłaceniu, wygaśnięciu i anulowaniu (wiadomość przez outbox); (3) operacja `refund` w porcie i automatyczny zwrot dla
  `duplicate` i `paid_after_deadline`; (4) alert na nowy wiersz z `refund_required_reason`.
- **Nakład:** M — podobny ekran istnieje dla zwrotów Ordering; anulowanie sesji to jeden handler.
- **Pewność:** wysoka.

### SA-23 — Brak odpytania operatora i rekoncyliacji; wygaszanie zależne od jednej wiadomości

- **Kategoria:** OWASP A06:2025 Insecure Design; A09:2025 Security Logging & Alerting Failures; CWE-754, CWE-391,
  CWE-772.
- **Poziom:** Medium przy prawdziwym operatorze; Low dziś.
- **Status:** HYPOTHETICAL co do skutku finansowego; braki CONFIRMED w kodzie, stan `pending` po wygaśnięciu
  potwierdzony testem.
- **Niezależny przegląd:** podtrzymane jako ryzyko architektury: do zamknięcia przed podłączeniem operatora, nie teraz.
- **Lokalizacja:** `apps/api/src/Payment/Application/Port/PaymentProvider.php:23-70` (brak `fetchStatus` i `refund`);
  `apps/api/src/PaymentSimulator/DeliverSimulatedWebhookHandler.php:57-70`;
  `apps/api/config/packages/messenger.yaml:55-63`;
  `apps/api/src/Ordering/Application/Command/ExpireUnpaidOrderHandler.php:54-80`;
  `infra/k8s/charts/shop/templates/00-alerts.yaml` (brak alertu na kolejkę `failed`).
- **Opis:** (1) Gdy webhook sukcesu nie dotrze, zamówienie wygasa, a płatność zostaje na zawsze `pending` bez oznaczenia
  do zwrotu: port nie umie zapytać operatora o status. (2) Zwolnienie rezerwacji i użycia kodu zależy od jednej
  opóźnionej wiadomości `ExpireUnpaidOrder`; po 5 nieudanych próbach (ok. 2 min) trafia ona do `failed`, zamówienie
  zostaje `new`, a towar jest zablokowany bez końca; nie ma zadania zamiatającego ani alertu. (3) Zwrot z panelu to
  wyłącznie znacznik, niepowiązany z operacją u operatora.
- **Wymagania wstępne ataku:** awaria albo błąd sklepu lub operatora w oknie dostawy webhooka; napastnik nie wywoła tego
  bezpośrednio, ale SA-03 i masowy SA-04 zwiększają prawdopodobieństwo.
- **Scenariusz wykorzystania:** API nie przyjmuje webhooków przez kilka minut (SA-03 albo wdrożenie), operator
  rezygnuje; zamówienia zapłacone w tym czasie wygasają po 35 minutach.
- **Konsekwencje:** zapłacone i anulowane zamówienia bez śladu w systemie; ciche zamrożenie stanu i limitu kodów do
  ręcznej interwencji w bazie.
- **Dowód:** kod portu (trzy metody: `createSession`, `cancelSession`, `parseWebhook`); lokalnie
  `work/pay/t10_expiry.py`: `R-100011 | cancelled | payment_timeout | demo-transfer:pending | released`. Ścieżki błędu
  wygaszania nie wywoływano.
- **Istniejące zabezpieczenia:** wiadomość wygaszająca zapisana w transakcji zamówienia, przeżywa restart workera;
  handler idempotentny; spóźniony webhook oznacza płatność do zwrotu; ostatnia nieudana próba trafia do Sentry.
- **Rekomendacja:** `fetchStatus` w porcie i zadanie rekoncyliacji płatności `pending` po terminie; polecenie konsoli z
  crona albo Schedulera, które wysyła `ExpireUnpaidOrder` dla zamówień `new` po `payment_deadline`; alert na kolejkę
  `failed`; zwrot powiązany z identyfikatorem operacji u operatora. Punkt odniesienia: Mollie przekazuje w webhooku
  tylko identyfikator i wymaga pobrania statusu z API; Stripe zaleca sprawdzenie `payment_status` sesji przy realizacji.
- **Nakład:** M (rekoncyliacja i `fetchStatus`); samo polecenie zamiatające z alertami to S.
- **Pewność:** średnia (zależna od przyszłego operatora; ryzyko operacyjne bez znanej ścieżki ataku).

### SA-24 — Celowa blokada cudzego konta dwiema błędnymi próbami

- **Kategoria:** OWASP A07:2025 Authentication Failures; API4:2023; CWE-645.
- **Poziom:** Low.
- **Status:** CONFIRMED.
- **Lokalizacja:** `infra/keycloak/realms/shop.json:14`, `infra/keycloak/realms/backoffice.json:13`;
  `infra/k8s/charts/shop/templates/ingress.yaml:13`.
- **Opis:** Domyślny „quick login check” blokuje konto na 60 s po dwóch nieudanych próbach w odstępie poniżej 1 s; w
  trakcie blokady poprawne hasło jest odrzucane. Blokadę można utrzymywać dwoma żądaniami na minutę, bez
  uwierzytelnienia i limitu per adres; realm `backoffice` jest publiczny.
- **Wymagania wstępne ataku:** znajomość e-maila klienta albo loginu pracownika (loginy typu `admin` są zgadywalne).
- **Scenariusz wykorzystania:** skrypt co 60 s wysyła dwie błędne próby dla loginów personelu; nowe logowania do panelu
  nie działają.
- **Konsekwencje:** niedostępność logowania dla wskazanych kont (istniejące sesje SSO działają do 8 h).
- **Dowód:** lokalnie, `work/auth/t06_enum.py`: `"numFailures": 2, "disabled": true`;
  `correct password right after -> 400 Invalid user credentials`.
- **Istniejące zabezpieczenia:** blokada czasowa; komunikat nie zdradza blokady; TOTP w panelu (blokada odbiera dostęp,
  nie daje go).
- **Rekomendacja:** limit per adres z SA-09; Cloudflare Access także na logowaniu `backoffice`; niezgadywalne loginy;
  alert na `user_temporarily_disabled`.
- **Nakład:** S — w ramach zmian z SA-09 plus reguła alertu.
- **Pewność:** wysoka.

### SA-25 — Enumeracja kont klientów przez formularz rejestracji

- **Kategoria:** OWASP A07:2025 Authentication Failures; API2:2023; CWE-204.
- **Poziom:** Low.
- **Status:** CONFIRMED.
- **Lokalizacja:** `infra/keycloak/realms/shop.json:6-9` (`registrationAllowed`, `registrationEmailAsUsername`,
  `duplicateEmailsAllowed: false`).
- **Opis:** Rejestracja z adresem istniejącego konta zwraca „E-mail już istnieje.”, bez limitu i CAPTCHA. Logowanie i
  reset hasła nie różnicują odpowiedzi (sprawdzone).
- **Wymagania wstępne ataku:** włączona rejestracja (`customerAccounts: true`; w obecnym profilu `prod` wyłączona).
- **Scenariusz wykorzystania:** masowe sprawdzanie adresów i przygotowanie listy celów do credential stuffingu (SA-09)
  albo phishingu.
- **Konsekwencje:** ujawnienie faktu posiadania konta; lepsza skuteczność ataków z SA-09.
- **Dowód:** lokalnie, `work/auth/t06_enum.py`:
  `registration, existing e-mail -> 200 ['E-mail już istnieje.', 'Rejestracja']`.
- **Istniejące zabezpieczenia:** jednolite odpowiedzi logowania i resetu; checkout gościa nie sprawdza istnienia konta.
- **Rekomendacja:** CAPTCHA i limit per adres na rejestracji (SA-09); neutralna odpowiedź „sprawdź pocztę” wymaga
  własnego kroku w flow i jest do decyzji.
- **Nakład:** S (CAPTCHA/limit) albo M (neutralna odpowiedź: własny authenticator/SPI Keycloaka).
- **Pewność:** wysoka.

### SA-26 — Wylogowanie zależne od przeglądarki; token ważny do 5 min; refresh tokeny nierotowane

- **Kategoria:** OWASP A07:2025 Authentication Failures; API2:2023; CWE-613.
- **Poziom:** Low.
- **Status:** CONFIRMED (zachowanie potwierdzone; wpływ ograniczony architekturą BFF).
- **Lokalizacja:** `apps/store/app/auth/logout/route.ts:31-52`; `apps/store/src/auth/refresh.ts:113-127`;
  `apps/api/config/packages/security.yaml:20-37`; realmy: `revokeRefreshToken` nieustawione.
- **Opis:** (1) `POST /auth/logout` usuwa sesję BFF, a sesję Keycloaka kończy dopiero przeglądarka, idąc na
  `end_session`. (2) Access token jest ważny w API do `exp` (5 min) także po wylogowaniu; BFF nie ma back-channel
  logout. (3) Refresh token nie jest rotowany.
- **Wymagania wstępne ataku:** współdzielone urządzenie i przerwane przekierowanie albo wcześniejsza kradzież tokenu
  (Redis, pamięć procesu, pamięć przeglądarki w panelu).
- **Scenariusz wykorzystania:** konto wyłączone w Keycloaku działa w sklepie jeszcze do ~4,5 min; skradziony refresh
  token można odświeżać równolegle z ofiarą bez wykrycia.
- **Konsekwencje:** okno do 5 min po unieważnieniu; brak wykrywania ponownego użycia refresh tokenu.
- **Dowód:** lokalnie, `work/auth/t04_refresh.py`, `t09_misc.py`:
  `access token at API after Keycloak logout -> 200 (exp in 300 s)`;
  `2nd refresh with the SAME RT0 -> 200 new tokens issued again`.
- **Istniejące zabezpieczenia:** tokeny klienta tylko w Redis; klient `store` poufny; access token 5 min; sesja BFF
  usuwana od razu przy wylogowaniu.
- **Rekomendacja:** wywołanie `logout`/`revocation` Keycloaka z serwera przy wylogowaniu; `revokeRefreshToken: true` w
  obu realmach (po aktualizacji z SA-01: notatki researchu odnotowują CVE-2026-9802 dotyczące rotacji, naprawione w
  26.6.3); opcjonalnie back-channel logout.
- **Nakład:** S (revocation, rotacja) / M (back-channel logout z indeksem sesji i testami).
- **Pewność:** wysoka.

### SA-27 — Cookies sklepu bez `__Host-`; HSTS bez `includeSubDomains`

- **Kategoria:** OWASP A07:2025 Authentication Failures, A02:2025 Security Misconfiguration; CWE-384, CWE-565, CWE-319.
- **Poziom:** Low.
- **Status:** HARDENING.
- **Lokalizacja:** `apps/store/src/auth/cookies.ts:4-6,32-39`; `apps/store/src/cart/cookies.ts:5,11`;
  `apps/store/src/checkout/cookies.ts:8`; `infra/k8s/charts/shop/templates/middlewares.yaml:38-46`.
- **Opis:** Cookies sklepu są host-only, `HttpOnly`, `SameSite=Lax` i na produkcji `Secure`, ale bez prefiksu `__Host-`,
  a sklep działa na domenie nadrzędnej wobec `admin.`, `api.`, `auth.`, `img.`. Skrypt z subdomeny może ustawić
  `store_session` z `Domain=<domena>`; podpis HMAC nie pomaga, bo atakujący podrzuca własne ważne cookie. HSTS jest
  wysyłany bez `includeSubDomains`.
- **Wymagania wstępne ataku:** XSS albo przejęcie siostrzanej subdomeny, albo pozycja w sieci ofiary (MITM).
- **Scenariusz wykorzystania:** ofiara nieświadomie działa w sesji atakującego i zostawia w niej dane adresowe z
  checkoutu.
- **Konsekwencje:** wyciek danych z checkoutu do konta atakującego, login CSRF; wyłącznie jako drugi krok po błędzie na
  subdomenie.
- **Dowód:** lokalnie, `work/auth/t11_cookies_redirects.py`: przy dwóch cookies `store_session` wygrywa ostatnie w
  nagłówku; chart: `middlewares.yaml:45` (`stsSeconds: 31536000` jako jedyne pole).
- **Istniejące zabezpieczenia:** nowy identyfikator sesji po każdym logowaniu; kontrola `Origin` na zapisach; cookie
  `Secure` nie da się nadpisać z HTTP.
- **Rekomendacja:** prefiks `__Host-` dla cookies sklepu przy `STORE_URL` z HTTPS (`store_login`: `__Secure-` albo
  zmiana ścieżki); `stsIncludeSubdomains: true`.
- **Nakład:** S — zmiana nazw w trzech plikach i testach, jedno pole w chartcie (jednorazowe wylogowanie użytkowników).
- **Pewność:** średnia (mechanizm potwierdzony; brak znanego XSS na subdomenie, wpływ zależy od konfiguracji
  Cloudflare).

### SA-28 — Klient `admin` z wildcardem w `redirectUris` i dowolnym adresem po wylogowaniu

- **Kategoria:** OWASP A02:2025 Security Misconfiguration, A07:2025 Authentication Failures; CWE-601, CWE-940.
- **Poziom:** Low.
- **Status:** HARDENING.
- **Lokalizacja:** `infra/keycloak/realms/backoffice.json:108,112`;
  `infra/k8s/charts/shop/templates/_keycloak.tpl:32-43`; `infra/k8s/tests/test_switches.py:146`.
- **Opis:** Klient publiczny `admin` ma `redirectUris: ${ADMIN_URL}/*` i `post.logout.redirect.uris: "+"`, więc Keycloak
  przyjmuje dowolną ścieżkę originu panelu, choć aplikacja używa tylko `/auth/callback` i `/`.
- **Wymagania wstępne ataku:** pracownik z aktywną sesją SSO klika link atakującego; dodatkowy błąd ujawniający URL na
  originie panelu (dziś nieznaleziony).
- **Scenariusz wykorzystania:** żądanie autoryzacji z `redirect_uri` na ścieżkę z wyciekiem i `code_challenge`
  atakującego → kod → token z rolami pracownika.
- **Konsekwencje:** przejęcie sesji pracownika po spełnieniu warunku dodatkowego.
- **Dowód:** lokalnie, `work/auth/t11_cookies_redirects.py`:
  `backoffice/admin http://localhost:24101/any/other/path?x=1 -> (200, 'login form')`; klient `store`:
  `(400, 'invalid redirect')`.
- **Istniejące zabezpieczenia:** PKCE S256 wymuszone; obce originy odrzucane (400); CSP `script-src 'self'` i
  `Referrer-Policy` na panelu.
- **Rekomendacja:** `redirectUris: ["${ADMIN_URL}/auth/callback"]` i `post.logout.redirect.uris: "${ADMIN_URL}/"` (job
  ustawia te pola przy każdym uruchomieniu); zaktualizować test. Powiązane: CVE-2026-7504 w SA-01.
- **Nakład:** S — dwie wartości w pliku realmu i jeden test.
- **Pewność:** wysoka co do konfiguracji; niska co do wykorzystania bez dodatkowego błędu.

### SA-29 — Niepełne CSP sklepu i panelu; `X-Powered-By`

- **Kategoria:** OWASP A02:2025 Security Misconfiguration; CWE-693.
- **Poziom:** Low.
- **Status:** HARDENING (nie znaleziono żadnego XSS; to brak drugiej linii obrony).
- **Lokalizacja:** `apps/store/next.config.ts:9-15`; `apps/admin/docker/security-headers.conf:9`.
- **Opis:** CSP sklepu (`frame-ancestors 'none'; object-src 'none'; base-uri 'self'`) nie ogranicza skryptów, połączeń
  ani formularzy (decyzja D10: nonce wyłączyłby ISR). Panel ma `script-src 'self'`, ale bez `default-src`, `connect-src`
  i `form-action`. Brak `Cross-Origin-Opener-Policy`; sklep ujawnia `X-Powered-By: Next.js`.
- **Wymagania wstępne ataku:** przyszły błąd XSS/HTML injection albo złośliwa zależność frontendu.
- **Scenariusz wykorzystania:** wstrzyknięty skrypt wywołuje BFF w imieniu klienta i wysyła dane na zewnątrz; w panelu
  wysyła token administratora z pamięci JS.
- **Konsekwencje:** brak ograniczenia skutków ewentualnego XSS; dziś bez wpływu.
- **Dowód:** lokalnie: `curl -sI http://localhost:24100/` zwraca powyższe CSP i `X-Powered-By: Next.js`; nagłówki panelu
  tylko statycznie (compose używa `vite dev`).
- **Istniejące zabezpieczenia:** React i Vue kodują każdą wartość (brak `v-html`); `X-Frame-Options: DENY`; cookies
  `HttpOnly`.
- **Rekomendacja:** sklep: `default-src 'self'`, `script-src 'self' 'unsafe-inline'`, `connect-src`, `img-src`,
  `form-action`, ocena `experimental.sri`, `poweredByHeader: false`; panel: `default-src`, `connect-src`, `form-action`,
  COOP.
- **Nakład:** M dla sklepu (adresy zależne od wdrożenia, przejście E2E); S dla panelu.
- **Pewność:** wysoka.

### SA-30 — Kosztowne publiczne odczyty bez limitu (wyszukiwarka, mapa strony)

- **Kategoria:** OWASP API4:2023 Unrestricted Resource Consumption; CWE-770, CWE-405.
- **Poziom:** Low (do ponownej oceny po testach na skali docelowej).
- **Status:** LIKELY (mechanizm potwierdzony; kosztu przy milionach produktów nie da się zmierzyć na 191 produktach
  seeda).
- **Lokalizacja:** `apps/api/src/Catalog/UI/Http/ListProductsController.php:40`;
  `apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php:31-45`;
  `apps/api/src/Catalog/UI/Http/SitemapProductsController.php:32`;
  `apps/api/src/Catalog/Application/Query/SitemapChunkKey.php:42-70`;
  `apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DbalSitemapProductSource.php:44-62`.
- **Opis:** (1) Ingress `api.<domena>` omija Varnish, więc każde wyszukiwanie z unikalną frazą to pełne zapytanie ES z
  agregacjami, bez limitu żądań. (2) Klucz porcji mapy strony to niepodpisany base64 JSON; API przyjmuje dowolny zakres
  i zwraca do 50 000 produktów w jednej odpowiedzi.
- **Wymagania wstępne ataku:** żadne.
- **Scenariusz wykorzystania:** kilkadziesiąt równoległych klientów z unikalnymi frazami albo zakresami nasyca ES, wątki
  API i łącze.
- **Konsekwencje:** degradacja lub niedostępność wyszukiwarki i API przy docelowej skali.
- **Dowód:** lokalnie, `work/api/ratelimit_poc.sh`: `300 200  <- product search, unique phrase (9 s)`; porcja z pełnym
  zakresem → `200, items: 191 (cały katalog)`.
- **Istniejące zabezpieczenia:** `perPage ≤ 48`, `q ≤ 100` znaków, okno wyników 10 000, limity czasu klienta ES 1 s/2 s,
  limit wierszy porcji.
- **Rekomendacja:** limiter per adres na wyszukiwanie i `sitemap/products` (albo katalog publicznie tylko przez
  Varnish); porcję przyjmować tylko z kluczem z listy `product-chunks`.
- **Nakład:** S (limiter i walidacja klucza) – M (routing katalogu przez Varnish na ingressie).
- **Pewność:** średnia.

### SA-31 — Maile zamówienia gościa z treścią atakującego na dowolny adres

- **Kategoria:** OWASP A06:2025 Insecure Design; API6:2023; CWE-20, CWE-451, CWE-799.
- **Poziom:** Low.
- **Status:** CONFIRMED.
- **Lokalizacja:** `apps/api/src/Ordering/Application/OrderConfirmationText.php:29,40-42`, `OrderCancelledText.php:22`;
  `apps/api/src/Ordering/UI/Http/PlaceGuestOrderRequest.php`; `apps/api/src/Ordering/Domain/ControlCharacters.php:15`.
- **Opis:** Gość podaje dowolny, niepotwierdzony e-mail oraz imię i nazwisko (100 znaków), ulicę (100) i miasto (60);
  walidacja odrzuca tylko znaki sterujące. Potwierdzenie zaczyna się od `Dzień dobry <fullName>,` i powtarza te pola; po
  wygaśnięciu zamówienia przychodzi drugi e-mail o anulowaniu.
- **Wymagania wstępne ataku:** brak; jeden koszyk z najtańszym produktem, bez płatności.
- **Scenariusz wykorzystania:** zamówienie na adres ofiary z komunikatem i adresem URL w `fullName`; 10 zamówień / 15
  min na IP daje 20 wiadomości.
- **Konsekwencje:** phishing i spam z poprawnym SPF/DKIM sklepu, spadek reputacji nadawcy.
- **Dowód:** lokalnie, `work/biz/t12_mail.py` i `work/api/order.json` (Mailpit):
  `Subject: Potwierdzenie zamówienia R-100100`, pierwsza linia `Dzień dobry Kliencie! Platnosc odrzucona, doplac tutaj:
  https://example.org/biz-audit-doplata,`.
- **Istniejące zabezpieczenia:** e-mail tekstowy; brak wstrzyknięcia nagłówków; limit zamówień na IP; w `prod`
  `orderMail: mailpit` (poczta nie wychodzi z klastra do włączenia Brevo).
- **Rekomendacja:** przed włączeniem Brevo: neutralne powitanie; odrzucanie `://`, `www.` i `@` w polach adresowych
  (`Assert\Regex`); do rozważenia potwierdzenie gościa dopiero po płatności i limit na adres odbiorcy.
- **Nakład:** S — reguły walidacji, zmiana powitania w szablonach tekstu, testy.
- **Pewność:** wysoka.

### SA-32 — Role panelu: szeroki odczyt dla każdej roli, brak roli tylko do odczytu

- **Kategoria:** OWASP API5:2023 Broken Function Level Authorization; A01:2025, A06:2025; CWE-272, CWE-285, CWE-269.
- **Poziom:** Low (część o braku roli tylko do odczytu: Informational).
- **Status:** HARDENING (zachowanie potwierdzone dynamicznie; zgodne ze specyfikacją).
- **Lokalizacja:** `apps/api/config/packages/security.yaml:44`;
  `apps/api/src/Pricing/UI/Http/DiscountCode/ListDiscountCodesController.php:37`, `DiscountCodeController.php:19`,
  `apps/api/src/Pricing/UI/Http/Promotion/ListPromotionsController.php:35`,
  `apps/api/src/Pricing/UI/Http/BackofficePricesController.php:34`,
  `apps/api/src/Inventory/UI/Http/BackofficeStockController.php:34`;
  `apps/api/src/Ordering/Infrastructure/Security/OrderVoter.php:32-35`;
  `apps/api/src/Backoffice/Infrastructure/Security/StaffUser.php:21`.
- **Opis:** Dziewięć tras odczytu panelu (kategorie, stany, ceny, promocje, kody rabatowe) wymaga wyłącznie
  `ROLE_STAFF`. `VIEW_PENDING_PAYMENTS`, `VIEW_SALES` i `VIEW_CUSTOMERS` ukrywają widoki, ale magazyn czyta wszystkie
  zamówienia z kwotami i danymi klientów. Każda z czterech ról ma jakiś zapis, więc nie da się wydać konta tylko do
  odczytu.
- **Wymagania wstępne ataku:** konto personelu z dowolną rolą albo jego przejęcie.
- **Scenariusz wykorzystania:** pracownik magazynu pobiera kody rabatowe przed kampanią albo stronicuje zamówienia i
  odtwarza bazę klientów; konto demonstracyjne z rolą `marketing` zmienia dane.
- **Konsekwencje:** wyciek kodów i planów promocji oraz danych klientów do roli, która „nie widzi klientów”; brak
  eskalacji do zapisu.
- **Dowód:** lokalnie, `work/authz/reads_by_role.txt`: `warehouse: GET /api/v1/admin/discount-codes -> 200, 7 kodów`;
  `…/orders?status=new -> 200, 39 zamówień` z e-mailem i nazwiskiem; `…/customers -> 403`.
- **Istniejące zabezpieczenia:** TOTP personelu, brak rejestracji w `backoffice`, token 5 min; każdy zapis ma voter.
- **Rekomendacja:** atrybuty `VIEW_*` w voterach i `#[IsGranted]` na trasach odczytu; świadoma decyzja o danych klientów
  w zamówieniach; rola `viewer`; test rola × trasa.
- **Nakład:** S — stałe w voterach, atrybuty na 9 trasach, jedna rola, test po trasach z routera.
- **Pewność:** wysoka.

### SA-33 — Kontenery bez `securityContext`; obraz `prod` API jako root

- **Kategoria:** OWASP A02:2025 Security Misconfiguration; CWE-250, CWE-732.
- **Poziom:** Low.
- **Status:** HARDENING.
- **Lokalizacja:** `infra/k8s/charts/shop/templates/_helpers.tpl:264-281,313-329`, `:67-89`; `apps/api/Dockerfile:62-68`
  (etap `prod` bez `USER`); `rabbitmq.yaml:17-19`; `scripts/server-bootstrap.sh:150-154`.
- **Opis:** W renderze `prod` żaden z 53 kontenerów nie ma `runAsNonRoot`, `allowPrivilegeEscalation: false`,
  `capabilities.drop`, `readOnlyRootFilesystem` ani `seccompProfile`. Obraz API nie ustawia użytkownika, więc `api`,
  `worker` i joby działają jako uid 0; k3s działa bez `seccomp-default`, a przestrzeń `shop` bez etykiet Pod Security
  Admission.
- **Wymagania wstępne ataku:** RCE w kontenerze i podatność jądra lub runtime'u.
- **Scenariusz wykorzystania:** po RCE w API napastnik jest rootem w kontenerze (podmiana kodu w `/app`, `CAP_NET_RAW`)
  i ma szerszą powierzchnię ucieczki na jedyny węzeł.
- **Konsekwencje:** łatwiejsza eskalacja z kontenera na węzeł; brak drugiej linii obrony.
- **Dowód:** chart `prod`: `containers: 53; with any hardening field in securityContext: 0`
  (`work/infra/render-facts.txt`); lokalnie `api: … Uid: 0 … CapEff: 00000000a80425fb`
  (`work/infra/container-users.txt`).
- **Istniejące zabezpieczenia:** `store`, `admin`, Keycloak, imgproxy, Elasticsearch i Varnish działają bez roota; brak
  `hostPath`, `hostNetwork`, `privileged`; limity pamięci.
- **Rekomendacja:** domyślny `securityContext` w helperach (`drop: [ALL]`, `RuntimeDefault`, gdzie można
  `runAsNonRoot`); `USER www-data` w etapie `prod` API; etykieta `pod-security.kubernetes.io/enforce: baseline`.
- **Nakład:** M — zmiana helperów jest mała, ale każda usługa wymaga sprawdzenia w teście dymnym.
- **Pewność:** wysoka co do konfiguracji; średnia co do obrazu `prod` API (wniosek z Dockerfile, obrazu nie budowano).

### SA-34 — Płaszczyzna zarządzania: 6443 otwarty, `sshd` nieutwardzony, niewycofywalny kubeconfig

- **Kategoria:** OWASP A02:2025 Security Misconfiguration; CWE-284, CWE-1327/CWE-668.
- **Poziom:** Low.
- **Status:** HARDENING (reguła zapory potwierdzona w skrypcie; stanu serwera nie sprawdzano).
- **Lokalizacja:** `infra/k8s/scripts/server-bootstrap.sh:138-145`; `infra/k8s/scripts/kubeconfig.sh:49,65`;
  `infra/k8s/README.md:20-24,97,133-146`.
- **Opis:** Bootstrap otwiera port API k3s dla każdego adresu, choć jedynym klientem jest operator z dostępem SSH.
  Skrypt nie konfiguruje `sshd` (hasła, root, limit prób). `k3s.yaml` to certyfikat `system:masters`, którego nie da się
  wycofać bez rotacji CA klastra.
- **Wymagania wstępne ataku:** podatność API k3s dostępna bez uwierzytelnienia, wyciek kubeconfigu albo słabe logowanie
  SSH na obrazie dostawcy.
- **Scenariusz wykorzystania:** po wycieku jednego pliku kubeconfig napastnik jest administratorem klastra z dowolnego
  miejsca, bez przechodzenia przez SSH.
- **Konsekwencje:** pełne przejęcie klastra i danych; brak szybkiego unieważnienia poświadczenia.
- **Dowód:** `server-bootstrap.sh:140-141`: `ufw allow "$ssh_port/tcp"` / `ufw allow 6443/tcp`; w skrypcie nie ma
  odwołań do `sshd_config`.
- **Istniejące zabezpieczenia:** uwierzytelnianie certyfikatem w API; weryfikacja klucza hosta; `unattended-upgrades`;
  kubeconfig tylko w katalogu tymczasowym; Argo CD bez publicznego adresu.
- **Rekomendacja:** usunąć regułę 6443 (tunel SSH) albo ograniczyć ją do adresu operatora; plik `sshd_config.d` bez
  logowania hasłem; krótkotrwały token zamiast `system:masters`.
- **Nakład:** S — kilka linii w dwóch skryptach i ich testach.
- **Pewność:** średnia (nieznane ustawienia `sshd` obrazu dostawcy i faktyczny stan zapory).

### SA-35 — Ochrona originu tylko listą adresów Cloudflare; Access obejmuje wyłącznie SPA panelu

- **Kategoria:** OWASP A02:2025 Security Misconfiguration; CWE-290, CWE-693.
- **Poziom:** Low.
- **Status:** HYPOTHETICAL (obejście Cloudflare) / HARDENING (zakres Access).
- **Lokalizacja:** `infra/k8s/charts/shop/templates/middlewares.yaml:2-14`, `ingress.yaml:5-8`;
  `infra/k8s/environments/prod/values.yaml:29-31,43-68`; `scripts/server-bootstrap.sh:134-135`;
  `infra/k8s/README.md:196-204,229-255`; `docs/features/deploy/spec.md:119-131`.
- **Opis:** (1) Jedynym mechanizmem „tylko Cloudflare” jest `ipAllowList`; zakresy Cloudflare są wspólne dla wszystkich
  jego klientów, a origin nie weryfikuje strefy (brak Authenticated Origin Pulls albo tunelu). Porty 80/443 są otwarte
  na poziomie TCP/TLS. (2) Cloudflare Access obejmuje tylko statyczne SPA pod `admin.<domena>`; API panelu i logowanie
  personelu są publiczne (świadomie, runbook 4.2).
- **Wymagania wstępne ataku:** (1) konto Cloudflare z nadpisaniem nagłówka Host i znajomość IP originu; (2) żadne, to
  stan projektowy.
- **Scenariusz wykorzystania:** ruch z obcej strefy z `Host: api.<domena>` przechodzi listę, omijając WAF i limity
  strefy sklepu; wyciek hasła i TOTP pracownika wystarcza do pracy na API panelu z dowolnego miejsca.
- **Konsekwencje:** ochrony konfigurowane w Cloudflare mogą okazać się pomijalne; fałszywe poczucie, że panel jest „za
  Access”.
- **Dowód:** chart `prod`: `Middleware/ipallowlist` (22 zakresy) na pięciu Ingressach; brak `TLSOption` z `clientAuth`.
  Obejścia nie testowano.
- **Istniejące zabezpieczenia:** `externalTrafficPolicy: Local`; Full (strict) z Origin CA; `master` poza Ingressem; API
  panelu wymaga tokenu z TOTP; aplikacje nie ufają `X-Forwarded-*`.
- **Rekomendacja:** Authenticated Origin Pulls z `clientAuth: RequireAndVerifyClientCert` w Traefiku albo tunel
  Cloudflare; Access na `/api/v1/admin` i logowaniu `backoffice` albo opisywanie go jako utrudnienia; cykliczne
  `cloudflare-ips CHECK=1`.
- **Nakład:** S (mTLS originu) / M (Access na API panelu).
- **Pewność:** niska dla obejścia przez obce konto Cloudflare (nie testowano); wysoka dla zakresu Access.

### SA-36 — Profil `prod` jest profilem demo: poczta w Mailpit, seed, bucket testowy

- **Kategoria:** OWASP A02:2025 Security Misconfiguration, A06:2025 Insecure Design; CWE-1188, CWE-489, CWE-359.
- **Poziom:** Low (dla demo: Informational; przy prawdziwych klientach bez zmiany wartości: Medium).
- **Status:** CONFIRMED (konfiguracja).
- **Lokalizacja:** `infra/k8s/environments/prod/values.yaml:20-23`; `charts/shop/templates/api.yaml:69-72`;
  `mailpit.yaml:13-16`; `jobs.yaml:238-241`, `files/scripts/initialize.sh:27-30`; `infra/garage/init.sh:7`,
  `imgproxy.yaml:26-27`.
- **Opis:** Jedyne środowisko wdrożeniowe w repo to demo nazwane `prod`: poczta zamówień trafia do Mailpita bez
  logowania (`orderMail: mailpit`, wdrażany zawsze), Job `initialize` przy pustej bazie wczytuje dane demo
  (`bin/seed-demo --force-reset`), a imgproxy dopuszcza bucket `product-images-test`. Część o płatnościach: SA-02.
- **Wymagania wstępne ataku:** uruchomienie sprzedaży na tej konfiguracji bez przeglądu przełączników.
- **Scenariusz wykorzystania:** potwierdzenia z danymi osobowymi nie docierają do klientów i gromadzą się w narzędziu
  czytelnym dla każdego poda (SA-06); odtworzenie na pustą bazę kończy się danymi demo.
- **Konsekwencje:** wyciek danych osobowych i brak powiadomień; w demo skutki zamierzone i komunikowane paskiem
  (`demoNotice`).
- **Dowód:** chart `prod`: `MAILER_DSN=smtp://mailpit:1025?timeout=10`, `Deployment/mailpit` obecny; lokalnie
  `GET http://mailpit:8025/api/v1/messages` → 200 bez poświadczeń.
- **Istniejące zabezpieczenia:** przełączniki to jawne wartości chartu z testami renderu; konta demo nie trafiają do
  klastra; logi Mailpita nie są zbierane; `noindex`.
- **Rekomendacja:** wartość `environment: demo|production` z błędem renderu `production` przy `orderMail: mailpit`,
  wyłączonych kopiach, włączonym `initialize` albo symulatorze; Mailpit i bucket testowy renderowane warunkowo. Mailpit
  v1.27.11 ma też zaległe advisories (część G).
- **Nakład:** S — warunki w szablonach i schemacie wartości plus testy renderu.
- **Pewność:** wysoka.

### SA-37 — Brak procedury rotacji sekretów; klucz Sealed Secrets nierotowany

- **Kategoria:** OWASP A04:2025 Cryptographic Failures, A02:2025 Security Misconfiguration; CWE-324, CWE-320.
- **Poziom:** Low.
- **Status:** HARDENING.
- **Lokalizacja:** `infra/k8s/argocd/apps/sealed-secrets.yaml:22-24`; `infra/k8s/scripts/seal-secrets.sh`;
  `infra/postgres/init/10-create-databases.sh:6,9`; `infra/k8s/README.md:160-161,297-304`;
  `charts/shop/templates/keycloak.yaml:21-22`.
- **Opis:** Nie ma procedury wymiany żadnego sekretu: `seal-secrets` nie zmienia istniejących wartości, hasła baz i
  administratora Keycloaka żyją w wolumenach. Jeden nierotowany klucz Sealed Secrets (`keyrenewperiod: "0"`)
  odszyfrowuje wszystkie sekrety, także historyczne wersje z publicznego repozytorium.
- **Wymagania wstępne ataku:** wyciek dowolnego sekretu albo klucza kontrolera (kopia w menedżerze haseł, root na
  węźle).
- **Scenariusz wykorzystania:** kto raz odczytał klucz kontrolera, może w przyszłości odszyfrować każdy zacommitowany
  sekret; właściciel nie ma gotowego sposobu unieważnienia wartości.
- **Konsekwencje:** długi czas reakcji po incydencie (np. SA-05); sekrety o praktycznie nieskończonym czasie życia.
- **Dowód:** `sealed-secrets.yaml:24` `keyrenewperiod: "0"`; `README.md:161` „Istniejących kluczy nie zmienia (wartości
  są już w bazach)”.
- **Istniejące zabezpieczenia:** wartości z `/dev/urandom` (16–32 bajty); zakres `strict`; `check` odrzuca jawny Secret;
  brak fallbacków „dev” w kodzie API.
- **Rekomendacja:** tryb `ROTATE=<klucz>` w `seal-secrets.sh` i runbooku z krokiem zmiany w usłudze; po SA-05 rotować
  wszystko raz; rozważyć odnawianie klucza z `kubeseal --re-encrypt`.
- **Nakład:** M — procedura per usługa i jej przećwiczenie w teście dymnym.
- **Pewność:** wysoka.

### SA-38 — Obrazy bazowe przypięte tagiem; zaległe CVE obrazów i Next.js 16.4.3

- **Kategoria:** OWASP A08:2025 Software or Data Integrity Failures, A03:2025 Software Supply Chain Failures; CWE-494,
  CWE-829, CWE-1395.
- **Poziom:** Low.
- **Status:** CONFIRMED (stan obrazów i przypięć) / LIKELY (Next.js).
- **Lokalizacja:** `apps/api/Dockerfile:8,24`; `apps/store/Dockerfile:4`; `apps/admin/Dockerfile:4,36`;
  `infra/k8s/scripts/server-bootstrap.sh:173-176`; `infra/k8s/argocd/install/argocd.yaml:9-11`,
  `argocd/apps/sealed-secrets.yaml:15-17`, `prometheus-crds.yaml:15-17`, `monitoring.yaml:21-23`;
  `apps/store/package-lock.json` (`next 16.4.3`).
- **Opis:** (1) Cztery obrazy produkcyjne budowane w repo startują z tagów bez skrótu, instalator k3s nie ma sumy
  kontrolnej, a charty platformy są pobierane po numerze wersji. (2) Warstwy obrazów się starzeją: Trivy pokazuje m.in.
  imgproxy `v3.31.1` (Critical CVE-2026-33186 w gRPC, 54 High), `nginx-unprivileged` (Critical brotli CVE-2026-111497),
  obraz `api` (Critical `linux-libc-dev`); Node `24.22.1` ma wydanie bezpieczeństwa `24.22.3`. (3) `next 16.4.3` podlega
  GHSA-mfr8-8qww-v76h i GHSA-vc7v-qv4w-wrv8 (naprawa 16.4.5); funkcje objęte luką nie są używane.
- **Wymagania wstępne ataku:** kompromitacja rejestru, repozytorium chartów albo konta wydawcy; dla CVE obrazów osobna
  ścieżka do podatnego komponentu.
- **Scenariusz wykorzystania:** złośliwa warstwa bazowa obrazu `api` ma dziś wszystkie sekrety (SA-05); złośliwy chart
  Argo CD ma klaster.
- **Konsekwencje:** wskaźnik braku kadencji łatania (SA-16), nie samodzielna ścieżka ataku na aplikację.
- **Dowód:** linie `FROM` bez `@sha256:`; wyniki w `work/sc/trivy/` i `work/sc/npm-audit-*.json`; `grep` w `apps/store`
  nie znajduje `use cache` ani `unstable_cache`.
- **Istniejące zabezpieczenia:** własne obrazy wdrażane po digeście; `check` wymusza `tag@digest` usług; brak `latest`;
  instalator k3s z tagu wydania.
- **Rekomendacja:** `@sha256:` w `FROM` z testem; suma kontrolna instalatora k3s; `next` 16.4.5+; okresowy `trivy image`
  i reguła rebuildu obrazów bazowych.
- **Nakład:** S (przypięcia, test, podbicie `next`) + M (bieżące odświeżanie digestów).
- **Pewność:** wysoka co do stanu; średnia co do wpływu.

### SA-39 — Identyfikatory koszyków gościa i kody rabatowe przechodzą przez maskowanie logów

- **Kategoria:** OWASP A09:2025 Security Logging & Alerting Failures; CWE-532.
- **Poziom:** Low.
- **Status:** CONFIRMED (format logu dynamicznie; reguły Alloy symulacją; w `prod` tylko dla żądań zakończonych logiem
  `error`).
- **Lokalizacja:** `infra/k8s/argocd/apps/monitoring.yaml:131-239`; `apps/api/config/packages/monolog.yaml:41-58`;
  `infra/k8s/tests/log-corpus/api.log`.
- **Opis:** Wpis `Matched route` zawiera `cartId` (klucz dostępu do koszyka gościa) i pełne `request_uri` z
  `?discountCode=`; reguły Alloy nie maskują żadnego z nich ani fraz wyszukiwania panelu. Korpus testu maskowania jest
  syntetyczny.
- **Wymagania wstępne ataku:** dostęp do logów w Grafana Cloud (konto właściciela albo wyciek tokenu odczytu).
- **Scenariusz wykorzystania:** odczyt identyfikatora aktywnego koszyka gościa i zmiana jego zawartości; odczyt
  działających kodów rabatowych.
- **Konsekwencje:** ograniczone: koszyk nie zawiera danych osobowych, a kody są z natury współdzielone.
- **Dowód:** `work/priv/06_alloy_mask_sim.py`: `guest cart id: 451→451, cartId param: 450→450, discountCode: 396→396,
  e-mail: 2→0, JWT: 85→0`.
- **Istniejące zabezpieczenia:** bufor `fingers_crossed` ogranicza liczbę wpisów; maskowanie e-maili, JWT, nagłówków i
  adresów IP działa.
- **Rekomendacja:** wzorce `/guest-carts/<id>`, `"cartId"` i `discountCode` w Alloy; rzeczywiste wpisy w korpusie;
  trwalej procesor Monolog usuwający query string.
- **Nakład:** S — dwie reguły i wiersze korpusu.
- **Pewność:** wysoka co do formatu i reguł; średnia co do częstości w `prod`.

### SA-40 — Adaptery płatności jako wzorzec dla prawdziwego operatora: luki do zamknięcia

- **Kategoria:** OWASP A04:2025 Cryptographic Failures, A08:2025 Software or Data Integrity Failures; CWE-294,
  CWE-323/CWE-321, CWE-345.
- **Poziom:** Low.
- **Status:** HARDENING (żadnej z pozycji nie da się dziś wykorzystać bez sekretu operatora).
- **Lokalizacja:** `apps/api/src/PaymentSimulator/Http/PaymentPageController.php:91-94`;
  `apps/api/src/Payment/Infrastructure/Provider/DemoTransferProvider.php:86-97`, `DemoCardProvider.php:114-146`;
  `apps/api/config/packages/rate_limiter.yaml` (`payment_webhook`); `security.yaml` (`^/api/v1`).
- **Opis:** (1) Token formularza strony płatności to HMAC kluczem webhooków (jeden klucz, dwa zastosowania). (2) Podpis
  `demo-transfer` nie obejmuje znacznika czasu; przed powtórzeniem chroni tylko deduplikacja. (3) Brak rotacji sekretu
  `demo-transfer`. (4) `reference` z webhooka nie jest porównywane z numerem zamówienia. (5) `paidAt` bez dolnej
  granicy. (6) Limit webhooków na adres nadawcy, przed podpisem. (7) Webhook z nagłówkiem `Authorization: Bearer`
  dostaje 401 z firewalla.
- **Wymagania wstępne ataku:** brak ścieżki ataku dziś.
- **Scenariusz wykorzystania:** po skopiowaniu wzorca do operatora, którego identyfikatory zdarzeń nie są unikalne
  między ponowieniami, powtórzenie webhooka stałoby się możliwe.
- **Konsekwencje:** przeniesienie słabości wzorca do prawdziwej integracji.
- **Dowód:** lokalnie, `work/pay` (T07: „form token == HMAC-SHA256(transactionId, transfer webhook secret): True”; T05,
  G: płatność z datą 2001-01-01 opłaca zamówienie).
- **Istniejące zabezpieczenia:** `hash_equals`, podpis surowego ciała, odmowa przy pustym sekrecie, deduplikacja pod
  blokadą, kontrola kwoty.
- **Rekomendacja:** osobny klucz tokenu; znacznik czasu w podpisie; lista sekretów (bieżący i poprzedni); porównanie
  `reference` i `executedAt >= createdAt`; Symfony Webhook. Punkt odniesienia: Stripe podpisuje znacznik czasu z ciałem,
  Adyen zaleca akceptowanie poprzedniego klucza w czasie rotacji.
- **Nakład:** S — kilka warunków i jedna zmienna środowiska.
- **Pewność:** wysoka.

### SA-41 — Nagłówek `sentry-trace` wymusza śledzenie w Sentry

- **Kategoria:** OWASP API4:2023 (zużycie limitu usługi zewnętrznej); CWE-770.
- **Poziom:** Low.
- **Status:** HARDENING (ryzyko przyjęte w `docs/features/sentry/spec.md`, P8; lokalnie nie do sprawdzenia: brak DSN).
- **Lokalizacja:** `apps/api/config/packages/sentry.yaml:20`; `apps/api/config/packages/nelmio_cors.yaml:8`.
- **Opis:** API kontynuuje trace z nagłówków przychodzących od dowolnego klienta; decyzja `sampled=1` rodzica omija
  `traces_sample_rate` (0,1 w produkcji).
- **Wymagania wstępne ataku:** żadne; dowolny klient HTTP.
- **Scenariusz wykorzystania:** atakujący dokłada nagłówek do każdego żądania.
- **Konsekwencje:** zużycie limitu transakcji projektu Sentry i dodatkowa wysyłka do Sentry przy każdym żądaniu.
- **Dowód:** tylko kod i specyfikacja (brak DSN lokalnie).
- **Istniejące zabezpieczenia:** ryzyko świadomie przyjęte w specyfikacji; serwer sklepu odrzuca przychodzące nagłówki
  śledzenia.
- **Rekomendacja:** `traces_sampler` honorujący decyzję rodzica tylko dla żądań z `Origin` równym `ADMIN_URL` albo z
  tokenem personelu.
- **Nakład:** S.
- **Pewność:** średnia.

### SA-42 — Pozycje informacyjne

- **Stan wariantu przez koszyk i kolejne numery zamówień (BIZ-05; CONFIRMED).** API3:2023 / A06:2025; CWE-203, CWE-200.
  Lokalizacja: `apps/api/src/Cart/Domain/Cart.php` (`assertPurchasable`),
  `apps/api/src/Ordering/Infrastructure/Persistence/Doctrine/DbalOrderNumbers.php:25`. Odpowiedź 200/409 na zmianę
  ilości pozwala ustalić stan wyszukiwaniem binarnym (do 99 sztuk, najwyżej 7 żądań), a kolejne numery `R-1000NN` dają
  górne oszacowanie liczby zamówień. Dowód (`work/biz/t17_stock_probe.py`):
  `stock learned through the cart: 36 in 7 requests | real: 36 | 0 | 36`. Numer nie daje dostępu. Rekomendacja: przyjąć
  świadomie albo dodać limit zmian ilości i losowy składnik numeru (nakład S).
- **Powierzchnie deweloperskie lokalnego stosu (INFRA-10; CONFIRMED, tylko dev).** A02:2025; CWE-489, CWE-798.
  Lokalizacja: `apps/api/docker/php/conf.d/spx.ini:4-6` (stały klucz SPX, whitelist `*`),
  `infra/docker-compose.yml:132`, `:221-224`, `infra/keycloak/realms/*.json` (konta demo z hasłami i sekretami TOTP w
  publicznym repo). Porty compose są publikowane wyłącznie na `127.0.0.1`; panel SPX odpowiada 200 każdemu, kto zna
  stały klucz (`work/infra/host-checks.txt`). Chart używa etapu `prod` bez SPX i bez kont demo. Rekomendacja:
  opcjonalnie losowy klucz SPX z `init-env.sh` i test, że etap `prod` nie zawiera `spx.so` (nakład S).
- **Dane w spoczynku (PRIV-09, reszta; HARDENING).** A04:2025; CWE-312. Lokalizacja:
  `apps/api/src/Ordering/Infrastructure/Persistence/Doctrine/DoctrineOrderRepository.php:215`,
  `infra/k8s/charts/shop/files/scripts/backup.sh:46`. Token dostępu zamówienia gościa leży w bazie jawnie i nie wygasa
  (daje tylko status i start płatności); `pg_dump` obu baz leży niezaszyfrowany na wolumenie `backup-work` między
  przebiegami; sesje w Redis: SA-06. Rekomendacja: skrót SHA-256 tokenu i usuwanie `dumps/` po udanym `restic backup`
  (nakład S–M).

## 8. Mocne strony obecnych zabezpieczeń

Każda pozycja podaje lokalizację w kodzie i, gdy był, test dynamiczny, który ją potwierdził (szczegóły w części B.2).

**Uwierzytelnianie i sesja**

- Walidacja JWT natywnym handlerem `oidc` Symfony: `RS256` jako jedyny algorytm, osobny firewall, issuer, audience i
  provider na realm (`apps/api/config/packages/security.yaml:11-37`). Test: macierz 25 wariantów JWT, wszystkie 401.
- Role pracownika filtrowane do znanej listy (`StaffUserProvider.php:48-57`); konto bez roli nie dostaje `ROLE_STAFF`
  (`StaffUser.php:49-52`).
- BFF: tokeny wyłącznie w Redis, przeglądarka dostaje 256-bitowy losowy identyfikator
  (`apps/store/src/auth/session-store.ts:35`); nowy identyfikator przy każdym logowaniu i usunięcie poprzedniej sesji
  (`app/auth/callback/route.ts:28-33`); `state`, `nonce`, PKCE S256 i weryfikacja podpisu ID tokenu
  (`oidc.ts:57-88,116`); transakcja logowania jednorazowa (`GETDEL`). Testy: fiksacja sesji, callback w 4 wariantach,
  cookie po wylogowaniu.
- CSRF: ścisła równość `Origin` i wymóg `application/json` na wszystkich zapisach BFF w jednym miejscu
  (`apps/store/src/cart/bff.ts:38-48`); osobna kontrola `Origin` i `Sec-Fetch-Site` na wylogowaniu, które jest wyłącznie
  `POST` (`app/auth/logout/route.ts:12-24`). Chroni też przed siostrzanymi subdomenami, których `SameSite=Lax` nie
  obejmuje. Testy: 403 w każdym wariancie (dwie niezależne serie).
- `returnTo` sanityzowane przy zapisie i ponownie przy użyciu, przekierowania budowane od `STORE_URL`, nie od nagłówka
  `Host` (`src/auth/return-to.ts`, `oidc.ts:72-76`); w panelu `safeTarget` (`AuthCallbackView.vue:15-18`). Testy: 14 i
  27 wariantów.
- Keycloak: klient `store` poufny, tylko standard flow, PKCE wymuszone, dokładny `redirect_uri`; `backoffice` z
  obowiązkowym TOTP także w direct grant; weryfikacja e-mail; linki akcji ważne 5 min; hasła w argon2id; konta demo
  usuwane z realmów produkcyjnych (`_keycloak.tpl:22`); `master` i konsola niedostępne z zewnątrz (`ingress.yaml:13`).
- Panel: tokeny tylko w pamięci (`apps/admin/src/auth/userManager.ts:8`), wylogowanie po bezczynności, role z `/me`.
- Higiena: logowanie błędów bez obiektów z tokenami (`src/auth/responses.ts:30-37`); wszystkie odpowiedzi osobiste
  `private, no-store` z jednego pomocnika (`src/auth/responses.ts:6-9`); porównania podpisów w stałym czasie.

**Autoryzacja**

- Domyślna odmowa w `access_control` (`security.yaml:44`, `:62`) i dwa osobne firewalle: nowa trasa pod `/api/v1` bez
  wpisu jest chroniona, a token jednego realmu nie działa w drugim. Testy: 401 na wszystkich 37 trasach panelu dla
  anonima i klienta; 126 + 30 wariantów ścieżki i metody bez obejścia.
- Zasoby klienta adresowane tożsamością z tokenu (`CartRef::customer`, `/me/*`); tam, gdzie id jest w ścieżce,
  decyduje jedna reguła domenowa `Order::grantsAccess` (`Order.php:152`), a brak dostępu i brak zasobu dają tę samą
  odpowiedź 404. Test: 36 scenariuszy IDOR/BOLA zgodnych z oczekiwaniem.
- Rozdzielenie typów właściciela: koszyk klienta nigdy nie jest osiągalny trasami gościa (`LockedCarts.php:37`,
  `ReadableCarts.php:29`, `Cart.php:304`); zamówienie klienta nie ma tokenu gościa.
- Identyfikatory o wystarczającej entropii: koszyk gościa UUID v4 z CSPRNG (`CartId.php:33`), token zamówienia 32 bajty,
  zamówienia i płatności UUID v7; sam identyfikator zamówienia nie daje dostępu.
- DTO wejściowe to jawne, niezmienne obiekty z walidacją typów (`mixed` + `Assert\Type`, bez rzutowania); nie ma
  denormalizacji do encji. Test: 8 scenariuszy mass assignment ze stanem bazy.
- Uprawnienia zapisu w panelu przez natywne `#[IsGranted]` i votery, sprawdzane przed mapowaniem ciała;
  `tests/Functional/Backoffice/AdminRouteAccessTest.php` bierze trasy z routera. Test: macierz ról, 403 i baza bez
  zmian.
- `CLIENT_IP_HEADER` jest brany pod uwagę tylko jako jeden poprawny adres, a chart odmawia renderu, gdy nagłówek jest
  włączony bez `ipAllowList` (`infra/k8s/charts/shop/values.yaml:70-82`).

**Logika biznesowa**

- Cena, suma, VAT i rabat wyłącznie z API: `PlaceOrder` nie ma pól linii ani cen (`PlaceOrder.php`), jedna wycena
  `BasketQuote` dla podglądu i zamówienia, `expectedTotalGross` tylko do porównania (`PlaceOrderHandler.php:124`). Testy
  T4, T10, T14, T19.
- Jedna transakcja złożenia zamówienia (blokada koszyka → kod → stan → zamówienie → zaplanowane wygaśnięcie → outbox) ze
  stałą kolejnością blokad. Testy T3, T5.
- Blokady pesymistyczne tam, gdzie liczy się licznik (`DoctrineStockItemRepository.php:54`,
  `RedeemDiscountCodeHandler.php:44-57`), i wersja optymistyczna `#[ORM\Version]` zamówienia. Testy T1, T2, T7, T7b. Dla
  porównania: brak blokady przy licznikach kuponów był przyczyną Sylius CVE-2026-31824.
- Ograniczenia w bazie jako druga linia: `CHECK` stanów (`reserved ≤ physical`), ilości 1–99, spójność statusu z datami,
  `UNIQUE` klucza idempotencji, numeru, użycia kodu na zamówienie, wariantu w koszyku.
- Idempotencja: `Idempotency-Key` z odciskiem żądania; rezerwacja po kluczu równym id zamówienia
  (`INSERT … ON CONFLICT DO NOTHING`); e-maile z zajęciem w `ordering_order_notification`.
- Procesy asynchroniczne nie niosą stanu biznesowego; jedyna asynchroniczna zmiana stanu (`ExpireUnpaidOrder`) decyduje
  na aktualnym stanie pod blokadą. Test T11.
- Testy współbieżności w repo (`tests/Integration/Ordering/Concurrency/*`, `Inventory/Concurrency/*`,
  `Cart/Concurrency/*`) pokrywają wyścigi powtórzone z zewnątrz.

**Płatności**

- Podpis surowego ciała sprawdzany przed parsowaniem, `hash_equals`, odmowa przy pustym sekrecie
  (`DemoTransferProvider.php:86-97`, `DemoCardProvider.php:114-146`). Test T02: 13 wariantów, wszystkie 400.
- Jedna transakcja na webhook: blokada wiersza płatności, deduplikacja `INSERT … ON CONFLICT DO NOTHING`
  (`DoctrinePaymentRepository.php:127-134`), kontrola kwoty (`Payment.php:90`), każdy błąd wycofuje też wiersz
  deduplikacji (`HandleProviderWebhookHandler.php:50-89`). Testy T03 (90 × 204, jedno opłacenie), T04.
- Kwota i adres powrotu wyłącznie z serwera (`StartPaymentHandler.php:96-97`), druga kontrola kwoty i waluty w
  `Order::markPaid` (`Ordering/Domain/Order.php:190-193`). Test T06.
- Dostęp przed idempotencją i jedna odpowiedź 404 dla każdego braku dostępu (`StartPaymentHandler.php:78-88`,
  `GetOrderForPaymentHandler.php:28-37`); token gościa porównywany `hash_equals` (`Ordering/Domain/AccessToken.php:36`).
- Unikalne indeksy jako ostateczna granica (jedna oczekująca płatność na zamówienie, klucz idempotencji, para
  dostawca–sesja, dostawca–zdarzenie). Test T06: 1 × 201, 19 × 409.
- Opłacenie, wygaśnięcie i zmiany pracownika szeregowane wersją i blokadą zamówienia (`StaffOrderLock.php`,
  `MarkOrderPaidHandler.php:54-58`); zwrot: jeden na zamówienie. Test T09.
- Status dla klienta tylko z API; cookie potwierdzenia podpisane i `HttpOnly`; token zamówienia gościa zostaje po
  stronie serwera (`app/checkout/confirmation/page.tsx`). Test T12.
- Sekrety losowe na środowisko, na produkcji SealedSecret, `#[\SensitiveParameter]`, `zend.exception_ignore_args`.
- Brak danych kartowych w systemie; przy operatorze z własną stroną płatności (przekierowanie) sklep zostaje w zakresie
  PCI DSS SAQ A, pod warunkiem że formularz karty nigdy nie trafi na domenę sklepu.

**API**

- Jednolity format błędów RFC 9457 bez komunikatów wyjątków, także w debug
  (`apps/api/src/Shared/Infrastructure/Http/ProblemDetailsSubscriber.php`). Test: jądro `prod` z nieosiągalną bazą.
- SQL: parametry, mapy stałych dla sortowania, kursory z walidacją, liczniki ograniczone
  (`Shared/Infrastructure/Persistence/BoundedCounter.php`), brak `OFFSET`. Test: payloady we wszystkich parametrach
  list, żadnego 500.
- Upload: typ po treści, limit bajtów i pikseli przed zapisem, klucz z SHA-256 treści, prywatny bucket, serwowanie
  wyłącznie przez podpisane adresy imgproxy (`Catalog/Infrastructure/Image/*`). Test: 19 wariantów plików.
- Rate limiter przed kontrolerem, klucz bez `X-Forwarded-For` (`Shared/Infrastructure/Http/ClientIp.php`,
  `RateLimitSubscriber.php`). Test: jądro `prod`, `422×10, 429, 429`.
- CORS tylko dla panelu, bez credentials; `nosniff` na każdej odpowiedzi; `http_method_override: false`.
- Varnish: klucz z Host, `pass` dla Authorization/Cookie, błędy `no-store`, PURGE z ACL i tokenem
  (`infra/varnish/default.vcl`). Test od środka sieci.
- Obraz produkcyjny bez Composera, gita i SPX; `expose_php = Off`.

**Frontend**

- BFF nie ufa przeglądarce: `cartId` tylko z podpisanego cookie, lista pól zamówienia
  (`apps/store/src/checkout/api.ts:11-21`), dostawca płatności sprawdzany z listą API, żadnych cen z przeglądarki.
- HTML katalogu nie zależy od sesji; strony konta i koszyka to powłoki, a dane idą przez BFF. Test: izolacja dwóch
  sesji, także 40 żądań równolegle.
- `serializeJsonLd` (`src/catalog/product-json-ld.ts:67-69`) i ucieczka XML mapy strony; React i Vue kodują każdą
  wartość, brak `v-html`. Testy XSS w Chromium: 0 dialogów w sklepie i w panelu.
- Panel: `script-src 'self'`, konfiguracja uruchomieniowa bez sekretów.
- `/api/revalidate`: porównanie sekretu w stałym czasie, słownik tagów, odczyt ciała po autoryzacji.
- Mapy źródeł usuwane z obrazów, optymalizator obrazów wyłączony własnym loaderem. Test: 0 trafień 19 sekretów w
  zasobach, `/_next/image` → 404.

**Infrastruktura**

- Obrazy wdrażane po digeście z jednej rewizji (`image.requireDigests`), obrazy usług `tag@digest` pilnowane testem
  (`infra/k8s/charts/shop/values.yaml:35-47`, `tests/test_images.py`).
- Wieloetapowe Dockerfile z osobnymi etapami `prod`: sklep jako `node`, panel na `nginx-unprivileged` z nagłówkami
  (`apps/admin/docker/security-headers.conf`), API bez narzędzi buildowych; `.dockerignore` wyklucza `.env*`, klucze i
  testy.
- Ekspozycja minimalna: pięć hostów, Keycloak tylko pod trzema prefiksami (`templates/ingress.yaml:13`), wszystko inne
  `ClusterIP`. Test: 12 Service `ClusterIP` w renderze.
- Sekrety: Sealed Secrets w zakresie strict, `check` odrzuca jawny Secret; `init-env.sh` generuje 128–256 bitów entropii
  z `umask 077`; brak wartości domyślnych w kodzie.
- Konta: brak kont demo w klastrze; pierwszy administrator bez hasła z wymuszonym `UPDATE_PASSWORD` i `CONFIGURE_TOTP`
  (`scripts/create-admin-keycloak.sh`).
- Tokeny API Kubernetesa wyłączone domyślnie, projekcja tylko do initContainerów `kubectl` z minimalną rolą
  (`templates/rbac.yaml`, `tests/test_hardening.py:42-63`).
- Kopie (gdy włączone): szyfrowanie, osobny dostawca, codzienna weryfikacja z odtworzeniem, alerty z `absent`.
- Skrypty serwera: weryfikacja klucza hosta, potwierdzenia słowem `tak` dla operacji niszczących, kubeconfig poza repo,
  zapora domyślnie `deny incoming`.
- Błędy bezpieczne: bez `externalTrafficPolicy: Local` lista Cloudflare odrzuca cały ruch; Varnish nie startuje bez
  sekretu purge; render pada bez digestów i przy `clientIpHeader` bez listy adresów.
- Compose: wszystkie porty na `127.0.0.1`, brak gniazda Dockera; Postgres z `scram-sha-256` i rolami bez superusera.
  Testy: `docker inspect` 13 kontenerów, `psql` rolą `shop`.

**Supply chain i CI/CD**

- Wszystkie 4 akcje przypięte do SHA (zweryfikowano zgodność SHA z tagiem), brak akcji stron trzecich; pilnuje tego
  `test_deploy_workflow.py::test_actions_are_pinned_by_sha`.
- Minimalne uprawnienia tokenu: `default_workflow_permissions: read`, `packages: write` tylko w jobach budujących
  obrazy.
- Brak `pull_request_target`, `workflow_run`, `issue_comment`; każdy `run:` ma `pipefail`.
- Wdrożenie tylko ręcznie na `main`, z zatwierdzeniem w środowisku `production`; Argo CD ciągnie `deploy-images` po
  digeście; render weryfikowany z plikiem digestów; sekrety tylko w środowiskach z polityką gałęzi `main` (test
  `test_no_workflow_uses_a_secret_outside_a_main_only_environment`).
- Instalacja z lockfile (`npm ci`, `composer install --no-dev --no-scripts` w buildzie produkcyjnym); `allow-plugins`
  ograniczone do `symfony/runtime` i `symfony/flex`.
- Wszystkie paczki z oficjalnych rejestrów, komplet `integrity`, brak paczek git/URL.
- Secret scanning i push protection włączone; Gitleaks na 326 commitach bez prawdziwego sekretu.

**Prywatność i obserwowalność**

- Filtry Sentry w trzech aplikacjach (`apps/api/src/Shared/Infrastructure/Observability/SentryEventFilter.php`,
  `apps/store/src/observability/filter.ts`, `apps/admin/src/observability/filter.ts`): `send_default_pii: false`, bez
  ciała żądania, cookies, użytkownika i zmiennych ramek; brak Session Replay.
- Minimalizacja: zdarzenia i kolejki niosą tylko identyfikatory; zakres OIDC `scope=openid`; brak daty urodzenia i
  danych płatniczych w zamówieniu. Testy: `messenger_messages`, kolejka `events`, Elasticsearch bez danych klientów.
- Historia cen, ruchów magazynowych i statusów z aktorem, bez endpointu modyfikacji. Test: zmiana ceny i anulowanie
  kontem `admin`.
- `X-Correlation-ID` generowany po stronie serwera i obecny w każdym wpisie logu.
- Alloy: maskowanie przed opuszczeniem klastra (e-maile, JWT, `Bearer`, adresy IP), Mailpit wyłączony ze zbierania,
  ClusterRole bez dostępu do Secretów, test na prawdziwym Alloy.
- Logi po pełnym przebiegu testów nie zawierały danych adresowych, telefonów, nagłówków `Authorization`/`Cookie` ani
  tokenów zamówień (przeszukanie 13 usług).

## 9. Zbędne lub nadmiernie skomplikowane zabezpieczenia

| Co | Dlaczego ma małą wartość albo jest pozorne | Co zamiast (jeśli audytor wskazał) |
|---|---|---|
| Limit logowań BFF (`login-rate-limit.ts`, ~90 linii z własnym parserem IPv6) | chroni Redis przed transakcjami, nie hasła (SA-09); komunikat sugeruje ochronę przed zgadywaniem; bez `CLIENT_IP_HEADER` jeden wspólny licznik pozwala 30 żądaniami zablokować logowanie wszystkim | limit per adres w Traefiku albo Cloudflare przed `auth.<domena>` |
| Cloudflare Access przed panelem | osłania tylko statyczne pliki SPA; API panelu i logowanie realmu `backoffice` są publiczne (SA-35) | objąć Access API panelu i logowanie albo nazwać go w dokumentacji utrudnieniem |
| Podpis HMAC cookie sesji i koszyka | identyfikator sesji ma 256 bitów entropii; podpis nie chroni przed podrzuceniem cudzego ważnego cookie (SA-27); realną wartość ma tylko dla `store_order_confirmation` | — |
| Jeden klucz HMAC dla pięciu rodzajów cookies bez separacji celu | dziś bez skutków (różne formaty wartości), ale bezpieczeństwo zależy od tych formatów | nazwa cookie w podpisywanej treści |
| Własna blokada odświeżania tokenu w Redis (`refresh.ts`, ~150 linii) | poprawna, ale ma sens bezpieczeństwa dopiero po włączeniu rotacji refresh tokenów (SA-26) | włączyć `revokeRefreshToken` |
| `KeySourceOutageTokenHandler` | rozróżnia 503 od 401 po typie wyjątku `previous` wewnętrznej implementacji Symfony; kruche przy aktualizacji frameworka | — |
| `VIEW_PENDING_PAYMENTS`, `VIEW_SALES`, `VIEW_CUSTOMERS` | wyglądają jak granice poufności, a ograniczają tylko widok (SA-32); specyfikacja mówi „każdy endpoint `/api/v1/admin` ma voter”, a 9 tras odczytu ma tylko `ROLE_STAFF` | egzekwować w handlerze listy zamówień albo usunąć jako granice |
| Ukrywanie id koszyka gościa w cookie `HttpOnly` | BFF zwraca je w polu `id` odpowiedzi `/api/cart`, a API ma je w ścieżce (logi: SA-39) | — (wpływ pomijalny) |
| Jawny token dostępu gościa w bazie; kolejne numery zamówień | drobiazgi (SA-42) | skrót SHA-256; losowy składnik numeru |
| Limit zamówień jako ochrona stanu | liczy żądania, nie zasoby; bez blokady (`lock_factory: null`) i fail-open przy awarii Redisa; jedno żądanie rezerwuje do 4950 sztuk (SA-04) | limit nieopłaconych zamówień i rezerwowanych sztuk |
| „Liczba sztuk nigdy nie jest podawana” | stan do odczytania w 7 żądaniach (SA-42) | — |
| Rozdzielenie ról cena (admin) / promocje i kody (marketing) | marketing może obniżyć cenę o 99% promocją i kodem | — |
| Reguły walidacji zdublowane ręcznie w `PlaceGuestOrderRequest` i `PlaceCustomerOrderRequest` | obrona w głąb jest świadoma, ale kopie mogą się rozjechać | wspólna klasa ograniczeń (`Assert\Compound`) |
| Własny limiter per trasa (`RateLimit::ROUTE_DEFAULT` + subskrybent) | działa i jest proporcjonalny (natywny atrybut nie istnieje), ale bez blokady i z za krótką listą tras (SA-14, SA-15, SA-30) | natywna konfiguracja `framework.rate_limiter` z `lock_factory` |
| Token „CSRF” strony symulatora (`PaymentPageController.php:91-94`) | bez sesji, wypisany każdemu, kto otworzy stronę; używa sekretu webhooków (SA-40) | osobny klucz albo brak tokenu |
| Limit webhooków na adres nadawcy | nie chroni przed niczym, czego nie zatrzymuje podpis; wszystkie dostawy symulatora w jednym kubełku | — |
| Powielone parsery w `Payment/Infrastructure/Provider` i `PaymentSimulator` | koszt świadomego rozdziału modułów | znika, gdy symulator wyjdzie z kodu produkcyjnego |
| Własny odbiór webhooków, czekanie na unikalnym indeksie, jedna wiadomość wygaszająca | działają, ale mają natywne odpowiedniki | Symfony Webhook, Symfony Lock albo blokada doradcza, Symfony Scheduler |
| Sprawdzenie `Content-Length > post_max_size` w `UploadProductImageController.php:86-90` | nie ogranicza ciał JSON ani żądań `chunked` (SA-12) | limit w serwerze HTTP |
| Wymiary obrazu czytane tylko z nagłówka | PNG z podmienionym nagłówkiem i złym CRC jest przyjmowany (201); oryginały bez produktu nigdy nie są usuwane (ok. 310 plików zapełnia 20 Gi Garage) | — (imgproxy ma własny limit) |
| `PhpSerializer` w transportach Doctrine Messengera | `unserialize` treści z `messenger_messages`; przy braku SQLi nieosiągalne | `messenger.transport.symfony_serializer` |
| Brak `framework.trusted_hosts` | dziś nagłówek Host nie trafia do żadnego adresu | jedna linia konfiguracji na przyszłość |
| `X-Frame-Options: DENY` obok `frame-ancestors 'none'` | powielenie, nieszkodliwe | można zostawić dla starych przeglądarek |
| CSP sklepu w obecnym kształcie | chroni tylko przed osadzeniem w ramce i `<base>`; nazwa sugeruje ochronę przed XSS (SA-29) | CSP z `default-src`, `script-src`, `connect-src`, `form-action` |
| `Permissions-Policy` z trzema nieużywanymi funkcjami | bez wartości bez dodania np. `payment=()`, `usb=()`, `browsing-topics=()` | — |
| Filtr zdarzeń Sentry i wzorce maskowania w czterech kopiach (Alloy, API, sklep, panel) | duży względem tego, co daje ponad ustawienia SDK; kopie już się rozjechały (wzorzec koszyka gościa jest w API i sklepie, nie ma go w Alloy i panelu) | jedna kopia po stronie serwera albo usuwanie danych u źródła (procesor Monolog) |
| Korpus testu maskowania Alloy | sprawdza wzorce na liniach, których aplikacje nie emitują | rzeczywiste wpisy ze stosu (SA-39) |
| Maskowanie każdego klucza z `token` w Alloy | usuwa z logów tożsamość użytkownika Symfony: zabezpieczenie prywatności działa przeciw wykrywaniu (SA-19) | wyjątek dla kanału `security` |
| Kryterium specyfikacji Sentry „401 nie daje zdarzeń” | testowane tylko dla żądania bez tokenu (SA-18) | test z błędnym tokenem |
| Własna logika poziomów logów wyjątków domenowych | 4xx logowane jako `CRITICAL` | natywne `framework.exceptions` |
| Nierówny rozkład utwardzenia: przestrzeń `monitoring` | własny RBAC, trzy `ValidatingAdmissionPolicy`, osobny test dymny i ~600 linii manifestu dla przestrzeni bez danych klientów, podczas gdy `shop` nie ma NetworkPolicy, `securityContext` ani rozdzielenia sekretów; operator Alloy z kube-state-metrics i node-exporterem dla jednego węzła i siedmiu alertów to powierzchnia i koszt większe od wartości | najpierw SA-05, SA-06, SA-33 |
| `ufw` jako „firewall hosta” | nie chroni portów 80/443 (DNAT), a poza SSH otwiera tylko niepotrzebnie publiczny 6443 (SA-34) | usunąć regułę 6443 |
| Podwójna gałąź `deploy`/`deploy-images`, app-of-apps z pięcioma aplikacjami i falami, Joby nazwane sumą kontrolną, initContainery z `kubectl rollout status` | działają i są przetestowane, ale dla jednego węzła to dużo ruchomych części bez istotnego zysku bezpieczeństwa | nie przebudowywać; nie dokładać warstw przed SA-05 i SA-06 |
| Mailpit, bucket `product-images-test` i wtyczka management RabbitMQ w `prod` | elementy deweloperskie utrzymywane w klastrze dla zgodności z compose (SA-36) | renderować warunkowo |
| Zasada „chart odzwierciedla compose” dla sekretów | przeniosła do klastra wzorzec `env_file` (SA-05), a `test_secrets.py` go utrwala | natywne `secretKeyRef`, `NetworkPolicy`, Pod Security Admission, Redis `requirepass`/ACL, `xpack.security` |
| Własne skrypty orkiestracji CI (`.github/scripts/background.sh`, `retry.sh`, `ghcr-image.sh`) | uzasadnione wydajnością, ale zwiększają powierzchnię utrzymania; luki nie znaleziono | — |
| Brak podpisu i atestacji obrazów (`provenance: false`, brak cosign/SLSA) | przy modelu digest + ruleset + zatwierdzenie to utwardzenie, nie luka | rozważyć przy publicznym ruchu |

Audytorzy wskazali też elementy własne, których nie należy zastępować: sanitizer `returnTo` (poprawny, bez natywnego
odpowiednika) oraz firewalle, `access_control`, `#[IsGranted]`, votery i `#[CurrentUser]`, użyte natywnie.

## 10. Brakujące testy bezpieczeństwa

**Uwierzytelnianie i Keycloak**

- Test konfiguracji realmów: polityka haseł, `directAccessGrantsEnabled` klientów domyślnych, wymuszone PKCE,
  `revokeRefreshToken`, parametry brute force, zdarzenia (SA-08, SA-09, SA-11, SA-26);
  `infra/k8s/tests/test_switches.py` utrwala dziś `redirectUris: <admin>/*` jako oczekiwane (SA-28).
- Test dryfu: porównanie efektywnej konfiguracji Keycloaka po `keycloak-config` na istniejącym realmie z plikiem realmu
  (SA-10).
- Test E2E/integracyjny na prawdziwym Keycloaku: rejestracja ze słabym hasłem, blokada po serii błędnych logowań, ROPC,
  odrzucenie żądania bez PKCE, dokładne dopasowanie `redirect_uri` (SA-08, SA-09, SA-24, SA-28).
- Test limitu per adres na endpointach Keycloaka (limitu nie ma: SA-09).
- API: test z ID tokenem i refresh tokenem prawdziwego Keycloaka oraz test nagłówków `jwk`/`jku`/`x5u` (dziś bezpieczne
  z konstrukcji handlera).
- BFF: test dwóch cookies o tej samej nazwie (SA-27); test, że wylogowanie unieważnia tokeny po stronie Keycloaka, i
  test E2E zakończenia sesji w Keycloaku przy żywej sesji sklepu (SA-26).

**Autoryzacja**

- Jedna tabela rola × trasa dla zapisu i odczytu w panelu, z trasami z routera (`AdminRouteAccessTest` sprawdza tylko
  `ROLE_STAFF`; SA-32).
- Testy jednostkowe `StockVoter`, `CategoryVoter`, `PromotionVoter`.
- Test funkcjonalny wariantów ścieżki i metody dla tras chronionych (końcowy `/`, kodowanie, `/index.php/…`,
  `X-HTTP-Method-Override`).
- Test funkcjonalny (HTTP) pełnej macierzy właściciela: klient ↔ klient, klient ↔ gość, gość ↔ gość dla zamówień,
  płatności i koszyków.
- Test mass assignment dla `POST /orders` i `POST /me/orders`.
- E2E: klient B otwiera `/konto/zamowienia/{id}` klienta A; podmienione cookie `store_order_confirmation`.

**Logika biznesowa**

- Test (i reguła) limitu jednocześnie nieopłaconych zamówień i rezerwowanych sztuk na tożsamość (SA-04).
- Test limitu prób kodu w podglądzie koszyka (SA-14).
- Test treści e-maili z wrogimi danymi kontaktowymi (SA-31).
- Test ścieżki „`ExpireUnpaidOrder` w `failed`”: co wykrywa i naprawia zaległe zamówienie (SA-23).
- Test serii równoległej limitera (przekroczenie limitu bez blokady) i zachowania przy niedostępnym Redisie (SA-03,
  SA-04).
- Test E2E wyścigu „płatność przed terminem, webhook w chwili wygaszenia” przeciw prawdziwemu workerowi.
- Test właściwości (property-based) alokacji rabatu.

**Płatności**

- Test rozpoczęcia płatności przez prawdziwe HTTP do symulatora w tym samym procesie i test obciążeniowy „N równoległych
  rozpoczęć jednego zamówienia” w `load/` (w `test` klient to `MockHttpClient`, `config/services.yaml:374-378`, więc
  SA-03 jest niewidoczne dla `StartPaymentConcurrencyTest` i E2E).
- Test, że konfiguracja ze sprzedażą nie wystawia `/payment-simulator/*` (SA-02).
- Test drugiej udanej płatności od strony klienta i test anulowania pozostałych sesji po opłaceniu albo wygaśnięciu
  (SA-22).
- Test utraconego webhooka: płatność `pending` po wygaśnięciu zamówienia (SA-23).
- Test rozdzielenia kluczy (token formularza a podpis webhooka) i odrzucenia `paidAt` sprzed utworzenia płatności
  (SA-40).

**API**

- Limit rozmiaru ciała na endpointach publicznych (> 1 MB → 413), limit czasu odczytu ciała i test obrazu `prod`, że
  błąd fatalny nie zwraca treści (SA-12).
- Limit tworzenia koszyków gościa; `RateLimitEndpointTest` obejmuje dziś tylko trzy limitery (SA-14, SA-15).
- Odrzucenie klucza porcji mapy strony spoza listy granic (SA-30).
- Test, że przychodzący `sentry-trace` spoza panelu nie wymusza próbkowania (SA-41).
- Jeden test przechodzący wszystkie parametry `sort`/`q`/`after` list panelu z payloadami wstrzyknięć i sprawdzający
  brak 500.

**Frontend**

- Test wzrostu cache ISR i zachowania dla slugów spoza formatu (SA-13).
- Test limitu ciała żądania w handlerach BFF (SA-12).
- Test nagłówków na odpowiedzi serwera, także stron z danymi (`tests/security-headers.test.ts` sprawdza tylko
  konfigurację; SA-29).
- Test E2E stored XSS (produkt, kategoria, zamówienie z payloadem w sklepie i w panelu).
- Test izolacji odpowiedzi między dwiema sesjami w buildzie produkcyjnym.
- Test panelu na obrazie nginx w CI (compose i E2E używają `vite dev` bez nagłówków).
- Test, że `/_next/image` pozostaje wyłączony.

**Infrastruktura**

- Test renderu ograniczający klucze sekretu per workload (istniejący `test_secrets.py:19-29` wymaga odwrotności), w tym
  brak `KC_BOOTSTRAP_ADMIN_PASSWORD` poza Keycloakiem i jobem oraz `envFrom` całego Secretu w `store`, imgproxy i
  Varnishu (SA-05).
- Test renderu `NetworkPolicy` z domyślną odmową i test dymny, że z poda `imgproxy`/`varnish` nie da się połączyć z
  `postgres`, `redis`, `elasticsearch`, `mailpit`; test, że Redis i Elasticsearch wymagają uwierzytelnienia
  (`test_workloads.py:139` sprawdza dziś, że Elasticsearch jest bez security; SA-06).
- Test `securityContext` i test, że obraz `prod` API nie działa jako root (SA-33).
- Test, że profil `prod` ma włączone kopie i prawdziwy adres repozytorium oraz nie używa Mailpita, symulatora płatności
  ani bucketu testowego (SA-07, SA-36, SA-02).
- Faktyczne odrzucenie ruchu spoza `ipAllowList` (test dymny działa z `ipAllowList.enabled: false`) i cykliczne
  sprawdzenie zakresów Cloudflare (SA-35).
- Asercje o regułach, których w `server-bootstrap.sh` ma nie być (6443 dla wszystkich), i o konfiguracji `sshd` (SA-34).
- Test przypięcia obrazów bazowych Dockerfile skrótem i sumy kontrolnej instalatora k3s (SA-38).

**Supply chain**

- Kontrola „żadna zależność produkcyjna nie ma znanej High/Critical” (`composer audit`, `npm audit`; SA-16, SA-17).
- Kontrola aktualności i CVE obrazów (`make check` sprawdza tylko zgodność digestów compose ↔ chart) oraz kadencji
  rebuildów obrazów bazowych (SA-01, SA-38).
- Asercja na podpis albo atestację obrazów w `test_deploy_workflow.py`.

**Prywatność i logi**

- Sentry: test „401 z nieprawidłowym lub wygasłym tokenem nie daje zdarzenia” (SA-18).
- Maskowanie Alloy: rzeczywiste wpisy Symfony, Keycloaka i Next.js w korpusie; test identyfikatora koszyka gościa i
  `discountCode` (SA-39).
- Test, że log `prod` nie zawiera nagłówków i ciał żądań (dziś wynika to z ustawień domyślnych).
- Ślad audytowy: testy, że zmiana i usunięcie promocji oraz zmiana kodu zostawiają wpis z aktorem (SA-20).
- Test trafienia limitera (429) pod kątem logu lub metryki (SA-19).
- Testy retencji poza koszykami (funkcji brak: SA-21).

## 11. Wyniki kontroli zależności

**Narzędzia i wersje.** Audytor supply chain pracował na eksporcie `origin/main` (`ef204620`) i na działającym stosie
(odczyt): `composer audit` i `composer audit --locked` w kontenerze `api` (Composer 2.10.3, PHP 8.5.12),
`composer validate`; `npm audit` (prod i dev) w kontenerach `store` i `admin` (npm 11.19.1), `e2e`/`load` przez ocenę
lockfile i Trivy; Trivy 0.76.1 (obraz przypięty digestem, `--disable-telemetry`, bez trybów upload): `trivy fs`,
`trivy config` (Dockerfile i chart z wartościami `prod`), `trivy image` obrazów `shop-secaudit-{api,store}` oraz obrazów
usług (keycloak, imgproxy, nginx-unprivileged, varnish); Gitleaks 8.30.1 na pełnej historii osiągalnej z `origin/main`;
stan repozytorium w GitHubie przez `gh api -X GET`. Surowe wyniki: `work/sc/` (`composer-audit*.json`,
`npm-audit-*.json`, `trivy/`, `gitleaks/`, `advisories/`). Wiersze oznaczone „(research)” pochodzą wyłącznie z tabeli
wersji i advisories w `stack_cves_and_hardening.md` i nie były potwierdzane skanem audytora.

| Komponent | Wersja w repo | Wersja z poprawką | Czego dotyczy | Osiągalność w projekcie | Wniosek |
|---|---|---|---|---|---|
| Keycloak (`quay.io/keycloak/keycloak`) | 26.6.0 | wg skanera 26.4.15, 26.6.6, 26.7.2; upstream: linia 26.6 kończy się na 26.6.4, bieżące 26.8.2 | CVE-2026-18963 (reset hasła, Critical), CVE-2026-7507, -7571, -11800, -9086, -7504 | flow resetu w realmie `shop` publiczny (200); obejścia nie odtworzono | SA-01: aktualizacja do linii z poprawką |
| `symfony/serializer` | 8.1.10 | 8.1.11 | GHSA-hqp2-7rr8-hf26 (CVE-2026-122053), klucze z kropką w `ObjectNormalizer` | 25 kontrolerów z `#[MapRequestPayload]` | osiągalne; główne ryzyko zestawu (SA-17) |
| `symfony/serializer` | 8.1.10 | 8.1.11 | GHSA-h38r-xrmh-cwhr (CVE-2026-118515), GHSA-pxj9-jh84-vhm5 (CVE-2026-121623): odczyt pliku przez payload | wymaga DTO z własnością `UploadedFile`/`SplFileInfo`; upload idzie przez `$request->files` | niska osiągalność dziś |
| `symfony/http-foundation`, `framework-bundle` | 8.1.9 | 8.1.11 | GHSA-xg2q-4rrq-657q (`private_ranges`), GHSA-jrf8-gvm4-7cgh (HEAD), GHSA-3h27-hjv7-fqjx (nazwa skryptu), GHSA-5mx8-mwrp-5693 (base URL) | brak `trusted_proxies`; reguły chroniące bez `methods:`; testy: 401, 401, 404 | nieosiągalne w tej konfiguracji |
| `symfony/http-client` | 8.1.10 | 8.1.11 | GHSA-gq53-v8w2-gjhj, GHSA-c7hm-wqwp-f9v8 | brak `ScopingHttpClient` i `NoPrivateNetworkHttpClient` | nieosiągalne |
| `symfony/yaml` | 8.1.9 | 8.1.11 | GHSA-hwfg-qwvh-2wfx (ReDoS parsera) | wg audytora `Yaml::parseFile` tylko na pliku z repo; notatki researchu: `#[MapRequestPayload]` przyjmuje YAML, gdy endpoint nie ogranicza `acceptFormat` | nieosiągalne wg audytora; do potwierdzenia (Uwagi redaktora) |
| `symfony/config`, `validator`, `routing`, `http-kernel`, `filesystem`, `security-http` | 8.1.5 / 8.1.9 / 8.1.10 | 8.1.11 | XXE `XmlUtils`, `Ip` validator, placeholder hosta, fragmenty inline, `Filesystem\Path`, X509 i `checkPostAuth` | funkcje nieużywane | nieosiągalne |
| `next` | 16.4.3 | 16.4.5 (dostępne 16.4.6) | `npm audit`: GHSA-mfr8-8qww-v76h, GHSA-vc7v-qv4w-wrv8; (research) łącznie 7 advisories „moderate” obejmuje 16.4.3 | brak `use cache`, `unstable_cache`, `cacheComponents`, root params; `/_next/image` → 404 | ekspozycja teoretyczna; podbić przy okazji (SA-38) |
| `web-token/jwt-library` (research) | 4.2.3 | 4.2.4 i 4.2.6 (najnowsza 4.2.7) | 13 advisories z 2026-10-31 i 2026-11-08, większość dotyczy JWE; GHSA-2xq2-pp89-x7fr: dekodowanie JSON przed weryfikacją podpisu | API weryfikuje tylko JWS; GHSA-2xq2 według notatek osiągalne dla każdego nadawcy nagłówka `Authorization` | nieujęte w wynikach audytora; do weryfikacji |
| FrankenPHP (obraz bazowy API) (research) | 1.13.4 | 1.13.5 | 17 advisories z 2026-11-05, m.in. GHSA-j45x-h238-h4xx (`request_body_timeout`), GHSA-2725-rh2w-9j9w (pole `host` w HTTP/2) | nieoceniane przez audytorów; Trivy: Critical `linux-libc-dev` w obrazie `api` | rebuild i podbicie bazy (SA-38, SA-12) |
| imgproxy | v3.31.1 | Trivy: upstream v3.31.4 / v4.x; (research) błędy loadera ICO naprawione tylko w 4.0.x | Trivy: Critical CVE-2026-33186 (gRPC), 54 High; (research) GHSA-494m-4556-wf5p, GHSA-mmq5-xm89-9pfg | wektor zawężony do pliku wgranego do bucketu; upload odrzuca SVG/HTML/GIF (422) | priorytet w odświeżaniu obrazów (SA-38) |
| `nginxinc/nginx-unprivileged` | 1.30.6-alpine | — | Trivy: Critical brotli CVE-2026-111497; (research) sam nginx 1.30.6 „Not vulnerable” | panel to statyczne pliki | rebuild obrazu |
| Node (obraz bazowy sklepu i panelu) | 24.22.1-alpine | 24.22.3 | wydanie bezpieczeństwa (http2/tls High); (research) 16 CVE, 7 high | (research) sklep działa po HTTP/1.1 bez TLS za proxy | podbić |
| PostgreSQL (research) | 18.6 | 18.7 | 52 CVE, większość wymaga uwierzytelnionej sesji SQL; narzędzia `pg_dump`/`pg_restore` | aplikacja nie daje użytkownikom dowolnego SQL | nieujęte w skanie audytora; aktualizacja |
| Elasticsearch (research) | 9.4.0 | 9.4.9 | 45 ogłoszeń ESA | przy wyłączonym security każdy z dostępem do portu jest „uwierzytelniony” (SA-06) | aktualizacja i izolacja sieci |
| Mailpit (research) | v1.27.11 | v1.34.0 | m.in. CSWSH (GHSA-524m-q5m7-79mm), SSRF, nieuwierzytelnione DoS | Mailpit działa w `prod` i trzyma pocztę zamówień (SA-36) | aktualizacja albo usunięcie z `prod` |
| Redis, RabbitMQ, Varnish (research) | 8.10.3 / 4.4.0-management / 9.1.0 | — | brak znanych otwartych advisories dla tych wersji | — | bez działań poza konfiguracją (SA-06) |
| Vite (dev, panel i testy) (research) | 8.3.5 | 8.3.7 | GHSA-p4f5-76q4-ffq7: RCE na serwerze deweloperskim wystawionym w sieci | tylko dev; porty compose wyłącznie na `127.0.0.1` | nie dotyczy produkcji; podbić |
| Argo CD, sealed-secrets, k3s (research) | v3.6.1 / 0.41.2 / v1.36.6+k3s1 | — | advisories z 2026 r. nie obejmują tych wersji | — | bez działań |
| Doctrine ORM/DBAL, `openid-client`, `oidc-client-ts`, Vue (research) | 3.7.5 / 4.5.0, 6.8.8, 3.5.0, 3.5.44 | — | brak opublikowanych advisories dla tych wersji | — | bez działań |

**Gitleaks.** 326 commitów (`--redact`, `--log-opts=origin/main`), 59 trafień, wszystkie po ręcznym przeglądzie
fałszywe; brak prawdziwego sekretu produkcyjnego w historii. Produkcyjne sekrety w repo to SealedSecrets (szyfrogramy),
`prod/sealed-secrets.pem` to klucz publiczny, `.env.example` nie ma wartości.

**Stan GitHub Actions.** Wszystkie 4 akcje (`checkout` v7.0.1, `setup-buildx-action` v4.4.1, `build-push-action` v7.4.0,
`upload-artifact` v7.0.2) są przypięte do SHA zgodnego z tagiem; nie ma akcji stron trzecich. Token:
`default_workflow_permissions: read`, `can_approve_pull_request_reviews: false`, `packages: write` tylko w jobach
budujących obrazy. Brak `pull_request_target`, `workflow_run`, `issue_comment`. Wdrożenie: `workflow_dispatch` na
`main`, zatwierdzenie w środowisku `production` (required reviewer), obrazy budowane raz i tagowane SHA commitu,
weryfikacja digestów (`verify-images.sh`), atomowy fast-forward bez force, jeden deploy key jako aktor bypass; sekrety
`DEPLOY_KEY` i `SENTRY_AUTH_TOKEN` wyłącznie w środowiskach z polityką gałęzi `main`. Secret scanning i push protection
są włączone; Dependabot, alerty podatności i code scanning nie (SA-16). Webhooki repozytorium: 0.

**Zastrzeżenia.**

- Trivy `fs` raportował część pakietów Symfony z severity `UNKNOWN`; severity ustalano z advisory upstream.
- Według notatek researchu OSV.dev jest opóźnione wobec advisories repozytoriów o ok. 2 tygodnie (dla `next@16.4.3`
  zwróciło 2 z 7 advisories, dla Symfony 8.1.9/8.1.10 nic), więc brak wyniku w bazie nie dowodzi braku podatności.
- Nie wykonano destrukcyjnych PoC dla CVE-2026-18963 ani CVE-2026-122053; osiągalność oceniono z konfiguracji, advisory
  i publicznego patcha.
- Obrazów `shop-secaudit-{admin,worker}` nie skanowano osobno (admin to statyczny nginx z `dist`, worker to ten sam
  obraz co `api`); nie odczytano listy pakietów GHCR (brak uprawnień tokenu).
- Audytorzy uwierzytelniania i frontendu zaznaczyli, że nie weryfikowali CVE swoich bibliotek w bazach (`openid-client`
  6.8.8, `oidc-client-ts` 3.5.0, React 19.3.1, Vue 3.5.44); `npm audit` uruchomił tylko audytor supply chain.

## 12. Ocena gotowości do publicznego wdrożenia

| Scenariusz | Gotowość | Warunki |
|---|---|---|
| Publiczne demo bez prawdziwych danych i pieniędzy (stan obecny) | warunkowo tak | Natychmiast: SA-01 (Keycloak), SA-17 i SA-38 (aktualizacje). Wkrótce: SA-03 i SA-04, bo pozwalają jednej osobie unieruchomić albo „wyprzedać” demo; SA-07, jeśli stan demo ma przetrwać awarię. |
| Prawdziwi klienci i ich dane osobowe (konta, zamówienia), bez płatności online | nie | Dodatkowo: SA-05, SA-06 (izolacja w klastrze), SA-07 (kopie), SA-08…SA-11 (Keycloak: hasła, limity prób, zdarzenia), SA-12…SA-15 (limity zasobów i prób), SA-16 (kontrola podatności), SA-18…SA-21 (monitoring, ślad audytowy, usuwanie i retencja danych), SA-36 (poczta zamówień poza Mailpit). |
| Prawdziwe płatności | nie | Dodatkowo: prawdziwy operator za istniejącym portem i wyłącznik symulatora (SA-02), wywołanie operatora poza transakcją (SA-03), rekoncyliacja, zwroty i widoczność płatności do zwrotu (SA-22, SA-23), uzupełnienia adapterów (SA-40). |

Uwagi:

- Ocena dotyczy konfiguracji w repozytorium. Stan serwera demo, Cloudflare, GitHuba (poza odczytem ustawień),
  dostawcy poczty, R2, Sentry i Grafana Cloud nie był sprawdzany.
- Zgodnie z zasadą projektu nie twierdzę nic o wydajności: wektory wyczerpania zasobów są potwierdzone co do
  mechanizmu, a ich skutek na produkcji (OOM, zapełnienie dysku) jest wyliczony z limitów chartu, nie zmierzony.
- Architektura płatności (przekierowanie do operatora, brak danych kartowych w systemie) utrzymuje sklep w zakresie
  PCI DSS SAQ A, o ile formularz karty nigdy nie trafi na domenę sklepu.
- Zgodności z RODO nie da się wykazać analizą kodu; raport ocenia wyłącznie techniczne środki ochrony.

## 13. Priorytetowy plan napraw

Kolejność według ryzyka. „Zgłoszenie” to istniejąca pozycja backlogu, której dotyczy znalezisko (odczyt z GitHuba
18.11; niczego nie tworzono ani nie zmieniano).

### P0 — natychmiast (dotyczy także obecnego demo)

| # | Działanie | Znaleziska | Nakład | Zgłoszenie |
|---|---|---|---|---|
| 1 | Aktualizacja Keycloaka do wersji z poprawkami; nowy tag i digest w compose i chartcie; E2E logowania | SA-01 | M | brak (nowe) |
| 2 | `composer update "symfony/*"` do 8.1.11; Next.js do 16.4.5+; Node 24.22.3; odświeżenie obrazów bazowych | SA-17, SA-38 | S | #411 (Symfony) |
| 3 | Alerty i security updates Dependabota; `composer audit` i `npm audit --omit=dev` w `make check` | SA-16 | S | brak (nowe) |
| 4 | Równoległe rozpoczęcia płatności tego samego zamówienia odrzucane od razu (409), bez czekania na indeksie | SA-03 | S | brak (nowe) |
| 5 | Limit rezerwowanego towaru i jednocześnie nieopłaconych zamówień gościa | SA-04 | M | #332 |
| 6 | Włączenie kopii zapasowych w `prod` i test, że profil `prod` je ma | SA-07 | S–M | #690 |

### P1 — przed powierzeniem danych prawdziwych klientów

| # | Działanie | Znaleziska | Nakład | Zgłoszenie |
|---|---|---|---|---|
| 7 | Sekrety na usługę (`secretKeyRef`) zamiast wspólnego `envFrom`; konto serwisowe dla joba `keycloak-config` | SA-05 | M | #409, #485 |
| 8 | NetworkPolicy z domyślną odmową; hasło Redisa; uwierzytelnienie Elasticsearch | SA-06 | M | #410 |
| 9 | Keycloak: polityka haseł, limity prób na adres, wyłączenie ROPC w `admin-cli`, zdarzenia logowania i administracyjne, krok joba przenoszący te ustawienia do istniejącego realmu | SA-08, SA-09, SA-10, SA-11 | M | brak (polityka haseł była w SR-13) |
| 10 | Limit rozmiaru i czasu ciała żądania (Caddy `request_body`, limity w route handlerach BFF) | SA-12 | S | #413 |
| 11 | Limity: tworzenie koszyków gościa, podgląd kodów rabatowych; licznik limitera z blokadą | SA-14, SA-15 | S–M | #406, #407, #421 |
| 12 | Cache ISR tylko dla istniejących produktów albo z limitem i sprzątaniem; limit `ephemeral-storage` | SA-13 | S–M | brak (nowe) |
| 13 | Błędny token nie tworzy zdarzenia Sentry (poziom logu albo filtr) | SA-18 | S | brak (nowe) |
| 14 | Minimalne sygnały bezpieczeństwa: liczniki 401/403/429, alert na kolejkę `failed`, na płatności do zwrotu, na serię nieudanych logowań | SA-19 | M | brak (nowe) |
| 15 | Ślad audytowy dla promocji, kodów, kategorii i wglądu w dane klientów | SA-20 | M | brak (nowe) |
| 16 | Usuwanie i anonimizacja danych, eksport, retencja zamówień gości, polityka prywatności | SA-21 | L | #119 |
| 17 | Poczta zamówień przez prawdziwego dostawcę zamiast Mailpit; bucket testowy poza `prod` | SA-36 | S | brak |

### P2 — przed prawdziwymi płatnościami

| # | Działanie | Znaleziska | Nakład | Zgłoszenie |
|---|---|---|---|---|
| 18 | Wyłącznik symulatora (domyślnie wyłączony przy sprzedaży), test chartu i test 404 | SA-02 | S | częściowo SR-11/SR-12 z przeglądu 10.11 |
| 19 | Adapter prawdziwego operatora za istniejącym portem | SA-02 | L | brak (epik) |
| 20 | Wywołanie operatora poza transakcją bazy | SA-03 | M | brak |
| 21 | `fetchStatus` i `refund` w porcie; zadanie rekoncyliacji; wygaszanie jako zadanie okresowe; lista płatności do zwrotu w panelu; anulowanie pozostałych sesji po opłaceniu | SA-22, SA-23 | M–L | częściowo #221 (wg specyfikacji) |
| 22 | Uzupełnienia adapterów: osobny klucz tokenu formularza, znacznik czasu w podpisie, lista sekretów, porównanie numeru zamówienia | SA-40 | S | #444 (pokrewne) |

### P3 — utwardzenie (może poczekać)

SA-24…SA-35, SA-37, SA-39, SA-41, SA-42: blokada cudzego konta, enumeracja przez rejestrację, rotacja refresh
tokenów, prefiks `__Host-` i HSTS, wildcard `redirectUris` panelu, CSP, kosztowne odczyty, treść atakującego
w mailach, role tylko do odczytu, `securityContext`, port 6443 i `sshd`, ochrona originu, rotacja sekretów,
maskowanie logów, `sentry-trace`. Większość to nakład S; najlepiej zebrać je w jeden epik przed startem sprzedaży.

### Znaleziska a poprzedni przegląd (10.11)

Audyt prowadzono bez wglądu w przegląd `docs/reports/security-review-2026-11-10.md`; porównanie zrobiono po fakcie.

- **Ponownie znalezione, nadal otwarte:** blokowanie towaru (SR-16 → SA-04, #332), koszyki gościa (SR-17 → SA-15,
  #406), kody rabatowe (SR-18 → SA-14, #407), wspólny Secret (SR-7 → SA-05, #409), NetworkPolicy i Redis (SR-8 →
  SA-06, #410), Symfony 8.1.11 (SR-14 → SA-17, #411), limit JSON (SR-3 → SA-12, #413), `securityContext` (SR-9 →
  SA-33), polityka haseł (SR-13 → SA-08).
- **Naprawione od tamtego czasu (według kodu):** odczyty panelu bez roli personelu (SR-4). Regułę potwierdza kod
  i test `AdminRouteAccessTest`; konta personelu bez roli nie było na stosie, więc nie sprawdzono jej dynamicznie.
- **Nowe w tym audycie:** SA-01 (Keycloak), SA-03 (samoblokada API), SA-02 jako brak wyłącznika, SA-09…SA-11
  (limity i zdarzenia Keycloaka), SA-13 (cache ISR), SA-16 (brak kontroli podatności), SA-18…SA-23 (monitoring,
  ślad audytowy, RODO technicznie, rekoncyliacja płatności) oraz cały obszar supply chain, prywatności i płatności,
  których poprzedni przegląd nie obejmował.

## 14. Propozycje epików bezpieczeństwa

Pogrupowane według rzeczywistego ryzyka, w proponowanej kolejności. To propozycje do decyzji; niczego nie założono
w GitHubie.

| # | Epik (nazwa robocza) | Cel | Znaleziska | Nakład | Kiedy |
|---|---|---|---|---|---|
| 1 | `SECURITY-PATCHING` | Aktualne komponenty i stała kontrola podatności | SA-01, SA-16, SA-17, SA-38 | M | natychmiast |
| 2 | `ABUSE-LIMITS` | Jeden anonimowy użytkownik nie zatrzyma sprzedaży | SA-03 (szybka poprawka), SA-04, SA-12, SA-13, SA-14, SA-15, SA-18, SA-30, SA-31 | M–L | natychmiast po 1; pokrywa #181, #332, #406, #407, #413, #421 |
| 3 | `CLUSTER-ISOLATION` | Przejęcie jednego komponentu nie daje całej platformy; dane da się odtworzyć | SA-05, SA-06, SA-07, SA-33, SA-34, SA-35, SA-37 | L | przed danymi klientów; pokrywa #296, #409, #410, #485, #690 |
| 4 | `IDENTITY-HARDENING` | Keycloak skonfigurowany jak dla prawdziwych kont, a konfiguracja z repo dociera na produkcję | SA-08, SA-09, SA-10, SA-11, SA-24…SA-28 | M | przed włączeniem `customerAccounts` |
| 5 | `PRIVACY` | Techniczne minimum RODO i pełny ślad audytowy | SA-20, SA-21, SA-36, SA-39 | L | przed danymi klientów; pokrywa #119 |
| 6 | `SECURITY-SIGNALS` | Nadużycie i incydent widać w istniejących narzędziach | SA-19 oraz alerty do SA-11, SA-22, SA-23 | S–M | razem z 4 albo 5 |
| 7 | `PAYMENT-OPERATOR` | Prawdziwy operator, rekoncyliacja, zwroty, wyłączony symulator | SA-02, SA-03 (docelowo), SA-22, SA-23, SA-40 | L | przed płatnościami |
| 8 | `HARDENING-2` | Pozostałe utwardzenia | SA-29, SA-32, SA-41, SA-42 | S | przy okazji |

Epik 1 jest mały i nie wymaga decyzji produktowych. Epiki 2 i 5 wymagają decyzji (limity rezerwacji, okresy
retencji). Epik 3 zmienia chart i skrypt sekretów w jednym PR na usługę (zasada „chart odzwierciedla compose”).

## 15. Rekomendacje stałych kontroli bezpieczeństwa w CI

Minimalny zestaw. Każda pozycja odpowiada klasie błędu, która w tym audycie faktycznie wystąpiła.

| # | Kontrola | Gdzie | Czas | Blokuje merge? | Co by wyłapała |
|---|---|---|---|---|---|
| 1 | Alerty i security updates Dependabota oraz `dependabot.yml` (composer, npm × 3, docker, github-actions) | ustawienia repo i jeden plik | 0 w CI | nie (otwiera PR) | SA-01, SA-17, SA-38 |
| 2 | `composer audit` i `npm audit --omit=dev` | fragment `make/*.mk` dopisany do `CHECK_TARGETS` | sekundy | tak, dla High/Critical w zależnościach produkcyjnych | SA-17, SA-38 |
| 3 | Skan obrazów aplikacji i usług (Trivy uruchamiany z obrazu przypiętego digestem, nie z akcji strony trzeciej) | workflow okresowy albo `workflow_dispatch`, nie w każdym PR | 1–2 min | nie (raport); Critical z dostępną poprawką jako zgłoszenie | SA-01, SA-38 |
| 4 | Niezmienniki chartu w istniejącym `make -C infra/k8s check`: żaden pod nie dostaje całego Secretu; istnieje NetworkPolicy z domyślną odmową; profil `prod` ma włączone kopie i adres inny niż wypełniacz; profil ze sprzedażą nie wystawia symulatora | testy chartu, które już istnieją | sekundy | tak | SA-02, SA-05, SA-06, SA-07 |
| 5 | Test współbieżności rozpoczęcia płatności przez prawdziwe HTTP i scenariusz „N równoległych rozpoczęć” w `load/` | testy API i `make load-smoke` | sekundy | tak | SA-03 |
| 6 | Testy limitów na ścieżkach publicznych (koszyk gościa, podgląd kodu, rozmiar ciała) po ich wprowadzeniu | testy funkcjonalne API | sekundy | tak | SA-12, SA-14, SA-15 |

Opcjonalnie, bez kosztu w pipeline: CodeQL w trybie domyślnym.

Czego **nie** dodawać: DAST (ZAP) w każdym PR (macierz autoryzacji lepiej utrzymać jako test funkcjonalny po
trasach z routera, który już istnieje dla panelu); osobnego Gitleaks w CI (secret scanning i push protection
GitHuba są włączone, a pełny skan historii nie znalazł prawdziwych sekretów); dodatkowych narzędzi SAST obok
PHPStan, Deptrac, ESLint i `tsc`; podpisywania obrazów przed startem sprzedaży.

## 16. Źródła i bezpośrednie linki do dokumentacji

**Standardy i wytyczne**

- OWASP Top 10:2025 — https://top10.owasp.org/2025 (wprowadzenie: https://top10.owasp.org/2025/0x00_2025-Introduction/)
- OWASP API Security Top 10 2023 — https://api-security.owasp.org/editions/2023/en/0x11-t10 (API6:
  https://api-security.owasp.org/editions/2023/en/0xa6-unrestricted-access-to-sensitive-business-flows)
- OWASP ASVS 5.0.0 (poziom odniesienia wymagań) — https://github.com/OWASP/ASVS/tree/v5.0.0/5.0/en
- OWASP OAT-021 Denial of Inventory (SA-04) —
  https://owasp.github.io/www-project-automated-threats-to-web-applications/assets/oats/EN/OAT-021_Denial_of_Inventory
- OWASP Cheat Sheet: Session Management (SA-26, SA-27) —
  https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
- OWASP Cheat Sheet: Cross-Site Request Forgery Prevention (część D) —
  https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
- OWASP Cheat Sheet: Credential Stuffing Prevention (SA-08, SA-09) —
  https://cheatsheetseries.owasp.org/cheatsheets/Credential_Stuffing_Prevention_Cheat_Sheet.html
- OWASP Cheat Sheet: Denial of Service (SA-03, SA-12, SA-13) —
  https://cheatsheetseries.owasp.org/cheatsheets/Denial_of_Service_Cheat_Sheet.html
- OWASP Cheat Sheet: Logging (SA-19, SA-20, SA-39) —
  https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html
- OWASP Cheat Sheet: Content Security Policy (SA-29) —
  https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html
- OWASP Cheat Sheet: Third Party Payment Gateway Integration (SA-02, SA-22, SA-23) —
  https://cheatsheetseries.owasp.org/cheatsheets/Third_Party_Payment_Gateway_Integration_Cheat_Sheet.html
- OWASP Cheat Sheet: Webhook Security (SA-40) —
  https://cheatsheetseries.owasp.org/cheatsheets/Webhook_Security_Cheat_Sheet.html
- OWASP Cheat Sheet: Business Logic Security (SA-04, SA-14) —
  https://cheatsheetseries.owasp.org/cheatsheets/Business_Logic_Security_Cheat_Sheet.html
- OWASP Cheat Sheet: Kubernetes Security (SA-06, SA-33) —
  https://cheatsheetseries.owasp.org/cheatsheets/Kubernetes_Security_Cheat_Sheet.html
- OWASP Cheat Sheet: Secrets Management (SA-05, SA-37) —
  https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html (niezweryfikowane w źródle
  pierwotnym: potwierdzono tylko istnienie w indeksie)
- RFC 9700, Best Current Practice for OAuth 2.0 Security (SA-09, SA-26, SA-28) —
  https://www.rfc-editor.org/rfc/rfc9700.html
- RFC 10017, OAuth 2.0 for Browser-Based Applications (wzorzec BFF, część D) —
  https://www.rfc-editor.org/rfc/rfc10017.html
- PCI DSS v4.0.1, biblioteka dokumentów PCI SSC — https://www.pcisecuritystandards.org/document_library/ (treść wymagań
  6.4.3 i 11.6.1 oraz lista wymagań SAQ A: niezweryfikowane w źródle pierwotnym)
- PCI SSC, zmiany SAQ A (styczeń 2025) —
  https://blog.pcisecuritystandards.org/important-updates-announced-for-merchants-validating-to-self-assessment-questionnaire-a
- PCI SSC FAQ 1588 (kryterium skryptów w SAQ A) — https://www.pcisecuritystandards.org/faqs/1588/
- RODO, rozporządzenie (UE) 2016/679 (SA-07, SA-11, SA-21) — https://eur-lex.europa.eu/eli/reg/2016/679/oj
- EROD, Wytyczne 4/2019 dotyczące art. 25 —
  https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-42019-article-25-data-protection-design-and_en
  (niezweryfikowane w źródle pierwotnym: treść PDF nieodczytana)
- EROD, Wytyczne 9/2022 o zgłaszaniu naruszeń —
  https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/guidelines-92022-personal-data-breach-notification-under_en
  (niezweryfikowane w źródle pierwotnym: treść PDF nieodczytana)
- UODO, poradnik o naruszeniach ochrony danych (2025) — https://uodo.gov.pl/pl/138/3561 (niezweryfikowane w źródle
  pierwotnym: PDF nieotwarty)
- CWE (MITRE) — https://cwe.mitre.org/ (np. CWE-770: https://cwe.mitre.org/data/definitions/770.html, CWE-799:
  https://cwe.mitre.org/data/definitions/799.html, CWE-532: https://cwe.mitre.org/data/definitions/532.html)

**Dokumentacja technologii**

- Symfony: Access Token Authentication — https://symfony.com/doc/current/security/access_token.html
- Symfony: Rate Limiter — https://symfony.com/doc/current/rate_limiter.html
- Symfony: konfiguracja frameworka (`trusted_hosts`, `http_method_override`) —
  https://symfony.com/doc/current/reference/configuration/framework.html
- Symfony: reverse proxy — https://symfony.com/doc/current/deployment/proxies.html
- Doctrine ORM: Security — https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/security.html
- Doctrine DBAL: Security — https://www.doctrine-project.org/projects/doctrine-dbal/en/current/reference/security.html
- Next.js: Data Security — https://nextjs.org/docs/app/guides/data-security
- Next.js: Content Security Policy — https://nextjs.org/docs/app/guides/content-security-policy
- Next.js: Self-Hosting — https://nextjs.org/docs/app/guides/self-hosting
- Vue: Security — https://vuejs.org/guide/best-practices/security.html
- Keycloak: Configuring for production — https://www.keycloak.org/server/configuration-production
- Keycloak: Using a reverse proxy — https://www.keycloak.org/server/reverseproxy
- Keycloak: brute force detection —
  https://github.com/keycloak/keycloak/blob/main/docs/documentation/server_admin/topics/threat/brute-force.adoc
- Keycloak: redirect URI —
  https://github.com/keycloak/keycloak/blob/main/docs/documentation/server_admin/topics/threat/redirect.adoc
- Keycloak: timeouts i Revoke Refresh Token —
  https://github.com/keycloak/keycloak/blob/main/docs/documentation/server_admin/topics/sessions/timeouts.adoc
- PostgreSQL 18 security — https://www.postgresql.org/support/security/18/
- Redis security — https://redis.io/docs/latest/operate/oss_and_stack/management/security/
- RabbitMQ: Production Checklist — https://www.rabbitmq.com/docs/production-checklist
- imgproxy: configuration options — https://docs.imgproxy.net/configuration/options
- Mailpit: runtime options — https://mailpit.axllent.org/docs/configuration/runtime-options/
- Docker Engine security — https://docs.docker.com/engine/security/
- Kubernetes: Pod Security Standards — https://kubernetes.io/docs/concepts/security/pod-security-standards/
- k3s: CIS Hardening Guide — https://docs.k3s.io/security/hardening-guide
- GitHub Actions: Secure use reference — https://docs.github.com/en/actions/reference/security/secure-use

**Advisories przywołane w raporcie**

- Keycloak: wydania i advisories — https://github.com/keycloak/keycloak/releases,
  https://github.com/keycloak/keycloak/security/advisories
- Keycloak 26.7.2 (CVE-2026-18963) — https://github.com/keycloak/keycloak/releases/tag/26.7.2
- Keycloak 26.6.2 (CVE-2026-7507, CVE-2026-7504, CVE-2026-7571) —
  https://github.com/keycloak/keycloak/releases/tag/26.6.2
- Keycloak 26.6.3 (CVE-2026-9802) — https://github.com/keycloak/keycloak/releases/tag/26.6.3
- Keycloak 26.6.4 (CVE-2026-11800, CVE-2026-9086) — https://github.com/keycloak/keycloak/releases/tag/26.6.4
- Red Hat, RHSA-2026:56523 (poprawki linii 26.6 w buildzie Red Hat) — https://access.redhat.com/errata/RHSA-2026:56523
- Symfony: lista advisories — https://github.com/symfony/symfony/security/advisories
- Symfony GHSA-hqp2-7rr8-hf26 (CVE-2026-122053) —
  https://github.com/symfony/symfony/security/advisories/GHSA-hqp2-7rr8-hf26
- Symfony GHSA-h38r-xrmh-cwhr — https://github.com/symfony/symfony/security/advisories/GHSA-h38r-xrmh-cwhr
- Symfony GHSA-pxj9-jh84-vhm5 — https://github.com/symfony/symfony/security/advisories/GHSA-pxj9-jh84-vhm5
- Symfony GHSA-jrf8-gvm4-7cgh (HEAD a `methods`) —
  https://github.com/symfony/symfony/security/advisories/GHSA-jrf8-gvm4-7cgh
- Symfony GHSA-hwfg-qwvh-2wfx (YAML) — https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx
- Next.js: lista advisories — https://github.com/vercel/next.js/security/advisories
- Next.js, wydanie bezpieczeństwa z 2026-11-03 (źródło wtórne) —
  https://www.netlify.com/changelog/2026-11-03-nextjs-security-vulnerabilities/
- OSV GHSA-mfr8-8qww-v76h, GHSA-vc7v-qv4w-wrv8 — https://osv.dev/vulnerability/GHSA-mfr8-8qww-v76h,
  https://osv.dev/vulnerability/GHSA-vc7v-qv4w-wrv8
- web-token/jwt-framework: advisories (GHSA-2xq2-pp89-x7fr) —
  https://github.com/web-token/jwt-framework/security/advisories/GHSA-2xq2-pp89-x7fr
- FrankenPHP: advisories — https://github.com/php/frankenphp/security/advisories
- imgproxy: advisories (GHSA-494m-4556-wf5p) —
  https://github.com/imgproxy/imgproxy/security/advisories/GHSA-494m-4556-wf5p
- Mailpit: advisories — https://github.com/axllent/mailpit/security/advisories
- Node.js, wydania bezpieczeństwa z listopada 2026 —
  https://nodejs.org/en/blog/vulnerability/november-2026-security-releases
- nginx security advisories — https://nginx.org/en/security_advisories.html
- Elastic Security Announcements — https://discuss.elastic.co/c/announcements/security-announcements/31
- Vite GHSA-p4f5-76q4-ffq7 — https://github.com/vitejs/vite/security/advisories/GHSA-p4f5-76q4-ffq7

**Platformy e-commerce i operatorzy płatności (punkty odniesienia)**

- Sylius CVE-2026-31824 (wyścig liczników promocji i kuponów; część D) —
  https://github.com/Sylius/Sylius/security/advisories/GHSA-7mp4-25j8-hp5q
- Saleor: Order expiration (SA-04) — https://docs.saleor.io/developer/order/order-expiration
- Adobe Commerce: Google reCAPTCHA (m.in. formularz kodów kuponów; SA-14) —
  https://experienceleague.adobe.com/en/docs/commerce-admin/systems/security/captcha/security-google-recaptcha
- Adobe Commerce: Action Logs (SA-20) —
  https://experienceleague.adobe.com/en/docs/commerce-admin/systems/action-logs/action-log
- Shopware: Rate limiter (SA-14) — https://developer.shopware.com/docs/guides/hosting/infrastructure/rate-limiter.html
- Stripe: Webhooks (SA-40) — https://docs.stripe.com/webhooks
- Stripe: Fulfill orders (SA-23) — https://docs.stripe.com/checkout/fulfillment.md?ui=full-page
- Adyen: Verify HMAC signatures (SA-40) —
  https://docs.adyen.com/development-resources/webhooks/secure-webhooks/verify-hmac-signatures/
- Mollie: Webhooks (SA-23) — https://docs.mollie.com/reference/webhooks

## Załącznik A. Metodyka i przebieg

- **Rewizja:** `origin/main` = `ef204620` (2026-11-18). Audytorzy czytali kod z eksportu tej rewizji bez `.git`,
  bez `docs/experiments` i bez przeglądu `docs/reports/security-review-2026-11-10.md`, żeby wynik był niezależny.
  Porównanie z tamtym przeglądem i z backlogiem zrobiono po zakończeniu analizy (punkt 13).
- **Research (3 wątki):** standardy (OWASP Top 10:2025, API Security Top 10:2023, ASVS 5.0, Cheat Sheets, RFC 9700,
  RFC 10017, PCI DSS v4.0.1, RODO), advisories i hardening dla dokładnych wersji z lockfile, compose i chartu,
  praktyki Sylius, Shopware, Magento, Medusa, Saleor oraz operatorów płatności. Notatki:
  `research_notes/Audyt bezpieczeństwa sklepu/` (lokalnie, poza repozytorium).
- **Audyt obszarów (9 niezależnych wątków):** uwierzytelnianie i sesje, autoryzacja, logika biznesowa, płatności,
  API, frontendy, infrastruktura i wdrożenie, supply chain i CI/CD, prywatność i monitoring. Każdy łączył analizę
  statyczną z testami dynamicznymi w swoim obszarze.
- **Środowisko testów dynamicznych:** osobny projekt Compose `shop-secaudit` na portach 24100–24106, uruchomiony
  z tej rewizji (`STORE_MODE=production`, dane z `make seed`). Sklep działał jako `next start`, API w `APP_ENV=dev`
  (limity deweloperskie; zachowanie `prod` sprawdzano osobnym jądrem w kontenerze albo z konfiguracji), panel jako
  `vite dev`. Cztery tożsamości: anonim, klient, inny klient, personel (role `admin`, `support`, `warehouse`,
  `marketing`). Stan bazy sprawdzano po operacjach.
- **Narzędzia:** `composer audit` (Composer 2.10.3), `npm audit` (npm 11.19.1), Trivy 0.76.1 i Gitleaks 8.30.1
  z obrazów przypiętych digestem (pełna historia `origin/main`, wartości zredagowane), `gh api` wyłącznie do odczytu
  ustawień repozytorium, skrypty Pythona i `curl`. Nie instalowano niczego na hoście. Semgrep, OSV-Scanner i ZAP
  pominięto świadomie: nie wniosłyby nic ponad powyższe i ręczną analizę.
- **Własna weryfikacja koordynatora:** ponowne uruchomienie dowodów SA-02 i SA-14 (wynik zgodny), sprawdzenie w kodzie
  i chartcie SA-01, SA-05, SA-06, SA-07, SA-08, SA-12, SA-17 (wersje, szablony, pliki realmów), trzy próby
  odtworzenia luki resetu hasła Keycloaka (bez skutku), odczyt stanu zgłoszeń w GitHubie.
- **Niezależny przegląd:** jeden reviewer, jedna runda, 13 grup znalezisk (załącznik B).
- **Konsolidacja:** znaleziska dziewięciu audytorów połączono w 42 pozycje `SA-NN` (duplikaty między obszarami
  scalone); części 4, 6–11 i 16 zredagowano z plików audytorów i notatek researchu, pozostałe napisał koordynator.
- **Nakład:** 14 wątków pomocniczych (3 research, 9 audytów, przegląd, redakcja), łącznie ok. 4,9 mln tokenów bez
  koordynatora; czas od startu stosu do raportu ok. 3,5 godziny.

## Załącznik B. Wynik niezależnej weryfikacji

Reviewer sprawdzał dowody, możliwość false positive, realność wykorzystania i adekwatność poziomu; czytał kod,
źródła pierwotne advisories i uruchamiał wybrane dowody ponownie.

| Znalezisko | Werdykt | Zmiana w raporcie |
|---|---|---|
| SA-01 | podtrzymane, status rozdzielony | wersja podatna `CONFIRMED` (advisory, Red Hat, zgłoszenie i poprawka upstream), przejęcie konta `LIKELY`: nieodtworzone także przez reviewera; demo Medium, sprzedaż Critical |
| SA-02 | podtrzymane | nazwane jako brak funkcji i brak wyłącznika; demo: zamierzone, sprzedaż: Critical |
| SA-03 | podtrzymane | High; mechanizm potwierdzony w kodzie (`doctrine_transaction` na szynie komend, częściowy indeks unikalny) |
| SA-04 | podtrzymane | High przy sprzedaży, Medium w demo; skala to arytmetyka na zmierzonych czasach |
| SA-05 | podtrzymane | High; mnożnik skutków, nie samodzielna ścieżka |
| SA-06 | podwyższone dla sprzedaży | Medium → High przy prawdziwych klientach (tokeny sesji wszystkich klientów w Redis bez hasła) |
| SA-07 | podtrzymane | demo Low, sprzedaż Medium |
| SA-08 | podtrzymane | demo Low (rejestracja wyłączona), sprzedaż Medium |
| SA-09 | podtrzymane, doprecyzowane | ROPC przez `admin-cli` to wyrocznia haseł omijająca limit BFF, nie token do API (`/api/v1/me` → 401); personel chroni dodatkowo OTP |
| SA-10 | podtrzymane | Low |
| SA-12, SA-13 | podtrzymane | Medium; Cloudflare łagodzi, nie usuwa |
| SA-14, SA-15 | podtrzymane | Medium; duplikaty między obszarami scalone |
| SA-17 | **obniżone** | Medium → Low: DTO są płaskie i `readonly`, bez `OBJECT_TO_POPULATE`; klucz z kropką daje najwyżej 500, nie ustawia pól (test na koszyku gościa); aktualizacja i tak zalecana |
| SA-18 | podtrzymane | Medium |
| SA-21 | podtrzymane | Medium |
| SA-22, SA-23 | podtrzymane jako ryzyko architektury | Medium przy prawdziwym operatorze, dziś bez skutku finansowego |

Nie odrzucono żadnego znaleziska jako false positive. Pozycje Low nie były przedmiotem przeglądu.

## Załącznik C. Ograniczenia audytu

- **Produkcja i demo nietestowane.** Wszystkie wnioski o konfiguracji produkcyjnej wynikają z chartu i wartości
  `environments/prod` w repozytorium. Nie sprawdzono: stanu klastra, zapory i `sshd` serwera, Argo CD, działania
  kopii, Cloudflare (WAF, limity, cache, Access, tryb TLS), ustawień GitHuba poza odczytem, dostawcy poczty, R2,
  Sentry, Grafana Cloud.
- **Lokalny stos różni się od produkcji:** API w `APP_ENV=dev` z limitami 10 000, panel jako `vite dev`, sklep jako
  `next start` (nie obraz standalone), brak Traefika i Cloudflare. Limitów produkcyjnych nie dało się zmierzyć;
  oceny SA-03 i SA-04 dla produkcji opierają się na kodzie limitera.
- **Skutki wyczerpania zasobów** (OOM poda, zapełnienie dysku, zapełnienie bazy) są wyliczone, nie wywołane:
  testy destrukcyjne były wykluczone.
- **SA-01:** przejęcia konta nie odtworzono; status wykorzystania to `LIKELY`. Spośród około 165 pozycji CVE
  Keycloaka pojedynczo zweryfikowano tylko kilka najważniejszych.
- **Advisories po czerwcu 2026** są nowsze niż wiedza modelu: brano je z baz skanerów i stron dostawców, a zakresy
  wersji kluczowych pozycji sprawdzono w źródłach pierwotnych. Część źródeł standardów (PDF-y PCI SSC i EROD,
  strona UODO) nie dała się pobrać; takie pozycje są oznaczone w punkcie 16.
- **Nie zbadano:** CVE Kubernetesa, Traefika, containerd i pakietów systemowych w obrazach usług poza
  przeskanowanymi; chartu `k8s-monitoring` (przejrzany częściowo); konsoli konta Keycloaka; panelu w prawdziwej
  przeglądarce poza testem XSS; endpointów `/api/v1/me/*` fuzzingiem z tokenem klienta (tylko statycznie
  i macierzą autoryzacji); wyścigu płatność–wygaśnięcie.
- **Pozycje znane tylko z researchu:** `web-token/jwt-library` 4.2.3, FrankenPHP 1.13.4, PostgreSQL 18.6,
  Elasticsearch 9.4.0, Mailpit v1.27.11, Vite 8.3.5 i pięć dodatkowych advisories Next.js mają nowsze wydania
  z poprawkami bezpieczeństwa (punkt 11, oznaczenie „(research)”), ale ich osiągalności w projekcie nie
  weryfikowano, a `composer audit` części z nich nie zgłosił. Obejmuje je zalecenie z SA-16 i SA-38.
- **Rozbieżności między audytorami,** których nie rozstrzygano: liczba tras API (74 w audycie autoryzacji, 71
  w audycie API), liczba advisories Symfony (19 według `composer audit --locked`, 17 według researchu), wersja
  imgproxy z poprawkami (v3.31.4 albo tylko linia 4.0.x), dostępność Keycloaka 26.6.6 jako obrazu upstream.
  Żadna nie zmienia wniosków.
- **Zgodność prawna** (RODO, PCI DSS) nie jest przedmiotem ani wynikiem tego audytu.
- **Stan po testach:** stos `shop-secaudit` został po audycie usunięty razem z danymi testowymi. W worktree nie
  zmieniono żadnego śledzonego pliku. Skrypty dowodowe i pliki robocze audytorów skopiowano do
  `research_notes/Audyt bezpieczeństwa sklepu/evidence/` (katalog lokalny, poza repozytorium).
