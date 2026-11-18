# Audyt wydajności i skalowalności aplikacji `shop` (2026-11-18)

Niezależny audyt wydajności produktu na `origin/main`: analiza kodu, pomiary na własnym lokalnym stosie i research
dokumentacji. Bez zmian w repozytorium, bez issues i PR-ów.

| | |
|---|---|
| Rewizja aplikacji | `1aeb507632ab4db4f79b97b88f1b5b9c744245f6` (`origin/main` z 2026-11-18, 16:58 CET). Stos, obrazy i wszystkie pomiary pochodzą z czystego drzewa tej rewizji. W chwili publikacji raportu `origin/main` to `d0ac76ee`; między tymi rewizjami nie zmienił się żaden plik w `apps/`, `infra/`, `load/` ani `e2e/` |
| Odstępstwa od rewizji w pomiarach | tylko dwa warianty porównawcze, oba poza repozytorium: Caddyfile z dyrektywą `worker` i obraz z opcją trwałego połączenia (sekcja 3.2) |
| Zakres | `apps/api`, `apps/store`, `apps/admin`, `infra` (compose, chart Helm, Varnish, Keycloak), `load` |
| Poza zakresem | Agentic Software House, AO, skille, narzędzia agentów |
| Dane | `make seed-scale PROFILE=small` (2 tys. produktów, 5 tys. zamówień) i `PROFILE=medium` (1 mln produktów, 3 mln wariantów, 1 mln zamówień, baza 8,4 GB) |
| Maszyna | Apple M4 Max, 16 rdzeni, 128 GB RAM; Docker Desktop 29.9.3, VM 16 CPU i 31,3 GiB; **współdzielona z innymi sesjami** (sekcja 2.4) |
| Materiał źródłowy | katalog [`performance-audit-2026-11-18/`](performance-audit-2026-11-18/): skrypty, wybrane surowe wyniki, cztery raporty analizy statycznej i raport z researchu ze źródłami ([`research.md`](performance-audit-2026-11-18/research.md)) |

