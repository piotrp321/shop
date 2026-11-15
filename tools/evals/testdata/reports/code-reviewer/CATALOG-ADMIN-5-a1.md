## Review CATALOG-ADMIN-5: lista produktów i formularz produktu ze zdjęciami

**SHA:** HEAD = 2a6e0b4395e3b093531b2a9e94013cc9aa35cb56 (zgodny z zamrożonym, drzewo czyste), base = c5012ee8bcbde1b0843c1be7906d923308e84147
**Tryb:** FULL
**Werdykt: CHANGES_REQUIRED.** Jest jedno ustalenie Important (I1). Ustaleń Critical nie ma.

### Trzy kontrole

1. **Istniejące testy: bez uwag.** Zmieniono tylko dwa pliki testów z base, w obu przypadkach zasadnie:
   - `apps/admin/tests/AppMenu.test.ts`: dopisany oczekiwany link „Produkty” → `/products`, zgodnie ze specyfikacją i AC3.
   - `apps/admin/tests/AppLayout.test.ts`: w fixturze routera dodana trasa `products`, bo menu linkuje teraz do tej nazwanej trasy. Nic nie zostało osłabione.
   - W dodanych liniach nie ma `.only`, `.skip`, `.todo` ani `xit`. Żaden test nie został usunięty.
2. **Zakres: bez uwag.** Wszystkie pliki są w `apps/admin`: menu, router, `src/views`, `src/products`, testy i `apps/admin/CLAUDE.md`. Nie ma zmian w API, w wygenerowanym kliencie ani w innych aplikacjach.
3. **RED przed implementacją: bez uwag.** Dziennik: `<home>/.local/state/ao-catalog-admin/tasks/CATALOG-ADMIN-5/delivery-state.json.evidence.jsonl`.
   - Każda grupa testów ma wpis RED przed GREEN: useProductList (0→1), useImageUpload (3→4), useProductForm (6→8), AppMenu/thumbnail/routerProducts (9), ProductsView (10), ProductFormView (11), a GREEN dla nich to wpisy 12–14.
   - We wpisach RED `dirty` zawiera tylko testy danej grupy oraz pliki produkcyjne wcześniejszych, już zielonych zachowań. Pliku produkcyjnego testowanego zachowania nie ma ani w `dirty`, ani w `head` wpisu RED. RED nie jest więc odtworzony po fakcie.
   - Zastrzeżenie: większość wpisów RED to błąd importu modułu („Failed to resolve import … Does the file exist?”), więc w `output_tail` jest nazwa pliku testów, a nie nazwy pojedynczych testów. Nazwy testów są tylko w wpisie 9 (routerProducts). To prawdziwa faza RED na poziomie pliku i zgadza się z `tdd.status: verified`.
   - Wpis 13 (GREEN, exit 2) pokazał regresję w `AppLayout.test.ts`. Wpis 14 pokazuje, że naprawiono ją przez fixturę, a nie przez osłabienie testu.

### Ustalenia

**IMPORTANT**

**I1. Ostrzeżenie `beforeunload` formularza wyłącza automatyczne wylogowanie po bezczynności.**
- Gdzie: `apps/admin/src/views/ProductFormView.vue:90-97`, w połączeniu z `apps/admin/src/layout/AppLayout.vue:19-24` i `apps/admin/src/composables/useIdleLogout.ts` (`check()` woła `stop()` przed `onIdle`).
- Scenariusz:
  1. Pracownik `marketing` zmienia pole formularza (formularz jest `dirty`, strona ma aktywację użytkownika) i odchodzi od komputera.
  2. Po 15 minutach `useIdleLogout` sam się zatrzymuje i woła `auth.logout()`, czyli `signoutRedirect()`, czyli nawigację `window.location` do Keycloak.
  3. `warnBeforeUnload` blokuje tę nawigację i przeglądarka pokazuje „Opuścić stronę?”.
  4. Ktoś przy komputerze klika „Zostań”. Nawigacja zostaje anulowana, `signoutRedirect` nie rzuca wyjątku, więc `logoutFailed` zostaje `false` i `AppLayout` nie uzbraja timera ponownie.
