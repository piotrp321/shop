## Review ADMIN-DESIGN-FIX-1, tryb DELTA (runda poprawek 1)

**SHA:** HEAD `4da204a47172c312405b3aa15f3e94a20b5925c8`, diff `f7da6219..4da204a4`. Drzewo było czyste, a SHA nie zmienił się w trakcie review.

**Werdykt: kod passed. Formalnie CHANGES_REQUIRED z jednego powodu procesowego:** RED nowego testu E2E odtworzono po fakcie, a w stanie zostało `tdd.status: verified`. Kod nie wymaga zmian, wystarczy poprawić status TDD i opis w PR (szczegóły w kontroli 3).

### I1: naprawione
- **Zmiana w kodzie:** `apps/admin/src/layout/usePageFocus.ts:71`. Warunek wejścia to teraz `if (!force && !isFree(area))`. Powłoka bierze fokus tylko z `body`/`null`/`<main>` albo po wyborze pozycji w szufladzie.
- **Sonda Playwright na tym HEAD.** Jednorazowa, w kontenerze `e2e` z `--rm`, POST podstawiony przez `page.route`, więc bez zapisu w bazie. Pliki sondy usunięte, repo nietknięte.
  - **Tworzenie produktu, fokus w polu `name` w trakcie żądania:** 3 na 3 próby fokus zostaje w `INPUT name` po przejściu na `/products/<id>`. Wcześniej trafiał na H1.
  - **Zwykłe utworzenie (Enter na „Zapisz”):** 2 na 2 próby fokus jest na H1 produktu, zgodnie ze spec „Po zapisie”.
  - **Okruszek „Produkty” z formularza:** fokus na H1 „Produkty”. Link znika, więc fokus jest wolny i trafia na H1.
- **Kod rabatowy:** pokrywa go nowy E2E, który sprawdza `toBeFocused` na `success-message` po przejściu na `/discount-codes/<id>`.

### M1: naprawione
Test `e2e/tests/admin-design.spec.ts:554` wchodzi z linku „Aktywne promocje” w treści pulpitu (`todo.ts:60`). Link znika razem z pulpitem, a `expectFocusOnHeading(page, "Promocje")` sprawdza fokus na H1 ekranu PrimeVue, któremu `tabindex` nadaje powłoka. Asercja jest teraz prawdziwa.

### Czy usunięcie `reused` odebrało coś wymaganego w spec
Nie.
- **Spec („Fokus, gdy wyzwalacz zniknął”):** H1 nowej strony należy się wtedy, gdy wyzwalacz zniknął, a „gdy wyzwalacz nadal istnieje, fokus wraca na niego”. Fokus na elemencie, który istnieje dalej, to właśnie ten drugi przypadek.
- **Gdzie `reused` działał:** tylko przy tej samej instancji widoku na innej ścieżce, czyli `/x/new` → `/x/:id` i `/products/1` → `/products/2`. W panelu nie ma linku do innego rekordu wewnątrz formularza, więc praktycznie chodziło tylko o tworzenie, czyli o błąd I1.
- **Przejścia między różnymi widokami:** stare elementy są usuwane przy renderze, bo `RouterView` nie ma `KeepAlive` ani przejść. Fokus spada wtedy na `body` przed `nextTick` i pozostałe ścieżki dalej trafiają na H1. Potwierdzają to 41 testów `admin-design.spec.ts` u orkiestratora i moja sonda (okruszek).

### Testy zmienione w rundzie
- **Zastąpiony test Vitest:** „moves the focus to the H1 when only a parameter changes” powstał w tym tasku (`f7da6219`), nie istnieje w `base_sha` `bd959e27`. Zmiana oczekiwania z H1 na pole wynika ze spec („wyzwalacz nadal istnieje”). To nie osłabienie.
- **Test z `#own-link`:** nie istnieje ani w `f7da6219`, ani w `4da204a4`. W diffie nic takiego nie usunięto, więc opis koordynatora jest w tym punkcie nieścisły. Liczba testów w pliku wzrosła z 12 do 13.
- **Nowy test Vitest „slow guard”:** odtwarza warunek I1, czyli asynchroniczny `beforeEach` i fokus ustawiony przed końcem nawigacji tej samej instancji. Jest w porządku.

