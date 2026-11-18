# SMALL-FIXES – plan zadań

Specyfikacja: `docs/features/small-fixes/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami (bez
AO). Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje
subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku
i wypycha ją (CI biegnie przy każdym pushu szkicu PR). Zadania nie mają własnych PR. Zadania jednej fali z listą
`parallel_with` mogą iść naraz. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

Epik w toku od 2026-11-18. Gałąź epiku: `feat/small-fixes`, szkic PR do `main`.

| Zadanie | Status |
|---|---|
| SMALL-FIXES-1 | zmergowane do gałęzi epiku (`75ef4781`); przegląd FULL: PASS |
| SMALL-FIXES-2 | |
| SMALL-FIXES-3 | |
| SMALL-FIXES-4 | zmergowane do gałęzi epiku (`4d614748`); przegląd FULL + DELTA: PASS |
| SMALL-FIXES-5 | zmergowane do gałęzi epiku (`9101e482`); przegląd FULL: PASS |
| SMALL-FIXES-6 | |
| SMALL-FIXES-7 | |
| SMALL-FIXES-8 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Praca szeregowa

- SMALL-FIXES-1, SMALL-FIXES-3: `apps/store/app/layout.tsx`; wspólny kod: rewalidacja czasowa stron statycznych (B1)
  i położenie paska demo (B8) mogą wymagać zmiany tego samego layoutu sklepu.
- SMALL-FIXES-7, SMALL-FIXES-8: `apps/api/CLAUDE.md`; oba zmieniają zasadę dla agentów w tym pliku: 7 usuwa wyjątek
  zgodności wersji `ProductCreated` od reguły „zdarzeń domenowych Catalog nikt poza handlerem komendy nie
  subskrybuje”, 8 dodaje zasadę „komenda konsoli nie połyka `\Throwable`”.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "SMALL-FIXES",
  "spec": "docs/features/small-fixes/spec.md",
  "tasks": [
    {
      "id": "SMALL-FIXES-1",
      "title": "Sklep: menu działów na stronach prerenderowanych w obrazie produkcyjnym (B1)",
      "scope": "Sekcja B1 specyfikacji. apps/store/Dockerfile (adres zastępczy API_CATALOG_URL tylko na czas next build, z komentarzem), rewalidacja czasowa najwyżej 1 h stron prerenderowanych w buildzie (/cart, /konto/zamowienia, strona 404; miejsce w apps/store/app wybiera zadanie), sprawdzenie menu w infra/k8s/smoke/checks.py. Bez zmian w odczycie drzewa kategorii, w stronach dynamicznych i w dokumentach wspólnych (opisy w apps/store/CLAUDE.md stają się prawdziwe). Gdy kryterium 1 nie potwierdzi tagu catalog z nieudanego fetch, zadanie zatrzymuje się i wraca do użytkownika.",
      "acceptance_criteria": [
        "Build etapu build obrazu z adresem zastępczym daje w .next/server/app/cart.meta (x-next-cache-tags) tag catalog, a w .next/prerender-manifest.json rewalidację /cart, /konto/zamowienia i /_not-found najwyżej 3600 s; przed zmianą ich nie ma; komenda, wynik i wersja Next z lockfile'a są w raporcie.",
        "Test dymny (make -C infra/k8s smoke) sprawdza po purge-caches w HTML /cart, /konto/zamowienia i strony 404 nieznanego adresu nawigację aria-label=\"Działy\" i link /c/; uruchomiony przed zmianą Dockerfile nie przechodzi (RED w raporcie), po zmianie przechodzi.",
        "docker build --target prod apps/store działa bez stosu, a etap prod nie ma zmiennej API_CATALOG_URL; strony dynamiczne zostają dynamiczne (check-dynamic-routes przechodzi), rewalidacja innych stron się nie skraca.",
        "Komentarz w apps/store/Dockerfile mówi, dlaczego build ma adres zastępczy.",
        "make -C apps/store check, make -C apps/store test i make -C infra/k8s check przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/store/Dockerfile",
        "apps/store/app/cart/",
        "apps/store/app/konto/",
        "apps/store/app/not-found.tsx",
        "apps/store/app/not-found.test.tsx",
        "apps/store/app/layout.tsx",
        "apps/store/app/layout.test.tsx",
        "infra/k8s/smoke/checks.py"
      ],
      "parallel_with": [
        "SMALL-FIXES-2",
        "SMALL-FIXES-4",
        "SMALL-FIXES-5",
        "SMALL-FIXES-6",
        "SMALL-FIXES-7",
        "SMALL-FIXES-8"
      ]
    },
    {
      "id": "SMALL-FIXES-2",
      "title": "Sklep: zmiana ilości w koszyku nie zabiera fokusu (B2, #391)",
      "scope": "Sekcja B2 specyfikacji. apps/store/src/cart (CartPage: pole ilości bez ponownego montowania, kontrolki w trakcie zapisu z aria-disabled, pole readOnly, aktywacja ignorowana), testy CartPage.test.tsx i e2e/tests/cart-guest.spec.ts. Bez ogłaszania sumy i bez fokusu po usunięciu ostatniej pozycji (#392); docs/features/cart/spec.md zostaje prawdziwe bez zmiany.",
      "acceptance_criteria": [
        "CartPage.test.tsx: po Enterze w polu ilości z udaną i odrzuconą odpowiedzią fokus jest w tym samym polu z właściwą wartością; w trakcie zapisu pole i „Usuń” mają aria-disabled=\"true\" (nie disabled), wpisanie albo ArrowUp nie zmienia wartości pola, kliknięcie „Usuń” nie wysyła żądania; testy nie przechodzą przed zmianą (RED w raporcie).",
        "e2e/tests/cart-guest.spec.ts: po ArrowUp, Tab i liczniku 2 przycisk „Usuń” ma fokus; po zmianie ilości Enterem pole ma fokus i nową wartość; test nie przechodzi przed zmianą.",
        "make -C apps/store check, make -C apps/store test i make e2e przechodzą."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "apps/store/src/cart/",
        "e2e/tests/cart-guest.spec.ts"
      ],
      "parallel_with": [
        "SMALL-FIXES-1",
        "SMALL-FIXES-3",
        "SMALL-FIXES-4",
        "SMALL-FIXES-5",
        "SMALL-FIXES-6",
        "SMALL-FIXES-7",
        "SMALL-FIXES-8"
      ]
    },
    {
      "id": "SMALL-FIXES-3",
      "title": "Sklep: pasek demo przypięty do dołu okna, bez przesunięcia strony (B8)",
      "scope": "Sekcja B8 specyfikacji i decyzja D1. apps/store/src/components/SiteSwitches.tsx (DemoNotice poza przepływem, miejsce na końcu strony, scroll-padding-bottom, pod oknami dialogowymi), ewentualnie apps/store/app/layout.tsx i globals.css, nowy test e2e/tests/demo-notice.spec.ts; w docs/features/deploy/spec.md:311 zdanie „nad nagłówkiem” zmienia się na położenie przy dole okna (inaczej przestałoby być prawdą). Odczyt przełączników, treść paska i jego warunek bez zmian; HTML stron nadal bez paska; apps/store/CLAUDE.md bez zmian (opis kodu, nie zasada).",
      "acceptance_criteria": [
        "e2e/tests/demo-notice.spec.ts (desktop i telefon) z przechwyconą, opóźnioną odpowiedzią /api/site z demoNotice: true: położenie nagłówka i pierwszego elementu treści jest takie samo przed i po pojawieniu się paska; po przewinięciu do końca ostatni link stopki nie jest zasłonięty; link stopki z fokusem po Tab nie jest zasłonięty; test nie przechodzi przed zmianą (RED w raporcie).",
        "Testy komponentu paska (z flagą, bez niej, błąd odczytu) przechodzą; sprawdzenia paska w infra/k8s/smoke/checks.py i smoke.sh nie wymagają zmian.",
        "docs/features/deploy/spec.md („Sklep: przełączniki”) mówi o pasku przy dole okna zamiast nad nagłówkiem.",
        "make -C apps/store check, make -C apps/store test i make e2e przechodzą."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "apps/store/src/components/",
        "apps/store/app/layout.tsx",
        "apps/store/app/layout.switches.test.tsx",
        "apps/store/app/globals.css",
        "e2e/tests/demo-notice.spec.ts",
        "docs/features/deploy/spec.md"
      ],
      "parallel_with": [
        "SMALL-FIXES-2",
        "SMALL-FIXES-4",
        "SMALL-FIXES-5",
        "SMALL-FIXES-6",
        "SMALL-FIXES-7",
        "SMALL-FIXES-8"
      ]
    },
    {
      "id": "SMALL-FIXES-4",
      "title": "Panel: wynik uploadu zdjęcia trafia tylko do formularza swojego produktu (B4)",
      "scope": "Sekcja B4 specyfikacji i decyzja D2. apps/admin/src/products (useProductForm, useImageUpload: porzucenie kolejki i wyników przy przejściu do innego produktu albo nowego), apps/admin/src/views/ProductFormView.vue (trwający upload jako niezapisana zmiana dla strażnika), testy w apps/admin/tests. Pasek zapisu bez zmian; przejście na trasę właśnie utworzonego produktu niczego nie porzuca; docs/features/catalog-admin/spec.md zostaje prawdziwe bez zmiany.",
      "acceptance_criteria": [
        "Test useProductForm albo useImageUpload: upload zakończony po open() innego produktu i po open(null) nie dodaje zdjęcia do nowego formularza, a lista wysyłanych plików jest pusta; test nie przechodzi przed zmianą (RED w raporcie).",
        "Test ProductFormView: przejście do innej trasy w trakcie uploadu otwiera pytanie o niezapisane zmiany; „Zostań” zostawia upload, który kończy się w tym samym formularzu; „Opuść bez zapisu” porzuca jego wynik.",
        "Zapis nowego produktu i przejście na jego trasę działa jak dotąd (istniejące testy przechodzą).",
        "make -C apps/admin check i make -C apps/admin test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/admin/src/products/",
        "apps/admin/src/views/ProductFormView.vue",
        "apps/admin/src/composables/useUnsavedChangesGuard.ts",
        "apps/admin/tests/useProductForm.test.ts",
        "apps/admin/tests/useImageUpload.test.ts",
        "apps/admin/tests/ProductFormView.test.ts"
      ],
      "parallel_with": [
        "SMALL-FIXES-1",
        "SMALL-FIXES-2",
        "SMALL-FIXES-3",
        "SMALL-FIXES-5",
        "SMALL-FIXES-6",
        "SMALL-FIXES-7",
        "SMALL-FIXES-8"
      ]
    },
    {
      "id": "SMALL-FIXES-5",
      "title": "Katalog: pageCount w ProductPage i paginacja sklepu w oknie wyników (R17)",
      "scope": "Sekcja „Paginacja w oknie wyników API” i decyzja D3. API Catalog: SearchResult, ElasticsearchProductSearch (okno jako parametr adaptera), ListProductsHandler, ProductPage z polem pageCount, kontroler; eksport apps/api/openapi.json i klient apps/store/src/api/schema.d.ts; sklep: apps/store/src/catalog (CatalogPagination z pageCount, brak paginacji bez pageCount) i fixtury testów stron listingu. Lista panelu (BackofficeProductPage) bez zmian; docs/features/search/spec.md zostaje prawdziwe bez zmiany.",
      "acceptance_criteria": [
        "Testy adaptera wyszukiwarki z małym oknem i handlera: pageCount = ceil(min(total, okno)/perPage) poniżej okna, równo z oknem i ponad nim (także perPage, które nie dzieli okna), 0 bez wyników; ProductsEndpointTest ma pageCount w odpowiedzi; testy nie przechodzą przed zmianą (RED w raporcie).",
        "Test CatalogPagination: total 10 000 000, perPage 24, pageCount 417 daje „Strona 1 z 417”, na stronie 417 bez „Następna”, ze strony 500 „Poprzednia” do 417; odpowiedź bez pageCount nie pokazuje paginacji; sklep nie liczy liczby stron z total.",
        "Stała okna (10 000) nie występuje w warstwie aplikacji Catalog ani w sklepie.",
        "apps/api/openapi.json i apps/store/src/api/schema.d.ts są wygenerowane (make -C apps/api openapi, make api-client) w tej samej zmianie co kod API.",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check i make -C apps/store test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Catalog/Application/Search/SearchResult.php",
        "apps/api/src/Catalog/Application/Port/ProductSearch.php",
        "apps/api/src/Catalog/Application/Query/ProductPage.php",
        "apps/api/src/Catalog/Application/Query/ListProductsHandler.php",
        "apps/api/src/Catalog/Infrastructure/Search/ElasticsearchProductSearch.php",
        "apps/api/src/Catalog/UI/Http/ListProductsController.php",
        "apps/api/config/services.yaml",
        "apps/api/tests/Unit/Catalog/Application/FakeProductSearch.php",
        "apps/api/tests/Unit/Catalog/Application/ListProductsHandlerTest.php",
        "apps/api/tests/Integration/Catalog/Search/",
        "apps/api/tests/Functional/Catalog/ProductsEndpointTest.php",
        "apps/api/tests/Support/SwitchableProductSearch.php",
        "apps/api/openapi.json",
        "apps/store/src/api/schema.d.ts",
        "apps/store/src/catalog/",
        "apps/store/app/page.test.tsx",
        "apps/store/app/page.requests.test.tsx",
        "apps/store/app/szukaj/",
        "apps/store/app/c/"
      ],
      "parallel_with": [
        "SMALL-FIXES-1",
        "SMALL-FIXES-2",
        "SMALL-FIXES-3",
        "SMALL-FIXES-4",
        "SMALL-FIXES-6",
        "SMALL-FIXES-7",
        "SMALL-FIXES-8"
      ]
    },
    {
      "id": "SMALL-FIXES-6",
      "title": "Chart: limit ephemeral-storage kontenera sklepu w prod (R17)",
      "scope": "Sekcja „Limit dysku cache ISR sklepu” i decyzja D4. infra/k8s/environments/prod/values.yaml i jego kopia infra/k8s/environments/load/values.yaml (żądanie 256 MiB, limit 2 GiB), test renderu w infra/k8s/tests, opis w docs/features/product-page/spec.md. Wartości domyślne chartu i profil ci bez zmian; bez cacheHandler i bez zmiany ISR 404.",
      "acceptance_criteria": [
        "Test w infra/k8s/tests renderuje profile prod, load i ci i sprawdza ephemeral-storage kontenera store (żądanie i limit w prod i load, brak w ci); test_load_profile.py przechodzi.",
        "Raport zadania podaje średni rozmiar wpisu ISR strony produktu zmierzony na stosie compose w trybie produkcyjnym i liczbę wpisów mieszczących się w limicie.",
        "docs/features/product-page/spec.md opisuje limit, eksmisję jako skutek i wspólny cache (A6) jako warunek skali docelowej.",
        "make -C infra/k8s check przechodzi (konfiguracja, bez fazy RED)."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "infra/k8s/environments/prod/values.yaml",
        "infra/k8s/environments/load/values.yaml",
        "infra/k8s/tests/",
        "docs/features/product-page/spec.md"
      ],
      "parallel_with": [
        "SMALL-FIXES-1",
        "SMALL-FIXES-2",
        "SMALL-FIXES-3",
        "SMALL-FIXES-4",
        "SMALL-FIXES-5",
        "SMALL-FIXES-7",
        "SMALL-FIXES-8"
      ]
    },
    {
      "id": "SMALL-FIXES-7",
      "title": "API: usunięcie ProductRepository::all(), zgodności ProductCreated i middleware validation",
      "scope": "Sekcja „Usunięcie martwego kodu API”. Catalog: ProductRepository bez all() (Doctrine, in-memory, test repozytorium), IntegrationEvents i IndexProduct bez domenowego ProductCreated; config/packages/messenger.yaml bez middleware validation (komentarze, także CorrelationIdMiddleware.php). apps/api/CLAUDE.md: zmiana zasady, czyli usunięcie wyjątku zgodności wersji od reguły „zdarzeń domenowych Catalog nikt poza handlerem komendy nie subskrybuje”; docs/features/catalog-admin/spec.md: usunięcie zdań o obu ProductCreated, które przestają być prawdą. Routing zdarzeń i allow_no_handlers bez zmian.",
      "acceptance_criteria": [
        "Utworzenie produktu zapisuje w outboxie dokładnie jedno ProductCreated (integracyjne) i żadnego domenowego; test nie przechodzi przed zmianą (RED w raporcie).",
        "Domenowe ProductCreated odebrane z transportu events (ReceivedStamp('events') albo worker na kernelu test_messaging) jest potwierdzone bez wyjątku i bez indeksowania.",
        "ProductRepository i jego implementacje nie mają all(); command.bus ma tylko CorrelationIdMiddleware i doctrine_transaction; komentarze nie wspominają walidacji (usunięcia bez fazy RED).",
        "apps/api/CLAUDE.md i docs/features/catalog-admin/spec.md nie opisują zgodności wersji ProductCreated.",
        "make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Catalog/Domain/Product/ProductRepository.php",
        "apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DoctrineProductRepository.php",
        "apps/api/src/Catalog/Application/Event/",
        "apps/api/src/Catalog/Application/EventHandler/IndexProduct.php",
        "apps/api/src/Shared/Infrastructure/Observability/CorrelationIdMiddleware.php",
        "apps/api/config/packages/messenger.yaml",
        "apps/api/tests/Support/InMemory/InMemoryProductRepository.php",
        "apps/api/tests/Integration/Catalog/Persistence/DoctrineProductRepositoryTest.php",
        "apps/api/tests/Integration/Catalog/Events/",
        "apps/api/tests/Integration/Shared/Messaging/",
        "apps/api/tests/Unit/Catalog/Application/IndexProductTest.php",
        "apps/api/tests/Unit/Catalog/Application/CreateProductHandlerTest.php",
        "apps/api/CLAUDE.md",
        "docs/features/catalog-admin/spec.md"
      ],
      "parallel_with": [
        "SMALL-FIXES-1",
        "SMALL-FIXES-2",
        "SMALL-FIXES-3",
        "SMALL-FIXES-4",
        "SMALL-FIXES-5",
        "SMALL-FIXES-6"
      ]
    },
    {
      "id": "SMALL-FIXES-8",
      "title": "API: komendy konsoli zgłaszają błędy do Sentry, zend.assertions jawnie",
      "scope": "Sekcje „Komendy konsoli” i „zend.assertions ustawione jawnie”, decyzja D5. Komendy cart:purge-expired, ordering:read-models:rebuild, pricing:reconcile-tax i app:backfill przekazują błąd dalej jako wyjątek nieobsłużony z kodem wyjścia z dokumentacji; ich testy ścieżki błędu przez ApplicationTester; test Sentry w tests/Functional/Shared/Sentry; apps/api/docker/php/conf.d/app.ini z zend.assertions = 1 i test pliku. apps/api/CLAUDE.md: nowa zasada „komenda konsoli nie połyka \\Throwable, po komunikacie przekazuje wyjątek dalej z kodem wyjścia komendy”. Inne komendy bez zmian; docs/features/sentry/spec.md zostaje prawdziwe bez zmiany.",
      "acceptance_criteria": [
        "Test z DSN i transportem testowym Sentry: każda z czterech komend przerwana błędem daje dokładnie jedno zdarzenie z wyjątkiem, kod wyjścia jak w apps/api/CLAUDE.md (purge-expired i reconcile-tax 2, rebuild i backfill 1) i komunikat dla operatora w wyjściu; test nie przechodzi przed zmianą (RED w raporcie).",
        "Istniejące testy komend sprawdzają ścieżkę błędu przez ApplicationTester (setAutoExit(false)) z tymi samymi asercjami kodu i komunikatu; zmienia się tylko asercja logu app:backfill.",
        "app.ini ma zend.assertions = 1 z komentarzem; test czyta plik i sprawdza ustawienie (konfiguracja, bez fazy RED); w obrazie prod (docker build --target prod apps/api) php -r 'echo ini_get(\"zend.assertions\");' wypisuje 1.",
        "apps/api/CLAUDE.md ma zasadę: komenda konsoli nie połyka \\Throwable, po komunikacie dla operatora przekazuje wyjątek dalej z kodem wyjścia komendy.",
        "make -C apps/api check i make -C apps/api test przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Cart/UI/Console/PurgeExpiredCartsCommand.php",
        "apps/api/src/Ordering/UI/Console/RebuildReadModelsCommand.php",
        "apps/api/src/Pricing/UI/Console/ReconcileTaxCommand.php",
        "apps/api/src/Shared/UI/Console/",
        "apps/api/docker/php/conf.d/app.ini",
        "apps/api/tests/Integration/Cart/PurgeExpiredCartsTest.php",
        "apps/api/tests/Integration/Ordering/RebuildReadModelsCommandTest.php",
        "apps/api/tests/Unit/Pricing/Console/",
        "apps/api/tests/Integration/Pricing/ReconcileTaxTest.php",
        "apps/api/tests/Integration/Shared/Backfill/",
        "apps/api/tests/Functional/Shared/Sentry/",
        "apps/api/tests/Unit/Shared/Php/",
        "apps/api/CLAUDE.md"
      ],
      "parallel_with": [
        "SMALL-FIXES-1",
        "SMALL-FIXES-2",
        "SMALL-FIXES-3",
        "SMALL-FIXES-4",
        "SMALL-FIXES-5",
        "SMALL-FIXES-6"
      ]
    }
  ]
}
```
