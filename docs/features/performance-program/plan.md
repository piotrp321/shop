# Program naprawczy wydajności – plan

Program odpowiada na audyt [`docs/reports/performance-audit-2026-11-25.md`](../../reports/performance-audit-2026-11-25.md)
(rewizja `2b4258c7`). Właściciel zaakceptował diagnozę audytu, a nie wszystkie jego rekomendacje. Ten plan jest
propozycją do akceptacji: porządkuje epiki, zależności i kryteria. Każdy epik dostaje własną specyfikację i plan zadań
(`/brainstorm`, `/planner`) dopiero po akceptacji programu. Plan jest dokumentem roboczym: po zakończeniu programu
usuń go, a obowiązujące wymagania zostają w `docs/vision.md` i specyfikacjach funkcji.

## Status

| Epik | Stan |
|---|---|
| Program | propozycja, czeka na akceptację właściciela |
| PERF-0 … PERF-9 | nie rozpoczęte |

## Założenia właściciela (2026-11-25)

1. Wydajność i skala (10 mln produktów, 1 mln klientów, 5 mln zamówień) są wymaganiem projektu od początku. Rozwój
   nowych funkcji jest wstrzymany do zakończenia programu.
2. Najpierw powtarzalne benchmarki i konkretne wymagania, potem optymalizacje.
3. Każdy epik ma mierzalny efekt i porównanie przed i po tym samym zestawem benchmarków.
4. Funkcji nie ograniczamy po to, żeby poprawić wynik benchmarku.
5. Rzeczywista optymalizacja architektury i zapytań ma pierwszeństwo przed cache.
6. Architektura zostaje prosta; używamy natywnych możliwości wybranych technologii, bez nowych usług.
7. Zasobów serwera na razie nie zwiększamy (8 vCPU, 22,9 GiB, 193 GB).
8. Odpowiedź zdegradowana nie jest sukcesem testu wydajności.
9. Benchmarki obejmują poprawność danych, współbieżność, długotrwałe obciążenie i równoległe zapisy.
10. Kryterium sukcesu to wydajny system na profilu `server`, a nie wygenerowanie 10 mln produktów.

## Co to zmienia względem rekomendacji audytu

| Rekomendacja audytu | W programie |
|---|---|
| strona główna bez facetów opcji, filtry wariantu tylko w kategorii, minimum 3 znaki frazy w panelu | odrzucone (założenie 4): funkcje zostają, a model wyszukiwania ma je obsłużyć w budżecie |
| listing zdegradowany (bez facetów albo kopia) jako ochrona przed przeciążeniem | zostaje wyłącznie jako zabezpieczenie awaryjne; w benchmarkach liczy się jako niepowodzenie (założenie 8) |
| cache facetów | nie jest pierwszym krokiem (założenie 5); patrz „Granica cache” niżej |
| serwer 32–64 GiB dla `server` | odrzucone na teraz (założenie 7); program ma zmieścić gorące dane w obecnej pamięci, a gdy pomiar pokaże, że się nie da, wraca do właściciela z liczbami |
| mechanizmy ASH (budżety w specyfikacji, pytanie o wzrost kosztu, bramka przed wdrożeniem) | poza programem; workflow ASH bez zmian. Zestaw benchmarków z PERF-0 jest narzędziem produktu w repo, nie zmianą workflow |

### Granica cache

Cache maskuje problem, gdy ukrywa koszt, który i tak trzeba zapłacić przy każdym chybieniu (dzisiejszy Varnish z TTL
60 s i kluczem per URL). Nie jest maskowaniem:

- wynik policzony raz dla danych, które nie zależą od użytkownika, strony ani sortowania, i unieważniany dokładnie
  przy zmianie danych (natywny request cache Elasticsearcha, ISR Next.js dla HTML niezależnego od sesji);
- model odczytu utrzymywany przy zapisie, ze zdefiniowaną świeżością, jak istniejące `ordering_sales_hour`.

