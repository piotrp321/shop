Review taska ADMIN-SCREENS-1. Werdykt: **CHANGES_REQUIRED**. Jest jedno zgłoszenie IMPORTANT: próg 600 px zamiast 640 px. Reszta to SUGGESTION.

**SHA:** fd4cfe61ba9a933591a711323dbbe5252c33c175 (HEAD sprawdzony na początku i na końcu, bez zmian). Merge-base 617bad00. Tryb **FULL**. Checkout `<home>/Development/claude/shop-ADMIN-SCREENS-1`, drzewo czyste. Testów nie uruchamiałem (tylko odczyt). Wyniki `make check`, testów panelu i `make e2e` znam tylko z raportu developera.

## Trzy kontrole z punktu 4

- **Istniejące testy: bez uwag.** Diff nie zmienia żadnego pliku testów z `base_sha` w `apps/admin/tests` (`useProductList.test.ts`, `ProductsView.test.ts` bez zmian) ani `catalog-admin.spec.ts`. W `e2e/tests/admin-design.spec.ts` usunięto tylko helpery przeniesione do `e2e/support/panel-states.ts` i podmieniono wywołania w `beforeAll` i `afterAll`. Asercje są nietknięte, nic nie jest pominięte (`skip`, `only`).
  - `newPanelPage` dostał dodatkowe `expect(context).toBeDefined()`, czyli kontrolę mocniejszą, nie słabszą.
  - Typ roli rozszerzono z `"admin" | "marketing"` na `keyof typeof staff`. To niczego nie osłabia.
