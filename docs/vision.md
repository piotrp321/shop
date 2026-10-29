# Shop monorepo – wizja projektu

## Co budujemy

Platformę e-commerce w monorepo: API w Symfony, sklep dla klientów i panel administracyjny.
Branża: sport i outdoor, szeroki asortyment w kilku działach. Robocza nazwa sklepu: „Rytm”.

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
- **Redis** – cache (odczyty katalogu, koszyki gości), limity dla rate limitera.
- **RabbitMQ** – komunikaty asynchroniczne przez Symfony Messenger: zdarzenia domenowe między contextami,
  indeksowanie w ES, e-maile, rewalidacja stron sklepu.
- **Keycloak** – tożsamość użytkowników w dwóch osobnych realmach (szczegóły w sekcji „Klienci i pracownicy”).
  API tylko waliduje tokeny JWT i mapuje role, nigdy nie przechowuje haseł.
- **Garage** – magazyn plików zgodny z S3. Przechowuje oryginały zdjęć produktów w prywatnym buckecie.
  API zapisuje przez Flysystem, więc w produkcji wystarczy podmienić adapter na prawdziwe S3.
- **imgproxy** – serwuje zdjęcia: czyta oryginał z Garage i na żądanie skaluje, przycina i konwertuje
  do formatu wskazanego w adresie (AVIF albo WebP). Adresy są podpisane (HMAC), więc nikt z zewnątrz nie wygeneruje dowolnych rozmiarów
  i nie obciąży serwera. W produkcji przed imgproxy stoi CDN, który cache'uje wyniki.
- **Varnish** – HTTP cache przed publicznymi endpointami odczytu API (katalog, wyszukiwarka, filtry).
  Odciąża API i Elasticsearch przy stronach wyszukiwarki renderowanych przez SSR, gdzie te same zapytania
  powtarzają się bardzo często. Unieważnianie precyzyjne przez tagi (xkey), opisane niżej.
- **Mailpit** – przechwytuje e-maile wysyłane lokalnie (potwierdzenia zamówień), z podglądem w przeglądarce.

## Zdjęcia produktów

- Panel wysyła oryginał do API, API waliduje (typ, rozmiar, wymiary) i zapisuje w Garage pod kluczem wyliczonym
  z treści pliku, więc adres obrazu o danej treści nigdy się nie zmienia. Oryginał nie jest nigdy serwowany
  bezpośrednio.
- Zestaw rozmiarów jest zamknięty i zdefiniowany w jednym miejscu w API (miniatura, lista, karta produktu, zoom),
  każdy w kilku szerokościach dla `srcset`.
- API w odpowiedziach zwraca gotowe, podpisane adresy imgproxy dla każdego rozmiaru, szerokości i formatu.
  Podpisuje jeden serwis po stronie API, klucz podpisu nigdy nie trafia do frontów.
- Format (AVIF, WebP) jest częścią podpisanego adresu. imgproxy nie wybiera go po nagłówku `Accept`, bo przed
  imgproxy stoi darmowy Cloudflare, który nie obsługuje Vary for Images. Każdy wariant musi mieć jednoznaczny
  adres dla CDN.
- Sklep składa `<picture>` ze źródłami AVIF i WebP przez `getImageProps()` z `next/image` z loaderem, który wybiera
  gotowy adres z odpowiedzi API dla żądanej szerokości. Wbudowana optymalizacja obrazów Next.js jest wyłączona,
  żeby obrazy nie były przetwarzane dwa razy.

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
- Zmiany wykonane w panelu zapisują identyfikator pracownika (z tokena), żeby było wiadomo, kto zmienił
  cenę, stan czy status zamówienia.
- Ta sama osoba jako klient i jako pracownik ma dwa osobne konta. To świadoma decyzja.

## Cache HTTP i unieważnianie

Trzy warstwy cache, każda z jasną rolą:

| Warstwa | Co trzyma | Unieważnianie |
|---|---|---|
| Next.js (ISR) | HTML stron produktów i kategorii | rewalidacja na żądanie po zdarzeniu domenowym |
| Varnish | odpowiedzi publicznych endpointów `GET` katalogu i wyszukiwarki | purge po tagach (xkey) po zdarzeniu domenowym |
| Cloudflare | obrazy z imgproxy, statyczne zasoby | adresy obrazów są niezmienne, więc bez unieważniania |

- Każda odpowiedź katalogu dostaje tagi obiektów, które zawiera (np. `product-123`, `category-7`),
  przez FOSHttpCacheBundle.
