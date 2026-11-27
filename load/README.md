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
- `make load-logic` – test stanu scenariusza zapisów (`lib/write-state.js`: ceny zmiany, stany oczekiwane po zapisach
  potwierdzonych, odrzuconych i bez odpowiedzi, porównanie z indeksem, dobór próby) w kontenerze k6, bez sieci i bez stosu
  (`test/write-state.sh`). CI uruchamia ten cel po testach E2E.
- `sh load/writes.sh` – scenariusz zapisów (niżej, „Scenariusz zapisów”), lokalnie albo wobec serwera.
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
| `writes.js`, `writes.sh` | scenariusz zapisów: zmiany cen i przyjęcia stanu przez API panelu w modelu otwartym, świeżość indeksu (W16) i kontrola zgodności (W13); wynik: `writes.json` |
| `admin-token.py` | token odświeżania sesji panelu konta demo lokalnego stosu (do `writes.sh`); Python na hoście, użycie w nagłówku |
| `lib/` | konfiguracja z `ENV`, dane z publicznego API (`setup()`), przepływy, metryki, podsumowania; dla zapisów: API i sesja panelu (`admin.js`), zapytanie wariantu (`variant-query.js`), próba (`write-sample.js`), stan wariantów (`write-state.js`) |

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

## Scenariusz zapisów

Specyfikacja: `docs/features/search/spec.md`, „Weryfikacja wydajności (PERF-2 etap B)”; kryterium 3 PERF-2
w `docs/features/performance-program/plan.md`. Zmiany cen (`PUT /api/v1/admin/variants/{variantId}/price`) i przyjęcia stanu
(`POST /api/v1/admin/variants/{variantId}/stock/receipts`) przez API panelu, czyli pełną ścieżką: transakcja, historia,
zdarzenie, reindeksacja. Jeden użytkownik wirtualny k6 wysyła żądania asynchronicznie, więc stan każdego wariantu (wersja
ceny, możliwe ceny i dostępność) jest w jednym miejscu; model otwarty wysyła zapis co `1/WRITE_RATE` s bez względu na
odpowiedzi.

### Token

Uwierzytelnienie to sesja panelu pracownika z rolą `admin` (publiczny klient `admin` realmu `backoffice`), bez nowego konta
i klienta. Skrypt dostaje token odświeżania w zmiennej `ADMIN_REFRESH_TOKEN` (przekazywanej do kontenera po nazwie, nigdy
w wierszu poleceń, nie trafia do logu ani wyniku), wymienia go na tokeny dostępu i odnawia je przed wygaśnięciem. Token
odświeżania sesji panelu wygasa po 15 min bez użycia i najpóźniej po 8 h; wylogowanie w panelu kończy sesję, a z nią przebieg
(błąd przebiegu przy następnym odnowieniu). Zmiany zapisują się w historii pod tym pracownikiem.

- Lokalnie: `python3 load/admin-token.py` loguje konto demo `admin` (hasło i TOTP z `infra/keycloak/realms/backoffice.json`)
  tak jak panel (kod z PKCE) w Keycloaku stosu z `.env` i wypisuje token odświeżania.
- Serwer (nie ma kont demo): zaloguj się do panelu w przeglądarce, w narzędziach dewelopera (Network) otwórz odpowiedź żądania
  `…/realms/backoffice/protocol/openid-connect/token` i skopiuj `refresh_token`. Panel trzyma tokeny tylko w pamięci karty.

### Uruchomienie

```sh
# lokalnie: stos compose tego checkoutu (make up, make seed), k6 w jego sieci, http://api (bez Varnisha) i http://keycloak:8080
ADMIN_REFRESH_TOKEN="$(python3 load/admin-token.py)" WRITE_RATE=5 WRITE_SECONDS=30 WRITE_SAMPLE=20 \
  WRITES_OUT=<istniejący katalog> sh load/writes.sh 2>&1 | tee <plik logu>

# wobec serwera, wyłącznie za zgodą właściciela na ten przebieg i najpierw lokalnie
ADMIN_REFRESH_TOKEN=<token z panelu> API_URL=https://api.<domena> KEYCLOAK_URL=https://auth.<domena> WRITE_RATE=50 \
  WRITES_OUT=<katalog> sh load/writes.sh
```

