## Krytyka szkicu `docs/features/admin-screens/spec.md` (ADMIN-SCREENS)

Szkic zawiera sześć znalezisk `Important` i pięć `Minor`. Zależności z API są potwierdzone: `perPage` jest we wszystkich listach, a `itemCount`, `history`, `actorType`, `paymentId` i `GET /tax-classes` są w `apps/api/openapi-admin.json`. Sekcja „Wymagane przed startem” („Brak”) jest poprawna. Model zagrożeń nie jest potrzebny, bo szkic nie ma kryterium bezpieczeństwa. E-mail w `q` maskuje Alloy (`infra/k8s/argocd/apps/monitoring.yaml:183-189`), a korpus logów panelu `admin.log` istnieje.

### Important

**I1. Kolejność fokusu w dwóch kolumnach (formularze promocji i kodu, szczegóły zamówienia)** – techniczne
- **Sekcje:** „Formularz promocji”, „Formularz kodu”, „Szczegóły”, „Responsywność i dostępność”.
- **Dowód:**
  - Szkic przejmuje standard formularza: boczna kolumna jest w DOM przed główną, a od 1280 px CSS przenosi ją na prawo (`apps/admin/src/views/ProductFormView.vue:229-232`, `xl:col-start-2 xl:row-start-1`).
  - Szczegóły zamówienia mają mieć „poniżej jedną (boczna nad główną)”.
  - ADMIN-DESIGN „Dostępność” (`docs/features/admin-design/spec.md:501`) wymaga kolejności fokusu zgodnej z układem i robi wyjątek tylko dla formularza produktu.
  - Szkic nie wskazuje szerokości z wyjątkiem ani kolejności w DOM dla trzech nowych ekranów.
- **Propozycja:** jawnie rozszerzyć wyjątek o formularze promocji i kodu oraz szczegóły zamówienia: od 1280 px Tab zaczyna od kolumny bocznej. Dopisać tę zmianę do listy edycji `admin-design/spec.md`. Alternatywa: przy dwóch kolumnach boczna kolumna po lewej.

**I2. Fokus po akcji, która ukrywa kartę „Akcje”** – techniczne
- **Sekcja:** „Szczegóły › Okno zmiany statusu”.
- **Sprzeczność:** szkic wymaga, żeby „po udanej akcji fokus był na nagłówku karty »Akcje«”, a jednocześnie „karta jest ukryta bez żadnej akcji”.
- **Kiedy karta znika:**
  - po anulowaniu zamówienia `new` (bez płatności nie ma zwrotu);
  - po „Oznacz zwrot jako wykonany” (anulowane i zwrócone nie mają `allowedTransitions`).
- Wtedy cel fokusu nie istnieje i fokus spada na `body`, co łamie regułę z „Responsywności i dostępności”.
- Brakuje też celu fokusu po 409: okno się zamyka, zamówienie wczytuje się ponownie, a wyzwalacz może zniknąć. Zachowanie 409 opisuje `apps/admin/CLAUDE.md`, sekcja `src/orders/`.
- **Propozycja:** gdy karta znika, fokus trafia na H1 albo na nagłówek karty „Zwrot pieniędzy” lub „Historia”. Po 409 fokus trafia na komunikat `conflict` (`tabindex="-1"`). Dodać oba przypadki do kryterium 10.

**I3. Testowe identyfikatory: spis nie istnieje, a wiersz grupy i karty zamówień ich nie mają** – techniczne
- **Sekcje:** „Dopowiedzenia standardów” (punkty „Testowe identyfikatory” i „Lista pogrupowana”), „Zamówienia › Lista”, A3.
- **Spis, do którego odsyła szkic, nie istnieje:** szkic pisze „spis w »Zachowanych funkcjach« tabelą, którą testy sprawdzają”, a tabela w tej sekcji opisuje funkcje, nie identyfikatory.
- **Wiersz grupy produktu:** dziś `name` jest wewnątrz pierwszego `price-row`/`stock-row` (`PricesView.vue:219-220`). Dwa helpery E2E na tym polegają:
  - `e2e/support/price-change.ts:30` filtruje `price-row` po `name`;
  - `e2e/support/catalog-admin.ts:146-147` używa `name` i `stock-row`; tego pliku i `e2e/tests/catalog-admin.spec.ts` nie ma w „Zakresie”.
  - Szkic nie mówi, czy wiersz grupy ma `price-row`, ani gdzie jest `name`.
