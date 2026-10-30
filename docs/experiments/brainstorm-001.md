# Eksperyment brainstorm-001: `/brainstorm` vs `/superpowers:brainstorming`

Data: 2026-10-30. Zadanie dla obu sesji, słowo w słowo:
„Zaprojektuj wishlistę („zapisz na później”) dla zalogowanych klientów sklepu, bez powiadomień.”

## Metoda

- **Kod**: commit `57613c8`, wyeksportowany przez `git archive` do dwóch identycznych katalogów
  (`diff -r` bez różnic), bez `docs/experiments` i bez `.git`. W kodzie z tego commitu nie ma słowa „wishlist” ani
  wcześniejszych projektów wishlisty. Pamięć auto-memory obu katalogów była pusta.
- **Sesje**: dwa osobne procesy `claude -p` (Claude Code 2.1.308) z ustawieniami
  `--model claude-opus-5-5 --effort high --strict-mcp-config --permission-mode acceptEdits`.
  - Obie miały tę samą listę narzędzi: Read, Glob, Grep, Write, Edit, Skill, Agent i Bash tylko do odczytu.
    Zablokowane były `docker`, `make` i `rm`.
  - Każda runda pytań to osobne wywołanie z `--resume <session_id>`, więc każda sesja ma ciągły, własny kontekst.
  - Rundy 1 i 2 obu sesji szły równolegle. Kolejne rundy szły już tylko w B, bo A skończyła po dwóch.
- **Wywołanie Skilla**:
  - A dostała prompt `/brainstorm <zadanie>`, B dostała `/superpowers:brainstorming <zadanie>`.
  - W transkryptach sesji widać `<command-name>` i „Base directory for this skill”. Dla A wskazuje on
    `.claude/skills/brainstorm`, dla B `plugins/.../superpowers/skills/brainstorming`.
  - Żadna sesja nie załadowała Skilla drugiej.
  - W obu działał ten sam hook SessionStart pluginu superpowers, bo plugin jest włączony w `.claude/settings.json`.
- **Odpowiedzi**: jeden klucz dla obu sesji (niżej). Odpowiadałem tylko na zadane pytania. Na tę samą kwestię
  dawałem tę samą treść: decyzję techniczną (wariant architektury) i limit pozycji.
- **Koniec sesji**: na pierwszej bramce po zapisaniu specyfikacji.
  - A pyta „Przygotować plan?”.
  - B prosi o przegląd specyfikacji, a potem chce wywołać `writing-plans`.
  - Nic nie zostało zaimplementowane ani zacommitowane, także dlatego, że w eksportach nie ma `.git`.
- **Ocena jakości (kryteria 3–6)**: zrobił ją ślepo osobny subagent (Opus). Dostał projekty jako X (= B) i Y (= A),
  kopię repo i klucz odpowiedzi, a nie dostał statystyk ani nazw Skilli. Jego twierdzenia o repo sprawdziłem
  wyrywkowo: Deptrac, kod 400 w katalogu, `apps/store/CLAUDE.md`. Wszystkie się potwierdziły.

### Klucz odpowiedzi

Narzucone w zadaniu eksperymentu:
- prosta wishlista,
- gość trafia na logowanie i wraca na stronę, z której przyszedł,
- produkt nie dodaje się automatycznie.

Rozstrzygnięte przeze mnie i stosowane jednakowo (przekazywane tylko wtedy, gdy sesja o to zapytała):
- jedna lista na klienta,
- jednostką jest produkt,
- brak biznesowego limitu,
- bez duplikatów,
- usuwa tylko klient, bez wygasania,
- produkt niedostępny zostaje na liście z informacją, wycofany jest ukryty,
- tylko aktualna cena,
- najnowsze na górze,
- koszyk, panel admina i analityka poza zakresem,
- lista znika razem z kontem.

### Pełny przebieg odpowiedzi

| Runda | A | B |
|---|---|---|
| 2 | „1. Decyzje techniczne zostawiam Tobie, przyjmuję Twoją rekomendację. 2. Biznesowo nie ma limitu pozycji. Limit techniczny to Twoja decyzja, 100 akceptuję.” + założenia narzucone | „A. Cel: klient odkłada produkty, żeby wrócić do nich później. Budujemy na tym, co jest.” + założenia narzucone |
| 3 | — | „Biznesowo nie ma limitu pozycji. Limit techniczny to Twoja decyzja, 100 akceptuję.” |
| 4 | — | „Decyzje techniczne zostawiam Tobie, przyjmuję Twoją rekomendację.” |
| 5–8 | — | „Tak, zgadza się.” (cztery sekcje projektu) |

## Wyniki: obserwacje

### 1. Czas, tokeny, koszt

Dane z wyników `claude -p` (`duration_ms`, `usage`, `total_cost_usd`). Czas nie obejmuje mojego czasu na odpowiedź
między rundami.

