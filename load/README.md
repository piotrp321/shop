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
| `baseline.py` | pomiar zimnej i ciepłej ścieżki katalogu samymi GET (program wydajności, PERF-0: W1–W4, W8), także na worf.pl: mieszanka adresów z danych, tryby pojedynczy, otwarty (stałe tempo) i skok; czasy klienta, szacunek sieci, nagłówki cache, kontrakt odpowiedzi; dowodem zimnej ścieżki są tylko wiersze raportu `cache=miss` (chybienie warstwy własnego celu). Python i curl na hoście; w klastrze (sklep może być zamknięty) woła go `make -C infra/k8s measure`: Job w namespace sklepu, przez Servicey `varnish` i `store`, etapy single, open (tempo rośnie osobno dla stron produktów i listingów) i burst; użycie i kody wyjścia w nagłówku |
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