- **`DataTable` nie obsługuje grup ani progu 640 px:**
  - ma jeden `rowTestid` i progi tylko `lg`/`md` (`apps/admin/src/components/list/DataTable.vue:56-58`);
  - przy kartach poniżej 640 px nie wiadomo, czy tabela i karty są razem w DOM (ukrywane CSS), czy renderowany jest jeden układ.
  - Przy obu w DOM `getByTestId("order-row")` liczy oba układy, co psuje `e2e/support/panel-orders.ts:38`, `order-management.spec.ts:138` i `panel-orders-url.spec.ts:30` (`allTextContents` liczy też ukryte).
- **Oś czasu:** zastępuje `history-change` („Opłacone → Wysłane”, `order-management.spec.ts:57,103`) samą etykietą statusu docelowego, a szkic nie podaje nowych identyfikatorów.
- **Propozycja:**
  - dodać tabelę identyfikatorów (zostają, nowe, usunięte);
  - wiersz grupy: `product-row` z `name`; `price-row`/`stock-row` tylko w wierszach wariantów;
  - `order-row` tylko w układzie widocznym (jeden układ według `matchMedia`) albo osobny `order-card`;
  - nazwać identyfikatory osi czasu;
  - dopisać `e2e/support/catalog-admin.ts` i `e2e/tests/catalog-admin.spec.ts` do „Zakresu”;
  - opisać rozszerzenie `DataTable` (wiersz grupy, próg 640 px).

**I4. Tabela pozycji zamówienia gubi kolumnę** – techniczne
- **Sekcja:** „Szczegóły › kolumna główna”.
- **Dziś:** Ilość, Cena brutto (jednostkowa), a przy `discount` dodatkowo Wartość przed rabatem i Rabat, na końcu Wartość (`OrderDetailView.vue:318-377`).
- **Szkic:** „ilość, cena przed rabatem, rabat, wartość; kolumny rabatu tylko przy `discount`”. Znika albo cena jednostkowa, albo wartość przed rabatem, a „cena przed rabatem” można czytać na dwa sposoby.
- To łamie zasadę „Migracja nie usuwa żadnej informacji”.
- **Propozycja:** wymienić kolumny wprost, z nagłówkami i identyfikatorami (`line-quantity`, cena jednostkowa, `line-gross-before-discount`, `line-discount`, `line-gross`), osobno dla zamówienia bez kodu i z kodem.

**I5. „Zakończ teraz”: brak opisu udanego zakończenia** – techniczne
- **Sekcje:** „»Zakończ teraz« i ponowny odczyt”, kryterium 9.
- Szkic opisuje komunikat tylko dla przypadku „dalej active po 5 próbach”.
- **Czego brakuje dla ścieżki „ponowny odczyt dał `ended`”:**
  - Komunikat „Promocja zakończona.” – dziś dostarcza go `success-message`, który epik usuwa (`usePromotionForm.ts:439`). Nie wiadomo, czy ma być toastem.
  - Kiedy fokus idzie na nagłówek „Status”: po `PUT` (przycisk zostaje wyłączony) czy po odczycie.
  - Co, gdy `PUT` od razu zwraca `ended` (kod to obsługuje).
- **Propozycja:**
  - toast „Promocja zakończona.” po odczycie `ended`;
  - fokus na „Status” zaraz po zamknięciu `ConfirmDialog` (karta zostaje, przyciski są wyłączone);
  - `ended` w odpowiedzi `PUT` kończy bez oczekiwania;
  - dopisać te przypadki do testów F7.