| Miara | A `/brainstorm` | B `superpowers:brainstorming` |
|---|---|---|
| Czas zegarowy (suma rund) | 210 s | 280 s |
| Czas pierwszej rundy | 134 s | 49 s |
| Wywołania modelu (`num_turns`) | 15 | 17 |
| Tokeny wejścia bez cache | 24 | 42 |
| Zapis do cache | 89 759 | 89 317 |
| Odczyt z cache | 980 037 | 1 444 077 |
| Tokeny wyjścia (w tym thinking) | 18 109 (6 687) | 25 680 (10 202) |
| Koszt | **$1,28** | **$1,52** |
| Narzędzia | Bash 7, Read 3, Write 1, Edit 2 | Bash 8, Write 1 |
| Długość specyfikacji | 1176 słów | 1336 słów |

- A eksplorowała repo raz, w pierwszej rundzie, i była wtedy wolniejsza (134 s wobec 49 s).
- B eksplorowała mniej na starcie, a doczytywała kod w rundach 4, 6 i 8.
- Różnica kosztu wynika głównie z odczytu cache, który rośnie z liczbą rund: B miała 8 rund, A 2.

### 2. Pytania i rundy decyzyjne

| Miara | A | B |
|---|---|---|
| Rundy wymagające mojej odpowiedzi do zapisania specyfikacji | 1 | 7 |
| Pytania merytoryczne | 2 (w jednej rundzie) | 3 (cel i termin, limit, wariant architektury) |
| Pytania biznesowe | 1 (limit) | 2 (cel i termin, limit) |
| Pytania techniczne lub DDD | 1 (agregat czy same wiersze) | 1 (osobny context, Customer czy same wiersze) |
| Zatwierdzenia sekcji projektu | 0 | 4 |
| Bramki po specyfikacji | 1 (plan) | 1 (przegląd specyfikacji), potem `writing-plans` |

- A przedstawiła pełny projekt w pierwszej odpowiedzi, a pytania postawiła na końcu.
- B zaklasyfikowała zadanie jako „architektoniczne” i szła krokami Skilla: pytanie, warianty, cztery sekcje.
- B w pierwszej rundzie wypisała osobno własne założenia i to, co powiedział użytkownik.

### 3–6. Ocena ślepa (X = B, Y = A)

| Kryterium | A | B |
|---|---|---|
| Kompletność wymagań i przypadków brzegowych | 4 | 4 |
| Trafność architektury i niepotrzebna złożoność | 3 | 4 |
| Nieuzasadnione założenia (ocena) | 4 | 3 |
| Nieuzasadnione założenia (liczba) | 3 | 4 |
| Gotowość do implementacji | 3 | 4 |

#### Wspólne obu projektom

- **Rdzeń**: agregat `Wishlist` na klienta z wersją (optimistic locking), limit techniczny 100, idempotentne
  `PUT`/`DELETE` pod `/api/v1/me/wishlist`, port `ProductCatalog` z zapytaniem do Catalog przez `query.bus`
  czytającym z Postgresa.
- **Sklep**: BFF w Next.js, wyspa z przyciskiem na kafelku, strona `/konto/zapisane` renderowana po stronie klienta.
- **Zakres**: oba pokrywają prawie cały klucz.
- **Niezgodność z kluczem**: oba zapowiadają automatyczne usuwanie pozycji po przyszłym zdarzeniu „produkt usunięty”
  z Catalog, a klucz mówi „usuwa tylko klient”. Ta kolizja jest częściowa, bo przy ukrytym produkcie oba projekty
  zgodnie z kluczem pomijają go na liście.
- **Ta sama sprzeczność przy 409**: przy pełnej liście ma się pokazać osobny komunikat, ale `WishlistFull`
  i `WishlistModifiedConcurrently` mają ten sam status 409, a BFF nie przekazuje treści problemu (B) albo nie mówi,
  czy ją przekazuje (A).

#### A (`/brainstorm`)

- **Architektura**: wishlista w contextcie `Customer`, bez nowego modułu.
- **Zgodność z wzorcami repo**:
  - 400 przy błędnych parametrach,
  - `perPage` 24 i maksymalnie 48, jak w katalogu,
  - wersja w persystencji, jak w `Product`,
  - współdzielenie `isSameOrigin` z `/auth/logout`.
- **Dodatkowo**:
  - `reconstitute` odrzuca niespójny stan,
  - stała kolejność przy równej dacie,
  - stan przycisku ładowany dopiero po potwierdzeniu sesji.
- **Fałszywe twierdzenie o repo**: zależność `Customer_Infrastructure → Catalog_Application` jest „jedyną dozwoloną
  zależnością między modułami w Deptrac”. W `deptrac.yaml` są też inne, np. `Customer_Infrastructure →
  Backoffice_Application`.
- **Przedwczesna zmiana dokumentacji**: przed implementacją zmieniła `docs/vision.md` („zapisane produkty” w zakresie
  Customer). Według CLAUDE.md dokumentacja opisuje stan obecny i zmienia się w tym samym commicie co kod.
- **Luki**:
  - `total` liczy pozycje pominięte, więc stronicowanie rozjedzie się z tym, co widać,
  - nie określa strony spoza zakresu,
  - nie przewiduje zmiany w `apps/store/CLAUDE.md`, który nazywa `client.ts` jedynym wywołaniem z przeglądarki,
  - brak E2E dla gościa.