**Odstępstwo od zlecenia.** Zlecenie zakłada, że epik DEMO-DATA nie jest zaimplementowany. Na `origin/main` jest już
część 1 (PR #744, generator `make seed-scale`), więc użyłem gotowego generatora zamiast przygotowywać własne dane.
Część 2 (#486: integracja generatora z k3d i k6) nie istnieje, więc pomiar `make -C infra/k8s load` nadal działa
tylko na małym katalogu. Pełnej skali (10 mln produktów, 50 mln zamówień) nie mierzyłem.

Każde twierdzenie ma klasę dowodu:

- **[POMIAR]** – zmierzone w tym audycie (liczba, polecenie i plik wyniku w sekcji 12);
- **[KOD]** – potwierdzone odczytem kodu albo konfiguracji (ścieżka wykonania wskazana);
- **[HIPOTEZA]** – wymaga benchmarku, którego nie wykonałem;
- **[SZACUNEK]** – ekstrapolacja pomiaru na większy wolumen albo inny sprzęt.

Wszystkie czasy i przepustowości opisują tę maszynę i ten dzień. Nie są deklaracją wydajności serwera produkcyjnego.
Wartości „CPU na żądanie” to czas CPU kontenera z cgroup podzielony przez liczbę żądań. Zależą od obcego
obciążenia mniej niż czasy odpowiedzi, ale nie są od niego wolne (sekcja 2.4); wnioski opieram na różnicach
kilkukrotnych, nie kilkunastoprocentowych.

## 0. Odpowiedzi wprost

**Jak czytać liczby.** Raport podaje dwa rodzaje przepustowości i nie wolno ich mieszać:

- *przepustowość pojedynczej warstwy w izolacji* (np. API pytane wprost: 3 000 żądań/s, 540 zamówień/s): mówi, ile
  kosztuje żądanie w tej warstwie i co zmienia dana poprawka. Nie jest przepustowością sklepu;
- *przepustowość całej ścieżki* (przebieg do nasycenia przez sklep, sekcja 3.9: 100–140 iteracji/s na tej
  maszynie): wyznacza ją najwolniejsza warstwa, a dla zamówień dodatkowo przetwarzanie asynchroniczne (ok. 17/s).

Żadna z tych liczb nie jest gwarancją dla serwera produkcyjnego: to pomiary na jednym komputerze, współdzielonym
z innymi zadaniami, na danych do 1 mln produktów.

**Co jest pilniejsze od wydajności.** Jedno znalezisko nie dotyczy szybkości, tylko poprawności biznesowej:
nieopłacone zamówienia zwalniają rezerwację towaru z opóźnieniem, które rośnie z zaległością workera i nie ma
górnej granicy (sekcja 4.1, B1). Uważam je za ważniejsze niż każdą z trzech zmian wydajnościowych niżej: większa
przepustowość API przy tym samym workerze to opóźnienie pogłębia.

**Gdzie jest największe wąskie gardło?** Zależy od ścieżki, więc podaję oba, z pomiarem:

1. *Ruch anonimowy po katalogu:* serwer sklepu (Next.js). Strony `/`, `/c/…` i `/szukaj` są renderowane na
   serwerze przy każdym żądaniu (12–15 ms CPU na stronę), a chart ma `replicas: 1` wpisane na sztywno. W dwóch
   przebiegach do nasycenia na 1 mln produktów system utrzymał 90–140 iteracji/s i załamał się, gdy sklep zużywał
   1,0–1,5 rdzenia z 16 dostępnych; żadna inna usługa nie była blisko swojego limitu [POMIAR]. Że granicą jest
   jeden proces Node.js, a nie coś innego w sklepie, to wniosek z trzech zgodnych obserwacji (sekcja 3.9), nie
   z profilowania.
2. *Wszystko, co dociera do API* (koszyk, zamówienie, panel, chybienia cache): FrankenPHP działa w trybie
   klasycznym i otwiera nowe połączenie z Postgresem na każde żądanie. Żądanie z bazą kosztuje 12–22 ms CPU (API
   i Postgres razem, zależnie od współbieżności), choć zapytania SQL strony produktu trwają łącznie 0,36 ms.
   Wariant kontrolny, ten sam tryb klasyczny z trwałym połączeniem: 2,2 ms. Worker mode: 1,0–1,2 ms dla strony
   produktu i 3,0–3,8 ms dla cięższych endpointów. Przepustowość strony produktu rośnie 6,5–7 razy przy 16
   użytkownikach, innych endpointów z bazą 3–4 razy. [POMIAR]

**Co jako pierwsze przestanie się skalować?**

- *Z wolumenem katalogu:* facety wyszukiwarki. Koszt zapytania listingu w Elasticsearch rośnie z liczbą
  pasujących produktów (ok. 0,6–0,8 ms na 1000 produktów ponad stałe kilkanaście ms), a przy szerokich zapytaniach
  99% tego kosztu to agregacje facetów liczone przy każdym chybieniu cache. Listing całego katalogu: 6 ms CPU
  Elasticsearch przy 2 tys. produktów, 598 ms przy 1 mln [POMIAR], czyli ok. 5–6 s przy 10 mln, jeśli wzrost
  pozostanie liniowy [SZACUNEK], powyżej limitu klienta 2 s (odpowiedź 503). Liniowo rosną też
  publiczne zapytania mapy strony. Druga rzecz to wyszukiwanie w panelu: po nazwie produktu od 7 ms do 4,3 s
  zależnie od frazy, lista produktów małej kategorii 1–12 s, już przy 1 mln produktów. [POMIAR]
- *Z ruchem:* sklep (jedna replika, SSR listingów), potem API w trybie klasycznym. [POMIAR kolejności w 3.9]
- *Z liczbą zamówień:* przetwarzanie asynchroniczne. Jeden worker obsługuje ok. 17 zdarzeń zamówień na sekundę
  (e-mail potwierdzenia) i ok. 17 wygaśnięć nieopłaconych zamówień na sekundę, a samo API w izolacji (bez sklepu
  i bez płatności) przyjmuje 120 (tryb klasyczny) do 540 (worker mode) zamówień na sekundę [POMIAR]. 31 tys. zamówień złożonych w 4 minuty testu to
  przy tym tempie ok. 30 minut zaległych e-maili [SZACUNEK: zmierzyłem tempo przez 3 minuty i opróżniłem kolejkę].

**Trzy zmiany o największym efekcie wydajnościowym:**

1. **Koniec z połączeniem do bazy na każde żądanie: trwałe połączenie, docelowo worker mode FrankenPHP.** Trwałe
   połączenie to jedna opcja w konfiguracji Doctrine, worker mode to dyrektywa `worker` w Caddyfile (Symfony 8.1
   obsługuje ją natywnie); oba wymagają walidacji stanu między żądaniami i policzenia połączeń. Zmierzone dla
   strony produktu przy 16 użytkownikach: CPU na żądanie z 16–22 ms do 2,2 ms (trwałe połączenie) i 1,0–1,2 ms
   (worker mode), przepustowość z ok. 450–500 do ponad 3 000 żądań/s. Dla cięższych endpointów z bazą oba
   warianty dają to samo: 3–4× przepustowości i ok. 5× mniej CPU. [POMIAR]
2. **Cache HTML listingów i skalowanie sklepu w poziomie.** Strona główna i kategorie w stanie domyślnym nie
   zależą od użytkownika, a są renderowane od zera przy każdym wejściu. Potrzebny jest cache HTML (ISR/`use cache`
   albo CDN), wspólny cache między replikami i konfigurowalna liczba replik. Zysku nie mierzyłem; punkt
   odniesienia to strona produktu, gdzie trafienie ISR kosztuje 12× mniej CPU niż render. [SZACUNEK]
3. **Facety poza gorącą ścieżką.** Agregacje dla zapytania bez filtrów wariantów zmieniają się rzadko: osobne
   zapytanie facetów z własnym cache (dłuższym niż 60 s) usuwa największy składnik ścieżki klienta, który rośnie
   z katalogiem. Zysk zależy od trafności tego cache dla długiego ogona fraz, której nie mierzyłem. [SZACUNEK]

Liczby przy zmianach 1–3 opisują warstwę, której zmiana dotyczy; o ile wzrośnie przepustowość całej ścieżki,
pokaże dopiero pomiar po zmianie, bo po usunięciu jednego ograniczenia decyduje następne. Czwarta w kolejności
jest przepustowość workera (R6b), a najtańsze są poprawki zapytań panelu (R5). Kolejność z uwzględnieniem
poprawności jest w sekcji 8.

**Czy architektura obsłuży duży sklep bez gruntownej przebudowy?** Tak, z zastrzeżeniem: to wniosek z kodu
i z pomiaru do 1 mln produktów, nie potwierdzenie skali docelowej. API jest bezstanowe, odczyty idą zbiorczymi
zapytaniami DBAL bez N+1, listy panelu mają stronicowanie kursorem i ograniczone liczniki, zapis zdarzeń jest
transakcyjny, Elasticsearch jest tylko modelem odczytu. Na 1 mln produktów zapytania sklepu do Postgresa pozostają
pomijalne wobec reszty kosztu żądania (strona produktu: 4 zapytania, łącznie 0,12 ms przy 2 tys. produktów
i 0,36 ms przy 1 mln). Do zmiany są konfiguracja uruchomieniowa (tryb
serwera, Postgres, Redis, Varnish), model cache sklepu, kształt facetów i topologia workerów. To praca na kilka
epików, nie przebudowa.

## 1. Ogólna ocena

| Obszar | Ocena | Uzasadnienie w jednym zdaniu |
|---|---|---|
| Zapytania sklepu do Postgresa | bardzo dobra | 2–5 zapytań na żądanie; łączny czas SQL poniżej 2,2 ms także przy 1 mln produktów (wyjątek: porcja mapy strony, 206 ms) [POMIAR] |
| Listy i liczniki panelu | dobra, z trzema wyjątkami | kursor i `BoundedCounter` działają; wyszukiwanie po nazwie i filtr kategorii mają złe plany [POMIAR] |
| Uruchomienie API (FrankenPHP, połączenia) | słaba | połączenie na żądanie to 70–90% CPU żądania z bazą [POMIAR] |
| Konfiguracja Postgresa | słaba | wartości domyślne (128 MB `shared_buffers`, brak limitów czasu), baza 8,4 GB już przy 1 mln produktów [POMIAR/KOD] |
| Wyszukiwarka | dobra do ok. 1 mln, potem ryzyko | strona wyników w 3–17 ms, facety do 540 ms na całym katalogu 1 mln [POMIAR] |
| Sklep (Next.js) | dobra jakość kodu, słaba skalowalność wdrożenia | brak cache HTML listingów, jedna replika, cache ISR lokalny i dziś nieograniczony [POMIAR/KOD] |
| Panel (Vue) | dobra | jeden bundle 294 KB gzip, listy stronicowane po stronie API [POMIAR/KOD] |
| Przetwarzanie asynchroniczne | słaba przepustowość | jeden proces, 17–100 zdarzeń/s, kolejki blokują się nawzajem [POMIAR] |
| Redis, Varnish | poprawne użycie, brak konfiguracji | Redis bez `maxmemory`, Varnish z domyślnymi 100 MB [POMIAR/KOD] |
| Keycloak, BFF | dobra | tokeny weryfikowane lokalnie, blokada odświeżania; koszt przy 60 tys. sesji niezmierzony [KOD/HIPOTEZA] |

Aplikacja jest wydajna tam, gdzie pisano ją z myślą o skali (zapytania, indeksy, blokady), i niewydajna tam, gdzie
zostały ustawienia domyślne (tryb serwera PHP, Postgres, Redis, Varnish, liczba replik). To dobra wiadomość:
większość strat da się odzyskać konfiguracją i zmianami punktowymi.

## 2. Metodyka, środowisko, ograniczenia

### 2.1 Jak mierzyłem

- **Własny stos compose** w tym worktree (`COMPOSE_PROJECT_NAME=shop-perfaudit`, porty 52700–52706), sklep
  w trybie `STORE_MODE=production`.
- **API z obrazu produkcyjnego** (`docker build --target prod apps/api`, `APP_ENV=prod`, opcache bez sprawdzania
  plików), czyli to, co uruchamia chart. Trzy kontenery obok stosu: `api-prod` (Caddyfile z repo: tryb klasyczny),
  `api-prodw` (ten sam obraz, podmieniony tylko Caddyfile z dyrektywą `worker`) i `api-prodp` (wariant kontrolny:
  tryb klasyczny z trwałym połączeniem PDO, obraz zbudowany z kopii kodu poza repo). Do pomiaru pełnej ścieżki
  usługi `api` i `worker` stosu przełączyłem lokalnym, niecommitowanym nadpisaniem compose na obraz produkcyjny.
- **k6** (obraz przypięty w `load/k6.sh`) w sieci stosu. Każdy przebieg to jeden rodzaj żądania z losowymi
  parametrami z danych (slugi z porcji mapy strony z całego katalogu, kategorie, frazy z nazw produktów). Wokół
  przebiegu skrypt odczytuje czas CPU każdego kontenera, `pg_stat_statements` i liczbę nowych sesji Postgresa.
- **`EXPLAIN (ANALYZE, BUFFERS)`** dla zapytań wskazanych przez analizę schematu, na danych `medium`.
- **Rozbicie zapytania Elasticsearch:** treść zapytania przechwycona z własnego indeksu (slow log), potem
  warianty tej samej treści bez poszczególnych części i odczyt `took`.
- **Przebieg do nasycenia** projektowym `load/saturate.js` (ruch przez sklep, model otwarty) na danych `medium`.
- **Lighthouse** projektowym `make lighthouse`.
- **Analiza statyczna:** czterech niezależnych audytorów (subagentów) na eksporcie rewizji bez wcześniejszych
  raportów, każdy na jednym obszarze; najważniejsze tezy sprawdziłem potem sam w kodzie i pomiarem. Pięć wątków
  researchu dokumentacji (skill `deep-research`).
- **Weryfikacja.** Gotowy raport przeszedł jedną rundę niezależnej recenzji z dostępem do surowych wyników
  (sekcja 14); po niej powtórzyłem dwa pomiary i poprawiłem tekst.

### 2.2 Zmiany wprowadzone tylko na moim stosie

`pg_stat_statements` i `track_io_timing` w Postgresie (`ALTER SYSTEM`), testowe konto pracownika bez OTP i grant
hasłem w lokalnym realmie `backoffice` (token do pomiaru `/api/v1/admin`), stan jednego wariantu podniesiony do
900 tys. sztuk (test gorącego wariantu), 2000 zmian cen przez API panelu. Nic z tego nie trafiło do repozytorium.

### 2.3 Dane

| | `small` | `medium` |
|---|---|---|
| Produkty / warianty | 2 191 / 7 081 | 1 000 191 (980 083 opublikowane) / 3 009 455 |
| Kategorie | 180 | 180 |
| Zamówienia / linie | 5 000 / 9 509 | 1 000 000 / 1 899 728 |
| Klienci (model odczytu panelu) | 888 | 98 544 |
| Historia cen / ruchy magazynowe | 8 915 / 8 903 | 3 910 971 / 3 238 480 |
| Baza `shop` | 56 MB | 8 406 MB |
| Indeks wyszukiwarki | – | 1,1 GB, 8,9 mln dokumentów Lucene, 1 shard |

### 2.4 Ograniczenia wiarygodności

- **Maszyna współdzielona.** Przez cały audyt działały stosy innych sesji (m.in. klaster k3d testu dymnego
  i testy E2E). Ich kontenery zajmowały od 1 do 10 z 16 rdzeni VM; każdy wynik ma zapisaną próbkę tego obciążenia
  (`docker_cpu_percent_ours_foreign` w `bench.jsonl`). Skutek: czasy odpowiedzi i przepustowość są zaniżone
  i rozrzucone, zwłaszcza p95 i p99. Ten sam scenariusz powtórzony po godzinie dał 2 976 i 6 446 żądań/s
  (worker mode, strona produktu, 16 użytkowników). Serię `small` mierzyłem przy medianie obcego obciążenia 6%
  jednego rdzenia, serię `medium` przy ok. 490%, więc różnice p50 rzędu kilku ms między nimi nie są wynikiem.
- **CPU na żądanie ma własne zniekształcenia.** Okno pomiaru obejmuje pracę tła kontenera (autovacuum, worker
  w części przebiegów). Przy przebiegach z kilkoma żądaniami (wolne scenariusze panelu) wartości dla kontenerów
  spoza ścieżki żądania są bez znaczenia. CPU rośnie też ze współbieżnością: strona produktu w trybie klasycznym
  to 5,8 ms CPU API przy 1 użytkowniku, 10,5–17,3 ms przy 16 i 14,4 ms przy 64. To rywalizacja o rdzenie
  (różne typy rdzeni M4, obce kontenery), nie większa praca.
- **Krótkie przebiegi bez powtórzeń.** Większość przebiegów trwa 8–20 s i jest wykonana raz, w modelu zamkniętym
  (stała liczba użytkowników bez przerw). Percentyle z przebiegów, w których zmieściło się kilka żądań, pomijam.
- **Generator obciążenia na tej samej maszynie** co system (jak w `make -C infra/k8s load`).
- **Sklep w compose to `next start` na katalogu z hosta** (bind mount), nie obraz `standalone` z chartu. Zapis
  wpisów ISR idzie przez system plików hosta, więc bezwzględna przepustowość sklepu jest tu niższa niż w pomiarze
  k3d z 2026-11-10 (277 iteracji/s na 191 produktach). Wskazanie warstwy, która się nasyca, jest zgodne w obu.
- **Zasoby.** Kontenery compose nie mają limitów; Elasticsearch ma stertę 512 MB (prod: 2 GB), Postgres 128 MB
  `shared_buffers` (jak w prod). Profil zasobów `prod` odtwarza tylko pomiar na k3d.
- **Nie uruchamiałem `make -C infra/k8s load`.** Na maszynie zajętej przez inne sesje dałby wynik nieporównywalny
  z raportem z 2026-11-10, a nadal na małym katalogu. Korzystam z tamtego raportu jako punktu odniesienia.
- **Dane `medium`, nie `full`.** Wszystko powyżej 1 mln produktów i 1 mln zamówień to ekstrapolacja.
- **Niezmierzone:** zalogowani klienci (sesje Redis, odświeżanie tokenów w Keycloaku), obrazy przez imgproxy,
  płatność (symulator działa w tym samym kontenerze co API), kod rabatowy z limitem pod obciążeniem, zachowanie
  przy kilku replikach, zimny cache po wdrożeniu.

## 3. Wyniki pomiarów

### 3.1 Generator danych (`make seed-scale PROFILE=medium`)

| Krok | Czas | Uwagi |
|---|---|---|
| `bin/seed-demo` | 45 s | |
| produkty (4 procesy) | 87 s | 1 mln produktów, 3 mln wariantów; ok. 11,5 tys. produktów/s |
| zdjęcia | 148 s | pula 4317 plików do Garage, 1,6 mln przypisań |
| ceny (4 procesy) | 34 s | 3 mln cen, 3,9 mln wpisów historii |
| konta w Keycloak | **błąd po 6 s** | `Operation timed out after 5002 milliseconds` na `partialImport` |
| zamówienia (4 procesy) | 150 s | 1 mln zamówień; ok. 6,7 tys./s |
| stany po zamówieniach | 86 s | |
| modele odczytu | 58 s | w obrazie produkcyjnym; w `dev` błąd pamięci |
| `ANALYZE` | 4 s | |
| reindeksacja | 341 s | w obrazie produkcyjnym; ok. 2,9 tys. produktów/s; w `dev` błąd pamięci |

Trzy znaleziska z samego generowania [POMIAR]:

- **Krok kont nie przechodzi dla `medium`.** `KeycloakAdmin` ustawia `timeout: 120`
  (`apps/api/src/DemoData/Accounts/KeycloakAdmin.php:21,55`), ale wspólny klient HTTP ma też `max_duration: 5`
  (`apps/api/config/packages/framework.yaml:9-12`), którego generator nie nadpisuje. Import 2000 kont w jednym
  żądaniu trwa dłużej niż 5 s. Profil `small` (1000 kont) mieści się w limicie, więc testy tego nie łapią.
  Pozostałe kroki uruchomiłem ręcznie w tej samej kolejności i z tymi samymi opcjami.
- **`ordering:read-models:rebuild` i `catalog:reindex` wyczerpują 256 MB w kontenerze `dev`** (middleware
  debugowe Doctrine i Stopwatch zbierają każde zapytanie). W obrazie produkcyjnym przechodzą. `make seed-scale`
  z profilem większym niż `small` nie da się więc ukończyć na stosie deweloperskim.
- **Przerwana reindeksacja zostawia osierocony indeks** (274 MB po przerwaniu w 91. sekundzie); nic go nie usuwa.

### 3.2 Koszt żądania API: tryb klasyczny, trwałe połączenie, worker mode

Ten sam obraz produkcyjny i te same dane (`medium`), żądania prosto do API, trzy warianty:

- **klasyczny**: Caddyfile i konfiguracja z repo;
- **klasyczny + trwałe połączenie**: wariant kontrolny, obraz zbudowany poza repo z jedną zmianą
  (`PDO::ATTR_PERSISTENT` w opcjach połączenia Doctrine);
- **worker**: obraz z repo, podmieniony tylko Caddyfile (dyrektywa `worker`).

| Scenariusz | Wariant | 1 użytkownik: p50 | 16 użytkowników: żądań/s, p95 | CPU API + Postgres na żądanie (16 użytk.) | Nowe połączenia PG na żądanie |
|---|---|---|---|---|---|
| `/health` (bez bazy) | klasyczny | 1,35 ms | 2 993/s, 14 ms | 1,1 ms | 0 |
| | worker | 0,50 ms | 6 716/s, 7 ms | 0,3 ms | 0 |
| strona produktu `GET /products/{slug}` | klasyczny | 11,3 ms | 445 i 499/s, 69 i 50 ms | 16,0 i 22,3 ms | 1,0 |
| | klasyczny + trwałe połączenie | 2,9 ms | 3 258/s, 10 ms | 1,8 + 0,4 = 2,2 ms | 0 |
| | worker | 1,7 ms | 2 976 i 6 446/s, 13 i 6 ms | 1,2 i 1,0 ms | 0 |
| oferty 24 produktów | klasyczny | 16,9 ms | 445/s, 63 ms | 11,6 + 7,2 = 18,8 ms | 1,0 |
| | klasyczny + trwałe połączenie | 5,7 ms | 1 350/s, 24 ms | 3,4 + 1,6 = 5,1 ms | 0 |
| | worker | 4,6 ms | 1 300/s, 28 ms | 2,2 + 1,6 = 3,8 ms | 0 |
| drzewo kategorii | klasyczny | 12,1 ms | 456/s, 65 ms | 12,3 + 4,8 = 17,1 ms | 1,0 |
| | klasyczny + trwałe połączenie | 4,5 ms | 1 680/s, 20 ms | 3,5 + 0,6 = 4,2 ms | 0 |
| | worker | 3,2 ms | 1 682/s, 23 ms | 2,4 + 0,6 = 3,0 ms | 0 |
| lista zamówień panelu (JWT) | klasyczny | 11,5 ms | 461/s, 61 ms | 11,9 + 5,8 = 17,7 ms | 1,0 |
| | klasyczny + trwałe połączenie | 3,8 ms | 1 678/s, 19 ms | 2,4 + 0,9 = 3,4 ms | 0 |
| | worker | 2,7 ms | 1 650/s, 23 ms | 1,4 + 2,2 = 3,6 ms | 0 |
| listing kategorii-liścia (Elasticsearch) | klasyczny | 29,9 ms | 165/s, 214 ms | 12,4 + 5,3 (+14,5 ES) | 1,0 |
| | worker | 19,6 ms | 281/s, 151 ms | 5,0 + 0,8 (+13,9 ES) | 0 |
| strona produktu, 64 użytkowników | klasyczny | – | 495/s, p95 196 ms | 14,4 + 5,5 = 19,9 ms | 1,0 |
| | klasyczny + trwałe połączenie | – | 4 027/s, p95 32 ms | 1,7 + 0,3 = 2,0 ms | 0 |
| | worker | – | 4 977/s, p95 29 ms | 0,8 + 0,4 = 1,1 ms | 0 |

Dwie wartości w komórce to dwa przebiegi tego samego scenariusza w odstępie ok. godziny; rozrzut pokazuje, ile
warta jest pojedyncza liczba przepustowości na tej maszynie. Wszystkie liczby w tej tabeli to API pytane wprost
jednym rodzajem żądania: opisują koszt warstwy, nie przepustowość sklepu (ta jest w 3.9).

Co z tego wynika [POMIAR]:

- W trybie klasycznym każdy endpoint z bazą kończy się na ok. 450–500 żądań/s, czy robi 2 zapytania, czy 5: to
  pułap kosztu doliczanego do każdego żądania, nie zapytań.
- **Przyczyną jest połączenie z bazą na każde żądanie.** Samo utrzymanie połączenia (bez worker mode) obniża CPU
  Postgresa na żądanie z ok. 5 ms do 0,3–0,4 ms, a CPU API z 10–17 ms do 1,8 ms dla strony produktu. To 70–90%
  kosztu żądania z bazą. Bezpośredni pomiar `pgbench` z `SELECT 1`: 40–46 tys. transakcji/s na utrzymanym połączeniu
  i 430–570 transakcji/s z nowym połączeniem na transakcję (1,6–2,1 ms czasu ściany na połączenie
  z uwierzytelnieniem SCRAM-SHA-256). Do końca testów trybu klasycznego Postgres obsłużył 168 827 sesji na
  2,05 mln transakcji.
- **Worker mode dokłada resztę**: start kernela Symfony (ok. 1 ms CPU, różnica na `/health`) i budowę usług. Na
  lekkich endpointach to jeszcze raz połowa kosztu (strona produktu 2,2 → 1,0–1,2 ms), na cięższych różnica znika
  w zapytaniach (oferty, kategorie, zamówienia: oba warianty dają to samo).
- W obu wariantach z utrzymanym połączeniem każdy wątek trzyma jedno połączenie stale: przy 32 wątkach na
  kontener widziałem 65 otwartych połączeń roli `shop` z dwóch kontenerów, przy `max_connections = 100`
  wspólnym z Keycloakiem. Liczbę wątków trzeba więc ustawić jawnie.
- W worker mode wykonałem ponad 380 tys. żądań (odczyt, koszyk, 23 tys. zamówień) bez odpowiedzi 5xx; pamięć
  kontenera z 32 wątkami doszła do 270 MB (tryb klasyczny: 198 MB). To nie jest dowód poprawności żadnego z dwóch
  wariantów dla tej aplikacji (stan sesji bazy i usług między żądaniami, zamknięty `EntityManager` po błędzie
  bazy, wycieki przy długiej pracy), tylko pomiar potencjału. Walidacja jest częścią rekomendacji R1.

### 3.3 Endpointy na 2 tys. i na 1 mln produktów (tryb klasyczny, 1 użytkownik)

Narzut trybu klasycznego (ok. 5 ms CPU Postgresa i 5–7 ms API) jest w każdym wierszu; kolumna „SQL” to
łączny czas wykonania zapytań z `pg_stat_statements`, „ES” to CPU kontenera Elasticsearch. Serię `small`
mierzyłem na prawie wolnej maszynie, a `medium` przy kilku rdzeniach zajętych przez inne sesje, więc różnice p50
rzędu kilku ms nie są skutkiem danych; miarodajne są kolumny SQL i ES oraz zmiany o rząd wielkości. Cztery wolne
scenariusze panelu zmieściły w 8 s po 3–7 żądań, więc ich p95 to praktycznie najwolniejsze żądanie.

| Endpoint | Zapytań SQL | SQL `small` → `medium` | ES CPU `small` → `medium` | p50 `small` → `medium` | p95 `medium` |
|---|---|---|---|---|---|
| strona produktu | 4 | 0,12 → 0,36 ms | – | 8,7 → 11,3 ms | 18 ms |
| oferty 24 produktów | 5 | 1,2 → 2,1 ms | – | 12,4 → 16,9 ms | 28 ms |
| drzewo kategorii | 2 | 0,5 → 0,6 ms | – | 8,1 → 12,1 ms | 20 ms |
| listing całego katalogu | 2 | 0,5 → 0,5 ms | 6 → 598 ms | 15,7 → **657 ms** | 792 ms |
| listing działu (131 tys. produktów) | 3 | 0,5 → 0,6 ms | 3 → 100 ms | 15,0 → 113 ms | 197 ms |
| listing kategorii-liścia | 3 | 0,5 → 0,6 ms | 3 → 15 ms | 12,5 → 30 ms | 43 ms |
| wyszukiwanie frazy | 2 | 0,5 → 0,5 ms | 5 → 88 ms | 14,5 → 50 ms | 285 ms |
| porcja mapy strony (10 tys. produktów, 884 KB) | 2 | 1,3 → 206 ms | – | 10,5 → 175 ms | 531 ms |
| panel: lista zamówień | 3 | 0,35 → 0,72 ms | – | 11,0 → 11,5 ms | 17 ms |
| panel: zamówienia, fragment tekstu | 3 | 0,35 → 64 ms | – | 9,0 → 59 ms | 123 ms |
| panel: szczegóły zamówienia | 4 | 0,07 → 0,37 ms | – | 8,8 → 14,7 ms | 29 ms |
| panel: lista klientów, fragment | 3 | 0,5 → 5,4 ms | – | 7,3 → 18 ms | 28 ms |
| panel: pulpit | 18 | 0,3 → 2,7 ms | 3 → 3 ms | 14,8 → 28,5 ms | 50 ms |
| panel: lista produktów | 4 | 0,3 → 1,0 ms | – | 8,9 → 13,5 ms | 23 ms |
| panel: produkty po nazwie | 4 | 0,3 → **1 723 ms** | – | 8,2 → 138 ms | **5 565 ms** |
| panel: ceny po nazwie | 5 | 0,5 → **2 678 ms** | – | 8,3 → **2 946 ms** | 4 875 ms |
| panel: stany po nazwie | 5 | 0,6 → **4 664 ms** | – | 9,5 → 467 ms | **17 900 ms** |
| panel: produkty w kategorii | 5 | 0,75 → **1 175 ms** | – | 9,0 → **1 092 ms** | 2 741 ms |

Wniosek [POMIAR]: czas zapytań sklepu do Postgresa rośnie między 2 tys. a 1 mln produktów nieznacznie (strona
produktu 0,12 → 0,36 ms, oferty 1,2 → 2,1 ms) i pozostaje ułamkiem kosztu żądania. Z wolumenem wyraźnie rosną
trzy rzeczy: koszt Elasticsearch dla listingów, cztery wyszukiwania panelu i porcja mapy strony.

### 3.4 Elasticsearch: co kosztuje w zapytaniu listingu

Treść zapytania, które API wysyła dla `GET /api/v1/products`, uruchomiona prosto w Elasticsearch w wariantach
(mediana `took` z 5–7 powtórzeń, ciepły cache, 1 mln produktów):

| Wariant | Cały katalog (980 tys. trafień) | Dział (132 tys.) | Fraza „sportowe” (76 tys.) | Fraza „buty biegowe” (5 tys.) |
|---|---|---|---|---|
| A. zapytanie tak, jak wysyła je API | **577 ms** | 101 ms | 89 ms | 31 ms |
| B. bez agregacji, dokładna liczba trafień | **3 ms** | 4 ms | 17 ms | 12 ms |
| C. bez agregacji, `track_total_hits: 10000` | 3 ms | 6 ms | 25 ms | 14 ms |
| D. z agregacjami, `track_total_hits: 10000` | 576 ms | 95 ms | 89 ms | 28 ms |
| F. tylko facety opcji (`free`: nested → nested → `reverse_nested`) | 351 ms | 59 ms | 63 ms | 30 ms |
| I. tylko zakres cen | 111 ms | 21 ms | 18 ms | 10 ms |
| H. tylko „dostępne” | 80 ms | 15 ms | 18 ms | 10 ms |
| G. tylko kategorie | 58 ms | 13 ms | 34 ms | 20 ms |

Co z tego wynika [POMIAR]:

- Pobranie i posortowanie strony wyników to 3–17 ms. Reszta to agregacje facetów
  (`apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php:117-184`): 99% czasu dla całego katalogu,
  96% dla działu, 81% dla częstej frazy i 61% dla frazy z 5 tys. trafień.
- Dokładne liczenie trafień (`track_total_hits: true`) nie ma mierzalnego kosztu. Analiza statyczna wskazywała je
  jako podejrzane; pomiar tej hipotezy nie potwierdził, więc nie rekomenduję zmiany.
- Koszt rośnie z liczbą pasujących produktów: 577 ms na 980 tys., 101 ms na 132 tys., 89 ms na 76 tys., 31 ms na
  5 tys. To zależność bliższa „stałe kilkanaście–kilkadziesiąt ms plus ok. 0,6 ms na 1000 produktów” niż czysta
  proporcja, wyznaczona z czterech punktów na jednym indeksie. Jeśli utrzyma się do 10 mln produktów, listing
  całego katalogu zajmie ok. 5–6 s, a dział z milionem produktów ok. 0,6–1 s [SZACUNEK]. Klient ma limit 2 s
  (`apps/api/config/services.yaml:26`), po którym API odpowiada 503.
- Facety dla zapytania bez filtrów wariantów są takie same dla każdego odwiedzającego, a Varnish trzyma je tylko
  60 s i razem z listą produktów. Każda kombinacja strony, sortu i filtra liczy je od nowa.

### 3.5 PostgreSQL: plany zapytań na 1 mln produktów i 1 mln zamówień

Wybrane wyniki `EXPLAIN (ANALYZE, BUFFERS)`; komplet w [`results/explain-medium.txt`](performance-audit-2026-11-18/results/explain-medium.txt).

| Zapytanie | Czas | Plan | Ocena |
|---|---|---|---|
| panel: produkty w małej kategorii-liściu (4 produkty), strona | **12 424 ms** (zimny cache), ok. 1 s (ciepły) | `Index Scan catalog_product_name_id_idx`, `Rows Removed by Filter: 1 000 187`, 827 tys. odczytanych bloków | zły |
| ten sam filtr, licznik `LIMIT 10001` | 484 ms | skan z filtrem `OR EXISTS` | zły |
| panel: nazwa `ILIKE '%sportowe%'` (8 603 trafienia) | **4 306 ms**, w powtórce 2 564 ms | `Index Scan catalog_product_name_id_idx`, odrzucone 815 146 wierszy | zły |
| nazwa `ILIKE '%butów%'` (4 433 trafienia) | 1 552 ms, w powtórce 2 946 ms | jw., odrzucone 556 516 | zły |
| nazwa `ILIKE '%buty%'` (107 742 trafienia) | 143 ms, w powtórce 257 ms | jw.; trafień jest dużo, więc 51. znajduje się szybko | akceptowalny |
| nazwa `ILIKE '%dorakos%'` (2 501 trafień) | 54 ms, w powtórce 7 ms | `Bitmap Index Scan catalog_product_name_trgm_idx` + sort top-N | dobry |
| panel: zamówienia, fragment „kowal”, sort po kwocie | 650 ms | `Index Scan Backward ordering_order_total_gross_id_idx` z filtrem | słaby |
| zamówienia, fragment „kowal”, licznik | 253 ms | bitmapa GIN | akceptowalny |
| granice porcji mapy strony (`row_number()` po katalogu) | 702 ms | `WindowAgg` po całym kluczu głównym | rośnie liniowo |
| `initialize.sh`: `count(*)` czterech największych tabel | 1 133 ms | cztery pełne skany indeksów | rośnie liniowo |
| zamówienia: status + doba + sort po kwocie | 7,7 ms | indeks statusu i daty | dobry |
| licznik statusu `LIMIT 10001` | 14,8 ms | index-only | dobry |
| aktywne użycia kodu rabatowego (12,5 tys. użyć) | 0,8 ms | index-only | dobry, liniowy z liczbą użyć |
| lista kodów z licznikami użyć | 10,4 ms | podzapytanie na wiersz | dobry przy tej liczbie użyć |
| liczniki drzewa kategorii | 20 ms | 180 × index-only | dobry |
| produkty bez stanu, strona | 0,45 ms | indeks częściowy | dobry |

Przyczyny dwóch złych planów [POMIAR]:

- **Wyszukiwanie po nazwie** (`DbalBackofficeProductSource.php:21-29`, wspólne dla list produktów, cen i stanów):
  dla fraz o średniej częstości planista szacuje 10 114 wierszy i wybiera przejście indeksu `(name, id)`
  z filtrem zamiast indeksu trigramowego; dla frazy rzadkiej szacuje 100 wierszy i wybiera trigramy. Czas zależy
  więc od frazy: od tego, jak daleko w porządku alfabetycznym leży 51. trafienie. W pomiarze przez API (20 fraz
  z nazw produktów, po kilka żądań na scenariusz) mediana wyniosła 138 ms dla listy produktów, 467 ms dla stanów
  i 2,9 s dla cen, a średni czas tego zapytania 1,6–4,6 s. Plany są w [`results/explain-ilike-medium.txt`](performance-audit-2026-11-18/results/explain-ilike-medium.txt). Testy
  planów w repo wyłączają skan sekwencyjny i sprawdzają, że indeks jest użyty, ale ten przypadek używa indeksu,
  tylko niewłaściwego.
- **Filtr kategorii** (`DbalBackofficeProductSource.php:35-43`): warunek `primary_category_id IN (…) OR EXISTS (…)`
  nie jest warunkiem żadnego indeksu, a planista szacuje 750 944 pasujące wiersze dla kategorii z czterema
  produktami, więc idzie po indeksie nazwy przez cały katalog.

Przy 10 mln produktów najgorsze przypadki obu zapytań będą trwały dziesiątki sekund [SZACUNEK]. Nic ich nie
przerwie, bo `statement_timeout` nie jest ustawiony [KOD].

Statystyki po testach [POMIAR]: `inventory_stock_item` 60 757 aktualizacji, 0% HOT (kolumny `physical` i `reserved`
są w predykatach indeksów częściowych); `ordering_order` 16 339 aktualizacji (wygaśnięcia), 0% HOT;
`ordering_customer` 0% HOT; `cart_cart` 95%; `ordering_sales_hour` 97%. Trafienia w bufor 91% przy 128 MB
`shared_buffers`. `ordering_order` ma 12 indeksów o łącznym rozmiarze ok. 750 MB przy 416 MB danych; największy
to trigramowy (134 MB). Zero zakleszczeń w całym audycie.

### 3.6 Ścieżka zapisu: koszyk gościa i zamówienie

Jedna iteracja to 4 żądania prosto do API: nowy koszyk, dodanie pozycji, odczyt koszyka, `POST /api/v1/orders`
(bez płatności). Łącznie ok. 75 instrukcji SQL na iterację. To przepustowość samego API i bazy: bez sklepu (BFF),
bez płatności i bez tego, co zamówienie uruchamia w tle. Ile zamówień system obsłuży do końca, ogranicza dziś
worker (3.7), nie ta tabela.

| Scenariusz | Tryb | Zamówień/s | Złożenie zamówienia: p50 / p95 / p99 |
|---|---|---|---|
| 1 użytkownik | klasyczny | 14 | 28 / 39 / 56 ms |
| | worker | 45 | 8,7 / 14 / 19 ms |
| 8 użytkowników | klasyczny | 98 | 27 / 42 / 56 ms |
| | worker | 298 | 8,2 / 18 / 62 ms |
| 32 użytkowników | klasyczny | ok. 120 | 81 / 171 / 250 ms |
| | worker | 544 | 24 / 63 / 89 ms |
| 32 użytkowników, **ten sam wariant w każdym zamówieniu** | klasyczny | 100 | 169 / 712 / 1 113 ms |
| | worker | 170 | 100 / 544 / 1 489 ms |

Co z tego wynika [POMIAR]:

- Samo złożenie zamówienia jest tanie: 8–9 ms w worker mode.
- Zamówienia z jednym wariantem szeregują się na blokadzie wiersza stanu (`FOR UPDATE` od rezerwacji do `COMMIT`,
  `PlaceOrderHandler.php:134-142`): średni czas SQL na żądanie rośnie z 0,3 do 41 ms, przepustowość spada
  z 544 do 170 zamówień/s, a p99 przekracza sekundę. Brak `lock_timeout`, więc wątki czekają bez limitu. Dla
  zwykłej sprzedaży to nie jest problem; dla wyprzedaży jednego produktu to twardy sufit.
- Kod rabatowy z limitem działa podobnie, tylko dłużej (blokada wiersza kodu przez prawie całą transakcję plus
  `count(*)` aktywnych użyć pod blokadą, `RedeemDiscountCodeHandler.php:43-51`) [KOD]. Pod obciążeniem tego nie
  zmierzyłem; sam licznik to 0,8 ms przy 12,5 tys. użyć i rośnie liniowo [POMIAR].

### 3.7 Przetwarzanie asynchroniczne

Worker stosu z obrazu produkcyjnego, jeden proces `messenger:consume outbox events scheduled payment_simulator
promotions jobs` (jak w compose i chartcie).

| Pomiar | Wynik |
|---|---|
| Przekaźnik outboxa do RabbitMQ | 22 622 zdarzenia w 93 s, czyli **ok. 240/s** (z próbkowaniem kolejki co sekundę) |
| Kolejka `events` w czasie opróżniania outboxa | urosła z 8 537 do ok. 31 tys.; **nic nie zostało z niej pobrane** |
| Zdarzenia zamówień po opróżnieniu outboxa (e-mail potwierdzenia) | 351 w 20 s, czyli **ok. 17/s**; 2,1 ms CPU workera i 0,45 ms SQL na zdarzenie |
| 2000 zmian cen przez API panelu | 231 zmian/s przy 8 użytkownikach, p50 26 ms, 22 instrukcje SQL na zmianę |
| Zdarzenia zmiany ceny (reindeksacja dokumentu + PURGE Varnisha + rewalidacja sklepu) | **ok. 100/s** (96–105/s w dwóch oknach tego samego przebiegu) |
| Wygaśnięcia nieopłaconych zamówień (31 061 terminów w oknie 3 min 45 s, 35 minut po teście zamówień) | **ok. 17/s** (1 805 w 104 s); po 2,5 minuty zaległość 10 464 |

Co z tego wynika:

- **Kolejki blokują się nawzajem** [POMIAR]: dopóki outbox ma wiersze, worker nie pobiera niczego z `events`,
  a `scheduled`, `payment_simulator`, `promotions` i `jobs` czekają za nimi [KOD: semantyka kolejności transportów
  w jednym `messenger:consume`].
- **Worker nie jest ograniczony przez CPU.** Zdarzenie zamówienia zajmuje 57 ms czasu ściany przy 2,1 ms CPU:
  ok. 96% czasu to oczekiwanie na I/O. Zdarzenie zmiany ceny, które robi więcej pracy w bazie i dwa żądania HTTP,
  zajmuje ok. 10 ms, więc różnica to najpewniej synchroniczna wysyłka SMTP (tu do lokalnego Mailpita)
  [HIPOTEZA oparta na dwóch pomiarach; czasu samej wysyłki nie mierzyłem]. Z prawdziwym dostawcą poczty będzie
  wolniej.
- **Skutek dla sprzedaży** [POMIAR tempa, SZACUNEK czasu końca]: 31 061 zamówień z testu (4 minuty) to ok. 30 minut
  zaległych potwierdzeń e-mail. Tak samo wygaśnięcia: każde generuje zdarzenia, które ten sam proces musi
  przekazać i obsłużyć, więc przy 17/s ostatnie z 31 tys. nieopłaconych zamówień zwolni rezerwację ok. pół godziny
  po terminie płatności. Przez ten czas towar wygląda na zarezerwowany.
- **Skutek dla zmian masowych** [SZACUNEK]: przy 100 zdarzeniach/s zmiana 1 mln cen to ok. 2,8 h zaległości,
  a 30 mln (zmiana stawki VAT bez wyjątku z ASYNC-RELIABILITY W4) ok. 3,5 doby. Każda zmiana to osobny PURGE
  i osobne `POST /api/revalidate` z jednym tagiem (`HttpStorefrontCache.php:29-47`), choć endpoint przyjmuje 100.

### 3.8 Sklep

**Koszt renderowania** (pełna ścieżka sklep → Varnish → API w obrazie produkcyjnym, dane `medium`) [POMIAR]:

| Strona | 1 użytkownik: p50 | 16 użytkowników: żądań/s, p50 / p95 | CPU sklepu na żądanie |
|---|---|---|---|
| `/` (listing, Varnish ciepły) | 11,0 ms | 91/s, 165 / 209 ms | 12,9–14,6 ms |
| `/c/<dział>` | 12,4 ms | 91/s, 168 / 221 ms | 13,2–14,8 ms |
| `/c/<liść>` | 10,1 ms | 100/s, 158 / 201 ms | 11,9 ms |
| `/szukaj?q=…` | 13,1 ms | 87/s, 170 / 273 ms | 13,7–15,1 ms |
| `/p/<slug>`, trafienie ISR | 1,15 ms | 1 036/s, 14 / 25 ms | 0,95–1,2 ms |
| `/p/<slug>`, chybienie ISR | 38,9 ms | – | 12,3 ms + API 7,7 ms + Postgres 7,7 ms |
| BFF `/api/site`, `/auth/session`, `/api/cart/summary` (gość) | 0,4 ms | – | 0,4–0,5 ms |

Przy 16 równoległych użytkownikach sklep oddaje 87–100 listingów na sekundę niezależnie od rodzaju strony,
zużywając ok. 1,2 rdzenia z 16 dostępnych, a p50 rośnie do 160–170 ms: żądania czekają w sklepie, nie w API.
Trafienie ISR jest 12 razy tańsze niż render.

**Rozmiary** [POMIAR]:

| | Wartość |
|---|---|
| HTML strony głównej / kategorii / produktu / koszyka (statyczna) | 320 / 311 / 149 / 115 KB; gzip 45 / 44 / 25 / 19 KB |
| Odnośniki do kategorii w HTML jednej strony | 389 (mega menu w dwóch wersjach i stopka, drzewo 180 kategorii) |
| Wpis ISR strony produktu na dysku | **262 KB** (HTML 142 KB, RSC 52 KB, segmenty 60 KB); 302 strony = 79 MB |
| Wpis ISR strony 404 | 140 KB; 200 losowych adresów `/p/…` = 200 nowych wpisów |
| JavaScript sklepu | 20 plików, 995 KB przed kompresją; na pierwsze wejście 174–188 KB przesłane w 11–12 plikach |
| Panel | jeden plik JS 970 KB (294 KB gzip), CSS 74 KB (15 KB gzip) |

Strona koszyka, która nie pokazuje żadnego produktu, waży 115 KB: tyle kosztuje układ z drzewem kategorii
osadzonym w HTML i w danych RSC (`MegaMenu` jest komponentem klienckim i dostaje całe drzewo,
`apps/store/src/catalog/SiteHeader.tsx:39`). Ten koszt jest w każdej stronie, w każdym wpisie ISR i w CPU każdego
renderu, i rośnie z liczbą kategorii.

Wpis ISR ma 262 KB, a nie ok. 88 KB, jak przyjmuje specyfikacja ABUSE-LIMITS (L9,
`docs/features/abuse-limits/spec.md:352`); różnica bierze się zapewne z drzewa 180 kategorii zamiast 44 z danych
seeda [HIPOTEZA].
Milion odwiedzonych stron produktu to ok. 260 GB na replikę, 10 mln to ok. 2,6 TB [SZACUNEK]. Dziś nic tego nie
ogranicza [KOD]; ograniczenie jest zaplanowane w dwóch przygotowanych epikach: ABUSE-LIMITS D12 (cache ISR
w pamięci poda, 128 MB z wypieraniem, bez zapisu na dysk) i SMALL-FIXES D4 (limit `ephemeral-storage`). Przy
wpisach tej wielkości 128 MB mieści kilkaset stron produktu, więc po tej zmianie strona produktu spoza gorącego
zbioru będzie renderowana przy każdym wejściu (39 ms i trzy odczyty API zamiast 1,2 ms). To trzeba uwzględnić przy
wyborze wartości D12.

**Lighthouse** (`make lighthouse`, profil mobilny z symulowanym łączem, mediany z 3 przebiegów) [POMIAR]:

| Strona | Wydajność | LCP | CLS | TBT | SEO |
|---|---|---|---|---|---|
| `/` | 94 | 3 156 ms | 0,001 | 42 ms | 100 |
| `/c/turystyka/biwak/namioty` | 93 | 3 262 ms | 0,001 | 51 ms | 100 |
| `/p/namiot-turystyczny-norvane-biwak-2` | 94 | 3 124 ms | 0,001 | 41 ms | 100 |

LCP jest powyżej progu „dobre” 2,5 s na wszystkich trzech stronach (w repo to tylko ostrzeżenie,
`e2e/lighthouse/lighthouserc.cjs:24-28`). Elementem LCP jest zdjęcie; czas serwera to 4–30 ms, więc wynik bierze
się z wagi strony na symulowanym wolnym łączu (33–48 żądań, w tym 14–16 `fetch`: prefetch stron produktów
i trzy żądania BFF). CLS i TBT są bardzo dobre. Pomiar jest bez paska demo (`demoNotice`), który w produkcji
przesuwa stronę po hydratacji [KOD]; poprawka jest już w SMALL-FIXES (B8).

### 3.9 Przebieg do nasycenia (pełna ścieżka, 1 mln produktów)

`load/saturate.js`: miks odczytu i koszyka przez sklep, tempo rośnie o 10 iteracji/s co 20 s, API w trybie
klasycznym z obrazu produkcyjnego. Dwa przebiegi [POMIAR]:

| Cel iteracji/s | Przebieg 1: p50 / p95 | Przebieg 2: p50 / p95 |
|---|---|---|
| 50 | 14 / 66 ms | 14 / 72 ms |
| 80 | 16 / 70 ms | 10 / 46 ms |
| 90 | 19 / 86 ms | 10 / 48 ms |
| 100 | **97 / 1 220 ms** | 11 / 55 ms |
| 110 | 1 307 / 2 009 ms (przerwany) | 12 / 51 ms |
| 120 | – | 16 / 107 ms |
| 130 | – | 18 / 90 ms |
| 140 | – | **36 / 395 ms** |
| 150 | – | 713 / 1 181 ms |
| 160 | – | 1 369 / 1 832 ms |

| | Przebieg 1 | Przebieg 2 |
|---|---|---|
| Ostatni schodek z p95 poniżej 0,5 s | 90 iteracji/s (108 żądań/s) | 140 iteracji/s (168 żądań/s) |
| Obce obciążenie w czasie przebiegu (suma CPU cudzych kontenerów) | 200–400%, skok do 640% w schodku załamania | 0–190% |
| Cache ISR stron produktu | zimny na starcie | rozgrzany przebiegiem 1 |
| CPU sklepu przy załamaniu | ok. 100% | 120–150% |
| CPU API / Postgres / Elasticsearch przy załamaniu | 33% / 21% / 17–33% | 45–95% / 35–45% / 40–60% |

Co z tego wynika:

- Przepustowość pełnej ścieżki na tej maszynie to 100–140 iteracji/s, zależnie od tego, co jeszcze na niej działa
  i czy cache jest ciepły. Pierwszy przebieg załamał się dokładnie w chwili skoku obcego obciążenia, więc jego
  wynik jest zaniżony. [POMIAR]
- W obu przebiegach sklep jest kontenerem o największym zużyciu CPU i załamanie przychodzi, gdy zużywa 1,0–1,5
  rdzenia przy 16 dostępnych; przejście od „dobrze” do „źle” mieści się w jednym–dwóch schodkach. Pomiar na k3d
  z 2026-11-10 (`docs/reports/load-test-2026-11-10.md`, 191 produktów, zasoby `prod`, 277,6 iteracji/s) pokazał
  to samo: sklep staje przy ok. 1,6 rdzenia, gdy reszta ma zapas. Trzecia obserwacja to test zamknięty z 3.8:
  16 równoległych użytkowników na samych listingach daje 87–100 żądań/s niezależnie od rodzaju strony. [POMIAR]
- Że granicą jest jeden proces Node.js (główny wątek renderujący plus wątki pomocnicze), to wniosek z tych trzech
  obserwacji i z faktu, że sklep jest jedyną usługą na ścieżce, która nie rozkłada pracy na wiele wątków. Nie
  profilowałem pętli zdarzeń. [HIPOTEZA dobrze poparta]
- Drugim konsumentem CPU jest API w trybie klasycznym. Po usunięciu sufitu sklepu ono będzie następne.
- Varnish miał w pierwszym przebiegu 82% trafień (18,0 tys. z 22,1 tys. żądań cache'owalnych), ale skrypt losuje
  z 720 produktów i fraz z ich nazw, czyli z gorącego zbioru; dla długiego ogona będzie gorzej. [HIPOTEZA]

### 3.10 Zasoby

| | Wartość [POMIAR] |
|---|---|
| Baza przy `medium` | 8,4 GB; największe: `catalog_product` 1,6 GB, `ordering_order` 1,2 GB, `inventory_stock_movement` 1,1 GB |
| Baza przy `server` (10 mln produktów, 5 mln zamówień) | ok. 65–70 GB [SZACUNEK liniowy, zgodny z ok. 62 GB ze specyfikacji DEMO-DATA] |
| Baza przy `full` (50 mln zamówień) | ok. 200 GB [SZACUNEK] |
| Indeks wyszukiwarki | 1,1 GB na 1 mln produktów → ok. 11 GB i ok. 90 mln dokumentów Lucene na 10 mln [SZACUNEK] |
| Pełna reindeksacja | 341 s na 1 mln → ok. 1 h na 10 mln, jednowątkowo [SZACUNEK] |
| WAL w czasie generowania i testów | 36 GB |
| Pamięć API | 198 MB (klasyczny, 32 wątki), 270 MB (worker, 32 wątki) |
| Pamięć workera / Varnisha / Redisa / Keycloaka | 48 MB / 100 MB / 17 MB / 584 MB |
| Varnish: rozmiar magazynu | 104 857 600 bajtów (domyślne 100 MB obrazu; `varnishstat SMA.s0.g_space`) |

## 4. Wąskie gardła i ich przyczyny

Kolejność według tego, jak wcześnie ograniczą system.

| # | Rodzaj | Wąskie gardło | Przyczyna | Dowód |
|---|---|---|---|---|
| W1 | wydajność | Sklep: ok. 100 listingów/s na całe wdrożenie (na tej maszynie) | listingi bez cache HTML (trasy dynamiczne przez `searchParams`, dane z `cache: "no-store"`, `apps/store/src/catalog/load-catalog.ts:41,79`), jeden proces serwera, `replicas: 1` na sztywno (`infra/k8s/charts/shop/templates/_helpers.tpl:244`) | 3.8, 3.9 |
| W2 | wydajność | API: ok. 450–500 żądań/s z bazą (na tej maszynie, przy kilku rdzeniach zajętych przez inne sesje) | połączenie z Postgresem na każde żądanie (`apps/api/config/packages/doctrine.yaml:2-9`), tryb klasyczny FrankenPHP (`apps/api/docker/frankenphp/Caddyfile:10`), brak poolera | 3.2 |
| W3 | wydajność; z danymi dostępność (503 po 2 s) | Listing i wyszukiwanie: czas liniowy z katalogiem | pełne agregacje facetów przy każdym chybieniu cache, jeden shard (`ProductSearchQuery.php:117-184`, `services.yaml:11-12`) | 3.4 |
| W4 | **poprawność biznesowa** i wydajność | Zdarzenia: 17–100/s, kolejki blokujące się nawzajem | jeden proces na sześć transportów, synchroniczny e-mail, zdarzenie na wariant bez łączenia (`infra/docker-compose.yml:350`, `messenger.yaml`) | 3.7 |
| W5 | wydajność panelu | Wyszukiwanie w panelu: do kilku sekund przy 1 mln, zależnie od frazy i kategorii | zły wybór planu dla `ILIKE` i dla filtra `OR EXISTS` | 3.5 |
| W6 | dostępność przy wyprzedaży | Wyprzedaż jednego wariantu: ok. 170 zamówień/s, p99 > 1 s | blokada wiersza stanu do końca transakcji, brak `lock_timeout` | 3.6 |
| W7 | dostępność (dysk); po skalowaniu poprawność | Cache ISR: 262 KB na stronę, dziś bez limitu, lokalny dla repliki | brak `cacheHandler`, wpisy 404 też zapisywane; limit zaplanowany (ABUSE-LIMITS D12, SMALL-FIXES D4), współdzielenie nie | 3.8 |
| W8 | wydajność i dostępność | Postgres na ustawieniach domyślnych | brak konfiguracji w compose i chartcie (`templates/postgres.yaml`) | 3.5, 3.10 |

W1 i W2 ograniczają system już dziś, przy małym katalogu. W3, W5 i W7 pojawiają się z danymi. W4 i W6 pojawiają
się ze sprzedażą. Liczby w tabeli to pomiary z tej maszyny; przyczyny W1–W3 i W5 potwierdza pomiar wariantu
(trwałe połączenie, rozbicie zapytania, `EXPLAIN`), przyczyny W4 i W6 wynikają z kodu i z zachowania kolejek
i blokad w pomiarze.

### 4.1 Co jest problemem poprawności, a co tylko wydajności

Większość znalezisk spowalnia system, ale nie zmienia jego wyniku. Kilka zmienia to, co widzi klient albo
sprzedawca. Te mają pierwszeństwo niezależnie od tego, ile dają przepustowości, i są w raporcie oznaczone osobno.

**Poprawność biznesowa** (wynik jest zły albo spóźniony, nawet gdy system „działa szybko”):

| ID | Problem | Skutek biznesowy | Kiedy występuje | Dowód | Stan w planach |
|---|---|---|---|---|---|
| B1 | **Opóźnione zwalnianie rezerwacji nieopłaconych zamówień.** Wygaśnięcie to wiadomość na transporcie `scheduled`, piątym w kolejności jednego workera | Towar zostaje zarezerwowany po terminie płatności, więc przy małym stanie nie da się go kupić. Opóźnienie nie ma górnej granicy | (a) gdy nieopłaconych zamówień przybywa szybciej, niż worker je wygasza: zmierzone ok. 17/s, zaległość 10 464 po 2,5 minuty; (b) gdy worker ma zaległość w outboxie albo w `events`, np. po masowej zmianie cen (1 mln zmian to ok. 2,8 h): `scheduled` czeka wtedy cały ten czas | (a) [POMIAR] 3.7; (b) [KOD] kolejność transportów w jednym `messenger:consume`, zmierzona dla pary outbox → `events`, nie dla `events` → `scheduled` | ASYNC-RELIABILITY: W1 (osobny worker komend) i W5 (komenda wygaszająca zamówienia po terminie z karencją), wyspecyfikowane, niezaimplementowane |
| B2 | **Opóźnione e-maile zamówień** (potwierdzenie, wpłata) | Klient nie dostaje potwierdzenia przez minuty albo godziny | powyżej ok. 17 zamówień/s albo gdy przed e-mailem stoi reindeksacja po zmianach cen (ta sama kolejka `events`) | [POMIAR] 3.7 | W1 nie rozdziela e-maili od reindeksacji; patrz R6b |
| B3 | **Stare ceny i dostępność w sklepie w czasie zaległości.** Listing, wyszukiwarka i strona produktu odświeżają się dopiero po obsłużeniu zdarzenia | Klient widzi cenę sprzed zmiany; zamówienie po starej cenie nie przejdzie, bo API porównuje kwotę z koszyka (`PlaceOrderHandler.php:124`), więc skutkiem jest rozjazd i odrzucone zamówienia, nie sprzedaż po złej cenie | przy każdej zaległości `events`; 100 zmian cen/s to górna granica nadążania | [KOD] mechanizm, [POMIAR] tempo 3.7 | R6b |
| B4 | **Indeks po pełnej przebudowie nie zawiera zmian zapisanych w jej trakcie** (S4) | Produkt w wyszukiwarce ma dane sprzed przebudowy do następnej zmiany | każda przebudowa pod ruchem: 341 s przy 1 mln produktów, ok. 1 h przy 10 mln [SZACUNEK] | [KOD] `ProductSearchIndexRebuilder.php:19-20` | brak |
| B5 | **Rewalidacja trafia do jednej repliki sklepu** (F2) | Pozostałe repliki pokazują starą cenę do godziny | od drugiej repliki sklepu; dziś nie występuje | [KOD] | kryterium SCALE-PROOF w audycie aplikacji |
| B6 | **Lista porcji mapy strony nieaktualna po masowym imporcie** (A5) | Mapa strony pomija produkty do godziny na każdym podzie | po imporcie albo generowaniu danych | [POMIAR]: 1 porcja zamiast 101 do wyczyszczenia cache | brak |
| B7 | **Generator danych nie kończy profilu `medium`** | Zbiór bez kont w Keycloaku; pomiar skali wymaga ręcznych kroków | zawsze dla profili większych niż `small` | [POMIAR] 3.1 | plan DEMO-LOAD (#486) |

**Dostępność i odporność** (system przestaje odpowiadać albo traci stan):

| ID | Problem | Dowód |
|---|---|---|
| O1 | Brak limitów czasu: zapytanie panelu trwa 1–12 s do końca, wątek czeka na blokadę wariantu bez limitu, sklep czeka na API bez limitu (P2, W6, F4) | [POMIAR] 3.5, 3.6; [KOD] |
| O2 | Redis bez `maxmemory`: przekroczenie limitu poda to restart i wylogowanie wszystkich klientów (C1) | [KOD]; pamięć na sesję niezmierzona |
| O3 | Listing powyżej limitu 2 s kończy się odpowiedzią 503: według ekstrapolacji przy ok. 3–4 mln pasujących produktów (W3) | [SZACUNEK] z 3.4 |
| O4 | Cache ISR zapełnia dysk węzła, wspólny z bazą (W7) | [POMIAR] 262 KB na wpis; zaplanowane ograniczenie |
| O5 | Liczba połączeń Postgresa: 100 domyślnie, wspólne dla API, workera, zadań i Keycloaka (A2) | [POMIAR] ustawienia; skutek [HIPOTEZA] |

**Tylko wydajność** (wynik poprawny, kosztuje więcej czasu albo sprzętu): W1, W2, W3 do progu 2 s, W5, koszt
połączeń, rozmiar stron i bundli, plany zapytań panelu, indeksy zdublowane i nie-HOT.

Pomiar gorącego wariantu (W6) sprawdzał czas i przepustowość. Tego, czy przy równoległych zamówieniach stan
magazynowy pozostaje poprawny, ten audyt nie badał; to zakres audytu poprawności, nie wydajności.

## 5. Znaleziska szczegółowe

Waga dotyczy skali docelowej; w nawiasie skutek na obecnym demo (worf.pl: jeden VPS, 8 vCore, 24 GB).
Komplet (ok. 90 znalezisk z analizy statycznej, z cytatami kodu) jest w [`performance-audit-2026-11-18/findings/`](performance-audit-2026-11-18/findings/).
Raporty cząstkowe powstały przed pomiarami: gdzie pomiar nie potwierdził ich hipotezy (np. koszt dokładnego
liczenia trafień), obowiązuje ten raport.

### 5.1 PHP, Symfony, FrankenPHP

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| A1 | Critical (Medium) | Połączenie z bazą i kernel na każde żądanie: 12–22 ms CPU na żądanie z bazą wobec 1–5 ms z utrzymanym połączeniem | [POMIAR] 3.2 |
| A2 | High (Low) | Liczba wątków domyślna, 2 × CPU widziane przez proces (32 na tej maszynie, 16 na VPS), niezależna od przydziału CPU poda i od `max_connections` Postgresa (100, wspólne z Keycloakiem) | [POMIAR] `total_threads: 32`; [KOD] |
| A3 | Medium | `opcache.preload` wyłączony, `interned_strings_buffer` 8 MB, JIT wyłączony (`php -i` w obrazie `prod`). Po przejściu na worker mode znaczenie maleje, bo kod zostaje w pamięci | [POMIAR] ustawień, [HIPOTEZA] zysku |
| A4 | Medium | Wywołania HTTP w transakcji bazy: start płatności (do ok. 10 s z blokadą wiersza, `StartPaymentHandler.php:97,121,128`) i partia reindeksu kategorii (500 zapisów do ES, `ReindexCategoryBatchHandler.php:67-70`) | [KOD] |
| A5 | Low | `cache.app` (JWKS, granice mapy strony) jest plikowy i osobny dla każdego poda; po masowym imporcie lista porcji mapy strony była nieaktualna do wyczyszczenia cache (1 porcja zamiast 101) | [POMIAR] |

### 5.2 Doctrine ORM i DBAL

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| D1 | – | Odczyty sklepu i panelu to zbiorcze zapytania DBAL, bez N+1; 2–5 zapytań na żądanie, 18 na pulpit | [POMIAR] 3.3 |
| D2 | Medium (Low) | Każdy listing hydratuje przez ORM całe drzewo kategorii, żeby wziąć dzieci jednej (`ListProductsHandler.php:79`, `DoctrineCategoryRepository.php:74-86`); 0,5 ms przy 180 kategoriach | [POMIAR] czasu, [KOD] |
| D3 | Low | Widok koszyka czyta ceny i promocje dwa razy; złożenie zamówienia odświeża koszyk, który transakcja już trzyma | [KOD] |
| D4 | High (Low) | Reindeks poddrzewa kategorii idzie produkt po produkcie (ok. 5 zapytań i 1 zapis do ES na produkt), nie ścieżką `_bulk` pełnej przebudowy | [KOD] |
| D5 | – | Unit of Work jest mały (jeden agregat na komendę), brak `flush` w pętli na ścieżkach HTTP | [KOD] |

### 5.3 PostgreSQL

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| P1 | Critical (High) | Konfiguracja domyślna: `shared_buffers` 128 MB, `work_mem` 4 MB, `random_page_cost` 4, autovacuum domyślny, brak `pg_stat_statements`; limit poda 2 GiB przy bazie ok. 65 GB dla 10 mln produktów | [POMIAR] `pg_settings`; [KOD] chart |
| P2 | High (Medium) | Brak `statement_timeout`, `lock_timeout`, `idle_in_transaction_session_timeout`: zapytania z 3.5 trwają do końca, wątki czekają na blokady bez limitu | [POMIAR] `pg_settings` |
| P3 | High (High) | Wyszukiwanie po nazwie w panelu: od 7 ms do 4,3 s na 1 mln produktów, zależnie od frazy | [POMIAR] 3.5 |
| P4 | High (High) | Filtr kategorii listy produktów w panelu: 1–12 s na 1 mln | [POMIAR] 3.5 |
| P5 | Medium | Aktualizacje stanów nigdy nie są HOT (0% z 61 tys.), podobnie `ordering_order` i `ordering_customer`; przy domyślnym autovacuum to rozdęcie indeksów pod obciążeniem zakupowym | [POMIAR] `pg_stat_user_tables`; skutek [HIPOTEZA] |
| P6 | Medium | `ordering_order`: 12 indeksów, ok. 750 MB na 416 MB danych; każda zmiana statusu dotyka większości z nich | [POMIAR] rozmiarów |
| P7 | Medium | Sześć indeksów zdublowanych z kluczem głównym albo unikalnym (m.in. `ordering_order_line_order_id_idx`, `ordering_order_status_change_order_id_idx`, `cart_line_cart_id_idx`); lista w `findings/02-postgres.md`, 3.1 | [KOD] |
| P8 | Medium | Granice porcji mapy strony (702 ms) i `count(*)` w `initialize.sh` (1,1 s) rosną liniowo; drugie wykonuje się przy każdym wdrożeniu | [POMIAR] 3.5 |
| P9 | Medium | Tabele rosnące bez sprzątania: ruchy magazynowe, historia statusów, powiadomienia, zdarzenia webhooków, klucze idempotencji | [KOD] |
| P10 | – | Zapytania sklepu, kursory, ograniczone liczniki, kolejność blokad: dobre plany i brak zakleszczeń w testach (0 w `pg_stat_database`) | [POMIAR] |

### 5.4 API i wyszukiwarka

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| S1 | Critical (Low) | Facety liczone przy każdym chybieniu: 61–99% czasu zapytania listingu, rosną z liczbą pasujących produktów | [POMIAR] 3.4 |
| S2 | High (Low) | Jeden shard, zero replik: zapytanie na jednym wątku, brak skalowania odczytu i brak odporności | [KOD] `services.yaml:11-12` |
| S3 | High (Medium) | `api.<domena>` wystawia publiczne odczyty katalogu z pominięciem Varnisha (`templates/ingress.yaml:12`): każdy klient z internetu może wywołać zapytanie z 3.4 bez cache. Znane: ABUSE-LIMITS odsyła to do SCALE-PROOF (`docs/features/abuse-limits/spec.md:45`) | [KOD] |
| S4 | Medium | Pełna przebudowa indeksu nie dogania zmian zapisanych w jej trakcie; przerwana zostawia osierocony indeks | [KOD]; [POMIAR] sierota |
| S5 | Medium | Rozmiary odpowiedzi: listing 39–43 KB, oferty 24 produktów 19 KB, porcja mapy strony 884 KB. Odpowiedź listingu niesie 4 adresy imgproxy na produkt | [POMIAR] |
| S6 | – | `track_total_hits: true` nie kosztuje mierzalnie | [POMIAR] 3.4 |

### 5.5 Redis, cache aplikacyjny i HTTP

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| C1 | High (High) | Redis bez `maxmemory` i bez polityki, z limitem poda 512 Mi; trzyma sesje sklepu (do 30 dni), więc przekroczenie limitu to restart i wylogowanie wszystkich | [KOD] `templates/redis.yaml`; zużycie na sesję [HIPOTEZA] 4–6 KB |
| C2 | High (Medium) | Varnish z domyślnymi 100 MB, bez `grace`; listing ma tylko `max-age=60` | [POMIAR] rozmiaru; [KOD] |
| C3 | High (Low) | Zmiana drzewa kategorii albo koniec reindeksu poddrzewa unieważnia tag `catalog`: cały Varnish i wszystkie strony ISR naraz, z `expire: 0` (render blokujący) | [KOD] `InvalidateStorefrontOnCategoryTreeChange.php:31`, `apps/store/app/api/revalidate/route.ts:57` |
| C4 | Medium | Purge tagu produktu usuwa też każdy listing, na którym produkt był; przy częstych zmianach popularnych produktów pierwsze strony kategorii są często zimne | [KOD] |
| C5 | Medium | Podwójna warstwa dla strony produktu (Varnish i ISR z tymi samymi tagami) przy braku jakiejkolwiek dla HTML listingów | [KOD]; koszt 3.8 |
| C6 | – | Redis jest użyty oszczędnie i poprawnie: sesje, blokada odświeżania tokenu, limity. Cache aplikacyjnego w Redisie nie ma i nie jest potrzebny | [KOD] |

### 5.6 Sklep (Next.js)

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| F1 | Critical (Medium) | Listingi renderowane przy każdym żądaniu, jedna replika | [POMIAR] 3.8, 3.9 |
| F2 | Critical (Medium) | Cache ISR lokalny dla repliki, 262 KB na stronę, także dla 404; `/api/revalidate` trafia do jednej repliki. Limit rozmiaru jest zaplanowany (ABUSE-LIMITS D12), współdzielenie między replikami nie | [POMIAR] rozmiarów; [KOD] |
| F3 | High (Low) | Całe drzewo kategorii w HTML i w danych RSC każdej strony (ok. 115 KB z każdej strony przy 180 kategoriach) | [POMIAR] |
| F4 | High (Medium) | Wywołania API ze sklepu bez limitu czasu (`apps/store/src/api/client.ts:14-20`); przy wolnym Elasticsearch rendery się kumulują | [KOD] |
| F5 | Medium | LCP 3,1–3,3 s w profilu mobilnym; próg w repo tylko ostrzega | [POMIAR] |
| F6 | Medium | Prefetch dwóch odnośników na kafelek i trzy żądania BFF na każde twarde wejście (`/api/site` z `no-store`, choć treść jest stała dla wdrożenia): 14–16 żądań `fetch` na wejście | [POMIAR] Lighthouse |
| F7 | – | Dobrze: HTML niezależny od sesji, deduplikacja odczytów, obrazy z `srcset` i wymiarami (CLS 0,001), Sentry ładowany leniwie, blokada odświeżania tokenu | [KOD], [POMIAR] CLS |

### 5.7 Panel (Vue)

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| V1 | Low | Jeden bundle 294 KB gzip bez podziału tras; przy kilkudziesięciu użytkownikach bez znaczenia | [POMIAR] |
| V2 | Low | Brak anulowania żądań: szybkie pisanie w polu wyszukiwania wysyła zapytania z 3.5, których wynik jest wyrzucany (debounce 300 ms to łagodzi) | [KOD] |
| V3 | – | Dobrze: stronicowanie kursorem po stronie API, `shallowRef`, polling pulpitu z pauzą w ukrytej karcie | [KOD] |

Wydajność panelu to dziś wydajność zapytań z 3.5, nie kodu Vue.

### 5.8 Keycloak, BFF, uwierzytelnianie

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| K1 | – | API weryfikuje tokeny lokalnie (JWKS z cache), bez introspekcji i bez odczytu bazy. Kosztu samej weryfikacji nie wyodrębniłem; górne ograniczenie to ok. 0,5 ms CPU (lista zamówień z JWT 1,4 ms CPU API w worker mode wobec 0,9 ms strony produktu bez tokenu) | [KOD]; [POMIAR] ograniczenia |
| K2 | High (Low) | Każdy aktywny zalogowany klient odświeża token co ok. 270 s; przy 60 tys. sesji to ok. 220 odświeżeń/s, a w Keycloak 26 każde zapisuje do bazy, która jest tą samą instancją Postgresa co sklep | [HIPOTEZA]; dokumentacja Keycloaka podaje 1 vCPU na 120 odświeżeń/s |
| K3 | Medium | Zalogowany klient to na każde twarde wejście 2 odczyty Redisa i 2 wywołania API, których nic nie cache'uje | [KOD] |
| K4 | Medium | Import kont: `partialImport` 2000 kont trwa ponad 5 s | [POMIAR] 3.1 |

### 5.9 Infrastruktura

| ID | Waga | Znalezisko | Dowód |
|---|---|---|---|
| I1 | Critical (Low) | `replicas: 1` wpisane w helperze wszystkich Deploymentów, brak HPA; sklepu nie da się skalować bez wspólnego cache, API i worker prawie tak | [KOD] |
| I2 | High (High) | Postgres, Elasticsearch, Garage, RabbitMQ i Redis na jednym dysku `local-path`; przy `medium` wygenerowało się 36 GB WAL | [KOD]; [POMIAR] WAL |
| I3 | High | Zasoby `prod` (Elasticsearch: sterta 2 GB, Postgres: limit 2 GiB) są dobrane do demo, nie do 10 mln produktów | [KOD] |
| I4 | Medium | Sondy tylko readiness; zawieszony worker jest „gotowy” (częściowo adresuje ASYNC-RELIABILITY W2) | [KOD] |
| I5 | Medium | Pomiar `load` losuje z pierwszych 4800 produktów po nazwie, więc mierzy gorący cache; nie obejmuje zalogowanych, panelu, obrazów ani zapisów katalogu | [KOD] `load/lib/data.js:47-61` |

## 6. Ocena użycia technologii

**Doctrine.** Użyte dojrzale: ORM do zapisu agregatów, DBAL do odczytów, modele odczytu tam, gdzie agregacja na
żądanie byłaby niemożliwa (`ordering_customer`, `ordering_sales_hour`). Nie znalazłem N+1 ani hydratacji dużych
zbiorów na ścieżkach HTTP. Jedyna realna strata to ORM w drzewie kategorii przy listingu (D2) i reindeks
poddrzewa (D4).

**PostgreSQL.** Schemat i zapytania są przygotowane na skalę lepiej niż serwer, na którym działają. Indeksy pod
kursory, liczniki z limitem, szacunek `reltuples` zamiast `count(*)` produktów: to wszystko działa na 1 mln
[POMIAR]. Brakuje konfiguracji serwera, limitów czasu, poolera albo trwałych połączeń i diagnostyki
(`pg_stat_statements` nie ma nawet w compose). Dwa złe plany z 3.5 wynikają z szacunków planisty, nie z braku
indeksów.

**Redis.** Zakres użycia jest właściwy (sesje, blokady, limity). Problem jest operacyjny: brak `maxmemory`
i jedna instancja jako pojedynczy punkt awarii logowania.

**Next.js.** Kod jest dobry, model cache nie nadąża za skalą. ISR stron produktu działa (12 razy tańsze
trafienie), ale tylko na jednej replice i do zapełnienia dysku. Listingi, czyli większość ruchu, nie mają cache
HTML wcale. Next 16 ma do tego narzędzia (`use cache`, `cacheLife`, współdzielony `cacheHandler`), których
projekt nie używa.

**Vue.** Bez zastrzeżeń istotnych dla wydajności.

**FrankenPHP.** Wybór dobry, użyty w trybie, który nie daje jego głównej zalety. Według testu Tideways
(wrzesień 2025) tryb klasyczny jest wydajnościowo równy PHP-FPM; przyspieszenie daje dopiero worker mode
(research, sekcja „Warstwa PHP”). Mój pomiar dodaje do tego jedno: w tej aplikacji większość straty to nie start
kernela, tylko połączenie z bazą, które da się utrzymać także w trybie klasycznym.

## 7. Zbędne albo przedwczesne optymalizacje

| Element | Ocena | Zalecenie |
|---|---|---|
| Varnish i ISR jednocześnie dla strony produktu, z podwójnym unieważnianiem (PURGE i `POST /api/revalidate` na każde zdarzenie) | Dwie warstwy tam, gdzie dane kosztują 4 zapytania i 0,36 ms SQL; zero warstw dla HTML listingów, które kosztują 13 ms CPU na wejście. Złożoność jest w miejscu o mniejszej korzyści | przy przebudowie cache sklepu (R3) zostawić jedną warstwę na stronę produktu |
| `CachingSitemapProductSource` (plikowy cache granic porcji, 1 h, osobny dla poda) | Odpowiedź i tak jest w Varnishu na 1 h; cache dodał nieaktualność po imporcie (A5) | usunąć albo zastąpić unieważnieniem po zdarzeniu |
| 16 kubełków sprzedaży na godzinę (`SalesSql.php:24-26`) | 97% aktualizacji HOT, blokada trwa milisekundy; jeden wiersz na godzinę prawdopodobnie by wystarczył | zostawić; nie rozwijać tego wzorca bez pomiaru |
| RabbitMQ jako przekaźnik jednej kolejki między outboxem w Postgresie a konsumentem w tej samej aplikacji | Dodaje usługę, wolumen i potwierdzany zapis na każde zdarzenie (przekaźnik: ok. 240/s) bez drugiego odbiorcy | decyzja architektoniczna dla właściciela; nie pilne |
| Sześć zdublowanych indeksów (P7) | czysty koszt zapisu | usunąć |
| Dwa indeksy sortu po kwocie i GIN trigramowy na zamówieniach, cztery indeksy sortu i dwa GIN na klientach | uzasadnione listami panelu, ale płaci za nie każde zamówienie | decyzja po odczycie `pg_stat_user_indexes` z prawdziwego użycia |
| Garage dla ok. 4 tys. plików demo | kopie i tak idą do R2 | przy prawdziwym sklepie zastąpić magazynem zarządzanym |

Nie uważam za przedwczesne: `BoundedCounter`, kursorów, `reltuples`, modelu `ordering_customer`, wersjonowania
dokumentów w Elasticsearch (`external_gte`), zdarzenia magazynowego tylko przy zmianie poziomu dostępności.
Wszystkie mają mierzalne uzasadnienie przy 1 mln wierszy.

## 8. Rekomendacje priorytetowe

Każda rekomendacja ma rodzaj (poprawność biznesowa, dostępność, wydajność), dowód w tym raporcie, oczekiwaną
korzyść, przybliżony koszt i poziom pewności. Koszt: S = dni, M = 1–2 tygodnie, L = epik. Pewność dotyczy
oczekiwanej korzyści. Korzyści liczbowe opisują warstwę, której zmiana dotyczy, na maszynie pomiaru; nie są
obietnicą przepustowości całego systemu.

| # | Rodzaj | Rekomendacja | Dowód | Oczekiwana korzyść | Koszt | Pewność |
|---|---|---|---|---|---|---|
| R6a | **poprawność biznesowa** | **Wygaśnięcia zamówień i e-maile niezależne od zaległości zdarzeń.** Wdrożyć to, co jest już wyspecyfikowane w ASYNC-RELIABILITY: osobny worker komend (W1) i komendę wygaszającą zamówienia po terminie (W5). Dodać pomiar tempa wygaśnięć po zmianie i alert na opóźnienie zwolnienia rezerwacji względem terminu płatności | 3.7: 17 wygaśnięć/s, zaległość 10 464 po 2,5 minuty; kolejka `events` nieobsługiwana w czasie opróżniania outboxa; 4.1 B1, B2 | zwolnienie rezerwacji ograniczone karencją z W5 (minuty) zamiast braku granicy; wygaśnięcia nie czekają za reindeksacją ani e-mailami | w zaplanowanym epiku (L); dodatkowy pomiar i alert: S | wysoka co do mechanizmu; tempo po zmianie niezmierzone |
| R2 | dostępność, wydajność | **Konfiguracja Postgresa.** `statement_timeout` i `lock_timeout` dla roli API, `idle_in_transaction_session_timeout`, `pg_stat_statements`; `shared_buffers`, `effective_cache_size`, `work_mem`, `random_page_cost`, autovacuum dla gorących tabel; `max_connections` i pula Keycloaka policzone razem z wątkami API | 3.5: zapytania 1–12 s bez limitu; 3.6: oczekiwanie na blokadę bez limitu; 3.10 i P1: 128 MB buforów przy bazie 8,4 GB; P5: 0% aktualizacji HOT | zapytania i blokady przerywane po limicie zamiast zajmować wątki; mniej odczytów z dysku; diagnostyka w produkcji | S | wysoka dla limitów; średnia dla zysku z buforów (niezmierzony) |
| R1 | wydajność | **Utrzymane połączenie z bazą, docelowo worker mode FrankenPHP**, pod warunkami z sekcji 8.1 | 3.2: trzy warianty tego samego obrazu, `pgbench`; A1, A2 | warstwa API: żądanie z bazą z 12–22 ms do 1–5 ms CPU, 3–7× przepustowości zależnie od endpointu, p50 strony produktu z 11 do 2–3 ms | S (trwałe połączenie) do M (worker mode z walidacją z 8.1) | wysoka co do zysku (zmierzony); średnia co do bezpieczeństwa, dopóki nie przejdą testy z 8.1 |
| R5 | wydajność panelu | **Trzy zapytania panelu.** Nazwa: plan niezależny od szacunku planisty (np. trafienia z indeksu trigramowego w materializowanym CTE z limitem, potem sort; dla fraz bardzo częstych obecna ścieżka po indeksie nazwy jest dobra, więc w teście planu potrzebne oba przypadki); kategoria: `UNION` dwóch gałęzi zamiast `OR EXISTS`; sort zamówień po kwocie z fragmentem: zakaz połączenia albo limit czasu | 3.3 i 3.5: plany i czasy; P3, P4 | najgorsze przypadki z 1–12 s do dziesiątek–setek ms na 1 mln produktów | S | wysoka dla filtra kategorii; średnia dla nazwy (wymaga pomiaru na frazach rzadkich, średnich i częstych) |
| R10 | narzędzia pomiaru | **Generator i pomiar.** `max_duration` w kliencie Keycloaka generatora, sprzątanie indeksu po przerwanej przebudowie, losowanie danych testu obciążeniowego z całego katalogu | 3.1: błąd kroku kont, osierocony indeks; I5 | profil `medium` i większe da się uruchomić jednym poleceniem; pomiar przestaje zawyżać przez gorący zbiór | S | wysoka |
| R3 | wydajność | **Cache HTML listingów.** Stan domyślny `/` i `/c/<ścieżka>` (bez parametrów, strona 1) przez ISR/`use cache` z tagami albo przez CDN z `s-maxage` i `stale-while-revalidate`; `replicas` jako wartość chartu; wspólny cache między replikami albo CDN jako jedyny cache HTML, zanim replik będzie więcej niż jedna (decyzja razem z ABUSE-LIMITS D12, które dziś zakłada cache w pamięci poda bez Redisa) | 3.8: 12–15 ms CPU na render, 87–100 listingów/s; 3.9: sklep największym konsumentem CPU w obu przebiegach; F1, I1 | trafienie zamiast renderu na najczęstszych stronach; dla strony produktu to 12× mniej CPU, dla listingów niezmierzone; zniesienie ograniczenia jednej repliki | L | wysoka co do kierunku; wielkość zysku do zmierzenia po zmianie |
| R7 | wydajność, dostępność | **Sklep: limity i waga.** Limit czasu w `createApiClient`; do klienta tylko trzy poziomy drzewa, które menu pokazuje; `prefetch` kafelków po najechaniu; `/api/site` z cache; krótki czas życia wpisów 404 | 3.8: 115 KB stałego narzutu strony, wpis ISR 262 KB, LCP 3,1–3,3 s, 14–16 żądań `fetch` na wejście; F3–F6 | mniejszy HTML i wpis ISR, krótszy LCP, brak kumulacji renderów przy wolnym API | M | średnia; do zmierzenia Lighthouse po zmianie |
| R4 | wydajność; z danymi dostępność | **Facety osobno.** Agregacje jako osobne zapytanie cache'owane dłużej niż wyniki (klucz: kategoria i fraza bez strony i sortu); dla szerokich zapytań bez filtrów wariantów unieważniane zdarzeniami; potem kilka shardów i replika | 3.4: rozbicie zapytania (61–99% czasu to facety), wzrost z liczbą trafień; S1, S2 | listing całego katalogu z ok. 580 ms do kilku–kilkunastu ms przy trafieniu w cache facetów [SZACUNEK z rozbicia]; odsuwa próg 503 | M–L | wysoka dla listingów kategorii; średnia dla wyszukiwania (trafność cache dla fraz niezmierzona) |
| R6b | wydajność; pośrednio poprawność (B2, B3) | **Przepustowość workera** (ponad zakres ASYNC-RELIABILITY, które dzieli workery na dwa, a repliki odsyła do SCALE-PROOF): e-maile na osobnym transporcie i w kilku procesach, kilka konsumentów `events`, łączenie zdarzeń zmiany produktu (jeden reindeks i jeden PURGE z wieloma tagami na partię), reindeks poddrzewa przez `_bulk` | 3.7: 17 zdarzeń zamówień/s przy 2,1 ms CPU na zdarzenie, ok. 100 zmian cen/s; D4 | więcej niż 17 zdarzeń zamówień na sekundę (skala zależy od liczby procesów i od SMTP) [SZACUNEK]; zmiany masowe wielokrotnie szybciej | L | średnia |
| R8 | dostępność | **Redis i Varnish.** `maxmemory` z polityką `volatile-*`, limit poda dobrany do liczby sesji; Varnish z jawnym rozmiarem i `grace` | C1, C2: brak konfiguracji; 3.10: magazyn Varnisha 100 MB | brak wylogowania wszystkich przy przepełnieniu; wyższy współczynnik trafień | S | średnia: pamięć na sesję i trafność przy długim ogonie niezmierzone |
| R9 | dostępność przy wyprzedaży | **Gorący wariant i kod z limitem.** `lock_timeout` (z R2), licznik użyć jako kolumna na wierszu kodu, rezerwacja możliwie blisko końca transakcji | 3.6: 170 zamówień/s i p99 powyżej 1 s na jednym wariancie; 3.5: licznik 0,8 ms przy 12,5 tys. użyć, liniowy | przewidywalny czas odpowiedzi przy wyprzedaży; kolejka zamiast narastania czekających wątków | M | średnia; kod z limitem pod obciążeniem niezmierzony |

**Proponowana kolejność.** Najpierw poprawność i bezpieczniki: R6a oraz limity czasu z R2. Potem R1 razem z resztą
R2 (jedno wdrożenie, jeden pomiar przed i po) oraz małe R5 i R10. Dalej R3 z R7, R4 i R6b. R8 i R9 przed pierwszą
prawdziwą sprzedażą. R1 przed R6a zwiększyłoby tempo przyjmowania zamówień przy niezmienionym tempie ich
obsługi w tle, czyli pogłębiło B1 i B2.

### 8.1 R1 szczegółowo: warunki bezpiecznego wdrożenia

Pomiar pokazuje zysk, nie bezpieczeństwo. W obu wariantach coś zaczyna żyć dłużej niż jedno żądanie i to trzeba
sprawdzić przed wdrożeniem.

| | Tryb klasyczny (dziś) | Tryb klasyczny + trwałe połączenie | Worker mode |
|---|---|---|---|
| Kernel i usługi PHP | nowe na każde żądanie | nowe na każde żądanie | **żyją między żądaniami** |
| Połączenie z bazą | nowe na każde żądanie | **jedno na wątek, stałe** | **jedno na wątek, stałe** |
| Zmierzony koszt strony produktu (CPU API + Postgres) | 16–22 ms | 2,2 ms | 1,0–1,2 ms |
| Zakres ryzyka | – | stan sesji bazy, liczba połączeń | to samo plus stan usług i pamięć |

Trwałe połączenie w trybie klasycznym daje większość zysku przy mniejszym zakresie ryzyka, więc nadaje się na
pierwszy krok. Worker mode jest krokiem drugim.

**1. Stan między żądaniami (worker mode).**

- Ryzyko: usługa, która zapamiętała dane jednego żądania (tożsamość, identyfikator korelacji, kontekst Sentry,
  wynik odczytu w polu obiektu), pokaże je następnemu żądaniu na tym samym wątku. Symfony zeruje usługi oznaczone
  `kernel.reset` albo implementujące `ResetInterface`; właściwości statyczne i ustawienia procesu zostają.
- Stan w repo [KOD]: `ResetInterface` implementuje jedna klasa aplikacji, `CorrelationId`
  (`apps/api/src/Shared/Infrastructure/Observability/CorrelationId.php:18`). Reszta polega na usługach
  frameworka i na tym, że handlery są bezstanowe; tego nie przeglądałem usługa po usłudze.
- Co zmierzyłem: 381 tys. żądań bez odpowiedzi 5xx, ale jednym tokenem pracownika i bez tożsamości klienta, więc
  przeplatania użytkowników na jednym wątku pomiar nie sprawdza.
- Przed wdrożeniem: przegląd usług aplikacji pod kątem pól zmienianych w czasie żądania; test z jednym wątkiem
  (`num_threads 1`), w którym po żądaniu użytkownika A idzie żądanie użytkownika B i gościa, z asercjami na
  tożsamość, identyfikator korelacji, nagłówki cache i kontekst zgłoszenia do Sentry; pełne E2E na stosie
  w worker mode.

**2. Cykl życia Doctrine.**

- `EntityManager` po żądaniu: w worker mode Unit of Work musi być czyszczony między żądaniami. Powinien to robić
  reset usług Doctrine przy `kernel.reset` [HIPOTEZA: zachowanie frameworka, niepotwierdzone w tym audycie;
  research nie znalazł źródła pierwotnego].
- `EntityManager` zamknięty po błędzie bazy: projekt zna tę pułapkę (`apps/api/CLAUDE.md`, „Pułapki Doctrine”).
  W trybie klasycznym zamknięty menedżer znika z żądaniem; w worker mode musi zostać odtworzony, inaczej wątek
  odpowiada błędem do restartu. Test: żądanie, które kończy się błędem bazy (np. naruszenie unikalności), a po
  nim zwykłe żądanie na tym samym wątku.
- Otwarta transakcja po przerwanym żądaniu (limit czasu, wyjątek poza middleware transakcji): w obu wariantach
  z utrzymanym połączeniem następne żądanie mogłoby dostać połączenie w środku cudzej transakcji. Test przerwania
  żądania w transakcji plus `idle_in_transaction_session_timeout` jako bezpiecznik (R2).
- Stan sesji bazy: ustawienia `SET` i blokady doradcze na poziomie sesji przeżywają żądanie. W kodzie HTTP nie
  znalazłem ani jednych, ani drugich: blokada sesyjna jest tylko w komendzie przebudowy indeksu
  (`PostgresSearchIndexRebuildLock.php:23`), reszta używa blokad transakcyjnych [KOD, według analizy statycznej].
  To trzeba utrzymać jako zasadę.
- Zerwane połączenie (restart albo przełączenie Postgresa, limit bezczynności po drodze): DBAL nie łączy się
  ponownie sam, więc pierwsze żądanie na każdym wątku dostanie błąd. Tego nie testowałem. Test: restart Postgresa
  pod ruchem; rozwiązanie zależne od wyniku (sprawdzenie połączenia na początku żądania albo recykling wątków).
- Worker Messengera jest już procesem długożyjącym, więc część tych zachowań projekt zna i opisał w ASYNC-RELIABILITY
  (W2: limity czasu i pamięci).

**3. Pamięć.**

- Co zmierzyłem: 270 MB na 32 wątki po 381 tys. żądań w ok. godzinę (tryb klasyczny: 198 MB). To jeden punkt,
  bez przebiegu długiego, więc nie wyklucza powolnego wycieku.
- Ryzyko: wyciek, który w trybie klasycznym znika z żądaniem, w worker mode się kumuluje. `memory_limit` to
  256 MB na wątek, a pod ma limit 2 GiB (`infra/k8s/environments/prod/values.yaml`), więc kilka wątków
  z ciężkim żądaniem naraz może go przekroczyć w każdym trybie.
- Przed wdrożeniem: przebieg co najmniej 1 mln żądań mieszanych (odczyt, koszyk, zamówienie, panel) z odczytem
  pamięci co minutę; kryterium: brak trendu rosnącego po rozgrzaniu. Recykling wątku po N żądaniach
  (`max_requests`) jako bezpiecznik; sposób ustawienia w FrankenPHP 1.13 trzeba potwierdzić w dokumentacji tej
  wersji, bo research podaje go jako eksperymentalny.

**4. Połączenia z bazą.**

- W obu wariantach każdy wątek trzyma jedno połączenie stale. Zmierzone: 65 otwartych połączeń roli `shop` przy
  dwóch kontenerach po 32 wątki, przy `max_connections = 100` wspólnym z workerem, zadaniami i Keycloakiem.
- Liczba wątków jest dziś domyślna (2 × liczba CPU widziana przez proces) i nie ma jej w konfiguracji ani
  w chartcie [KOD]; tak samo pula połączeń Keycloaka.
- Przed wdrożeniem: jawna liczba wątków w Caddyfile i w wartościach chartu; bilans połączeń (wątki × repliki +
  workery + zadania + pula Keycloaka + zapas na wymianę podów, która chwilowo podwaja liczbę replik) zapisany
  i sprawdzany testem chartu; `max_connections` ustawione świadomie.
- PgBouncer w trybie transakcyjnym dopiero wtedy, gdy bilans przestanie się mieścić (wiele replik). Wymaga
  sprawdzenia blokady sesyjnej przebudowy indeksu i przygotowanych zapytań PDO.

**Wdrożenie i odwrót.** Oba warianty to zmiana konfiguracji, którą da się cofnąć bez migracji: opcja połączenia
w `doctrine.yaml` i dyrektywa w Caddyfile. Kolejność: compose i CI (E2E w nowym trybie), pomiar przed i po na
k3d (`make -C infra/k8s load`), dopiero potem środowisko wdrożeniowe.

## 9. Propozycje epików

Audyt aplikacji z 2026-11-18 przewiduje epik SCALE-PROOF („dokończenie DEMO-DATA, potem poprawki z pomiaru”),
a specyfikacje ASYNC-RELIABILITY i ABUSE-LIMITS odsyłają do niego repliki workerów i katalog na hoście `api.`.
Poniższe pozycje to proponowana treść tych „poprawek z pomiaru”, podzielona tak, żeby każdą dało się zmierzyć
osobno. Czy powstaną jako osobne epiki, czy jako etapy SCALE-PROOF, to decyzja właściciela; nie proponuję
równoległego zakresu.

| Epik | Zakres | Kryterium akceptacji z pomiarem |
|---|---|---|
| **API-RUNTIME** | R1, R2, R8 (część Postgresa i Redisa), A2, A3; chart i compose razem | powtórzony pomiar z 3.2: 0 nowych połączeń na żądanie, CPU strony produktu ≤ 3 ms na żądanie, przepustowość co najmniej 3× względem trybu klasycznego mierzonego w tym samym przebiegu; 1 mln żądań bez wzrostu pamięci; liczba połączeń Postgresa pod obciążeniem poniżej limitu |
| **STORE-CACHE** | R3, R7, F2, C3, C5, I1 dla sklepu; decyzja wspólna z ABUSE-LIMITS D12: cache w pamięci poda (stan z D12), wspólny `cacheHandler` czy CDN jako jedyny cache HTML | w przebiegu z 3.9 sklep przestaje być kontenerem o największym zużyciu CPU; dwie repliki pokazują tę samą cenę po zmianie; rozmiar wpisu ISR i HTML strony produktu zmierzony przed i po |
| **SEARCH-FACETS** | R4, S2, S3, S4 | listing całego katalogu `medium` p95 < 100 ms przy trafieniu w cache facetów; pomiar na `server` (10 mln) przed zamknięciem |
| **ADMIN-QUERIES** (mały) | R5, P7, P8, V2 | cztery scenariusze z 3.3 na `medium`: mediana poniżej 100 ms i najwolniejsze żądanie poniżej 1 s dla fraz rzadkich, średnich i częstych; testy planów dla każdej z tych grup |
| **ASYNC-THROUGHPUT** (po ASYNC-RELIABILITY, które realizuje R6a) | R6b: ASYNC-RELIABILITY dzieli workery na dwa i dodaje limity, ale nie zwiększa przepustowości `events` ani nie rusza e-maili; repliki workerów odsyła do SCALE-PROOF | zmierzone zdarzenia zamówień/s, wygaśnięcia/s i zmiany cen/s przed i po; brak zaległości `events` w czasie opróżniania outboxa |
| uzupełnienie **DEMO-LOAD (#486)** | R10 i plan z sekcji 11 | `make seed-scale PROFILE=medium` kończy się kodem 0 na obrazie produkcyjnym |

SMALL-FIXES (limit `ephemeral-storage` sklepu, pasek demo, paginacja w oknie wyników) i ABUSE-LIMITS (L9 i D12:
cache ISR w pamięci) zostają bez zmian zakresu. Dwie liczby z tego audytu są dla nich wejściem: wpis ISR ma
262 KB przy 180 kategoriach (ABUSE-LIMITS przyjmuje 88 KB, a SMALL-FIXES wymaga pomiaru tej wartości w raporcie
zadania), a cache 128 MB zmieści kilkaset takich stron (sekcja 3.8).

## 10. Rekomendowana początkowa infrastruktura produkcyjna

To rekomendacja dla prawdziwego sklepu na starcie (rząd dziesiątek odsłon na sekundę w szczycie, pojedyncze
zamówienia na sekundę, katalog do ok. 1 mln produktów), nie dla skali docelowej. Rozmiary są szacunkiem
wyprowadzonym z pomiarów na M4; rdzeń serwera w chmurze jest zwykle wolniejszy, więc przed zakupem trzeba
powtórzyć pomiar z sekcji 3.2 i 3.8 na docelowej maszynie.

| Warstwa | Rekomendacja | Uzasadnienie |
|---|---|---|
| CDN | Cloudflare jak dziś, plus reguły cache dla HTML katalogu i publicznego JSON-a z krótkim czasem i `stale-while-revalidate` | najtańsze odciążenie sklepu (W1) i wyszukiwarki (W3); zamyka S3 |
| Baza | zarządzany PostgreSQL 18 albo osobna maszyna: 4 vCPU, 16 GB RAM, NVMe, kopie z odtwarzaniem do punktu w czasie; baza Keycloaka jako osobna baza z własnym limitem połączeń; PgBouncer, gdy replik API będzie więcej niż dwie | największy i najważniejszy stan; baza 8,4 GB przy 1 mln produktów powinna mieścić się w pamięci; izolacja I/O od wyszukiwarki |
| Aplikacje | dwa węzły po 4 vCPU i 8 GB: API ×2 (worker mode, ok. 8 wątków na replikę), sklep ×2 ze wspólnym cache, worker ×2–3 (przekaźnik, zdarzenia, komendy), Varnish z 1–2 GB | bezprzerwowe wdrożenia i zapas: API w worker mode to ok. 1–4 ms CPU na żądanie, sklep ok. 13 ms na render bez cache [POMIAR na M4] |
| Wyszukiwarka | osobny węzeł: 4 vCPU, 16 GB RAM (sterta 8 GB), NVMe, snapshoty do magazynu obiektów; indeks 1,1 GB na 1 mln produktów | izolacja pamięci i dysku od bazy; dziś brak snapshotów oznacza godziny bez wyszukiwarki po awarii |
| Redis | mała instancja zarządzana albo jeden węzeł z `maxmemory` i AOF, 1 GB | sesje i limity logowania |
| Obrazy | zarządzany magazyn obiektów (R2/S3) zamiast Garage, imgproxy za CDN | trwałość; prawdziwe zdjęcia to dziesiątki TB przy 10 mln produktów [SZACUNEK] |
| Keycloak | jedna replika `start --optimized`, 2 vCPU i 2 GB; druga, gdy logowanie stanie się krytyczne | dokumentacja: 1 vCPU na 15 logowań/s |
| Kolejki | RabbitMQ jednowęzłowy jak dziś | bez klastra, dopóki jest jeden odbiorca |

Obecny układ (wszystko na jednym VPS 8 vCore i 24 GB) jest odpowiedni dla demo. Dla sprzedaży jego problemem nie
jest wydajność, tylko wspólna domena awarii i wspólny dysk bazy, wyszukiwarki i plików.

**Ścieżka wzrostu** [SZACUNEK]: (1) baza na osobną maszynę; (2) cache HTML na CDN i wspólny cache sklepu, potem
repliki sklepu; (3) wyszukiwarka na trzy węzły z kilkoma shardami i repliką po przeprojektowaniu facetów;
(4) repliki API i workerów z autoskalowaniem po kolejce; (5) Keycloak z własną bazą; (6) repliki bazy do odczytu
dla panelu (czytniki DBAL są już osobnymi klasami, więc to zmiana konfiguracji usług).

**Skala docelowa.** 60 tys. jednocześnie aktywnych użytkowników to według researchu od ok. 400 do ok. 3 750
odsłon na sekundę, zależnie od definicji „aktywnego” i czasu między odsłonami [SZACUNEK z researchu]. Przy dzisiejszym
koszcie renderu listingu (13 ms CPU) górna granica to kilkadziesiąt rdzeni samego sklepu; z cache HTML o trafności
90% rząd wielkości mniej. Specyfikacja pomiaru musi zdefiniować obie wielkości, zanim ktokolwiek poda liczbę
serwerów.

## 11. Plan benchmarków po ukończeniu DEMO-DATA (część 2, #486)

Do powtórzenia w tej kolejności, na k3d z obrazami produkcyjnymi i zasobami `prod`, na wolnej maszynie:

1. **`make seed-scale` do końca** na profilach `medium` i `server` po poprawce z R10; czasy kroków i rozmiary
   tabel do raportu (punkt odniesienia: sekcja 3.1 i 3.10).
2. **Zapytania z 3.5 na `server` i `full`**: ten sam plik `explain.sql`; sprawdzić ekstrapolacje (dziesiątki sekund
   dla wyszukiwania po nazwie, sekundy dla mapy strony).
3. **Rozbicie zapytania Elasticsearch z 3.4 na 10 mln**: czy koszt facetów jest nadal liniowy (ok. 5–6 s), ile
   zapytań kończy się 503 przy limicie 2 s, wpływ sterty 2 GB na zimnym cache stron.
4. **`make -C infra/k8s load LOAD_DATA=medium`, potem `full`**, z danymi losowanymi z całego katalogu (rozkład
   Zipfa, nie pierwsze 4800 produktów); `varnishstat` (trafienia, `n_lru_nuked`) w wynikach.
5. **Ten sam przebieg po R1** (worker mode): oczekiwana zmiana na ścieżkach koszyka i zamówienia, brak zmiany
   sufitu sklepu.
6. **Zamówienia z płatnością** po wyniesieniu symulatora z kontenera API (dziś równoległość jest sztucznie
   ograniczona): przepustowość złożenia i płatności, gorący wariant, kod z limitem przy 200 tys. użyć.
7. **Zaległość asynchroniczna pod ruchem**: 100 tys. zmian cen w czasie stałego ruchu zakupowego; opóźnienie
   e-maila, wygaśnięć i webhooków.
8. **Zalogowani klienci**: 10 tys. sesji, twarde wejścia; pamięć Redisa na sesję, odświeżenia tokenów na sekundę,
   zapisy Keycloaka do bazy.
9. **Zimny start**: wdrożenie w czasie ruchu (pusty cache ISR i Varnish po `purge-caches`), czas powrotu p95.
10. **Unieważnienie tagu `catalog` pod ruchem** (zmiana nazwy kategorii).
11. **Sklep przy dwóch replikach** po R3: spójność ceny po zmianie.
12. **Lighthouse** z paskiem demo i z drzewem kategorii o docelowej liczbie węzłów.

Każdy raport powinien podawać, co działało na maszynie oprócz pomiaru (ten audyt pokazuje, jak bardzo to zmienia
percentyle).

## 12. Jak powtórzyć pomiary

Skrypty i wybrane surowe wyniki są w [`performance-audit-2026-11-18/`](performance-audit-2026-11-18/) (opis katalogu: [`README.md`](performance-audit-2026-11-18/README.md)). Skrypty
traktują katalog nad `scripts/` jako roboczy (zapisują tam `targets/` i `results/`), więc przed użyciem trzeba je
skopiować poza repozytorium. Mają wpisaną nazwę projektu compose `shop-perfaudit` i porty 52700–52706.

```bash
A=docs/reports/performance-audit-2026-11-18
W=<katalog roboczy poza repozytorium>; mkdir -p "$W" && cp -R "$A/scripts" "$W/"
export SHOP_ROOT="$PWD"                # katalog główny repozytorium (dla run_ramp.sh i kc_token.sh)

# stos i dane (.env z COMPOSE_PROJECT_NAME=shop-perfaudit i portami 52700-52706)
STORE_MODE=production make up
make seed-scale PROFILE=small          # 29 s
make seed-scale PROFILE=medium         # zatrzyma się na kroku kont; dalsze kroki: scripts/rest-of-generate.sh
                                       # w kontenerze api, a read-models i reindex w kontenerze z obrazu prod

# obraz produkcyjny API: tryb klasyczny (jak w repo) i wariant worker (tylko inny Caddyfile)
docker build --target prod -t shop-perfaudit-api:prod apps/api
docker run -d --name shop-perfaudit-api-prod  --network shop-perfaudit_default --network-alias api-prod \
  --env-file <zmienne kontenera api> -e APP_ENV=prod -e APP_DEBUG=0 shop-perfaudit-api:prod
docker run -d --name shop-perfaudit-api-prodw --network shop-perfaudit_default --network-alias api-prodw \
  --env-file <zmienne kontenera api> -e APP_ENV=prod -e APP_DEBUG=0 \
  -v "$W/scripts/Caddyfile.worker:/etc/frankenphp/Caddyfile:ro" shop-perfaudit-api:prod

# pg_stat_statements na własnym Postgresie
docker compose exec postgres psql -U postgres -c "ALTER SYSTEM SET shared_preload_libraries='pg_stat_statements'"
docker compose restart postgres
docker compose exec postgres psql -U postgres -d shop -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements"

# token panelu (testowe konto w lokalnym realmie) i adresy do pomiaru z danych stosu
sh "$W/scripts/kc_token.sh" "$W/admin.token" && cp "$W/admin.token" "$W/scripts/admin.token"
python3 "$W/scripts/gen_targets.py" medium --token-file "$W/admin.token"

# 3.3 i 3.2: każdy endpoint osobno; warianty trybu
"$W/scripts/sweep.sh" medium api-prod 1 8s
"$W/scripts/sweep.sh" medium api-prodw 16 12s product offers_24

# 3.6: koszyk i zamówienie; gorący wariant po podniesieniu jego stanu
python3 "$W/scripts/bench.py" medium.api-prodw.checkout.v32 "$W/targets/medium/variants.json" --script checkout.js \
  --vus 32 --duration 20s --env API=http://api-prodw --env VARIANTS_FILE=/targets.json
docker exec shop-perfaudit-postgres-1 psql -U postgres -d shop \
  -c "update inventory_stock_item set physical = 900000 where variant_id = '<wariant>'"
python3 "$W/scripts/bench.py" medium.api-prodw.checkout_hot.v32 "$W/targets/medium/variants.json" --script checkout.js \
  --vus 32 --duration 20s --env API=http://api-prodw --env VARIANTS_FILE=/targets.json --env HOT=<wariant>

# 3.2: koszt połączenia
echo 'SELECT 1;' > select1.sql
pgbench -n -h 127.0.0.1 -U shop -f select1.sql    -c 1 -T 6 shop
pgbench -n -h 127.0.0.1 -U shop -f select1.sql -C -c 1 -T 6 shop

# 3.5: plany
docker exec -i shop-perfaudit-postgres-1 psql -U postgres -d shop -f - < "$W/scripts/explain.sql"

# 3.4: rozbicie zapytania Elasticsearch
"$W/scripts/es_capture.sh" topcat '/api/v1/products?category=<id działu>'
python3 "$W/scripts/es_attrib.py" "$W" "$W/results/es-topcat.json" 5

# pełna ścieżka: api i worker stosu z obrazu produkcyjnego (lokalne nadpisanie compose, nie do commitu)
docker compose -f compose.yaml -f "$W/scripts/perf.override.yaml" up -d --wait api worker

# 3.8: strony sklepu i statystyki Varnisha
"$W/scripts/sweep.sh" medium store 1 10s store_home store_category_top store_product store_product_one
"$W/scripts/sweep.sh" medium store 16 15s store_home store_category_top store_product_one
docker exec shop-perfaudit-varnish-1 varnishstat -1 | grep -E 'MAIN.cache_(hit|miss) |SMA.s0.g_space'

# 3.7: 2000 zmian cen i zaległość kolejek (drain.py próbkuje outbox i kolejkę events co sekundę)
python3 "$W/scripts/drain.py" 900 &
python3 "$W/scripts/bench.py" medium.api.price_changes.v8 "$W/targets/medium/price-changes.json" --script prices.js \
  --vus 8 --duration 60s --env API=http://api --env CHANGES_FILE=/targets.json --env TOKEN_FILE=/scripts/admin.token

# 3.9: przebieg do nasycenia, 3.8: Lighthouse
DISCOVER_MAX_PAGES=15 RAMP_STEPS=25 RAMP_STEP_SECONDS=20 "$W/scripts/run_ramp.sh" medium-classic
make lighthouse
```

`<zmienne kontenera api>` to plik ze zmiennymi środowiska działającego kontenera `api` stosu
(`docker inspect <kontener> --format '{{range .Config.Env}}{{println .}}{{end}}'`); zawiera lokalne sekrety
z `.env.local`, więc nie ma go w materiałach, podobnie jak tokenu panelu i list adresów z tokenem (`targets/`).
Wariant z trwałym połączeniem to obraz zbudowany z kopii `apps/api` z jedną dodaną opcją
w `config/packages/doctrine.yaml` (`options: { 12: true }`, czyli `PDO::ATTR_PERSISTENT`). Lista zmian cen
(`price-changes.json`) to 2000 wierszy `variantId, version, gross` wybranych losowo z `pricing_variant_price`.

Pliki wyników w `performance-audit-2026-11-18/results/`: `bench.jsonl` (wpis na każdy przebieg k6 z CPU, SQL i próbką obcego obciążenia;
gdy nazwa się powtarza, to kolejny przebieg tego samego scenariusza), `sweep-*.txt`,
`classic-vs-worker-medium.txt`, `classic-persistent-medium.txt`, `checkout-medium.txt`, `store-medium.txt`,
`explain-medium.txt`, `explain-ilike-medium.txt`, `pgbench-connect.txt`, `es-attrib-*.txt`, `drain-*.txt`,
`scheduled-drain.txt`, `ramp-medium-classic/`, `ramp-medium-classic-2/`, `lighthouse-medians.md`,
`seed-medium-steps.txt`, `manual-measurements.md` (odczyty jednorazowe: statystyki Postgresa, pamięć, rozmiary
HTML i wpisów ISR, bundle, Varnish, tempo workera).

**Zachowane środowisko.** Stos `shop-perfaudit` jest zatrzymany, a jego wolumeny (Postgres z danymi `medium`,
indeks wyszukiwarki, Garage) zostały na komputerze właściciela do pomiarów porównawczych. Do ponownego
uruchomienia potrzebne są pliki `.env` i `.env.local` z worktree audytu, bo hasła w wolumenach pochodzą z nich.

## 13. Źródła

Raporty i kod repozytorium:

- `docs/reports/load-test-2026-11-10.md` (punkt odniesienia dla 3.9), `docs/vision.md` („Wymagania
  niefunkcjonalne”), `docs/features/demo-data/spec.md`, `docs/features/async-reliability/spec.md`,
  `docs/features/small-fixes/spec.md`, `docs/features/abuse-limits/spec.md`.
- Analiza statyczna tego audytu: [`findings/01-backend.md`](performance-audit-2026-11-18/findings/01-backend.md),
  [`02-postgres.md`](performance-audit-2026-11-18/findings/02-postgres.md), [`03-frontend.md`](performance-audit-2026-11-18/findings/03-frontend.md),
  [`04-infra-cache-search.md`](performance-audit-2026-11-18/findings/04-infra-cache-search.md).

Research (pełna lista z cytatami i oznaczeniem klasy źródła: [`research.md`](performance-audit-2026-11-18/research.md)).
Najważniejsze źródła pierwotne:

- FrankenPHP: <https://frankenphp.dev/docs/worker/>, <https://frankenphp.dev/docs/classic/>,
  <https://frankenphp.dev/docs/performance/>, <https://frankenphp.dev/docs/known-issues/>,
  <https://frankenphp.dev/docs/metrics/>
- Symfony: <https://symfony.com/doc/current/performance.html>, <https://symfony.com/doc/current/messenger.html>,
  <https://symfony.com/doc/current/components/runtime.html>
- Doctrine: <https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/improving-performance.html>,
  <https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/batch-processing.html>,
  <https://www.doctrine-project.org/projects/doctrine-orm/en/stable/tutorials/pagination.html>
- PostgreSQL 18: <https://www.postgresql.org/docs/18/release-18.html>,
  <https://www.postgresql.org/docs/18/runtime-config-resource.html>,
  <https://www.postgresql.org/docs/18/runtime-config-autovacuum.html>,
  <https://www.postgresql.org/docs/18/runtime-config-client.html>,
  <https://www.postgresql.org/docs/18/storage-hot.html>, <https://wiki.postgresql.org/wiki/Slow_Counting>,
  <https://www.pgbouncer.org/config.html>
- Elasticsearch: <https://www.elastic.co/docs/deploy-manage/production-guidance/optimize-performance/search-speed>,
  <https://www.elastic.co/guide/en/elasticsearch/reference/current/size-your-shards.html>,
  <https://www.elastic.co/docs/reference/elasticsearch/rest-apis/paginate-search-results>
- Redis, Varnish, RabbitMQ: <https://redis.io/docs/latest/develop/reference/eviction/>,
  <https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/>,
  <https://varnish-cache.readthedocs.io/users-guide/sizing-your-cache.html>,
  <https://www.rabbitmq.com/docs/confirms>, <https://www.rabbitmq.com/docs/quorum-queues>
- Next.js: <https://nextjs.org/docs/app/guides/self-hosting>, <https://nextjs.org/docs/app/getting-started/caching>,
  <https://nextjs.org/docs/app/api-reference/config/next-config-js/cacheHandlers>,
  <https://nextjs.org/docs/app/guides/cdn-caching>, <https://web.dev/articles/vitals>
- Keycloak: <https://www.keycloak.org/high-availability/single-cluster/concepts-memory-and-cpu-sizing>,
  <https://www.keycloak.org/high-availability/single-cluster/concepts-database-connections>
- Testy obciążeniowe: <https://grafana.com/docs/k6/latest/using-k6/scenarios/concepts/open-vs-closed/>,
  <https://docs.xceptance.com/xlt/how-tos/load-numbers/>

Pomiary niezależne przywołane w tekście (blogi i producenci, nie dokumentacja):

- Tideways, tryb klasyczny FrankenPHP a PHP-FPM:
  <https://tideways.com/profiler/blog/testing-if-franken-php-classic-mode-is-faster-and-more-scalable-than-php-fpm>
- Shopware 6.7, test partnera na 11 mln produktów: <https://shopware.com/en/news/shopware-6-7-performance-benchmarks-8mylez/>
- Shopify, przygotowanie do szczytu 2025: <https://shopify.engineering/bfcm-readiness-2025>

Research oznacza własne luki; najważniejsze dla tego raportu: brak niezależnych pomiarów dokładnie tych wersji
(FrankenPHP 1.13, PHP 8.5, Symfony 8.1), brak źródła pierwotnego o zachowaniu `EntityManager` Doctrine w worker
mode i brak liczb przepustowości Elasticsearch dla facetów zagnieżdżonych. Dlatego te trzy rzeczy zmierzyłem sam
albo zostawiłem jako warunek rekomendacji.

## 14. Weryfikacja raportu

Pierwszą wersję raportu sprawdził niezależny recenzent (subagent w świeżym kontekście, z dostępem do surowych
wyników i kodu, bez możliwości uruchamiania pomiarów). Sprawdził ok. 25 odwołań do kodu (zgodne, dwa doprecyzowane)
i wszystkie liczby tabel sekcji 3 (zgodne ze źródłami poza wymienionymi niżej). Zgłosił 16 uwag; co z nimi zrobiłem:

| Uwaga recenzenta | Co zmieniłem |
|---|---|
| Mnożniki „6–10×” i „9× mniej CPU” dotyczyły tylko strony produktu | Zakresy rozpisane na endpointy; dodany wariant kontrolny z trwałym połączeniem (nowy pomiar), który pokazał, że większość zysku nie wymaga worker mode |
| Nasycenie sklepu oparte na jednej próbce w chwili skoku obcego obciążenia | Przebieg powtórzony: przy mniejszym obcym obciążeniu załamanie przy ok. 140, nie 100 iteracji/s; sklep nadal największym konsumentem CPU; wniosek o jednym procesie oznaczony jako hipoteza |
| Części liczb brakowało plików źródłowych | Dodane: `explain-ilike-medium.txt`, `pgbench-connect.txt`, `manual-measurements.md`, `scheduled-drain.txt` |
| Wyszukiwanie po nazwie przedstawione jak stałe 1,6–4,6 s | Podane mediany, liczba próbek i zależność od frazy |
| „30 minut zaległych e-maili” jako pomiar | Oznaczone jako szacunek z tempa; dopisane ręczne opróżnienie kolejki |
| „CPU na żądanie odporne na hałas” | Opisane zniekształcenia miary i wzrost CPU ze współbieżnością (2.4) |
| „SQL nie rośnie”, „80–99% to facety”, liniowość z dwóch punktów | Poprawione na wartości z tabel: wzrost nieznaczny, 61–99%, zależność z czterech punktów |
| Sprzeczność z przygotowanymi specyfikacjami (ABUSE-LIMITS D12, SMALL-FIXES D4, SCALE-PROOF) | Dopisane odwołania i skutek D12 przy wpisie 262 KB; propozycje epików ustawione jako treść SCALE-PROOF |
| Pewność R5, brak klas dowodu przy korzyściach R3 i R4, drobne liczby | Poprawione |

Po recenzji, na życzenie właściciela przed publikacją, raport dostał jeszcze: rozróżnienie poprawności biznesowej,
dostępności i wydajności (sekcja 4.1 i kolumna „Rodzaj”), warunki bezpiecznego wdrożenia worker mode (8.1),
objaśnienie różnicy między przepustowością warstwy a systemu (sekcja 0), dowód przy każdej rekomendacji i pełny
identyfikator rewizji. To zmiany redakcyjne na tych samych pomiarach; nowej rundy audytu nie było.

Czego nie zrobiłem mimo uwag: nie powtarzałem wszystkich przebiegów wielokrotnie (większość liczb to nadal jeden
przebieg 8–20 s na współdzielonej maszynie) i nie mierzyłem trafności cache facetów ani zysku z cache HTML listingów.
Oba braki są w planie benchmarków (sekcja 11).

