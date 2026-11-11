# Shop monorepo – wizja projektu

## Co budujemy

Platformę e-commerce w monorepo: API w Symfony, sklep dla klientów i panel administracyjny.
Branża: sport i outdoor, szeroki asortyment w kilku działach. Nazwa sklepu: „Worf” (domena worf.pl).

Projekt ma dwa cele:

1. Działający sklep z realistyczną domeną (katalog, ceny, koszyk, checkout, zamówienia, płatności,
   stany magazynowe), zbudowany tak, jak buduje się system produkcyjny.
2. Powtarzalny workflow pracy z AI: większość kodu piszą agenci Claude Code, prowadzeni przez specyfikacje,
   zasady projektu, niezależne review i CI. Zasady pracy z agentami (`CLAUDE.md`, `.claude/`) są równie ważnym
   rezultatem jak sam kod.

## Aplikacje

| Aplikacja | Stos | Rola |
|---|---|---|
| `apps/api` | PHP 8.5, Symfony 8, Doctrine ORM, PostgreSQL | REST API, cała logika biznesowa |
| `apps/store` | Next.js (App Router), React, TypeScript, Tailwind CSS 4, shadcn/ui | Sklep dla klientów, SSR/ISR pod SEO |
| `apps/admin` | Vue 3, Vite, TypeScript, Pinia, Vue Router, PrimeVue | Panel dla obsługi sklepu |
| `infra` | Docker Compose | Postgres, Redis, Elasticsearch, RabbitMQ, Keycloak, Garage, imgproxy, Varnish, Mailpit |

Wszystko działa w Dockerze. Na komputerze nie jest potrzebny ani PHP, ani Node.

## Rola poszczególnych usług

- **PostgreSQL** – źródło prawdy dla wszystkich contextów.
- **Elasticsearch** – wyszukiwanie i filtrowanie produktów w sklepie. Tylko model odczytu, odbudowywany z Postgresa.
  Analizę tekstu polskiego (stemming) robi oficjalna wtyczka `analysis-stempel`, dlatego usługa działa na własnym
  obrazie (`infra/elasticsearch/Dockerfile`: obraz Elasticsearch z zainstalowaną wtyczką). Indeks należy do Catalog
  i zawiera warianty produktów z cenami i dostępnością. Catalog bierze je przez port do oferty Cart
  (`ProductOfferFacts`), więc „możliwy do kupienia” i cena „od” liczą się tak samo jak na kafelku, a Catalog nie
  zależy od Pricing, Inventory ani Cart. Zmiany cen i stanów aktualizują indeks przez zdarzenia.
- **Redis** – cache (odczyty katalogu), limity dla rate limitera.
- **RabbitMQ** – komunikaty asynchroniczne przez Symfony Messenger: zdarzenia domenowe między contextami,
  indeksowanie w ES, e-maile, rewalidacja stron sklepu.
- **Keycloak** – tożsamość użytkowników w dwóch osobnych realmach (szczegóły w sekcji „Klienci i pracownicy”).
  API tylko waliduje tokeny JWT i mapuje role, nigdy nie przechowuje haseł.
- **Garage** – magazyn plików zgodny z S3. Przechowuje oryginały zdjęć produktów w prywatnym buckecie.
  API zapisuje przez Flysystem, więc w produkcji wystarczy podmienić adapter na prawdziwe S3.
- **imgproxy** – serwuje zdjęcia: czyta oryginał z Garage i na żądanie skaluje, przycina i konwertuje
  do formatu wskazanego w adresie (WebP). Adresy są podpisane (HMAC), więc nikt z zewnątrz nie wygeneruje dowolnych rozmiarów
  i nie obciąży serwera. W produkcji przed imgproxy stoi CDN, który cache'uje wyniki.
- **Varnish** – HTTP cache przed publicznymi endpointami odczytu API (katalog, wyszukiwarka, filtry).
  Odciąża API i Elasticsearch przy stronach wyszukiwarki renderowanych przez SSR, gdzie te same zapytania
  powtarzają się bardzo często. Serwer sklepu czyta przez niego katalog pod osobnym adresem (`API_CATALOG_URL`);
  koszyk, kasa i logowanie idą do API bezpośrednio (`API_INTERNAL_URL`). Varnish nie ma portu na hoście,
  a `PURGE` wymaga sekretu. Unieważnianie precyzyjne przez tagi (xkey), opisane niżej.
- **Mailpit** – przechwytuje e-maile wysyłane lokalnie (potwierdzenia zamówień), z podglądem w przeglądarce.