- **Uzasadnienie wyboru Customer**: specyfikacja tylko stwierdza ten wybór. Uzasadnienie (prywatne dane jak adresy,
  usuwanie razem z kontem) jest wyłącznie w rozmowie.

#### B (`superpowers:brainstorming`)

- **Architektura**: osobny bounded context `Wishlist` z czterema warstwami Deptrac i nową regułą
  `Wishlist_UI → Customer_Application`. Dziś żadna warstwa UI nie zależy od innego modułu.
- **Twierdzenia o repo**: wszystkie zweryfikowane są prawdziwe, np. deterministyczne UUID danych demo,
  `SameSite=Lax`, ES jako model odczytu.
- **Odstępstwa od wzorców repo**:
  - 422 zamiast 400,
  - `perPage` 20 zamiast 24,
  - wersja w agregacie zamiast w rekordzie,
  - optymistyczna zmiana stanu serca bez uzasadnienia.
- **Dostępność poza zakresem**: przenosi „ceny i dostępność na liście” do sekcji „Poza zakresem”. To jest
  sprzeczne z kluczem, który oczekuje informacji o niedostępności.
- **Szczegóły ponad A**:
  - stronicowanie po odfiltrowaniu brakujących produktów (`total` się zgadza),
  - pusta strona spoza zakresu,
  - E2E gościa,
  - zmiana w `apps/store/CLAUDE.md` i ścieżka przez `client.ts`,
  - test obejścia Deptrac,
  - wspólny helper sesji w BFF.
- **Dokumentacja**: zmiany w `vision.md` zapowiada razem z kodem.

## Opinie (moje, oddzielone od obserwacji)

- **Koszt i liczba interakcji**: A jest wyraźnie tańsza w interakcji. Jedna runda zamiast siedmiu i około 16% niższy
  koszt przy podobnej objętości wyniku. Przy prostej funkcji cztery zatwierdzenia sekcji w B nie zmieniły ani jednej
  decyzji: wszystkie odpowiedzi brzmiały „Tak, zgadza się”. Tego kroku nie da się jednak ocenić na przykładzie bez
  sprzeciwu użytkownika.
- **Pytanie o cel**: pytanie B o cel i termin (budować teraz czy po karcie produktu) to trafna decyzja biznesowa,
  której A nie zadała. A przyjęła „budujemy teraz” po cichu.
- **Architektura**: projekt A jest prostszy i bliższy wzorcom repo. Osobny context w B to więcej konfiguracji przy
  jednej funkcji, choć jest uzasadniony przyszłym rozrostem Customer. Ten wybór to istotna decyzja DDD (granica
  modułu). Według CLAUDE.md trafia do użytkownika, a tutaj obie sesje zostawiły go sobie, bo tak im odpowiedziałem.
- **Gotowość do implementacji**: B jest nieco lepiej przygotowany. Ma mniej luk w stronicowaniu, ścieżce
  przeglądarka → BFF i zasadach sklepu, a wszystkie jego twierdzenia o repo są prawdziwe.
- **Dyscyplina procesu**: A ma dwa uchybienia: przedwczesną zmianę `vision.md` i jedno fałszywe twierdzenie o Deptrac.
  Moje „przyjmuję Twoją rekomendację” A potraktowała jako akceptację całego projektu i od razu zapisała specyfikację.
  To zgodne z literą jej Skilla, ale ominęło przedstawienie poprawionej wersji.
- **Bilans**: przy tej samej jakości rdzenia A daje projekt szybciej i taniej. B daje go precyzyjniej i z mniejszą
  liczbą błędów, kosztem wielu rund. Żaden nie jest gotowy bez poprawek: oba muszą rozwiązać rozróżnienie dwóch
  przypadków 409 i kolizję z regułą „usuwa tylko klient”.

## Ograniczenia eksperymentu

- **Jedna próba (n=1)**: nie znamy wariancji, a różnice kosztu i ocen mogą mieścić się w szumie.
- **Ten sam model wszędzie**: odpowiadający, sesje i oceniający to ten sam model. Ocenę zrobił jeden ślepy subagent.
- **Inne miejsca zakończenia**: A skończyła przed planem, B przed przeglądem specyfikacji. Pełna ścieżka B ma jeszcze
  jedną bramkę.
- **Środowisko inne niż zwykła praca**: brak `.git` w eksportach, więc B nie mogła zrobić kroku „commit spec”. Brak
  uruchomionego stosu, więc nie było eksperymentów na usługach, o których mówi krok 4 Skilla A.
- **Wspólne zasady repo**: hook superpowers i reguła „Tymczasowe” z CLAUDE.md działały w obu sesjach.
- **Pominięte rozstrzygnięcia z klucza**: część klucza nie została przekazana, bo nikt o nią nie pytał (np.
  niedostępność, wygasanie). Oceniający traktował te punkty jako oczekiwania właściciela, a obie sesje oceniał
  według tej samej miary.
