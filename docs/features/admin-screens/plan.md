# ADMIN-SCREENS – plan zadań

Specyfikacja: `docs/features/admin-screens/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ADMIN-SCREENS-1 | |
| 2 | ADMIN-SCREENS-2, ADMIN-SCREENS-4, ADMIN-SCREENS-6 | |
| 3 | ADMIN-SCREENS-3, ADMIN-SCREENS-5 | |
| 4 | ADMIN-SCREENS-7 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ADMIN-SCREENS",
  "spec": "docs/features/admin-screens/spec.md",
  "tasks": [
    {
      "id": "ADMIN-SCREENS-1",
      "title": "Toasty bez zasłaniania paska zapisu, wspólne wyszukiwanie po nazwie i wspólne helpery E2E stanów panelu",
      "scope": "apps/admin: sekcja spec „Toasty” (#507, #508: położenie toastów pod topbarem w prawym górnym rogu, poniżej 640 px na szerokość ekranu z marginesem 16 px, nazwa regionu dokładnie „Powiadomienia”; `AppLayout.vue`, `src/components/ui/sonner/`), `src/composables/useNameSearch.ts` z „Dopowiedzeń standardów” (D16) i przejście `useProductList` na niego bez zmiany zachowania. E2E: wspólne helpery stanów panelu z `e2e/tests/admin-design.spec.ts` (logowanie ról, `newPanelPage`, `go`, `inState`, `violationsOf`, typ stanu) przeniesione do nowego `e2e/support/panel-states.ts`, żeby testy ekranów w kolejnych zadaniach miały własne pliki; testy #507 i #508. Dokumentacja: `docs/features/admin-design/spec.md` (toasty w „Powłoce”, wyszukiwanie w „Standardzie listy”), `apps/admin/CLAUDE.md` (toasty, `useNameSearch`). Bez zmian ekranów cen, stanów, promocji, kodów i zamówień.",
      "acceptance_criteria": [
        "Region toastów ma nazwę dostępną dokładnie „Powiadomienia”: E2E `getByRole(\"region\", { name: \"Powiadomienia\", exact: true })` ma 1 element; test nazwany `#508`.",
        "Przy 1280 × 720, 375 × 812 i 320 × 640 px toast po zapisie formularza produktu nie przecina prostokąta żadnego przycisku paska zapisu ani topbaru, a „Zapisz” da się kliknąć w 3 s, gdy toast jest widoczny (E2E nazwany `#507`; formularz kodu dochodzi w ADMIN-SCREENS-4).",
        "`tests/useNameSearch.test.ts` sprawdza 1 znak, 2 znaki, 100 i 101 znaków (punkty kodowe), same spacje, opóźnienie 300 ms, `reset` i odmontowanie przed upływem opóźnienia; wartość z adresu ustawia pole bez ponownego `apply`.",
        "`useProductList` używa `useNameSearch`, a istniejące testy wyszukiwania listy produktów (`useProductList`, `ProductsView`, `catalog-admin.spec.ts`) przechodzą bez zmian asercji.",
        "`e2e/tests/admin-design.spec.ts` korzysta z `e2e/support/panel-states.ts` i przechodzi bez zmian asercji.",
        "Przechodzą `make check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "ADMIN-SCREENS-2",
      "title": "Ceny w standardzie listy i okien, wiersze grup produktu w DataTable",
      "scope": "apps/admin: sekcje spec „Ceny i Stany magazynowe” (część Ceny: lista, okna „Zmień cenę” i „Historia ceny”) i „Dopowiedzenia standardów” (lista pogrupowana po produkcie: wiersz grupy `product-row` w `DataTable` z `<th scope=\"rowgroup\">`; nadpisanie po zapisie jako nakładka nad pagerem; czyste funkcje `fetchPricePage` i `priceRows` w `src/prices/`; `usePriceList` na `useListUrlState`, `useCursorPager` i `useNameSearch`; klasy podatkowe przez funkcję wyniesioną z `useProductForm` do wspólnego modułu; okna z polami na `Dialog`). `PricesView.vue` bez PrimeVue i poza `LEGACY_FILES`. `PromotionVariantPicker.vue` dostaje nowy `<script>` na `fetchPricePage`, `priceRows`, `useCursorPager` i `useNameSearch` (zapytanie w pamięci, 25 produktów) z zachowaniem dzisiejszego szablonu i działania; jego szablon migruje ADMIN-SCREENS-5. Testowe identyfikatory z sekcji „Testowe identyfikatory” (`product-row`). E2E: nowy `e2e/tests/admin-screens-prices.spec.ts` (axe, reflow, 375 px, testy zgłoszeń), `e2e/support/price-change.ts` i `e2e/tests/price-change.spec.ts` („Zapisz cenę”, wiersz wariantu pod `product-row`). Dokumentacja: `apps/admin/CLAUDE.md` (Ceny), `docs/features/pricing/spec.md` (zdania o ekranie panelu). Zamyka #135, #272, #273, #393 i część Ceny #343.",
      "acceptance_criteria": [
        "Axe (WCAG 2.0–2.2 A i AA) przy 1280 i 768 px nie zgłasza naruszeń na `/prices`, z otwartym oknem „Zmień cenę” i z oknem historii; brak `color-contrast` w oknie zmiany ceny (`#135`) i brak `aria-command-name` oraz nienazwanych przycisków na `/prices` (`#343`).",
        "Reflow przy 320 × 640 px na `/prices` i obu oknach: `scrollWidth` równa się `clientWidth`, każda kontrolka osiągalna Tabem i w całości widoczna po przewinięciu; przy 375 px okno „Zmień cenę” ma `x ≥ 0` i `x + width ≤ 375` (`#393`).",
        "500 przy wyszukiwaniu „hantle” daje komunikat „Nie udało się pobrać cen” i 0 `price-row` (`#272`); okno „Zmień cenę” otwarte Enterem ma fokus w `gross-input` (`#273`).",
        "`/prices?q=hantle&perPage=25` pokazuje wypełnione filtry i wyniki; zmiana filtra zmienia adres bez nowego wpisu historii; „Wyczyść filtry” wraca do `/prices`; druga strona przy 10 produktach mówi „Pokazano produkty 11–20” niezależnie od liczby wariantów, a zmiana rozmiaru wraca na pierwszą stronę.",
        "Wiersz produktu ma polską nazwę klasy podatkowej, a przy błędzie `GET /tax-classes` kod; przy 768 px akcje pierwszego wiersza wariantu są widoczne bez przewijania w poziomie, a Netto jest linią komórki Wariant przy 768 i 320 px.",
        "Zapis ceny: toast „Cena zapisana: <produkt> <wariant>”, fokus na „Zmień cenę” wiersza, cena z API w wierszu także w trakcie odświeżenia (nakładka); 409 `price-locked-by-promotion` przy polu, inny 409 odświeża stronę z komunikatem `change-conflict` (testy komponentów i composable).",
        "Wybór wariantów promocji działa jak dziś na nowych funkcjach (istniejące testy `PromotionFormView` i `promotions.spec.ts` przechodzą); `PricesView.vue` nie importuje PrimeVue i nie ma go na `LEGACY_FILES`.",
        "Przechodzą `make check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-SCREENS-1"]
    },
    {
      "id": "ADMIN-SCREENS-3",
      "title": "Stany magazynowe w standardzie listy i okien",
      "scope": "apps/admin: sekcja spec „Ceny i Stany magazynowe” (część Stany: lista, okna przyjęcia, korekty i „Historia stanu”) na wierszach grup z ADMIN-SCREENS-2; `useStockList` na `useListUrlState`, `useCursorPager` i `useNameSearch` z nakładką po zapisie; `InventoryView.vue` bez PrimeVue i poza `LEGACY_FILES` (`InputNumber` → `Input`, powód korekty jako `Select`). E2E: nowy `e2e/tests/admin-screens-inventory.spec.ts`, `e2e/tests/stock-receipt.spec.ts` i `e2e/support/catalog-admin.ts` (wiersz wariantu pod `product-row`), `e2e/tests/catalog-admin.spec.ts` przechodzi. Dokumentacja: `apps/admin/CLAUDE.md` (Stany), `docs/features/inventory/spec.md` (zdania o ekranie panelu). Zamyka część Stany #343.",
      "acceptance_criteria": [
        "Axe przy 1280 i 768 px nie zgłasza naruszeń na `/inventory` z oknami przyjęcia, korekty i historii; brak `aria-command-name` i nienazwanych przycisków na `/inventory` (`#343`).",
        "Reflow przy 320 × 640 px na `/inventory` i trzech oknach przechodzi; przy 375 px każde okno mieści się w szerokości ekranu; historia stanu jest przewijanym regionem dostępnym z klawiatury.",
        "`/inventory?category=<id>` pokazuje wybraną kategorię i wyniki; stan w adresie i stronicowanie produktów jak na Cenach („Pokazano produkty X–Y”, domyślnie 10).",
        "Przy 768 px akcje i poziom pierwszego wiersza wariantu są widoczne bez przewijania w poziomie, a Zarezerwowane jest linią komórki Wariant przy 768 i 320 px; wariant bez rekordu stanu pokazuje „—”.",
        "Przyjęcie i korekta: fokus w pierwszym polu, klucz idempotencji z otwarcia okna, toast „Przyjęto N szt.: …” albo „Stan skorygowany: …”, fokus na wyzwalaczu po zapisie, 409 odświeża bieżącą stronę z komunikatem, 422 przy polach (testy komponentów); `stock-receipt.spec.ts` i `catalog-admin.spec.ts` przechodzą.",
        "`InventoryView.vue` nie importuje PrimeVue i nie ma go na `LEGACY_FILES`; testy `useStockList` i `useStockHistory` przechodzą bez zmian asercji poza stronicowaniem i stanem w adresie.",
        "Przechodzą `make check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-SCREENS-2"]
    },
    {
      "id": "ADMIN-SCREENS-4",
      "title": "Kody rabatowe: lista i formularz w standardach",
      "scope": "apps/admin: sekcja spec „Promocje i kody rabatowe” (lista kodów, wyszukiwanie z przycinaniem znaków niewidocznych, formularz kodu z kartami, `RadioGroup` rodzaju rabatu, przełącznik „Aktywny” jako pole formularza, pasek zapisu, strażnik zmian, toast, 404 z `Alert`); `RadioGroup` shadcn-vue skopiowany do `src/components/ui/`; `useDiscountCodeList` na `useListUrlState` i `useCursorPager` bez obserwatora `route.query` i `statusOf` (most usuwa z menu ADMIN-SCREENS-7); `DiscountCodesView.vue` i `DiscountCodeFormView.vue` bez PrimeVue i poza `LEGACY_FILES`. E2E: nowy `e2e/tests/admin-screens-discount-codes.spec.ts`, test formularza kodu w `e2e/tests/admin-design.spec.ts` (bez `success-message`: toast i fokus według standardu), test `#507` na formularzu kodu. Dokumentacja: `apps/admin/CLAUDE.md` (kody), `docs/features/discount-codes/spec.md` (zdania o ekranie panelu). Zamyka #336, #337, część kodów #446.",
      "acceptance_criteria": [
        "Axe przy 1280 i 768 px nie zgłasza naruszeń na `/discount-codes`, `/discount-codes/new` z oknem niezapisanych zmian i `/discount-codes/<nieistniejący>`; brak `color-contrast` w komunikacie 404 (`#446`).",
        "Reflow przy 320 × 640 px na tych ekranach przechodzi (także pasek zapisu); przy 768 px akcja i status pierwszego wiersza są widoczne bez przewijania w poziomie.",
        "Test Vitest nazwany `#336`: U+200B, U+00AD, U+2060 i „spacja U+200B spacja” dają puste wyszukiwanie bez żądania z `q`; test `DiscountCodesView` nazwany `#337` (`attachTo: document.body`): 500, ponowienie z 403, fokus nie na `body`.",
        "`/discount-codes?status=active&q=LATO` pokazuje przefiltrowaną listę z wypełnionymi filtrami; zmiana filtra zmienia adres bez nowego wpisu historii; „Wyczyść filtry” wraca do `/discount-codes`; puste stany według standardu.",
        "Formularz kodu: pasek zapisu (także po zmianie „Aktywny”), strażnik zmian („Zostań” zostawia formularz), toast „Kod rabatowy zapisany.” i fokus na H1 po zapisie, fokus na pierwszym polu z błędem po nieudanym zapisie, `discount-code-taken` przy polu kodu, `termsLocked` i `limitLocked` jak dziś, tryb tylko do odczytu bez paska (testy komponentów).",
        "Test `#507` przechodzi także na formularzu kodu przy 1280 × 720, 375 × 812 i 320 × 640 px; `DiscountCodesView.vue` i `DiscountCodeFormView.vue` nie importują PrimeVue i nie ma ich na `LEGACY_FILES`.",
        "Przechodzą `make check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-SCREENS-1"]
    },
    {
      "id": "ADMIN-SCREENS-5",
      "title": "Promocje: lista, formularz, wybór wariantów i ponowny odczyt po „Zakończ teraz”",
      "scope": "apps/admin: sekcja spec „Promocje i kody rabatowe” (lista promocji, formularz promocji z kartami „Dane promocji”, „Warianty” i „Status”, wybór wariantów z nazwanymi przyciskami i fokusem, `ConfirmDialog` dla „Zakończ teraz” i „Usuń”, ponowny odczyt po „Zakończ teraz”, tryb tylko do odczytu, pasek zapisu, strażnik zmian, toasty, 404 z `Alert`); `usePromotionList` na `useListUrlState` i `useCursorPager` bez obserwatora `route.query` i `statusOf`; szablon `PromotionVariantPicker.vue` na komponentach shadcn-vue (logika z ADMIN-SCREENS-2); `PromotionsView.vue`, `PromotionFormView.vue` i `PromotionVariantPicker.vue` bez PrimeVue i poza `LEGACY_FILES`. E2E: nowy `e2e/tests/admin-screens-promotions.spec.ts`, `e2e/tests/promotions.spec.ts` i `e2e/support/promotions.ts` (toast zamiast `success-message`), `e2e/tests/staff-dashboard.spec.ts` (filtr `Select`), test przejścia z pulpitu w `e2e/tests/admin-design.spec.ts`. Dokumentacja: `apps/admin/CLAUDE.md` (promocje), `docs/features/promotions/spec.md` (zdania o ekranie panelu). Zamyka #304 i część promocji #446.",
      "acceptance_criteria": [
        "Axe przy 1280 i 768 px nie zgłasza naruszeń na `/promotions`, `/promotions/new` z otwartym wyborem wariantów i z oknem niezapisanych zmian, `/promotions/<aktywna>` z oknem „Zakończ teraz” i `/promotions/<nieistniejąca>` (bez `color-contrast` w komunikacie 404, `#446`).",
        "Reflow przy 320 × 640 px na tych ekranach i oknach przechodzi (także wybór wariantów i pasek zapisu); przy 375 px okna mieszczą się w ekranie; przy 768 px akcja i status pierwszego wiersza listy są widoczne bez przewijania w poziomie.",
        "`/promotions?status=active` pokazuje przefiltrowaną listę z filtrem `Select`; zmiana filtra zmienia adres bez nowego wpisu historii; puste stany według standardu.",
        "Testy nazwane `#304`: F3 fokus i przewinięcie do komunikatu nieudanego zapisu; F4 fokus po dodaniu (pole wyszukiwania) i po usunięciu (następny wiersz, a bez niego pole wyszukiwania otwartego wyboru albo przycisk otwierający wybór); F5 nazwy przycisków z nazwą wariantu (E2E); F6 `ConfirmDialog` z „Wróć” w fokusie i zamknięciem `Escape` (E2E); F7 ponowny odczyt po „Zakończ teraz” z fałszywym zegarem: wyłączone „Zapisz” i „Zakończ teraz” z opisem „Promocja kończy się”, `ended` z `PUT` albo odczytu daje toast „Promocja zakończona.” i tryb tylko do odczytu z kartą „Status”, najwyżej 5 ponowień co 1 s, komunikat z godziną końca, błąd z „Odśwież”, brak żądań po odmontowaniu.",
        "Formularz promocji: pasek zapisu, strażnik zmian, toast „Promocja zapisana.” i fokus na H1 po zapisie, po utworzeniu przejście na `/promotions/:id`; po usunięciu przejście na listę z fokusem na H1 i toastem „Promocja usunięta.”; tryb tylko do odczytu z notatką w karcie „Status” (testy komponentów); `promotions.spec.ts` tworzy promocję i widzi toast w regionie `aria-live`.",
        "`PromotionsView.vue`, `PromotionFormView.vue` i `PromotionVariantPicker.vue` nie importują PrimeVue i nie ma ich na `LEGACY_FILES`; testy `usePromotionForm` i `usePromotionList` przechodzą bez zmian asercji poza zmianami ze specyfikacji.",
        "Przechodzą `make check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-SCREENS-1", "ADMIN-SCREENS-2"]
    },
    {
      "id": "ADMIN-SCREENS-6",
      "title": "Zamówienia: lista z kartami na telefonie i szczegóły z osią czasu",
      "scope": "apps/admin: sekcja spec „Zamówienia” (lista z Klientem i e-mailem, `itemCount`, numerem bez łamania, stanem w adresie, kartami poniżej 640 px renderowanymi jednym układem według `useMediaQuery`; szczegóły w dwóch kolumnach z kartami „Pozycje”, „Historia”, „Zwrot pieniędzy”, „Akcje”, „Klient”, „Adres dostawy”, „Płatność”, kopiowaniem identyfikatora płatności, oknem zmiany statusu, toastem z polską etykietą statusu z odpowiedzi API (D18), fokusem po akcji i po 409; komponent `OrderTimeline`). `useOrderList` na `useListUrlState` i `useCursorPager` bez obserwatora `route.query` i `filtersOf` (wybór „Zwroty do wykonania” w menu działa przez obserwację `route.query` w `useListUrlState`); `OrdersView.vue` i `OrderDetailView.vue` bez PrimeVue i poza `LEGACY_FILES`. Testowe identyfikatory z sekcji „Testowe identyfikatory” (`order-row`, oś czasu, `copy-payment-id`, `copy-status`, `refund-pending`, bez `back`). E2E: nowy `e2e/tests/admin-screens-orders.spec.ts`, `e2e/tests/order-management.spec.ts`, `e2e/tests/panel-orders-url.spec.ts`, `e2e/support/panel-orders.ts`. Dokumentacja: `apps/admin/CLAUDE.md` (zamówienia), `docs/features/order-management/spec.md` (zdania o ekranach panelu). Zamyka #260 i #261.",
      "acceptance_criteria": [
        "Axe przy 1280 i 768 px nie zgłasza naruszeń na `/orders`, `/orders?refund=pending` i `/orders/<id>` z otwartym oknem zmiany statusu (bez `color-contrast`, `#260` F9).",
        "Reflow przy 320 × 640 px na tych ekranach i w oknie przechodzi; przy 375 px okno mieści się w ekranie, lista ma karty z numerem, statusem i kwotą widocznymi bez przewijania w poziomie, szczegóły nie przewijają strony w poziomie, a tabela pozycji pokazuje produkt, ilość i wartość; `order-row` jest w DOM raz na zamówienie przy każdej szerokości.",
        "Numer zamówienia przy 1280 px na `/orders` i `/orders?refund=pending` ma wysokość nie większą niż 1,5 × `line-height` (E2E `#261`); fokus po zmianie strony listy zostaje na pagerze (test komponentu `#260` F7); po 403 w oknie zmiany statusu fokus na komunikacie okna (test komponentu `#260` F8).",
        "`/orders?status=paid&perPage=10` i `/orders?refund=pending` pokazują przefiltrowane listy z wypełnionymi filtrami; zmiana filtra zmienia adres bez nowego wpisu historii; wybór „Zwroty do wykonania” w menu na otwartej liście przełącza ją bez przeładowania (`panel-orders-url.spec.ts`); przy 768 px numer, status i kwota pierwszego wiersza są widoczne, a data jest linią komórki Numer przy 768 i 320 px.",
        "Oś czasu: wpisy `history` w kolejności z API, czas w `<time>`, zmiana „Opłacone → Wysłane”, aktor („Klient”, „System”, login, „Pracownik” bez loginu) i notatka; „Inne zamówienia klienta” prowadzi do `/orders?q=<e-mail>`; kopiowanie ogłasza „Skopiowano”, a przy odmowie schowka komunikat i zaznaczenie tekstu; tabela pozycji ma kolumny ze specyfikacji z kodem i bez kodu (testy komponentów).",
        "Po akcji toast „Status zamówienia zmieniony: <polska etykieta statusu z odpowiedzi API>.” (nigdy pusty) albo „Zwrot oznaczony jako wykonany.”, fokus na nagłówku „Akcje”, a gdy karta zniknęła, na „Historia”; 409 zamyka okno, pokazuje `conflict` z fokusem i wczytuje zamówienie (testy komponentów); `order-management.spec.ts` przechodzi przez okruszki zamiast `back`.",
        "`OrdersView.vue` i `OrderDetailView.vue` nie importują PrimeVue i nie ma ich na `LEGACY_FILES`; testy `useOrderList` i `useOrderDetail` przechodzą bez zmian asercji poza zmianami ze specyfikacji.",
        "Przechodzą `make check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-SCREENS-1"]
    },
    {
      "id": "ADMIN-SCREENS-7",
      "title": "Usunięcie PrimeVue, mostu starych list i test regresji całego panelu",
      "scope": "Sekcja spec „Usunięcie PrimeVue”: pakiety `primevue`, `@primeuix/themes`, `primeicons` z `apps/admin/package.json` i `package-lock.json`, `src/main.ts`, `src/primevue.ts`, montowanie PrimeVue w testach jednostkowych, `LEGACY_FILES` w `eslint.config.js` i `eslint.config.d.ts`, test reguł w `tests/lint/`, gałąź `force` w `src/layout/AppMenuItem.vue` z jej testami, typ `TreeNode` w `useCategoryTree` (gdy nieużywany), komentarz `style-src` w `apps/admin/docker/security-headers.conf`. Test regresji: `e2e/tests/admin-design.spec.ts` i testy `admin-screens-*` przechodzą razem po usunięciu (skip link i bieżąca pozycja menu na każdej trasie, w tym zmigrowanych). Dokumentacja z sekcji „Dokumenty opisujące funkcję” nie przypisana do zadań ekranów: `CLAUDE.md`, `README.md`, `docs/vision.md`, `docs/adr/admin-ui-library.md`, `docs/features/admin-design/spec.md` (stan bez okresu przejściowego, wyjątek kolejności fokusu A4), `docs/features/walking-skeleton/spec.md`, `docs/features/catalog-admin/spec.md`, `apps/admin/CLAUDE.md` (stos, bez mostu, bez `LEGACY_FILES`, bez „do ADMIN-SCREENS”). Bez zmian zachowania ekranów.",
      "acceptance_criteria": [
        "`apps/admin/package.json` nie ma `primevue`, `@primeuix/themes` ani `primeicons`; żaden plik `apps/admin/src` i `apps/admin/tests` poza `tests/lint/` nie zawiera `primevue` ani `primeicons`; `eslint.config.js` i `eslint.config.d.ts` nie mają `LEGACY_FILES`.",
        "Test reguł w `tests/lint/` sprawdza, że ESLint zgłasza błąd dla importu `primevue/button`, `@primeuix/themes/aura` i `primeicons/primeicons.css` oraz dla `bg-[#fff]` i bloku `<style>` w dowolnym pliku `src/`, z identyfikatorem reguły i liczbą błędów.",
        "`AppMenuItem` nie ma gałęzi `force`; klik z Ctrl, Cmd, Shift, Alt albo środkowym przyciskiem zostaje przeglądarce; wybór „Zwroty do wykonania” na otwartej liście zamówień dalej ją przełącza (`panel-orders-url.spec.ts`).",
        "Pierwszy Tab na każdej trasie panelu trafia na „Przejdź do treści”, a bieżąca pozycja menu ma `aria-current=\"page\"` na każdej trasie (`admin-design.spec.ts`); wszystkie testy `admin-screens-*` i istniejące testy E2E panelu przechodzą razem.",
        "Każde zgłoszenie z linii `Issues` (poza #462) ma test z kolumny „Weryfikacja” nazwany jego numerem; każda pozycja spisu „Ceny”, „Stany”, „Promocje”, „Kody rabatowe” i „Zamówienia” z „Zachowanych funkcji” ADMIN-DESIGN ma test, który ją wykonuje (lista pozycji z testami w opisie PR).",
        "`CLAUDE.md`, `README.md`, `docs/vision.md`, `docs/adr/admin-ui-library.md`, `docs/features/admin-design/spec.md`, `docs/features/walking-skeleton/spec.md` i `apps/admin/CLAUDE.md` opisują panel bez PrimeVue i bez okresu przejściowego; `grep -ri primevue` w tych plikach zwraca tylko opis usunięcia w ADR (albo nic).",
        "Przechodzą `make check`, `make -C apps/admin test` i `make e2e`."
      ],
      "risk": "STANDARD",
      "depends_on": ["ADMIN-SCREENS-2", "ADMIN-SCREENS-3", "ADMIN-SCREENS-4", "ADMIN-SCREENS-5", "ADMIN-SCREENS-6"]
    }
  ]
}
```
