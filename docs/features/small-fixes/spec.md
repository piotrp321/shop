# SMALL-FIXES: drobne poprawki z audytu aplikacji 2026-11-18

Epik poprawek z propozycji SMALL-FIXES audytu `docs/reports/app-audit-2026-11-18.md` (sekcja 7; błędy z sekcji 2.1,
ryzyko R17 z 2.4, pozycje z 2.5 i pozycja 8 z sekcji 4). Każda sekcja to jedna poprawka niezależna od pozostałych.
Działanie funkcji opisują ich specyfikacje; ta opisuje tylko zmiany. Każde twierdzenie o obecnym kodzie dotyczy
`origin/main` z 2026-11-18 (`fc3324a8`).

Issues: #391
Zakres: `apps/store/Dockerfile`, `apps/store/src/cart`, `apps/store/src/components`, `apps/store/src/catalog`, `apps/store/app/layout.tsx`, `apps/store/app/layout.switches.test.tsx`, `apps/store/app/cart`, `apps/store/app/konto`, `apps/store/app/not-found.tsx`, `apps/store/app/page.test.tsx`, `apps/store/app/page.requests.test.tsx`, `apps/store/app/szukaj`, `apps/store/app/c`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/products`, `apps/admin/src/views/ProductFormView.vue`, `apps/admin/tests`, `apps/api/src/Catalog`, `apps/api/src/Cart/UI/Console`, `apps/api/src/Ordering/UI/Console`, `apps/api/src/Pricing/UI/Console`, `apps/api/src/Shared/UI/Console`, `apps/api/src/Shared/Infrastructure/Observability/CorrelationIdMiddleware.php`, `apps/api/config/packages/messenger.yaml`, `apps/api/config/packages/nelmio_api_doc.yaml`, `apps/api/docker/php/conf.d/app.ini`, `apps/api/tests`, `apps/api/openapi.json`, `apps/api/CLAUDE.md`, `infra/k8s/smoke/checks.py`, `infra/k8s/environments/prod/values.yaml`, `infra/k8s/environments/load/values.yaml`, `infra/k8s/tests`, `e2e/tests/cart-guest.spec.ts`, `e2e/tests/demo-notice.spec.ts`, `docs/features/deploy/spec.md`, `docs/features/catalog-admin/spec.md`, `docs/features/product-page/spec.md`, `docs/features/walking-skeleton/spec.md`

## Cel i zakres

- Strony sklepu prerenderowane w buildzie obrazu produkcyjnego mają menu działów i linki stopki po wdrożeniu (B1).
- Zmiana ilości w koszyku nie zabiera fokusu klawiatury (B2, #391).
- Zdjęcie wysyłane dla jednego produktu nigdy nie trafia do formularza innego produktu (B4).
- Pasek sklepu demonstracyjnego nie przesuwa strony, gdy się pojawia (B8).
- Komenda konsoli przerwana błędem daje zdarzenie Sentry (ze śladem stosu) i wpis w logu z wyjątkiem (audyt 2.5, Z8
  raportu 08).
- Paginacja listingu nie prowadzi poza okno wyników API, a cache ISR sklepu ma limit dysku (R17, część).
- Z kodu znika `ProductRepository::all()`, tymczasowa zgodność `ProductCreated` i middleware `validation`.
- `zend.assertions` jest ustawione jawnie we wszystkich obrazach API.

## Decyzje

Specyfikacja przyjmuje rekomendacje poniżej; użytkownik potwierdza je w PR specyfikacji.

| # | Decyzja | Rekomendacja przyjęta w specyfikacji | Odrzucone warianty |
|---|---|---|---|
| D1 | Gdzie stoi pasek demo (B8) | Przypięty do dołu okna (poza przepływem), strona ma pod treścią miejsce na jego wysokość, więc nic nie zostaje nim trwale zasłonięte | Pasek zawsze renderowany z zarezerwowanym miejscem (pusty pas we wdrożeniach bez demo); pasek w HTML z serwera (łamie zasadę „HTML stron jest taki sam w każdym wdrożeniu”, `apps/store/CLAUDE.md:637-640`) |
| D2 | Trwający upload zdjęcia przy przejściu do innego produktu (B4) | Trwający albo oczekujący upload liczy się jak niezapisana zmiana: strażnik pyta „Masz niezapisane zmiany”; po „Opuść bez zapisu” wyniki tych uploadów są porzucane | Ciche porzucenie uploadu bez pytania |
| D3 | Skąd sklep wie, ile stron listingu da się przeczytać (R17) | Nowe pole `pageCount` w odpowiedzi `ProductPage` (publiczne OpenAPI): liczba stron osiągalnych w oknie wyników; `total` zostaje dokładne | Stała okna (10 000) w sklepie (front liczyłby pochodną danych API) |
| D4 | Limit dysku cache ISR (R17) | `ephemeral-storage` kontenera `store` w profilu `prod` (i jego kopii `load`): żądanie 256 MiB, limit 2 GiB; przekroczenie to eksmisja poda sklepu (przerwa na start nowego poda, jedna replika) i nowy pod z pustym cache. To ochrona dysku węzła demo, nie rozwiązanie dla skali docelowej (10 mln produktów): tam potrzebny jest wspólny cache ISR z eksmisją (A6), osobny epik | Brak limitu do czasu wspólnego cache ISR (A6 strony produktu) |
| D5 | Wartość `zend.assertions` | `1` (asercje działają, jak dziś, bo obraz nie ma `php.ini`) w `app.ini`, wspólnym dla wszystkich obrazów | `-1` w obrazie produkcyjnym (wyłącza ok. 200 `\assert()`, które zawężają typy) |

## Poza zakresem

- **B5 (zdublowane indeksy) wypada.** Każdy z pięciu indeksów z mapowaniem `#[ORM\Index]`
  (`ordering_order_line_order_id_idx`, `ordering_order_status_change_order_id_idx`,
  `ordering_order_notification_order_id_idx`, `cart_line_cart_id_idx`, `pricing_promotion_variant_promotion_id_idx`)
  istnieje, bo Doctrine DBAL 4.5 wymaga dla klucza obcego relacji `ManyToOne` indeksu o dokładnie tych kolumnach,
  a indeks wielokolumnowy go nie zastępuje (komentarze przy atrybutach, np.
  `apps/api/src/Ordering/Infrastructure/Persistence/Doctrine/Record/OrderStatusChangeRecord.php:18-20`; to samo
  ustalenie dla `cart_line` w `docs/features/cart-fixes/spec.md`, #85). Szósty, `IDX_8BEBEF264584665A` na
  `catalog_product_category`, generuje sam Doctrine dla tabeli łączącej `ManyToMany`
  (`apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/Record/ProductRecord.php:51`). Usunięcie któregokolwiek
  wymaga zmiany mapowania relacji (model persystencji), więc pasuje do SIMPLIFY-BACKEND, nie do drobnych poprawek.
- B3, B6, B7 (inne epiki: ASYNC-RELIABILITY, ABUSE-LIMITS, BACKUPS-ON).
- Limit czasu wywołań API ze sklepu (pierwsza część R17, R11): OPS-GUARDS.
- Wpisy ISR 404 nieznanych slugów i wspólny cache ISR (A6 strony produktu): tylko limit dysku wchodzi tutaj.
- Ogłaszanie nowej sumy koszyka w `role="status"` i fokus po usunięciu ostatniej pozycji (#392).
- Sprzątanie oryginałów zdjęć w Garage po porzuconym uploadzie (K7 CATALOG-ADMIN: oryginałów się nie usuwa).
- Inne komendy konsoli niż cztery wymienione w sekcji „Komendy konsoli”: pozostałe nie łapią `\Throwable`
  (`SeedDemoCommand` symulatora wycofuje transakcję i rzuca wyjątek dalej).

## B1: menu na stronach prerenderowanych w obrazie produkcyjnym

**Problem.** `loadCategoryTree` (`apps/store/src/catalog/category-tree.ts:13-17`) rzuca wyjątek, zanim wywoła `fetch`,
gdy brak `API_CATALOG_URL`, a obraz `prod` jest budowany bez tej zmiennej (`apps/store/Dockerfile:12,38`). Layout łapie
błąd i renderuje nagłówek bez menu (`category-tree.ts:38-46`), a strona nie dostaje tagu `catalog`, bo tag trafia do
strony tylko z wywołanego `fetch` z `next.tags`. Rewalidacja tagu (`storefront:cache:purge-all`, Job `purge-caches`)
nie ma czego unieważnić, a strony statyczne nie mają rewalidacji czasowej. Na `worf.pl` (rewizja `fe13f4d4`) `/cart`
nie ma `<nav>` ani linków `/c/…` (audyt, B1). `apps/store/CLAUDE.md:54-56` i `:283-285` opisują, że menu wraca przy
pierwszej rewalidacji tagu, co dla obrazu nie jest prawdą (`:283` wymienia też `/checkout`, które jest dynamiczne, bo
czyta ciasteczka: `apps/store/app/checkout/page.tsx:17-18`). Nawet z tagiem HTML z obrazu wraca po każdym restarcie
poda sklepu (eksmisja, OOM, restart węzła), a Job `purge-caches` działa tylko raz na wdrożenie
(`infra/k8s/charts/shop/templates/jobs.yaml:243`). Test dymny sprawdza na `/cart` tylko brak paska demo
(`infra/k8s/smoke/checks.py:313-322`). Lokalnie i w CI błędu nie widać, bo `next build` w compose ma `API_CATALOG_URL`.

**Zachowanie.**
- Build obrazu `prod` ma `API_CATALOG_URL` z adresem zastępczym, który od razu odmawia połączenia (np.
  `http://127.0.0.1:9`), ustawionym tylko na czas `next build` (nie trafia do etapu `prod`). Build dalej nie woła
  żadnej usługi i nie potrzebuje stosu. Otagowany `fetch` drzewa się odbywa i zawodzi po sieci, więc strony
  prerenderowane w buildzie (dziś `/cart`, `/konto/zamowienia` i strona 404) mają tag `catalog` i po pierwszej
  rewalidacji tagu (Job `purge-caches` po każdym wdrożeniu) renderują się z menu i linkami stopki.
- Te same strony mają rewalidację czasową co najwyżej 1 h (jak strona produktu, `apps/store/app/p/[slug]/page.tsx:10`),
  więc po restarcie poda menu wraca najpóźniej po godzinie bez wdrożenia. Miejsce ustawienia wybiera zadanie, pod
  warunkiem że strony dynamiczne zostają dynamiczne (`scripts/check-dynamic-routes.mjs` przechodzi), a rewalidacja
  innych stron się nie skraca.
- Mechanizm opiera się na zachowaniu Next.js: strona dostaje tagi `fetch` także wtedy, gdy samo żądanie się nie
  udało (potwierdzone na buildzie obrazu z Next 16.4.6, kryterium 1; sama rewalidacja czasowa bez adresu zastępczego
  tagu nie daje). Bez tagu menu wracałoby dopiero po rewalidacji czasowej, nie po wdrożeniu.
- Rewalidację czasową ustawiają same strony (`export const revalidate = 3600` w `app/cart/page.tsx`,
  `app/konto/zamowienia/page.tsx` i `app/not-found.tsx`), nie layout: eksport z layoutu skróciłby rewalidację innych
  tras.
- Test dymny klastra po `purge-caches` sprawdza w HTML `/cart`, `/konto/zamowienia` i strony 404 nieznanego adresu
  nawigację `aria-label="Działy"` i co najmniej jeden link `/c/`.
- `apps/store/Dockerfile` ma przy adresie zastępczym komentarz, dlaczego istnieje. Opisy w `apps/store/CLAUDE.md:54-56`
  i `:284-285` (menu po rewalidacji tagu) stają się prawdziwe bez zmiany pliku; ta specyfikacja opisuje mechanizm.

**Kryteria.**
1. Build etapu `build` obrazu (`docker build --target build apps/store`) z adresem zastępczym daje w metadanych
   prerenderu `/cart` (`.next/server/app/cart.meta`, nagłówek `x-next-cache-tags`) tag `catalog`, a w
   `.next/prerender-manifest.json` rewalidację `/cart`, `/konto/zamowienia` i `/_not-found` najwyżej 3600 s; przed
   zmianą tagu i rewalidacji nie ma. Wynik (komenda i wersja Next z lockfile'a) jest w raporcie zadania.
2. Test dymny (`make -C infra/k8s smoke`) z nowym sprawdzeniem, uruchomiony przed zmianą Dockerfile, nie przechodzi na
   menu (faza RED w raporcie zadania); po zmianie przechodzi.
3. `docker build --target prod apps/store` działa bez stosu; etap `prod` nie ma zmiennej `API_CATALOG_URL`.
4. `make -C apps/store check`, `make -C apps/store test`, `make -C infra/k8s check` przechodzą.

## B2: fokus w koszyku po zmianie ilości (#391)

**Problem.** `QuantityField` ma `key` z ilością (`apps/store/src/cart/CartPage.tsx:260`), więc po udanym zapisie React
montuje nowe pole i fokus spada na `<body>`. Na czas zapisu pole i „Usuń” mają `disabled` (`:263`, `:271`), więc
fokus przeniesiony `Tab` na „Usuń” też ginie (#391: po `ArrowUp`, `Tab` fokus jest na `BODY`). `CartPage.test.tsx` nie
ma asercji fokusu, E2E `cart-guest.spec.ts` naciska Enter bez sprawdzenia fokusu.

**Zachowanie.**
- Pole ilości linii to ten sam element przez cały czas życia linii: po udanej zmianie pokazuje nową ilość, po
  odrzuconej (409, błąd, wartość spoza 1–99) poprzednią, bez ponownego montowania.
- W trakcie zapisu pola ilości i przyciski „Usuń” nie są `disabled`: mają `aria-disabled="true"`, pola ilości są
  dodatkowo tylko do odczytu (`readOnly`: zachowują fokus, nie przyjmują wpisywania ani strzałek), a aktywacja
  (zatwierdzenie ilości, kliknięcie, Enter, Spacja) nic nie wysyła. Po zakończeniu zapisu działają jak dotąd.
- Fokus zostaje tam, gdzie postawił go klient: po Enterze w polu ilości w tym polu, po `Tab` na następnej kontrolce
  (np. „Usuń”), także po odrzuconej zmianie.
- Opis strony `/cart` w `docs/features/cart/spec.md` zostaje prawdziwy bez zmiany (nie mówi o stanie kontrolek
  w trakcie zapisu); nowe zachowanie opisuje ta specyfikacja.

**Kryteria.**
1. `CartPage.test.tsx`: po Enterze w polu ilości z udaną i z odrzuconą odpowiedzią `document.activeElement` to to samo
   pole z właściwą wartością; w trakcie zapisu pole i „Usuń” mają `aria-disabled="true"`, nie `disabled`, wpisanie
   albo `ArrowUp` w polu nie zmienia jego wartości, a kliknięcie „Usuń” nie wysyła żądania. Testy nie przechodzą
   przed zmianą.
2. `e2e/tests/cart-guest.spec.ts`: po `ArrowUp`, `Tab` i liczniku 2 przycisk „Usuń” ma fokus (kryterium #391); po
   zmianie ilości Enterem pole ilości ma fokus i nową wartość. Test nie przechodzi przed zmianą.
3. `make -C apps/store check`, `make -C apps/store test` i `make e2e` przechodzą.

## B4: zdjęcie z trwającego uploadu w formularzu innego produktu

**Problem.** Trasy `product` i `product-new` mają ten sam komponent `ProductFormView`
(`apps/admin/src/router/index.ts:44-57`), więc przejście „Dodaj produkt” z karty produktu zostawia tę samą instancję,
a `open()` tylko zeruje formularz (`apps/admin/src/products/useProductForm.ts:272-285`). Uploader powstaje raz na
instancję i dopisuje wynik do bieżącego `form.value.images` (`useProductForm.ts:169-175`,
`apps/admin/src/products/useImageUpload.ts:99-103`), więc zdjęcie produktu A pojawia się w formularzu nowego produktu,
a A go nie dostaje. Strażnik niezapisanych zmian nie pyta, bo w trakcie uploadu `dirty` jest `false`
(`apps/admin/src/views/ProductFormView.vue:138`).

**Zachowanie (D2).**
- Trwający albo oczekujący w kolejce upload zdjęcia jest dla strażnika niezapisaną zmianą: przejście do innej trasy
  panelu (także „Dodaj produkt” i inny produkt) pyta „Masz niezapisane zmiany”, a zamknięcie karty ostrzega jak przy
  niezapisanych zmianach.
- „Zostań” zostawia upload bez zmian. „Opuść bez zapisu” (`apps/admin/src/components/UnsavedChangesDialog.vue:21`)
  porzuca uploady: wynik, który przyjdzie później, nie trafia do żadnego formularza, a lista wysyłanych plików nowego
  produktu jest pusta.
- Przejście formularza do innego produktu albo do pustego nowego (`open()` z innym identyfikatorem albo `null`)
  porzuca kolejkę i wyniki wcześniejszych uploadów, więc wynik uploadu trafia wyłącznie do formularza produktu, dla
  którego go wysłano. Przejście na trasę właśnie utworzonego produktu (`open()` z identyfikatorem pokazanego produktu,
  `useProductForm.ts:274-276`) niczego nie porzuca.
- Pasek zapisu (`docs/features/admin-design/spec.md`, „Standard formularza i okien”) bez zmian: pokazuje się przy
  zmianach danych formularza; trwający upload widać na liście statusów przy zdjęciach, a akcje formularza są w tym
  czasie wyłączone jak dziś.
- Sekcja zdjęć w `docs/features/catalog-admin/spec.md` zostaje prawdziwa bez zmiany; regułę opisuje ta specyfikacja.

**Kryteria.**
1. Test `useProductForm` albo `useImageUpload`: upload zakończony po `open()` innego produktu (i po `open(null)`) nie
   dodaje zdjęcia do nowego formularza, a lista wysyłanych plików jest pusta. Test nie przechodzi przed zmianą.
2. Test `ProductFormView`: przejście do innej trasy w trakcie uploadu otwiera pytanie o niezapisane zmiany; „Zostań”
   zostawia upload, który kończy się w tym samym formularzu.
3. `make -C apps/admin check` i `make -C apps/admin test` przechodzą.

## B8: pasek demo bez przesunięcia strony

**Problem.** `DemoNotice` (`apps/store/src/components/SiteSwitches.tsx:39-49`) pojawia się w przepływie nad nagłówkiem
(`apps/store/app/layout.tsx:31`) dopiero po odczycie `/api/site` w przeglądarce, więc spycha w dół nagłówek i całą
treść, razem ze zdjęciem hero (przesunięcie układu, CLS). Profil `prod` ma `demoNotice: true`
(`infra/k8s/environments/prod/values.yaml:21`); compose go nie ustawia, więc Lighthouse w CI tego nie widzi.

**Zachowanie (D1).**
- Pasek jest przypięty do dołu okna, poza przepływem dokumentu; jego pojawienie się nie zmienia położenia nagłówka ani
  treści. Na końcu strony jest miejsce na wysokość paska, więc po przewinięciu do końca stopka nie jest zasłonięta.
- Gdy pasek jest widoczny, dokument ma `scroll-padding-bottom` równy jego wysokości, więc element z fokusem nie chowa
  się pod paskiem (WCAG 2.4.11, wzór `SaveBar` panelu z `docs/features/admin-design/spec.md`). Pasek leży pod oknami
  dialogowymi sklepu (np. powiększenie galerii, `apps/store/src/components/ui/dialog.tsx:40`).
- Treść paska, rola `note`, warunek (`demoNotice`) i odczyt przełączników w przeglądarce bez zmian; HTML stron dalej
  nie zawiera paska (sprawdzenie w `infra/k8s/smoke/checks.py` i przeglądarkowe w `smoke.sh` przechodzą bez zmian).
- `docs/features/deploy/spec.md` („Sklep: przełączniki”, `:311`) mówi „nad nagłówkiem”, co przestaje być prawdą:
  zadanie poprawia to zdanie na położenie przy dole okna. `apps/store/CLAUDE.md:641` ma ten sam opis; to opis kodu,
  nie zasada, więc zadanie go nie zmienia (porządki opisów w plikach `CLAUDE.md`: #737).

**Kryteria.**
1. Test E2E `e2e/tests/demo-notice.spec.ts` (Chromium, desktop i telefon) z przechwyconą, opóźnioną odpowiedzią
   `/api/site` z `demoNotice: true`: położenie nagłówka i pierwszego elementu treści jest takie samo przed i po
   pojawieniu się paska; po przewinięciu do końca strony ostatni link stopki nie jest zasłonięty paskiem; link stopki,
   do którego doszedł `Tab`, nie jest zasłonięty paskiem. Test nie przechodzi przed zmianą.
2. Testy komponentu paska (z flagą, bez niej, błąd odczytu) przechodzą.
3. `make -C apps/store check`, `make -C apps/store test` i `make e2e` przechodzą.

## Komendy konsoli: błąd ze śladem stosu i zdarzeniem Sentry

**Problem.** `cart:purge-expired` (`apps/api/src/Cart/UI/Console/PurgeExpiredCartsCommand.php:53-57`; w klastrze
CronJob), `ordering:read-models:rebuild` (`apps/api/src/Ordering/UI/Console/RebuildReadModelsCommand.php:34-38`)
i `pricing:reconcile-tax` (`apps/api/src/Pricing/UI/Console/ReconcileTaxCommand.php:51-55`) łapią `\Throwable`,
wypisują jedną linię i kończą się kodem błędu, bez logu wyjątku. `app:backfill`
(`apps/api/src/Shared/UI/Console/BackfillCommand.php:64-68`) loguje wyjątek na `error`, ale handler Sentry pomija
rekordy z `context.exception` (`apps/api/config/services.yaml:230-237`, P6 i D5 `docs/features/sentry/spec.md`),
więc żadna z czterech nie daje zdarzenia Sentry. Listener konsoli Sentry zgłasza tylko wyjątki nieobsłużone
(`services.yaml:238-245`).

**Zachowanie.**
- Każda z czterech komend po swoim komunikacie dla operatora (bez zmian treści) przekazuje błąd dalej do konsoli
  Symfony jako wyjątek nieobsłużony, z pierwotnym wyjątkiem jako przyczyną (`previous`). Zgłasza go jeden kanał
  (listener konsoli Sentry, D5: jedno zdarzenie na błąd, ze śladem stosu), a log konsoli (`ErrorListener`, kanał
  `console`) zapisuje go z klasą i komunikatem wyjątku na poziomie co najmniej `error`. Stos jest w zdarzeniu Sentry;
  formatter JSON logów produkcyjnych go nie zapisuje (bez zmian), więc bez `SENTRY_DSN` zostaje sam wpis w logu.
- Kody wyjścia zostają jak w `apps/api/CLAUDE.md`: `cart:purge-expired` i `pricing:reconcile-tax` 2,
  `ordering:read-models:rebuild` i `app:backfill` 1. Konsola Symfony bierze kod wyjścia nieobsłużonego wyjątku
  z jego `getCode()` (0 daje 1), więc wyjątek przekazywany dalej ma kod z tej listy. Pozostałe kody (błędne
  argumenty, rozbieżności) bez zmian.
- `app:backfill` nie loguje już wyjątku sam (jeden wpis na błąd).
- Nowa zasada dla agentów w `apps/api/CLAUDE.md`: komenda konsoli nie połyka `\Throwable`; po komunikacie dla
  operatora przekazuje wyjątek dalej z kodem wyjścia z dokumentacji komendy. `docs/features/sentry/spec.md` („nieobsłużone
  wyjątki komend konsoli”) zostaje prawdziwe bez zmiany.

**Kryteria.**
1. Test z DSN i transportem testowym Sentry (wzór: `tests/Functional/Shared/Sentry`): każda z czterech komend
   przerwana błędem daje dokładnie jedno zdarzenie z wyjątkiem i kod wyjścia jak wyżej, a wyjście zawiera komunikat
   dla operatora. Test nie przechodzi przed zmianą.
2. Istniejące testy kodów wyjścia i komunikatów (`PurgeExpiredCartsTest`, `RebuildReadModelsCommandTest`,
   `ReconcileTaxCommandTest`, `BackfillCommandTest`) sprawdzają ścieżkę błędu przez `ApplicationTester`
   (`setAutoExit(false)`), bo `CommandTester` nie łapie wyjątków, z tymi samymi asercjami kodu wyjścia i komunikatu;
   poza tym zmienia się tylko asercja logu `app:backfill` (log zapisuje konsola, nie komenda).
3. `make -C apps/api check` i `make -C apps/api test` przechodzą.

## Paginacja w oknie wyników API

**Problem.** Sklep liczy liczbę stron z `total` (`apps/store/src/catalog/Pagination.tsx:8,22`), a API czyta tylko
pierwsze 10 000 wyników (`index.max_result_window`; strona za oknem jest pusta z prawdziwym `total`:
`apps/api/src/Catalog/Infrastructure/Search/ElasticsearchProductSearch.php:28-31`, `docs/features/search/spec.md:203`).
Przy dużym katalogu sklep pokazuje „Strona 1 z 416667” i aktywne „Następna” na pustych stronach za oknem.

**Zachowanie (D3).**
- `ProductPage` (`GET /api/v1/products`, także z `q`) ma pole `pageCount`: liczba stron osiągalnych dla filtra
  i `perPage`, czyli `ceil(min(total, okno) / perPage)`; 0 przy braku wyników. `total` zostaje dokładne. Okno zna
  tylko adapter wyszukiwarki (parametr `maxResultWindow`); warstwa aplikacji i sklep nie mają stałej 10 000.
- Sklep pokazuje „Strona X z `pageCount`” i link „Następna” tylko na stronie mniejszej niż `pageCount`; „Poprzednia”
  ze strony za końcem prowadzi do strony `pageCount`; licznik produktów w nagłówku listingu dalej pokazuje `total`.
  Sklep nie wylicza liczby stron z `total`.
- Strona za oknem zostaje jak dziś: „Nie znaleźliśmy produktów.” z licznikiem `total` w nagłówku i linkiem
  „Poprzednia” do strony `pageCount`.
- Zgodność wersji: w trakcie wdrożenia nowy sklep może dostać z Varnisha odpowiedź starszego API bez `pageCount`
  (`api` i `store` startują w tej samej fali, a cache czyści dopiero `purge-caches`). Odpowiedź bez liczbowego
  `pageCount` daje listing bez paginacji, nigdy „Strona 1 z undefined”.
- `make -C apps/api openapi` i `make api-client` aktualizują `apps/api/openapi.json` i `apps/store/src/api/schema.d.ts`.
- Zdanie o oknie w `docs/features/search/spec.md:203` zostaje prawdziwe; `pageCount` i paginację w oknie opisuje ta
  specyfikacja.

**Kryteria.**
1. Test adaptera wyszukiwarki z małym oknem (np. 5) i testy handlera: `pageCount` dla wyników poniżej okna, równych
   oknu i ponad nim (także `perPage`, które nie dzieli okna), 0 bez wyników; test funkcjonalny `ProductsEndpointTest`
   ma `pageCount` w odpowiedzi. Testy nie przechodzą przed zmianą.
2. Test `Pagination` w sklepie: `total` 10 000 000, `perPage` 24, `pageCount` 417 daje „Strona 1 z 417”, na stronie
   417 bez „Następna”, ze strony 500 „Poprzednia” prowadzi do 417; odpowiedź bez `pageCount` nie pokazuje paginacji.
3. Wyeksportowana specyfikacja i klient są w tej samej zmianie co kod API; `make -C apps/api check`, `make -C apps/api test`,
   `make -C apps/store check` i `make -C apps/store test` przechodzą.

## Limit dysku cache ISR sklepu

**Problem.** Strona produktu jest w ISR (`apps/store/app/p/[slug]/page.tsx:10`), cache plikowy rośnie z każdym
odwiedzonym produktem i nieznanym slugiem, a kontener `store` nie ma limitu `ephemeral-storage`
(`infra/k8s/charts/shop/values.yaml:194`, `infra/k8s/environments/prod/values.yaml:103`). Ruch po losowych adresach
`/p/…` zapełnia dysk jedynego węzła, na którym stoją też baza i pozostałe usługi.

**Zachowanie (D4).**
- Kontener `store` w profilu `prod` (i w jego kopii w `load`, której zgodność z `prod` pilnuje
  `tests/test_load_profile.py`) ma `ephemeral-storage`: żądanie 256 MiB, limit 2 GiB. Wartości domyślne chartu i profil
  `ci` bez `ephemeral-storage` (helm scala mapy, więc wpis w wartościach domyślnych trafiłby też do `ci`).
- Przekroczenie limitu to eksmisja poda sklepu przez kubelet (warstwa zapisywalna kontenera, w której leży
  `.next/cache`, liczy się do `ephemeral-storage`) i nowy pod z pustym cache: strony ISR renderują się od nowa przy
  pierwszym wejściu, strony prerenderowane w buildzie wracają do HTML z obrazu do rewalidacji (sekcja B1). Dysk węzła
  i pozostałe usługi nie są dotknięte. Restart poda łapie istniejąca reguła `WorkloadNotReady`; osobnego alertu
  eksmisji ten epik nie dodaje.
- `docs/features/product-page/spec.md:446-447` („nic ich nie usuwa (nieograniczony przyrost…)”) przestaje być
  prawdą: zadanie poprawia to ryzyko na limit z eksmisją poda i wspólny cache (A6) jako warunek skali docelowej.

**Kryteria.**
1. Test w `infra/k8s/tests` renderuje chart z profilami `prod`, `load` i `ci` i sprawdza `ephemeral-storage` kontenera
   `store` (żądanie i limit w `prod` i `load`, brak w `ci`); `test_load_profile.py` przechodzi.
2. Raport zadania podaje średni rozmiar wpisu ISR strony produktu zmierzony na stosie compose w trybie produkcyjnym
   (rozmiar katalogu cache po N wejściach na różne produkty) i wynikającą z niego liczbę wpisów mieszczących się
   w limicie.
3. `make -C infra/k8s check` przechodzi (bez fazy RED: konfiguracja).

## Usunięcie martwego kodu API

**Problem.**
- `ProductRepository::all()` (`apps/api/src/Catalog/Domain/Product/ProductRepository.php:36`, implementacje
  `DoctrineProductRepository.php:78` i `tests/Support/InMemory/InMemoryProductRepository.php:66`) ładuje cały katalog
  przez ORM i nie ma użytkownika poza `tests/Integration/Catalog/Persistence/DoctrineProductRepositoryTest.php`.
- `IntegrationEvents::translate` publikuje domenowe `ProductCreated` obok integracyjnego „dla poprzedniej wersji”
  (`apps/api/src/Catalog/Application/Event/IntegrationEvents.php:15-17,30-32`), a `IndexProduct` obsługuje oba
  (`apps/api/src/Catalog/Application/EventHandler/IndexProduct.php:38`), więc każdy nowy produkt jest indeksowany dwa
  razy. Wersja ze zgodnością (#623) jest już wdrożona (gałąź `deploy`: `fe13f4d4`), więc okno zgodności minęło.
- Middleware `validation` na `command.bus` (`apps/api/config/packages/messenger.yaml:12`) nie ma czego sprawdzać:
  żadna klasa poza `UI` nie ma ograniczeń walidatora.

**Zachowanie.**
- `ProductRepository` nie ma `all()`; testy repozytorium czytają produkty przez `get()` albo SQL.
- `CreateProduct` publikuje jedno `ProductCreated` (integracyjne `Catalog\Application\Event\ProductCreated`);
  `IndexProduct` obsługuje tylko integracyjne `ProductCreated` i `ProductChanged`. Domenowe `ProductCreated` z outboxa
  albo kolejki `events` wysłane przez poprzednią wersję jest potwierdzane bez handlera i bez błędu
  (`allow_no_handlers` na `event.bus`, `messenger.yaml:20-23`), a produkt indeksuje równoległe zdarzenie integracyjne.
- `command.bus` ma middleware `CorrelationIdMiddleware` i `doctrine_transaction`; komentarze w `messenger.yaml`
  i `CorrelationIdMiddleware.php:17` nie wspominają walidacji.
- `apps/api/CLAUDE.md` traci wyjątek od zasady „zdarzeń domenowych Catalog nikt poza handlerem komendy nie
  subskrybuje” (zmiana zasady: `:634-638`, wyjątek zgodności wersji `ProductCreated`), a
  `docs/features/catalog-admin/spec.md` (`:81-83`, `:160-161`) traci zdania o publikacji i obsłudze obu
  `ProductCreated`, które przestają być prawdą.

**Kryteria.**
1. Test: utworzenie produktu zapisuje w outboxie dokładnie jedno `ProductCreated` (integracyjne) i żadnego
   domenowego; test nie przechodzi przed zmianą.
2. Test: domenowe `ProductCreated` odebrane z transportu `events` (koperta z `ReceivedStamp('events')` albo worker na
   kernelu `test_messaging`; zwykła wysyłka na `event.bus` trafia przez routing do `outbox` i niczego nie dowodzi) jest
   potwierdzone bez wyjątku i bez wywołania indeksowania.
3. `make -C apps/api check` i `make -C apps/api test` przechodzą (usunięcie `all()` i middleware bez fazy RED:
   kod bez użytkowników).

## `zend.assertions` ustawione jawnie

**Problem.** Obraz API nie ma `php.ini` (`apps/api/docker/php/conf.d/app.ini:11`), więc działa wartość domyślna PHP
(`zend.assertions = 1`), a kod ma ok. 200 wywołań `\assert()`. Standardowe utwardzenie obrazu
(`php.ini-production`, `-1`) wyłączyłoby je bez śladu w repo; ani `app.ini`, ani `prod.ini` tego nie ustawia.

**Zachowanie (D5).** `app.ini` (wspólny dla obrazów `dev` i `prod`) ma `zend.assertions = 1` z komentarzem, dlaczego.
Plik ładuje się po `php.ini`, więc ustawienie wygrywa także z `php.ini-production`.

**Kryteria.**
1. Test w `apps/api/tests` czyta `docker/php/conf.d/app.ini` i sprawdza `zend.assertions = 1` (bez fazy RED:
   konfiguracja).
2. W obrazie `prod` (`docker build --target prod apps/api`) `php -r 'echo ini_get("zend.assertions");'` wypisuje `1`.
3. `make -C apps/api check` i `make -C apps/api test` przechodzą.

## Kryteria akceptacji epiku

1. Kryteria każdej sekcji są spełnione, a raporty zadań z TDD mają komendy i wyniki fazy RED i GREEN.
2. Na buildzie produkcyjnym przechodzą `make check`, `make test` i `make e2e` (`STORE_MODE=production`); test dymny
   klastra przechodzi z nowym sprawdzeniem menu.
3. Po wdrożeniu `/cart` na `worf.pl` ma menu działów (sprawdza użytkownik po wdrożeniu, które uruchamia sam).

## Ryzyka i ograniczenia

- B1 opiera się na zachowaniu Next.js 16 (tag z nieudanego `fetch`), potwierdzonym na Next 16.4.6; po aktualizacji
  Next.js pilnuje go test dymny klastra (menu na `/cart`, `/konto/zamowienia` i stronie 404 po `purge-caches`).
- Po restarcie poda sklepu strony prerenderowane w buildzie mogą być bez menu do godziny (rewalidacja czasowa).
- Pasek demo przypięty do dołu zajmuje na telefonie 2–3 wiersze ekranu przez cały czas oglądania sklepu demo.
- Eksmisja poda sklepu po przekroczeniu limitu dysku to przerwa sklepu na czas startu nowego poda (jedna replika);
  przy dużym katalogu i ruchu robotów może się powtarzać. Limit chroni węzeł demo, skali docelowej nie rozwiązuje.
  Pomiar na stosie compose w trybie produkcyjnym (70 różnych stron produktów): wpis ISR strony produktu to 7 plików,
  średnio ok. 140 KiB, więc w limicie 2 GiB mieści się ok. 15 tys. wpisów (mniej o logi i inne zapisy poda; wpisy 404
  nieznanych slugów nie były mierzone).
- `pageCount` to nowe pole wymagane w publicznym kontrakcie; jedynym klientem jest sklep, generowany w tej samej zmianie,
  a okno wdrożenia obsługuje reguła zgodności z sekcji paginacji.
- Porzucony upload zostawia oryginał w Garage (jak dziś upload bez zapisu produktu, K7).
