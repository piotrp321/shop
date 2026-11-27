# Testy obciążeniowe (k6)

Skrypty k6 krytycznych ścieżek sklepu. Specyfikacja: `docs/features/hardening/spec.md` (sekcja „Testy obciążeniowe”).
k6 działa w kontenerze, obraz jest przypięty tagiem i digestem w `k6.sh` (jedyne miejsce); na hoście nic się nie instaluje.

## Uruchamianie

- `make load-smoke` – jeden użytkownik, jedna iteracja każdego scenariusza (strony katalogu, wyszukiwarka z filtrem i bez,
  koszyk, checkout gościa na 1 sztukę) na działającym stosie compose (`make up`, `make seed`). Bez progów wydajności, ale
  niepowodzenie scenariusza (odpowiedź inna niż oczekiwana, żądanie z błędem, checkout bez zamówienia i rozpoczętej płatności)
  kończy się kodem niezerowym (99; 108 – odrzucone zamówienie przerywa przebieg; 2 – stos nie działa). k6 biegnie
  w sieci stosu i woła `http://store:3000` oraz `http://api`; `Origin` zapisów to publiczny adres sklepu (`STORE_URL`
  kontenera sklepu), którego BFF wymaga. CI uruchamia ten cel po testach E2E.
- `make load-cookies` – test klienta k6 (`lib/http.js`) na serwerze, który ustawia ciasteczko z `Secure`, w Dockerze, bez stosu
  (`test/cookies.sh`). Sklep za ingressem z TLS (klaster) ustawia ciasteczka z `Secure`, a k6 woła go po `http://store:3000`, gdzie
  takich ciasteczek nie odsyła; gdy `STORE_URL` jest `http:`, a `STORE_ORIGIN` `https:`, klient odsyła je mimo to, jak przeglądarka.
  CI uruchamia ten cel po testach E2E.
- `make load-panel` – W7 panelu (program wydajności, PERF-7): mieszanka żądań panelu administracyjnego w tempie jego użytkowników,
  na działającym stosie z danymi profilu (`make seed-scale`); `make load-panel PANEL_MODE=p4 SHOP_URLS_FILE=…` – P4 (wpływ
  panelu na sklep). Opis: „Panel (W7 i P4)” niżej. `make load-panel-check` – testy narzędzi na ścieżkach błędu, bez stosu
  (należy do `make test`).
- `make -C infra/k8s load LOAD_OUT=<katalog poza repo>` – pomiar (`infra/k8s/scripts/load.sh`): rozgrzanie (`warmup.js`),
  przebieg rosnący do nasycenia (`saturate.js`), przebieg stały (`steady.js`), na danych z seeda albo z generatora
  (`LOAD_DATA=seed|medium|server|full`). Opis, kody wyjścia i ustawienia są w nagłówku skryptu i w `CLAUDE.md`.

## Skrypty

| Plik | Rola |
|---|---|
| `smoke.js` | jedna iteracja każdego scenariusza, progi: wszystkie sprawdzenia, brak błędów, 1 zamówienie z rozpoczętą płatnością |
| `warmup.js` | niskie stałe tempo odczytu i koszyka oraz kilka checkoutów przed pomiarem |
| `saturate.js` | tempo iteracji rośnie schodkami (`RAMP_*`) aż p95 odpowiedzi schodka przekroczy 2 s albo błędy 5xx i sieci 1%; wynik: `ramp.json` i `steady-rate` (70% przepustowości) |
| `steady.js` | stałe tempo odczytu i koszyka (`STEADY_RATE`) oraz checkout o stałej, ograniczonej równoległości (`CHECKOUT_VUS`); wynik: `steady.json` |
| `baseline.py` | pomiar zimnej i ciepłej ścieżki katalogu samymi GET (program wydajności, PERF-0: W1–W4, W8), także na worf.pl: mieszanka adresów z danych, tryby pojedynczy, otwarty (stałe tempo) i skok; czasy klienta, szacunek sieci, nagłówki cache, kontrakt odpowiedzi; dowodem zimnej ścieżki są tylko wiersze raportu `cache=miss` (chybienie warstwy własnego celu). Python i curl na hoście; użycie i kody wyjścia w nagłówku |
| `panel.sh` | `make load-panel`: jedno polecenie dla W7 i P4: token administratora, mieszanka z danych, k6, trzy przebiegi, raport |
| `panel.js` | scenariusz k6 panelu (mieszanka w modelu otwartym, wątki najgorszych przypadków, odczyt granic mapy strony, ruch sklepu dla P4) |
| `panel_token.py` | token administratora bez przeglądarki (kod autoryzacyjny z PKCE, hasło i TOTP konta demo), z tokenem odświeżania |
| `panel_mix.py` | mieszanka żądań panelu z danych przez API (klasy: listy, strony głębokie, frazy według częstości, kategorie według rozmiaru, kombinacje, zamówienia, klienci, kody, dashboard) |
| `panel_report.py` | mediana przebiegów i rozrzut, werdykt względem W7, różnica z panelem i bez dla P4, obciążenie PostgreSQL |
| `lib/` | konfiguracja z `ENV`, dane z publicznego API (`setup()`), przepływy, metryki, podsumowania |