W programie obowiązuje zasada: benchmark „zimny” wyłącza wszystkie warstwy cache dla mierzonego adresu, a wymagania
dla zimnej ścieżki muszą być spełnione bez nich. Model odczytu wolno wprowadzić dopiero, gdy pomiar pokaże, że samo
zapytanie nie zmieści się w budżecie na obecnym serwerze, i tylko z testem zgodności z wynikiem liczonym na żywo.

## Ograniczenia, które trzeba powiedzieć wprost

- **Fasety całego katalogu 10 mln przy każdym żądaniu nie zmieszczą się w budżecie na 8 vCPU.** Najtańszy zmierzony
  model (pola na dokumencie produktu) to ok. 160 ms CPU na 1 mln produktów na szybkim rdzeniu, czyli ok. 5 s CPU
  serwera na 10 mln [SZACUNEK z audytu, sekcja 2.3]. Dokładne fasety całego katalogu, liczone osobno dla każdego
  żądania i przy wielu równoległych wejściach, są na tym sprzęcie niewykonalne. Skoro funkcja zostaje (założenie 4)
  i sprzęt zostaje (założenie 7), fasety zapytań bez filtrów muszą być liczone raz i współdzielone. PERF-2 zaczyna
  się od pomiaru, który to potwierdzi albo obali na indeksie 10 mln.
- **Zapytania z filtrem wariantu na całym katalogu 10 mln** kosztują w obu zbadanych modelach ok. 9–13 s na serwerze
  [SZACUNEK]. To główne ryzyko programu: PERF-2 musi znaleźć model, który je obsłuży, i udowodnić to pomiarem przed
  implementacją.
- **Pamięć.** Gorące dane `server` to ok. 16 GiB (indeksy sklepu w Postgresie ok. 9,5 GB, indeks wyszukiwarki ok.
  4 GB, sterta 2 GiB) [SZACUNEK]. Zmieszczenie ich w 22,9 GiB wymaga przesunięcia limitów pamięci między usługami
  i zmiany reguły chartu „suma limitów do 20 GB”. To zmiana zasady, więc decyzja właściciela w PERF-1.
- **60 tys. jednocześnie aktywnych użytkowników** (`docs/vision.md`) to przy każdym rozsądnym modelu ruchu setki do
  tysięcy stron na sekundę. Jeden serwer 8 vCPU tego nie obsłuży niezależnie od optymalizacji [SZACUNEK]. Program
  mierzy przepustowość jednego węzła i usuwa przeszkody w skalowaniu poziomym; cel 60 tys. staje się wtedy rachunkiem
  liczby węzłów, a nie cechą obecnego serwera.
- Liczby audytu dla serwera i 10 mln są ekstrapolacją. Pierwszym wynikiem programu jest ich zastąpienie pomiarem.

## Wymagania wydajnościowe (propozycja wartości wyjściowych)

Wartości zatwierdza właściciel w PERF-0. PERF-0 może je zmienić tylko w górę albo doprecyzować sposób pomiaru.
Progu nie obniżamy po cichu: gdy po optymalizacji pomiar pokaże, że próg jest nieosiągalny na obecnym serwerze bez
ograniczenia funkcji, epik kończy się raportem i decyzją właściciela.

Sposób pomiaru: czas po stronie serwera (od wejścia żądania do ostatniego bajtu odpowiedzi, bez sieci klienta),
profil `server` na worf.pl, liczone są wyłącznie pełne odpowiedzi 2xx zgodne z kontraktem. Odpowiedź zdegradowana,
odrzucona albo 5xx to niepowodzenie. „Zimne” znaczy: żadna warstwa cache nie zna tego adresu.

| ID | Ścieżka | p50 | p95 | p99 |
|---|---|---|---|---|
| W1 | listing, wyszukiwanie, filtrowanie (z facetami): zimne | 200 ms | 500 ms | 1000 ms |
| W2 | listing: ciepłe | 30 ms | 100 ms | 200 ms |
| W3 | strona produktu: zimna | 80 ms | 250 ms | 500 ms |
| W4 | strona produktu: ciepła | 15 ms | 50 ms | 100 ms |
| W5 | koszyk: odczyt i zmiana | 50 ms | 150 ms | 300 ms |
| W6 | złożenie zamówienia | 150 ms | 500 ms | 1000 ms |
| W7 | panel: listy, wyszukiwanie, filtry | 100 ms | 500 ms | 2000 ms (maksimum) |

