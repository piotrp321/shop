# Testy obciążeniowe (k6)

Skrypty k6 krytycznych ścieżek sklepu. Specyfikacja: `docs/features/hardening/spec.md` (sekcja „Testy obciążeniowe”).
k6 działa w kontenerze, obraz jest przypięty tagiem i digestem w `k6.sh` (jedyne miejsce); na hoście nic się nie instaluje.

## Uruchamianie

- `make load-smoke` – jeden użytkownik, jedna iteracja każdego scenariusza (strony katalogu, wyszukiwarka z filtrem i bez,
  koszyk, checkout gościa na 1 sztukę) na działającym stosie compose (`make up`, `make seed`). Bez progów wydajności, ale
  niepowodzenie scenariusza (odpowiedź inna niż oczekiwana, żądanie z błędem, checkout bez zamówienia i rozpoczętej płatności)
  kończy się kodem niezerowym (99; 2 – stos nie działa). k6 biegnie w sieci stosu i woła `http://store:3000` oraz
  `http://api`; `Origin` zapisów to publiczny adres sklepu (`STORE_URL` kontenera sklepu), którego BFF wymaga. CI uruchamia
  ten cel po testach E2E.
- `make -C infra/k8s load LOAD_OUT=<katalog poza repo>` – pomiar (`infra/k8s/scripts/load.sh`): rozgrzanie (`warmup.js`),
  przebieg rosnący do nasycenia (`saturate.js`), przebieg stały (`steady.js`). Opis, kody wyjścia i ustawienia są w nagłówku
  skryptu i w `CLAUDE.md`.

## Skrypty

| Plik | Rola |
|---|---|
| `smoke.js` | jedna iteracja każdego scenariusza, progi: wszystkie sprawdzenia, brak błędów, 1 zamówienie z rozpoczętą płatnością |
| `warmup.js` | niskie stałe tempo odczytu i koszyka oraz kilka checkoutów przed pomiarem |
| `saturate.js` | tempo iteracji rośnie schodkami (`RAMP_*`) aż p95 odpowiedzi schodka przekroczy 2 s albo błędy 5xx i sieci 1%; wynik: `ramp.json` i `steady-rate` (70% przepustowości) |
| `steady.js` | stałe tempo odczytu i koszyka (`STEADY_RATE`) oraz checkout o stałej, ograniczonej równoległości (`CHECKOUT_VUS`); wynik: `steady.json` |
| `lib/` | konfiguracja z `ENV`, dane z publicznego API (`setup()`), przepływy, metryki, podsumowania |

## Scenariusze i dane

- Odczyt i koszyk (udziały w `lib/config.js`, `MIX`): lista `/` 10%, kategoria `/c/<ścieżka>` 20%, strona produktu
  `/p/<slug>` 35%, wyszukiwarka `/szukaj?q=…` 25% (połowa z jednym filtrem: opcja z facetów, przedział ceny, dostępne,
  sortowanie albo druga strona), koszyk 10% (dodanie przez `/api/cart/items`, `/api/cart`, strona `/cart`). Skrypty pobierają
  dokumenty HTML i wywołania API sklepu, bez plików statycznych i zdjęć.
- Checkout: dodanie losowego wariantu możliwego do kupienia (ilość 1), odczyt koszyka i `POST /api/checkout` z losowym
  dostawcą płatności. Zamówienie z rozpoczętą płatnością (`redirectUrl`) to sukces.
- Dane wejściowe (ścieżki kategorii, slugi, frazy z nazw produktów, filtry z facetów, warianty z `product-offers`, dostawcy
  płatności) czyta `setup()` z publicznego API; pusty katalog przerywa przebieg.
- Każda iteracja ma nowy adres w `CF-Connecting-IP` z puli `198.18.0.0/15` (osobne połowy dla odczytu i checkoutu), własny
  słoik ciasteczek (nowa przeglądarka) i tagi `flow`, `kind`, w przebiegu rosnącym `step`.
- Wyniki: 409 (`conflicts_409`) i 429 (`rate_limited_429`) są liczone osobno i nie wchodzą do `http_req_failed`; błędem
  (`server_errors`) jest status 5xx albo błąd sieci; inne 4xx liczy `other_4xx`.

## Ograniczenia

- Checkout przechodzi przez symulator płatności, który działa w kontenerze API, więc jego równoległość jest ograniczona
  poniżej połowy wątków FrankenPHP (`load.sh` bierze ćwierć). Wynik checkoutu przy tej równoległości nie jest ani
  benchmarkiem checkoutu, ani przepustowością systemu.
- Ramp kończy się na nasyceniu przez próg `abortOnFail` danego schodka (po połowie jego długości, najwyżej po 10 s), więc
  kod wyjścia k6 po takim przebiegu to 99 i `load.sh` uznaje go za normalny koniec.