`API_URL` musi prowadzić do samego API (host `api` ingressu omija Varnisha); zapytanie wariantu z nagłówkiem `X-Cache`, `Age`
większym od 0 albo trafieniem `Cf-Cache-Status` przerywa przebieg. Na worf.pl do origin dochodzi tylko Cloudflare, więc
przebieg idzie przez jego publiczne adresy. Wiersze niezgodności są tylko w wyjściu k6 (stąd `tee`), liczby w `writes.json`.

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `WRITE_RATE` | wymagana | zapisy na sekundę modelu otwartego (zmiany cen i przyjęcia części `bulk`) |
| `WRITE_SECONDS` | 60 | czas wysyłania zapisów |
| `WRITE_SAMPLE` | 40 | liczba wariantów próby |
| `WRITE_UNSTOCKED_SHARE` | 0.1 | udział wariantów bez dostępnego stanu na początku (0–1) |
| `WRITE_PRICE_PROBES` | 3 | warianty próbki świeżości ceny (z części możliwej do kupienia) |
| `WRITE_PRICE_SHARE` | 0.5 | udział zmian cen wśród zapisów modelu otwartego |
| `WRITE_MAX_IN_FLIGHT` | 100 | zapisy modelu otwartego czekające naraz na odpowiedź; zapis ponad limit jest pomijany i liczony (`dropped`) |
| `WRITE_TIMEOUT_SECONDS` | 60 | zapis bez odpowiedzi przez ten czas jest „bez odpowiedzi” |
| `WRITE_DISCOVER_PRODUCTS` | 3 × `WRITE_SAMPLE`, co najmniej 60 | ile produktów czytać z każdego źródła przy wyborze próby |
| `FRESHNESS_POLL_MS` | 250 | odstęp zapytań wariantu próbki świeżości (rozdzielczość pomiaru) |
| `FRESHNESS_TIMEOUT_SECONDS` | 120 | zmiana niewidoczna tak długo kończy swoją próbkę (`timeouts`) |
| `CHECK_TIMEOUT_SECONDS` | 120 | limit czasu kontroli zgodności |
| `CHECK_POLL_SECONDS` | 2 | odstęp rund kontroli |
| `WRITES_OUT` | brak | katalog wyniku `writes.json` |
| `API_HOST`, `KEYCLOAK_HOST`, `WRITES_NETWORK` | brak, `bridge` | nagłówek `Host` (adres inny niż domena usługi) i sieć Dockera przy `API_URL` |

Wynikiem pomiaru jest tylko przebieg z kodem 0: zapisy wysłane w zadanym tempie, każdy z odpowiedzią, każda zmiana próbek
świeżości zobaczona w limicie czasu i kontrola zgodności ukończona bez niezgodności. Werdykt z przyczynami jest w wierszu
`verdict:` wyjścia i w `verdict` pliku `writes.json` (`passed`, `reasons`). Przebieg, który nie jest pomiarem, kończy się kodem
99, nawet gdy kontrola zgodności przeszła:

- zapis pominięty w tempie (`changes.dropped`: limit `WRITE_MAX_IN_FLIGHT` albo brak wolnego wariantu `bulk`, gdy zmiany cen
  czekają na odpowiedź; wtedy zwiększ `WRITE_SAMPLE` albo limit);
- zapis bez odpowiedzi w `WRITE_TIMEOUT_SECONDS` albo z 5xx (`changes.unanswered`); kontrola zgodności i tak sprawdza takie
  warianty względem każdego możliwego stanu;
- zmiana próbki świeżości niewidoczna w `FRESHNESS_TIMEOUT_SECONDS` (`freshness.timeouts`): nie ma jej w percentylach, więc
  `freshness.percentiles_valid` jest `false`, a p95 i p99 nie są wynikiem W16;
- kontrola zgodności nieukończona (`finished: false`), także ucięta przez `maxDuration` scenariusza.

Kody wyjścia: 0 – pomiar w zadanym tempie z kontrolą zgodności bez niezgodności; 99 – przebieg nie jest takim pomiarem
(przyczyny wyżej), niezgodność indeksu, odrzucony zapis (4xx) albo błąd przebiegu (zapytanie poza oknem, odpowiedź z cache,
token, którego nie da się odnowić); 107 – przebieg nie ruszył (brak albo odrzucony
token, token pracownika bez roli `admin`, API albo Keycloak nie odpowiada, pusta albo za mała próba, zła wartość ustawienia);
105 – przerwany; 2 – środowisko (brak Dockera, `ADMIN_REFRESH_TOKEN`, stosu albo katalogu `WRITES_OUT`). Każdy przypadek
podaje przyczynę.

### Próba

Próba jest wybierana na początku przebiegu i zapisana w wyniku. Warianty z ceną w opublikowanych produktach, bez promocji
(cena w ofercie równa cenie katalogowej), bez dwukropka w nazwie osi (takiej osi nie da się podać w `option`): możliwe do kupienia
z losowych porcji mapy strony (jeden wariant na produkt), bez dostępnego stanu z tych samych produktów i z listy stanów panelu
(`level=out_of_stock`). Wariant wchodzi do próby, gdy panel zna wersję jego ceny, indeks pokazuje go jak oferta (zapytanie
wariantu przy cenie początkowej znajduje produkt wtedy i tylko wtedy, gdy wariant jest możliwy do kupienia) i zapytanie
przy każdej cenie, którą może dostać, ma `total` poniżej okna 10 000. Pominięte warianty są policzone według przyczyny
(`left_out`). Kandydat pominięty, bo indeks różnił się na początku od oferty, daje ostrzeżenie `WARNING` w setupie
i w podsumowaniu: indeks może mieć zaległość albo niezgodność z bazą (W13), np. po przerwanym przebiegu; sprawdź wtedy kolejkę
i `failed`. Części próby:

