## Review SEARCH-4: FULL, werdykt CHANGES_REQUIRED

- SHA: `78ddc299117301d42d048ba71a57fa30b047cd2e`. HEAD w checkoutcie zgodny, drzewo czyste. Diff `07524862d7a2..78ddc299`, 6 commitów.
- Tryb: FULL.
- Werdykt: **CHANGES_REQUIRED**. Są dwa ustalenia Important: 500 zamiast 400 dla `option` z niepoprawnym UTF-8 oraz niezatwierdzone odstępstwo od spec w fasetach osi (limit 400 wartości). Poza tym zmiana jest zgodna ze specyfikacją i dobrze pokryta testami.

### Wynik trzech kontroli

1. **Istniejące testy: bez uwag.**
   - `ProductSearchTest::testPageAndPerPageMustBePositive` usunięto, ale sprawdzenie przeszło do konstruktora `ProductSearchCriteria`. Pokrywa je `ProductSearchCriteriaTest::testPageAndPerPageMustBePositive` dla obu pól (0/24 i 1/0). Test nie jest osłabiony.
   - W `ProductsEndpointTest`:
     - lista kluczy odpowiedzi i lista parametrów OpenAPI zostały rozszerzone, nie poluzowane;
     - kontrola „komunikat nie zawiera wartości” dostała warunek `!str_contains($value, '%')`. Pomija on tylko nowe przypadki z kodowaniem procentowym, a żaden przypadek z `base_sha` nie zawiera `%`. Patrz Minor M5.
   - Zmiany testów sklepu (`page.test.tsx`, `page.requests.test.tsx`, `load-catalog.test.ts`, `page-metadata.test.ts`) tylko dodają `facets: EMPTY_FACETS`. Wymaga tego nowe obowiązkowe pole w typie.
   - W diffie nie ma `skip`, `only` ani `markTestSkipped`.
2. **Zakres: bez uwag.** Wszystkie pliki należą do apps/api Catalog: `nelmio_api_doc.yaml` jako źródło schematu `ProductPage`, `openapi.json`, testy i `tests/Support`. Do tego `apps/store/src/api/schema.d.ts`, `apps/api/CLAUDE.md` oraz `apps/store/src/catalog/fixtures.ts` z czterema testami sklepu. Te ostatnie są poza literalnym zakresem („store TS client”), ale ich podstawą jest kryterium 8: bez nich nie przechodzi tsc ani vitest sklepu. Brak zmian w admin, infra, e2e i deptrac.
3. **RED przed implementacją:** `tdd.status` to `lost_verified_on_base`, więc podaję tylko wynik kontroli, bez nowego zgłoszenia.
   - Prawdziwy RED (w drzewie tylko testy) mają:
     - wpis 0: `ListProductsQueryTest|OptionFilterTest`;
     - wpis 3: `ProductSearchCriteriaTest`;
     - wpis 7: `ListProductsHandlerTest` dla faset;
     - wpis 12: `ProductSearchQueryTest`, 42 testy;
     - wpis 15: `ListProductsQueryTest` dla cen jako napisów.
   - Wpis 4 ma etykietę `red`, ale w drzewie są już produkcyjne pliki `Search/*`. `ListProductsHandler.php` jest wtedy jednak niezmieniony, a RED `ProductSearchCriteriaTest` jest wcześniej, we wpisie 3.
   - Potwierdzam opis orkiestratora co do brakującego RED:
     - `ProductsEndpointTest` nie ma RED;
     - `testNumericAxisAndValueNamesWork` (43. test) nie ma żadnego uruchomienia w dzienniku. Zielony wpis 13 ma 42 testy;
     - zmiany produkcyjne obsługi liczbowej nazwy osi weszły w commicie `4e23894` bez żadnego wpisu testu. Dotyczy to `OptionFilter.php`, `ListProducts.php` i `ProductSearchCriteria.php`, których nie ma na liście `dirty` wpisu 13.

   Do ujawnienia w PR, zgodnie z ustaleniem.

