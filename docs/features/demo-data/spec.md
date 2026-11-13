# DEMO-DATA: generator danych w skali docelowej i pomiar wydajności

Deterministyczny generator, który wypełnia sklep danymi w skali docelowej z `docs/vision.md` (10 mln produktów)
i realistycznymi danymi operacyjnymi (klienci, pracownicy, zamówienia), oraz pomiar obciążenia na tych danych na
klastrze k3d. Raport z pomiaru pokazuje, jak sklep działa przy tej skali, i wskazuje wąskie gardła do poprawy przed
wdrożeniem danych na serwer demo (worf.pl).

Epik ma dwie części z osobnymi końcowymi PR, mergowane po kolei (P11): część 1 (#482, plan w `plan.md` tego katalogu)
to generator i jego testy, część 2 (#486, DEMO-LOAD) to integracja z k3d, k6 i przebieg `medium`. Ta specyfikacja
opisuje obie; sekcje i kryteria części 2 są oznaczone „część 2”, a jej plan powstaje w przygotowaniu #486.

Issues: #482
Zakres: `apps/api/src/DemoData`, `apps/api/tests/Unit/DemoData`, `apps/api/tests/Integration/DemoData`, `apps/api/tests/Functional/DemoData`, `apps/api/fixtures/demo-data`, `apps/api/bin/generate-demo`, `apps/api/bin/upload-demo-photos`, `apps/api/config/packages/flysystem.yaml`, `apps/api/phpunit.dist.xml`, `apps/api/tests/Unit/Bin`, `make/demo-data.mk`, `apps/api/deptrac.yaml`, `apps/api/config/services.yaml`, `apps/api/CLAUDE.md`, `apps/api/src/Catalog/Application/Search`, `apps/api/src/Catalog/Infrastructure/Search`, `apps/api/tests/Unit/Catalog`, `apps/api/tests/Integration/Catalog`, `docs/vision.md`, `docs/features/search/spec.md`

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
- **U5. Zdjęcia.** Większa pula prawdziwych zdjęć z Unsplash poza gitem (w repo lista plików z autorami i sumą
  kontrolną archiwum), obiekty w Garage współdzielone między produktami (oryginały nigdy nie są usuwane, decyzja K7
  w `docs/features/catalog-admin/spec.md`).
- **U6. Bez nocnego resetu.** Dane demo nie są resetowane co noc (zmiana #179 należy do osobnego epiku dostępu do
  demo); przywrócenie danych to świadome uruchomienie generatora.

Przyjęte przez użytkownika 2026-11-13 (rekomendacje z przygotowania epiku):

- **P1. Generator jako płaski moduł.** `DemoData` poza contextami (jak `PaymentSimulator`), z jedną warstwą Deptrac
  z dostępem tylko do `Native`, `Vendor` i `Shared_Infrastructure`. Pisze tabele wszystkich modułów przez `COPY`
  i SQL, bez importu klas contextów; reguły, które powtarza, pilnują testy kontraktu porównujące wynik generatora
  z kodem modułów (testy nie podlegają Deptrac).
- **P1a. Reguły z warstw Domain zamiast kopii.** Warstwa `DemoData` może dodatkowo zależeć od `Catalog_Domain`,
  `Pricing_Domain` i `Ordering_Domain` (czysty PHP bez infrastruktury; `DemoData` jest ujściem, od którego nic nie
  zależy). Generator woła istniejące reguły i value objecty dla wartości (`PriceBreakdown`, `PromotionalPrice`,
  `LineAmounts`, `OrderNumber`, `Slug`), nigdy agregatów ani repozytoriów. Testy kontraktu zostają tylko dla reguł
  spoza Domain (klucz zdjęcia, `values_key`, ścieżka kategorii).
- **P2. Kolejność prac.**
  1. Ten epik (części 1 i 2, P11): generator, pomiar na k3d i przebudowa indeksu odporna na błędy. Po nim
     obowiązkowy pomiar `full` na k3d i raport (W3).
  2. Epik poprawek wąskich gardeł na podstawie raportu (ryzyka H1–H4 niżej to hipotezy do sprawdzenia).
  3. Epik wdrożenia danych na serwer demo z pomiarem na serwerze i kopiami przy dużej bazie: #484.
  Ten epik nie zmienia profilu `prod` chartu.
- **P3. Warianty na produkt.** Średnio około 3 warianty na produkt w ogonie (około 40% produktów z jednym wariantem:
  mapy, książki, żywność, akcesoria; pozostałe 2–8).
- **P4. Historia stanów magazynowych.** Ruchy stanów i rekordy rezerwacji zakończonych tylko dla zamówień
  z ostatnich 90 dni przed chwilą odniesienia; wariant z ruchami zaczyna okno ruchem bilansu otwarcia, starsze
  zamówienia nie mają ruchów (jak po archiwizacji). Aktywne rezerwacje zawsze.
- **P5. Konta wyłączone.** Wygenerowani klienci i pracownicy są w Keycloaku wyłączeni (`enabled: false`) i bez
  poświadczeń: nie da się na nie zalogować ani zamówić dla nich resetu hasła (realm `shop` ma
  `resetPasswordAllowed`, a poczta Keycloaka w `prod` idzie przez Brevo). Istnieją jako tożsamości, na które wskazują
  zamówienia i historia działań.
- **P6. Pula zdjęć.** Około 400 zdjęć z Unsplash (Unsplash License), w tonacji obecnych zdjęć, przypisanych do typów
  produktów (3–6 na typ), wybranych i przeskalowanych do 1600 px przez zadanie epiku; archiwum w prywatnym magazynie
  (P10), w repo manifest z autorami, licencjami i sumami kontrolnymi. Testy i profil `small` używają 37 obecnych zdjęć
  z `apps/api/fixtures/images`.
- **P7. Okres historii zamówień.** 3 lata przed chwilą odniesienia, z rosnącym ruchem i sezonowością.
- **P8. Bez danych „w toku”.** Generator nie tworzy zamówień nieopłaconych (`new`) ani aktywnych promocji: zamówienie
  `new` wygasa tylko przez zaplanowaną wiadomość `ExpireUnpaidOrder`, a koniec promocji przez wiadomość granicy
  (`docs/features/promotions/spec.md`), a generator wiadomości nie planuje. W danych są zamówienia opłacone i dalej
  oraz anulowane, promocje zakończone; aktywne promocje i kody z seeda działają jak dziś.
- **Zgłoszenie sekretu.** Rozdzielenie sekretu administratora Keycloaka od sekretu aplikacji: #485.
- **P9. Konta w Keycloak zostają w tym epiku.** Recenzja architektury proponowała rezygnację, bo w tym epiku kont nic
  nie czyta (API bierze tożsamość z tokenu, zamówienia mają kopię danych klienta, k6 się nie loguje); konta zostają,
  bo skala Keycloaka (1 mln klientów, 500 pracowników) jest częścią demo (U1), a W1 wymaga ich spójności z danymi.
- **P10. Prywatny magazyn zdjęć.** Pula nie jest publikowana jako ogólnodostępne archiwum (bez wydania GitHub), żeby
  nie redystrybuować kolekcji z Unsplash. Archiwum leży w prywatnym buckecie Cloudflare R2 (konto użytkownika, jak
  kopie zapasowe), w niezmiennych wersjach, z weryfikacją SHA-256 (sekcja „Zdjęcia”). W repo zostaje manifest.
- **P11. Dwa końcowe PR.** Jeden epik w specyfikacji, z dotychczasowymi kryteriami i kolejnością zależności,
  dostarczany w dwóch częściach mergowanych po kolei, każda z własnym końcowym PR i własnymi kontrolami CI: część 1
  (#482) generator i jego testy (zadania DEMO-DATA-1–6), część 2 (#486, DEMO-LOAD) integracja z k3d, k6 i przebieg
  `medium` (dawne DEMO-DATA-7–9). AO prowadzi epik jako jedną gałąź i jeden końcowy PR, więc część 2 ma własne issue
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
- **W3. Pełna skala dopiero po pomiarze.** Przebieg `medium` i ekstrapolacja nie zastępują pomiaru `full`. Po epiku
  obowiązkowo wykonujemy pomiar na 10 mln produktów i 50 mln zamówień na k3d, zanim zaczną się optymalizacje
  (P2, krok 2) i przygotowanie wdrożenia na worf.pl (#484). Do tego czasu ani dokumentacja, ani PR, ani raport nie
  przedstawiają pełnej skali jako zweryfikowanej: wyniki `medium` są opisane jako `medium`, a ekstrapolacja jako
  niezweryfikowana.

## Poza zakresem

- Dostęp do demo (rezygnacja z nocnego resetu w #179, rola tylko do odczytu, konta dla rekruterów, Cloudflare
  Access przed panelem, #119, #332): osobny epik.
- Poprawki wąskich gardeł wykrytych pomiarem (P2, krok 2): osobny epik z raportu pomiaru `full`.
- Dane w skali na serwerze demo, pomiar na serwerze, kopie zapasowe przy dużej bazie i sekret odczytu puli zdjęć
  w klastrze serwera (P2, krok 3): #484.
- Profil `prod` chartu: bez zmian w tym epiku.
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

Szacunek rozmiaru z pomiaru na schemacie z migracji (Postgres 18, ładowanie do tabel z indeksami): około 1,1 KB na
produkt, około 1,2 KB na wariant z ceną, historią i stanem, około 2,6 KB na zamówienie poza oknem ruchów i około
4,6 KB w oknie:

| Profil | Postgres | Elasticsearch (szacunek) | Keycloak |
|---|---|---|---|
| `server` | około 62 GB | około 20 GB | około 1,7 GB |
| `full` | około 190 GB | około 20 GB | około 1,7 GB |

Rzeczywiste rozmiary i czasy podaje raport pomiaru.

### Realizm

- **Katalog.** Obecne ~200 produktów z seeda zostają bez zmian (E2E, `catalog.yaml`). Ogon powstaje ze słowników
  w `apps/api/fixtures/demo-data/`: drzewo kategorii rozszerzone do około 100 kategorii końcowych w tych samych
  działach, około 120 typów produktów (osie wariantów, klasa podatkowa, przedział cen, grupa zdjęć), około 300
  fikcyjnych marek, nazwy modeli i fragmenty opisów po polsku. Nazwa jak dziś: „<typ> <marka> <model>”, slug ze
  slugify nazwy z sufiksem z globalnego indeksu produktu, więc unikalny z konstrukcji, niezależnie od części i liczby
  procesów, i rozłączny ze slugami `catalog.yaml` (sprawdza to `verify`). Opisy z fragmentów cech typu, bez tekstu zastępczego. Około
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
  (zwrot rozliczony, jeśli jest starszy niż 14 dni), około 6% `cancelled` (nieopłacone po terminie, z historią
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

- Płaski moduł `apps/api/src/DemoData` (warstwa Deptrac `DemoData`: `Native`, `Vendor`, `Shared_Infrastructure`).
  Żaden context od niego nie zależy, on od żadnego contextu.
- Skrypt `apps/api/bin/generate-demo` (`sh`, `set -eu`, jak `bin/seed-demo`): najpierw `bin/seed-demo` (reset
  i katalog z `catalog.yaml`), potem kroki generatora, `catalog:reindex`, `storefront:cache:purge-all`. Profil, chwila
  odniesienia i liczba procesów z argumentów; w `prod` wymaga `--force-reset` jak seedy. Błąd kroku przerywa skrypt
  z niezerowym kodem; przerwane generowanie zaczyna się od nowa (reset seedów to `TRUNCATE`).
- Kroki (komendy `demo-data:*`) w kolejności:
  1. katalog: kategorie, produkty, warianty, przypisania kategorii;
  2. zdjęcia: archiwum puli do Garage i klucze zdjęć produktów;
  3. ceny z historią, zakończone promocje i historyczne kody rabatowe;
  4. konta w Keycloak;
  5. zamówienia z liniami, historią statusów, płatnościami i użyciami kodów;
  6. stany, rezerwacje i ruchy stanów: jedno przejście SQL po zamówieniach (sekcja „Stany po zamówieniach”);
  7. sekwencja numerów zamówień (`setval` powyżej najwyższego numeru);
  8. `demo-data:verify`.
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
- Wyjątek od persystencji przez rekordy ORM (`apps/api/CLAUDE.md`): `DemoData` pisze przez `COPY` i SQL; zapisany
  w `apps/api/CLAUDE.md` z listą tabel, które generator wypełnia.

### Identyfikatory, czas i determinizm

- Chwila odniesienia: opcja `--anchor` (domyślnie początek bieżącej doby UTC). Wszystkie czasy generatora liczą się
  od niej, nie od zegara.
- Kategorie, produkty, warianty, konta, historyczne kody: UUID v5 z nazw (`category:…`, `product:…`, `variant:…`,
  `customer:<n>`, `staff:<n>`, `code:<n>`) w przestrzeni z `profiles.yaml`, innej niż `catalog.yaml`.
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
  rezerwacja `committed` dla zamówień wysłanych i `released` dla anulowanych;
- `reserved` wariantu = suma linii aktywnych rezerwacji; `physical` = stan z rozkładu, nie mniejszy niż największa
  suma rezerwacji jednocześnie aktywnych w oknie (więc żaden ruch nie narusza `physical >= reserved`, a bilans
  otwarcia wariantu z zamówieniami w oknie jest dodatni); około 8% wariantów bez zamówień w oknie ma stan 0;
- ruchy w oknie: bilans otwarcia (`receipt`, źródło `seed`, `reference` = `opening:<variantId>`) na początku okna,
  potem `reservation` przy złożeniu, `commit` przy wysyłce albo `release` przy anulowaniu (źródło `system`,
  `reference` = klucz rezerwacji, jak w aplikacji), z `physical_after`
  i `reserved_after` z sum narastających; bilans otwarcia = stan końcowy + zatwierdzone w oknie; ostatni ruch zgadza
  się ze stanem;
- warianty bez ruchów w oknie: rekord stanu bez historii (jak `CreateEmptyStock`) albo przyjęcie `seed` przy stanie
  > 0 (jak `inventory:seed-demo`).

### Spójność (sprawdzenie po załadowaniu)

`demo-data:verify` (ostatni krok skryptu, też osobno) kończy się kodem 1 i listą naruszeń, gdy:
- opublikowany produkt nie ma wariantu albo wariant nie ma ceny lub rekordu stanu;
- `reserved` wariantu różni się od sumy aktywnych linii rezerwacji, ostatni ruch wariantu nie zgadza się ze stanem
  albo zamówienie `paid` nie ma aktywnej rezerwacji;
- suma linii zamówienia minus rabat różni się od sumy zamówienia, opłacone zamówienie nie ma płatności z tą kwotą
  albo zamówienie z kodem nie ma użycia kodu;
- cena jednostkowa linii różni się od ceny skutecznej wariantu w chwili złożenia albo linia w okresie promocji nie ma
  jej kopii;
- zamówienie klienta wskazuje klienta spoza wygenerowanych kont;
- liczby w tabelach różnią się od profilu albo slug produktu z ogona nie jest unikalny.
W profilach `medium`, `server` i `full` sprawdzenie ceny linii obejmuje deterministyczną próbkę zamówień (co k-te
według indeksu), w `small` wszystkie; pozostałe sprawdzenia są pełne.
Ograniczenia `CHECK` w tabelach odrzucają wiersz już przy `COPY`.

### Konta w Keycloak

- Krok kont tworzy pracowników (realm `backoffice`, z rolą) i klientów (realm `shop`) przez REST administratora
  Keycloaka (`partialImport`, `ifResourceExists=SKIP`, paczki po 2000, 4 strumienie), z identyfikatorem wyliczonym.
  Eksperyment na Keycloak 26.6.0 z Postgresem: identyfikator z importu zostaje, około 770 kont/s na komputerze
  użytkownika (1 mln w około 22 min).
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
  pliku (archiwum to tylko transport), grupą typu, tekstem alternatywnym, stroną źródła, autorem i licencją (Unsplash License).
  Bez adresu bucketu i bez danych dostępu.
- **Dostęp.** Dwa tokeny API R2 ograniczone do tego bucketu: zapis i odczyt dla wysyłki puli (zadanie epiku), tylko
  odczyt dla generatora. Endpoint, nazwa bucketu i klucze są w pliku użytkownika poza repo (domyślnie
  `~/.config/shop/demo-photos.env`, zmienne `DEMO_PHOTOS_*`); narzędzia czytają go bez wypisywania wartości. W CI
  i profilu `small` dostęp nie jest potrzebny.
- **Pobieranie.** Krok zdjęć dla profili innych niż `small` pobiera archiwum przez osobny filesystem Flysystem
  (adapter AsyncAws S3, już w zależnościach) z danymi odczytu ze zmiennych `DEMO_PHOTOS_*`, sprawdza SHA-256 każdego
  pliku i komplet plików z manifestu (niezgodność: błąd bez zmian w Garage i w bazie), zapisuje każdy plik raz do Garage pod kluczem
  `originals/<sha256>.<rozszerzenie>` (format `FlysystemProductImageStorage`; test kontraktu) i przypisuje produktom
  klucze z grupy typu. Bez zmiennych `DEMO_PHOTOS_*` profil inny niż `small` kończy się błędem przed pierwszym krokiem.
  Profil `small` bierze 37 plików z `apps/api/fixtures/images` bez pobierania.
- **Wysyłka.** Zadanie epiku wybiera zdjęcia z CDN `images.unsplash.com`, skaluje je, buduje archiwum, wysyła je
  skryptem `apps/api/bin/upload-demo-photos` (obraz `rclone` przypięty digestem jak w chartcie, `copyto --immutable`,
  więc istniejąca wersja nie zostanie nadpisana) z tokenem zapisu i commituje `photos.yaml`. Przed wysyłką każdy plik
  przechodzi te same reguły co upload w Catalog (typ po zawartości, rozmiar, wymiary). Archiwum ani zdjęcia puli nie
  trafiają do repo.

### Przebudowa indeksu wyszukiwarki

Dziś pojedynczy przejściowy błąd zapisu partii przerywa przebudowę i usuwa nowy indeks
(`ProductSearchIndexRebuilder.php:137-139`), a przy 10 mln produktów przebudowa trwa godzinami. Zmiany:
- adapter Elasticsearcha ponawia pozycje partii z wyniku `transient` (`BulkResult`) i przejściowe błędy całego
  żądania, z narastającym odczekaniem, do 5 prób; zapis z `version_type: external_gte` jest bezpieczny do powtórzenia;
  trwały błąd albo wyczerpane próby kończą przebudowę jak dziś;
- na czas wypełniania nowy indeks ma wyłączone odświeżanie (`refresh_interval: -1`), przywracane przed weryfikacją
  i przełączeniem aliasu;
- postęp (liczba dokumentów, tempo) w logu co partię.
Aktualizacja `docs/features/search/spec.md`.

## Środowiska

### Testy i lokalnie

- Testy API (`make -C apps/api test`, w CI w jobie `checks`) uruchamiają kroki `demo-data:*` z profilem `small`
  osobno, na bazie `shop_test`, bez `bin/seed-demo` i bez czyszczenia cache sklepu (worker i Varnish nie obsługują
  `shop_test`): w procesie testu, w transakcji testu, a test determinizmu przy innej liczbie procesów w osobnej grupie
  z czyszczeniem tabel po sobie. Krok kont w testach pracuje na tymczasowych realmach zakładanych i usuwanych przez
  test, nigdy na realmach stosu deweloperskiego, z przestrzenią nazw unikalną dla przebiegu testu (identyfikatory
  użytkowników są globalne w Keycloaku). Skrypt `bin/generate-demo` testuje się jak `bin/seed-demo`
  (`tests/Unit/Bin`, podstawiona konsola). Cały skrypt na prawdziwych usługach: w części 1 `make seed-scale
  PROFILE=small` na stosie zadania (wynik w PR), w części 2 test dymny k3d.
- Stos E2E nie uruchamia generatora.
- Cel `make seed-scale [PROFILE=small]` (fragment `make/demo-data.mk`) uruchamia `bin/generate-demo` w kontenerze
  `api`, z danymi administratora Keycloaka stosu i (dla profili innych niż `small`) zmiennymi `DEMO_PHOTOS_*` z pliku
  użytkownika przekazanymi do procesu. Po nim `make seed` przywraca dane, na których działają testy E2E.

### k3d (chart w profilach `ci` i `load`; część 2)

- Zawieszony CronJob `demo-data` (wzorzec CronJoba `restore`) tylko gdy `demoData.enabled` (profile `ci` i `load`;
  `prod` bez zmian): obraz api, `bin/generate-demo` z profilem i chwilą odniesienia z wartości, sekret stosu,
  login administratora Keycloaka jak w Jobie `keycloak-config`, zasoby `resources.demoData`, limit czasu z wartości
  per profil (początkowo `small` 30 min, `medium` 2 h, `server` 8 h, `full` 16 h; przebieg `medium` je kalibruje), bez
  ponowień.
- Test dymny (`make -C infra/k8s smoke`, w CI w `deploy.yml`) uruchamia Job z tego CronJoba z profilem `small`
  i sprawdza kod 0.
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
  się kodem 2. Szacunek z tej specyfikacji: `medium` około 25 GB, `server` około 100 GB, `full` około 225 GB;
  przebieg `medium` w zadaniu epiku uaktualnia `diskPeakGb` według zmierzonych rozmiarów.
- Wynik `load` zawiera czasy kroków generatora i rozmiary tabel i indeksów (do raportu).

### Pomiar k6 (część 2)

- `load/lib/data.js` bierze klucze porcji z `/api/v1/sitemap/product-chunks` (albo z opcji, gdy podaje je `load`),
  produkty z losowych porcji (`/api/v1/sitemap/products?chunk=…`), nazwy do fraz wyszukiwania z danych tych
  produktów w API, a facety z listingu kategorii, nie z listingu całego katalogu; liczba porcji i produktów z opcji.
  Na danych z seeda wynik jak dziś (`make load-smoke`).

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
  - przypadkowe zastąpienie danych: CronJob `demo-data` zawieszony i tylko w profilach `ci` i `load`; sprawdzenie:
    test renderu (`suspend: true`, brak w `prod`);
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
   nieudanych próbach; trwały błąd i wyczerpane próby zachowują się jak dziś.
9. (część 2) Chart: CronJob `demo-data` zawieszony, tylko z `demoData.enabled`, z zasobami i limitem czasu z wartości; profil
   `load` bez kopii; profil `prod` renderuje się tak jak przed epikiem; `make -C infra/k8s check` przechodzi,
   a selftest łapie brak `suspend`.
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
    `docs/vision.md` („Skala docelowa”: pomiar na danych w skali i obowiązkowy pomiar `full` przed optymalizacjami
    i wdrożeniem na serwer; bez twierdzenia, że pełna skala jest zweryfikowana, W3), `infra/k8s/CLAUDE.md` (`LOAD_DATA`,
    CronJob `demo-data`), `docs/features/hardening/spec.md` (opis `load`), `load/README.md`.
15. `make seed-scale PROFILE=small` na stosie zadania kończy się kodem 0 (cały skrypt na prawdziwych usługach, wynik
    w PR części 1), a `make seed` po nim przywraca dane E2E.
16. Pula zdjęć (P10): obiekt archiwum w prywatnym buckecie istnieje pod kluczem z manifestu, sumy plików zgadzają
    się z manifestem, każdy plik spełnia reguły uploadu Catalog, bucket nie ma dostępu publicznego, a skrypt wysyłki
    przekazuje `--immutable` (test skryptu z podstawionym `docker`).

## Ryzyka

Hipotezy z analizy kodu do sprawdzenia pomiarem (P2), z miejscami w kodzie:
- **H1.** Listing i wyszukiwarka sklepu liczą dokładną liczbę wyników i agregacje zagnieżdżone przy każdym chybieniu
  cache; strona główna bez kategorii agreguje cały katalog (`ProductSearchQuery.php:36,117-184`), limit zapytania
  2 s, sterta Elasticsearcha 2 GB w `prod`.
- **H2.** Stronicowanie sklepu bez górnej granicy (`ListProductsQuery.php:40-41`, `Pagination.tsx`): przy 10 mln
  produktów setki tysięcy stron, każda z pełnym zapytaniem.
- **H3.** Pulpit panelu co 30 s liczy `COUNT(*)` produktów i produktów bez zdjęć bez indeksu
  (`DoctrineProductRepository.php:95-108`, `useDashboard.ts:18`).
- **H4.** Wyszukiwarka produktów w panelu z filtrem kategorii (`DbalBackofficeProductSource.php:23-33`): warunek
  `OR` z `EXISTS` nie jest warunkiem indeksu.
- **H5.** Przebudowa indeksu trwa godzinami (około 208 tys. wywołań `GetProductOffers` przy 10 mln produktów); ten
  epik zmniejsza ryzyko przerwania, nie czas.
- Brak `statement_timeout` na połączeniu bazy; czyszczenie całego cache sklepu przy każdym wydaniu; cache ISR sklepu
  na dysku poda bez limitu; `count(*)` na pełnych tabelach w Jobie `initialize` przy każdym wdrożeniu.
- Szacunki rozmiaru i czasu pochodzą z eksperymentów na komputerze użytkownika (SQL po stronie serwera, bez PHP);
  przebieg `medium` w zadaniu epiku je kalibruje, ale nie weryfikuje pełnej skali (W3).
- Usuwanie kont generatora po zmianie przestrzeni nazw albo słowników to do miliona pojedynczych wywołań REST
  Keycloaka, a każdy przebieg kroku kont przegląda pełną listę członków grupy (czas nieznany, zmierzy go przebieg
  `medium` w części 2).

## Wymagane przed startem

- Prywatny bucket Cloudflare R2 na pulę zdjęć (P10), bez dostępu publicznego, i dwa tokeny API ograniczone do niego:
  zapis i odczyt (wysyłka puli, zadanie DEMO-DATA-6) oraz tylko odczyt (generator); endpoint, bucket i klucze w pliku
  `~/.config/shop/demo-photos.env` (zmienne `DEMO_PHOTOS_ENDPOINT`, `DEMO_PHOTOS_BUCKET`,
  `DEMO_PHOTOS_WRITE_ACCESS_KEY_ID`, `DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY`, `DEMO_PHOTOS_ACCESS_KEY_ID`,
  `DEMO_PHOTOS_SECRET_ACCESS_KEY`) — użytkownik: bucket, tokeny i plik przed `/ao-run` (wartości nigdy nie przechodzą
  przez rozmowę).
- Pobieranie zdjęć z CDN `images.unsplash.com` bez klucza API: istnieje (tak powstały zdjęcia VISUAL-DESIGN-11,
  PR #357; link „Download” blokuje ochrona przed botami).
- (część 2) Przebieg `LOAD_DATA=medium` w zadaniu: Docker na komputerze użytkownika z co najmniej 10 CPU, 20 GiB pamięci
  (wymagania `load`) i około 32 GB wolnego miejsca (1,25 × szacunek `medium`, W2) w VM i na dysku komputera — istnieje (2026-11-13: 16 CPU, 31 GiB, w VM 873 GB wolnego,
  na dysku komputera 279 GiB wolnego).

## Po epiku

Kroki sesji za zgodą użytkownika po części 2, nie zadania AO:
1. Obowiązkowy pomiar `full` (W3) na k3d: `make -C infra/k8s load LOAD_DATA=full` na komputerze użytkownika, raport
   w `docs/reports/`, zgłoszenia wąskich gardeł do epiku poprawek (P2, krok 2). Dopiero po nim zaczynają się
   optymalizacje i przygotowanie #484. Cel sam odmówi startu bez zapasu miejsca (W2): przy szacunku `full` około
   225 GB potrzeba około 281 GB wolnego na dysku komputera, a 2026-11-13 wolne było 279 GiB (około 300 GB), więc zapas
   może wymagać zwolnienia miejsca po kalibracji z przebiegu `medium`.