## Zdjęcia produktów

- Panel wysyła oryginał (JPEG, PNG, WebP, AVIF albo HEIC; bez SVG) do API, API waliduje typ po zawartości,
  rozmiar i rozdzielczość i zapisuje w Garage pod kluczem wyliczonym z treści pliku, więc adres obrazu o danej
  treści nigdy się nie zmienia. Oryginał nie jest nigdy serwowany bezpośrednio.
- Zestaw rozmiarów jest zamknięty i zdefiniowany w jednym miejscu w API (miniatura, lista, karta produktu, zoom),
  każdy w kilku szerokościach dla `srcset`. Miniatura, lista i karta produktu mają stałe proporcje (przycięcie);
  `zoom` pokazuje całe zdjęcie bez przycinania (proporcje oryginału, szerokości 1280, 1920 i 2560, bez powiększania
  ponad oryginał) i służy do powiększenia na karcie produktu.
- API w odpowiedziach zwraca gotowe, podpisane adresy imgproxy dla każdego rozmiaru, szerokości i formatu.
  Podpisuje jeden serwis po stronie API, klucz podpisu nigdy nie trafia do frontów.
- Wyjątek od imgproxy: zdjęcia marketingowe strony głównej (hero i kafelki działów) to statyczne, zoptymalizowane
  pliki WebP w repozytorium sklepu, responsywne (osobny kadr i szerokości dla telefonu, budżet rozmiaru na plik,
  telefon nie pobiera pliku szerokiego ekranu); generuje je skrypt sklepu z jednej listy rozmiarów, nie panel ani API.
- Zdjęcia wychodzą w WebP. AVIF nie jest generowany, bo jego kodowanie na żądanie mocno obciąża CPU małego
  serwera; można go dodać później jako kolejny format w liście rozmiarów.
- Format jest częścią podpisanego adresu. imgproxy nie wybiera go po nagłówku `Accept`, bo przed
  imgproxy stoi darmowy Cloudflare, który nie obsługuje Vary for Images. Każdy wariant musi mieć jednoznaczny
  adres dla CDN.
- Sklep renderuje zwykły `<img srcset>` z gotowych adresów WebP z odpowiedzi API. Wbudowana optymalizacja
  obrazów Next.js jest wyłączona, żeby obrazy nie były przetwarzane dwa razy.

## Klienci i pracownicy

Klient sklepu (`Customer`) i pracownik obsługujący panel (`Staff`) to dwa osobne byty, bez wspólnej klasy
`User`. Mają inne cykle życia, inne wymagania bezpieczeństwa i nigdy nie dzielą konta.

| | Klient (`Customer`) | Pracownik (`Staff`) |
|---|---|---|
| Realm Keycloak | `shop` | `backoffice` |
| Klient OIDC | `store` (Authorization Code + PKCE) | `admin` (Authorization Code + PKCE) |
| Rejestracja | samodzielna, w sklepie | tylko zakładany przez administratora |
| Uwierzytelnianie | hasło, opcjonalnie logowanie społecznościowe | hasło + obowiązkowe 2FA (TOTP) |
| Sesja | długa (zapamiętaj mnie) | krótka, wygasa po bezczynności |
| Role | brak (klient ma dostęp tylko do swoich danych) | `admin`, `support`, `warehouse`, `marketing` |
| Usunięcie | na żądanie klienta (RODO), dane zamówień anonimizowane | dezaktywacja konta, historia działań zostaje |
| Context w API | Customer (profil, adresy) | brak osobnego contextu; tożsamość i role z tokena |

- API ma dwa firewalle. `/api/v1/...` przyjmuje tylko tokeny z realmu `shop`, `/api/v1/admin/...` tylko z realmu
  `backoffice` (sprawdzany wystawca tokena). Token klienta nie przejdzie przez firewall panelu, niezależnie od ról.
- Uprawnienia pracowników sprawdzane w voterach na podstawie ról z tokena.
- Katalog (tworzenie i edycja produktów, upload zdjęć, publikacja i wycofanie) edytują `admin` i `marketing`; listę
  i podgląd produktów widzi każdy pracownik. Ceny zmienia `admin`, stany magazynowe `admin` i `warehouse`.
- Zmiany wykonane w panelu zapisują identyfikator pracownika (z tokena), żeby było wiadomo, kto zmienił
  cenę, stan czy status zamówienia.
- Ta sama osoba jako klient i jako pracownik ma dwa osobne konta. To świadoma decyzja.

## Cache HTTP i unieważnianie

Trzy warstwy cache, każda z jasną rolą:

| Warstwa | Co trzyma | Unieważnianie |
|---|---|---|
| Next.js (ISR) | HTML stron produktów | rewalidacja na żądanie po zdarzeniu domenowym |
| Varnish | odpowiedzi publicznych endpointów `GET` katalogu i wyszukiwarki | purge po tagach (xkey) po zdarzeniu domenowym |
| Cloudflare | obrazy z imgproxy, statyczne zasoby | adresy obrazów są niezmienne, więc bez unieważniania |

- Każda odpowiedź katalogu dostaje tagi obiektów, które zawiera (`catalog` i np.
  `product-0192f0c4-7c1a-7c4e-9a6e-000000000001`), w nagłówku `xkey` ustawianym przez własny mały serwis tagów
  w API (bez FOSHttpCacheBundle). Varnish usuwa nagłówek przed wysłaniem odpowiedzi.
- Zmiana ceny lub poziomu dostępności publikuje zdarzenie domenowe (outbox). Jeden konsument robi kolejno: purge
  w Varnishu odpowiedzi z tagiem produktu, potem rewalidację stron Next.js z tym tagiem (`POST /api/revalidate`
  sklepu). Kolejność jest stała, więc regenerowana strona czyta już odświeżone dane. Reszta cache zostaje.
  Zmiany bez zdarzenia odświeża `s-maxage` odpowiedzi i `revalidate` stron (siatka bezpieczeństwa, 1 h), a `make seed`
  kończy się pełnym unieważnieniem (tag `catalog`).
- Varnish nigdy nie cache'uje: koszyka, checkoutu, konta klienta, panelu admina ani żadnego żądania
  z nagłówkiem `Authorization` lub ciasteczkiem sesji.
- Odpowiedzi publiczne mają jawne nagłówki `Cache-Control` ustawiane w API, a nie domyślne zachowanie Varnisha.
- Wyjątek: mapa strony produktów. Porcje (`/api/v1/sitemap/products?chunk=…`, do 10 000 produktów, rząd MB) nie mają
  tagów obiektów ani nie przechodzą przez cache Varnisha: 10 000 tagów nie mieści się w nagłówku, a odpowiedzi
  przy dowolnych kluczach wypychałyby z Varnisha katalog. Świeżość daje krótki `max-age` (5 min), a koszt odczytu jest
  ograniczony (limit wierszy zakresu). Lista porcji (`/api/v1/sitemap/product-chunks`) ma tag `catalog` i jest
  cache'owana jak reszta katalogu; granice porcji liczy API najwyżej raz na godzinę na instancję (pula cache API).

## Kontrakt API między aplikacjami

- Źródłem prawdy jest specyfikacja OpenAPI generowana z kodu API (NelmioApiDocBundle).
- Specyfikacja jest eksportowana do dwóch plików w repo i commitowana, więc każda zmiana kontraktu jest widoczna
  w diffie PR: `apps/api/openapi.json` (sklep: endpointy publiczne i klienta) oraz `apps/api/openapi-admin.json`
  (panel: `/api/v1/admin`).
- Z tych plików generowane są typowane klienty TypeScript (`openapi-typescript`): dla `store` tylko z pierwszego,
  dla `admin` tylko z drugiego. Fronty nie piszą ręcznie typów odpowiedzi API.
- Zmiana w API, która łamie front, kończy się błędem `tsc` w CI, a nie błędem na produkcji.
- CI sprawdza, czy wyeksportowane pliki są aktualne względem kodu.

## Sklep: renderowanie i SEO (apps/store)

Sklep ma być w pełni przyjazny wyszukiwarkom. Next.js z App Router, domyślnie Server Components.

- **Strony produktów**: renderowane na serwerze (ISR). Gotowy HTML jest cache'owany,
  a po zmianie ceny lub stanu API wysyła rewalidację na żądanie (zdarzenie domenowe → purge Varnisha → webhook do
  Next.js).
  Wyszukiwarka zawsze dostaje pełną treść, a ruch nie obciąża API przy każdym wejściu.
- **Strony kategorii** (`/c/<ścieżka slugów>`) i lista `/`: renderowane na serwerze przy każdym żądaniu (SSR), bez ISR.
  Odczyty idą przez Varnisha, który jest jedynym cache list (bez data cache Next.js), więc strona nie zależy od
  rewalidacji. Mega menu działów w nagłówku jest zbudowane z drzewa kategorii, a wszystkie jego linki są w HTML.
- **Wyszukiwarka i filtry**: SSR, parametry w URL, kanoniczne adresy dla kombinacji filtrów,
  `noindex` dla kombinacji bez wartości SEO.