### Ustalenia

**Important**

**I1. `option` z niepoprawnym UTF-8 daje 500, a nie 400**
- Miejsce: `apps/api/src/Catalog/UI/Http/OptionFilter.php:86-98`, w połączeniu z `ElasticsearchProductSearch.php:104-106`.
- Przyczyna:
  - `validate()` sprawdza tylko `mb_strlen`, które dla `"\xFF"` zwraca 1, więc wpis przechodzi.
  - Klient ES koduje treść z `JSON_INVALID_UTF8_SUBSTITUTE` (`elastic/transport` `JsonSerializer`), więc zapytanie się udaje.
  - Wybrana wartość wraca w `facets.options` z liczbą 0 (`$counts[$axis][$value] ??= 0`).
  - `new JsonResponse($page)` koduje bez substytucji i rzuca `InvalidArgumentException` („Malformed UTF-8”). Subscriber zamienia to na 500.
- Jak odtworzyć: `GET /api/v1/products?option=Kolor:%FF` albo `?option=%FF:x`. Wniosek z kodu, nie uruchamiałem.
- Dla porównania `q` jest chroniony, bo `Assert\Length` sprawdza charset, a `category` przez `Uuid`.
- Narusza to kryterium 6 („każdy niepoprawny parametr → 400”). To 500 z anonimowego wejścia na publicznym endpoincie.
- Naprawa: `mb_check_encoding($entry, 'UTF-8')` w `OptionFilter::validate` plus przypadek w `OptionFilterTest` i `invalidQueries`.

**I2. Wartości osi bez wyboru są ucinane globalnym limitem 400, czego spec nie przewiduje**
- Miejsce: `ProductSearchQuery.php:20,140-147` i `ElasticsearchProductSearch.php:88-91,113-117`.
- Co mówi spec („Fasety”): osie do 10, wartości do 30 na oś, liczba przy każdej wartości.
- Co robi implementacja:
  - wartości wszystkich osi bez wyboru czyta jeden `terms` (`size: 400`), posortowany globalnie po liczbie produktów;
  - oś z top 10 osi, której wartości mają mniej produktów niż 400 najczęstszych wartości innych osi, traci część wartości;
  - gdy traci wszystkie, znika z faset (`if ([] === $values) continue;`).
- Kiedy to zagraża: przy docelowej skali (`/` bez filtra, wartości osi jako wolny tekst, „czarne”/„czarna” jako osobne wartości, co spec sama opisuje w Ryzykach) to realny scenariusz, a nie przypadek graniczny.
- Developer opisał ograniczenie w `apps/api/CLAUDE.md`, ale `spec.md` go nie ma. To kompromis ograniczający skalowalność i odstępstwo od specyfikacji, więc według CLAUDE.md wymaga decyzji użytkownika.
- Sam kompromis jest rozsądny: indeks nie pozwala w jednym żądaniu wziąć wartości „z prefiksem osi z bucketu rodzica”, bo `options` wariantu zawiera wszystkie osie.
- Do wyboru:
  - (a) decyzja użytkownika i wpis w „Ryzyka i ograniczenia” spec;
  - (b) druga faza (`msearch`/drugie zapytanie) z `include` po prefiksie dla każdej z wybranych 10 osi.

**Minor**