## Scenariusze i dane

- Odczyt i koszyk (udziały w `lib/config.js`, `MIX`): lista `/` 10%, kategoria `/c/<ścieżka>` 20%, strona produktu
  `/p/<slug>` 35%, wyszukiwarka `/szukaj?q=…` 25% (połowa z jednym filtrem: opcja z facetów, przedział ceny, dostępne,
  sortowanie albo druga strona), koszyk 10% (dodanie przez `/api/cart/items`, `/api/cart`, strona `/cart`). Skrypty pobierają
  dokumenty HTML i wywołania API sklepu, bez plików statycznych i zdjęć.
- Checkout: dodanie losowego wariantu możliwego do kupienia (ilość 1), odczyt koszyka i `POST /api/checkout` z losowym
  dostawcą płatności. Zamówienie z rozpoczętą płatnością (`redirectUrl`) to sukces.
  Zamówienie dostaje listę pozycji odczytanego koszyka i kupującego `buyer: "guest"` (jak kasa gościa); odpowiedź 422
  albo 409 `cart-changed` to błąd skryptu, więc kończy przebieg (kod 108) komunikatem ze statusem, typem i polami
  naruszeń.
- Dane wejściowe czyta `setup()` (`lib/data.js`) z publicznego API; pusty katalog przerywa przebieg. Katalog nie jest
  nigdy listowany w całości, więc liczba żądań w `setup()` nie zależy od jego rozmiaru:
  - kategorie: drzewo `GET /api/v1/categories` (ścieżki do stron `/c/<ścieżka>`) i dostawcy płatności;
  - produkty: z losowych porcji mapy strony. Klucze porcji daje `GET /api/v1/sitemap/product-chunks`, a produkty porcji
    `GET /api/v1/sitemap/products?chunk=…` (tylko opublikowane). Z każdej wybranej porcji losowana jest równa część
    produktów; dla każdego `setup()` czyta `GET /api/v1/products/<slug>` (identyfikator i nazwa) i `product-offers`
    (warianty możliwe do kupienia, używane przez koszyk i checkout);
  - frazy wyszukiwania: słowa i pary pierwszych słów z nazw wybranych produktów;
  - filtry wyszukiwarki (opcje i zakresy cen): z facetów listingu `GET /api/v1/products?category=<id>&perPage=1` kilku
    losowych kategorii, które mają produkty (trzy, po najwyżej 20 próbach), nie z listingu całego katalogu.