- **Zakres: bez uwag.** 10 plików, wszystkie wymienione w scope (`AppLayout.vue`, `ui/sonner/Sonner.vue`, `useNameSearch.ts` z testem, `useProductList.ts`, `panel-states.ts`, `admin-design.spec.ts`, nowy `admin-screens-toasts.spec.ts`, `admin-design/spec.md`, `apps/admin/CLAUDE.md`). Ekrany cen, stanów, promocji, kodów i zamówień są nietknięte.
- **RED przed implementacją: OK** (dziennik `.../ADMIN-SCREENS-1/delivery-state.json.evidence.jsonl`, 8 wpisów).
  - `useNameSearch`: RED o 18:44:07 przy `head`=617bad00. W drzewie był tylko nowy test, a błąd to `Failed to resolve import "@/composables/useNameSearch"`. Kod produkcyjny nie był ani w `dirty`, ani w `head`. GREEN o 18:44:18: 11 testów.
    - Uwaga: RED działa na poziomie pliku, więc w `output_tail` nie ma nazw pojedynczych testów (`0 test`, sama nazwa pliku). Przy brakującym module inaczej się nie da i nie uważam tego za zgłoszenie.
  - E2E toastów: RED o 18:48:06 przy `head`=4ccd8ec4. `git diff 617bad00..4ccd8ec4` nie ma `Sonner.vue` ani `AppLayout.vue`, a `dirty` to tylko spec. W `output_tail` są nazwy wszystkich 4 testów (#508 i 3 × #507) z prawdziwym błędem: toast `y:570.5` przecina „Odrzuć” przy 320 px, pole 3024. GREEN o 18:48:41 z tymi samymi nazwami, a w `dirty` oba pliki produkcyjne.
  - Przejście `useProductList` na nowy composable to refaktor. Pokrywają go istniejące testy bez zmian, więc nie potrzebuje RED.

## Ustalenia

### IMPORTANT 1: toast zajmuje szerokość ekranu od 600 px, a spec wymaga poniżej 640 px; dokumentacja podaje dwie różne wartości

Uzasadnienie wagi: kod nie spełnia jawnej reguły ze specyfikacji, a komentarz w kodzie opisuje stan, którego nie ma.

**Dowód.** `docker compose exec -T admin` na `node_modules/vue-sonner/lib/index.css` (vue-sonner 2.0.9) daje jedyne `@media (max-width: 600px)`. Tylko tam działają `--mobile-offset-*` i `width: calc(100% - var(--mobile-offset-left) * 2)`. Przy 601–639 px toast ma `--width` 356 px i prawy margines 24 px (`TOAST_OFFSET.right: "1.5rem"`), nie szerokość ekranu z marginesem 16 px.

**Spec:**
- `docs/features/admin-screens/spec.md:243-244`: „Przy szerokości poniżej 640 px toast zajmuje szerokość ekranu z marginesem 16 px”.
- Scope taska mówi to samo.

**Niespójność dokumentacji:**
- `apps/admin/src/layout/AppLayout.vue:18`: „Below 640 px they take the width of the screen with a 16 px margin” (nieprawda).
- `apps/admin/CLAUDE.md:39`: „do 600 px” (prawda, ale sprzeczne ze spec).
- `docs/features/admin-design/spec.md:260`: „na wąskim ekranie” (bez progu).

**Wpływ.** W paśmie 601–639 px toast nadal jest pod topbarem i nie zasłania paska zapisu ani „Menu” czy konta, więc #507 jest spełnione. Rozjazd dotyczy tylko układu. E2E mierzy 1280, 375 i 320 px, więc tego pasma nie łapie.

**Naprawa** (decyzja orkiestratora albo użytkownika, bo jedna z opcji zmienia spec):
- (a) Próg 640 px w kodzie. Wymaga nadpisania CSS biblioteki: arkusz vue-sonner jest poza warstwami i wygrywa z narzędziami Tailwinda w warstwie, a `--width` jest ustawiane inline. Potrzebne byłoby np. `max-sm:` z modyfikatorem `!` na `class` Toastera i test przy np. 620 px.
- (b) Zmiana spec na „do 600 px” (próg biblioteki) i poprawka komentarza `AppLayout.vue:18`.

W obu wariantach trzeba ujednolicić trzy dokumenty.

### SUGGESTION 1: nieaktualny komentarz i luźna asercja nazwy regionu w teście jednostkowym

`apps/admin/tests/ui-accessible-names.test.ts:125-126` (poza diffem):
- komentarz mówi „vue-sonner appends the hotkey to the name of the region („Powiadomienia alt+T”)”, co już nie jest prawdą;
- regex `/^Powiadomienia\b/` przepuściłby powrót skrótu, więc regresję #508 łapie tylko E2E.

Rzeczywisty `aria-label` to `"Powiadomienia "` z końcową spacją (`index.js:1151`: `` `${containerAriaLabel} ${hotkeyLabel}` ``). Nazwa dostępna jest przycinana, więc E2E `exact` przechodzi.

Wpływ jest tylko na jakość testów. Propozycja: asercja `getAttribute("aria-label")?.trim() === "Powiadomienia"` i aktualizacja komentarza.

### SUGGESTION 2: martwy fallback w `boxesOf`

`e2e/tests/admin-screens-toasts.spec.ts:67`: `(await element.innerText()).trim() ?? …`. String nigdy nie jest nullish, więc fallback `tagName` nie działa. Przycisk z samą ikoną dostaje pustą etykietę w komunikacie błędu. Asercje to nie zmienia.

### SUGGESTION 3: przycinanie w `setFromAddress`

`apps/admin/src/composables/useNameSearch.ts:76`: `setFromAddress` ustawia `synced = term ?? ""` bez `trim()`, a start ustawia `synced = search.value.trim()` (linia 42). Dla `q` z adresu ze spacjami (` ab `) po 300 ms idzie jedno `apply("ab")`. `url.set` z tym samym `q` niczego nie zapisuje (sygnatura równa `known`), a w bazie było tak samo, więc zachowanie się nie zmienia. To tylko kwestia spójności.

### Nie zgłaszam

- **Obejście `hotkey: ["Key"]`** (`Sonner.vue:15`). W vue-sonner 2.0.9 nie ma czystszej drogi. Etykieta to `hotkey.join("+").replace(/Key/g,"")`, a wykrycie to `hotkey.every((key) => event[key] || event.code === key)` (`index.js:980` i `1098`).
  - `hotkey: []` dałoby pustą etykietę, ale `[].every(...)` jest zawsze `true`, czyli każdy klawisz przenosiłby fokus na toasty. To byłoby gorsze.
  - `["Key"]` daje pustą etykietę i skrót, którego żadne zdarzenie nie spełni.
  - Spec (`admin-screens/spec.md:238-240`) dopuszcza usunięcie skrótu. Toasty panelu (`ProductFormView.vue:105`, tylko `toast.success`) nie mają akcji ani przycisku zamknięcia, więc usunięcie skrótu nie zabiera klawiaturze żadnej funkcji. Komentarz w kodzie trafnie opisuje obejście.
- **„Menu” i konto w topbarze.** Topbar ma stałą wysokość `h-17.5` (70 px) przy każdej szerokości (`AppTopbar.vue:54`), a toast startuje od `calc(4.375rem + 0.75rem)` = 82 px (górne odsunięcie jest takie samo na desktopie i mobile). Dlatego nie przecina topbaru przy żadnej szerokości. Przy 320 × 640 nawet 3 rozwinięte toasty (~285 px) nie sięgają paska zapisu. E2E sprawdza wszystkie `a, button, input` w `header` (jedyny `<header>` w panelu).
  - `TOAST_TOP` powiela wysokość topbaru. To świadome i opisane w komentarzu.

## `useNameSearch` a dotychczasowy `useProductList` (porównanie z 617bad00)

Zachowanie jest takie samo:
- reguły 2–100 punktów kodowych po `trim()`;
- 300 ms od ostatniej zmiany;
- pusta fraza i same spacje dają `null`;
- za krótka albo za długa fraza nie jest stosowana, a teksty podpowiedzi są identyczne;
- `q: validNameTerm` w schemacie adresu;
- pole startuje od surowego `address.q` (za krótkie `q` z adresu zostaje w polu z podpowiedzią);
- zmiana adresu z zewnątrz (Wstecz, topbar) przez `setFromAddress` czyści timer i ustawia pole;
- `clearFilters` to `reset()` plus `url.clear()`;
- timer jest czyszczony przy odmontowaniu.

Jedyna różnica to pominięcie timera, gdy `value.trim() === synced`. W bazie w tych przypadkach (echo zmiany adresu, `clearFilters`, powrót do zastosowanej frazy) po 300 ms szło `url.set` z tym samym `q`. Według `useListUrlState.ts:102-108` to nic nie zapisuje (sygnatura równa `known`), a `useCursorPager` porównuje po JSON, więc nie wysyła żądania. Efekt jest więc niewidoczny. Stałe `MAX_SEARCH_LENGTH` i `SEARCH_DEBOUNCE_MS` są nadal eksportowane i używane w `ProductsView` i testach. Usunięty `MIN_SEARCH_LENGTH` z `useProductList` nie ma odbiorców (`git grep`).

`tests/useNameSearch.test.ts` pokrywa kryterium 3: 1 znak, 2 znaki, 100 i 101 znaków (emoji jako dowód liczenia punktów kodowych), same spacje, 299 i 300 ms, `reset`, odmontowanie przed upływem opóźnienia, `setFromAddress` bez `apply` i wpisywanie po wartości z adresu.

## Lista kontrolna z punktu 6

- **Błędy API:** nie dotyczy (diff nie zmienia API ani obsługi odpowiedzi).
- **Komunikaty po polsku:** sprawdzone, bez uwag. Podpowiedzi są te same co w bazie, region nazywa się „Powiadomienia”, przycisk zamknięcia „Zamknij powiadomienie”.
- **Unicode:** sprawdzone, bez nowych uwag.
  - `String.prototype.trim()` usuwa też NBSP i U+2028, więc fraza z samych takich znaków to `null` (brak filtra). Punkty kodowe są testowane.
  - NUL w frazie jest liczony jak znak i idzie do API, tak jak w bazie (D16: bez zmiany zachowania). Topbar traktuje znaki sterujące jak spacje, lista nie: to różnica sprzed zmiany.
  - Test NBSP w `useNameSearch.test.ts` byłby miłym dodatkiem, ale kryterium go nie wymaga.
- **Klawiatura, focus, etykiety:** sprawdzone, bez uwag. Region `aria-live="polite"` zostaje, ma nazwę dokładnie „Powiadomienia”, usunięty skrót nie odbiera klawiaturze żadnej funkcji, a toast nie blokuje „Zapisz” (E2E klika w 3 s).
- **Stany (loading, empty, error, 375 px):** loading, empty i error nie dotyczą (bez nowych zapytań). Szerokość 375 px pokrywa E2E #507. Pasmo 601–639 px opisuje IMPORTANT 1.
- **Przypadki brzegowe:** sprawdzone. Granice 1, 2, 100 i 101 znaków, same spacje, timer i odmontowanie są pokryte. Próg szerokości toastu: IMPORTANT 1.

## Model zagrożeń

Kod taska nie dotyka autoryzacji, danych ani sekretów. Nie wprowadza żadnej drogi ataku, także poza modelem.