| ID | Przepustowość i współbieżność na obecnym serwerze (30 min, 0 niepowodzeń, czasy w normie W1–W7) |
|---|---|
| W8 | 100 równoczesnych zimnych wejść na listing (różne adresy: strona główna, działy, liście, frazy, filtry, sortowania, strony głębokie) |
| W9 | zimne listingi z długiego ogona: co najmniej 30 na sekundę |
| W10 | ruch mieszany sklepu (listing 60%, strona produktu 30%, koszyk i zamówienia 10%): co najmniej 150 stron na sekundę |
| W11 | zamówienia: co najmniej 20 na sekundę, równolegle ze 50 zmianami cen i stanów na sekundę i pracą w panelu |
| W12 | przeciążenie 2 razy ponad zmierzoną przepustowość przez 5 min: pełne odpowiedzi co najmniej 80% wartości szczytowej, koszyk i zamówienia w normie W5–W6, powrót do normy w 60 s po ustaniu, 0 restartów |

| ID | Poprawność, trwałość i zasoby |
|---|---|
| W13 | po każdym benchmarku z zapisami: stan magazynowy nieujemny i zgodny z ruchami, brak podwójnych zamówień i płatności, sumy zamówień zgodne z liniami, indeks wyszukiwarki zgodny z bazą dla próby produktów, 0 wiadomości w `failed` |
| W14 | bieg ciągły 2 h przy 70% zmierzonej przepustowości z zapisami: brak trendu wzrostu p95, pamięci, zaległości kolejek i rozmiaru tabel kolejek; 0 restartów i OOM |
| W15 | zasoby przy obciążeniu nominalnym (połowa zmierzonej przepustowości): CPU węzła do 70%, iowait do 5%, żaden kontener powyżej 85% limitu pamięci |

| ID | Operacje asynchroniczne i masowe (`server`) |
|---|---|
| W16 | zmiana ceny albo stanu widoczna w sklepie: p95 do 10 s, p99 do 60 s przy 50 zmianach na sekundę |
| W17 | 100 tys. zmian cen: sklep zgodny w 15 min; e-mail zamówienia złożonego w trakcie w 60 s |
| W18 | zmiana nazwy albo przeniesienie działu (20% katalogu): zakończone w 30 min, W1 spełnione w trakcie |
| W19 | pełny reindeks: do 60 min, zapisy z czasu przebudowy obecne w nowym indeksie |
| W20 | generator `server`: do 6 h w limitach pamięci zadania |

Te same identyfikatory na profilu `medium` mają progi czasów W1 i W7 o jedną trzecią niższe; służą do szybkich
porównań przed i po w trakcie epików.

## Epiki

Rodzaj: **przebudowa** (zmiana modelu danych, kontraktu albo sposobu przetwarzania), **optymalizacja** (zmiana
zapytania, konfiguracji albo kodu bez zmiany modelu), **narzędzia**.

### PERF-0. Benchmarki, wymagania i linia bazowa (narzędzia)

Blokuje wszystkie pozostałe epiki.

- Zestaw scenariuszy w repo (k6 w `load/`, cele `make`), jeden scenariusz na każde wymaganie W1–W20: zimny długi
  ogon z adresów generowanych z danych, rampa współbieżności, ruch mieszany z zapisami, przeciążenie, bieg ciągły,
  zdarzenia i operacje masowe, panel (najgorsze przypadki z audytu), kontrola poprawności W13 po każdym przebiegu.
- Wynik przebiegu to plik z werdyktem dla każdego wymagania (spełnione, niespełnione, wartość), ten sam format przed
  i po. Ścieżka błędu: przebieg bez danych, z niedziałającą usługą albo z niepełną próbą kończy się błędem, nie
  werdyktem.
