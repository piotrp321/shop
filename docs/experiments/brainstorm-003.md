# Eksperyment brainstorm-003: `/brainstorm` v0.5 vs `superpowers:brainstorming`

Data: 2026-10-30. A = nasz `/brainstorm` v0.5 (`b3cb1b6`), B = `superpowers:brainstorming` (plugin z
`.claude/settings.json`). Pięć nowych zadań, po jednej sesji A i B na zadanie. Obie sesje kończą na zapisanej
i zaakceptowanej specyfikacji, bez planu implementacji. Nowość względem 002: Product Owner nie akceptuje
automatycznie, tylko weryfikuje kompletny projekt względem klucza i zgłasza poprawki.

## Werdykt

**Główne pytanie: tak, w tym eksperymencie v0.5 osiąga jakość co najmniej porównywalną z Superpowers przy wyraźnie
mniejszej liczbie interakcji. Nie jest jednak tańsza ani szybsza w czasie pracy modelu.**

- **Pierwsze projekty (przed uwagami PO): A lepsza w 4 z 5 par.** Średnia z 10 ślepych ocen (oryginalne X/Y
  i zamienione): A 3,98, B 3,51. Wyjątek to warianty produktów: B 4,15, A 3,8.
- **Końcowe specyfikacje: A 4,20, B 4,05.** A wygrywa w trzech parach (koszyk, anulowanie, alerty), B w dwóch
  (warianty, zdjęcia). Po korekcie różnica jest niewielka.
- **Interakcje.** A potrzebowała 5,2 wiadomości właściciela na sesję, B 15,0. A miała 1,2 rundy pytań, B 7,6.
  A nie dostała żadnego pustego zatwierdzenia sekcji, B 16 łącznie.
- **Czas i koszt bez przewagi A.** Czas modelu: A 570 s, B 553 s. Koszt: A $2,47, B $2,31 na sesję.
  Zbiorcza runda pytań i jeden duży projekt przesuwają pracę z wielu krótkich rund do dwóch długich.

Odwrócenie względem brainstorm-002 (tam B 4,04 vs A 3,08) wynika głównie z tego, że v0.5 zadaje teraz więcej pytań
biznesowych niż B: 87 wobec 48 i 70 punktów klucza uzyskanych pytaniami wobec 53. Przy pięciu parach nie jest to
wynik rozstrzygający statystycznie.

## Metoda

- **Kod.** `git archive b3cb1b6` w 10 katalogach `b003-runs/sNN/shop`, w każdym `git init` i jeden commit
  z ustaloną datą. Wszystkie mają commit `bcaf99c` i drzewo `20b7910` (= drzewo `b3cb1b6`). W eksporcie nie ma
  `docs/experiments` ani historii. Auto-memory była pusta.
- **Sesje.** `claude -p` (Claude Code 2.1.308) z `--model claude-opus-5-5 --effort high --strict-mcp-config
  --permission-mode acceptEdits`, każda runda przez `--resume`.
  - Narzędzia jak w 002: Read, Glob, Grep, Write, Edit, Skill, Agent, WebSearch, WebFetch i Bash tylko do odczytu.
  - Dozwolone `git add/commit` i (nowość) `git checkout -b`, `git switch -c`, `git branch`.
  - Zablokowane: `docker`, `make`, `rm`, `git push`.
  - Wszystkie 10 sesji szło równolegle (15:26–15:42 UTC).
- **Przydział.** Losowy w parze (`mapping.json`): t1 s01/s02, t2 s03/s04, t3 s05/s06, t4 s08/s07, t5 s09/s10 (A/B).
- **Brief.** Prompt to `/brainstorm <brief>` albo `/superpowers:brainstorming <brief>`, z identycznym tekstem.
  - Brief to treść zadania z polecenia oraz jedno zdanie o brakujących zależnościach w repo, np. „koszyka jeszcze
    nie ma; nie ma też cen, stanów…”.
  - Brief nie narzuca architektury.
  - Żadne zadanie nie powtarza funkcji z 001/002.
- **Weryfikacja Skilli.** W transkryptach (`~/.claude/projects/...b003-runs-sNN-shop`) każda sesja ma jeden
  „Base directory for this skill”:
  - A → `.claude/skills/brainstorm`,
  - B → `plugins/.../skills/brainstorming`.
  Żadna sesja nie załadowała Skilla drugiej strony.