- Wpływ: sesja z tokenami w pamięci zostaje aktywna bez limitu bezczynności. Wymaga tego `docs/vision.md:81` („krótka, wygasa po bezczynności”). To regresja istniejącej kontroli bezpieczeństwa, wprowadzona tym diffem. `beforeunload` nie jest wymagany specyfikacją, to wybór developera.
- Dowód: ścieżka wynika z kodu i ze standardowej semantyki `beforeunload`. Nie odtwarzałem jej w przeglądarce.
- Naprawa: pominąć ostrzeżenie, gdy trwa wylogowanie (np. flaga w store `auth` ustawiana przez `logout()` i sprawdzana w `warnBeforeUnload`), i dopisać test.

**MINOR**

- **M1. Spóźniona odpowiedź POST przenosi użytkownika na formularz po tym, jak go opuścił.**
  - Gdzie: `apps/admin/src/products/useProductForm.ts:355-361` oraz `ProductFormView.vue:55-57`.
  - Scenariusz: na `/products/new` „Zapisz”, a w trakcie POST „Wróć do listy”. Formularz jest `dirty`, użytkownik potwierdza, widok się odmontowuje, guard jest usunięty. POST kończy się, `onCreated` wywołuje `router.replace({name:'product'})` i użytkownik ląduje z listy (albo np. z `/prices`) z powrotem na nowym produkcie.
  - Naprawa: pomijać `onCreated` i `apply` po odmontowaniu, np. przez znacznik ustawiany w `onUnmounted` albo przez licznik `latest`.
- **M2. Pola nie są blokowane w trakcie zapisu, a sukces nadpisuje formularz odpowiedzią.**
  - Gdzie: `useProductForm.ts:200-204` i `355-357`. W `ProductFormView.vue` pola mają tylko `:disabled="readOnly"`.
  - Skutek 1: tekst wpisany w trakcie PUT znika po sukcesie, a `dirty` wraca do `false`, więc guard nie pyta.
  - Skutek 2: upload wybrany w trakcie PUT, który skończy się przed PUT (`useImageUpload.ts:103`), zostaje usunięty z `images`, a wpis pokazuje „Gotowe”.
  - Okno czasowe jest krótkie. Do rozważenia: blokować pola i „Dodaj zdjęcia” przy `busy`.
- **M3. Nieudane „Odśwież” po 409 chowa cały formularz i nie daje możliwości ponowienia.**
  - Gdzie: `useProductForm.ts:214-233` ustawia `loadError`, a szablon `ProductFormView.vue:124-136` pokazuje wtedy tylko komunikat, bez przycisku. To samo dotyczy pierwszego ładowania.
  - Specyfikacja mówi o błędach „bez utraty danych formularza”. W przypadku odświeżenia zmiany i tak są odrzucane, ale użytkownik musi przeładować kartę.
- **M4. Naruszenie na samej ścieżce `images` nie jest nigdzie wyświetlane.**
  - Gdzie: `useProductForm.ts:308` przypisuje je do pola, bo `images` jest w `FIELD_ROOTS`, a `ProductImages.vue:157-162` renderuje tylko `images[${index}]`.
  - API zwraca tę ścieżkę dla „Produkt może mieć najwyżej 10 zdjęć.” i „Zdjęcia muszą być listą.” (`ValidProductWriteValidator.php:124,129`).
  - W praktyce jest to prawie nieosiągalne, bo panel pilnuje limitu, ale komunikat zniknąłby po cichu.
- **M5. Ciała błędów są parsowane ręcznie, choć wygenerowany klient ma ich typy.**
  - Gdzie: `useProductForm.ts:47,57-61,118-132`: ręczne kształty `{propertyPath,title}` i `Outcome` oraz zaszyty `PROBLEM_PREFIX`.
  - Wygenerowany klient ma `components["schemas"]["Problem"]` i `ProductConflictProblem`, który zawiera enum `type`.
  - Wzorzec jest spójny z `src/inventory/useStockList.ts`, więc to sugestia: oprzeć zawężanie na typach wygenerowanych, zgodnie z zasadą „nie pisz ręcznie typów odpowiedzi API”.