- **Artefakty**: surowe logi (stream-json), skrypty, eksporty i materiały oceny są poza repo, w
  `~/Development/claude/bench-brainstorm-001/`.

## Załącznik A: specyfikacja sesji A (`/brainstorm`)

Plik `docs/features/wishlist/spec.md` w eksporcie A. Sesja A zmieniła też `docs/vision.md`:

```diff
-| Context w API | Customer (profil, adresy) | brak osobnego contextu; tożsamość i role z tokena |
+| Context w API | Customer (profil, adresy, zapisane produkty) | brak osobnego contextu; tożsamość i role z tokena |
-| Customer | Profil klienta, adresy; sama tożsamość jest w Keycloak |
+| Customer | Profil klienta, adresy, zapisane produkty (wishlista); sama tożsamość jest w Keycloak |
```

### Wishlista („Zapisane”)

Zalogowany klient zapisuje produkty na później i wraca do nich na stronie „Zapisane”. Prosta lista bez
powiadomień, udostępniania i podziału na kilka list.

#### Cel i kryteria sukcesu

1. Zalogowany klient zapisuje produkt przyciskiem na kafelku listy produktów i usuwa go tym samym przyciskiem.
   Kafelek pokazuje, czy produkt jest zapisany.
2. Gość po kliknięciu przycisku trafia do logowania, a po nim wraca na stronę, z której przyszedł. Produkt nie jest
   dodawany automatycznie, klient klika jeszcze raz.
3. Strona `/konto/zapisane` pokazuje zapisane produkty od ostatnio dodanego, ze stronicowaniem i przyciskiem
   „Usuń”.
4. Klient widzi i zmienia wyłącznie własną listę.
5. HTML stron katalogu jest taki sam dla gościa i zalogowanego klienta. Stan przycisku ładuje się w przeglądarce.

#### Poza zakresem

Powiadomienia (np. o spadku ceny lub powrocie na stan), lista gościa i łączenie jej po zalogowaniu, kilka list,
udostępnianie, zapisywanie wariantów, ekran wishlisty w panelu, automatyczne dodanie produktu po zalogowaniu.

#### Reguły

- Lista należy do klienta identyfikowanego przez `sub` z tokena realmu `shop`. Identyfikator klienta nigdy nie
  pochodzi z adresu ani treści żądania.
- Zapisuje się produkt, a nie wariant.
- Produkt jest na liście najwyżej raz. Ponowne zapisanie nie zmienia listy ani daty dodania.
- Usunięcie produktu, którego nie ma na liście, nie jest błędem.
- Kolejność: od najnowszej daty dodania, przy równej dacie po identyfikatorze produktu.
- Biznesowo lista nie ma limitu. Limit techniczny to 100 pozycji: chroni przed nieograniczonym wzrostem danych
  i pozwala sklepowi pobrać wszystkie identyfikatory jednym żądaniem. Przy pełnej liście zapis jest odrzucany,
  a sklep pokazuje komunikat „Lista zapisanych jest pełna. Usuń coś, żeby zapisać nowy produkt.”

#### API (`apps/api`)

##### Moduł

Wishlista jest częścią contextu `Customer`.

| Warstwa | Zawartość |
|---|---|
| Domain | agregat `Wishlist`, `WishlistItem`, `CustomerId`, `ProductId` (własne value objecty Customer), wyjątki, interfejs `WishlistRepository` |
| Application | komendy `SaveProductForLater`, `RemoveSavedProduct`; zapytania `GetWishlist`, `GetWishlistProductIds`; port `ProductCatalog` |
| Infrastructure | rekordy Doctrine i `DoctrineWishlistRepository`; adapter `ProductCatalog` wołający zapytanie `GetProductListItems` z `Catalog` przez `query.bus` |
| UI | kontrolery `/api/v1/me/wishlist…` |

Catalog dostaje zapytanie `GetProductListItems(ids)`: najwyżej 48 identyfikatorów, wynik z Postgresa (źródła
prawdy), elementy w kształcie `ProductListItem` (ze zdjęciem w rozmiarze `listing`), w kolejności wejścia, bez
produktów, których nie ma. Budowa `ImageView` jest wspólna z `ListProductsHandler`. Customer nie importuje
klas Catalog: adapter mapuje wynik na własny widok `WishlistProduct`, a zależność `Customer\Infrastructure →
Catalog\Application` jest jedyną dozwoloną zależnością między modułami w Deptrac.

##### Model

**`Wishlist`** (agregat, jeden na klienta)
- `CustomerId` (niepusty `sub`), lista `WishlistItem(ProductId, addedAt)`.
- `Wishlist::empty(customerId)` tworzy pustą listę. Repozytorium zwraca ją, gdy klient nie ma jeszcze zapisanej.
- `save(productId, now)`: produkt już zapisany nic nie zmienia; przy 100 pozycjach rzuca `WishlistFull`
  (`Conflict`, 409).
- `remove(productId)`: usuwa pozycję, jeśli jest.
- `Wishlist::reconstitute(...)` odtwarza stan i odrzuca stan niespójny (powtórzony produkt, ponad 100 pozycji).
- Agregat nie zapisuje zdarzeń domenowych, bo nikt ich nie odbiera.