- **Klucz biznesowy.** `answer-key.md` ma 17–18 punktów na zadanie: cel i zakres MVP, zachowania, uprawnienia,
  reguły i limity, przypadki brzegowe, błędy, kryteria akceptacji.
  - Spisany przed startem, z hashem SHA-256 w `answer-key.sha256`, i potem niezmieniany.
  - Tekst punktu jest kanoniczną odpowiedzią: PO przekazuje go dosłownie, więc ta sama kwestia dostaje w obu
    sesjach pary identyczny tekst.
- **Product Owner.** Każda sesja miała własny proces PO: `claude -p` Opus, effort medium, bez narzędzi, wznawiany
  przez `--resume`.
  - Proces PO dostał tylko reguły (`po-rules.md`), klucz swojego zadania i brief. Nie wiedział, czy rozmawia z A,
    czy z B. Odpowiadał w JSON-ie z klasyfikacją wiadomości, liczbą pytań, przekazanymi punktami (osobno „na
    pytanie” i „w korekcie”) i weryfikacją projektu.
  - Reguły:
    - pytanie techniczne → „przyjmuję Twoją rekomendację”,
    - pytanie biznesowe spoza klucza → „zdecyduj sam, zapisz założenie”,
    - ogólne „coś jeszcze?” → nic nie ujawnia,
    - sekcja → „Tak, zgadza się.”, chyba że jest sprzeczna z punktem już przekazanym.
  - Prośba o akceptację całości → weryfikacja względem całego klucza i korekta z listą braków i sprzeczności
    (maksymalnie 3 rundy). To samo przy zapisanej specyfikacji.
  - Zamknięcie dla obu stron: „Akceptuję specyfikację. Na tym etapie kończymy: nie przygotowuj planu…”.
- **Ślepa ocena.** Jedna sesja `claude -p` (Opus, effort high, tylko odczyt) na parę, w katalogu z eksportem repo
  bez `.claude`.
  - Recenzent dostał:
    - pierwszy projekt każdego autora (wszystkie części od pierwszej sekcji do pierwszej weryfikacji PO),
    - końcową specyfikację z diffem innych plików,
    - odpowiedzi PO z oznaczeniem trybu ujawnienia,
    - klucz i brief.
  - Recenzent nie znał nazw Skilli, kosztów ani wcześniejszych ocen.
  - Ponieważ losowanie dało A jako X w 4 z 5 par, powtórzyłem każdą ocenę z zamienionymi etykietami
    (`r{t}s`). Wyniki podaję jako średnią obu ocen.
  - Istotne zarzuty recenzentów sprawdziłem w kodzie (sekcja „Weryfikacja ocen”).

### Odstępstwa od metodologii

1. **PO to proces modelu, a nie ja.** Dzięki temu PO był ślepy na przydział (w 002 nie był) i obsłużył skalę
   10 równoległych sesji. Kosztem jest niepełna konsekwencja PO, opisana w p. 2.
2. **Niespójność PO na korzyść A (t5).**
   - s09 (A) miała już w pierwszym projekcie zawężenie odbiorców do roli `warehouse` nadanej bezpośrednio, a PO tego
     nie zgłosił.
   - s10 (B) dopisała to samo zawężenie do specyfikacji i dostała korektę (T5-K06).
   - A uniknęła przez to jednej rundy korekty specyfikacji. Recenzenci ocenili tę lukę w specyfikacji A jako błąd
     (K06), więc oceny jakości tego nie maskują.
3. **PO ocenia zapisaną specyfikację na podstawie streszczenia agenta** (reguła 12), a nie pliku. Zmiany ukryte
   w pliku może przeoczyć. Recenzent widział pełny plik.
4. **Drobne odchylenie od dosłowności.** W 5 z 147 przekazań PO pominął etykietę punktu („Zakres i kiedy
   budować:”), treść była identyczna.
5. **Pierwszy projekt s10 (B)** to jedna wiadomość z 7 sekcjami (793 słowa). Porównanie wariantów było rundę
   wcześniej i nie trafiło do materiału recenzenta. Wpływ jest niewielki, ale na niekorzyść B w ocenie pierwszego
   projektu t5.
