# PRODUCT-DATA (etap 1): marka, opis, parametry i kody na karcie produktu

Issues: #772 (epik; etap 1 go nie zamyka)
Źródła: `docs/reports/app-audit-2026-11-18.md` (sekcja 5.2), `docs/reports/ecommerce-functional-audit-2026-11-18.md`
(epik 4 „Dane produktu i import”).
Zakres: `apps/api/src/Catalog`, `apps/api/src/DemoData`, `apps/api/fixtures`, `apps/api/migrations`, `apps/api/tests`,
`apps/admin`, `apps/store`, `e2e`, dokumentacja funkcji.

## Stan obecny (sprawdzony 2026-11-24)

Przykład: „Namiot turystyczny Hallen Halny 4” (`namiot-turystyczny-hallen-halny-4`), dane z `make seed`.

| Miejsce | Co jest dziś |
|---|---|
| Panel, formularz produktu | Nazwa (200), slug, krótki opis (500), klasa podatkowa, kategoria główna i dodatkowe, do 10 zdjęć z tekstem alternatywnym, do 2 osi i 100 wariantów (same wartości osi). Ceny i stany na osobnych listach. |
| API panelu, `POST`/`PUT /api/v1/admin/products` | `name`, `slug`, `shortDescription`, `mainCategoryId`, `additionalCategoryIds`, `taxClass`, `images[{key, alt}]`, `variantAxes`, `variants[{variantId, values}]` (+ `version` w `PUT`). |
| API sklepu, `GET /api/v1/products/{slug}` | `id`, `slug`, `name`, `shortDescription`, `breadcrumb`, `images`, `zoomImages`, `variantAxes`, `variants[{variantId, label, values}]`. |
| Karta w sklepie | Ścieżka kategorii, jedno zdjęcie, nazwa, jedno zdanie opisu („Czteroosobowy namiot na każdą pogodę, ze wzmocnionym stelażem.”), cena z najniższą ceną z 30 dni, dostępność, wybór koloru, „Do koszyka”. |
| JSON-LD `Product` | `name`, `description` (krótki opis), `image`, `url`, `offers[]` (`name`, `price`, `priceCurrency`, `priceValidUntil`, `availability`, `url`). Bez `brand`, `sku`, `gtin`. |

Marka istnieje tylko w danych demo jako składnik nazwy: seed buduje nazwę „typ marka model”
(`apps/api/src/Catalog/Infrastructure/Demo/DemoCatalogExpander.php`), generator tak samo
(`apps/api/src/DemoData/Catalog/ProductGenerator.php`). Wariant ma wyłącznie identyfikator i wartości osi
(`apps/api/src/Catalog/Domain/Product/ProductVariant.php`). Dokument wyszukiwarki nie ma żadnego z brakujących pól
(`ProductIndexDefinition.php`).

## Cel i zakres etapu 1

Karta produktu w sklepie demo ma treść, po której klient rozpoznaje i porównuje produkt, a sprzedawca wpisuje ją
w panelu. Etap jest najmniejszym krokiem, który to daje: pięć pól, bez nowego agregatu, bez zmian w wyszukiwarce
i bez zmian w zamówieniach.

W zakresie:

1. Produkt: **marka**, **opis** (długi) i **parametry** (lista par nazwa–wartość).
2. Wariant: **SKU** i **EAN**.
3. Panel: pola w formularzu produktu.
4. Sklep: marka nad nazwą, sekcje „Opis” i „Parametry”, kod produktu i EAN wybranego wariantu, JSON-LD z `brand`,
   `sku` i `gtin` (`gtin` tylko dla globalnego numeru towaru, D7).
5. Dane demo: seed (191 produktów) i generator (do 10 mln) wypełniają wszystkie pięć pól.

Poza etapem 1 (zostaje w #772):

- dane producenta i osoby odpowiedzialnej (GPSR), ostrzeżenia i informacje o bezpieczeństwie: wymagają potwierdzenia
  prawnika i słownika producentów (nowy agregat);
- waga i gabaryt jako pola: nie mają dziś odbiorcy (dostawa nie liczy kosztu z wagi); do tego czasu waga jest
  parametrem tekstowym;
- import i eksport CSV produktów, cen i stanów;
- wyszukiwanie po SKU i EAN w panelu, kody na listach cen i stanów, na pozycjach zamówienia i w e-mailach;
- marka i parametry w wyszukiwarce: filtr marki, fasety parametrów, marka na kafelku listy, opis w indeksie;
- słownik marek i słownik parametrów z typami i jednostkami;
- flaga „wyłączone z prawa odstąpienia”, duplikowanie produktu, pola SEO, zdjęcia per wariant.

## Decyzje

Właściciel zaakceptował 2026-11-25 zakres etapu 1, decyzje D1–D10 i plan pomiarów („Skala i dane na serwerze”).

| # | Decyzja | Alternatywa i dlaczego nie teraz |
|---|---|---|
| D1 | Zakres etapu 1 jak wyżej. | Pełny zakres #772 w jednym epiku (GPSR, CSV, waga): rozmiar L, zależność od prawnika i nowy agregat; poprawa karty czekałaby na import. |
| D2 | Marka to opcjonalny tekst produktu, bez słownika. | Słownik marek (agregat, ekran w panelu, klucz obcy): potrzebny dopiero przy stronie marki albo gdy dane producenta mają wisieć na marce. Przejście później to migracja kolumny i ponowne wygenerowanie danych demo. |
| D3 | Opis to zwykły tekst z akapitami (znak końca wiersza), do 5000 znaków. Sklep pokazuje go dosłownie, bez znaczników. | Markdown albo HTML: nowa zależność w sklepie i sanitizacja; bez wartości dla danych demo. |
| D4 | Parametry to uporządkowana lista do 30 par nazwa–wartość per produkt, bez słownika nazw i wartości. | Słownik atrybutów z typami (podstawa faset): osobny etap razem z wyszukiwarką. Parametry z etapu 1 nie staną się wtedy fasetami bez przepisania danych. |
| D5 | SKU jest opcjonalny, zapisywany wielkimi literami i unikalny w całym sklepie (także wśród szkiców). | SKU unikalny tylko w produkcie albo bez unikalności: nie nadaje się na klucz importu cen i stanów (kolejny etap). |
| D6 | EAN jest opcjonalny, sprawdzany cyfrą kontrolną (8, 12, 13 albo 14 cyfr), bez wymogu unikalności. | Unikalny EAN: sprzedawcy miewają ten sam kod na kilku pozycjach (zestawy); drugi indeks unikalny na ~30 mln wariantów bez odbiorcy. |
| D7 | SKU („Kod produktu”) i EAN są publiczne na karcie. JSON-LD dostaje `sku` oraz `gtin`, ale `gtin` tylko dla EAN będącego globalnym numerem towaru: kod z puli obiegu ograniczonego GS1 („Pola i reguły”) nie trafia do `gtin`. | Kody tylko w panelu: brak korzyści dla klienta i dla danych strukturalnych. Każdy EAN w `gtin`: dane strukturalne twierdziłyby, że kod wewnętrzny identyfikuje towar w obrocie. |
| D8 | Publikacja produktu nie wymaga żadnego z nowych pól. | Wymagane pola publikacji: sens dopiero przy danych GPSR. |
| D9 | Dane demo: EAN z puli GS1 o prefiksie „2” (numery obiegu ograniczonego), więc fikcyjny produkt nie nosi kodu prawdziwego towaru. Skutek D7: żaden produkt danych demo nie ma `gtin` w JSON-LD; kod widać w panelu i na karcie. Treść seeda jest pisana per typ i model, treść generatora powstaje ze słowników z budżetem długości (niżej). | Losowe EAN z pełnej puli: kolizje z kodami istniejących towarów. Dane demo bez EAN: pole w panelu i na karcie byłoby puste w całym katalogu. |
| D10 | Etap 1 nie zmienia indeksu wyszukiwarki ani jego dokumentów. Wdrożenie etapu poprzedza `generate PROFILE=server` na worf.pl („Skala i dane na serwerze”). | Marka w indeksie już teraz: zmiana mapowania i pełna reindeksacja bez funkcji, która z niej korzysta. |

## Wymagane przed startem

- PR #915 (API-INPUT-CONTRACT etap 2) jest na `main`. Etap 1 zmienia te same pliki (`Product.php`,
  `ValidProductWriteValidator.php`, `ProductInput.php`, `DoctrineProductRepository.php`, `YamlDemoCatalogSource.php`,
  oba pliki OpenAPI, `CatalogTextRows.php`) i opiera się na jego regułach:
  - teksty wpisywane przez ludzi sprawdza wyłącznie `App\Shared\Domain\Text` (T1–T5); value objecty Catalog są własne
    i wołają `Text`;
  - odczyt z bazy odtwarza value objecty konstruktorem `stored()` bez walidacji; regułę stosuje zapis (żądanie, seed,
    generator);
  - każde nowe pole tekstowe treści żądania ma wiersz w `CatalogTextRows` albo wpis z powodem w
    `TextFieldExceptions`; inaczej `ClosedTextFieldListTest` nie przechodzi.