##### Persystencja

- `customer_wishlist`: `customer_id` (tekst, klucz główny), `version` (BIGINT NOT NULL).
- `customer_wishlist_item`: `customer_id` (FK do `customer_wishlist`, `ON DELETE CASCADE`), `product_id` (UUID),
  `added_at` (`timestamptz`), klucz główny (`customer_id`, `product_id`), indeks
  (`customer_id`, `added_at DESC`, `product_id`) pod stronicowanie.
- Brak klucza obcego do `catalog_product`: to granica contextu.
- Wersja należy do persystencji, jak w `Product`: każdy zapis zwiększa ją o 1, porównywana jest wersja odczytana
  w tej samej jednostce pracy. Zapis równoległy (także dwa równoczesne pierwsze zapisy tej samej listy, czyli
  naruszenie klucza głównego `customer_wishlist`) rzuca `WishlistModifiedConcurrently` (`Conflict`, 409).
  Dzięki temu limit obowiązuje dokładnie.

##### Endpointy

Wszystkie w `openapi.json`, za firewallem `shop`, z odpowiedziami `Cache-Control: private, no-store`. Brak albo
błędny token (także z realmu `backoffice`) daje 401, niedostępne klucze wystawcy 503. Błędy w formacie RFC 9457.

| Endpoint | Odpowiedzi |
|---|---|
| `PUT /api/v1/me/wishlist/{productId}` | 204 zapisany (także gdy już był); 404 produkt nie istnieje albo `productId` nie jest UUID; 409 `WishlistFull` albo `WishlistModifiedConcurrently` |
| `DELETE /api/v1/me/wishlist/{productId}` | 204 (także gdy produktu nie było na liście); 404 `productId` nie jest UUID |
| `GET /api/v1/me/wishlist?page&perPage` | 200 `WishlistPage`; 400 błędne parametry (`page` od 1, `perPage` 1–48, domyślnie 24) |
| `GET /api/v1/me/wishlist/product-ids` | 200 `{ "productIds": [...] }` w kolejności listy, najwyżej 100 |

- `WishlistPage`: `items` (`{ product: WishlistProduct, addedAt }`), `page`, `perPage`, `total`.
- `WishlistProduct`: `id`, `name`, `slug`, `shortDescription`, `image` (ten sam kształt co w liście katalogu).
- Pozycje, których produktu Catalog nie zwrócił, są pomijane w `items` i liczone w `total`. Katalog nie ma dziś
  usuwania produktów, więc to sytuacja wyjątkowa.

#### Sklep (`apps/store`)

##### Trasy BFF

Route handlery w `app/wishlist/`, owinięte w `withAuthDeps`. Biorą token z sesji (z odświeżeniem, jak
`/auth/session`), wołają API wygenerowanym klientem i odpowiadają z `Cache-Control: private, no-store`.

| Route handler | Działanie |
|---|---|
| `GET /wishlist/ids` | identyfikatory zapisanych produktów |
| `GET /wishlist/items?page=` | strona listy z danymi produktów |
| `PUT /wishlist/items/[productId]` | zapisanie |
| `DELETE /wishlist/items/[productId]` | usunięcie |

- Brak sesji albo token, którego nie da się odświeżyć: 401 (sesja usunięta jak w `/auth/session`).
- `PUT` i `DELETE` odrzucają żądania spoza sklepu (sprawdzanie `Origin` i `Sec-Fetch-Site` wydzielone z
  `/auth/logout` do wspólnej funkcji): 403.
- Odpowiedzi API 404 i 409 przechodzą do przeglądarki z tym samym statusem. Niedostępne API, Redis albo Keycloak:
  503 bez usuwania sesji.

##### Przycisk na kafelku

- `SaveForLaterButton` (Client Component) w `ProductTile`. Siatka i kafelek zostają Server Components.
- Jeden provider na stronę pobiera `/wishlist/ids` dopiero wtedy, gdy `/auth/session` potwierdzi zalogowanie.
  Kafelki czytają stan z providera.
- Gość: kliknięcie przechodzi do `/auth/login?returnTo=<bieżąca ścieżka z parametrami>`.
- Kliknięcie wysyła `PUT` albo `DELETE`, a stan przycisku zmienia się po odpowiedzi. W trakcie przycisk jest
  zablokowany. 409 `WishlistFull` pokazuje komunikat o pełnej liście, inny błąd ogólny komunikat, a stan zostaje.
- Przycisk ma dostępną etykietę („Zapisz na później” / „Usuń z zapisanych”) i `aria-pressed`.
- Dopóki stan nie jest znany (ładowanie, 503), przycisk dla zalogowanego jest nieaktywny.

##### Strona `/konto/zapisane`

- Renderowana po stronie klienta, `noindex`. Dane z `GET /wishlist/items`.
- Kafelki jak w katalogu, z przyciskiem „Usuń”. Stronicowanie z `?page=`. Pusta lista pokazuje komunikat z linkiem
  do katalogu.