6. **Brak stosu.** Nikt nie uruchamiał kodu; twierdzenia o repo są sprawdzane tylko statycznie.
7. **Jeden recenzent-model na ocenę.** Ocena X↔Y różniła się o 0,2–0,6 na sesję. Kierunek zmienił się tylko
   w pierwszych projektach t3 (A 3,8→3,6, B 3,4→3,8) i końcowych t2 (A 4,0→4,8).

## Wyniki zbiorcze

Czas = suma `wall` wywołań agenta (bez czasu PO). Koszt = `total_cost_usd` ostatniej rundy (wartość narasta przy
`--resume`). Rundy = wiadomości właściciela łącznie z zamknięciem.

| Zadanie | Skill | Rundy | Czas | Do 1. projektu | Do akceptacji | Wyjście (tok.) | Odczyt cache | Zapis cache | Koszt |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 1 Koszyk gościa | A | 5 | 602 s | 371 s | 492 s | 59 640 | 1,81 M | 136 k | $2,64 |
| | B | 13 | 455 s | 268 s | 331 s | 40 291 | 1,84 M | 96 k | $1,94 |
| 2 Warianty | A | 6 | 812 s | 629 s | 710 s | 67 645 | 2,30 M | 144 k | $2,97 |
| | B | 18 | 726 s | 506 s | 506 s | 59 914 | 3,63 M | 149 k | $3,12 |
| 3 Zdjęcia | A | 5 | 536 s | 353 s | 441 s | 52 642 | 2,93 M | 155 k | $2,88 |
| | B | 15 | 573 s | 370 s | 430 s | 45 377 | 3,65 M | 118 k | $2,58 |
| 4 Anulowanie | A | 5 | 404 s | 250 s | 316 s | 39 457 | 0,94 M | 94 k | $1,73 |
| | B | 17 | 461 s | 332 s | 366 s | 38 600 | 1,73 M | 80 k | $1,76 |
| 5 Alerty stanów | A | 5 | 498 s | 324 s | 407 s | 47 930 | 1,44 M | 110 k | $2,13 |
| | B | 12 | 550 s | 281 s | 345 s | 48 540 | 1,90 M | 100 k | $2,15 |
| **Średnia** | A | 5,2 | 570 s | 385 s | 473 s | 53 463 | 1,88 M | 128 k | $2,47 |
| | B | 15,0 | 553 s | 351 s | 396 s | 46 544 | 2,55 M | 108 k | $2,31 |

- Wejście bez cache to 32–94 tokeny na sesję.
- Czas PO (symulacja odpowiedzi właściciela): A 69 s, B 178 s na sesję. Koszt PO: A $0,36, B $0,50.
- Długość tekstu:
  - pierwszy projekt: A śr. 1698 słów, B 1772,
  - specyfikacja: A 2433, B 2887.
- Koszty łącznie:
  - sesje: A $12,34, B $11,54,
  - PO $4,30,
  - oceny: $9,24 oryginalne i $8,88 zamienione.
- Cały eksperyment trwał 15:26–15:53 UTC, a z ocenami do ok. 16:15.

Czas do akceptacji A jest dłuższy, bo pierwsza runda A (eksploracja i zbiorcze pytania) trwa 103–160 s, a runda
z projektem 164–392 s. B rozkłada pracę na wiele krótkich rund po 14–40 s. Dla realnego użytkownika B oznacza
jednak 15 wiadomości do przeczytania i odpowiedzi, a A tylko 5.

## Interakcja i discovery

| Miara (suma 5 sesji) | A | B |
|---|---:|---:|
| Rundy z pytaniami | 6 (1, 2, 1, 1, 1) | 38 (5, 10, 7, 9, 7) |
| Pytania biznesowe | 87 (12, 26, 14, 17, 18) | 48 (11, 12, 8, 9, 8) |
| Pytania techniczne / decyzje DDD do zatwierdzenia | 22 | 12 |
| Rundy sekcji projektu | 0 | 17 |
| Puste zatwierdzenia („Tak, zgadza się.”) | 0 | 16 |
| Korekty sekcji przed pełnym projektem (sprzeczność z przekazanym punktem) | 0 | 1 (s10) |
| Rundy korekty pełnego projektu | 5 (po 1 w każdej sesji) | 4 (s04 bez korekty) |
| Rundy korekty zapisanej specyfikacji | 0 (patrz odstępstwo 2) | 1 (s10) |
| Punkty klucza przekazane na pytanie (z 89) | **70** (12, 17, 12, 15, 14) | 53 (12, 15, 6, 11, 9) |
| Punkty ujawnione dopiero w korekcie | 7 (1, 1, 2, 1, 2) | 10 (1, 0, 3, 3, 3) |
| Pokrycie klucza w 1. projekcie (wg PO) | 81/89 | 79/89 |
| Pokrycie klucza w 1. projekcie (wg recenzenta, oryginalne oceny) | 79/89, w tym 16 trafnie założonych | 78/89, w tym 22 trafnie założone |
| Nieautoryzowane zmiany plików | 0 | 2 sesje: s02, s07 (`docs/vision.md`) |