- Sesja prowadząca przed pierwszym zadaniem sprawdza, czy sekcja „Etap 2: reguła tekstu” w
  `docs/features/api-input-contract/spec.md` na `main` zgadza się z opisem wyżej; różnica to pytanie do właściciela.

## Pola i reguły

Brak wartości w API to pusty tekst (`""`) albo pusta lista, tak jak dziś `shortDescription`. Wszystkie klucze są
częścią treści żądania: brak klucza to naruszenie przy polu (jak dla istniejących pól), nieznane klucze są pomijane.

| Pole | Gdzie | Reguła |
|---|---|---|
| `brand` | produkt | Opcjonalny tekst jednowierszowy pod regułą `Text`; po przycięciu 0–60 znaków. |
| `description` | produkt | Opcjonalny tekst pod regułą `Text` z dozwolonym U+000A; po przycięciu 0–5000 znaków. |
| `parameters` | produkt | Lista 0–30 obiektów `{name, value}` w kolejności wyświetlania. `name`: tekst jednowierszowy, 1–60 znaków. `value`: tekst jednowierszowy, 1–200 znaków. Nazwy różne bez względu na wielkość liter (porównanie po przycięciu, `mb_strtolower`, jak osie wariantów). |
| `sku` | wariant | Opcjonalny. Po przycięciu brzegów (T2) i zamianie liter ASCII na wielkie: 1–40 znaków z `A–Z`, `0–9`, `-`, `_`, `.`, `/`, pierwszy znak to litera albo cyfra. Unikalny wśród wszystkich wariantów wszystkich produktów. Poza regułą `Text` (zamknięty alfabet, wpis w `TextFieldExceptions`). |
| `ean` | wariant | Opcjonalny. Po przycięciu brzegów (T2): same cyfry, długość 8, 12, 13 albo 14, poprawna cyfra kontrolna GS1. Nieunikalny. Poza regułą `Text` (własny format, wpis w `TextFieldExceptions`). |

Kolejność sprawdzania jest w każdym nowym polu taka sama jak w regule tekstu: najpierw T1 na wartości, jak przyszła,
potem przycięcie brzegów (T2), potem długość albo format. `sku` i `ean` robią to jak pola z formatem API-INPUT-CONTRACT
(`postalCode`, `phone`): znak niedozwolony przez T1, także na brzegu, daje naruszenie formatu pola, a nie cicho
przycięty kod.

Kody nie należą do tożsamości wariantu (K5 CATALOG-ADMIN): można je zmieniać także po publikacji. Zmiana samych kodów
albo samych nowych pól produktu podnosi wersję produktu i publikuje `ProductChanged` jak każdy zapis.

**Kody należą do wariantu, nie do jego wartości.** Wariant, który zostaje w produkcie (ten sam `variantId`), zachowuje
SKU i EAN z żądania przy każdej dozwolonej zmianie osi i wartości: przed pierwszą publikacją przy dodaniu, usunięciu
i przestawieniu osi, zmianie nazwy osi i zmianie wartości wariantu (także przy usunięciu ostatniej osi, gdy zostaje
jeden wariant domyślny), a po publikacji przy zmianach dozwolonych przez K5 (wielkość liter i brzegi). Kod znika tylko
wtedy, gdy żądanie niesie pusty kod albo wariant jest usuwany.