- **Koszyk, checkout, konto klienta**: renderowane po stronie klienta, `noindex`.
- **Metadane**: tytuł, opis, Open Graph (z obrazem z imgproxy), `canonical` generowane per strona
  (Metadata API w Next.js).
- **Dane strukturalne**: JSON-LD `Product` (cena, dostępność, zdjęcia), `BreadcrumbList` oraz `Organization` i `WebSite` na stronie głównej.
- **Mapa strony i robots**: `/sitemap.xml` to indeks map: mapa listingu (`/sitemaps/listing.xml`: `/` i każda kategoria
  `/c/<ścieżka>`) i po jednej pozycji na porcję produktów z API (`/sitemaps/products/<klucz>.xml`: `/p/<slug>`
  z `lastmod` równym czasowi ostatniej zmiany produktu). Porcje wyznacza API (kolejne `id` produktów, nieprzezroczysty
  klucz), więc sklep nie ładuje katalogu do pamięci. Trasy są renderowane przy każdym żądaniu, bez data cache Next.js;
  błąd API daje 503 bez cache, nigdy pustą ani częściową mapę. `robots.txt` blokuje `/cart`, `/checkout`, `/konto`,
  `/auth/` i `/api/`, nie blokuje stron z `noindex, follow` (listing z filtrami, `/szukaj`), żeby robot widział ich
  dyrektywę, i wskazuje indeks map; jest taki sam w każdym środowisku.
- **Przyjazne adresy**: `/p/<slug>`, `/c/<ścieżka slugów kategorii>` (np. `/c/turystyka/biwak/namioty`),
  przekierowania 301 przy zmianie sluga. Slug produktu jest stały od pierwszej publikacji (także po wycofaniu
  z publikacji); zmienia się tylko w szkicu, który nie był nigdy opublikowany, więc w MVP adres produktu nie wymaga
  przekierowania. Stary adres `/?category=<id>` przekierowuje trwale na `/c/<ścieżka>`.
- **Wydajność**: Core Web Vitals jako wymaganie (obrazy z imgproxy w WebP z `srcset`,
  `priority` dla głównego zdjęcia produktu, minimum JavaScriptu po stronie klienta). Pomiar przez Lighthouse w CI.

## Wygląd sklepu (apps/store)

- Kierunek: outdoor premium w duchu arcteryx.com. Zdjęcia w terenie na całą szerokość, stonowana paleta
  (czerń, grafit, złamana biel i jeden akcent), prosta i pewna typografia, dużo przestrzeni.
- Struktura dużego katalogu jak w decathlon.pl: mega menu dla wielu działów, filtry, breadcrumby.
- Inspiracja, nie kopia: własna marka, bez logotypów, nazw i elementów identyfikacji Arc'teryx i Decathlona.
  Nazwę sklepu przed publicznym demo sprawdzamy w bazach znaków towarowych (UPRP, EUIPO).
- Komponenty na shadcn/ui (Tailwind CSS 4, motyw na zmiennych CSS). Tokeny (paleta i warstwa semantyczna) są
  w jednym miejscu, a kolory „na sztywno” blokuje ESLint.
- Baza układów: github.com/vercel/shop (MIT). Przenosimy komponenty i układy z zachowaniem informacji o licencji,
  ale dane pochodzą wyłącznie z naszego API przez wygenerowanego klienta, bez Shopify.
- Nie korzystamy z gotowych szablonów komercyjnych (np. z ThemeForest).
- Strona główna: hero na całą szerokość (zdjęcie z hasłem „Sprzęt na każdy szlak” i linkiem do działu), pod nim
  kafelki działów (kategorie główne) i listing wszystkich produktów; opis w `docs/features/visual-design/spec.md`.
- Wybrany kierunek: „Granit” (jasne tło, grafit, jeden pomarańczowy akcent na najważniejszej akcji, ostre kształty
  bez cieni, krój Outfit z plików sklepu); opis, makieta i wymagania są w `docs/features/visual-design/spec.md`.

## Bounded contexty (api)

| Context | Odpowiedzialność |
|---|---|
| Catalog | Produkty, warianty, kategorie, atrybuty, zdjęcia |
| Pricing | Ceny katalogowe (netto, VAT, brutto), promocje, kody rabatowe, historia cen (Omnibus) |
| Cart | Pozycje koszyka, podgląd sum, koszyki gości i zalogowanych w PostgreSQL |
| Ordering | Checkout, cykl życia zamówienia (przejścia jako metody agregatu `Order`), historia zamówień |
| Payment | Dostawcy płatności za jednym portem, status płatności, webhooki dostawców |
| Inventory | Stany magazynowe, rezerwacja przy zamówieniu, zwolnienie przy anulowaniu |
| Customer | Profil klienta, adresy; sama tożsamość jest w Keycloak |