Decyzje techniczne A w s05 (15) to „decyzje DDD do zatwierdzenia” podane razem z projektem, zgodnie z CLAUDE.md.
Nie były to pytania discovery.

**Sprzeczności i braki w pierwszych projektach (wg PO):**

| Para | A | B |
|---|---|---|
| 1 Koszyk | K15 sprzeczny (równoległe dodania → 409 bez ponowienia) | K15 sprzeczny (to samo) |
| 2 Warianty | K18 sprzeczny (wygrywa ostatni zapis, brak komunikatu kombinacji) | brak |
| 3 Zdjęcia | brak K09 (produkt bez zdjęć), K16 (postęp per plik) | K05 i K12 sprzeczne (limity z kodu, przyciski zamiast przeciągania), brak K07 (duplikat) |
| 4 Anulowanie | brak K15 (mimo że przekazany na pytanie), K16 (widok obsługi) | K13 sprzeczny (ogólny komunikat 409), brak K15, K17 |
| 5 Alerty | K10 sprzeczny (stan z chwili alertu, brak wariantu), brak K15 | brak K10, K14, K15 |

Obie strony zmieniały pliki wyłącznie po akceptacji projektu.

Git:
- A nie commitowała i została na `main`.
- B w 4/5 sesji założyła branch i zacommitowała specyfikację, bo tym razem było to dozwolone.
- s02 i s07 dodały do commitu zmiany `docs/vision.md`. Były zapowiedziane w projekcie, ale to zmiana dokumentacji
  przed kodem, wbrew CLAUDE.md.
- Ten sam błąd B był w 002 (s06, s07).

## Oceny jakości

Skala 1–5. Wartości to średnia z dwóch ocen: oryginalnej i z zamienionymi etykietami.

### Pierwsze projekty

| Para | A | B | Lepszy |
|---|---:|---:|---|
| 1 Koszyk | 4,1 | 3,5 | A |
| 2 Warianty | 3,8 | 4,15 | B |
| 3 Zdjęcia | 3,7 | 3,6 | remis (kierunek zależał od etykiet) |
| 4 Anulowanie | 4,3 | 3,4 | A |
| 5 Alerty | 4,0 | 2,9 | A |
| **Średnia** | **3,98** | **3,51** | |

| Kryterium (średnia z 10 ocen) | A | B |
|---|---:|---:|
| 1. Kompletność wymagań | 4,0 | 3,6 |
| 2. Architektura i prostota | 3,9 | 3,6 |
| 3. Zgodność z kodem i CLAUDE.md | **4,5** | 3,6 |
| 4. Przypadki brzegowe | 3,5 | 3,6 |
| 5. Gotowość do implementacji | 4,0 | 3,15 |

### Zaakceptowane specyfikacje

| Para | A | B | Lepszy |
|---|---:|---:|---|
| 1 Koszyk | 4,3 | 3,8 | A |
| 2 Warianty | 4,4 | 4,55 | B (oryginalna ocena B 4,6/A 4,0, zamieniona A 4,8/B 4,5) |
| 3 Zdjęcia | 3,7 | 4,4 | B |
| 4 Anulowanie | 4,5 | 4,0 | A |
| 5 Alerty | 4,1 | 3,5 | A |
| **Średnia** | **4,20** | **4,05** | |

| Kryterium (średnia z 10 ocen) | A | B |
|---|---:|---:|
| 1. Kompletność wymagań | 4,4 | 4,6 |
| 2. Architektura i prostota | 3,7 | 3,6 |
| 3. Zgodność z kodem i CLAUDE.md | **4,5** | 3,55 |
| 4. Przypadki brzegowe | 3,9 | **4,35** |
| 5. Gotowość do implementacji | 4,5 | 4,15 |