**EAN z puli obiegu ograniczonego (RCN).** EAN jest globalnym numerem towaru, chyba że jest numerem obiegu
ograniczonego (Restricted Circulation Number). Źródło: GS1 General Specifications, wydanie 26.0 (styczeń 2026),
§2.1.11 „Fixed measure trade items – restricted distribution applications”, s. 113–116
(<https://ref.gs1.org/standards/genspecs/>; odczytane 2026-11-25). Według §2.1.11 numery te mają 8, 12 albo 13 cyfr
i nie dają jednoznacznej identyfikacji poza firmą albo regionem. Domena Catalog rozpoznaje je na postaci 14-cyfrowej
kodu (kod dopełniony zerami z lewej):

| Rodzaj i paragraf | Reguła na postaci 14-cyfrowej | Przykłady (z cyfrą kontrolną) |
|---|---|---|
| RCN-8 z prefiksem 0 albo 2 (§2.1.11.1) | sześć zer na początku (kod 8-cyfrowy), siódma cyfra 0 albo 2 | `00000017`, `20000011` |
| RCN-13 z prefiksem GS1 04 i RCN-12 z prefiksem U.P.C. 4 (§2.1.11.2) | cyfry 2–3 to `04` | `0400000000008`, `400000000008` |
| RCN-12 z prefiksem U.P.C. 0: LAC i RZSC (§2.1.11.3) | druga cyfra to `0` (kod jest U.P.C.-12, także dopełniony do 13 albo 14 cyfr, albo GTIN-14 nad nim), a cyfry 3–8 (U.P.C. Company Prefix) są w zakresie `001000`–`007999`; prefiks firmowy `000000` (kody „velocity”) spełnia pierwszy wiersz | `001000000052`, `007999000097`, `001000001004`, `005000009992`, `000000000000`, `000000009997` |
| Prefiksy GS1 02 i 20–29, w tym RCN-12 z prefiksem U.P.C. 2 (§2.1.11.4) | cyfry 2–3 to `02` albo druga cyfra to `2` | `0200000000004`, `2000000000008`, `2999999999991`, `200000000004` |

- Sześć przykładów trzeciego wiersza to skrajne numery z rysunku 2-1 paragrafu (LAC, RZSC, „velocity”); ich cyfry
  kontrolne zgadzają się z rysunkiem. §2.1.11.3 wymienia prefiksy firmowe `000000` i `001000`–`007999` jako użyte w tym
  zastosowaniu, a rysunek zawęża je do 40 500 numerów. Reguła bierze całe prefiksy firmowe (nadzbiór rysunku):
  `gtin` jest twierdzeniem o globalnej identyfikacji, więc przy wątpliwości zostaje puste.
- Kod 14-cyfrowy, którego 13 ostatnich cyfr spełnia wiersz 2, 3 albo 4, też nie dostaje `gtin`: RCN nie ma postaci
  14-cyfrowej.
- Prefiksy spoza §2.1.11 (np. `03`, `05`, `30`, prefiksy firmowe U.P.C. `000999` i `008000`, kod 8-cyfrowy
  zaczynający się od `1` albo `9`) nie są RCN. Kod 13-cyfrowy, którego druga cyfra postaci 14-cyfrowej nie jest zerem
  (np. `3001234567892`, `4006381333931`), nie jest U.P.C.-12, więc nie podlega wierszowi 3 (ma `gtin`, jeśli nie spełnia innego wiersza).

Rozstrzyga to domena Catalog, nie sklep: odpowiedź sklepu niesie `ean` (kod wpisany przez sprzedawcę) i `gtin` (ten
sam kod, gdy jest globalnym numerem towaru, inaczej pusty tekst). Panel przyjmuje i pokazuje każdy poprawny EAN.

### Naruszenia (status 422, jedno naruszenie przy polu)

| Pole | Przypadek | `propertyPath` | Komunikat |
|---|---|---|---|
| `brand` | brak klucza, zły typ | `brand` | „Marka musi być tekstem.” |
| | znak niedozwolony | `brand` | „Marka zawiera niedozwolone znaki.” |
| | za długa | `brand` | „Marka może mieć najwyżej 60 znaków.” |
| `description` | brak klucza, zły typ | `description` | „Opis musi być tekstem.” |
| | znak niedozwolony | `description` | „Opis zawiera niedozwolone znaki.” |
| | za długi | `description` | „Opis może mieć najwyżej 5000 znaków.” |
| `parameters` | brak klucza, nie lista | `parameters` | „Parametry muszą być listą.” |
| | ponad 30 | `parameters` | „Produkt może mieć najwyżej 30 parametrów.” |
| | element nie jest obiektem z `name` i `value` | `parameters[i]` | „Parametr musi mieć nazwę i wartość.” |
| | nazwa: zły typ, pusta (T3), za długa | `parameters[i].name` | „Nazwa parametru musi mieć od 1 do 60 znaków.” |
| | nazwa: znak niedozwolony | `parameters[i].name` | „Nazwa parametru zawiera niedozwolone znaki.” |
| | nazwa powtórzona (późniejszy wiersz) | `parameters[i].name` | „Parametr o tej nazwie już jest na liście.” |
| | wartość: zły typ, pusta (T3), za długa | `parameters[i].value` | „Wartość parametru musi mieć od 1 do 200 znaków.” |
| | wartość: znak niedozwolony | `parameters[i].value` | „Wartość parametru zawiera niedozwolone znaki.” |
| `sku` | brak klucza, zły typ | `variants[i].sku` | „Kod SKU musi być tekstem.” |
| | zły format | `variants[i].sku` | „Kod SKU może mieć od 1 do 40 znaków: litery A–Z, cyfry oraz znaki - _ . / i musi zaczynać się literą albo cyfrą.” |
| | powtórzony w żądaniu (późniejszy wiersz) | `variants[i].sku` | „Ten kod SKU powtarza się w produkcie.” |
| | zajęty przez wariant innego produktu | `variants[i].sku` | „Kod SKU jest już używany przez inny produkt.” |
| `ean` | brak klucza, zły typ (także liczba JSON) | `variants[i].ean` | „Kod EAN musi być tekstem.” |
| | zły format albo cyfra kontrolna | `variants[i].ean` | „Podaj poprawny kod EAN: 8, 12, 13 albo 14 cyfr z cyfrą kontrolną.” |

Tekst z brzegami (T2) jest przyjęty i zapisany bez nich; tekst pusty według T3 w polu opcjonalnym zapisuje brak
wartości. Znak U+000D w opisie jest znakiem niedozwolonym (jak w krótkim opisie).

### Unikalność SKU przy równoległym zapisie

- Handler zapisu sprawdza przed zapisem, czy któryś SKU produktu należy do wariantu innego produktu: odpowiedź 422 przy
  `variants[i].sku` (tabela wyżej).
- Gwarancją jest indeks unikalny kolumny `sku`. Zapis, który przeszedł sprawdzenie, a przegrał wyścig z równoległym
  zapisem innego produktu, kończy się 409 z `type` `…/problems/sku-taken` i `detail` „Kod SKU jest już używany przez
  inny produkt.”, bez `violations` (wzorzec `slug-taken`: repozytorium rozpoznaje naruszenie po nazwie ograniczenia).
  Nigdy 500 i nigdy dwa warianty z tym samym SKU.
- Zamiana kodów między wariantami jednego produktu w jednym zapisie jest dozwolona (repozytorium usuwa stare wiersze
  wariantów przed dodaniem nowych, jak dla pozycji i wartości).
- Kody wariantów zapisywanego produktu nie są dla niego zajęte. Powtórzenie tego samego `PUT` ze starą wersją po
  udanym zapisie daje więc 409 `product-modified`, a nie naruszenie przy SKU.
- Powtórzenie `POST` po udanym utworzeniu, na które nie przyszła odpowiedź, dostaje naruszenie zajętego SKU (422 przy
  polu albo 409 `sku-taken`), bo kod należy już do produktu z pierwszego żądania; utworzenie nie ma klucza idempotencji
  (ograniczenie B4 ADMIN-UX-FIXES). Panel mówi o tym przy komunikacie („Panel”, „Nieznany wynik zapisu”).

## Model i persystencja

Bez nowego agregatu. `Product` (Catalog) dostaje markę, opis i parametry; `ProductVariant` dostaje SKU i EAN. Nowe
value objecty w `Catalog\Domain\Product` z konstruktorami zapisu i `stored()`: marka, opis, parametry (lista
i pozycja), SKU, EAN. `Product::create()`, `edit()` i `reconstitute()` przyjmują nowe wartości; reguły publikacji
i blokady tożsamości wariantu się nie zmieniają.

Repozytorium zapisuje dziś wiersze wariantów tylko wtedy, gdy zmieniła się lista identyfikatorów i wartości
(`DoctrineProductRepository::write()` porównuje `storedVariants()`), i wymienia je przez usunięcie i ponowne dodanie.
Po etapie zmiana samego kodu też jest zmianą wierszy wariantów, a wymiana wierszy zapisuje kody każdego wariantu,
który zostaje.

Migracje (zwykłe, bez przepisywania wierszy; `ProductRecord` i `ProductVariantRecord` mapują te same kolumny):

- `catalog_product`: `brand` (tekst do 60 znaków, pusty domyślnie), `description` (`text`, pusty domyślnie),
  `parameters` (`jsonb`, `[]` domyślnie; `[{"name": …, "value": …}]`).
- `catalog_product_variant`: `sku` (do 40 znaków, `NULL` = brak), `ean` (do 14 znaków, `NULL` = brak).
- Indeks unikalny `sku` w osobnej migracji nietransakcyjnej (`CREATE UNIQUE INDEX CONCURRENTLY`, zasada
  `apps/api/CLAUDE.md`). `NULL` się nie powtarza, więc istniejące wiersze bez kodu go nie naruszają. `ean` bez indeksu.

Kolumny z wartością domyślną dodaje sam katalog bazy, bez przepisywania tabeli, więc migracja mieści się w limicie
30 minut zadania `migrate` także na tabelach 10 mln produktów i 30 mln wariantów. Założenie o czasie budowy indeksu na
30 mln wierszy z samymi `NULL` jest niezmierzone; kolejność z „Skala i dane na serwerze” sprawia, że indeks powstaje
na serwerze przy 3 mln wariantów.

## API

### Panel (`/api/v1/admin`, `openapi-admin.json`)

- `POST /products`, `PUT /products/{id}`: treść zyskuje wymagane klucze `brand`, `description`, `parameters` oraz
  `sku` i `ean` w każdym elemencie `variants`.
- `GET /products/{id}` i odpowiedzi zapisu (`ProductEditViewResponse`): `brand`, `description`,
  `parameters[{name, value}]`, `variants[{variantId, values, sku, ean}]`. SKU wraca w postaci zapisanej (wielkie
  litery).
- Uprawnienia bez zmian (K3 CATALOG-ADMIN). Lista produktów panelu bez zmian.

### Sklep (`openapi.json`)

- `GET /api/v1/products/{slug}` (`ProductDetails`): `brand`, `description`, `parameters[{name, value}]`,
  `variants[{variantId, label, values, sku, ean, gtin}]`; `gtin` według „EAN z puli obiegu ograniczonego”. Nagłówki
  cache i tagi xkey bez zmian; zapis produktu unieważnia odpowiedź i stronę jak dziś (`ProductChanged`).
- `GET /api/v1/products`, `GET /api/v1/product-offers`, kontrakty między modułami (`GetVariantSummaries`,
  `ListVariantIdentities`, `ListProductVariants`, `GetVariant`) i dokument Elasticsearch: bez zmian. Skrót definicji
  indeksu (`_meta`) zostaje ten sam, więc etap nie wymaga reindeksacji.

Po zmianie klas żądań i odpowiedzi: `make -C apps/api openapi` i `make api-client` w tym samym commicie.
Wołający zapis produktu poza wygenerowanymi klientami (`git grep 'admin/products'`): testy funkcjonalne API
(`tests/Functional/Catalog`, `Pricing`, `DemoData`, `Shared`, `Backoffice`) i `e2e/tests/catalog-admin.spec.ts`;
`load/` i `infra/k8s/smoke/` go nie wołają.

## Panel (`apps/admin`)

Formularz produktu (`ProductFormView.vue`, `useProductForm.ts`, `ProductVariants.vue`), w standardzie formularzy
panelu (`FormSection`, `FormField`, `SaveBar`, `useFormSafety`):

- „Dane produktu”: pole „Marka” (opcjonalne, `maxlength` 60) po nazwie; pole „Opis” (obszar tekstu z licznikiem
  `n/5000`) po krótkim opisie.
- Nowa sekcja „Parametry”: wiersze „Nazwa” i „Wartość”, przyciski „Przesuń parametr N w górę”, „…w dół”, „Usuń
  parametr N”, przycisk „Dodaj parametr” (nieaktywny przy 30). Po dodaniu focus trafia do nazwy nowego wiersza, po
  usunięciu do następnego wiersza albo do „Dodaj parametr”.
- „Warianty”: kolumny „SKU” (`maxlength` 40) i „EAN” (`maxlength` 14) w każdym wierszu, także dla produktu z jednym
  wariantem bez osi.
- Naruszenia 422 trafiają do pól po `propertyPath` (`brand`, `description`, `parameters[i].name`,
  `parameters[i].value`, `parameters`, `variants[i].sku`, `variants[i].ean`); 409 `sku-taken` jest komunikatem sekcji
  wariantów. Po zapisie formularz jest odbudowany z odpowiedzi API (SKU wielkimi literami).
- Nowe pola podlegają tym samym zasadom co istniejące: blokada na czas żądania, ochrona niezapisanych zmian,
  propozycja przywrócenia po 409 `product-modified`, etykiety i `aria-describedby`, obsługa z klawiatury, ekran 375 px
  bez poziomego przewijania strony.
- Panel nie sprawdza formatu kodów ani reguły tekstu: robi to API.

### Kody przy zmianie osi i wierszy

Edytor wariantów przebudowuje dziś wiersze przy każdej operacji na osiach (`changeRows` w `useProductForm.ts` tworzy
wiersz z samego `variantId` i wartości), więc bez zmiany zgubiłby kody. Po etapie SKU i EAN wiersza zostają przy
dodaniu, usunięciu i przestawieniu osi, zmianie nazwy osi i zmianie wartości wiersza; po usunięciu ostatniej osi
zostaje pierwszy wiersz ze swoim identyfikatorem i swoimi kodami. Kody znikają tylko razem z usuniętym wierszem.
Nowy wiersz ma puste kody.

### Nieznany wynik zapisu

Obowiązuje B4 ADMIN-UX-FIXES (`docs/features/admin-ux-fixes/spec.md`): „Sprawdź stan” porównuje ściśle żądanie
zbudowane ze szkicu z żądaniem zbudowanym z odczytu. API normalizuje nowe pola, więc bez zmiany udany zapis, na który
nie przyszła odpowiedź, wyglądałby po „Sprawdź stan” jak cudza zmiana („Na serwerze jest inna wersja niż ta, którą
edytujesz.”). Dlatego:

**Wysyłka bez przycinania.** Żądanie zapisu niesie nowe pola dokładnie tak, jak są w szkicu: panel nie przycina
brzegów i nie usuwa znaków. Regułę stosuje API w swojej kolejności: T1 na wartości, jak przyszła, dopiero potem
przycięcie (T2). Przycinanie w panelu (`trim()` JavaScript) usuwałoby z brzegów znaki, które T1 odrzuca (tabulator,
U+000D, koniec wiersza w polu jednowierszowym, U+2028, U+2029), więc API zapisałoby wartość bez naruszenia, którego
wymaga kontrakt. Jedyna zmiana wartości po stronie panelu: pole „SKU” zamienia litery ASCII na wielkie przy
wpisywaniu, więc pracownik widzi kod w postaci, w jakiej zostanie zapisany; żadnego znaku to nie usuwa.

**Porównanie „Sprawdź stan” z normalizacją API.** Dla nowych pól wartość ze szkicu przechodzi przed ścisłym
porównaniem przez tę samą normalizację, którą wykonuje API przy zapisie, w tej samej kolejności:

1. T1: wartość ze znakiem niedozwolonym w tym polu (w opisie dozwolony jest U+000A) nie ma postaci znormalizowanej.
   Szkic z taką wartością nie jest zgodny z żadnym stanem serwera, bo API takiej wartości nie zapisuje.
2. T2: z brzegów znikają białe znaki Unicode oraz U+200B, U+2060, U+FEFF i U+00AD; w środku nic.
3. T3: tekst bez znaku widocznego to pusty tekst (brak marki, opisu albo kodu).
4. SKU: po krokach 1–3 litery ASCII na wielkie. EAN: bez dalszych zmian.

Normalizacja służy wyłącznie temu porównaniu: nie zmienia pól formularza, wysyłanego żądania ani wykrywania zmian
(`dirty`). Jest jedynym miejscem panelu, które zna regułę tekstu, i odpowiada `App\Shared\Domain\Text`
(`hasForbiddenCharacters`, `normalize`); jej zgodność z API jest sprawdzana testami („Testy”), bo kopia reguły w drugim
języku może się rozjechać (różnice klas znaków między PCRE a JavaScript, np. U+180E i U+0085).

Skutek i pozostałe zasady:

- Po udanym zapisie bez odpowiedzi „Sprawdź stan” pokazuje „Stan na serwerze odpowiada formularzowi.”, formularz
  przyjmuje stan z serwera (wersja, kody, teksty bez brzegów), a kolejny zapis przechodzi bez 409. Dotyczy każdego
  znaku brzegu z T2, w tym U+200B, opisu pustego według T3 i SKU wpisanego albo wklejonego małymi literami. Wiersz
  wariantu bez identyfikatora jest dopasowany po wartościach, jak dziś, i porównany razem z kodami.
- Po nieznanym wyniku utworzenia produktu ponowny zapis odrzucony z powodu zajętego SKU (422 przy `variants[i].sku`
  albo 409 `sku-taken`) ma przy tym komunikacie zdanie „Możliwe, że utworzył go Twój wcześniejszy zapis.”, tak jak
  dziś 409 zajętego sluga.
- Istniejące pola formularza (nazwa, krótki opis, teksty zdjęć, osie i wartości) zostają przy ścisłym porównaniu B4:
  ich przycięte brzegi dalej dają brak zgodności.

## Sklep (`apps/store`)

Karta `/p/<slug>` (`ProductDetailsView.tsx`, `VariantChoice.tsx`):

- Marka nad nazwą (zwykły tekst), gdy niepusta.
- „Kod produktu: <SKU>” i „EAN: <EAN>” pod wyborem wariantu, każdy tylko gdy niepusty: dla produktu z jednym
  wariantem zawsze (także niedostępnego), dla produktu z wieloma wariantami po wybraniu wariantu. Pierwszy render jest
  taki sam dla każdego odwiedzającego (ISR).
- Pod zdjęciami i danymi zakupu, na całą szerokość: sekcja „Opis” (`h2`; akapity rozdzielone pustym wierszem,
  pojedynczy koniec wiersza to złamanie wiersza; tekst dosłownie, bez interpretacji znaczników) i sekcja „Parametry”
  (`h2`; lista opisowa nazwa–wartość w kolejności z API). Każda sekcja tylko wtedy, gdy ma treść; produkt bez opisu
  i parametrów wygląda jak dziś.
- Krótki opis zostaje przy nazwie; metadane strony (`title`, `description`) bez zmian.
- JSON-LD (`product-json-ld.ts`): `brand: {"@type": "Brand", "name": …}` przy niepustej marce; w każdym `Offer`
  wariantu `sku` z pola `sku` i `gtin` z pola `gtin` odpowiedzi API, każde tylko gdy niepuste. Sklep nie ocenia kodu:
  EAN z puli obiegu ograniczonego ma w odpowiedzi puste `gtin`, więc nie trafia do JSON-LD, choć karta go pokazuje.
  `description` zostaje krótkim opisem.
- Kafelek listy, koszyk, kasa i konto: bez zmian.

Cache ISR ma limit w bajtach (`cacheMaxMemorySize`, 128 MB w `next.config.ts`), więc większa strona zmniejsza liczbę
stron w pamięci, a nie zwiększa jej zużycia.

## Dane demo

### Seed (`fixtures/catalog.yaml`, 37 szablonów, 191 produktów)

- Marka: z listy `brands` szablonu, ta sama, która jest w nazwie. Nazwy, slugi i identyfikatory produktów
  i wariantów się nie zmieniają.
- Opis: szablon dostaje opis typu produktu (2–3 akapity), a opis produktu to zdanie modelu i akapity typu. Model
  może mieć własny opis w całości.
- Parametry: wspólne dla typu w szablonie i własne modelu (np. „Liczba osób”, „Waga”, „Wodoodporność tropiku”);
  parametr modelu o tej samej nazwie zastępuje parametr typu. Każdy produkt ma co najmniej 3 parametry, żaden nie
  powtarza nazwy osi wariantów produktu, a treść zgadza się z krótkim opisem modelu (namiot „Halny 4” ma „Liczba
  osób: 4”).
- SKU: czytelny kod z marki, modelu i wartości wariantu, zaczyna się literą, unikalny w seedzie.
- EAN: EAN-13 z prefiksem „2”, wyliczony z klucza wariantu, z poprawną cyfrą kontrolną.
- Seed dalej idzie przez komendę `CreateProduct`, więc wszystkie wartości przechodzą reguły zapisu. Błędna treść
  `catalog.yaml` (powtórzona nazwa parametru, parametr o nazwie osi, za długi opis) zatrzymuje seed przy wczytaniu
  katalogu, przed zmianą danych, z komunikatem wskazującym produkt, tak jak dziś błędne wartości istniejących pól.

### Generator (`DemoData`, profile `small`…`full`)

- Marka: marka już losowana do nazwy (bez nowego losowania).
- Opis: 2–3 akapity, 300–600 znaków, ze słowników (cechy typu, pule zdań działu), bez słów, które musiałyby się
  zgadzać gramatycznie z nazwą produktu, i inny niż krótki opis.
- Parametry: 3–6 par z zestawów parametrów typu produktu (nazwa i pula wartości), bez nazw osi wariantów typu.
- SKU: numer produktu dopełniony zerami, potem pozycja wariantu; zaczyna się cyfrą. Unikalny z konstrukcji, bez
  odczytów z bazy, i rozłączny z seedem (seed zaczyna literą). Numer na początku kodu sprawia, że wiersze jednej części
  generatora trafiają do sąsiednich stron indeksu unikalnego („Skala i dane na serwerze”, p. 5).
- EAN: EAN-13 z prefiksem „2” z numeru produktu i pozycji wariantu, z cyfrą kontrolną.
- Nowe losowania idą z osobnego strumienia generatora liczb. To samo ziarno, profil i chwila odniesienia dają te same
  co przed etapem identyfikatory, slugi, nazwy, krótkie opisy, warianty i ceny, a nowe pola są deterministyczne
  i niezależne od liczby procesów.
- `COPY` dostaje nowe kolumny (`CatalogLoader`); `demo-data:verify` sprawdza, że żaden wygenerowany produkt nie ma
  marki, opisu ani parametrów (brak to `NULL`, pusty tekst albo tekst bez znaku widocznego według T3; parametr bez nazwy
  lub wartości też jest naruszeniem) i żaden wygenerowany wariant nie jest bez SKU albo EAN (ta sama definicja braku).

## Skala i dane na serwerze

Decyzje etapu, które wpływają na planowane 10 mln produktów (`docs/features/demo-scale/spec.md`):

1. **Kolejność.** Generator pisze kolumny, które zna w chwili uruchomienia. `generate PROFILE=server` uruchomione przed
   wdrożeniem etapu 1 da 10 mln produktów z pustymi nowymi polami, a ich uzupełnienie wymaga drugiego pełnego
   generowania (prognoza około 11 h przy zamkniętym sklepie, `docs/reports/demo-scale-medium-worf-2026-11-20.md`).
   Etap 1 powinien być na serwerze przed `generate PROFILE=server`.
2. **worf.pl dziś (`medium`).** Po wdrożeniu etapu 1 istniejące produkty (seed i 1 mln wygenerowanych) mają puste nowe
   pola, więc karta wygląda jak dziś. Poprawę na serwerze daje dopiero ponowne `generate` (osobna zgoda właściciela
   z nazwą operacji, hosta i profilu); produkty dodane albo uzupełnione w panelu pokazują nowe pola od razu.
3. **Rozmiar tabel: pomiar.** Etap mierzy, nie szacuje, rozmiar `catalog_product` i `catalog_product_variant`: sterta,
   TOAST i każdy indeks osobno, w tym indeks unikalny SKU. Dwa pomiary, każdy przed zmianą i po niej, z tego samego
   ziarna:
   - pełny przebieg generatora na `small` (2 000 produktów): rozmiar końcowy po wszystkich krokach, czyli razem
     z martwymi wersjami wierszy po kroku zdjęć, który aktualizuje każdy wiersz produktu (`ProductPhotos`);
   - same kroki katalogu (`demo-data:categories`, `demo-data:products`) na `medium` (1 mln produktów, około 3 mln
     wariantów), bez zdjęć, cen i zamówień: rozmiar tabel i indeksów przy liczbie wierszy, przy której indeks ma
     już kilka poziomów.

   Wynik to bajty na produkt i na wariant, zapisane jako pomiar tych dwóch przebiegów. Budżet długości opisu
   (300–600 znaków) jest częścią specyfikacji, bo wprost skaluje ten rozmiar. Przed pomiarem jedyną liczbą jest
   rachunek z budżetów (opis średnio ~450 znaków, ~4,5 parametru, SKU i EAN na ~3 warianty): rząd 1 kB na produkt.
4. **Szczyt dysku: szacunek, osobno od pomiaru.** `diskPeakGb` profilu to szacunek szczytu całego stosu w trakcie
   generowania, nie rozmiar tabel po nim. Przyrost szczytu składa się z: zmierzonego przyrostu tabel i indeksów
   (p. 3) przeliczonego na liczbę produktów i wariantów profilu; drugiej kopii przyrostu wiersza produktu na czas
   kroku zdjęć (stara wersja wiersza zostaje do odkurzenia); WAL nowych kolumn i indeksu SKU. Zadanie generatora
   zapisuje ten rachunek składnik po składniku w `docs/features/demo-data/spec.md`, oznacza wartości `medium`,
   `server` i `full` jako szacunek niezweryfikowany przebiegiem i podnosi `diskPeakGb` w `profiles.yaml`. Od
   `diskPeakGb` zależy strażnik wolnego miejsca (wolne ≥ `1,25 × diskPeakGb`): po etapie `diskPeakGb` to 23 GB (`medium`),
   114 GB (`server`) i 268 GB (`full`), więc próg dla `server` to 142,5 GB wobec 163,9 GiB (176 GB) wolnych po `medium`.
   Przyrost szczytu `server` (17,85 GB: około 10 GB tabel i indeksów i około 8 GB drugiej kopii wierszy) i pomiary, z
   których wynika, są w `docs/features/demo-data/spec.md` („Rozmiar i czas pól treści produktów”); wszystkie trzy wartości
   to szacunek niezweryfikowany przebiegiem. Szczyt `server` potwierdzi dopiero raport z jego przebiegu (DEMO-SCALE).
5. **Czas generowania i indeks SKU podczas `COPY`: szacunek z pomiaru kroku.** Indeks unikalny SKU istnieje, gdy
   generator ładuje warianty, więc każdy wiersz `COPY` dopisuje do niego wpis (tak jak dziś do dwóch indeksów
   unikalnych wariantu). Generator nie zmienia schematu (`docs/features/demo-data/spec.md`), więc nie usuwa indeksu na
   czas ładowania. Koszt ogranicza budowa kodu: numer produktu na początku SKU daje w każdej części wpisy do
   sąsiednich stron indeksu, a nie do losowych. Zadanie generatora mierzy czas kroku `demo-data:products` na `medium`
   przed zmianą i po niej (ta sama maszyna, ta sama liczba procesów, kroki wywołane tak, jak robi to
   `bin/generate-demo`) i zapisuje różnicę. Gdy kroków katalogu `medium` nie da się uruchomić lokalnie, to
   niespełnione wymaganie do decyzji właściciela, a nie powód, żeby czas i indeks oszacować z `small`. Czas dla `server` i `full`
   to szacunek z tego pomiaru (×10), niezweryfikowany: gdy indeks przestaje mieścić się w pamięci bazy (chart nie ustawia
   `shared_buffers`, więc obowiązuje domyślne 128 MB), koszt wpisu może rosnąć szybciej niż liniowo. Odniesienie: krok produktów zajął około
   2 min z 67 min przebiegu `medium` na serwerze, a limit Joba `server` to 24 h przy prognozie 11 h. Dwa nowe
   sprawdzenia `demo-data:verify` to po jednym przejściu po tabeli produktów i wariantów; ich czas zadanie mierzy
   na `small` i zapisuje jako składnik szacunku.
6. **Wyszukiwarka.** Bez zmiany mapowania i dokumentów: czas i pamięć reindeksacji 10 mln się nie zmieniają, a pomiar
   shardów (#928) nie zależy od etapu.
7. **Decyzje odwracalne tylko przez ponowne generowanie.** Marka jako tekst (D2) i parametry bez słownika (D4):
   późniejszy filtr marki wymaga zmiany dokumentów i pełnej reindeksacji, a słownik marek albo atrybutów także
   przepisania danych demo. Jeśli filtr marki albo fasety parametrów mają wejść przed `generate PROFILE=server`,
   trzeba to rozstrzygnąć teraz.

Etap nie potwierdza działania przy 10 mln produktów: weryfikacja skali należy do DEMO-SCALE.

## Wdrożenie i zgodność wersji

- Migracje, bez zmian compose i chartu.
- Treść zapisu produktu zyskuje wymagane klucze. Panel otwarty sprzed wdrożenia dostaje przy zapisie 422 przy nowych
  polach i nie nadpisuje danych; po odświeżeniu działa. Na etapie demo bez działań.
- Odpowiedź szczegółów produktu w Varnishu sprzed wdrożenia nie ma nowych kluczy; wdrożenie kończy się zadaniem
  `purge-caches` chartu (`storefront:cache:purge-all`).
- Dane demo lokalnie: `make seed`. Dane na serwerze: „Skala i dane na serwerze”, p. 2.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Dwóch pracowników zapisuje ten sam produkt | 409 `product-modified` jak dziś; propozycja przywrócenia obejmuje nowe pola. |
| Dwa produkty zapisane równolegle z tym samym SKU | Jeden zapis przechodzi, drugi dostaje 422 przy polu albo 409 `sku-taken`; w bazie jeden wariant z tym kodem. |
| SKU różni się tylko wielkością liter od istniejącego | Zajęty (kod jest zapisywany wielkimi literami). |
| Usunięty wariant miał SKU | Kod jest wolny od razu po zapisie. |
| Szkic produktu: dodana, usunięta albo przestawiona oś, zmieniona wartość wariantu, który zostaje | Wariant ma po zapisie te same SKU i EAN; w formularzu kody nie znikają przy operacji na osi. |
| Edycja: zapis się udał, odpowiedź nie dotarła (błąd sieci, 5xx, 10 s), SKU małymi literami albo pola ze znakami brzegu T2 (spacja, U+00A0, U+200B) | „Sprawdź stan”: „Stan na serwerze odpowiada formularzowi.”, formularz ma nową wersję i wartości z serwera. |
| Marka z tabulatorem na końcu, wartość parametru z U+2028, SKU z tabulatorem na brzegu | Panel wysyła wartość bez zmian; 422 przy polu (niedozwolone znaki albo format kodu), nic nie jest zapisane. |
| Nieznany wynik zapisu, a szkic ma w nowym polu znak niedozwolony przez T1 | „Sprawdź stan” nie stwierdza zgodności, niezależnie od stanu serwera. |
| Edycja: ten sam `PUT` wysłany ponownie ze starą wersją po udanym zapisie | 409 `product-modified`, bez naruszenia przy SKU. |
| Nowy produkt: utworzenie się udało, odpowiedź nie dotarła, pracownik zapisuje ponownie | Odmowa z powodu zajętego SKU (albo sluga) ze zdaniem „Możliwe, że utworzył go Twój wcześniejszy zapis.”; drugi produkt z tym kodem nie powstaje. |
| EAN z puli obiegu ograniczonego (np. każdy EAN danych demo) | Karta pokazuje „EAN: …”, JSON-LD nie ma `gtin`. |
| Opis z samych białych znaków | Zapisany brak opisu; sekcja „Opis” niewidoczna. |
| Opis ze znakami `<script>` | Pokazany dosłownie jako tekst; JSON-LD bez zmian (`serializeJsonLd`). |
| Lista wysłana jako obiekt o kluczach „0”…„n” | Jak dla istniejących list (#903, poza etapem). |
| Wiersz produktu sprzed etapu | Puste nowe pola; karta, panel i zapis działają. |

## Dokumentacja do zmiany razem z implementacją

- `docs/features/catalog-admin/spec.md`: „Poza zakresem” (długi opis, marka, SKU i EAN), tabele treści żądania,
  opis formularza.
- `docs/features/product-page/spec.md`: „Poza zakresem”, kształt `ProductDetails`, lista właściwości JSON-LD, opis
  karty.
- `docs/features/variants/spec.md`: „Poza zakresem” (SKU i EAN), zdania o marce tylko w nazwie.
- `docs/features/api-input-contract/spec.md`: tabela pól treści JSON i „Pola tekstowe poza regułą” (`sku`, `ean`).
- `docs/features/admin-ux-fixes/spec.md`, B4: zdanie o przyciętych spacjach jako braku zgodności (nowe pola produktu
  są porównywane po normalizacji API) i zdanie o 409 pola unikalnego po nieznanym wyniku utworzenia (dochodzi zajęty
  SKU, także jako 422 przy polu).
- `docs/features/demo-data/spec.md`: pola generatora, rozmiary i `diskPeakGb`.
- `docs/vision.md`, „Dane demo”: zdanie o zawartości seeda.

## Kryteria akceptacji

1. Zapis produktu z panelu z marką, opisem, parametrami oraz SKU i EAN wariantów zwraca te wartości w
   `GET /api/v1/admin/products/{id}` i w `GET /api/v1/products/{slug}`; SKU wraca wielkimi literami, teksty bez
   brzegów (T2).
2. Każdy wiersz tabeli „Naruszenia” daje 422 z podanym `propertyPath` i komunikatem; żadne błędne wejście nowych pól
   nie daje 500.
3. Nowe pola tekstowe treści żądania mają wiersze w `CatalogTextRows` (`brand`, `description` z dozwolonym U+000A,
   `parameters[n].name`, `parameters[n].value`) albo wpis w `TextFieldExceptions` (`sku`, `ean`);
   `ClosedTextFieldListTest` przechodzi.
4. SKU zajęty przez inny produkt (także szkic, także różniący się wielkością liter) daje 422 przy `variants[i].sku`;
   zapis z pominięciem sprawdzenia przed zapisem (test repozytorium na indeksie) daje `sku-taken`, a w bazie zostaje
   jeden wariant z tym kodem. Zamiana kodów między wariantami jednego produktu w jednym zapisie przechodzi.
5. Zmiana samych kodów albo samych nowych pól produktu podnosi wersję, publikuje `ProductChanged` i unieważnia
   odpowiedź szczegółów; zapis ze starą wersją to 409 `product-modified`.
6. Produkt sprzed etapu (puste nowe pola) czyta się i zapisuje w panelu, a jego karta nie ma sekcji „Opis”
   i „Parametry”, marki ani kodów.
7. Formularz panelu ma pola „Marka” i „Opis”, sekcję „Parametry” (dodanie, usunięcie, zmiana kolejności, limit 30)
   oraz kolumny „SKU” i „EAN”; naruszenia API trafiają do właściwych pól; nowe pola są zablokowane w trakcie żądania;
   obsługa z klawiatury i focus po dodaniu i usunięciu parametru według sekcji „Panel”; 375 px bez poziomego
   przewijania strony.
8. Kody wariantu, który zostaje. (a) API: zapis szkicu ze zmienionymi osiami (dodana, usunięta, przestawiona oś,
   zmieniona nazwa osi, zmieniona wartość wariantu, usunięta ostatnia oś) zostawia wariantowi o tym samym `variantId`
   SKU i EAN z żądania, także gdy repozytorium wymienia wiersze wariantów; to samo po publikacji dla zmian
   dozwolonych przez K5. (b) API: zapis, który zmienia wyłącznie kod jednego wariantu, zapisuje ten kod i nie zmienia
   kodów pozostałych. (c) Panel: każda operacja edytora na osiach i wartościach zostawia SKU i EAN wierszy, które
   zostają, w szkicu i w wysłanym żądaniu; usunięcie ostatniej osi zostawia pierwszy wiersz z jego kodami.
9. Wysyłka nowych pól i udany zapis bez odpowiedzi.
   (a) Wysyłka: żądanie zapisu niesie nowe pola tak, jak są w szkicu, z brzegami i wszystkimi znakami. Marka
   z tabulatorem na końcu, wartość parametru z U+2028 na brzegu, opis z U+000D i SKU albo EAN z tabulatorem na
   brzegu dają 422 przy polu (niedozwolone znaki albo format kodu) i nic nie jest zapisane; panel nie zamienia tego
   w udany zapis przez przycięcie.
   (b) Edycja: zapis wykonany na serwerze, którego odpowiedź nie dotarła (błąd sieci, 502, limit 10 s), po „Sprawdź
   stan” daje „Stan na serwerze odpowiada formularzowi.”, nową wersję w formularzu i kolejny zapis bez 409, gdy
   wartości nowych pól mają na brzegach znaki przycinane przez T2: spację, U+00A0, U+2003, U+202F, U+3000, U+180E,
   U+200B, U+2060, U+FEFF, U+00AD, a opis także U+000A; gdy opis jest pusty według T3 (same białe znaki, samo
   U+200D); gdy SKU jest małymi literami; dotyczy też nowego wiersza wariantu z kodami (bez identyfikatora
   w szkicu). Znaki w środku tekstu (U+200B między literami, U+200D w emoji, znaki flagi na końcu) zostają i nie
   psują zgodności.
   (c) Zgodność normalizacji panelu z API jest sprawdzona dwoma testami: tabelą po punktach kodowych w testach panelu
   (wartość ze szkicu, postać znormalizowana albo „brak postaci” dla T1) i testem E2E na prawdziwym API, w którym
   zapis z wartościami z tej tabeli jest wykonany, odpowiedź odrzucona w przeglądarce, a „Sprawdź stan” stwierdza
   zgodność. Różnica między panelem a API dla któregokolwiek znaku z (b) kończy test E2E błędem.
   (d) Szkic, który w nowym polu ma znak niedozwolony przez T1 (także na brzegu), po „Sprawdź stan” nie jest zgodny
   z żadnym stanem serwera.
   (e) Normalizacja porównania nie zmienia pól formularza, wysyłanego żądania ani `dirty`; pole „SKU” pokazuje
   wielkie litery już przy wpisywaniu.
   (f) Ten sam `PUT` ze starą wersją po udanym zapisie daje 409 `product-modified`, nie naruszenie przy SKU.
   (g) Nowy produkt: po nieznanym wyniku utworzenia ponowny zapis odrzucony z powodu zajętego SKU (422 przy polu
   i 409 `sku-taken`) pokazuje przy komunikacie „Możliwe, że utworzył go Twój wcześniejszy zapis.”, a drugi produkt
   z tym kodem nie powstaje.
10. Karta w sklepie pokazuje markę, sekcje „Opis” (akapity) i „Parametry” oraz kod produktu i EAN wybranego wariantu
    według sekcji „Sklep”; tekst opisu ze znacznikami HTML jest pokazany dosłownie; 375 px bez poziomego przewijania.
11. JSON-LD i `gtin`. (a) Odpowiedź sklepu ma w wariancie `gtin` równe `ean` dla globalnego numeru towaru i puste dla
    numeru obiegu ograniczonego według tabeli §2.1.11 z sekcji „Pola i reguły”. Test tabelaryczny domeny obejmuje
    każdy przykład tabeli (w tym sześć numerów RCN-12 z prefiksem U.P.C. 0 z rysunku 2-1), ten sam RCN-12 zapisany
    jako 13 cyfr z zerem z lewej, kod 14-cyfrowy nad RCN-13 oraz kody, które RCN nie są: prefiksy `03`, `05`, `19`
    i `30`, prefiksy firmowe U.P.C. `000999` i `008000`, kod 8-cyfrowy zaczynający się od `1` i `9`, GTIN-14
    z cyfrą wskaźnika nad zwykłym GTIN-13. (b) JSON-LD `Product` ma `brand`, a `Offer` wariantu `sku` i `gtin`, każde tylko przy niepustym polu
    odpowiedzi; wariant z EAN o prefiksie „2” ma na karcie „EAN: …”, a w JSON-LD nie ma `gtin`. (c) Produkt bez nowych
    danych daje JSON-LD jak przed etapem.
12. Dokument i mapowanie Elasticsearch, `GET /api/v1/products`, `GET /api/v1/product-offers` i kontrakty między
    modułami są bez zmian; skrót definicji indeksu się nie zmienia.
13. Po `make seed` każdy z 191 produktów ma markę zgodną z nazwą, opis, co najmniej 3 parametry (żaden nie powtarza
    nazwy osi), a każdy wariant unikalny SKU i poprawny EAN-13 z prefiksem „2” (więc puste `gtin`); liczby produktów
    i wariantów, slugi i identyfikatory się nie zmieniają. „Namiot turystyczny Hallen Halny 4” ma parametr „Liczba
    osób: 4”.
14. Generator: każdy wygenerowany produkt ma markę równą marce z nazwy, opis 300–600 znaków inny niż krótki opis
    i 3–6 parametrów; każdy wariant unikalny SKU zaczynający się numerem produktu dopełnionym zerami i poprawny
    EAN-13 z prefiksem „2”. Te same ziarno, profil i chwila odniesienia dają te same nowe pola przy 1, 3 i 4
    procesach, a identyfikatory, slugi, nazwy, krótkie opisy i warianty są takie jak przed etapem: zadanie przed
    zmianą generatora dopisuje test ze skrótem tych kolumn profilu `small` wyliczonym na kodzie sprzed zmiany, a test
    przechodzi przed zmianą i po niej (ceny powstają z identyfikatorów wariantów we własnym strumieniu, więc zostają
    takie same).
15. `demo-data:verify` kończy się błędem, gdy wygenerowany produkt nie ma marki, opisu albo parametrów albo wygenerowany
    wariant nie ma SKU albo EAN (sprawdzone także na ścieżce błędu), i przechodzi na profilu `small`.
16. Pomiar rozmiaru tabel („Skala i dane na serwerze”, p. 3): rozmiar sterty, TOAST i każdego indeksu
    `catalog_product` i `catalog_product_variant` (indeks SKU osobno), przed zmianą i po niej z tego samego ziarna,
    po pełnym przebiegu `small` i po krokach katalogu `medium`; komendy i wyniki są w raporcie zadania, a bajty na
    produkt i na wariant w `docs/features/demo-data/spec.md` jako pomiar, z nazwą profilu i zakresem kroków.
17. Szacunek szczytu dysku (p. 4): `docs/features/demo-data/spec.md` ma rachunek przyrostu szczytu składnik po
    składniku (tabele i indeksy z pomiaru, druga kopia wiersza produktu w kroku zdjęć, WAL), wartości `medium`,
    `server` i `full` oznaczone jako szacunek niezweryfikowany przebiegiem, a `diskPeakGb` w `profiles.yaml` jest
    podniesione zgodnie z rachunkiem. Żadne zdanie nie podaje rozmiaru tabel jako szczytu dysku ani szacunku jako
    pomiaru.
18. Czas generowania i indeks SKU podczas `COPY` (p. 5): czas kroku `demo-data:products` na `medium` zmierzony przed
    zmianą i po niej (ta sama maszyna i liczba procesów, indeks unikalny SKU obecny podczas ładowania) oraz czas
    nowych sprawdzeń `verify` na `small` są w raporcie zadania; `docs/features/demo-data/spec.md` podaje różnicę jako
    pomiar `medium`, a czas `server` i `full` jako szacunek z zastrzeżeniem o wzroście szybszym niż liniowy
    i odniesieniem do limitu Joba.
19. Dokumenty z sekcji „Dokumentacja do zmiany” opisują stan po etapie.

## Testy

- TDD dla domeny i aplikacji: value objecty (granice długości, alfabet SKU, cyfra kontrolna EAN dla 8, 12, 13
  i 14 cyfr, powtórzona nazwa parametru), `Product::edit()` z nowymi polami, handlery zapisu.
- API: test kontraktu tekstów (wiersze i wyjątki), testy funkcjonalne zapisu i odczytu, test repozytorium na indeksie
  SKU, test schematu (`CatalogSchemaTest`); zapis ze zmianą osi i z samą zmianą kodu (kryterium 8); T1 na brzegu
  każdego nowego pola, także `sku` i `ean` (kryterium 9a); powtórzony `PUT` ze starą wersją (kryterium 9f).
- Domena: test tabelaryczny reguły RCN z przykładami §2.1.11, w tym RCN-12 z prefiksem U.P.C. 0 (kryterium 11a).
- Panel: testy jednostkowe (Vitest) formularza; operacje edytora wariantów na wierszach z kodami (kryterium 8c);
  treść wysłanego żądania ze znakami na brzegach bez zmian (kryterium 9a, 9e); tabela normalizacji porównania po
  punktach kodowych, z przypadkami T1, T2, T3 i znaków zachowanych w środku tekstu (kryterium 9c, 9d); nieznany wynik
  zapisu z odpowiedzią odczytu zbudowaną tak, jak zwraca ją API (kryterium 9b, 9g), w istniejących testach ochrony
  formularza produktu.
- E2E zgodności normalizacji (kryterium 9c): w `catalog-admin.spec.ts` zapis produktu z wartościami tabeli w marce,
  opisie, parametrach, SKU i EAN dociera do API, test odrzuca jego odpowiedź w przeglądarce, a „Sprawdź stan”
  pokazuje „Stan na serwerze odpowiada formularzowi.”; osobny przypadek: marka z tabulatorem na końcu daje 422 przy
  polu. To jedyny test, który porównuje kopię reguły w panelu z regułą API, więc obejmuje każdy znak z kryterium 9b.
- Sklep: testy jednostkowe widoku karty i JSON-LD, w tym wariant z `ean` i pustym `gtin` (kryterium 11b).
- E2E karty: produkt utworzony w panelu z nowymi polami i opublikowany pokazuje je na karcie i w JSON-LD; jeden
  wariant ma EAN spoza puli obiegu ograniczonego (jest `gtin`), drugi EAN z prefiksem „2” (bez `gtin`).
- Dane demo: `RealDemoCatalogTest` (kryterium 13), testy generatora i determinizmu (kryterium 14), `verify`
  (kryterium 15). Kryteria 16–18 to pomiary i rachunek w dokumentacji, nie testy automatyczne.

## Ryzyka i ograniczenia

- Etap mierzy rozmiar tabel na `small` i na krokach katalogu `medium` oraz czas kroku produktów na `medium`. Szczyt
  dysku, czas całego generowania i czas budowy indeksu przy 10 mln produktów zostają szacunkiem do przebiegu `server`.
- Żaden produkt danych demo nie ma `gtin` w JSON-LD (D7, D9), więc tej właściwości nie widać na worf.pl; pokrywają ją
  testy z kodem spoza puli obiegu ograniczonego.
- Panel ma kopię reguły tekstu (T1–T3) użytą tylko w porównaniu „Sprawdź stan”. Zmiana reguły w API bez zmiany kopii
  wykrywa test E2E zgodności, a nie test jednostkowy; istniejące pola formularza zostają przy ścisłym porównaniu B4.
- Reguła RCN obejmuje tylko §2.1.11. Inne prefiksy, które nie oznaczają towaru (kupony `05`, `981`–`984`, `99`,
  pokwitowania zwrotu `980`), nie są rozpoznawane: kod z takim prefiksem dostałby `gtin`. Sprzedawca nie ma powodu
  wpisywać ich jako EAN produktu.
- Dla prefiksu U.P.C. 0 reguła bierze całe prefiksy firmowe z §2.1.11.3, szerzej niż 40 500 numerów z rysunku 2-1
  („Pola i reguły”); to świadoma ostrożność, nie odczyt litery rysunku.
- Parametry bez słownika dopuszczają różną pisownię tej samej nazwy w różnych produktach („Waga” i „Masa”); to koszt
  D4 do czasu słownika atrybutów.
- Treść seeda (opisy i parametry 37 typów i ich modeli) pisze agent; sens treści sprawdza przegląd zadania na
  próbce produktów, a nie test.
- Dane producenta wymagane przez GPSR dalej nie istnieją: etap 1 nie przybliża sklepu do zgodności prawnej.
