## Review ADMIN-DESIGN-1: tryb FULL, werdykt CHANGES_REQUIRED

- **SHA:** HEAD `931e528fddc3c66c09c3e27131a4971252aa70c5`, potwierdzony na początku i na końcu review. Drzewo czyste. Diff to `81cdbd77..931e528f`: 9 commitów, 153 pliki.
- **Zakres pracy:** tylko odczyt. Do odczytu źródeł `reka-ui` i `vue-sonner` użyłem `docker exec` w działającym kontenerze `admin`, niczego nie uruchamiałem. Zapisałem jeden plik roboczy poza repo: `/tmp/review-base-lock-ad1.json`, czyli lockfile z `base_sha` do porównania.
- **Werdykt:** CHANGES_REQUIRED, bo są dwa znaleziska IMPORTANT. BLOCKER-ów brak.

### Trzy kontrole obowiązkowe

**1. Istniejące testy: bez uwag.**
- Diff nie zmienia, nie usuwa i nie wyłącza żadnego pliku testów z `base_sha`. Wszystkie testy w diffie są nowe; nie ma `skip`, `only` ani `todo`.
- `vitest.config.ts` dostaje tylko `setupFiles`. `include` jest bez zmian.
- `tests/AppMenu.test.ts` dalej sprawdza klasę `menu-item--active`, która zostaje w `AppMenu.vue`.
- E2E nie zmienione.

**2. Zakres: bez uwag.**
- Wszystkie pliki mieszczą się w `scope` albo są pomocnicze dla testów (`eslint.config.d.ts`, `tests/node-fs.d.ts`).
- `src/layout/AppMenu.vue` ma uzasadnienie. Miał blok `<style>` bez importu PrimeVue, więc nie mógł trafić na `LEGACY_FILES` (test wymaga importu `primevue`). Klasy przepisano na Tailwinda 1:1:
  - te same zmienne `--p-*` (padding, gap, promień, kolor, hover);
  - `!` przy klasie aktywnej zachowuje pierwszeństwo aktywnej pozycji nad hoverem;
  - klasa `menu-item--active` zostaje dla testów.
  - Wygląd i zachowanie są bez zmian.
- `src/composables/useCategoryTree.ts` ma uzasadnienie. Importował typ z `primevue/treenode`, a kryterium 1 zakazuje importu PrimeVue poza `LEGACY_FILES`. Nowy `CategoryTreeNode` ma ten sam kształt `{key, label, children}`, więc `TreeSelect` w starych widokach działa jak dotąd.

**3. RED przed implementacją**

| Test | Ocena |
|---|---|
| `tokens` | Prawdziwy RED (wpis 1). Dirty był tylko plik testu, a w `head` `f94486da` nie ma `tokens.css`; ENOENT na `tokens.css`. |
| `tokens`, druga runda (nowe tokeny foreground) | Prawdziwy RED (wpis 12): 6 failed, `tokens.css` nie był dirty. |
| `color-rules` | Prawdziwy RED (wpisy 6–8): dirty był tylko test, failure przez brak `LEGACY_FILES`, potem GREEN (wpis 10). |
| `CategoryCombobox` | Prawdziwy RED (wpis 17): dirty był tylko test, „Failed to resolve import @/components/CategoryCombobox.vue”. W `538c6fcd` komponentu nie ma, GREEN w wpisie 19. |
| `fonts` | RED częściowo po fakcie (wpis 3): `src/assets/` i `src/styles/` były już dirty, więc 3 testy plików i `@font-face` przeszły od razu. To weryfikacja zasobów i konfiguracji, dla której `CLAUDE.md` nie wymaga RED. Bez zgłoszenia. |
| `ui-accessible-names` | Fazy RED nie było (wpis 21 ma tylko GREEN, plik nazywał się wtedy `ui-dialogs.test.ts`), bo komponenty były już w drzewie (`src/components/` dirty od wpisu 12). Status `tdd.status = lost_verified_on_base`, więc podaję to jako wynik kontroli, bez nowego zgłoszenia. Ujawnienie w PR jest wymagane. |

### Zgodność ze specyfikacją w punktach, o które prosiłeś

- **Tokeny i kontrast.**
  - Wartości w `src/styles/tokens.css` zgadzają się z tabelą spec, a mapowanie nazw shadcn-vue ze spec.
  - `tests/tokens.test.ts` liczy kontrast wzorem WCAG 2.x dla wszystkich par z tabeli, w tym kpi, fokus i sidebar.
  - Test oblewa przy `muted-foreground = #6b7280` i przy `input-border = #dfe2e8`.
  - Paleta jest w `--palette-*`, więc nie generuje klas; `--color-*: initial` usuwa domyślną paletę.