**M1. Opisy OpenAPI liczby przy wartości są nieprecyzyjne dla osi, która już ma wybór**
- Miejsce: `apps/api/config/packages/nelmio_api_doc.yaml` (`ProductFacets.description`, `OptionValue.count`), a w efekcie też `openapi.json` i `schema.d.ts`.
- Opis mówi „the `total` the search would return with this value added”.
- Implementacja dla wybranej osi X liczy produkty z wariantem o wartości v, spełniającym pozostałe warunki bez warunku osi X. Test `testOptionFacetOfAnotherAxisCountsWithTheSelectedOptions` przypina to jako `białe => 2` przy wybranym `czarne`.
- Ocena decyzji developera: semantyka jest zgodna z dokładną definicją w spec („wariant z wartością v spełniający wszystkie pozostałe warunki wariantu”, agregacja nested → filtr pozostałych → terms → reverse_nested) i z kryterium 4 dla osi bez wyboru.
- Dopisek spec „czyli `total` po dodaniu v” jest dosłownie prawdziwy tylko dla osi bez wyboru. Dla osi z wyborem „dodanie” v daje sumę zbiorów S∪{v}.
- `apps/api/CLAUDE.md` opisuje to poprawnie. Warto ujednolicić OpenAPI z CLAUDE.md, a orkiestrator może doprecyzować zdanie w spec.

**M2. Komentarz nie zgadza się z kodem**
- Miejsce: `ElasticsearchProductSearch.php:125-130`.
- Komentarz mówi „in place of the last ones”, a kod dopisuje wartość (`$kept[] = $value`). Lista wybranej osi może więc mieć ponad 30 pozycji.
- Podobnie wybrane osie spoza top 10 są dopisywane, więc osi bywa ponad 10 (`:107-109`).
- Samo zachowanie jest rozsądne (spec: wybrana wartość zawsze w liście), ale komentarz trzeba poprawić, a przekroczenie limitów opisać w OpenAPI („at most 10” i „at most 30”).

**M3. `GROSZE_PATTERN` przepuszcza końcowy znak nowej linii**
- Miejsce: `ListProductsQuery.php:22`.
- `/^\d{1,12}$/` dopasowuje `"10\n"`, bo PCRE `$` pasuje przed końcowym `\n`. W efekcie `priceMin=10%0A` daje 200, a `(int)` zamienia wartość na 10.
- Szkody nie ma, ale to niepoprawny parametr przyjęty bez 400 i dodatkowy wariant klucza cache. Poprawka: `\z` albo modyfikator `D`.

**M4. Nieużywany kod**
- Miejsce: `ProductSearchCriteria.php:60` (`hasVariantCondition`).
- Metoda nie ma żadnego użycia w `src`, sprawdza ją tylko test jednostkowy.

**M5. Kontrola braku echa wartości jest pominięta w nowych przypadkach `option`**
- Miejsce: `ProductsEndpointTest.php:~506`.
- Po dodaniu `!str_contains($value, '%')` żaden nowy przypadek `option` (wszystkie mają `%3A`) nie sprawdza, czy wartość nie wraca w odpowiedzi.
- Ochronę daje `OptionFilterTest::testViolationMessagesNeverContainTheSubmittedValue`. W teście funkcjonalnym wystarczy porównywać z `rawurldecode($value)`.

**M6. Nazwa testu obiecuje więcej, niż sprawdza**
- Miejsce: `ProductsEndpointTest::testViolationsOfTheOptionsAndOfOtherParametersAreReportedOneAtATime`.
- Test sprawdza tylko, że `violations` nie jest puste.
- Zachowanie: naruszenia DTO zatrzymują żądanie, zanim kontroler zwaliduje `OptionFilter`. Naruszenia `option` nie trafiają wtedy do tej samej odpowiedzi. Spec tego nie wymaga, ale test powinien przypiąć to wprost albo zmienić nazwę.

**Suggestion**

- **S1.** `ListProductsHandler::facetCategories` (`ListProductsHandler.php:74-82`) przy każdym żądaniu listy ładuje całe drzewo agregatów (`CategoryRepository::all()`) i buduje je z `Collator`. Przy kilkuset kategoriach i Varnishu 60 s to akceptowalne, a wydzielenie `CategoryTreeBuilder` jest poprawne i zachowuje `GetCategoryTreeHandler` bez zmiany zachowania. Gdy kategorii przybędzie, wystarczy odczyt dzieci jednego węzła.
- **S2.** Dla SEARCH-6: w wygenerowanym kliencie `priceMin` i `priceMax` mają typ `number`. Spec sklepu każe przekazywać niecyfrowe wartości bez zmian, żeby API odpowiedziało 400, więc sklep będzie potrzebował rzutowania. Osierocony schemat `components.ListProductsQuery` (był już w `base_sha`) ma teraz `priceMin: string`, co jest niespójne z parametrami operacji. Zmiana nieszkodliwa.