- Zmiana produktu, ceny lub stanu publikuje zdarzenie domenowe. Jeden konsument unieważnia w Varnishu
  odpowiedzi z danym tagiem, drugi rewaliduje odpowiednie strony w Next.js. Reszta cache zostaje.
- Varnish nigdy nie cache'uje: koszyka, checkoutu, konta klienta, panelu admina ani żadnego żądania
  z nagłówkiem `Authorization` lub ciasteczkiem sesji.
- Odpowiedzi publiczne mają jawne nagłówki `Cache-Control` ustawiane w API, a nie domyślne zachowanie Varnisha.

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

- **Strony produktów i kategorii**: renderowane na serwerze (ISR). Gotowy HTML jest cache'owany,
  a po zmianie ceny, stanu lub opisu API wysyła rewalidację na żądanie (zdarzenie domenowe → webhook do Next.js).
  Wyszukiwarka zawsze dostaje pełną treść, a ruch nie obciąża API przy każdym wejściu.
- **Wyszukiwarka i filtry**: SSR, parametry w URL, kanoniczne adresy dla kombinacji filtrów,
  `noindex` dla kombinacji bez wartości SEO.
- **Koszyk, checkout, konto klienta**: renderowane po stronie klienta, `noindex`.
- **Metadane**: tytuł, opis, Open Graph (z obrazem z imgproxy), `canonical` generowane per strona
  (Metadata API w Next.js).