Korekta PO podniosła B bardziej niż A (+0,54 wobec +0,22). B zaczynała z większymi lukami w kluczu, a po korekcie
jej specyfikacje są najmocniejsze w przypadkach brzegowych. Przewaga A w zgodności z repo (4,5 vs 3,55) utrzymuje
się po korekcie, bo korekta PO jej nie dotyczy.

## Wyniki A/B według zadań

### 1. Koszyk gościa i łączenie (A lepsza)

Obie sesje dostały te same 12 punktów klucza. Obie pominęły K15 (równoległe dodania nie mogą przepaść) i poprawiły
go po korekcie.

- **A (s01).** Kontrakt błędów zgodny z repo: 400 z `violations`, kody problemów, polskie komunikaty. Łączenie leniwe
  i idempotentne.
  - Overengineering przy K15: ponawianie w API i do tego Web Locks w przeglądarce.
  - Sekcja „zmiany dokumentacji do wykonania” w specyfikacji ma charakter planu.
- **B (s02).** Prostszy i mocniejszy mechanizm dla K15: `SELECT … FOR UPDATE` z `ON CONFLICT`.
  - 422 dla błędnych danych wejściowych, choć walidacja DTO daje w repo 400 (`ProblemDetailsSubscriber.php:111-114`).
  - Zakaz zmiany ilości pozycji niedostępnej pilnuje tylko UI.
  - Fałszywe twierdzenie: „`docs/adr/` jest pusty”, a katalog nie istnieje.
  - Zmiana `vision.md`, sprzeczna z `vision.md:294`.

### 2. Warianty produktów (B lepsza)

- **B (s04).** Jedyny pierwszy projekt bez żadnej korekty: 18/18 punktów klucza, w tym K17 i K18 założone
  trafnie. 15 punktów uzyskała pytaniami w 10 rundach.
  - Lista w panelu korzysta z istniejącego ES.
  - Luka: `access_control` dla nowego publicznego endpointu.
- **A (s03).** Najwięcej pytań w eksperymencie: 26 biznesowych w dwóch rundach, 17 punktów.
  - Mimo to przyjęła „wygrywa ostatni zapis”, sprzeczne z K18.
  - Lista panelu po `lower(name)` bez indeksu (`catalog_product` ma indeksy tylko na `id`, `slug`
    i `primary_category_id`, `Version20261029101125CreateCatalogProduct.php:19-21`), co przy 10 mln produktów jest
    realnym problemem skali.
  - Plus: testy obejścia `access_control` i jawnie zgłoszona zmiana zasady błędów.

### 3. Zarządzanie zdjęciami (pierwszy projekt remis, specyfikacja B)

- **A (s05).** Pytaniami uzyskała 12 punktów (B tylko 6). Najprostszy model: zapis od razu, bez szkicu galerii.
  - Przeoczyła, że `ProductIndexDefinition.php:80` odrzuca `main_image` z `alt`, który nie jest stringiem. Opcjonalny
    alt (K10) sprawiłby więc, że zdjęcie znika z kafelka.
  - K14 (awaria magazynu w środku paczki) opisany częściowo.
- **B (s06).** Limity uploadu przejęte z kodu bez pytania, wbrew K05; przyciski zamiast przeciągania, wbrew K12.
  - Najlepsza obsługa awarii aż do UI i wykrycie problemu `alt: null`.
  - Większa złożoność: `image_count` w ES z przebudową indeksu, wersja w agregacie, logika limitów w panelu.

### 4. Anulowanie zamówienia (A lepsza)

- **A (s08).** 17 pytań biznesowych w jednej rundzie; pierwszy projekt pokrył 16/18.
  - Pominęła jednak K15 (kody rabatowe), który właściciel podał w odpowiedzi.
  - Wykryła konflikt „Symfony Workflow” z czystą domeną (`deptrac.yaml:88-90`).
  - Payment jako jedyne źródło prawdy o pieniądzach.
  - Recenzent nie znalazł fałszywych twierdzeń o repo.