- `unstocked` (udział `WRITE_UNSTOCKED_SHARE`): każdy dostaje jedno przyjęcie (1 szt.), rozłożone równo w czasie przebiegu;
  to próbka świeżości dostępności. Brak takich wariantów przy udziale większym od 0 albo mniej niż potrzeba kończy przebieg
  błędem;
- `price_probe` (`WRITE_PRICE_PROBES`): próbka świeżości ceny; kolejna zmiana wariantu rusza dopiero, gdy zapytanie wariantu
  zobaczy poprzednią (ceny ±1 gr się powtarzają, więc wcześniejsza widoczna cena dałaby fałszywie krótki czas);
- `bulk`: reszta możliwych do kupienia; model otwarty zmienia ich ceny (jedna zmiana wariantu naraz) i przyjmuje stan
  u nich i u `price_probe`.

Zmiana ceny wybiera inną z cen początkowa ±1 gr (nigdy poniżej 1 gr), tylko u wariantów możliwych do kupienia na początku.
Scenariusz nigdy nie odbiera dostępności ani nie dotyka rezerwacji.

### Zapytanie wariantu i świeżość

`GET /api/v1/products?category=<główna kategoria produktu>&option=<oś>:<wartość>…&priceMin=<cena>&priceMax=<cena>&perPage=48`,
strona po stronie do ostatniej (`pageCount`). Produkt jest w wynikach wtedy i tylko wtedy, gdy ten wariant jest w indeksie
możliwy do kupienia za tę cenę. Produkt nieznaleziony przy `total` ponad oknem jest błędem przebiegu. Świeżość (W16) to czas
od odpowiedzi zapisu do odpowiedzi pierwszego zapytania wariantu, które widzi nowy stan (`freshness.price`,
`freshness.availability`: liczba, p50, p95, p99, max w ms); rozdzielczość to `FRESHNESS_POLL_MS` plus czas zapytania.

### Kontrola zgodności i `failed`

Po ostatniej odpowiedzi zapisu kontrola liczy oczekiwany stan każdego wariantu próby z potwierdzonych zapisów (cena ostatnia
zapisana albo początkowa, dostępność początkowa albo „tak” po przyjęciu) i powtarza zapytania wariantów, aż każde zgadza się
z oczekiwanym stanem, najdłużej `CHECK_TIMEOUT_SECONDS`. Zapis bez odpowiedzi (brak odpowiedzi w czasie albo 5xx) mógł się
zapisać albo nie: wariant jest sprawdzany względem każdego stanu, który mógł zostawić, i nie dostaje dalszych zapisów;
ich liczba to `consistency.uncertain_variants`; sam taki zapis sprawia jednak, że przebieg nie jest pomiarem (kod 99, wyżej).
Odrzucony zapis (4xx) niczego nie zmienił, ale jest błędem przebiegu.
Każda niezgodność po limicie to wiersz `consistency: mismatch of variant …: expected …, the index shows …` (wariant, stan
oczekiwany i to, przy których cenach ±1 gr indeks pokazuje wariant) i błąd przebiegu; `consistency.seconds_to_consistent` to
czas do zgodności. Kontrola zakłada, że w trakcie przebiegu nikt poza scenariuszem nie zmienia wariantów próby i nie zaczyna się promocja, która je obejmuje (zamówienie
klienta może dać fałszywą niezgodność).

Po przebiegu sprawdź wiadomości w `failed` (W13 wymaga 0): na serwerze `make -C infra/k8s failed HOST=<user>@<adres>`,
lokalnie `docker compose exec api bin/console messenger:failed:show`.

### Trwałe skutki i stan po przerwaniu

Scenariusz niczego nie cofa: każda zmiana zostaje w historii cen i ruchów magazynowych, w zdarzeniach, w wersjach cen, stanów
i dokumentów indeksu; warianty bez stanu, które dostały przyjęcie, są potem możliwe do kupienia, a stan wariantów próby rośnie
o przyjęte sztuki. Kolejny przebieg liczy ceny ±1 gr od cen ze swojego początku. Przerwany przebieg (Ctrl-C, kod 105) zostawia
warianty próby z ceną różną od początkowej o najwyżej 1 gr i wyższym stanem; zapisy w toku w chwili przerwania mogły się
zapisać albo nie i nie są policzone. `writes.json` przerwanego przebiegu ma `finished: false`, listę wariantów próby
(`sample.variants`: wariant, produkt, część, cena i dostępność na początku) i liczbę wykonanych zmian (`changes`), bez
kontroli zgodności.

## Ograniczenia

- Checkout przechodzi przez symulator płatności, który działa w kontenerze API, więc jego równoległość jest ograniczona
  poniżej połowy wątków FrankenPHP (`load.sh` bierze ćwierć). Wynik checkoutu przy tej równoległości nie jest ani
  benchmarkiem checkoutu, ani przepustowością systemu.
- Ramp kończy się na nasyceniu przez próg `abortOnFail` danego schodka (po połowie jego długości, najwyżej po 10 s), więc
  kod wyjścia k6 po takim przebiegu to 99 i `load.sh` uznaje go za normalny koniec.