- **Dane strukturalne**: JSON-LD `Product` (cena, dostępność, zdjęcia), `BreadcrumbList`, `Organization`.
- **Mapa strony i robots**: `sitemap.xml` generowana z katalogu, `robots.txt`.
- **Przyjazne adresy**: `/p/<slug>`, `/c/<slug-kategorii>`, przekierowania 301 przy zmianie sluga.
- **Wydajność**: Core Web Vitals jako wymaganie (obrazy z imgproxy w WebP/AVIF z `srcset`,
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
- Kolejność prac: najpierw pełne makiety strony głównej, listingu i karty produktu ze zdjęciami, dopiero potem
  docelowe tokeny. Do tego czasu sklep ma tymczasowy, neutralny motyw na tych samych tokenach.

## Bounded contexty (api)

| Context | Odpowiedzialność |
|---|---|
| Catalog | Produkty, warianty, kategorie, atrybuty, zdjęcia |
| Pricing | Ceny katalogowe (netto, VAT, brutto), promocje, kody rabatowe, historia cen (Omnibus) |
| Cart | Pozycje koszyka, podgląd sum, koszyki gości i zalogowanych |
| Ordering | Checkout, cykl życia zamówienia (Symfony Workflow), historia zamówień |
| Payment | Dostawcy płatności za jednym portem, status płatności, webhooki dostawców |
| Inventory | Stany magazynowe, rezerwacja przy zamówieniu, zwolnienie przy anulowaniu |
| Customer | Profil klienta, adresy; sama tożsamość jest w Keycloak |

Contexty nie importują nawzajem swojego kodu domenowego. Komunikują się przez zdarzenia domenowe
(asynchronicznie, RabbitMQ) albo przez jawne porty w warstwie aplikacji. To samo słowo może znaczyć
co innego w różnych contextach (cena w Pricing to nie kwota do obciążenia w Payment), dlatego każdy
context ma własne value objecty.

## Reguły domenowe, które obowiązują od początku

- **Ceny brutto i VAT**: klient widzi ceny brutto. Cena przechowuje netto, stawkę VAT i brutto,
  liczone w groszach z jawną regułą zaokrąglania. Produkt w Catalog ma kategorię podatkową (`taxCategory`:
  np. standardowa, żywność, suplementy diety, publikacje drukowane), a nie stawkę. Pricing wyznacza stawkę
  (23%, 8%, 5%, 0%) z kategorii podatkowej i okresu obowiązywania stawki.
- **Omnibus**: każda zmiana ceny jest zapisywana w historii. Przy promocji sklep pokazuje najniższą cenę
  z 30 dni przed jej rozpoczęciem.
- **Stany magazynowe**: sprzedaż ponad stan jest niemożliwa, także przy równoległych zakupach.
- **Zamówienie**: po złożeniu ceny, rabaty i adres są zamrożone w zamówieniu (kopia, nie referencja),
  więc późniejsza zmiana ceny produktu nie zmienia historycznego zamówienia.

## Wymagania niefunkcjonalne

Wymagania obowiązują cały projekt. Wdrażamy je razem z funkcjami, których dotyczą, a nie w szkielecie.

**Spójność i współbieżność**
- Optimistic locking (wersja) na agregatach, które mogą być edytowane równolegle (zamówienie, stan magazynowy, cena).
- `Idempotency-Key` na operacjach, które nie mogą się wykonać dwa razy: złożenie zamówienia, rozpoczęcie płatności.
  Podwójne kliknięcie „Zapłać” nie tworzy dwóch zamówień.
- Odbiorcy zdarzeń są idempotentni (outbox daje dostarczenie „co najmniej raz”).
- Webhooki płatności: weryfikacja podpisu, deduplikacja po identyfikatorze zdarzenia.

**Obserwowalność**
- Logi strukturalne (JSON) z Monologa.
- Correlation ID nadawany na wejściu (HTTP) i przekazywany przez RabbitMQ do konsumentów,
  więc jedno zamówienie da się prześledzić przez wszystkie procesy.
- Health checki dla API i wszystkich usług w docker-compose.
- Profilowanie PHP lokalnie przez SPX (php-spx): rozszerzenie tylko w obrazie deweloperskim API,
  włączane na żądanie, z podglądem flame graph w przeglądarce. W środowisku demo wydajność śledzimy
  przez trace'y (Grafana Cloud) i Sentry Performance.

**Bezpieczeństwo**
- Rate limiting (Symfony RateLimiter, Redis) na logowaniu, checkout i webhookach.
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

Komenda ładująca realistyczny katalog sportowo-outdoorowy: kilkaset produktów w drzewie kategorii, z wariantami,
zdjęciami (prawdziwe zdjęcia „w terenie” w spójnej tonacji, wgrywane do Garage), cenami w różnych stawkach VAT,
stanami magazynowymi i kilkoma aktywnymi promocjami.
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

## Po MVP: publiczne środowisko demo

Publiczna wersja jest jednocześnie środowiskiem pokazowym (bez osobnej produkcji). Cel: pełny,
realistyczny deployment przy kosztach rzędu kilkudziesięciu euro miesięcznie.

- **Serwer**: jeden serwer (dedykowany lub VPS z odpowiednią ilością RAM).
- **Kubernetes**: k3s na jednym węźle. Aplikacje i usługi jako Helm charty w `infra/k8s/`.
- **GitOps**: Argo CD. Zmiana w repo → Argo synchronizuje klaster. Żadnego ręcznego `kubectl apply`.
- **CI**: GitHub Actions buduje obrazy, wypycha je do GitHub Container Registry i aktualizuje tagi w repo,
  które Argo wdraża.
- **Obserwowalność**: Grafana Cloud (darmowy plan) – metryki, logi i trace'y przez agenta Grafana Alloy
  w klastrze, bez własnego Prometheusa i Lokiego.
- **Błędy**: Sentry SaaS (darmowy plan) dla API, store i admin.
- **Brzeg sieci**: Cloudflare (darmowy plan) – DNS, TLS, cache obrazów z imgproxy.
- **Sekrety**: nigdy w repo w postaci jawnej (Sealed Secrets albo SOPS).
- **Migracje i indeks**: osobny Job przed wdrożeniem nowej wersji, nigdy przy starcie kontenera aplikacji.
- **Tryb demo**: dane resetowane co noc z danych demo, konta demo podane na stronie logowania,
  płatności udawane, e-maile nie wychodzą na zewnątrz.
- **Świadomie bez Ranchera**: przy jednym klastrze to narzut bez korzyści.

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
  mapowanie agregat ↔ rekord w repozytoriach.
- Osobne value objecty w każdym contexcie zamiast wspólnej klasy bazowej.
- Transactional outbox dla zdarzeń domenowych.
- Elasticsearch wyłącznie jako model odczytu.
- ISR z rewalidacją na żądanie zamiast SSR przy każdym żądaniu dla stron katalogu.
- Varnish z tagami (xkey) tylko przed publicznymi odczytami API; trzy warstwy cache i ich unieważnianie.
- Optimistic locking i klucze idempotencji zamiast blokad pesymistycznych.
- Garage jako magazyn S3 i imgproxy (przetwarzanie obrazów na żądanie, format w podpisanym adresie) zamiast
  generowania miniatur przy uploadzie.
- Drzewo kategorii jako lista sąsiedztwa (`parent_id`, źródło prawdy) ze zmaterializowaną ścieżką do zapytań
  o poddrzewo, bez nested set i Gedmo Tree.
- Kategoria podatkowa produktu w Catalog zamiast stawki VAT; stawkę wyznacza Pricing.
- SPX zamiast Blackfire do profilowania (darmowe, wystarczające przy tej skali).
- k3s + Argo CD na jednym serwerze; Grafana Cloud i Sentry SaaS zamiast hostowania obserwowalności u siebie.

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