- **B (s07).** Przy wyścigu z wysyłką ogólny komunikat 409, wbrew K13.
  - Rozróżnienie błędów 409 po polu `type`, którego repo nie ustawia (zawsze `about:blank`,
    `ProblemDetailsSubscriber.php:100,128`).
  - 422 zamiast 400 dla błędnych danych wejściowych.
  - Workflow i reguły w agregacie bez wskazania źródła prawdy.
  - Zmiana `vision.md`.
  - Plus: zauważyła, że zamówień gości nie da się w praktyce anulować.

### 5. Alerty o niskich stanach (A lepsza)

- **A (s09).** Decyzja o alercie zapada w agregacie w transakcji zmiany stanu. Niewysłane alerty czekają w bazie.
  - Twierdzenia o repo trafne.
  - Luka: odbiorcy tylko z rolą nadaną bezpośrednio (K06), patrz odstępstwo 2.
- **B (s10).** Projekcja `StockLevelWatch` (ok. 10 mln wierszy) i agregat `LowStockDigest` to overengineering.
  - Fałszywe twierdzenia:
    - `failed` „widoczny na dashboardzie” (dashboard liczy tylko outbox i kolejki RabbitMQ,
      `MessagingStatisticsAdapter.php:21,35`),
    - `ADMIN_PUBLIC_URL` (istnieje `ADMIN_URL`, `infra/docker-compose.yml:229`),
    - „plan stanów magazynowych uwzględnia…” (takiego planu nie ma).
  - Brak retencji 90 dni (K17).
  - Plus: poprawna obsługa ról z grup Keycloak.

## Weryfikacja ocen

Sprawdziłem w eksporcie repo 11 istotnych zarzutów. Wszystkie się potwierdziły:

- `type: about:blank` w każdym problemie (`ProblemDetailsSubscriber.php:100,114,128`),
- walidacja DTO → 400 z `violations` (`:111-122`),
- `vision.md:294` (optimistic locking zamiast blokad),
- brak `docs/adr`,
- `events` ma `max_retries: 3` (`messenger.yaml:41-42`),
- Workflow w ramach reguły „Domain bez Vendor” (`deptrac.yaml:2,88-90`),
- `ProductIndexDefinition.php:80` (alt musi być stringiem),
- brak indeksu na `name` w `catalog_product`,
- `security.yaml:41` (publiczne tylko `products|categories` bez sufiksu),
- `ADMIN_URL` zamiast `ADMIN_PUBLIC_URL`,
- zawężenie ról w `designs/s09/final.md`.

**Obiektywne vs preferencje.**
- Do błędów liczę:
  - sprzeczności z kluczem,
  - fałszywe twierdzenia o kodzie,
  - 400/422,
  - niezgodność z `access_control`,
  - brak indeksu,
  - alt w indeksie,
  - zmiany `vision.md` przed kodem.
- Za preferencje uznaję:
  - Web Locks vs blokada wiersza,
  - wersja w agregacie vs w persystencji,
  - projekcja vs decyzja w agregacie,
  - ES vs Postgres dla listy panelu.

Przewaga A w kryterium 3 wynika z błędów obiektywnych: B ma co najmniej 8 fałszywych lub niezgodnych twierdzeń
o repo (s02, s07, s10). A ma kilka drobnych: `ProductPhoto` w panelu, `null://`, ogólne „Deptrac dopuszcza”.

## Skuteczność odkrywania wymagań biznesowych

- **v0.5 naprawiła główną słabość v0.4.** W 002 A miała 13 punktów klucza z pytań na 5 sesji, teraz 70 (B: 53).
  Zbiorcza runda z rekomendacjami daje pokrycie porównywalne z B przy 1–2 rundach zamiast 5–10.
- **Korekta nie maskuje discovery.** A dostała w korektach 7 nowych punktów, B 10. W pierwszych projektach A miała
  3 sprzeczności i 5 braków, B 4 sprzeczności i 6 braków.
- **B trafniej zakłada.** Recenzent policzył 22 punkty trafnie założone przez B, wobec 16 u A. B jednak częściej
  zakłada błędnie. W s06 przejęła limity z kodu, a w s07 i s10 przyjęła zachowania sprzeczne z kluczem bez pytania.
- **Przekazany punkt to nie gwarancja.** s08 (A) dostała K15 (kody rabatowe) w odpowiedzi i nie umieściła go
  w projekcie. Recenzent t4 nazwał to „pominięciem przekazanego wymagania, a nie luką w wiedzy”. To słabość kontroli
  jakości v0.5 (krok 10: „każda decyzja użytkownika ma odzwierciedlenie”). U B taki przypadek nie wystąpił.