- Gość jest przekierowany do logowania z `returnTo=/konto/zapisane`.
- W nagłówku zalogowany klient widzi link „Zapisane”.

#### Testy

- Jednostkowe (TDD): `Wishlist` (duplikat, limit, kolejność, usuwanie nieobecnego, odrzucenie niespójnego stanu),
  handlery na adapterach in-memory (nieznany produkt, pusta lista, pominięcie brakującego produktu).
- Integracyjne: `DoctrineWishlistRepository` (zapis i odtworzenie, konflikt wersji, równoczesny pierwszy zapis),
  `GetProductListItems` na Postgresie.
- Funkcjonalne: każdy endpoint, w tym brak tokena, token `backoffice`, błędny UUID, nieznany produkt, pełna lista,
  izolacja dwóch klientów, nagłówki `Cache-Control`.
- Sklep (Vitest): trasy BFF (brak sesji, obca strona, przekazanie 404/409, 503), przycisk (gość, zapisany, pełna
  lista, błąd), strona „Zapisane” (pusta, stronicowanie).
- E2E: logowanie → zapisanie produktu z listy → `/konto/zapisane` → usunięcie.

#### Ograniczenia i zależności

- Każda strona katalogu oglądana przez zalogowanego klienta wykonuje jedno dodatkowe żądanie (BFF → API →
  Postgres, skan indeksu po `customer_id`, najwyżej 100 wierszy). Bez cache. Wydajność przy docelowej skali
  potwierdzą dopiero testy obciążeniowe.
- Usuwanie produktów z katalogu (gdy powstanie) wymaga odbiorcy zdarzenia, który usuwa je z wishlist.
- Usuwanie konta klienta (RODO, gdy powstanie) usuwa też jego wishlistę.
- Ceny i dostępność pojawią się na liście, gdy dostanie je element listy katalogu. Przycisk na stronie produktu
  powstanie razem z tą stroną.

## Załącznik B: specyfikacja sesji B (`/superpowers:brainstorming`)

Plik `docs/features/wishlist/spec.md` w eksporcie B. Sesja B nie zmieniała innych plików.

### Wishlista („Zapisane”)

Zalogowany klient odkłada produkty, żeby wrócić do nich później. Zapisuje produkt sercem na kafelku katalogu
i widzi swoje zapisane produkty na stronie `/konto/zapisane`. Wishlista nie wysyła żadnych powiadomień.

#### Cel i kryteria sukcesu

1. Zalogowany klient zapisuje produkt sercem na kafelku i usuwa go tym samym sercem. Stan serca odpowiada
   zawartości listy po odświeżeniu strony i w innej karcie.
2. Strona `/konto/zapisane` pokazuje zapisane produkty (zdjęcie, nazwa) od najnowszych, ze stronicowaniem,
   i pozwala usunąć każdy z nich.
3. Gość klikający serce trafia na logowanie i po nim wraca na stronę, z której przyszedł. Produkt nie zapisuje
   się sam.
4. HTML katalogu jest taki sam dla gościa i zalogowanego klienta (ISR i cache bez zmian).
5. `make check` i testy `api`, `store` oraz `make e2e` przechodzą.

#### Poza zakresem

Powiadomienia (spadek ceny, powrót towaru), lista gościa w przeglądarce i scalanie jej po zalogowaniu,
automatyczne dodanie produktu po zalogowaniu, wiele nazwanych list, udostępnianie listy, przeniesienie do koszyka,
ceny i dostępność na liście, wybór wariantu, widok wishlist w panelu, rate limiting.

#### Reguły

- Jedna lista na klienta. Właścicielem jest klient identyfikowany przez `sub` z tokena realmu `shop`.
- Pozycją listy jest produkt (`ProductId` z Catalog) z datą zapisu.
- Biznesowo lista nie ma limitu. Technicznie mieści najwyżej **100** pozycji: to zabezpieczenie przed
  nieograniczonym wzrostem danych przy docelowej skali, zdefiniowane w jednym miejscu w domenie.
- Zapis produktu, który już jest na liście, nic nie zmienia (data zapisu zostaje).
- Usunięcie produktu, którego nie ma na liście, nic nie zmienia i nie jest błędem.
- Zapisać można tylko produkt, który istnieje w Catalog.
- Lista jest uporządkowana od ostatnio zapisanych.

#### API (`apps/api`)

##### Moduł `Wishlist`

Osobny bounded context `src/Wishlist/{Domain,Application,Infrastructure,UI}`. Context `Customer` zostaje przy
profilu i adresach.

**Domena**

- Agregat `Wishlist`, jeden na klienta, identyfikowany przez `CustomerId` (value object contextu Wishlist,
  wartością jest `sub`). Zawiera listę `WishlistItem` (`ProductId` contextu Wishlist, `savedAt`) i wersję
  (optimistic locking).
- `Wishlist::save(ProductId, savedAt)` pilnuje braku duplikatów i limitu. Przy pełnej liście rzuca
  `WishlistFull` (`Conflict`).