**I6. Promocja tylko do odczytu po zakończeniu: brak celu fokusu i treści karty** – techniczne
- **Sekcje:** „Formularz promocji” (tryb tylko do odczytu) i I5.
- Po przejściu w `ended` formularz przechodzi w tryb tylko do odczytu „bez akcji karty »Status«”.
- Szkic nie mówi, że karta „Status” z etykietą zostaje (warunek, żeby fokus na jej nagłówku był możliwy), ani gdzie pojawia się „dzisiejsza notatka” trybu tylko do odczytu.
- **Propozycja:** jedno zdanie, że karta „Status” zostaje z etykietą „Zakończona” i notatką, a fokus jest na jej nagłówku.

### Minor

- **M1 – produktowe (F4, „Formularz promocji”).** Po usunięciu ostatniego wybranego wariantu szkic przenosi fokus na poprzedni wiersz, a bez wierszy na przycisk otwierający wybór. Zaakceptowany opis w #462 mówi: „następny wiersz listy wybranych albo pole wyszukiwania”. **Rekomendacja:** wariant ze szkicu, bo pole wyszukiwania nie istnieje przy zamkniętym wyborze, ale zaznaczyć odejście od #462 do akceptacji właściciela.
- **M2 – techniczne (oś czasu).** `staffUsername` jest `nullable` w schemacie, a dziś `actorLabel` pokazuje wtedy „Pracownik” (`apps/admin/src/orders/format.ts:31-33`). Zdanie „bez nazwy »Pracownik«” jest niejasne: usunąć zapas czy tylko przedrostek? **Propozycja:** „login, a bez loginu »Pracownik«”.
- **M3 – techniczne (kryteria 4 i #261).**
  - Przy listach z grupami „pierwszy wiersz” to wiersz grupy bez akcji, a lista zamówień nie ma akcji wiersza: wskazać „pierwszy wiersz wariantu” i „status” dla zamówień.
  - Dla #261 podać mierzalny próg, np. wysokość komórki ≤ 1,5 × `line-height`.
- **M4 – techniczne (okna historii).** „Przy wąskim oknie” nie ma progu, a `hideBelow` działa według szerokości ekranu, nie okna. Podać próg (np. `lg`), przy 11 kolumnach stanu także przewijany region.
- **M5 – techniczne (porządki).**
  - `apps/admin/eslint.config.d.ts` deklaruje `LEGACY_FILES` i trzeba go usunąć albo poprawić razem z listą.
  - „Stan w adresie (`status`, `q`, `perPage`)” dla promocji: API promocji nie ma `q` (`openapi-admin.json`, `GET /promotions`), więc `q` dotyczy tylko kodów.
  - Przełącznik „Aktywny” w karcie „Status” kodu to pole formularza zapisywane paskiem, nie natychmiastowa akcja (jak inne akcje karty „Status”). Napisać to wprost.
  - Semantyka wiersza grupy dla czytników (np. `<th scope="rowgroup">`) nie jest określona.

### Werdykt
**DO POPRAWY** (6 × Important, 0 × Critical).

Pliki:
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/docs/features/admin-screens/spec.md
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/docs/features/admin-design/spec.md
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/apps/admin/src/components/list/DataTable.vue
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/apps/admin/src/views/OrderDetailView.vue
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/apps/admin/src/views/PricesView.vue
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/apps/admin/src/promotions/usePromotionForm.ts
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/apps/admin/src/orders/format.ts
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/apps/admin/eslint.config.d.ts
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/e2e/support/price-change.ts
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/e2e/support/catalog-admin.ts
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/e2e/support/panel-orders.ts
- <home>/Development/claude/shop/.claude/worktrees/admin-stage-d6ba37/e2e/tests/order-management.spec.ts