- Dane i środowiska: `medium` i `server` lokalnie na k3d z zasobami ograniczonymi do 8 CPU i 22,9 GiB (porównania
  przed i po w epikach) oraz worf.pl (wynik obowiązujący). Lokalne czasy nie są wynikiem serwera; służą do różnic.
- Metryki potrzebne do diagnozy w ramach darmowego planu Grafana Cloud: Elasticsearch (pula `search`, sterta, GC),
  Postgres (`pg_stat_statements`, połączenia), FrankenPHP (zajęte wątki), kolejki (głębokość, wiek), Varnish
  (trafienia), presja CPU i I/O węzła.
- Zatwierdzenie wartości W1–W20 i zapis w `docs/vision.md`.
- Linia bazowa: `medium` na worf.pl (stan dzisiejszy) i `server` lokalnie, raport w `docs/reports/`.

Kryteria akceptacji: dwa kolejne przebiegi tego samego scenariusza na niezmienionym stosie różnią się o nie więcej
niż ustalony w epiku rozrzut (wyznaczony z co najmniej pięciu przebiegów); scenariusz W8 odtwarza dzisiejszy błąd
(#954) na linii bazowej; każdy scenariusz ma test ścieżki błędu; raport linii bazowej z werdyktem W1–W20.

Zgody właściciela: benchmarki z zapisami na worf.pl (zamówienia testowe na danych demo) i każdy `generate` osobno.

### PERF-1. Konfiguracja uruchomieniowa i budżet zasobów (optymalizacja)

Zależy od: PERF-0. Równolegle z: PERF-2, PERF-7.

- Postgres: pamięć, WAL i punkty kontrolne, `statement_timeout`, `lock_timeout`,
  `idle_in_transaction_session_timeout`, `/dev/shm`, liczba połączeń wobec wątków API, workerów i Keycloaka.
- Budżet pamięci węzła dla `server`: limity Elasticsearcha i Postgresa obejmujące cache plików gorących danych,
  limity pozostałych usług dopasowane do zmierzonego zużycia, rezerwa dla systemu; zmiana reguły sumy limitów
  w chartcie (decyzja właściciela); rozmiary wolumenów i miejsce na dysku przy reindeksie.
- Redis `maxmemory`, rozmiar magazynu Varnisha, pula Keycloaka, liczba i pamięć wątków PHP, współbieżność imgproxy.
- ADR z decyzjami i liczbami.

Efekt mierzony: W15 (iowait, pamięć), W3, W5–W6 przy bazie większej niż dzisiejszy limit pamięci, czas generatora
(W20). Kryteria: gorące ścieżki sklepu na danych `server` (lokalnie) bez odczytów z dysku w stanie ustalonym;
zapytanie dłuższe niż limit jest przerywane i zwalnia wątek; suma zużycia w biegu W10 mieści się w pamięci węzła
z rezerwą; porównanie przed i po dla W3, W5, W6, W15.

### PERF-2. Model wyszukiwania i facetów (przebudowa)

Zależy od: PERF-0. Równolegle z: PERF-1, PERF-7. Zastępuje wstrzymaną specyfikację SEARCH-FACETS.

Zakres funkcjonalny bez zmian: fasety na każdym listingu (także na stronie głównej), filtry wariantu wszędzie,
dokładne liczby produktów, warunek „ten sam wariant”.

Etap A, pomiar na indeksie 10 mln przed implementacją (wynik: raport i decyzja właściciela o modelu):

- pola facetów na dokumencie produktu i `nested` tylko dla warunków na wariant (zmierzone na 1 mln: CPU 687 → 160 ms,
  wyniki identyczne w 10 z 10 scenariuszy);
- model dla zapytań z warunkami na wariant na dużych zbiorach (kandydaci do pomiaru: warunki pojedyncze obsłużone
  polami produktu, jeden poziom `nested`, inny podział dokumentów);
- fasety osobnym zapytaniem, niezależnym od strony i sortowania, z natywnym request cache Elasticsearcha;
- dla zapytań bez filtrów: liczone na żywo wobec modelu odczytu utrzymywanego przy indeksowaniu (tylko jeśli pomiar
  wykaże, że liczenie na żywo nie mieści się w W1 i W8);
- liczba shardów dobrana do W1 i W9 jednocześnie, z kontrolą dokładności `terms`.

Etap B, implementacja wybranego modelu: mapping, zapytania, indeksowanie, kontrakt API i sklep jako konsument
(OpenAPI i klient generowany w tym samym zadaniu), reindeks.

Efekt mierzony: W1, W8, W9 na `medium` i `server`. Kryteria: W1, W8 i W9 spełnione na `server` lokalnie przy
wyłączonym Varnishu; test równoważności: fasety i liczby trafień nowego modelu identyczne z obecnym dla zestawu
zapytań z PERF-0 na danych z wieloma wariantami; test zgodności modelu odczytu z liczeniem na żywo (jeśli model
odczytu powstanie) i zdefiniowana jego świeżość (W16); porównanie przed i po dla W1, W8, W9.

Ryzyko: jeśli żaden model z etapu A nie spełni W1 dla zapytań z filtrem wariantu na całym katalogu, etap A kończy
się raportem z wariantami (model, koszt, co każdy wymaga) i decyzją właściciela. Implementacja nie rusza bez niej.

### PERF-3. Odporność na przeciążenie i izolacja ścieżek (optymalizacja z małą przebudową obsługi błędów)

Zależy od: PERF-0; część wyszukiwarki po etapie B PERF-2 (te same pliki). Części Varnisha, FrankenPHP i sklepu
równolegle z PERF-2.

- Ograniczenie współbieżności zapytań wyszukiwarki i kolejki wątków FrankenPHP, tak żeby wolna wyszukiwarka nie
  zajmowała wątków koszyka, zamówień i `/health`; limity połączeń w Varnishu.
- Varnish: grace i obsługa błędów (krótki cache błędu zamiast hit-for-miss na 120 s), miękki purge zamiast czyszczenia
  całego cache przy zmianie kategorii.
- Sklep: limit czasu wywołań API; błąd wyszukiwarki nie kończy się 500 całej strony (zabezpieczenie awaryjne).
- Nadmiar ruchu odrzucany szybko i jawnie zamiast po limicie czasu.

Efekt mierzony: W12. W testach W1–W11 odpowiedź odrzucona albo zdegradowana pozostaje niepowodzeniem; ten epik nie
poprawia tych wyników, tylko zachowanie poza przepustowością. Kryteria: W12 spełnione; podczas przeciążenia
listingu koszyk i zamówienia w normie W5–W6; po zatrzymaniu Elasticsearcha sklep odpowiada bez 500 i wraca do normy
w 60 s po jego starcie; zmiana drzewa kategorii pod ruchem nie obniża trafień cache do zera.

### PERF-4. Tryb uruchomienia API (optymalizacja)

Zależy od: PERF-1 (budżet połączeń). Równolegle z: PERF-2, PERF-3.

- Trwałe połączenie z bazą albo tryb worker FrankenPHP (wybór pomiarem; audyt z 2026-11-18: 3–7 razy większa
  przepustowość API), połączenia z Elasticsearchem i Redisem utrzymywane między żądaniami.
- Walidacja stanu między żądaniami: reset usług, pamięć w długim biegu, transakcje i blokady doradcze.

Efekt mierzony: CPU API na żądanie, W3, W5, W6, W10, W14 (wycieki). Kryteria: przepustowość strony produktu
i koszyka w API co najmniej 3 razy większa niż na linii bazowej przy tych samych czasach; W13 i W14 spełnione
(poprawność i brak wzrostu pamięci po 2 h); testy stanu między żądaniami (tożsamość, limitery, lokalizacja).

### PERF-5. Renderowanie i skalowanie sklepu (przebudowa częściowa)

Zależy od: PERF-2 (kontrakt facetów), PERF-3 (obsługa błędów w tych samych plikach sklepu).

- HTML listingów niezależny od sesji renderowany raz i unieważniany tagami (natywny mechanizm Next.js), zamiast
  renderu przy każdym wejściu; odczyty zimnej ścieżki równolegle zamiast po kolei.
- Sklep gotowy na więcej niż jedną replikę: rewalidacja do wszystkich replik albo wspólny cache, konfigurowalna
  liczba replik w chartcie. Na obecnym serwerze liczba replik wynika z pomiaru W10 i W15.
- Grupowanie rewalidacji i purge po tagach.

Efekt mierzony: W2, W10, CPU sklepu na stronę. Kryteria: W2 i W10 spełnione; zmiana ceny widoczna na wszystkich
replikach w czasie W16; zimna ścieżka nadal spełnia W1 przy wyłączonym cache HTML.

### PERF-6. Przetwarzanie zdarzeń i operacje masowe (przebudowa)

Zależy od: PERF-2 etap B (kształt dokumentu i indeksowanie). Podział kolejek i alerty mogą iść równolegle z PERF-2.

- Przetwarzanie wsadowe natywnym mechanizmem Messengera: łączenie zdarzeń tego samego produktu, `_bulk`, wiele tagów
  w jednym żądaniu purge i rewalidacji.
- Osobna kolejka e-maili; outbox nie blokuje kolejki zdarzeń; więcej procesów workera tam, gdzie pomiar pokaże zysk.
- Wsadowy reindeks kategorii; pełny reindeks w kilku procesach z zachowaniem zapisów z czasu przebudowy.
- Alert zaległości kolejki zdarzeń (wiek najstarszej wiadomości).

Efekt mierzony: W16–W19, W11. Kryteria: W16–W19 spełnione na `server` lokalnie; W13 po każdym scenariuszu
(kolejność zdarzeń tego samego produktu, brak utraconych zmian, indeks zgodny z bazą); porównanie przed i po dla
tempa zdarzeń na proces i czasu reindeksu.

### PERF-7. Zapytania panelu i mapy strony (optymalizacja)

Zależy od: PERF-0. Równolegle ze wszystkimi (kod panelu i zapytania zaplecza).

- Filtr kategorii i wyszukiwanie po nazwie: plan dobierany do liczby trafień (zmierzone 4,6–117 ms zamiast sekund),
  rozwiązanie dla fraz 1–2-znakowych bez ograniczania funkcji, wybrane pomiarem.
- Panel anuluje poprzednie żądania; lista kodów rabatowych bez pełnego liczenia użyć; granice mapy strony bez
  funkcji okna po całej tabeli.

Efekt mierzony: W7 na `medium` i `server`. Kryteria: W7 spełnione dla zestawu najgorszych przypadków z audytu
(najmniejsza i największa kategoria, fraza częsta, 2-znakowa, bez wyników, każde sortowanie); pięć równoległych
najgorszych zapytań panelu nie zmienia W1 i W5 sklepu; plany sprawdzone bez sterowania planistą.

### PERF-8. Ścieżka zapisu i równoległe zapisy (optymalizacja)

Zależy od: PERF-1. Równolegle z: PERF-2, PERF-3, PERF-7 (inne pliki: zamówienia, magazyn, migracje).

- Indeksy tabel zapisu: zdublowane, blokujące aktualizacje HOT, zbędne przy 12 indeksach `ordering_order`.
- Czas trzymania blokad w zamówieniu (kod rabatowy z limitem, popularny wariant), liczenie użyć kodu.
- Tabele kolejek i historii: rozrost przy ciągłym ruchu, retencja.

Efekt mierzony: W6, W11, W13, W14. Kryteria: W11 spełnione razem z W13; scenariusz wyprzedaży jednego wariantu
(wiele równoległych zamówień tego samego wariantu z tym samym kodem z limitem): brak nadsprzedaży, brak zakleszczeń,
p99 w normie W6; W14 bez wzrostu tabel kolejek.

### PERF-9. Akceptacja na profilu `server` (pomiar)

Zależy od: wszystkich poprzednich.

- Wdrożenie i `generate server` na worf.pl za osobnymi zgodami właściciela, pełny zestaw W1–W20, bieg W14, raport
  w `docs/reports/`, aktualizacja `docs/vision.md` i specyfikacji o zmierzony stan.

Kryterium sukcesu programu: wszystkie wymagania W1–W20 spełnione na worf.pl na profilu `server`, w jednym przebiegu
pełnego zestawu, na jednej rewizji. Wymaganie niespełnione jest wymienione w raporcie z przyczyną i decyzją
właściciela (dalsza praca, zmiana sprzętu albo zmiana wymagania).

## Kolejność i praca równoległa

| Fala | Epiki | Uwagi |
|---|---|---|
| 0 | PERF-0 | wszystko inne czeka |
| 1 | PERF-1, PERF-2 etap A, PERF-7 | różne obszary: chart i konfiguracja, pomiar modelu wyszukiwania, panel |
| 2 | PERF-2 etap B, PERF-4, PERF-8, części PERF-3 poza wyszukiwarką | PERF-4 i PERF-8 po PERF-1; kod wyszukiwarki tylko w PERF-2 |
| 3 | PERF-3 część wyszukiwarki, PERF-5, PERF-6 | po kontrakcie i kształcie dokumentu z PERF-2 |
| 4 | PERF-9 | po punkcie kontrolnym |

Punkty kontrolne (pełny zestaw benchmarków, raport przed i po): po fali 1 lokalnie, po fali 2 na worf.pl na
`medium` (kryterium: #954 nie występuje, W8 spełnione na `medium`), po fali 3 lokalnie na `server`.

Równoległość ogranicza wspólny kod aplikacji: dwa epiki nie zmieniają naraz tych samych plików. Wspólne miejsca to
wyszukiwarka API (PERF-2, PERF-3, PERF-6), pliki listingu w sklepie (PERF-2, PERF-3, PERF-5), `infra/varnish` (PERF-3,
PERF-5) i chart (PERF-1, potem każdy epik zmieniający usługę).

## Przebudowa a optymalizacja

| Zmiana | Rodzaj |
|---|---|
| model dokumentu i zapytań wyszukiwarki, fasety oddzielone od wyników, ewentualny model odczytu facetów (PERF-2) | przebudowa |
| przetwarzanie zdarzeń wsadowo, podział kolejek, reindeks w kilku procesach z zachowaniem zapisów (PERF-6) | przebudowa |
| HTML listingów poza ścieżką każdego żądania, sklep na wielu replikach (PERF-5) | przebudowa częściowa |
| tryb uruchomienia API (PERF-4) | optymalizacja o podwyższonym ryzyku (stan między żądaniami) |
| konfiguracja Postgresa i budżet pamięci (PERF-1), limity współbieżności i obsługa błędów (PERF-3), zapytania panelu (PERF-7), indeksy i blokady ścieżki zapisu (PERF-8) | optymalizacja |
| benchmarki, metryki, akceptacja (PERF-0, PERF-9) | narzędzia i pomiar |

## Pokrycie problemów audytu

| Problem audytu | Epik |
|---|---|
| P1 koszt listingu liniowy z katalogiem | PERF-2 |
| P2 załamanie przy przeciążeniu, P3 błąd wyszukiwarki jako 500, P8 czyszczenie całego cache | PERF-3 |
| P4 Postgres na ustawieniach domyślnych, P5 limity pamięci, P14 dysk, limity połączeń i pamięci usług | PERF-1 |
| P6 HTML listingów i jedna replika sklepu | PERF-5 |
| P7 kernel i połączenia przy każdym żądaniu | PERF-4 |
| P9 zdarzenia po jednym, P10 operacje masowe, P13 pełny reindeks | PERF-6 |
| P11 zapytania panelu, mapa strony | PERF-7 |
| P12 brak metryk i pomiaru zimnej ścieżki | PERF-0 |
| indeksy zapisu, blokady w zamówieniu, rozrost tabel kolejek | PERF-8 |

## Poza programem

- Zmiany workflow ASH (propozycje z sekcji 6 audytu) i nowe funkcje sklepu.
- Operacje na worf.pl bez osobnej zgody właściciela.
- Zwiększenie zasobów serwera.