- `Wishlist::remove(ProductId)` usuwa pozycję, gdy jest.
- Agregat powstaje przy pierwszym zapisie. Klient bez agregatu ma pustą listę.
- Agregat nie publikuje zdarzeń domenowych: nikt ich nie konsumuje.

**Aplikacja**

- Komendy `SaveProduct` i `RemoveSavedProduct` (`command.bus`), zapytania `GetWishlist` (strona listy)
  i `GetSavedProductIds` (`query.bus`).
- Port `ProductCatalog`:
  - `exists(ProductId): bool` – sprawdzenie przy zapisie; brak produktu kończy się `ProductNotFound` contextu
    Wishlist (`NotFound`),
  - `summaries(list<ProductId>)` – nazwa, slug i podpisane zdjęcie (lub `null`) dla istniejących produktów;
    nieistniejące są pomijane.
- `GetWishlist` pobiera opisy wszystkich pozycji listy jednym wywołaniem `summaries`, odrzuca produkty, których
  nie ma w Catalog, i dopiero wynik dzieli na strony po 20. `total` liczy tylko widoczne pozycje.

**Infrastruktura**

- Adapter `QueryBusProductCatalog` woła nowe zapytanie Catalog `GetProductSummaries(ids)` przez `query.bus`.
  Zapytanie czyta z Postgresa po kluczu głównym (sprawdzenie istnienia to decyzja biznesowa, Elasticsearch jest
  tylko modelem odczytu) i zwraca warianty zdjęć z tego samego generatora adresów imgproxy co lista produktów.
- Persystencja przez rekordy w `Wishlist/Infrastructure/Persistence/Doctrine`, mapowane w repozytorium
  `DoctrineWishlistRepository`:
  - `wishlist (customer_id PK, version)`,
  - `wishlist_item (customer_id FK, product_id, saved_at)`, klucz główny `(customer_id, product_id)`,
    indeks `(customer_id, saved_at DESC)`.
- Konflikt wersji przy zapisie oraz naruszenie klucza głównego przy równoległym pierwszym zapisie kończą się
  `WishlistModifiedConcurrently` (`Conflict`).

**Deptrac**

- Warstwy `Wishlist_Domain`, `Wishlist_Application`, `Wishlist_Infrastructure`, `Wishlist_UI` z tymi samymi
  regułami co pozostałe moduły.
- `Wishlist_Infrastructure` może zależeć od `Catalog_Application` (adapter portu `ProductCatalog`).
- `Wishlist_UI` może zależeć od `Customer_Application` (kontrolery przyjmują `AuthenticatedCustomer`).
- Domena i aplikacja Wishlist nie zależą od żadnej warstwy Catalog.

##### Endpointy

Wszystkie za firewallem `shop` (token realmu `backoffice` dostaje 401), w `openapi.json`, z odpowiedziami
`Cache-Control: private, no-store`. Błędy w formacie RFC 9457.

| Metoda i ścieżka | Sukces | Błędy |
|---|---|---|
| `GET /api/v1/me/wishlist?page=` | 200: `items[]` (`product {id, name, slug, image}`, `savedAt`), `page`, `perPage` (20), `total` | 401, 422 (niepoprawny `page`) |
| `GET /api/v1/me/wishlist/product-ids` | 200: `productIds[]` – wszystkie zapisane id | 401 |
| `PUT /api/v1/me/wishlist/items/{productId}` | 204 | 401, 404 (niepoprawny UUID lub produkt nie istnieje), 409 (`WishlistFull`, `WishlistModifiedConcurrently`) |
| `DELETE /api/v1/me/wishlist/items/{productId}` | 204 (także gdy produktu nie ma na liście) | 401, 404 (niepoprawny UUID), 409 (`WishlistModifiedConcurrently`) |

`PUT` i `DELETE` są idempotentne, więc nie wymagają nagłówka `Idempotency-Key`. Strona spoza zakresu zwraca pustą
listę `items`.

#### Sklep (`apps/store`)

##### BFF `/wishlist/*`

Route handlery obok `/auth/*`, wołające API z serwera sklepu z tokenem z sesji:

| Trasa sklepu | Wołany endpoint API |
|---|---|
| `GET /wishlist/product-ids` | `GET /api/v1/me/wishlist/product-ids` |
| `GET /wishlist?page=` | `GET /api/v1/me/wishlist` |
| `PUT /wishlist/items/[productId]` | `PUT /api/v1/me/wishlist/items/{productId}` |
| `DELETE /wishlist/items/[productId]` | `DELETE /api/v1/me/wishlist/items/{productId}` |

- Ustalenie klienta (ciasteczko → sesja → świeży token) jest we wspólnym helperze w `src/auth/`, używanym też
  przez `/auth/session`.
- Brak sesji, sesja nie do odświeżenia lub 401 z API: odpowiedź 401. W dwóch ostatnich przypadkach sesja jest
  usuwana, a ciasteczko czyszczone.