- Opcje wyboru danych (zmienne środowiska k6; `make load-smoke` przekazuje te, które są ustawione):

  | Opcja | Domyślnie | Znaczenie |
  |---|---|---|
  | `DISCOVER_CHUNKS` | brak (losowanie) | klucze porcji oddzielone przecinkami; zastępują losowanie porcji i listę z API |
  | `DISCOVER_CHUNK_COUNT` | 3 | ile porcji wylosować, gdy nie podano `DISCOVER_CHUNKS` (nie więcej, niż ma API) |
  | `DISCOVER_PRODUCTS` | 60 | ile produktów w sumie (równo z każdej porcji); to liczba żądań szczegółów w `setup()` |

  Klucz, którego API nie wydało, albo wartość opcji, która nie jest liczbą nieujemną, kończy `setup()` czytelnym błędem
  (kod 107), a porcje bez opublikowanych produktów, tak jak pusty katalog, błędem „no products”. `setup()` loguje liczbę
  produktów, porcji, wariantów do kupienia, opcji i przedziałów cen. Cel `make -C infra/k8s load` przekazuje k6 te
  opcje, które ustawił wywołujący; z `LOAD_DATA` innym niż `seed` (dane z generatora) sam wybiera porcje
  (`DISCOVER_CHUNKS` wywołującego albo `DISCOVER_CHUNK_COUNT` losowych z API klastra), podnosi stany tylko wariantów
  produktów z tych porcji i przekazuje k6 ich klucze w `DISCOVER_CHUNKS`, więc checkout kupuje warianty z podniesionym
  stanem.
- Każda iteracja ma nowy adres w `CF-Connecting-IP` z puli `2001:db8::/32` (zakres dokumentacyjny RFC 3849; API i sklep liczą adres IPv6 po prefiksie /64, więc adresy różnią się prefiksem; osobne pule dla odczytu i checkoutu, bez powtórzeń w 16 milionach iteracji scenariusza), własny
  słoik ciasteczek (nowa przeglądarka) i tagi `flow`, `kind`, w przebiegu rosnącym `step`.
- Wyniki: 409 (`conflicts_409`) i 429 (`rate_limited_429`) są liczone osobno i nie wchodzą do `http_req_failed`; błędem
  (`server_errors`) jest status 5xx albo błąd sieci; inne 4xx liczy `other_4xx`.

## Ograniczenia

- Checkout przechodzi przez symulator płatności, który działa w kontenerze API, więc jego równoległość jest ograniczona
  poniżej połowy wątków FrankenPHP (`load.sh` bierze ćwierć). Wynik checkoutu przy tej równoległości nie jest ani
  benchmarkiem checkoutu, ani przepustowością systemu.
- Ramp kończy się na nasyceniu przez próg `abortOnFail` danego schodka (po połowie jego długości, najwyżej po 10 s), więc
  kod wyjścia k6 po takim przebiegu to 99 i `load.sh` uznaje go za normalny koniec.

## Panel (W7 i P4)

Wymaganie W7 (`docs/features/performance-program/plan.md`): listy, wyszukiwanie i filtry panelu mają p50 100 ms, p95 500 ms
i maksimum 2000 ms przy pracy użytkowników panelu; P4 (`docs/features/admin-queries/spec.md`, „Wpływ na sklep”): praca panelu nie
pogarsza sklepu. Pomiary biegną po HTTP przez API (`/api/v1/admin/...`), czas to czas klienta k6 w sieci stosu, więc bliski czasowi
serwera.

```sh
make load-panel                                  # W7: 3 przebiegi mieszanki po 300 s, 5 żądań/s
make load-panel PANEL_MODE=p4 SHOP_URLS_FILE=<plik>   # P4: 3 przebiegi bez panelu i 3 z panelem, na przemian
```

- **Token:** `panel_token.py` loguje się jak panel i testy E2E (przepływ kodu z PKCE, klient `admin` realmu `backoffice`, hasło
  i kod TOTP konta `admin` z danych demo; inne konto: `PANEL_USER`, `PANEL_PASSWORD`, `PANEL_TOTP_SECRET`). Token dostępu żyje
  5 minut, więc k6 odnawia go tokenem odświeżania (każdy wirtualny użytkownik osobno); odmowa Keycloaka kończy przebieg (kod 108).
- **Mieszanka:** `panel_mix.py` czyta dane przez API (nazwy produktów z losowych porcji mapy strony, liczby trafień fraz
  i kategorii z pierwszej strony list, kursory stron głębokich z `next`) i zapisuje plik `mix.json` w katalogu wyników razem
  z klasami żądań, wagami i sposobem losowania. `PANEL_MIX=<plik>` używa gotowej mieszanki, więc ta sama jest przed zmianą
  i po niej. Klasy fraz i kategorii wynikają z liczby trafień (brak, rzadka do 20, średnia, częsta ponad limit 10 000, 2 znaki,
  sama interpunkcja, polskie znaki; kategoria pusta, mała do 20, średnia, duża, dział).