## Czy zbiorcze pytania v0.5 zmniejszają liczbę rund bez utraty jakości?

**Tak co do rund i jakości, nie co do kosztu modelu.**

- Rundy: 5,2 vs 15,0; rundy pytań 1,2 vs 7,6.
- Jakość pierwszych projektów wyższa (3,98 vs 3,51), specyfikacji porównywalna (4,20 vs 4,05).
- Koszt modelu: czas 570 vs 553 s, koszt $2,47 vs $2,31. Długie rundy A generują więcej tokenów wyjścia
  i zapisu cache (53 k vs 47 k, 128 k vs 108 k), co znosi oszczędność na odczycie cache (1,88 M vs 2,55 M).
- Względem v0.4 koszt A wzrósł z $1,43 do $2,47, a czas z 291 s do 570 s.
- Każda sesja A wymagała dokładnie jednej korekty. Pierwszy projekt A jest lepszy, ale ani razu nie był kompletny.

## Czy dodatkowe zatwierdzenia Superpowers przyniosły wartość?

- **Zatwierdzenia sekcji: nie.** 16 pustych „Tak, zgadza się.” w 17 rundach sekcji nie zmieniło niczego. Jedyna
  korekta przed pełnym projektem (s10) przyszła przy prezentacji wariantów, a nie sekcji, i wymagała reguły „sprzeczne
  z przekazanym punktem”, którą PO stosuje też do A.
- **Wiele krótkich rund pytań: częściowo.** W t2 dały jedyny projekt bez korekty (10 rund, 15 punktów). W t3
  i t5 B zadała jednak mniej trafnych pytań niż A (6 i 9 punktów vs 12 i 14), mimo 7 rund.
- **Self-review specyfikacji: niejednoznaczny.** s10 dopisała przy zapisie założenie sprzeczne z kluczem. To samo
  zawężenie A miała już w projekcie i nikt go nie złapał (odstępstwo 2).

## Powtarzające się błędy

| Typ | A (`/brainstorm` v0.5) | B (Superpowers) |
|---|---|---|
| Współbieżność i przypadki brzegowe w 1. projekcie | t1 K15, t2 K18 (ostatni zapis), t3 alt `null` w ES, częściowo K14 | t1 K15, t4 K13 |
| Przekazany punkt pominięty w projekcie | t4 K15 | — |
| Założenie zawężające wymaganie biznesowe bez pytania | t5 odbiorcy tylko z bezpośrednią rolą | t3 limity z kodu, t5 to samo zawężenie w specyfikacji |
| Fałszywe twierdzenie o repo lub niezgodność z kontraktem | drobne (t2 `ProductPhoto` w panelu) | t1 i t4: 422 dla danych wejściowych; t4: `type` problemu; t5: dashboard, `ADMIN_PUBLIC_URL`, „plan istnieje”; t1: `docs/adr`, `/p/` „istnieje” |
| Skala | t2 brak indeksu dla listy panelu | t1 token i odczyt Postgresa przy każdej odsłonie gościa (niezgłoszony) |
| Overengineering | t1 podwójny mechanizm K15 | t5 projekcja i agregat zestawień; t3 `image_count` z przebudową indeksu; t2 podwójne źródło osi; t1 raport łączenia w Redisie |
| Zmiany plików przed kodem | brak | t1, t4 `vision.md` (jak w 002) |
| Proces | 5/5 sesji z jedną korektą; długie rundy | 16 pustych zatwierdzeń; 4× branch i commit |

## Porównanie z brainstorm-002

| | brainstorm-002 | brainstorm-003 |
|---|---|---|
| Wersja A | v0.4 (2 pytania w 1 rundzie) | v0.5 (wszystkie pytania zbiorczo, z rekomendacjami) |
| PO | ja, znając przydział; akceptacja automatyczna | osobny proces modelu na sesję, ślepy na przydział; weryfikacja względem klucza i korekty |
| Ocena | jedna, końcowa specyfikacja | pierwszy projekt i specyfikacja, dwie oceny z zamianą X/Y |
| Rundy A / B | 4,0 / 12,6 | 5,2 / 15,0 |
| Punkty klucza z pytań A / B | 13 / 34 | 70 / 53 |
| Koszt A / B | $1,43 / $2,20 | $2,47 / $2,31 |
| Czas A / B | 291 s / 491 s | 570 s / 553 s |
| Jakość A / B | 3,08 / 4,04 (bez korekt) | 1. projekt 3,98 / 3,51; specyfikacja 4,20 / 4,05 |