- Niedostępne API, Keycloak lub Redis: 503, sesja zostaje.
- Statusy 404 i 409 z API przechodzą do przeglądarki bez treści problemu z API.
- `PUT` i `DELETE` odrzucają (403) żądanie, którego nagłówek `Origin` nie jest adresem sklepu. Ciasteczko sesji
  ma `SameSite=Lax`, więc i tak nie jest wysyłane przy takich żądaniach z innej domeny.
- Wszystkie odpowiedzi mają `Cache-Control: private, no-store` i są owinięte w `withAuthDeps`.
- Przeglądarka woła te trasy wyłącznie przez funkcje w `src/api/client.ts`.

##### Serce na kafelku

- `ProductTile` zostaje Server Componentem i zawiera Client Component `SaveButton` z `src/wishlist/`.
- `WishlistProvider` na stronie katalogu pobiera `/wishlist/product-ids` raz na wyświetlenie strony.
- Stany:
  - 401 (gość): kliknięcie przenosi na `/auth/login?returnTo=<bieżący adres ze ścieżką i zapytaniem>`,
  - zalogowany: kliknięcie od razu zmienia stan serca; błąd cofa stan i pokazuje komunikat w regionie
    `aria-live`: przy pełnej liście „Lista zapisanych jest pełna”, w pozostałych przypadkach „Nie udało się
    zapisać, spróbuj ponownie”,
  - 503 lub błąd sieci przy ładowaniu: serca nie są pokazywane.
- Przycisk to `<button aria-pressed>` z etykietą „Zapisz na później” albo „Usuń z zapisanych”.

##### Strona `/konto/zapisane`

- Renderowana po stronie klienta, `noindex`.
- Kafelki ze zdjęciem (`ProductPhoto`) i nazwą, przycisk „Usuń”, stronicowanie, od najnowszych.
- Pusta lista: „Nie masz jeszcze zapisanych produktów” i link do katalogu.
- Gość jest przekierowany na `/auth/login?returnTo=/konto/zapisane`.
- Kafelki nie linkują do produktu, bo sklep nie ma jeszcze karty produktu. API zwraca `slug` na potrzeby linku.
- `AccountMenu` pokazuje zalogowanemu klientowi link „Zapisane”.

#### Znane ograniczenia i wymagania na przyszłość

- Catalog nie ma usuwania produktów. Produkt może zniknąć z Catalog tylko po zmianie danych demo i ponownym
  `make seed` (identyfikatory produktów demo są deterministyczne, więc niezmienione produkty zachowują id).
  Taka pozycja jest pomijana na liście, ale zajmuje miejsce w limicie. Gdy Catalog dostanie usuwanie produktów,
  Wishlist ma usuwać pozycje po zdarzeniu Catalog.
- Wishlista to dane osobowe klienta. Usuwanie konta klienta (RODO) musi usuwać też jego wishlistę.

#### Testy

**API**

- Jednostkowe (TDD, adaptery in-memory): reguły agregatu (duplikat, limit, usunięcie nieobecnej pozycji,
  kolejność), handlery z `InMemoryWishlistRepository` i fałszywym `ProductCatalog` (zapis nieistniejącego
  produktu, pomijanie produktów spoza Catalog i stronicowanie po odrzuceniu).
- Integracyjne (Postgres): `DoctrineWishlistRepository` (zapis i odczyt, konflikt wersji przez drugie połączenie,
  równoległy pierwszy zapis), `GetProductSummaries` z podpisanymi wariantami zdjęć.
- Funkcjonalne dla każdego endpointu: 401 bez tokena i z tokenem realmu `backoffice`, 404 dla niepoprawnego UUID
  i nieistniejącego produktu, 409 przy pełnej liście, rozdzielne listy dwóch klientów, nagłówki `private, no-store`.
- Deptrac: import czegokolwiek z Catalog w `Wishlist_Domain` lub `Wishlist_Application` jest zgłaszany jako
  naruszenie.

**Sklep (Vitest)**

- Helper ustalania klienta i route handlery `/wishlist/*`: 401 bez sesji, usunięcie sesji po 401 z API,
  odświeżenie tokena, 503 przy awarii, 403 dla obcego `Origin`, nagłówki `no-store`.
- `SaveButton`: stany, cofnięcie po błędzie, komunikat przy 409, przekierowanie gościa z `returnTo`.
- `WishlistProvider`: jedno żądanie na stronę.
- `/konto/zapisane`: pusta lista, stronicowanie, gość.

**E2E (Playwright)**

- Logowanie klienta → zapis produktu na listingu → produkt na `/konto/zapisane` → usunięcie.
- Gość klika serce → logowanie → powrót na listing, lista zapisanych pusta.

#### Dokumentacja

Razem z kodem funkcji aktualizowane są:

- `docs/vision.md`: context `Wishlist` w tabeli contextów, wishlista w zakresie MVP, scenariusz w ścieżkach E2E,
- `apps/store/CLAUDE.md`: przeglądarka woła wyłącznie route handlery sklepu (`/auth/*`, `/wishlist/*`) i tylko
  przez `src/api/client.ts`; katalog `src/wishlist/` w strukturze,
- `apps/api/openapi.json` i wygenerowany klient TS sklepu.

#### Zależności

Bez nowych zależności.