### Co sprawdziłem bez uwag

- **Zapytanie:** `query` zawiera kategorię jako `term` w `filter` i frazę w `bool.should` z `minimum_should_match: 1`. Gałąź dokładna to `cross_fields` z `and` i wagami `^3`/`^2`/1. Gałąź literówek to `most_fields` na `folded` z `fuzziness: AUTO`, `prefix_length: 1`, `max_expansions: 20` i `boost: 0.3`. Bez frazy działa jak `match_all`. Wszystko zgodnie ze spec.
- **post_filter:** jeden `nested` z warunkami jednego wariantu. Cena i `available` dodają `purchasable`, więc produkt bez wariantu do kupienia odpada. `total` uwzględnia `post_filter`.
- **Agregacje:**
  - `categories`: pełny warunek wariantu i `include` z identyfikatorami dzieci;
  - `available`: warunki osi i ceny plus `purchasable` → `reverse_nested`;
  - `price`: osie plus `purchasable`, bez ceny;
  - osie bez wyboru: pełny warunek i `exclude` wybranych osi;
  - osie z wyborem: warunek bez własnej osi, `top` z `include` po prefiksie i `chosen` z dokładnymi wartościami, więc wybrana wartość spoza 30 ma prawdziwą liczbę;
  - `reverse_nested` liczy produkty, nie warianty.
- **Sortowanie:** `from_gross` z `missing: _last` w obu kierunkach, potem `name.sort` i `id`. Trafność to `_score`, `name.sort`, `id`.
- **Escapowanie regexu prefiksu:** obejmuje wszystkie operatory Lucene RegExp `ALL` (`.?+*|{}[]()"\#@&<>~`). Separator U+001F nie jest znakiem specjalnym. Ścieżka `(`, `)` i `.*` w nazwie osi jest przetestowana (`testOptionWithRegexCharactersIsAPlainValue`).
- **Liczbowe nazwy osi i wartości:** klucze int są obsłużone przez `strval` i `(string)`.
- **Architektura:** Infrastructure → Application, UI → Application, Application → Domain. Port przyjmuje `ProductSearchCriteria`. Brak importów innych contextów. Niezmienniki kryteriów są w konstruktorze, a DTO odrzuca te same przypadki z 400 przed handlerem.
- **Decyzje developera:**
  - `OptionFilter` na surowym `QUERY_STRING` i `option[]` → 400 to ten sam wzorzec co `productId` w ofertach; spec wymaga formatu klienta, więc akceptowalne;
  - ceny jako napisy z `Regex` są uzasadnione, bo denormalizer ucina `10.5`;
  - pojedynczy `terms` dla osi bez wyboru patrz I2;
  - fasety kategorii z drzewa w handlerze są zgodne ze spec.
- **Nagłówki:** `Cache-Control` i `xkey` bez zmian, 503 przy filtrach pokryte testem.
- **Kryteria 1, 2, 3 i 5:** pokryte testami integracyjnymi ES w `ProductSearchQueryTest` i funkcjonalnymi.

### Pliki

- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/UI/Http/OptionFilter.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/UI/Http/ListProductsQuery.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/Infrastructure/Search/ElasticsearchProductSearch.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/Application/Search/ProductSearchCriteria.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/Application/Query/ListProductsHandler.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/config/packages/nelmio_api_doc.yaml`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/tests/Functional/Catalog/ProductsEndpointTest.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/tests/Integration/Catalog/Search/ProductSearchQueryTest.php`
- `<home>/.local/state/ao-search/tasks/SEARCH-4/delivery-state.json.evidence.jsonl`