- **`LEGACY_FILES`.** Lista ma 22 wpisy i jest dokładnie równa zbiorowi plików `src/`, które na HEAD importują `primevue`, `@primeuix` albo `primeicons`. Każdy plik z blokiem `<style>` jest na liście. Test sprawdza, że każdy wpis istnieje i importuje PrimeVue, oraz że lista ma wyłącznie jawne ścieżki.
- **Reguły ESLint.**
  - Wszystkie przypadki z kryterium 3 są pokryte.
  - Test sprawdza identyfikator i liczbę błędów (`toEqual([ruleId])`), w tym próby obejścia: wielkie litery, `rgba`, `oklch`, `color-mix`, kolory nazwane, modyfikator, `cn()`, `cva`, template literal i `:style`.
  - Luki opisuję w IMPORTANT-2.
- **Zależności.**
  - Nowe pakiety w `package.json` to dokładnie 11 pakietów z tabeli spec. `@tanstack/vue-table` nie ma; w lockfile jest tylko `@tanstack/vue-virtual` jako zależność przechodnia `reka-ui`.
  - Wersje i licencje w `docs/adr/admin-ui-library.md` zgadzają się z `package-lock.json`: 4.3.5 MIT; 4.3.5 MIT; 1.4.0 MIT; 4.11.0 MIT; 2.11.0 MIT; 0.7.1 Apache-2.0; 2.1.1 MIT; 3.7.0 MIT; 15.0.0 MIT; 1.0.0 ISC; 2.0.9 MIT.
- **Nunito.**
  - Dwa pliki WOFF2; ich SHA-256 policzone `shasum` zgadzają się z `THIRD_PARTY_NOTICES.md`.
  - Wpis OFL ma adres źródła i wersję `v32`; `font-display: swap`; żadnego zewnętrznego hosta.
  - Twierdzenia o `tnum` w `apps/admin/CLAUDE.md` (równe szerokości cyfr) nie zweryfikowałem; nie ma też testu, który by je utrwalał.
- **`vue/multi-word-component-names`** (`eslint.config.js:169`).
  - To nie jest wyłączenie reguły. To oficjalna opcja `ignores` z jawną listą 18 nazw, tylko w `src/components/ui/**`, i każda nazwa odpowiada istniejącemu plikowi.
  - Reguła dalej działa dla pozostałych komponentów, także w `ui/`.
  - Zgodne z konwencją shadcn-vue. Uwaga do ujawnienia w SUGGESTION-5.
- **Dokumentacja.** ADR, `vision.md`, `README.md`, główny `CLAUDE.md`, `apps/admin/CLAUDE.md` (nagłówek, Struktura, Wygląd, Testy), tabela zależności w `walking-skeleton/spec.md` i komentarz `style-src` są zgodne z kodem. Jedna nieścisłość w ADR: SUGGESTION-4.
- **Zmiana wyglądu poza preflightem.** Jedyna to globalny `:focus-visible` z `tokens.css:269–276` (SUGGESTION-3). Sprawdziłem pozostałe możliwe źródła:
  - Kolizje klas starych widoków z utilities Tailwinda (`grid`, `table`, `sr-only`) dają te same właściwości co ich scoped style.
  - Nunito na `html` jest nadpisane przez `body { font-family: system-ui }` z `styles.css`.

### Lista kontrolna

- **Błędy API:** nie dotyczy, bez zmian w API.
- **Komunikaty po polsku:** sprawdzone.
  - Wszystkie teksty w `src/components/ui` są polskie: „Zamknij”, „Więcej”, „Ścieżka nawigacji”.
  - Sonner ustawia „Powiadomienia” i „Zamknij powiadomienie”. Sprawdziłem w kodzie `vue-sonner`, że te opcje działają; bez testu (SUGGESTION-6).
  - `CategoryCombobox` ma polski placeholder i komunikat pustej listy.
- **Unicode:** sprawdzone. Filtr robi NFC i `toLocaleLowerCase("pl")`, a `trim()` w JS obcina NBSP. Polskie litery w filtrze są przetestowane („śpi”). Filtr nie ignoruje znaków diakrytycznych, czego spec nie wymaga, a nazwa testu sugeruje coś innego (SUGGESTION-7).
- **Klawiatura, focus, etykiety:** sprawdzone.
  - Combobox ma nazwę z `<label for>`, opcje mają `aria-label` z pełną ścieżką, przyciski usuwania mają nazwy, a cel 24 px jest spełniony (`size-6`).
  - Problem: utrata fokusu po „Usuń” (IMPORTANT-1) oraz domyślny fokus w AlertDialog (SUGGESTION-2).