### Jakość nowego E2E (stub)
Stub jest stabilny.
- `page.route` działa tylko na tej stronie, więc nie wycieka do współdzielonego kontekstu.
- Dopasowanie `**/api/v1/admin/discount-codes` łapie tylko POST bez query, a reszta idzie przez `fallback`.
- Obiekt `created` ma wszystkie pola `DiscountCodeResponse` (porównałem ze `schema.d.ts`).
- Fikcyjny UUID nie powoduje GET: `useDiscountCodeForm.open()` pomija wczytanie, gdy `view.value?.id === id` (`useDiscountCodeForm.ts:208-211`). Nie ma więc 404 ani błędu wczytania.
- Daty: `startsAt` w 2030 roku jest bezpieczne.
- `waitForTimeout(500)` przed `toBeFocused` łapie późne przejęcie fokusu. To świadoma decyzja, nie źródło niestabilności.

### Trzy kontrole (zakres poprawek)
- **Istniejące testy:** bez uwag. Żaden test z `base_sha` nie został zmieniony, usunięty ani wyłączony. Zmieniono tylko testy dodane w tym tasku i każdą zmianę uzasadnia spec.
- **Zakres:** bez uwag. Zmienione są tylko `usePageFocus.ts`, `apps/admin/CLAUDE.md`, `AppLayoutFocus.test.ts` i `admin-design.spec.ts`, wszystkie z I1 i M1. Opis w `CLAUDE.md` jest zgodny z kodem.
- **RED przed implementacją:**
  - **Vitest dla I1:** RED o 19:48:32. `head` f7da6219, `dirty` zawiera tylko plik testu, wynik 2 failed z asercji (`expect(active()).toBe(message)`). GREEN o 19:48:45. To prawdziwa faza RED.
  - **Nowy E2E „legacy form…”:** RED o 19:49:34 i 19:50:44 ma `dirty` bez `usePageFocus.ts`. We wcześniejszym wpisie GREEN o 19:48:45 ten plik był już w drzewie, więc zniknął tylko na czas RED. Według reguły to RED odtworzony po fakcie, czyli **IMPORTANT (proces)**: `tdd.status` w `delivery-state.json` to `verified`, a nie `lost_verified_on_base`. Uzasadnienie wagi: dziennik nie dowodzi prawdziwej fazy RED dla tego testu.
    - Okoliczność łagodząca: to samo zachowanie ma prawdziwą fazę RED w Vitest, a koordynator zapowiada ujawnienie w PR.
    - Naprawa bez zmian w kodzie: oznaczyć RED tego testu jako `lost_verified_on_base` (albo odpowiednik w AO) i opisać to w PR.
  - **Test M1:** w RED przechodził (1 passed). To oczekiwane: poprawia jakość testu zachowania z poprzedniej rundy, a nie dodaje nowego zachowania.

### Lista kontrolna (zakres poprawek)
- **Błędy API:** nie dotyczy.
- **Komunikaty po polsku:** sprawdzone, brak nowych tekstów.
- **Unicode:** nie dotyczy.
- **Klawiatura, focus, etykiety:** sprawdzone sondą i testami. Brak uwag.
- **Stany:** nie dotyczy.
- **Przypadki brzegowe:** sprawdzone: tworzenie z fokusem w polu, tworzenie zwykłe, wolny fokus po okruszku. Szuflady runda nie zmienia.

### Model zagrożeń
Nie dotyczy (zmiana tylko w UI).

### Pliki
- <home>/Development/claude/shop-ADMIN-DESIGN-FIX-1/apps/admin/src/layout/usePageFocus.ts
- <home>/Development/claude/shop-ADMIN-DESIGN-FIX-1/apps/admin/tests/AppLayoutFocus.test.ts
- <home>/Development/claude/shop-ADMIN-DESIGN-FIX-1/e2e/tests/admin-design.spec.ts
- <home>/Development/claude/shop-ADMIN-DESIGN-FIX-1/apps/admin/CLAUDE.md
- <home>/.local/state/ao-admin-design/tasks/ADMIN-DESIGN-FIX-1/delivery-state.json.evidence.jsonl