- **W7:** żądania mieszanki w stałym tempie (`PANEL_RATE`, domyślnie 5/s, tempo zatwierdzone przez właściciela,
  decyzja D4 specyfikacji) przez `PANEL_SECONDS` (300). Raport: p50, p95, p99 i maksimum całości i każdej klasy (mediana trzech przebiegów
  i najniższy–najwyższy), błędy, werdykt względem W7.
- **P4:** w tle sklep (API): `SHOP_LISTING_RATE` zimnych listingów na sekundę (adresy z `python3 load/baseline.py urls <api>
  <sklep> PLIK --listings N`, każdy raz; N co najmniej `SHOP_LISTING_RATE × PANEL_SECONDS`) i `SHOP_CART_RATE` koszyków na sekundę
  (utworzenie, dodanie, odczyt, zmiana ilości: W5). Przebieg „z panelem” dodaje mieszankę, `PANEL_WORST_VUS` (8) wątków
  najcięższych klas bez przerwy i zimne obliczenie granic mapy strony co `BOUNDARIES_EVERY` (30) sekund (cache `cache.sitemap`
  jest czyszczony w tym czasie). Przy każdym przebiegu zapisywane są liczniki PostgreSQL przed i po (odczyty z dysku i trafienia
  w bufory, bajty tymczasowe, WAL) oraz próbki co 5 s (aktywne sesje, CPU kontenerów). Kryterium: różnica median z panelem i bez
  nie większa niż rozrzut przebiegów bez panelu.
- **Tryb `shop`:** sam sklep (bez panelu) w tempie P4, `PANEL_RUNS` przebiegów: do dobrania tempa, które stos utrzymuje przed P4,
  i jako linia bazowa sklepu. P4 zaczyna od rozgrzewki sklepu (`PANEL_WARMUP_SECONDS`, domyślnie 60 s, niezliczana), bo pierwszy
  przebieg po innym obciążeniu bywa kilka razy wolniejszy; każdy przebieg loguje się od nowa (sesja pierwszego logowania nie
  przetrwa sześciu przebiegów). Adresy listingów są zużywane bez powtórzeń (`SHOP_URL_OFFSET` przesuwa początek).
- **Wyniki:** `PANEL_OUT` (domyślnie nowy katalog w `$TMPDIR`): `mix.json`, `token.json` (krótkotrwały, nie commitować),
  `w7-runN/` albo `p4-with(out)-runN/` z `panel.json`, `k6.txt`, `pg-before.json`, `pg-after.json`, `samples.txt`, oraz
  `report.txt`. Kod wyjścia: 0 gotowe; 2 złe ustawienia (brak stosu, tokenu, pliku mieszanki, zły tryb); kod k6 (99, 108) przy
  niepowodzeniu przebiegu.
- **Koszyki sklepu w P4** idą przez API (`/api/v1/guest-carts`), które limituje tworzenie koszyków po adresie klienta. Bez
  `CLIENT_IP_HEADER=CF-Connecting-IP` w kontenerze `api` (w klastrze za Cloudflare jest ustawiony, w compose nie) wszystkie
  żądania k6 mają jeden adres i dostają 429. Lokalnie ustaw tę zmienną nadpisaniem compose poza repo. Krok zmiany ilości (z 1 na 2) dostaje 409 dla wariantu
  z jedną dostępną jednostką, której publiczne API nie podaje, więc `setup()` czyta jednostki z listy zapasów administratora (`/api/v1/admin/stock?productId=`) i zmienia ilość tylko dla wariantów
  z co najmniej 2 jednostkami.
- **Ograniczenia:** czasy zależą od trybu API (pomiar na `APP_ENV=prod`, w trybie `dev` dochodzi ok. 0,4 s na żądanie), od pamięci
  Postgresa i od obciążenia maszyny; lokalny pomiar jest porównaniem przed i po, nie wynikiem serwera. Pomiar na worf.pl wymaga
  zgody właściciela i tylko żądań GET (mieszanka panelu jest tylko do odczytu; sklep w P4 zakłada koszyki, więc to zapisy).