- **M6. Brak testu usunięcia guarda po odmontowaniu.** W kodzie usuwanie jest poprawne (`ProductFormView.vue:98-101`, `removeGuard()` i `removeEventListener`). Żaden test nie sprawdza, że po opuszczeniu formularza kolejna nawigacja nie woła już `confirm`.
- **M7. `ProductVariants.vue` używa własnej `<table>`.** `apps/admin/CLAUDE.md` („Wygląd”) zaleca PrimeVue `DataTable`. Komponent jest tymczasowy do CATALOG-ADMIN-6, więc to tylko sugestia.
- **M8. Podwójne `/me` przy bezpośrednim wejściu na `/products/new`.** Pobiera je `beforeEnter` w `router/index.ts`, a potem jeszcze raz `AppLayout` w `onMounted`. Szkoda jest pomijalna.

### Decyzje developera
Są trafne:
- **Globalny `router.beforeEach` zamiast `onBeforeRouteLeave`:** uzasadniony, bo guard opuszczenia zostaje przy rekordzie `product-new`, a ta sama instancja przechodzi na `product`. Guard jest zdejmowany w `onBeforeUnmount`.
- **`beforeunload`:** listener jest poprawnie usuwany. Problemem jest tylko interakcja z wylogowaniem po bezczynności (I1).
- **`onCreated` z `router.replace`:** działa. `open()` pomija ponowne GET dla właśnie utworzonego id, a guard nie pyta, bo `apply` ustawia baseline przed nawigacją.
- **`beforeEnter` z `auth.load()`:** działa i biegnie po globalnym guardzie logowania. Gdy `/me` się nie uda, przekierowuje na listę, co jest przetestowane.
- **TreeSelect, SelectButton, ukryty input pliku:** w porządku. Klucze kategorii to UUID, więc kolejność `Object.keys` jest stabilna.
- **`product-not-publishable` i ścieżki spoza formularza jako komunikat formularza:** zgodne ze specyfikacją.
- **Upload:** usuwanie zakończonych wpisów przy nowym wyborze i brak ponowienia dla duplikatu i nadmiaru są w porządku.

### Sprawdzone bez uwag
- **Zgodność ze specyfikacją:**
  - Lista: kolumny, „Dodaj produkt” tylko dla `admin`/`marketing`, filtr statusu z trzema opcjami, wyszukiwanie od 2 znaków liczone w code pointach.
  - Formularz: podpowiedź sluga, blokada `slugLocked` z wyjaśnieniem, licznik opisu, klasy podatkowe z API, wariant domyślny `{variantId:null, values:[]}`, warianty wysyłane bez zmian.
  - Błędy: 409 rozpoznawane po `type`, w tym oba teksty `product-not-publishable`. 422 trafia przy polach, także w ścieżkach zagnieżdżonych (`images[i].key` z `ImageNotFound`, `additionalCategoryIds[i]`).
  - Uprawnienia: tryb tylko do odczytu bez akcji i przekierowanie z `/products/new` (AC2).
- **Wyścigi na liście:** licznik `latest` odrzuca spóźnione odpowiedzi. Zmiana filtra zeruje stos kursorów, `retry` ponawia ostatnią próbę, a `onUnmounted` czyści timer.
- **Kolejność uploadu:** jedna pętla `drain` z flagą `running`. Wolne miejsca liczą pliki oczekujące i wysyłane, `send` jeszcze raz sprawdza limit przy ponowieniu. Duplikat klucza nie jest dodawany, a błąd jednego pliku nie zatrzymuje pozostałych.
- **`dirty`:** porównuje body bez podglądów. Baseline jest odbudowywany z odpowiedzi po każdym sukcesie i po odświeżeniu.
- **Logika i klient:** front niczego nie liczy, używa wyłącznie wygenerowanego klienta.
- **Dokumentacja:** `apps/admin/CLAUDE.md` opisuje `src/products` i nowe widoki (AC4).
- **Testy:** pokrywają AC1–AC3. AC5 według orkiestratora: 469 testów zielonych i zielony `check`. Sam ich nie uruchamiałem.