Contexty nie importują nawzajem swojego kodu domenowego. Komunikują się przez zdarzenia domenowe
(asynchronicznie, RabbitMQ) albo przez jawne porty w warstwie aplikacji. To samo słowo może znaczyć
co innego w różnych contextach (cena w Pricing to nie kwota do obciążenia w Payment), dlatego każdy
context ma własne value objecty.

## Reguły domenowe, które obowiązują od początku

- **Ceny brutto i VAT**: klient widzi ceny brutto. Cena przechowuje netto, stawkę VAT i brutto,
  liczone w groszach z jawną regułą zaokrąglania. Produkt w Catalog ma klasę podatkową (`taxClass`:
  jednorodna grupa towarów nazwana po towarze, np. standardowa, książka drukowana, żel energetyczny), a nie
  stawkę. Pricing wyznacza stawkę (23%, 8%, 5%, 0%) z klasy podatkowej i okresu obowiązywania stawki;
  stawki dla PL są w kodzie Pricing.
- **Omnibus**: każda zmiana ceny jest zapisywana w historii. Przy promocji sklep pokazuje najniższą cenę
  z 30 dni przed jej rozpoczęciem.
- **Stany magazynowe**: sprzedaż ponad stan jest niemożliwa, także przy równoległych zakupach.
- **Zamówienie**: po złożeniu ceny, rabaty i adres są zamrożone w zamówieniu (kopia, nie referencja),
  więc późniejsza zmiana ceny produktu nie zmienia historycznego zamówienia.

## Wymagania niefunkcjonalne

Wymagania obowiązują cały projekt. Wdrażamy je razem z funkcjami, których dotyczą, a nie w szkielecie.

**Skala docelowa**
- Katalog do 10 milionów produktów.
- Do 60 tysięcy jednocześnie aktywnych użytkowników.
- To cele, do których architektura ma być przygotowana, a nie deklaracja obecnej wydajności. Takiego obciążenia
  nie uruchamiamy lokalnie ani w środowisku demo.
- Testy obciążeniowe (k6, skrypty w `load/`) mierzą krytyczne ścieżki: katalog, stronę produktu, wyszukiwarkę, koszyk
  i checkout gościa, przez sklep, na klastrze k3d z obrazami produkcyjnymi (`make -C infra/k8s load`). Wynik opisuje
  tę maszynę i to demo (mały katalog), nie skalę docelową. Raport pomiaru jest w `docs/reports/`. `make load-smoke` uruchamia skrypty z jednym użytkownikiem w CI, żeby nie przestały działać.

**Spójność i współbieżność**
- Optimistic locking (wersja) dla edycji z widoku: zmiana agregatu edytowanego równolegle (zamówienie, cena, korekta
  stanu magazynowego) wskazuje wersję, na której się opiera, a nieaktualna wersja kończy się konfliktem.
- Krótka blokada wiersza (`SELECT … FOR UPDATE`) dla atomowych operacji na stanie magazynowym (przyjęcie, rezerwacja,
  zwolnienie, zatwierdzenie): szereguje je baza, bez sprawdzania wersji z widoku i bez ponawiania. Wiersze kilku
  produktów są blokowane w kolejności identyfikatorów, więc nie ma zakleszczeń; każda zmiana i tak podnosi wersję.
- Zmiany koszyka szereguje krótka blokada wiersza koszyka, bez wersji z widoku: równoległe dodania z dwóch kart są
  wszystkie zapisane, a zmiana ilości z nieaktualnej karty nadpisuje nowszą (ostatni zapis wygrywa).
- `Idempotency-Key` na operacjach, które nie mogą się wykonać dwa razy: złożenie zamówienia, rozpoczęcie płatności,
  przyjęcie magazynowe. Podwójne kliknięcie „Zapłać” nie tworzy dwóch zamówień, a ponowione przyjęcie nie zwiększa
  stanu drugi raz.
- Odbiorcy zdarzeń są idempotentni (outbox daje dostarczenie „co najmniej raz”).
- Webhooki płatności: weryfikacja podpisu, deduplikacja po identyfikatorze zdarzenia.