Najlepszym odpowiednikiem 002 są pierwsze projekty, bo w 002 nie było korekt. Kierunek się odwrócił, ale liczb
nie należy porównywać wprost:
- inne zadania i inny prompt recenzenta,
- inny respondent i reguły PO,
- recenzent ocenia też „pierwszy projekt”.

v0.5 przejęła rekomendacje 1–3 z 002: pytania zbiorcze, założenia jako pytania, nie zawężać zakresu. Efekt widać
w pokryciu klucza. Rekomendacja 4 (lista kontrolna zgodności z repo) też działa: kryterium 3 wynosi 4,5. Ceną jest
koszt i czas, wyższe niż w v0.4.

## Rekomendacje do ewentualnego v0.6 (maks. 5)

1. **Śledzenie odpowiedzi użytkownika przed pokazaniem projektu.** Krótka tabela „odpowiedź / decyzja → miejsce
   w projekcie” zamiast ogólnego „każda decyzja ma odzwierciedlenie”. W s08 przekazany punkt wypadł z projektu,
   a każda sesja A potrzebowała korekty.
2. **Obowiązkowy przegląd współbieżności i modeli odczytu.**
   - Dla każdej operacji zapisu: co widzi użytkownik przy równoległej zmianie (czy coś przepada, czy się sumuje)
     i czy konflikt jest wykrywany między żądaniami, a nie tylko w jednej transakcji.
   - Dla każdej zmiany danych: kto je czyta (indeks ES, DTO, sklep).
   - Uzasadnienie: kryterium 4 to najsłabszy punkt A (3,5); błędy t1 K15, t2 K18 i t3 `alt: null`.
3. **Ograniczenie techniczne zawężające wymaganie biznesowe to pytanie biznesowe**, a nie założenie techniczne.
   Przykłady: odbiorcy tylko z bezpośrednią rolą, limity przejęte z kodu, „wygrywa ostatni zapis”.
4. **Utrzymać zbiorcze pytania i jedną akceptację; nie dodawać zatwierdzeń sekcji.** Dane: 16 pustych zatwierdzeń
   B bez efektu, a A ma wyższą jakość pierwszego projektu przy 1,2 rundy pytań.
5. **Mniej tekstu w rundzie projektu.**
   - Pokazać decyzje, przepływy, kontrakt, błędy i kryteria; szczegóły dopisać dopiero do specyfikacji.
   - Spec nie zawiera sekcji o charakterze planu.
   - Cel: obniżyć koszt i czas, które w v0.5 wzrosły względem v0.4 (A $2,47 vs B $2,31, czas do akceptacji
     473 s vs 396 s).
   - Zmianę warto zmierzyć osobno, bo może obniżyć gotowość do implementacji.

## Artefakty

Wszystko poza repo, niezacommitowane:
- `~/Development/claude/bench-brainstorm-003/`:
  - `tasks.txt` (briefy), `answer-key.md` z `answer-key.sha256` i `po-rules.md`,
  - `driver.py`, `stats.py`, `build_review.py`, `judge.sh`, `judge-swap.sh`, `judge-prompt.md`,
  - `mapping.json`, `blind-mapping.json`, `stats.jsonl`, `judge/scores.json`,
  - `logs/` (stream-json, `.msg.md`, `.git` i `.wall` każdej rundy, `sNN.rounds.json` z odpowiedziami PO),
  - `prompts/` (dosłowne wiadomości do agentów), `po/sNN/` (surowe logi PO),
  - `designs/sNN/first.md` i `final.md`, `judge/r{1..5}.md` i `r{1..5}s.md` (oceny).
- `~/Development/claude/b003-runs/sNN/shop` – katalogi sesji w stanie końcowym.
- `~/Development/claude/b003-review/r{1..5}` i `r{1..5}s` – materiały recenzentów.
- Transkrypty: `~/.claude/projects/-Users-<user>-Development-claude-b003-runs-sNN-shop/`.
