# DEMO-DATA: generator danych w skali docelowej i pomiar wydajności

Deterministyczny generator, który wypełnia sklep danymi w skali docelowej z `docs/vision.md` (10 mln produktów)
i realistycznymi danymi operacyjnymi (klienci, pracownicy, zamówienia), oraz pomiar obciążenia na tych danych na
klastrze k3d. Raport z pomiaru pokazuje, jak sklep działa przy tej skali, i wskazuje wąskie gardła do poprawy. Dane w skali trafiają
także na serwer demo (worf.pl): `docs/features/demo-scale/spec.md`.

Epik ma dwie części z osobnymi końcowymi PR, mergowane po kolei (P11): część 1 (#482) to generator i jego testy,
zaimplementowane, część 2 (#486, DEMO-LOAD) to integracja z k3d i k6 oraz przebieg `medium`: zaimplementowana, a przebieg
`medium` wykonany 2026-11-19 (raport `docs/reports/demo-load-medium-2026-11-19.md`, kalibracja w sekcji „Wyniki przebiegu `medium`”). Pomiar `full` (W3) jest odłożony (DEMO-SCALE, U1): nie odbył się.
Ta specyfikacja opisuje obie; sekcje i kryteria części 2 są oznaczone „część 2”.

Issues: #486
Zakres: `apps/api/src/DemoData`, `apps/api/tests/Unit/DemoData`, `apps/api/tests/Integration/DemoData`, `apps/api/tests/Functional/DemoData`, `apps/api/fixtures/demo-data`, `apps/api/bin/generate-demo`, `apps/api/bin/upload-demo-photos`, `apps/api/config/packages/flysystem.yaml`, `apps/api/.env`, `apps/api/phpunit.dist.xml`, `apps/api/tests/Unit/Bin`, `make/demo-data.mk`, `apps/api/deptrac.yaml`, `apps/api/config/services.yaml`, `apps/api/CLAUDE.md`, `apps/api/src/Catalog/Application/Search`, `apps/api/src/Catalog/Infrastructure/Search`, `apps/api/tests/Unit/Catalog`, `apps/api/tests/Integration/Catalog`, `docs/vision.md`, `docs/features/search/spec.md`, `infra/k8s`, `load`, `docs/features/hardening/spec.md`

## Cel i zakres

- Generator danych w skali: 500 pracowników, 1 mln klientów, 10 mln produktów i 5 mln zamówień (profil `server`,
  docelowo dla serwera demo) albo 50 mln zamówień (profil `full`); mała skala w testach i CI.
- Dane wyglądają realnie: słowniki po polsku, realistyczne rozkłady, prawdziwe zdjęcia, spójność między modułami
  (zamówienie ↔ płatność ↔ rezerwacja i stany ↔ kod rabatowy ↔ konto klienta), a panel i sklep obsługują wygenerowane
  dane tak samo jak dane z działania sklepu (wysyłka, anulowanie, zwrot, historia).
- Generator jest szybki: ładowanie hurtowe (`COPY`), bez API i bez agregatów.
- Te same opcje (ziarno, profil, chwila odniesienia) dają te same dane, więc przywrócenie danych i porównanie pomiarów
  są możliwe.
- Pomiar obciążenia (k6) na k3d na danych `server` i `full`; skrypty k6 losują produkty z całego katalogu (część 2).
- Przebudowa indeksu wyszukiwarki odporna na przejściowe błędy Elasticsearcha (warunek załadowania 10 mln produktów).

## Decyzje użytkownika

Ustalone z użytkownikiem 2026-11-13 przed przygotowaniem epiku:

- **U1. Skala.** Serwer demo: 500 pracowników, 1 mln klientów, 10 mln produktów, 5 mln zamówień. k3d na komputerze
  użytkownika: te same dane i 50 mln zamówień. Liczba zamówień jest jedynym parametrem, który różni oba profile.
- **U2. Pomiar w dwóch miejscach.** k3d (architektura, 50 mln zamówień) i serwer demo (k6 jako Job w klastrze,
  z pominięciem Cloudflare, w nocy, na żądanie). Kolejność prac: P2.
- **U3. Sposób generowania.** Bez API i bez agregatów: ładowanie hurtowe (`COPY`) z deterministycznym ziarnem
  i identyfikatorami wyliczanymi, sprawdzenie spójności po załadowaniu.
- **U4. Realizm.** Obecny katalog z `apps/api/fixtures/catalog.yaml` zostaje (seed jak dziś), a generator dokłada
  długi ogon ze słowników w repo (wygenerowanych raz, po polsku), z realistycznymi rozkładami. Dane osób z otwartych
  danych GUS, e-maile wyłącznie w domenach zarezerwowanych (`example.com`, `example.net`, `example.org`).
- **U5. Zdjęcia.** Większa pula prawdziwych zdjęć z Wikimedia Commons (CC0 1.0 i domena publiczna) poza gitem
  (w repo lista plików z autorami, licencjami i sumami kontrolnymi), obiekty w Garage współdzielone między produktami (oryginały nigdy nie są usuwane, decyzja K7
  w `docs/features/catalog-admin/spec.md`).
- **U6. Bez nocnego resetu.** Dane demo nie są resetowane co noc (zmiana #179 należy do osobnego epiku dostępu do
  demo); przywrócenie danych to świadome uruchomienie generatora.

Przyjęte przez użytkownika 2026-11-13 (rekomendacje z przygotowania epiku):

- **P1. Generator jako płaski moduł.** `DemoData` poza contextami (jak `PaymentSimulator`), z jedną warstwą Deptrac
  z dostępem tylko do `Native`, `Vendor` i `Shared_Infrastructure`. Pisze tabele wszystkich modułów przez `COPY`
  i SQL, bez importu klas contextów; reguły, które powtarza, pilnują testy kontraktu porównujące wynik generatora
  z kodem modułów (testy nie podlegają Deptrac). Teksty pod regułą tekstu (`Text`) generator pisze
  z pominięciem domeny, więc testy kontraktu wymagają, żeby teksty słowników i wygenerowane (nazwy, opisy, osie,
  wartości wariantów, tekst alternatywny, kategorie, imię, ulica, miejscowość) były w postaci, którą reguła zachowuje.
- **P1a. Reguły z warstw Domain zamiast kopii.** Warstwa `DemoData` może dodatkowo zależeć od `Catalog_Domain`,
  `Pricing_Domain` i `Ordering_Domain` (czysty PHP bez infrastruktury; `DemoData` jest ujściem, od którego nic nie
  zależy). Generator woła istniejące reguły i value objecty dla wartości (`PriceBreakdown`, `PromotionalPrice`,
  `LineAmounts`, `OrderNumber`, `Slug`), nigdy agregatów ani repozytoriów. Testy kontraktu zostają tylko dla reguł
  spoza Domain (klucz zdjęcia, `values_key`, ścieżka kategorii).
- **P2. Kolejność prac** (decyzja użytkownika z 2026-11-19, DEMO-SCALE U1).
  1. Ten epik (części 1 i 2, P11): generator, pomiar `medium` na k3d i przebudowa indeksu odporna na błędy.
  2. Dane w skali na serwerze demo: `medium`, po analizie jego wyników i osobnej zgodzie `server`
     (`docs/features/demo-scale/spec.md`, #786). Wynik jest pomiarem tego serwera i tego profilu.
  3. Epiki poprawek wąskich gardeł na podstawie raportów (ryzyka H1–H4 niżej to hipotezy do sprawdzenia) oraz
     reszta wdrożenia danych na serwerze (pomiar k6 na serwerze, kopie przy dużej bazie): #484.
  4. Pomiar `full` na k3d jest odłożony (W3).
  Ten epik nie zmienia profilu `prod` chartu; CronJob `demo-data` w `prod` opisuje DEMO-SCALE.
- **P3. Warianty na produkt.** Średnio około 3 warianty na produkt w ogonie (około 40% produktów z jednym wariantem:
  mapy, książki, żywność, akcesoria; pozostałe 2–8).
- **P4. Historia stanów magazynowych.** Ruchy stanów i rekordy rezerwacji zakończonych tylko dla zamówień
  z ostatnich 90 dni przed chwilą odniesienia; wariant z ruchami zaczyna okno ruchem bilansu otwarcia, starsze
  zamówienia nie mają ruchów (jak po archiwizacji). Aktywne rezerwacje zawsze.
- **P5. Konta wyłączone.** Wygenerowani klienci i pracownicy są w Keycloaku wyłączeni (`enabled: false`) i bez
  poświadczeń: nie da się na nie zalogować ani zamówić dla nich resetu hasła (realm `shop` ma
  `resetPasswordAllowed`, a poczta Keycloaka w `prod` idzie przez Brevo). Istnieją jako tożsamości, na które wskazują
  zamówienia i historia działań.
- **P6. Pula zdjęć.** 4317 plików JPEG z Wikimedia Commons (tylko CC0 1.0 i domena publiczna: licencje bez wymogu
  podania autora i bez zakazu zmian), w tonacji obecnych zdjęć, przypisanych do 20 grup zdjęć typów produktów (120–258 na
  grupę, około 216 średnio). To 1142 prawdziwe zdjęcia i ich warianty (odbicie lustrzane, kadry 80%), przeskalowane
  do 1600 px; każdy wariant to osobny plik, więc osobny oryginał dla imgproxy i Varnisha. Commons zamiast Unsplash i Pexels: Pexels wstrzymał nowe klucze API, a regulamin API Unsplash wymaga
  hotlinkingu, więc ich zdjęć nie wolno pobrać do własnej puli. Pula ma tyle plików, żeby na stronie listy jednego typu (24 produkty)
  zdjęcia rzadko się powtarzały, a pomiar obejmował realną liczbę różnych oryginałów w imgproxy i Varnishu; archiwum w prywatnym magazynie
  (P10), w repo manifest z autorami, licencjami i sumami kontrolnymi. Testy i profil `small` używają 37 obecnych zdjęć
  z `apps/api/fixtures/images`.
- **P7. Okres historii zamówień.** 3 lata przed chwilą odniesienia, z rosnącym ruchem i sezonowością.
- **P8. Bez danych „w toku”.** Generator nie tworzy zamówień nieopłaconych (`new`) ani aktywnych promocji: zamówienie
  `new` wygasa przez zaplanowaną wiadomość `ExpireUnpaidOrder` (bez niej dopiero przez siatkę bezpieczeństwa
  `ordering:expire-overdue`, CronJob `order-expiry` w klastrze, `docs/features/async-reliability/spec.md`, W5), a koniec
  promocji przez wiadomość granicy (`docs/features/promotions/spec.md`), a generator wiadomości nie planuje. W danych
  są zamówienia opłacone i dalej oraz anulowane, promocje zakończone; aktywne promocje i kody z seeda działają jak dziś.
- **Zgłoszenie sekretu.** Rozdzielenie sekretu administratora Keycloaka od sekretu aplikacji: #485.
- **P9. Konta w Keycloak zostają w tym epiku.** Recenzja architektury proponowała rezygnację, bo w tym epiku kont nic
  nie czyta (API bierze tożsamość z tokenu, zamówienia mają kopię danych klienta, k6 się nie loguje); konta zostają,
  bo skala Keycloaka (1 mln klientów, 500 pracowników) jest częścią demo (U1), a W1 wymaga ich spójności z danymi.
- **P10. Prywatny magazyn zdjęć.** Pula nie jest publikowana jako ogólnodostępne archiwum (bez wydania GitHub), żeby
  nie redystrybuować kolekcji zdjęć z Commons. Archiwum leży w prywatnym buckecie Cloudflare R2 (konto użytkownika, jak
  kopie zapasowe), w niezmiennych wersjach, z weryfikacją SHA-256 (sekcja „Zdjęcia”). W repo zostaje manifest.
- **P11. Dwa końcowe PR.** Jeden epik w specyfikacji, z kryteriami i kolejnością zależności,
  dostarczany w dwóch częściach mergowanych po kolei, każda z własnym końcowym PR i własnymi kontrolami CI: część 1
  (#482) generator i jego testy, część 2 (#486, DEMO-LOAD) integracja z k3d, k6 i przebieg
  `medium`. AO prowadzi epik jako jedną gałąź i jeden końcowy PR, więc część 2 ma własne issue
  i plan, przygotowany po merge'u części 1.

Wymagania dodatkowe użytkownika (2026-11-13):

- **W1. Konta przy ponownym generowaniu.** Ponowne generowanie z innym ziarnem, profilem albo wersją słowników nie
  zostawia niespójnych danych ani starych kont: generator aktualizuje albo usuwa w kontrolowany sposób wyłącznie
  konta, które sam utworzył; prawdziwych kont nie zmienia ani nie usuwa. Własność konta jest oznaczona trwale
  i niezależnie od ziarna, także po częściowo nieudanym imporcie; generator nie usuwa konta, którego własności nie
  potwierdzi (sekcja „Konta w Keycloak”).
- **W2. Zapas miejsca (część 2).** Przed generowaniem w profilach `medium`, `server` i `full` na k3d cel wymaga wolnego miejsca
  co najmniej 25% ponad przewidywane szczytowe zużycie i odmawia startu bez niego; w trakcie przerywa pracę, zanim
  dysk się zapełni (sekcja „k3d”).
- **W3. Pełna skala jest niezweryfikowana.** Przebieg `medium`, przebieg na serwerze i ekstrapolacja nie zastępują
  pomiaru `full` (10 mln produktów, 50 mln zamówień na k3d), który jest odłożony (DEMO-SCALE, U1). Ani dokumentacja,
  ani PR, ani raport nie przedstawiają pełnej skali jako zweryfikowanej: wyniki `medium` są opisane jako `medium`,
  przebieg `server` na worf.pl jako pomiar tego serwera, a rozmiary i czasy `server` na innym sprzęcie i `full`
  jako ekstrapolacja niezweryfikowana.

## Poza zakresem

- Dostęp do demo (rezygnacja z nocnego resetu w #179, rola tylko do odczytu, konta dla rekruterów, Cloudflare
  Access przed panelem, #119, #332): osobny epik.
- Poprawki wąskich gardeł wykrytych pomiarami (P2, krok 3): osobne epiki z raportów.
- Generowanie danych w skali na serwerze demo i sekret odczytu puli zdjęć w klastrze serwera: DEMO-SCALE
  (`docs/features/demo-scale/spec.md`). Pomiar k6 na serwerze i kopie zapasowe przy dużej bazie: #484.
- Pomiar `full` na k3d: odłożony (P2, W3).
- Profil `prod` chartu poza CronJobem `demo-data` z DEMO-SCALE: bez zmian w tym epiku.
- Obciążenie 60 tys. jednocześnie aktywnych użytkowników.
- Koszyki (dane przejściowe), kolejki Messengera i powiadomienia e-mail zamówień: generator ich nie tworzy.
- Ochrona przed dwoma równoległymi przebiegami generatora (lokalnie uruchamia go jedna osoba, na k3d cel tworzy
  jeden Job w jednorazowym klastrze).
- Rozdzielenie sekretu administratora Keycloaka od sekretu aplikacji: #485 (stan obecny w „Modelu zagrożeń”).

## Dane

### Profile

Profil to zestaw liczb w `apps/api/fixtures/demo-data/profiles.yaml`; ziarno (UUID przestrzeni nazw) i stały
rozmiar części są w tym pliku wspólne dla wszystkich profili, więc profile z tymi samymi liczbami (np. konta i katalog
`server` i `full`) dają te same dane. Liczby i ziarno można nadpisać opcjami komendy.

| Profil | Pracownicy | Klienci | Produkty (ogon) | Zamówienia | Gdzie |
|---|---|---|---|---|---|
| `small` | 20 | 1 000 | 2 000 | 5 000 | testy API, test dymny k3d |
| `medium` | 50 | 100 000 | 1 000 000 | 1 000 000 | zadanie epiku (k3d), lokalnie |
| `server` | 500 | 1 000 000 | 10 000 000 | 5 000 000 | k3d (później serwer demo) |
| `full` | 500 | 1 000 000 | 10 000 000 | 50 000 000 | k3d |

Rozmiary `medium` są zmierzone (raport `docs/reports/demo-load-medium-2026-11-19.md`) dla generatora bez pól treści produktów
(marki, opisu, parametrów, SKU i EAN): produkt z wariantami, cenami, historią cen i stanem zajmuje około 5,4 KB (tabele
produktowe 5,42 GB na 1 mln produktów), a pola treści i indeks SKU dodają do tabel katalogu około 1,0 KB na produkt
z wariantami (pomiar w „Rozmiar i czas pól treści produktów”, niżej), zamówienie ze wszystkimi tabelami
zamówieniowymi około 3,4 KB (3,44 GB na 1 mln zamówień), indeks Elasticsearcha około 1,06 GB na 1 mln produktów.
Rozmiary `server` i `full` to ekstrapolacja liniowa z tych liczb (bez pól treści; z nimi tabele katalogu rosną o około
10 GB, szacunek niżej), niezweryfikowana pomiarem:

| Profil | Postgres (ekstrapolacja) | Elasticsearch (ekstrapolacja) | Keycloak (ekstrapolacja) |
|---|---|---|---|
| `server` | około 73 GB | około 10,6 GB | około 1,5 GB |
| `full` | około 227 GB | około 10,6 GB | około 1,5 GB |

Rzeczywiste rozmiary `server` na serwerze demo poda raport z jego przebiegu (DEMO-SCALE); `full` nie ma pomiaru.

### Realizm

- **Katalog.** Obecne ~200 produktów z seeda zostają bez zmian (E2E, `catalog.yaml`). Ogon powstaje ze słowników
  w `apps/api/fixtures/demo-data/`: drzewo kategorii rozszerzone do około 100 kategorii końcowych w tych samych
  działach, około 120 typów produktów (osie wariantów, klasa podatkowa, przedział cen, grupa zdjęć), około 300
  fikcyjnych marek, nazwy modeli i fragmenty opisów po polsku. Nazwa jak dziś: „<typ> <marka> <model>”, slug ze
  slugify nazwy z sufiksem z globalnego indeksu produktu, więc unikalny z konstrukcji, niezależnie od części i liczby
  procesów, i rozłączny ze slugami `catalog.yaml` (sprawdza to `verify`). Krótkie opisy z fragmentów cech typu, bez tekstu zastępczego; marka, długi opis, parametry i kody wariantów: „Treść produktów i kody wariantów”. Około
  2% produktów to szkice, co 50. produkt bez zdjęcia (jak dziś), pozostałe 1–3 zdjęcia z grupy typu.
- **Ceny.** Przedział brutto per typ, z drabinki cen detalicznych (końcówki ,99 i ,00), warianty jednego produktu
  w tej samej cenie albo z dopłatą za rozmiar; netto i VAT według reguły Pricing (`PriceBreakdown`), stawka z klasy
  podatkowej. Historia: wpis początkowy (źródło `seed`) przed pierwszym zamówieniem wariantu, u około 30% wariantów
  zmiana w ostatnich 90 dniach (źródło `staff`, aktor z wygenerowanych pracowników `admin`). Kilkadziesiąt
  zakończonych promocji (każda do 1000 wariantów, okresy bez nakładania się na wariancie; P8). Cena wariantu
  w chwili `t` (cena skuteczna) wynika z historii i promocji: ostatni wpis historii przed `t`, obniżony o promocję
  trwającą w `t` (reguła `PromotionalPrice`).
- **Stany.** Stan fizyczny per wariant z rozkładu typu; około 8% wariantów bez stanu (poza wariantami z aktywnymi
  rezerwacjami).
- **Klienci.** Imiona i nazwiska z list częstości GUS (dane.gov.pl, rejestr PESEL), z wagami; miasta z poprawnymi
  kodami pocztowymi i nazwy ulic ze słownika; telefon w formacie polskim; e-mail klienta
  `<imię>.<nazwisko>.<n>@example.(com|net|org)` bez polskich znaków (`n` to numer konta), gościa
  `<imię>.<nazwisko>.g<n>@example.(com|net|org)` (`n` to numer zamówienia). Klient w Keycloak (realm `shop`, e-mail
  jest nazwą użytkownika) i jego zamówienia mają te same dane. Źródła i licencje słowników w `apps/api/fixtures/demo-data/CREDITS.md`.
- **Pracownicy.** 500 kont w realmie `backoffice` (nazwa użytkownika `<imię>.<nazwisko>.<n>`): 10 `admin`,
  200 `support`, 200 `warehouse`, 90 `marketing` (rola jest funkcją numeru konta); aktorzy historii z rolą, która pozwala na daną czynność
  (`docs/features/order-management/spec.md`, P6: wysyłka i dostarczenie `admin` albo `warehouse`, anulowanie, zwrot
  i rozliczenie zwrotu `admin` albo `support`; zmiana ceny `admin`).
- **Zamówienia.** Okres 3 lat przed chwilą odniesienia (P7), ruch rosnący z miesiąca na miesiąc, sezonowość według
  działów (narty zimą, kajaki latem, szczyt w listopadzie i grudniu), pora dnia. Tylko produkty z ogona. Około 60%
  zamówień klientów, 40% gości; liczba zamówień klienta z rozkładu o długim ogonie (większość 1–3, nieliczni ponad
  50), część klientów bez zamówień. 1–4 linie, ilości 1–3, tylko warianty produktów opublikowanych przed złożeniem
  (szkice nigdy). Popularność wariantów z rozkładu Pareto (20% produktów to około 80% sprzedaży). Cena jednostkowa
  linii to cena skuteczna wariantu w chwili złożenia; linia w okresie promocji ma kopię promocji
  (`promotion_*`, `regular_unit_gross`); rabat kodu dzielony na linie według `LineAmounts`. Statusy według wieku (P8): starsze niż 30 dni głównie `delivered`, około 4% `returned`
  (zwrot należny po 14 dniach, rozliczony do 2 dni po terminie, jeśli to przed chwilą odniesienia), około 6% `cancelled` (nieopłacone po terminie, z historią
  `system` i zwolnionym użyciem kodu, albo anulowane przez pracownika; anulowanie po opłaceniu ma zwrot rozliczany jak
  zwrot); ostatnie dni: `paid`, `shipped`, `delivered`. Token dostępu zamówienia gościa i klucze idempotencji
  zamówień i płatności są losowe (bezpieczny generator, nie z ziarna), bo ziarno jest publiczne, a złożenie zamówienia
  z tym samym kluczem idempotencji zwraca istniejące zamówienie.
  Około 10% zamówień z kodem rabatowym: kilkanaście historycznych kodów tworzy generator (zakończone, okresy zgodne
  z datami zamówień), kody z seeda nie są używane. Kwoty linii, rabatów i sum spełniają reguły Ordering
  (`LineAmounts`, `CHECK` w tabeli). Opłacone zamówienie ma płatność (`demo-transfer` albo `demo-card`, `succeeded`,
  ta sama kwota); zamówienie anulowane przez pracownika po opłaceniu ma zwrot zgodny z `refund_check`.
- **Historia działań.** Zmiany statusów z aktorem (`customer` przy złożeniu, `system` przy opłaceniu i wygaśnięciu, `staff`
  przy wysyłce, dostarczeniu, anulowaniu i zwrocie), w kolejności i czasie zgodnym z kolumnami zamówienia.

## Generator

### Moduł i komendy (P1)

- Płaski moduł `apps/api/src/DemoData` (warstwa Deptrac `DemoData`: `Native`, `Vendor`, `Shared_Infrastructure`,
  `Catalog_Domain`, `Pricing_Domain`, `Ordering_Domain`; P1a).
  Żaden context od niego nie zależy, on od żadnego contextu.
- Skrypt `apps/api/bin/generate-demo` (`sh`, `set -eu`, jak `bin/seed-demo`): najpierw sprawdzenie opcji i, dla profilu
  innego niż `small`, puli zdjęć (`demo-data:products --list-parts`, `demo-data:photos --preflight`: nic nie jest jeszcze
  kasowane), potem `bin/seed-demo` (reset i katalog z `catalog.yaml`), kroki generatora, `catalog:reindex`,
  `storefront:cache:purge-all`. Profil (`--profile`, domyślnie `small`), chwila odniesienia (`--anchor`) i liczba
  procesów (`--processes`) z argumentów; w `prod` wymaga `--force-reset` jak seedy. Błąd kroku przerywa skrypt
  z niezerowym kodem; przerwane generowanie zaczyna się od nowa (reset seedów to `TRUNCATE`).
- Kroki (komendy `demo-data:*`) w kolejności:
  1. katalog: kategorie, produkty, warianty, przypisania kategorii;
  2. zdjęcia: archiwum puli do Garage i klucze zdjęć produktów;
  3. ceny z historią, zakończone promocje i historyczne kody rabatowe;
  4. konta w Keycloak;
  5. zamówienia z liniami, historią statusów, płatnościami i użyciami kodów;
  6. stany, rezerwacje i ruchy stanów: jedno przejście SQL po zamówieniach (sekcja „Stany po zamówieniach”);
  7. sekwencja numerów zamówień (`setval` powyżej najwyższego numeru);
  8. modele odczytu i statystyki, których kroki 1–6 z pominięciem komend nie wypełniają: `ordering:read-models:rebuild`
     (sprzedaż godzinowa i klienci panelu z zamówień) i `ANALYZE` wypełnionych tabel (pulpit liczy produkty ze
     statystyk `catalog_product`);
  9. `demo-data:verify`.
- Kroki 1, 3 i 5 dzielą dane na części o stałym rozmiarze z `profiles.yaml` (niezależnym od liczby procesów);
  skrypt uruchamia części równolegle (liczba procesów z opcji, domyślnie 4), czeka na każdy proces osobno, a błąd
  jednej części zatrzymuje pozostałe i kończy krok kodem różnym od 0. Każda część ma własny strumień liczb
  pseudolosowych z ziarna i numeru części.
- Ładowanie przez `COPY FROM STDIN` (natywne `Pdo\Pgsql::copyFromArray` na połączeniu DBAL), paczkami, do tabel
  z indeksami z migracji (bez usuwania indeksów: pomiar na 1 mln produktów i 1 mln zamówień dał 2–3 razy wolniejsze
  ładowanie niż bez indeksów, a przerwanie nie zostawia bazy bez indeksów). Sesja ładowania ustawia
  `synchronous_commit = off`. Schematu generator nie zmienia.
- Generator nie publikuje zdarzeń (outbox) i nie planuje wiadomości; dane „w toku”, które ich wymagają, nie powstają
  (P8). Indeks wyszukiwarki i cache sklepu odświeża końcówka skryptu. To jawny wyjątek od „zdarzenia w tej samej
  transakcji co agregat”, ograniczony do danych demo.
- Wyjątek od persystencji przez rekordy ORM (`apps/api/CLAUDE.md`): `DemoData` pisze przez `COPY` i SQL. Tabele, które
  generator wypełnia: `catalog_category`, `catalog_product`, `catalog_product_variant`, `catalog_product_category`
  (krok 1), kolumnę `images` `catalog_product` (krok 2, `UPDATE` z tabeli tymczasowej), `pricing_variant_price`,
  `pricing_price_history` (krok 3, części), `pricing_promotion`, `pricing_promotion_variant` i `pricing_discount_code`
  (krok 3, jeden proces), `ordering_order`, `ordering_order_line`, `ordering_order_status_change`, `payment_payment`
  i `pricing_discount_code_usage` (krok 5, części), `inventory_reservation`, `inventory_reservation_line`,
  `inventory_stock_movement`, `inventory_stock_item` (krok 6, SQL) i sekwencję `ordering_order_number_seq` (krok 7,
  `setval`). Nie pisze `ordering_order_notification`, `payment_webhook_event`, tabel symulatora płatności,
  `catalog_category_redirect`, outboxu ani kolejek.

### Treść produktów i kody wariantów (krok 1)

Każdy wygenerowany produkt dostaje pola treści karty (`docs/features/product-data/spec.md`, „Dane demo” → „Generator”),
a każdy wariant kody; wszystkie wartości są w postaci, którą zachowują reguły zapisu Catalog (`ProductBrand`,
`ProductDescription`, `ProductParameter`, `Sku`, `Ean`), bo generator pisze przez `COPY`, poza domeną
(`GeneratedText` i testy kontraktu w `CatalogStepsTest`, `ProductGeneratorTest`, `DictionariesTest`).

- **Marka:** ta, którą generator losuje do nazwy produktu; nie ma osobnego losowania.
- **Opis:** 2 albo 3 akapity rozdzielone pustym wierszem (`\n\n`), 300–600 znaków, inny niż krótki opis. Budowa:
  zdania działu z `descriptions.yaml` (`sentences`, od 9 na dział, każde 80–115 znaków) i akapit „Cechy: …” z trzech cech
  typu produktu (`features` w `product-types.yaml`) w losowej kolejności. Dwa akapity to trzy zdania i akapit cech, trzy
  akapity to dwa zdania, akapit cech i dwa zdania; zdania są losowane bez powtórzeń. Zdania nie mają słów, które
  musiałyby zgadzać się gramatycznie z nazwą produktu (zaimków ani czasowników i przymiotników odnoszących się do
  produktu), bo produkt bywa liczby pojedynczej i mnogiej, każdego rodzaju. Budżet długości wynika z długości zdań i cech:
  `DictionariesTest` liczy go dla każdego typu przy najkrótszych i najdłuższych zdaniach działu.
- **Parametry:** 3–6 par nazwa–wartość z zestawu typu: `parameters.yaml` ma zestawy (każdy co najmniej 6 parametrów,
  każdy parametr co najmniej 3 różne wartości), a typ w `product-types.yaml` wskazuje jeden (`parameters:`). Produkt
  bierze losowo od 3 do 6 parametrów zestawu (w kolejności zestawu) i jedną wartość każdego. Nazwa parametru nie jest nazwą
  osi wariantów typu (bez względu na wielkość liter): sprawdza to ładowanie słownika (błąd z nazwą typu i parametru)
  i `DictionariesTest`.
- **SKU** (`VariantCodes::sku`): numer produktu (indeks + 1, ten sam, którym kończy się slug) dopełniony zerami do 8
  cyfr, `-` i numer wariantu (pozycja + 1), np. `00001235-3`. Zaczyna się cyfrą (SKU z seeda literą, więc zbiory są
  rozłączne), ma do 40 znaków alfabetu SKU i jest unikalny z konstrukcji, bez odczytów z bazy. Wiersze jednej części
  trafiają do sąsiednich stron indeksu unikalnego `catalog_product_variant_sku_key`, który zostaje na czas ładowania.
- **EAN** (`VariantCodes::ean`): EAN-13 `2` + numer produktu (9 cyfr) + numer wariantu (2 cyfry) + cyfra kontrolna GS1.
  Prefiks `2` to pula numerów obiegu ograniczonego (fikcyjny produkt nie nosi kodu prawdziwego towaru), więc sklep nie
  pokazuje dla niego `gtin`. Numer produktu albo wariantu, który nie mieści się w kodzie, to wyjątek, nie nieważny kod.
- **Determinizm.** Markę, opis i parametry losuje osobny strumień (`catalog-content`, ta sama część), więc nie przesuwa
  żadnego losowania strumienia `catalog`: identyfikatory, slugi, nazwy, krótkie opisy, warianty i ceny profilu są takie
  jak przed dodaniem pól (`GeneratedCatalogDigestTest` trzyma skróty tych kolumn profilu `small`), a nowe pola
  nie zależą od liczby procesów (`ProcessCountDeterminismTest`: 1, 3 i 4 procesy).

### Identyfikatory, czas i determinizm

- Chwila odniesienia: opcja `--anchor` (domyślnie początek bieżącej doby UTC). Wszystkie czasy generatora liczą się
  od niej, nie od zegara.
- Kategorie, produkty, warianty, konta, promocje, historyczne kody: UUID v5 z nazw (`category:<ścieżka slugów>`,
  `product:<i>`, `variant:<i>:<k>`, `customer:<odcisk>:<n>`, `staff:<odcisk>:<n>`, `promotion:<i>`, `code:<n>`) w przestrzeni
  z `profiles.yaml`, innej niż `catalog.yaml`.
- Zamówienia, linie, płatności, historia cen, rezerwacje i ruchy stanów: UUID v7 z czasem zdarzenia i bitami
  pseudolosowymi z ziarna (rosnące w czasie, jak w aplikacji).
- Zamówienie ma globalny indeks `i`; część to ciągły zakres indeksów. Czas złożenia jest rosnącą funkcją `i`
  (odwrotność rozkładu ruchu w czasie z sezonowością), numer to `R-<pierwsza wartość sekwencji + i>`, a klient
  i warianty zamówienia wynikają z `i` i strumienia części. Numeracja zgodna z czasem nie wymaga przebiegu po
  załadowaniu; krok 7 tylko ustawia sekwencję za ostatnim numerem.
- Wiersze z `bin/seed-demo` mają czas seeda, a tokeny dostępu gości i klucze idempotencji są losowe; determinizm
  dotyczy pozostałych wierszy i kolumn generatora.

### Stany po zamówieniach

Krok 6 to SQL po załadowaniu zamówień (`INSERT … SELECT` z sumami narastającymi po wariancie):
- aktywna rezerwacja (klucz = id zamówienia, status `active`, linie) dla każdego zamówienia `paid`; w oknie 90 dni
  rezerwacja `committed` dla zamówień wysłanych i `released` dla anulowanych (okno to zamówienia złożone po jego
  początku: zamówienie z chwili początku okna, czyli chwili bilansu otwarcia, jest „zarchiwizowane”);
- `reserved` wariantu = suma linii aktywnych rezerwacji; `physical` = stan z rozkładu, nie mniejszy niż największa
  suma rezerwacji jednocześnie aktywnych w oknie (więc żaden ruch nie narusza `physical >= reserved`, a bilans
  otwarcia wariantu z zamówieniami w oknie jest dodatni); około 8% wariantów bez zamówień w oknie ma stan 0;
- ruchy w oknie: bilans otwarcia (`receipt`, źródło `seed`, `reference` = `opening:<variantId>`) na początku okna,
  potem `reservation` przy złożeniu, `commit` przy wysyłce albo `release` przy anulowaniu (źródło `system`,
  `reference` = klucz rezerwacji, jak w aplikacji), z `physical_after`
  i `reserved_after` z sum narastających; bilans otwarcia = stan końcowy + zatwierdzone w oknie; ostatni ruch zgadza
  się ze stanem;
- warianty bez ruchów w oknie: rekord stanu bez historii (jak `CreateEmptyStock`) albo przyjęcie `seed` przy stanie
  > 0 (jak `inventory:seed-demo`), więc rekord stanu (z `product_id`) ma każdy wariant, także bez zamówień: kolejki
  i filtr poziomu stanów pulpitu czytają tylko rekordy stanów.

### Spójność (sprawdzenie po załadowaniu)

`demo-data:verify` (ostatni krok skryptu, też osobno) kończy się kodem 1 i listą naruszeń, gdy:
- opublikowany produkt nie ma wariantu albo wariant nie ma ceny lub rekordu stanu;
- `reserved` wariantu różni się od sumy aktywnych linii rezerwacji, ostatni ruch wariantu nie zgadza się ze stanem
  albo zamówienie `paid` nie ma aktywnej rezerwacji;
- suma linii zamówienia minus rabat plus koszt dostawy (`delivery_gross`) różni się od sumy zamówienia, sumy netto,
  VAT i brutto linii z `delivery_net`, `delivery_vat` i `delivery_gross` różnią się od sum zamówienia, opłacone
  zamówienie nie ma płatności z tą kwotą albo zamówienie z kodem nie ma użycia kodu (wygenerowane zamówienia nie mają
  metody dostawy, ich dostawa to 0);
- cena jednostkowa linii różni się od ceny skutecznej wariantu w chwili złożenia albo linia w okresie promocji nie ma
  jej kopii;
- zamówienie klienta wskazuje klienta spoza wygenerowanych kont;
- historia zamówienia nie kończy się jego statusem;
- bieżąca cena wariantu nie jest ostatnim wpisem jego historii, a netto, VAT i stawka ceny różnią się od reguły Pricing;
- wygenerowana promocja nie jest zakończona przed chwilą odniesienia, ma ponad 1000 wariantów, nakłada się na inną
  promocję na wariancie albo jej wariant nie miał ceny w chwili startu lub zmienił cenę w jej trakcie; liczba promocji
  albo kodów różni się od planu, albo kod nie jest zakończony;
- liczby w tabelach różnią się od profilu, kategorie ogona są niekompletne, slug produktu z ogona nie jest unikalny
  (albo slug z `catalog.yaml` należy do innego produktu);
- wygenerowany produkt nie ma marki, opisu albo parametrów (naruszenia „A generated product has no brand”, „… no
  description”, „… no parameters”) albo ma parametr bez nazwy lub wartości („… has a parameter without a name or a
  value”) albo wygenerowany wariant nie ma SKU albo EAN („A generated variant has no SKU”, „… no EAN”); każde z nich
  wymienia pierwsze slugi produktów. Brak wartości to `NULL`, pusty tekst albo tekst bez znaku widocznego według T3
  reguły tekstu (`BlankText`: PostgreSQL nie ma klas Unicode, więc wzorzec wylicza niewidoczne punkty kodowe, a test
  porównuje go z `Text` dla każdego z nich), nie tylko `NULL`: odbiorca API nie odróżnia ich od siebie. Produkty
  z `catalog.yaml` i ich warianty są pominięte (seed pisze własną treść i kody). Każde z dwóch sprawdzeń to jedno
  przejście po tabeli (liczniki), a produkty nazywa dopiero drugie zapytanie, gdy licznik jest dodatni;
- w którymś realmie nie ma grupy `demo-data-generated` albo jej członkowie to nie dokładnie zbiór docelowy z bieżącymi
  danymi (opcja `--no-accounts` pomija sprawdzenia kont).
W `small` sprawdzenie ceny linii obejmuje wszystkie zamówienia, w większych profilach deterministyczną próbkę
(jedno zamówienie na `zamówienia / 100000`, według indeksu); zgodność bieżącej ceny z historią obejmuje wszystkie
warianty w `small`, w większych jeden na `produkty / 300000`, a rozbicie netto i VAT próbkę 2000 cen; pozostałe
sprawdzenia są pełne.
Ograniczenia `CHECK` w tabelach odrzucają wiersz już przy `COPY`.

### Konta w Keycloak

- Krok kont tworzy pracowników (realm `backoffice`, z rolą) i klientów (realm `shop`) przez REST administratora
  Keycloaka (`partialImport`, `ifResourceExists=SKIP`, paczki po 2000, 4 strumienie), z identyfikatorem wyliczonym.
  Eksperyment na Keycloak 26.6.0 z Postgresem: identyfikator z importu zostaje, około 770 kont/s na komputerze
  użytkownika (1 mln w około 22 min).
- Żądania kroku do Keycloaka idą własnym klientem HTTP (nie domyślnym klientem aplikacji z limitem 5 s na żądanie):
  `partialImport` paczki to jedna transakcja Keycloaka i nie odpowiada, dopóki się nie skończy, a na klastrze przy
  4 strumieniach trwa dłużej niż 5 s. Limit bezczynności żądania to 120 s, limit całego żądania 300 s (parametry
  `app.demo_data.keycloak_idle_timeout` i `app.demo_data.keycloak_max_duration` w `config/services.yaml`), więc
  zawieszony Keycloak nadal kończy krok błędem.
- Konta wyłączone i bez poświadczeń (P5). Adres Keycloaka, login i hasło administratora z opcji albo zmiennych
  (`DEMO_DATA_KEYCLOAK_URL`, domyślnie `http://keycloak:8080`; login domyślnie `admin` jak w compose i Jobie
  `keycloak-config`; hasło z `KC_BOOTSTRAP_ADMIN_PASSWORD` sekretu stosu). Nazwy realmów są opcjami kroku
  (domyślnie `shop` i `backoffice`).
- **Własność (W1).** W każdym realmie generator zakłada (jeśli jej nie ma) grupę `demo-data-generated` z atrybutem
  opisu „konta generatora danych demo; nie dodawaj do niej innych kont”. Każde konto generatora należy do tej grupy od
  chwili utworzenia: przypisanie jest w tej samej reprezentacji konta w `partialImport`, a paczka jest zapisywana
  w jednej transakcji (eksperyment na Keycloak 26.6.0: paczka odrzucona przez błąd nie tworzy żadnego konta), więc nie
  ma konta generatora bez oznaczenia. Członkostwo nie zależy od ziarna ani od formatu nazwy; atrybutów użytkownika
  generator nie używa (Keycloak 26 domyślnie nie przechowuje atrybutów spoza profilu użytkownika, a realmów nie
  zmieniamy). Członkostwo nadaje wyłącznie generator; konto dodane do grupy ręcznie generator uzna za swoje.
- **Identyfikator niesie dane.** Identyfikator konta to UUID v5 z przestrzeni nazw i klucza `customer:<odcisk>:<n>`
  albo `staff:<odcisk>:<n>`, gdzie odcisk to skrót słowników osób i reguły ról. Rola pracownika jest funkcją samego
  `n` (stały cykl 10 `admin` / 200 `support` / 200 `warehouse` / 90 `marketing` na każde 500 numerów), nie liczby kont.
  Ten sam identyfikator oznacza więc zawsze te same dane, a zamówienia liczą `customer_id` tą samą funkcją.
- **Reconciliacja (W1).** Każde uruchomienie kroku robi to samo, niezależnie od poprzedniego przebiegu (inna
  przestrzeń nazw, liczba kont albo odcisk, przerwany krok, paczka odrzucona w połowie importu):
  1. zapewnia grupę;
  2. zbiera pełną listę członków grupy (stronami, bez zmian w trakcie) i wybiera spoza zbioru docelowego;
  3. usuwa wybranych po kolei, każde konto dopiero po odczycie jego grup i potwierdzeniu członkostwa (konto spoza
     grupy pomija i zgłasza, 404 pomija); bez grupy w realmie nic nie jest usuwane;
  4. importuje brakujące konta (`ifResourceExists=SKIP`) i sprawdza każdy wynik `SKIPPED`: konto pominięte, które nie
     należy do grupy albo ma inny identyfikator niż wyliczony, to konflikt.
  Po kroku realm ma dokładnie zbiór docelowy jako członków grupy, a konta spoza grupy są bez zmian.
- **Konflikt.** Nazwa użytkownika albo e-mail konta generatora zajęte przez konto spoza grupy (wynik `SKIPPED`)
  albo kolizja identyfikatora (paczka odrzucona, 409) kończy krok błędem z listą konfliktów; cudzego konta generator
  nie zmienia.
- Reset seedów kont nie usuwa (jak dziś); spójność kont z zamówieniami zapewnia to, że oba powstają z tej samej
  przestrzeni nazw, klucza i słowników w jednym przebiegu skryptu.

### Zdjęcia (P6, P10)

- **Magazyn.** Prywatny bucket Cloudflare R2 użytkownika (bez publicznego dostępu i bez domeny publicznej), np.
  `shop-demo-photos`. Wersja puli to niezmienny obiekt `demo-photos/v<N>/photos.tar` (R2 nie ma wersjonowania obiektów
  S3, więc wersjonowanie to niezmienne klucze): wysyłka nie nadpisuje istniejącego klucza, a nowa pula to nowa
  wersja.
- **Manifest w repo.** `apps/api/fixtures/demo-data/photos.yaml`: wersja, klucz obiektu i lista plików z SHA-256
  pliku (archiwum to tylko transport), grupą typu, tekstem alternatywnym, `sourceId`, `variant`, stroną źródła, autorem i licencją. `sourceId` to
  `commons:<id strony pliku>` (dodatnia liczba całkowita), `variant` to `original`, `mirror`, `crop-left`,
  `crop-right`, `crop-top`, `crop-bottom`, `crop-center`, `mirror-crop-left`, `mirror-crop-right` albo
  `mirror-crop-center`, źródło to strona pliku `https://commons.wikimedia.org/wiki/File:…`, a licencja to `CC0 1.0`
  albo `Public domain`. Para `sourceId` i `variant` jest unikalna (to samo zdjęcie może wystąpić w wielu wariantach,
  każdy wariant ma własny plik i sumę). Schemat jest zamknięty: nieznany klucz to błąd. Bez adresu bucketu i bez
  danych dostępu.
- **Dostęp.** Dwa tokeny API R2 ograniczone do tego bucketu: zapis i odczyt dla wysyłki puli, tylko odczyt dla
  generatora. Endpoint, nazwa bucketu i klucze są w pliku użytkownika poza repo (domyślnie
  `~/.config/shop/demo-photos.env`, zmienne `DEMO_PHOTOS_*`); narzędzia czytają go bez wypisywania wartości. W CI
  i profilu `small` dostęp nie jest potrzebny.
- **Pobieranie.** Krok zdjęć dla profili innych niż `small` pobiera archiwum przez osobny filesystem Flysystem
  (adapter AsyncAws S3, już w zależnościach) z danymi odczytu ze zmiennych `DEMO_PHOTOS_*`, sprawdza SHA-256 każdego
  pliku i komplet plików z manifestu (niezgodność: błąd bez zmian w Garage i w bazie; archiwum około 1,6 GB rozpakowane
  do katalogu tymczasowego usuwanego po kroku, także po błędzie; opcja `--preflight` sprawdza tylko zmienne, manifest
  i obecność archiwum w buckecie, bez zmian), zapisuje każdy plik raz do Garage pod kluczem
  `originals/<sha256>.<rozszerzenie>` (format `FlysystemProductImageStorage`; test kontraktu) i przypisuje produktom
  klucze z grupy typu. W trakcie pobierania na dysku są dwie kopie archiwum (strumień `php://temp` adaptera i
  `photos.tar`), a szczyt miejsca w katalogu tymczasowym to około 2 × rozmiar archiwum (pobranie i rozpakowanie).
  Bez zmiennych `DEMO_PHOTOS_*` profil inny niż `small` kończy się błędem przed pierwszym krokiem.
  Profil `small` bierze 37 plików z `apps/api/fixtures/images` bez pobierania.
- **Wysyłka.** Pulę (zdjęcia z Wikimedia Commons, przeskalowane, z wariantami, bez duplikatów: ta sama para `sourceId`
  i `variant` raz w puli) wysyła na R2 skrypt `apps/api/bin/upload-demo-photos` (`make upload-demo-photos DIR=…`; obraz
  `rclone` przypięty digestem jak w chartcie, `copyto --immutable`, więc istniejąca wersja nie zostanie nadpisana)
  z tokenem zapisu, a `photos.yaml` opisuje wysłaną wersję. Przed wysyłką `demo-data:photos:check` sprawdza katalog
  z manifestem (komplet plików, sumy) i każdy plik przechodzi te same reguły co upload w Catalog (typ po zawartości,
  rozmiar, wymiary). Archiwum ani zdjęcia puli nie trafiają do repo. Nowa pula to nowa wersja manifestu; wybór zdjęć
  z Commons idzie partiami z przerwami między pobraniami, a gdy Commons zacznie blokować pobieranie, zatrzymuje się,
  bez obchodzenia ochrony.

### Przebudowa indeksu wyszukiwarki

Przy 10 mln produktów przebudowa (`catalog:reindex`) trwa godzinami, więc pojedynczy przejściowy błąd zapisu partii
nie może jej przerywać:
- adapter Elasticsearcha (`ElasticsearchSearchIndexMaintenance::write`) ponawia pozycje partii z wyniku `transient`
  (`BulkResult`) i przejściowe błędy całego żądania, z odczekaniem podwajanym po każdej próbie (1, 2, 4, 8 s), do
  5 prób łącznie z pierwszą; zapis z `version_type: external_gte` jest bezpieczny do powtórzenia; trwały błąd albo
  wyczerpane próby kończą przebudowę błędem: nowy indeks jest usuwany, a alias zostaje na poprzednim;
- na czas wypełniania nowy indeks ma wyłączone odświeżanie (`refresh_interval: -1`), przywracane przed weryfikacją
  i przełączeniem aliasu;
- postęp (liczba dokumentów, tempo) i każde ponowienie są w logu (postęp co partię).
Opis szczegółowy: `docs/features/search/spec.md`.

## Środowiska

### Testy i lokalnie

- Testy API (`make -C apps/api test`; testy integracyjne generatora to suity `demo-data` i `demo-data-orders` (testy zamówień),
  w CI w częściach `checks part api demo data` i `checks part api demo data orders`, testy funkcjonalne w części `checks part api functional`) uruchamiają kroki `demo-data:*` z profilem `small`
  osobno, na bazie `shop_test`, bez `bin/seed-demo` i bez czyszczenia cache sklepu (worker i Varnish nie obsługują
  `shop_test`): w procesie testu, w transakcji testu, a test determinizmu przy innej liczbie procesów w osobnej grupie
  z czyszczeniem tabel po sobie. Krok kont w testach pracuje na tymczasowych realmach zakładanych i usuwanych przez
  test, nigdy na realmach stosu deweloperskiego, z przestrzenią nazw unikalną dla przebiegu testu (identyfikatory
  użytkowników są globalne w Keycloaku). Skrypt `bin/generate-demo` testuje się jak `bin/seed-demo`
  (`tests/Unit/Bin`, podstawiona konsola). Cały skrypt na prawdziwych usługach uruchamia `make seed-scale
  PROFILE=small` na stosie, w części 2 test dymny k3d.
- Stos E2E nie uruchamia generatora.
- Cel `make seed-scale [PROFILE=small]` (fragment `make/demo-data.mk`) uruchamia `bin/generate-demo` w kontenerze
  `api`, z danymi administratora Keycloaka stosu i (dla profili innych niż `small`) zmiennymi `DEMO_PHOTOS_*` z pliku
  użytkownika przekazanymi do procesu. Po nim `make seed` przywraca dane, na których działają testy E2E.

### k3d (chart w profilach `ci` i `load`; część 2)

- Zawieszony CronJob `demo-data` (wzorzec CronJoba `restore`) tylko gdy `demoData.enabled` (profile `ci`, `load` i
  `prod`; na serwerze Job powstaje wyłącznie z celu `make -C infra/k8s generate`, który zamyka sklep, a po Jobie
  `make -C infra/k8s open` sprawdza dane i otwiera go: `docs/features/demo-scale/spec.md`): obraz api, skrypt chartu `files/scripts/demo-data.sh`, który
  uruchamia `bin/generate-demo` z profilem i chwilą odniesienia z wartości (dla `seed` `bin/seed-demo`) i odmawia
  (kod 64) bez zmiennej `DEMO_DATA_CONFIRM` równej profilowi albo `seed` (ustawiają ją tylko `generate`, `load`
  i test dymny, więc Job utworzony ręcznie niczego nie kasuje), z strażnikiem wolnego miejsca (`demoData.minFreeGb`:
  kod 74 przed startem, 75 po zatrzymaniu generatora; `seed` bez progu), sekret stosu,
  login administratora Keycloaka jak w Jobie `keycloak-config`, zasoby `resources.demoData`, limit czasu z wartości
  per profil (`small` 30 min, `medium` 1 h, `server` 9 h, `full` 20 h: około dwukrotność czasu generatora, w górę do
  pełnej godziny; `medium` z pomiaru, `server` i `full` z ekstrapolacji, niezweryfikowanej; w `prod` `medium` 6 h
  i `server` 24 h, bez pomiaru na tym serwerze), bez ponowień.
- Test dymny (`make -C infra/k8s smoke`, w CI w `deploy.yml`) uruchamia Job z tego CronJoba z profilem `small`
  i sprawdza kod 0. Job powstaje dopiero, gdy wszystkie StatefulSety zakończą rollout: poprzednia faza (upgrade r4)
  kończy się celowo nieudanym `initialize`, a nowa rewizja zmienia obraz Elasticsearcha, więc jego pod jest wtedy
  jeszcze wymieniany, a generator zaczyna od resetu indeksu wyszukiwarki.
- `make -C infra/k8s load`: opcja `LOAD_DATA=seed|medium|server|full` (domyślnie `seed`, jak dziś). Dla pozostałych
  po `initialize` uruchamia Job generatora i czeka na niego (osobny limit czasu), potem pomiar jak dziś. Zamiast
  podnoszenia stanu wszystkich wariantów (dziś jedno `UPDATE` na każdym wariancie ze stanem, około 30 mln wierszy
  po generatorze) cel losuje porcje mapy strony, podnosi stan tylko wariantów produktów z tych porcji i przekazuje
  ich klucze k6. Dla profili innych niż `small` cel tworzy w klastrze sekret odczytu puli zdjęć z pliku
  użytkownika (brak pliku: kod 2 przed utworzeniem klastra).
  Profil `load` wyłącza kopie zapasowe (dziś dziedziczy je z `ci`).
- **Zapas miejsca (W2).** `profiles.yaml` podaje dla profilu przewidywane szczytowe zużycie dysku (`diskPeakGb`:
  dane Postgresa, Elasticsearcha i Keycloaka, WAL, obrazy i klaster). Przed utworzeniem klastra cel sprawdza wolne
  miejsce w VM Dockera i na dysku hosta, na którym leży dysk VM (ścieżka z opcji, domyślnie katalog domowy; dysk VM
  Dockera Desktop rośnie w miarę zapisu, więc granicą jest host). Oba muszą mieć co najmniej 1,25 × `diskPeakGb`;
  inaczej cel kończy się kodem 2 bez startu, z wymaganym i dostępnym miejscem w komunikacie. W trakcie cel co 30 s
  sprawdza oba miejsca i przy spadku poniżej 10% `diskPeakGb` zatrzymuje Job generatora i k6, usuwa klaster i kończy
  się kodem 2. Każdy odczyt trafia do `space.log` w wynikach (pierwszy wiersz to odczyt sprzed startu), a na końcu
  przebiegu plik dostaje podsumowanie z najmniejszym wolnym miejscem i zużyciem względem pierwszego odczytu (szczytowe
  zużycie dysku, z którego uaktualnia się `diskPeakGb`). Próbniki pamięci i CPU (`top.log`, `node-stats.log`) działają
  od chwili, gdy klaster jest gotowy, więc obejmują generator. Wartości `diskPeakGb`: `medium` 23 GB, `server` 114 GB i `full` 268 GB. Każda to suma szczytu przebiegu `medium`
  z 2026-11-19 bez pól treści produktów (20,52 GB zmierzone; `server` i `full` jego ekstrapolacja, rachunek w „Wyniki
  przebiegu `medium`”) i szacunku przyrostu od tych pól i indeksu SKU („Rozmiar i czas pól treści produktów”). Wszystkie
  trzy są szacunkiem niezweryfikowanym przebiegiem po zmianie generatora.
- **Uśpienie komputera.** Gdy komputer się usypia, VM Dockera staje razem z nim, a czasy i przepustowość pomiaru są
  bezwartościowe, choć nic w wynikach tego nie pokazuje. Dlatego na macOS cel przez cały przebieg blokuje uśpienie
  z bezczynności, a na zasilaniu sieciowym także systemowe (`caffeinate -ims -w <pid skryptu>`); nie chroni przed
  zamkniętą klapą ani uśpieniem ręcznym, więc przy pomiarze klapa ma być otwarta, a komputer podłączony do prądu.
  Na innych systemach cel nic nie blokuje i bez błędu, a `environment.txt` w linii `sleep lock:` mówi, co zrobiono. Na końcu cel szuka przerw w odczytach, które sam zbiera (`space.log`
  przy generatorze, `node-stats.log` od chwili, gdy klaster jest gotowy, oraz koniec przebiegu): odstęp dłuższy niż
  trzykrotność `LOAD_SPACE_POLL` i co najmniej 120 s to uśpienie. Przerwy trafiają do `space.log` (linia `#`: liczba,
  łączny i najdłuższy czas), do `environment.txt` (linia `host sleep:`, także gdy ich nie było) i jako ostrzeżenie na
  standardowe wyjście błędów, a przebieg doprowadzony do końca kończy się kodem 1, nie 0: wyniki zostają w `LOAD_OUT`,
  ale czasów nie wolno używać. Przebieg, który i tak padł (kod 2), zachowuje swój kod i tylko nazywa przerwę.
  INT kończy przebieg kodem 130, a TERM kodem 143 (klaster usunięty w obu przypadkach).
- Wynik `load` zawiera czasy kroków generatora i rozmiary tabel i indeksów (do raportu).

### Pomiar k6 (część 2)

- `load/lib/data.js` bierze klucze porcji z `/api/v1/sitemap/product-chunks` (albo z opcji, gdy podaje je `load`),
  produkty z losowych porcji (`/api/v1/sitemap/products?chunk=…`), nazwy do fraz wyszukiwania z danych tych
  produktów w API, a facety z listingu kategorii, nie z listingu całego katalogu; liczba porcji i produktów z opcji.
  Na danych z seeda wynik jak dziś (`make load-smoke`).

### Wyniki przebiegu `medium` (część 2)

Pomiar `make -C infra/k8s load LOAD_DATA=medium` (1 mln produktów, 1 mln zamówień, 100 tys. klientów, k3d na komputerze
użytkownika) opisuje raport `docs/reports/demo-load-medium-2026-11-19.md`: czasy kroków i tempo generatora, rozmiary tabel
i indeksów, dysk, pamięć i CPU podów oraz wyniki k6. Z pomiaru wynikają:

- **`diskPeakGb`.** Szczyt zużycia dysku w VM Dockera względem odczytu sprzed startu (`space.log`), zaokrąglony w górę
  do pełnego GB: `medium` 21 GB (generator bez pól treści produktów; `diskPeakGb` `medium` w `profiles.yaml` to ta liczba
  plus szacowany przyrost od tych pól, niżej). Zmierzono go przy obrazach z cache, więc zimna budowa dodaje warstwy obrazów.
- **Limit Joba.** Około dwukrotność czasu Joba generatora (`medium`: 1731 s), w górę do pełnej godziny: `medium` 1 h.
- **Limit pamięci Joba** (`resources.demoData`) zostaje zapasem dla `server` i `full`, których zużycia pamięci nie
  zmierzono.

**Ekstrapolacja `server` i `full` (niezweryfikowana pomiarem).** Liniowa z wartości `medium`; produkty, warianty,
klienci i konta ×10 (oba profile), zamówienia ×5 (`server`) albo ×50 (`full`):

| Składnik `medium` | `medium` | `server` | `full` |
|---|---|---|---|
| tabele produktowe i Elasticsearch (×10) | 6,48 GB | 64,8 GB | 64,8 GB |
| tabele zamówieniowe | 3,44 GB | 17,2 GB | 171,7 GB |
| klienci i Keycloak (×10) | 0,29 GB | 2,9 GB | 2,9 GB |
| stały narzut (obrazy, klaster, pula zdjęć, WAL) | 10,31 GB | 10,31 GB | 10,31 GB |
| razem (szczyt bez pól treści produktów) | 20,52 GB | 95,2 GB | 249,7 GB |

Do tej sumy dochodzi przyrost od pól treści produktów i indeksu SKU (szacunek, niżej), z którego wychodzi `diskPeakGb`.

Czas generatora: kroki produktowe (produkty, zdjęcia, ceny, stany: 303 s) ×10, konta (162 s) ×10, przebudowa indeksu
(447 s) ×10, zamówienia i sprzedaż (173 s) ×5 albo ×50, a `demo-data:verify` (596 s, przy założeniu, że połowa
przypada na produkty, a połowa na zamówienia) ×7,5 albo ×30. Wychodzi około 14 500 s (4 h) dla `server` i około
35 700 s (10 h) dla `full`; limity Joba (9 h i 20 h) dają około dwukrotny zapas. Założenia nie sprawdzono:
indeksy B-drzewa, scalanie segmentów Elasticsearcha i weryfikacja mogą rosnąć szybciej niż liniowo.

Założenia rachunku dysku: część ruchów magazynowych (rezerwacja, zatwierdzenie, zwolnienie) skaluje się
z zamówieniami, nie z produktami, a rachunek skaluje wszystkie ruchy z produktami (×10): dla `full` to niedoszacowanie
rzędu kilku GB, w granicach zapasu 1,25.

### Rozmiar i czas pól treści produktów (PRODUCT-DATA, etap 1)

Pomiary i szacunki do `docs/features/product-data/spec.md` („Skala i dane na serwerze”, p. 3–5): co do rozmiaru
i czasu zmienia generator, który pisze markę, opis, parametry, SKU i EAN („Treść produktów i kody wariantów”). Różnica
jest liczona względem generatora bez tych pól na tym samym ziarnie (`8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8`), chwili
odniesienia `2026-11-17T00:00:00Z` i 4 procesach, na tym samym komputerze (macOS, Docker Desktop: 16 CPU, 31 GiB
pamięci, Postgres 18.6 z ustawieniami domyślnymi: `shared_buffers` 128 MB, `max_wal_size` 1 GB) i na stosie Compose
(nie k3d). Indeks unikalny SKU istnieje podczas ładowania. Rozmiary podaje `pg_relation_size` (sterta, TOAST i każdy
indeks osobno) i `pg_total_relation_size` (razem), po kroku, bez `VACUUM` i `ANALYZE`; GB to 10^9 bajtów.

**Pomiar 1: `small`, pełny przebieg generatora** (`make seed-scale PROFILE=small ANCHOR=2026-11-17T00:00:00Z
PROCESSES=4`, czyli `bin/generate-demo` ze wszystkimi krokami; 2 191 produktów, w tym 191 z seeda, i 7 081 wariantów).
Sterta zawiera martwe wersje wierszy po kroku zdjęć, który raz aktualizuje każdy wiersz produktu. Jeden przebieg przed
i jeden po, bajty:

| Relacja | Część | Bez pól treści | Z polami treści |
|---|---|---|---|
| `catalog_product` | sterta | 2 170 880 | 5 300 224 |
| | TOAST (sterta, indeks) | 0, 8 192 | 0, 8 192 |
| | indeksy (9; największy `name_trgm`) | 5 120 000 (`name_trgm` 4 046 848) | 4 456 448 (`name_trgm` 3 391 488) |
| | razem | 7 356 416 | 9 789 440 |
| `catalog_product_variant` | sterta | 753 664 | 892 928 |
| | TOAST (sterta, indeks) | 0, 8 192 | 0, 8 192 |
| | `catalog_product_variant_sku_key` (SKU) | 73 728 | 352 256 |
| | pozostałe indeksy (`pkey`, `position_key`, `values_key`) | 1 212 416 | 1 220 608 |
| | razem | 2 080 768 | 2 506 752 |

W przeliczeniu na wiersz: tabela produktów 3 358 B → 4 468 B na produkt, tabela wariantów 294 B → 354 B na wariant;
sterta produktów 991 B → 2 419 B na produkt. Przyrost sterty (1 428 B) jest większy niż w pomiarze 2 (779 B), bo krok
zdjęć zostawia martwą wersję każdego wiersza: ponad przyrost wiersza dochodzi druga kopia o około 650 B na produkt.
Opis (300–600 znaków) i parametry (3–6 par) mieszczą się w wierszu poniżej progu TOAST, więc TOAST jest pusty.

**Pomiar 2: `medium`, kroki katalogu** (`bin/seed-demo`, `demo-data:categories`, potem `demo-data:products` w 4 procesach
`--worker=i --workers=4`, tak jak woła je `bin/generate-demo`; bez zdjęć, cen i zamówień; 1 000 191 produktów, w tym
191 z seeda, i 3 009 350 wariantów; średnia z 3 przebiegów bez pól treści i 4 z polami, rozrzut rozmiaru między
przebiegami poniżej 0,5%), MB:

| Relacja | Część | Bez pól treści | Z polami treści | Różnica |
|---|---|---|---|---|
| `catalog_product` | sterta | 396,3 | 1 175,4 | +779,1 |
| | TOAST | 0 | 0 | 0 |
| | indeksy (9) | 460,1 | 476,5 | +16,4 |
| | razem | 856,6 | 1 652,3 | +795,7 |
| `catalog_product_variant` | sterta | 313,0 | 387,5 | +74,5 |
| | TOAST | 0 | 0 | 0 |
| | `catalog_product_variant_sku_key` (SKU) | 30,2 | 167,1 | +136,9 |
| | pozostałe indeksy (`pkey`, `position_key`, `values_key`) | 495,3 | 494,1 | −1,1 |
| | razem | 838,6 | 1 048,9 | +210,3 |

Indeks SKU bez pól treści ma wpisy `NULL` dla każdego wariantu (10 B na wariant); z polami ma 55,5 B na wariant. Jedyny
indeks produktów, który się zmienił, to `catalog_product_name_trgm_idx` (`medium`: 125,5–125,7 MB w 3 przebiegach bez pól, 141,5–142,9 MB w 4 z polami, czyli różnica systematyczna +16,6 MB;
w `small` odwrotnie, −0,7 MB w jednym przebiegu; indeks jest po nazwie, której nowe pola nie zmieniają, przyczyny nie badano),
pozostałe różnią się o mniej niż 1 MB. Rachunek niżej bierze przyrost w całości, także tę różnicę.

**Bajty na produkt i na wariant (pomiar 2: `medium`, kroki katalogu, bez zdjęć, cen i zamówień).** Tabela produktów:
856 B → 1 652 B na produkt (+796 B, z tego sterta +779 B). Tabela wariantów: 279 B → 349 B na wariant (+70 B: sterta
+25 B, indeks SKU +46 B). Przyrost na produkt z jego wariantami (3,009 na produkt) to 1 006 B, czyli rząd 1 kB, który
dawał rachunek z budżetów („Skala i dane na serwerze” w specyfikacji PRODUCT-DATA); wszystkie tabele i indeksy katalogu
razem: 1 695 B → 2 701 B na produkt z wariantami.

**Rachunek przyrostu szczytu dysku (szacunek).** `diskPeakGb` to szacunek szczytu całego stosu w trakcie generowania, nie
rozmiar tabel po nim (tabele po kroku to część rachunku, nie szczyt). Przyrost szczytu składa się z:

| Składnik | Podstawa | `medium` | `server` | `full` |
|---|---|---|---|---|
| tabele i indeksy produktów | +796 B × produkty (pomiar 2) | 0,80 GB | 7,96 GB | 7,96 GB |
| tabele i indeksy wariantów, w tym indeks SKU | +70 B × warianty (3,009 na produkt; pomiar 2) | 0,21 GB | 2,10 GB | 2,10 GB |
| druga kopia przyrostu wiersza produktu w kroku zdjęć | 779 B × produkty: `UPDATE` obrazów zostawia stary wiersz (z nowymi kolumnami) do odkurzenia | 0,78 GB | 7,79 GB | 7,79 GB |
| WAL nowych kolumn i indeksu SKU | zapis kroku produktów 8,93 → 10,84 GB na `medium` (+1,91 GB), ale na dysku WAL trzyma `max_wal_size` (1 GB, chart go nie zmienia, kopie zapasowe to `pg_dump`, bez archiwizacji WAL), więc szczytu nie zwiększa; zawiera go stały narzut 10,31 GB | 0 | 0 | 0 |
| **przyrost szczytu** | | **1,79 GB** | **17,85 GB** | **17,85 GB** |
| szczyt bez pól treści | tabela „Ekstrapolacja `server` i `full`” wyżej | 20,52 GB | 95,2 GB | 249,7 GB |
| **razem, w górę do pełnego GB (`diskPeakGb`)** | | **22,3 → 23 GB** | **113,05 → 114 GB** | **267,55 → 268 GB** |

Wszystkie trzy wartości `diskPeakGb` są szacunkiem niezweryfikowanym przebiegiem po zmianie generatora: przyrost tabel
pochodzi z pomiaru `medium` skalowanego liniowo, a druga kopia i WAL to założenia (druga kopia zakłada, że odkurzanie
nie nadąża za krokiem zdjęć, więc jest górną granicą; w `small` zmierzono około 0,83 przyrostu wiersza). Założenie o WAL
(przyrost 0): `max_wal_size` jest limitem miękkim, więc przy zapisie większym o 21% WAL może go chwilowo przekroczyć;
nie mierzono, o ile; zakładamy, że ewentualny nadmiar mieści się w zapasie 1,25 strażnika miejsca. Strażnik wolnego
miejsca (wolne ≥ 1,25 × `diskPeakGb`) wymaga więc dla `medium` 28,75 GB, dla `server` 142,5 GB, dla `full` 335 GB.

**Czas kroku `demo-data:products`, `medium`, 4 procesy** (od startu pierwszego procesu do końca ostatniego, bez resetu
i kategorii; indeks SKU obecny):

| Wariant | Przebiegi (s) | Średnia |
|---|---|---|
| bez pól treści (5 przebiegów) | 36,97; 37,28; 39,91; 33,72; 33,91 | 36,4 s |
| z polami treści (6 przebiegów) | 42,85; 42,93; 41,02; 41,10; 41,90; 39,59 | 41,6 s |
| z polami treści, bez indeksu SKU (2 przebiegi, pomiar uzupełniający na lokalnej bazie, indeks usunięty i odtworzony) | 38,72; 39,54 | 39,1 s |

Różnica to pomiar `medium`: około +5 s (+14%), z czego około połowa przypada na indeks SKU (+2,5 s), a reszta na
szersze wiersze (+2,7 s). Rozrzut między przebiegami tego samego kodu wynosi około 6 s (komputer dzielił CPU i dysk
z innymi stosami), więc różnicę trzeba czytać jako rząd wielkości, nie jako dokładną liczbę. Indeks SKU (167 MB) nie mieści
się w `shared_buffers` (128 MB) już przy `medium`.

Czas dla `server` i `full` (oba mają 10 mln produktów, kroki katalogu nie zależą od zamówień) to szacunek ×10,
niezweryfikowany: krok produktów około 6 min bez pól treści i około 7 min z nimi na tym komputerze, a na serwerze demo,
gdzie krok `medium` zajął około 2 min (`docs/reports/demo-scale-medium-worf-2026-11-20.md`), około 20 min i około 23 min,
czyli przyrost około 3 min. Przy 128 MB `shared_buffers` (chart ich nie ustawia) indeks SKU `server` (około 1,7 GB)
jest kilkanaście razy większy od pamięci podręcznej bazy, a drzewo ma o poziom więcej niż przy `medium`, więc koszt
wpisu może rosnąć szybciej niż liniowo; kolejność SKU (numer produktu na początku) ogranicza to, bo wiersze części
trafiają do sąsiednich stron. Odniesienie: limit Joba `server` to 24 h (`docs/features/demo-scale/spec.md`) przy prognozie 11 h
(`docs/reports/demo-scale-medium-worf-2026-11-20.md`), więc przyrost rzędu kilku minut to około 0,5% prognozy;
nawet kilkukrotnie większy nie zbliża Joba do limitu.

**Czas sprawdzeń treści `demo-data:verify`** (dwa zapytania liczące: produkty bez marki, opisu, parametrów i z parametrem
bez nazwy lub wartości w jednym przejściu, warianty bez SKU i EAN w drugim; brak to `NULL`, pusty tekst albo tekst bez
znaku widocznego): na `small` (2191 produktów, 7081 wariantów, `EXPLAIN ANALYZE` po `ANALYZE`, dwa pomiary) 6,7–6,9 ms
i 2,3 ms, z czego sprawdzenie wpisów parametrów to około 5,2 ms (bez niego produkty 1,5 ms); same sprawdzenia
`NULL` kosztowały 0,8–0,9 ms i 0,6 ms. Na `medium` nie mierzono po tej zmianie (wymaga puli zdjęć; przed zmianą 155 ms
i 127 ms): proporcja `small` daje około 1,2 s i 0,5 s, a dla `server` i `full` ×10 około 12 s i 5 s, czyli kilka
procent czasu `demo-data:verify`.

Kroki zdjęć, cen, promocji i zamówień przechodzą generator produktów i liczą teraz także opis i parametry (dane tych
kroków bez zmian); koszt CPU tego dodatku nie był mierzony i nie wchodzi do szacunku czasu.

Pomiar `server` i `full` nie został wykonany; szczyt dysku całego stosu po zmianie generatora też nie (`medium` na k3d
powtórzy DEMO-SCALE).

## Model zagrożeń

- **Aktywa i strony.** Konta w Keycloak (logowanie i reset hasła) przed osobami z internetu; adresy e-mail osób
  przed wysyłką z demo; dane w klastrze przed przypadkowym zastąpieniem.
- **Stan obecny (bez zmian w epiku).** Hasło administratora Keycloaka (`KC_BOOTSTRAP_ADMIN_PASSWORD`) jest we
  wspólnym sekrecie, który dostają przez `envFrom` wszystkie pody api, worker i Joby (`templates/secret.yaml:20`,
  `_helpers.tpl:49-53`). Job generatora korzysta z tego samego sekretu; epik nie dodaje nowej drogi do niego.
- **Drogi ataku w zakresie:**
  - logowanie na wygenerowane konta albo reset ich hasła: konta wyłączone i bez poświadczeń (P5); sprawdzenie: test
    integracyjny na Keycloaku (wygenerowany klient jest wyłączony, nie ma poświadczeń, logowanie i żądanie resetu
    nie dają sesji ani e-maila);
  - wysyłka e-maili do prawdziwych osób: adresy wyłącznie w domenach zarezerwowanych, generator nie publikuje
    zdarzeń; sprawdzenie: test generatora (każdy e-mail kończy się `@example.(com|net|org)`) i pusty outbox po `small`;
  - przypadkowe zastąpienie danych: CronJob `demo-data` zawieszony we wszystkich profilach, które go mają (`ci`,
    `load`, `prod`), a jego skrypt odmawia bez `DEMO_DATA_CONFIRM` (kod 64); sprawdzenie: test renderu
    (`suspend: true`) i test skryptu; na serwerze Job powstaje tylko z celu `generate` po zgodzie użytkownika
    (`docs/features/demo-scale/spec.md`, „Ochrona przed przypadkowym uruchomieniem”);
  - podmiana zdjęć puli: SHA-256 każdego pliku w repo; sprawdzenie: test kroku zdjęć z niezgodną sumą pliku
    i z brakującym plikiem (błąd bez zmian);
  - wyciek danych dostępu do bucketu puli: tylko w pliku użytkownika poza repo i w sekrecie jednorazowego klastra k3d,
    token generatora tylko do odczytu jednego bucketu; sprawdzenie: test, że manifest ani logi kroku nie zawierają
    wartości `DEMO_PHOTOS_*`;
  - usunięcie albo zmiana prawdziwego konta przez sprzątanie kont (W1): generator usuwa tylko członków grupy
    `demo-data-generated` po potwierdzeniu członkostwa, a import nie nadpisuje istniejących kont, a każdy pominięty import jest sprawdzany; sprawdzenie: test
    integracyjny na Keycloaku (konto spoza grupy z nazwą w formacie generatora i z identyfikatorem wyliczonym z ziarna,
    konto zwykłe i konto jak z `create-admin` przetrwają każdy wariant ponownego generowania i sprzątania bez zmian;
    bez grupy w realmie nic nie jest usuwane);
  - odczyt zamówień gości po wyliczonym tokenie dostępu albo przez powtórzenie złożenia z wyliczonym kluczem
    idempotencji: tokeny i klucze idempotencji zamówień i płatności losowe, nie z publicznego ziarna; sprawdzenie:
    test (dwa przebiegi z tym samym ziarnem dają różne tokeny i klucze; żądanie złożenia odtworzone z ziarna nie zwraca
    istniejącego zamówienia).
- **Poza zakresem.** Rozdzielenie sekretu administratora Keycloaka od sekretu aplikacji (#485); przejęty węzeł albo
  obraz zewnętrzny.

## Kryteria akceptacji

1. Kroki generatora z profilem `small` kończą się kodem 0 w testach API; dwa uruchomienia z tą samą chwilą
   odniesienia dają identyczne wiersze generatora (suma kontrolna każdej tabeli bez wierszy seeda, tokenów dostępu
   gości i kluczy idempotencji), także przy innej liczbie procesów; `bin/generate-demo` przerywa się na błędzie kroku
   i na błędzie jednej z równoległych części, a w `prod` bez `--force-reset` odmawia (test skryptu).
2. `demo-data:verify` przechodzi po `small` i wykrywa każde naruszenie z listy (wstrzyknięte w teście).
3. Wygenerowane dane czytają i zmieniają prawdziwe ścieżki: strona produktu z ogona, listing kategorii,
   wyszukiwarka, lista zamówień w panelu z filtrami, szczegół zamówienia z historią i płatnością, wysyłka i anulowanie
   wygenerowanego zamówienia `paid` przez API panelu, historia zamówień klienta (token z `sub` wygenerowanego
   klienta), historia cen i stanów wariantu, cena Omnibus wariantu z ogona po zaplanowaniu promocji przez API
   panelu (z wygenerowanej historii cen i zakończonych promocji).
4. Każde wygenerowane zamówienie (z historią statusów), płatność, stan, cena, promocja i kod rabatowy profilu `small`
   odtwarza się przez repozytorium modułu bez błędu (wartości enumów, warunki agregatów).
5. Testy kontraktu: reguły spoza warstw Domain powtórzone w generatorze dają te same wyniki co kod modułów (klucz
   zdjęcia, klucz wartości wariantu, ścieżka kategorii); reguły Domain generator woła wprost (P1a). Brak
   kolumny `NOT NULL` bez wartości domyślnej odrzuca `COPY`, więc wyłapuje go kryterium 1.
6. Rozkłady na `small` w tolerancjach z testu: udział zamówień gości, statusy według wieku, zwroty i anulowania,
   kody rabatowe, produkty bez zdjęć i szkice, warianty bez stanu.
7. Konta: wygenerowani klienci i pracownicy należą do grupy `demo-data-generated`, mają wyliczone identyfikatory
   i role pracowników według numeru konta, są wyłączeni i bez poświadczeń; ponowne uruchomienie z tymi samymi opcjami
   nic nie zmienia. Test integracyjny na Keycloaku, na tymczasowych realmach i z unikalną przestrzenią nazw (W1): po
   każdym przebiegu (inna przestrzeń nazw, mniejsza i większa liczba kont, inny odcisk, przerwany krok, paczka
   odrzucona w połowie importu, liczba kont większa niż strona listy członków) członkami grupy są dokładnie konta
   zbioru docelowego z bieżącymi danymi, a konta spoza grupy (z kryterium modelu zagrożeń) są bez zmian; nazwa albo
   e-mail zajęte przez konto spoza grupy kończą krok błędem z listą.
8. Przebudowa indeksu ponawia przejściowe błędy (pozycje partii i całe żądanie) i kończy się sukcesem po kilku
   nieudanych próbach; trwały błąd i wyczerpane próby kończą przebudowę błędem (nowy indeks usunięty, alias bez zmian).
9. (część 2) Chart: CronJob `demo-data` zawieszony, tylko z `demoData.enabled`, z zasobami i limitem czasu z wartości; profil
   `load` bez kopii; `make -C infra/k8s check` przechodzi, a selftest łapie brak `suspend` (CronJob w `prod`:
   DEMO-SCALE, kryterium 1).
10. (część 2) Test dymny k3d uruchamia Job generatora z profilem `small` i kończy się sukcesem.
11. (część 2) `make -C infra/k8s load` z `LOAD_DATA`: ścieżki błędu (nieznany profil, Job generatora zakończony błędem albo
    po czasie) testowane jak istniejące ścieżki `load`; bez opcji działa jak dziś. Zapas miejsca (W2): test skryptu
    odmawia startu, gdy VM albo host ma mniej niż 1,25 × `diskPeakGb` (także dokładnie na granicy minus 1 GB),
    i przerywa przebieg z kodem 2, gdy w trakcie miejsce spada poniżej progu (miejsce podawane przez podmienione
    polecenie w teście).
12. (część 2) Przebieg `LOAD_DATA=medium` w zadaniu (k3d na komputerze użytkownika) kończy się kodem 0; PR podaje tempo
    ładowania (wiersze/s na tabelę), czas kont i przebudowy indeksu, rozmiary, szczyt pamięci Postgresa i Joba,
    uaktualnione `diskPeakGb` i ekstrapolację dla `server` i `full` opisaną jako niezweryfikowana (W3).
13. (część 2) `load/lib/data.js` działa na danych z seeda jak dziś (`make load-smoke` w CI).
14. Dokumentacja części 1: `docs/vision.md` („Dane demo”: generator i profile), `apps/api/CLAUDE.md` (moduł
    `DemoData`, wyjątki persystencji i outboxu, komendy, sposób testów), `docs/features/search/spec.md`. Część 2:
    `docs/vision.md` („Skala docelowa”: pomiar na danych w skali, pomiar `full` odłożony; bez twierdzenia, że pełna
    skala jest zweryfikowana, W3), runbook `infra/k8s/README.md`
    i komentarz przy celu make (opis `LOAD_DATA` i CronJoba `demo-data`), `infra/k8s/CLAUDE.md` (tylko zasada, np.
    wymóg zgody użytkownika), `docs/features/hardening/spec.md` (opis `load`), `load/README.md`.
15. `make seed-scale PROFILE=small` na stosie kończy się kodem 0 (cały skrypt na prawdziwych usługach), a `make seed`
    po nim przywraca dane E2E.
16. Pula zdjęć (P10): obiekt archiwum w prywatnym buckecie istnieje pod kluczem z manifestu, sumy plików zgadzają
    się z manifestem, pula ma 4317 plików (1142 zdjęcia i ich warianty) bez powtórzonej pary `sourceId` i `variant` i 100–300
    plików na grupę zdjęć (test pliku `photos.yaml`: 3500–4500 plików), każdy plik spełnia reguły
    uploadu Catalog, bucket nie ma dostępu publicznego, a skrypt wysyłki
    przekazuje `--immutable` (test skryptu z podstawionym `docker`).

## Ryzyka

Hipotezy z analizy kodu do sprawdzenia pomiarem (P2), z miejscami w kodzie:
- **H1.** Listing i wyszukiwarka sklepu liczą dokładną liczbę wyników i agregacje zagnieżdżone przy każdym chybieniu
  cache; strona główna bez kategorii agreguje cały katalog (`ProductSearchQuery.php:36,117-184`), limit zapytania
  2 s, sterta Elasticsearcha 2 GB w `prod`.
- **H2.** Stronicowanie sklepu bez górnej granicy (`ListProductsQuery.php:40-41`, `Pagination.tsx`): przy 10 mln
  produktów setki tysięcy stron, każda z pełnym zapytaniem.
- **H3.** Pulpit panelu co 30 s (`useDashboard.ts:27`) liczy kolejki ograniczone do 1000 wierszy po indeksach list,
  a liczbę produktów szacuje ze statystyk tabeli (`PostgresProductTableStatistics.php:20`, bez `COUNT(*)`); do sprawdzenia
  pomiarem, czy to wystarcza przy 10 mln produktów.
- **H4.** Wyszukiwarka produktów w panelu z filtrem kategorii (`DbalBackofficeProductSource.php:35-43`): warunek
  `OR` z `EXISTS` nie jest warunkiem indeksu.
- **H5.** Przebudowa indeksu trwa godzinami (około 208 tys. wywołań `GetProductOffers` przy 10 mln produktów); ten
  epik zmniejsza ryzyko przerwania, nie czas.
- Brak `statement_timeout` na połączeniu bazy; czyszczenie całego cache sklepu przy każdym wydaniu; `count(*)` na
  pełnych tabelach w Jobie `initialize` przy każdym wdrożeniu.
- Rozmiary i czasy `server` i `full` to ekstrapolacja z przebiegu `medium` (sekcja „Wyniki przebiegu `medium`”), nie
  pomiar; `server` zmierzy dopiero przebieg na serwerze demo (DEMO-SCALE, jako pomiar tego serwera), a `full` nie ma
  pomiaru (odłożony, W3).
- Usuwanie kont generatora po zmianie przestrzeni nazw albo słowników to do miliona pojedynczych wywołań REST
  Keycloaka, a każdy przebieg kroku kont przegląda pełną listę członków grupy. Dla 100 tys. kont krok kont trwał
  162 s; czasu dla miliona kont nie zmierzono.

## Wymagane przed startem

- Prywatny bucket Cloudflare R2 na pulę zdjęć (P10), bez dostępu publicznego, i dwa tokeny API ograniczone do niego:
  zapis i odczyt (wysyłka puli) oraz tylko odczyt (generator); endpoint, bucket i klucze w pliku
  `~/.config/shop/demo-photos.env` (zmienne `DEMO_PHOTOS_ENDPOINT`, `DEMO_PHOTOS_BUCKET`,
  `DEMO_PHOTOS_WRITE_ACCESS_KEY_ID`, `DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY`, `DEMO_PHOTOS_ACCESS_KEY_ID`,
  `DEMO_PHOTOS_SECRET_ACCESS_KEY`) — zakłada je użytkownik (wartości nigdy nie przechodzą przez rozmowę).
- Pobieranie zdjęć z Wikimedia Commons (tylko CC0 1.0 i domena publiczna) bez klucza API, przez publiczne API Commons
  z limitami zapytań i nagłówkiem `User-Agent`, potrzebne tylko do wyboru nowej puli (sekcja „Zdjęcia”).
- (część 2) Przebieg `LOAD_DATA=medium` w zadaniu: Docker na komputerze użytkownika z co najmniej 10 CPU, 20 GiB pamięci
  (wymagania `load`) i około 29 GB wolnego miejsca (1,25 × `diskPeakGb` `medium`, W2) w VM i na dysku komputera; przebieg `medium` z 2026-11-19
  miał 16 CPU, 31 GiB pamięci, w VM około 894 GB i na dysku komputera około 185 GB wolnego.

## Po epiku

Kroki sesji za zgodą użytkownika, nie zadania AO:
1. Dane w skali na serwerze demo: `generate PROFILE=medium`, po analizie `PROFILE=server`
   (`docs/features/demo-scale/spec.md`, „Przebieg na serwerze”).
2. Pomiar `full` (W3) na k3d jest odłożony: `make -C infra/k8s load LOAD_DATA=full` na komputerze użytkownika, raport
   w `docs/reports/`. Cel sam odmówi startu bez zapasu miejsca (W2): przy `diskPeakGb` `full` równym 268 GB
   (szacunek) potrzeba około 335 GB wolnego na dysku komputera, a przy przebiegu `medium` 2026-11-19 wolne było
   około 185 GB, więc przed pomiarem `full` trzeba zwolnić miejsce albo wskazać inny dysk.