**Obserwowalność**
- Logi strukturalne (JSON) z Monologa.
- Correlation ID nadawany na wejściu (HTTP) i przekazywany przez RabbitMQ do konsumentów,
  więc jedno żądanie da się prześledzić przez wszystkie procesy, które uruchomiło.
- Health checki dla API i wszystkich usług w docker-compose.
- Profilowanie PHP lokalnie przez SPX (php-spx): rozszerzenie tylko w obrazie deweloperskim API,
  włączane na żądanie, z podglądem flame graph w przeglądarce. W środowisku demo wydajność śledzimy
  przez trace'y (Grafana Cloud) i Sentry Performance.

**Bezpieczeństwo**
- Rate limiting (Symfony RateLimiter, Redis) na składaniu zamówień, rozpoczęciu płatności i webhookach dostawców;
  rozpoczęcie logowania w sklepie (`/auth/login`) ma licznik w Redisie sklepu (30 na 10 min na adres). Za Cloudflare
  adres klienta pochodzi z nagłówka `CF-Connecting-IP`, którego nie da się podrobić, bo origin przyjmuje ruch tylko
  z adresów Cloudflare (`docs/features/hardening/spec.md`).
- Nagłówki bezpieczeństwa: sklep, panel i API wysyłają `X-Content-Type-Options: nosniff` (sklep i panel także
  `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy` i CSP; CSP panelu ogranicza skrypty do własnego origin),
  HSTS dodaje ingress przy TLS (`docs/features/hardening/spec.md`).
- Walidacja wejścia na granicy API (DTO + Symfony Validator). Domena dodatkowo pilnuje swoich niezmienników.
- CORS tylko dla domeny panelu (`admin`). Sklep woła API wyłącznie z serwera, więc nie potrzebuje CORS.
- Uprawnienia w panelu sprawdzane w API (votery), nie tylko ukrywane w UI.
- Upload plików: walidacja typu po zawartości (nie po rozszerzeniu), limit rozmiaru i wymiarów,
  bucket z oryginałami niedostępny publicznie.

## Testy

- **Jednostkowe** (domena i aplikacja): adaptery in-memory, bez Postgresa, Elasticsearch i RabbitMQ.
  Trwają sekundy, więc uruchamia się je przy każdej zmianie.
- **Integracyjne**: repozytoria Doctrine, indeksowanie w ES, Messenger na RabbitMQ, zapis w Garage,
  na prawdziwych usługach.
- **Funkcjonalne API**: każdy endpoint, w tym przypadki bez autoryzacji i z błędnymi danymi.
- **Frontend**: testy komponentów (Vitest) dla logiki w store i admin.
- **E2E** (Playwright) dla krytycznych ścieżek:
  1. wyszukanie produktu → strona produktu → koszyk → checkout gościa → płatność → potwierdzenie,
  2. logowanie klienta przez Keycloak → historia zamówień,
  3. obsługa w panelu: zmiana ceny → nowa cena widoczna w sklepie.

## Dane demo

Komenda ładująca realistyczny katalog sportowo-outdoorowy: około 200 produktów (marka × model) w drzewie kategorii,
z około tysiącem wariantów (kolor, rozmiar, smak, oprawa; ceny i stany per wariant), zdjęciami (prawdziwe zdjęcia
„w terenie” w spójnej tonacji, wgrywane do Garage), cenami w różnych stawkach VAT,
stanami magazynowymi, kilkoma aktywnymi promocjami i kodami rabatowymi (`WITAJ10`, `MINUS50` i wygasły `LATO15`).
Do tego konta testowe w Keycloak: klient w realmie `shop`, pracownicy z każdą rolą w realmie `backoffice`. Po `make up` i `make seed`
sklep ma wyglądać jak prawdziwy.

## Zakres MVP

1. Szkielet (walking skeleton): wszystkie usługi startują jedną komendą, logowanie przez Keycloak
   w sklepie i panelu, lista produktów ze zdjęciami przechodzi drogę Postgres → API → Elasticsearch → sklep
   (obrazy przez imgproxy), wygenerowany klient TS używany w obu frontach, pierwsza wersja danych demo.
2. Pełne CI: cały stos w GitHub Actions, testy integracyjne i E2E.
3. Projekt wizualny sklepu (kierunek w sekcji „Wygląd sklepu”): makiety strony głównej, listingu i karty produktu
   ze zdjęciami, potem docelowe tokeny i komponenty.
4. Zarządzanie katalogiem w panelu (z uploadem zdjęć), wyszukiwarka i strona produktu w sklepie (SSR/ISR, SEO),
   Varnish przed endpointami katalogu z unieważnianiem po tagach.