- **Stany:** częściowo nie dotyczy, bo zadanie nie ma zapytań ani widoków. Combobox ma stan pusty („Brak pasujących kategorii.”). 375 px: okna mają `max-w-[calc(100%-2rem)]`.
- **Przypadki brzegowe:** sprawdzone. Puste drzewo jest przetestowane. Nieznane id pokazuje surowe id (SUGGESTION-1), a tryb pojedynczy nie ma czyszczenia (SUGGESTION-1).
- **Model zagrożeń:** nie dotyczy, specyfikacja nie ma tej sekcji.

### Ustalenia

**IMPORTANT-1 (Important): po „Usuń: <nazwa>” fokus spada na `body`.**
- Miejsce: `apps/admin/src/components/CategoryCombobox.vue:96–101` i `:130–149`.
- Mechanizm: `remove()` tylko emituje nowy `modelValue`. Rodzic z `v-model` usuwa wtedy `Badge` z kluczem `selectedId` razem z przyciskiem, który ma fokus, a nic nie przenosi fokusu dalej.
- Wpływ: użytkownik klawiatury i czytnika ekranu traci pozycję po każdym usunięciu kategorii dodatkowej. Spec, sekcja „Dostępność”, wymaga: „fokus nigdy nie spada na `body` po akcji (… usunięcie elementu)”.
- Waga: istotny problem fokusu w komponencie, który podłączają zadania 4 i 5 oraz ADMIN-SCREENS.
- Odtworzenie: zamontuj z `v-model` i `multiple`, Tab na „Usuń: Namioty”, Enter. `document.activeElement === document.body`.
- Test `selects several categories in multiple mode…` tego nie wyłapie, bo montuje bez rodzica, który aktualizuje `modelValue`, więc przycisk w teście nie znika.
- Poprawka: po usunięciu przenieść fokus na następny chip, a bez niego na pole input; dodać test z rodzicem `v-model`.

**IMPORTANT-2 (Important): reguła koloru ma obejścia, które przepuszczają kolor zakazany w spec.**
- Miejsce: `apps/admin/eslint.config.js:63`, `:65–67`, `:83` i reguły `vue/no-restricted-syntax`.
- Kolor nazwany wewnątrz złożonej wartości arbitralnej nie jest łapany, bo `NAMED_ARBITRARY_COLOR` wymaga, żeby kolor był całą wartością w nawiasie. Przykłady: `shadow-[0_0_0_2px_red]`, `[box-shadow:0_0_0_2px_white]`, `bg-[linear-gradient(red,blue)]`. To poprawne klasy Tailwinda, więc nie zgłosi ich też `no-unknown-classes`. Spec mówi o „nazwanych kolorach w nawiasach”.
- Dynamiczny `:style` bez obiektu, zapisany wprost w szablonie, przechodzi, choć spec mówi: „dynamiczny `:style` tylko z tokenami przez `var(--…)`”. Przykłady: `` :style="`background: ${tone}`" ``, `:style="'color:' + tone"`, `:style="{ [key]: tone }"`. Powód: `STYLE_COLOR` szuka wartości koloru, a nie nazwy właściwości, a kontrola wartości wyliczanej obejmuje tylko `Property` w obiekcie.
- `apps/admin/CLAUDE.md` ujawnia tylko przypadek zmiennej z `<script>`, nie te formy w szablonie.
- Waga: reguła przepuszcza dane, które spec odrzuca, a `CLAUDE.md` wymaga, żeby zadania ustawiające reguły sprawdzały próby obejścia. Wpływ dotyczy egzekwowania tokenów w kolejnych zadaniach, nie użytkownika.
- Dowód: wynika z wyrażeń regularnych i selektorów w konfiguracji; nie uruchamiałem lintu.
- Poprawka:
  - kolor nazwany jako osobny człon po `_`, `,`, `(` albo `:` wewnątrz `[...]`;
  - `:style` dopuszczony tylko jako `ObjectExpression` z kluczami nie-wyliczanymi;
  - testy dla tych form.