5. Ceny: netto/VAT/brutto, historia cen.
6. Koszyk i checkout (gość i zalogowany klient).
7. Zamówienia ze statusami: nowe → opłacone → wysłane → dostarczone, plus anulowanie i zwrot.
   E-mail z potwierdzeniem (Mailpit).
8. Płatności: dwóch udawanych dostawców za jednym interfejsem, webhooki z podpisem.
9. Rezerwacja stanów bez sprzedaży ponad stan przy równoległych zakupach.
10. Promocje i kody rabatowe, z ceną Omnibus.

## Środowisko wdrożeniowe

Pierwsze wdrożenie poza komputerem dewelopera: jeden serwer, jeden klaster, jeden pipeline. Na start działa jako
demonstracja (sklep publiczny z paskiem o symulowanych płatnościach, `noindex`, panel za Cloudflare Access, rejestracja
klientów wyłączona, potwierdzenia zamówień nie wychodzą na zewnątrz). Te ograniczenia są wartościami konfiguracji
(`infra/k8s/environments/prod/values.yaml`), więc przejście na publiczny sklep nie wymaga przebudowy obrazów.
Specyfikacja: `docs/features/deploy/spec.md`, uruchomienie krok po kroku: `infra/k8s/README.md`.

- **Serwer**: jeden VPS (8 vCore, 24 GB RAM, OVH) z Ubuntu 24.04, przygotowany po SSH przez `make -C infra/k8s bootstrap`.
- **Kubernetes**: k3s na jednym węźle (Traefik z k3s jako ingress, `local-path` jako storage). Własny chart
  `infra/k8s/charts/shop` na tych samych obrazach usług co `infra/docker-compose.yml`; gotowe charty tylko dla
  platformy (Argo CD, Sealed Secrets).
- **GitOps**: Argo CD (app-of-apps w `infra/k8s/argocd/`) śledzi gałąź `deploy-images`. Zmiana w repo → Argo
  synchronizuje klaster, bez ręcznego `kubectl apply`.
- **CI**: GitHub Actions z każdego commita na `main` buduje cztery obrazy produkcyjne (api, store, admin,
  elasticsearch), wypycha je do GitHub Container Registry, uruchamia smoke test na k3d na tych obrazach i dopiero
  po zielonym jednym atomowym pushem przesuwa `deploy` (fast-forward na commit z `main`) i dodaje do `deploy-images`
  snapshot: ten commit plus plik z tagiem i digestami obrazów. Obrazy są wdrażane po digeście.
- **Sekrety**: nigdy w repo w postaci jawnej; Sealed Secrets (certyfikat kontrolera w repo, klucz prywatny w kopii
  u właściciela).
- **Migracje i indeks**: osobny Job przed wdrożeniem nowej wersji, nigdy przy starcie kontenera aplikacji; dane demo
  wczytuje idempotentny Job.
- **Brzeg sieci**: Cloudflare (proxy, „Full (strict)” z certyfikatem Origin CA, cache obrazów z imgproxy, Access przed
  panelem); serwer przyjmuje HTTP(S) tylko od Cloudflare.
- **Kopie zapasowe**: codzienna, szyfrowana (restic) kopia baz PostgreSQL i obiektów Garage do bucketu Cloudflare R2,
  poza serwerem; retencja 7 dni; hasło restic u właściciela.
- **Poczta**: Brevo SMTP dla Keycloaka i (za przełącznikiem) potwierdzeń zamówień; SPF, DKIM, DMARC w Cloudflare.
- **Świadomie bez Ranchera**: przy jednym klastrze to narzut bez korzyści.

Kolejne kroki, osobne zgłoszenia:

- **DEPLOY-OPS** (#178): weryfikacja kopii zapasowych i ich odtwarzanie, monitoring (nocna kopia do Cloudflare R2 już jest).
  Obserwowalność przez Grafana Cloud (darmowy plan) – metryki, logi i trace'y przez agenta Grafana Alloy w klastrze, bez własnego Prometheusa
  i Lokiego. Musi być skończone przed przełączeniem na publiczny sklep albo przed pierwszymi prawdziwymi danymi.
- **Tryb publicznego demo** (#179): dane resetowane co noc z danych demo, konta demo podane na stronie logowania, limity
  uploadów.
- **Sentry** (#180): Sentry SaaS (darmowy plan) dla API, store i admin, także Sentry Performance.

## Poza zakresem (na razie)

Wiele walut, wiele języków, prawdziwi dostawcy płatności, integracje z kurierami, faktury, funkcje marketplace.

## Decyzje architektoniczne (docs/adr/)

Każda obowiązująca decyzja ma plik w `docs/adr/` z uzasadnieniem. Pliki opisują stan obecny:
zmiana decyzji to edycja, porzucenie to usunięcie. Do opisania:


- Dlaczego DDD + hexagonal w tym systemie (i gdzie to przesada).
- REST zamiast GraphQL/gRPC dla tych klientów.
- OpenAPI jako kontrakt i generowany klient TS zamiast ręcznie pisanych typów.
- Keycloak zamiast własnego serwera OAuth.
- Klienci i pracownicy jako osobne byty w osobnych realmach Keycloak, z osobnymi firewallami w API.
- Domena bez mapowania Doctrine: osobny model persystencji (rekordy z atrybutami w Infrastructure) i jawne
  mapowanie agregat ↔ rekord w repozytoriach. To założenie początkowe, nie ostateczna decyzja: po walking
  skeleton ocenimy, czy osobny model persystencji daje korzyści warte kosztu utrzymania. Jeśli okaże się zbędny,
  rozważymy bezpośrednie mapowanie agregatów przez Doctrine w XML. Wyjątek: Inventory zapisuje przez DBAL,
  a jego rekordy służą tylko za definicję schematu (uzasadnienie w `apps/api/CLAUDE.md`).
- Osobne value objecty w każdym contexcie zamiast wspólnej klasy bazowej.
- Transactional outbox dla zdarzeń domenowych.
- Elasticsearch wyłącznie jako model odczytu, z polską analizą tekstu przez wtyczkę `analysis-stempel` (własny obraz
  zamiast gotowego z rejestru).
- ISR z rewalidacją na żądanie dla stron produktów zamiast SSR przy każdym żądaniu; strony kategorii i lista to SSR
  z odczytami przez Varnisha (adres kategorii i filtry w URL).
- Varnish z tagami (xkey) tylko przed publicznymi odczytami API; trzy warstwy cache i ich unieważnianie.
- Model mieszany: optimistic locking i klucze idempotencji dla edycji z widoku i operacji powtarzanych przez klienta,
  krótka blokada wiersza (`SELECT … FOR UPDATE`) z decyzją w agregacie dla atomowych operacji na stanie magazynowym,
  żeby sprzedaż ponad stan była niemożliwa także przy równoległych rezerwacjach.
- Garage jako magazyn S3 i imgproxy (przetwarzanie obrazów na żądanie, format w podpisanym adresie) zamiast
  generowania miniatur przy uploadzie.
- Drzewo kategorii jako lista sąsiedztwa (`parent_id`, źródło prawdy) ze zmaterializowaną ścieżką do zapytań
  o poddrzewo, bez nested set i Gedmo Tree. Kategoria ma slug unikalny wśród rodzeństwa (nie globalnie); adres
  kategorii to ścieżka slugów od korzenia, liczona przy odczycie drzewa, bez kolumny w bazie.
- Klasa podatkowa produktu (`taxClass`) w Catalog zamiast stawki VAT; stawkę dla PL wyznacza Pricing, tabela w kodzie.
- SPX zamiast Blackfire do profilowania (darmowe, wystarczające przy tej skali).
- Grafana Cloud i Sentry SaaS zamiast hostowania obserwowalności u siebie.

## Praca z agentami AI

- Każda funkcja przechodzi drogę: specyfikacja → plan → implementacja → review → CI. Specyfikacja zostaje
  w `docs/features/` i opisuje aktualne działanie funkcji, plan jest usuwany po jej ukończeniu.
- Dokumentacja zawsze opisuje stan obecny, bez dziennika zmian. To, co usuwamy z projektu,
  usuwamy też z dokumentacji.
- Granic architektury pilnują narzędzia (Deptrac, PHPStan, ESLint, tsc) w `make check` i w CI, a nie zaufanie
  do modelu.
- Zadania z planu realizują subagenci Claude Code prowadzeni przez plugin Superpowers: każde zadanie ma
  implementację i niezależne review, a PR przechodzi CI. Konfiguracja jest w repo (`.claude/settings.json`),
  zasady pracy w `CLAUDE.md`.
- Agenci pracują lokalnie, na pełnym stosie w Dockerze.
- Zasady wspólne są w głównym `CLAUDE.md`, zasady konkretnej aplikacji w jej własnym `CLAUDE.md`,
  żeby agent pracujący nad frontem nie dostawał w kontekście zasad Doctrine i odwrotnie.
- Raport z każdego PR podaje czas pracy i zużycie modelu, żeby koszt pracy z AI był widoczny.