**SUGGESTION-1 (Minor): tryb pojedynczy `CategoryCombobox` nie ma czyszczenia, a odznaczenie emituje `undefined`.**
- Miejsce: `CategoryCombobox.vue:91–94`.
- Dzisiejszy `TreeSelect` na liście produktów ma `show-clear` (`ProductsView.vue:103`). Reka (`ListboxRoot`, domyślne `selectionBehavior: "toggle"`) odznacza tylko ponownym kliknięciem tej samej opcji i wtedy emituje `undefined`, a kontrakt to `string | null`.
- Nieznane id (np. przy błędzie drzewa) pokazuje surowy UUID w etykiecie i w „Usuń: <uuid>” (`:136`, `:140`).
- Dziś komponentu nic nie używa; warto domknąć przed zadaniem 4 (normalizacja do `null` i jawne „Wyczyść”).

**SUGGESTION-2 (Minor): domyślny fokus `AlertDialog` trafi na krzyżyk „Zamknij”.**
- Miejsce: `apps/admin/src/components/ui/alert-dialog/AlertDialogContent.vue:49–56`.
- Krzyżyk jest renderowany jako `AlertDialogCancel` po slocie. Reka rejestruje `cancelElement` w `onMounted` każdego Cancel i fokusuje ostatni zarejestrowany, a krzyżyk montuje się ostatni. Fokus otwarcia trafi więc na niego zamiast na „Wróć”, którego wymaga zadanie 2.
- Wniosek z lektury kodu Reka, niezweryfikowany testem. Zadanie 2 wyłapie to swoim testem, ale warto to poprawić w komponencie bazowym.

**SUGGESTION-3 (Minor): globalny pierścień fokusu zmienia wygląd fokusu w starych widokach.**
- Miejsce: `apps/admin/src/styles/tokens.css:267–276`.
- `:focus-visible { outline: 2px solid var(--focus-ring) }` w `@layer base` zmienia fokus natywnych elementów (linki menu, `<a>`, `<button>`) na wszystkich ekranach. Komponenty PrimeVue mają własny, niewarstwowy styl, który wygrywa.
- To jedyna zmiana wyglądu poza preflightem. Jest dostępnościowo korzystna, ale formalnie wykracza poza „żaden widok nie zmienia wyglądu poza skutkami preflightu”; warto to odnotować w PR.

**SUGGESTION-4 (Minor): ADR obiecuje więcej, niż daje kod.**
- Miejsce: `docs/adr/admin-ui-library.md:57`.
- ADR pisze, że „ekrany niezmigrowane wyglądają jak dziś”, a spec w sekcji „Ryzyka” mówi, że preflight zmienia ich surowe elementy (nagłówki, listy). Dokumentacja ma opisywać stan obecny.

**SUGGESTION-5 (Minor): wyjątek reguły nazw komponentów do ujawnienia.**
- Miejsce: `eslint.config.js:165–177`.
- Wyjątek `vue/multi-word-component-names` jest wąski i uzasadniony konwencją shadcn-vue, ale to poluzowanie reguły lintera. Według `CLAUDE.md` („nie wyłączaj… zapytaj”) warto wpisać je jawnie do PR albo ledgera (`Ruling:`).

**SUGGESTION-6 (Minor): polskie nazwy Sonnera bez testu.**
- Miejsce: `tests/ui-accessible-names.test.ts`.
- Test statyczny nie obejmuje Sonnera (nazwy idą przez propsy `vue-sonner`, domyślnie „Notifications” i „Close toast”). Jeden test komponentu Sonner zabezpieczyłby polskie nazwy.

**SUGGESTION-7 (Minor): nazwa testu nie odpowiada zachowaniu.**
- Miejsce: `tests/CategoryCombobox.test.ts:111`.
- Test „ignoring case and Polish letters” nie sprawdza ignorowania polskich znaków (wpisuje „śpi” z „ś”), a filtr ich nie ignoruje. Nazwa wprowadza w błąd.

### Kluczowe pliki
- `<home>/Development/claude/shop-ADMIN-DESIGN-1/apps/admin/src/components/CategoryCombobox.vue`
- `<home>/Development/claude/shop-ADMIN-DESIGN-1/apps/admin/eslint.config.js`
- `<home>/Development/claude/shop-ADMIN-DESIGN-1/apps/admin/src/components/ui/alert-dialog/AlertDialogContent.vue`
- `<home>/Development/claude/shop-ADMIN-DESIGN-1/apps/admin/src/styles/tokens.css`
- `<home>/Development/claude/shop-ADMIN-DESIGN-1/apps/admin/tests/CategoryCombobox.test.ts`
- `<home>/Development/claude/shop-ADMIN-DESIGN-1/apps/admin/tests/lint/color-rules.test.ts`
- `<home>/Development/claude/shop-ADMIN-DESIGN-1/docs/adr/admin-ui-library.md`