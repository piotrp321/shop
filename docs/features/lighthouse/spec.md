# LIGHTHOUSE – pomiar Core Web Vitals w CI

Issues: #289
Zakres: `e2e/lighthouse`, `e2e/package.json`, `e2e/package-lock.json`, `e2e/entrypoint.sh`, `e2e/.gitignore`, `infra/docker-compose.yml`, `Makefile`, `.github/workflows/ci.yml`, `CLAUDE.md`, `docs/features/walking-skeleton/spec.md`

Punkt 4 „Zakresu MVP” (SEO sklepu), sekcja „Sklep: renderowanie i SEO” w `docs/vision.md`, punkt „Wydajność”: Core
Web Vitals jako wymaganie, pomiar przez Lighthouse w CI. Funkcja mierzy trzy strony sklepu na buildzie produkcyjnym
lokalnie (`make lighthouse`) i w jobie `e2e` CI. Blokuje tylko wynik SEO. Pozostałe progi ostrzegają i dają wartości
wyjściowe do decyzji o progach blokujących (#290).

## Cel i zakres

- Pomiar Lighthouse trzech adresów sklepu na danych demo, na buildzie produkcyjnym (`STORE_MODE=production`).
- Cel `make lighthouse` w głównym `Makefile`.
- Krok w jobie `e2e` CI, raport jako artefakt joba i mediany w podsumowaniu kroku.

## Poza zakresem

- poprawa wydajności stron, gdy pomiar nie spełnia progów (osobne zgłoszenie po #290);
- decyzja o blokujących progach LCP, CLS, Performance i TBT oraz o liczbie przebiegów w PR: #290;
- pomiar w terenie (RUM, CrUX) i serwer LHCI (publiczny ani własny);
- profil desktopowy, inne strony (wyszukiwarka, koszyk, konto, panel).

Epik nie twierdzi, że sklep spełnia Core Web Vitals w terenie: mierzy laboratoryjnie, na runnerze CI.

## Decyzje

Ustalenia D1–D5 zatwierdził użytkownik (issue #289).

| ID | Decyzja |
|---|---|
| D1 | Narzędzie: `@lhci/cli` w dokładnej wersji (0.16.0) jako `devDependency` w `e2e/package.json`. Działa w kontenerze `e2e` z Chromium obrazu Playwright: `CHROME_PATH` z `chromium.executablePath()` pakietu Playwright, flagi Chrome `--headless=new --no-sandbox`. Bez nowego obrazu i bez nowej usługi. |
| D2 | Raporty wyłącznie lokalnie (`upload.target: filesystem`) i w artefaktach GitHub Actions, nigdy w publicznym magazynie LHCI (`temporary-public-storage`) ani na serwerze LHCI. |
| D3 | Profil mobilny (domyślny profil Lighthouse: `formFactor: mobile`, throttling symulowany), 3 przebiegi na adres. Przed pomiarem rozgrzewka: jedno żądanie każdego adresu (ISR karty produktu i listingu). |
| D4 | Każda asercja ma jawne `aggregationMethod: "median"` (domyślne `optimistic` bierze najlepszy przebieg). |
| D5 | Blokuje (`error`) tylko wynik SEO ≥ 0,9 (`categories:seo`, `minScore: 0.9`). Ostrzeżenia (`warn`): wynik Performance ≥ 0,9, LCP ≤ 2500 ms, CLS ≤ 0,1, TBT ≤ 200 ms. Progi zmienia wyłącznie decyzja użytkownika. |
| D6 | Mierzone adresy (stałe w konfiguracji, ścieżki względem adresu sklepu `http://localhost:${STORE_PORT}`): strona główna `/`, kategoria `/c/turystyka/biwak/namioty`, karta produktu `/p/namiot-turystyczny-norvane-biwak-2`. Kategoria i produkt pochodzą z `apps/api/fixtures/catalog.yaml` (slug ze slugifikacji nazwy, jak w `DemoCatalogExpander`), produkt ma zdjęcia, a żaden test E2E ich nie kupuje, nie zmienia ceny, stanu, promocji ani nie dodaje do tej kategorii produktów (`newProduct` w `e2e/support/catalog-admin.ts` używa kategorii „Trening”). Pomiar po `make e2e` widzi więc te same dane co po `make seed`. |
| D7 | Tryb sklepu: `make lighthouse` sprawdza tryb działającego kontenera `store` (`docker compose exec -T store printenv STORE_MODE`), nie zmienną środowiska `make`. Inna wartość niż `production` albo sklep, który nie działa: błąd z komunikatem, który odróżnia te przypadki, bez pomiaru. `make` kończy każdy nieudany przepis kodem 2 niezależnie od kodu komendy, więc kody celu to 0 (sukces) i 2 (błąd z komunikatem); dokładne kody (1 rozgrzewka, kod asercji) daje `run.sh` i widać je w linii `Error N` z `make`. Uzasadnienie: pomiar `next dev` daje wartości bez znaczenia, a zmienna `make` nie mówi, w jakim trybie działa sklep (CLAUDE.md, „Środowisko”). |
| D8 | Przebieg pomiaru jest w skrypcie `e2e/lighthouse/run.sh`, montowanym do kontenera razem z konfiguracją, nie w `e2e/entrypoint.sh`: poprawka pomiaru nie zmienia klucza obrazu `e2e` (`E2E_IMAGE_FILES`) i nie wymaga przebudowy. `entrypoint.sh` dostaje tylko przejście do tego skryptu dla argumentu `lighthouse` po postawieniu przekierowań socat. |
| D9 | `run.sh` woła `npx lhci autorun --config=/app/lighthouse/lighthouserc.cjs --failOnUploadFailure` (collect, assert, upload): bez tej opcji błąd zapisu raportu jest tylko ostrzeżeniem. `autorun` zapisuje raport także wtedy, gdy asercja SEO nie przechodzi, a kod wyjścia pomiaru to kod `lhci`. Po pomiarze `run.sh` sprawdza, że `manifest.json` jest w katalogu wyników; brak to kod 1, także gdy asercja przeszła. |
| D10 | Mediany liczy mały skrypt Node bez zależności (`e2e/lighthouse/summary.mjs`). Wejście: `manifest.json`, który zapisuje `lhci upload` (`url` i `jsonPath` każdego przebiegu). Dla każdego adresu mediana każdej z pięciu metryk z jego przebiegów: wynik SEO i Performance w skali 0–100, LCP i TBT w ms (liczby całkowite), CLS z trzema miejscami po przecinku; w tabeli liczba przebiegów adresu. Przebieg bez pliku albo z wartością `null` (błąd Lighthouse) nie wchodzi do mediany danej metryki; metryka bez żadnej wartości to „—”. Brak `manifest.json`, manifest, który nie jest poprawnym JSON-em, albo pusty: komunikat bez tabeli, kod 0. Zapisuje tabelę Markdown jako `medians.md` w katalogu wyników i wypisuje ją na standardowe wyjście. Kod skryptu nie wpływa na kod wyjścia pomiaru (`run.sh` go ignoruje): blokuje tylko asercja. Testy `node --test` (`e2e/lighthouse/summary.test.mjs`) uruchamia `make e2e-check` (bez usług). |

## Przebieg `make lighthouse`

Warunek: stos działa (`STORE_MODE=production make up`, `make seed`).

1. Cel sprawdza tryb sklepu (D7). Błąd: komunikat na stderr (inny tryb albo sklep nie działa) i niezerowy kod `make`,
   bez uruchamiania kontenera `e2e` i bez zmian w katalogu wyników.
2. Cel tworzy `e2e/lighthouse-results` i uruchamia kontener `e2e` tak jak `make e2e`
   (`docker compose --profile e2e run --rm $(E2E_BUILD) $(E2E_FLAGS) e2e lighthouse`), więc `E2E_FLAGS=-T E2E_BUILD=`
   z CI działają tak samo.
3. `e2e/entrypoint.sh` sprawdza dostępność usług i stawia przekierowania socat jak przed testami Playwrighta, a dla pierwszego argumentu
   `lighthouse` przechodzi do `run.sh` (D8) zamiast Playwrighta. Pozostałe wywołania (`make e2e`, `make e2e-agent`,
   `npx` przez `--entrypoint`) przechodzą do Playwrighta albo do wskazanego polecenia.
4. `run.sh`:
   - czyści katalog wyników (`/app/lighthouse-results`), żeby mediany nie mieszały przebiegów z różnych uruchomień;
   - rozgrzewka: jedno żądanie GET każdego adresu z D6; odpowiedź inna niż 200 kończy pracę kodem 1 z adresem
     i statusem w komunikacie, bez pomiaru;
   - pomiar według D9, potem sprawdzenie `manifest.json` (brak: kod 1);
   - `summary.mjs` (D10), także gdy asercja się nie udała;
   - kod wyjścia według D9 (0, gdy mediana SEO każdego adresu ≥ 0,9; ostrzeżenia nie zmieniają kodu).
5. Raport w `e2e/lighthouse-results/` na hoście: raporty HTML i JSON przebiegów, `manifest.json` i `medians.md`.

Compose montuje `e2e/lighthouse` tylko do odczytu (`/app/lighthouse`) i `e2e/lighthouse-results` do zapisu
(`/app/lighthouse-results`), jak `tests` i `test-results`. `e2e/lighthouse-results/` jest w `e2e/.gitignore`. Klucz
obrazu `e2e` w GHCR (`E2E_IMAGE_FILES` w `ci.yml`) zależy od `e2e/Dockerfile`, `package.json`, `package-lock.json`
i `entrypoint.sh`, a nie od `e2e/lighthouse`.

## CI

- Cel `lighthouse` jest na liście `CI_AFTER_E2E` (`make/e2e.mk`). Job `e2e` uruchamia go krokiem `make ci-after-e2e`
  (`RUN="docker compose exec -T" E2E_FLAGS=-T E2E_BUILD=`, `if: env.FULL_CI == 'true'`) po kroku `make e2e`. Cele tej
  listy działają także po błędzie jednego z nich, więc niespełniona asercja nie pomija testów `init-env.sh` ani
  pozostałych sprawdzeń. Bez nowego joba i bez nowego wymaganego checka: blokujący wynik SEO robi czerwony job `e2e`.
  Gdy `make e2e` się nie udał, pomiar nie rusza (domyślne zachowanie kroków).
- Krok „Lighthouse medians in the step summary” z `if: always() && env.FULL_CI == 'true'` dopisuje `medians.md` do
  `$GITHUB_STEP_SUMMARY` (gdy plik istnieje, także po błędzie asercji), żeby mediany były widoczne w podsumowaniu
  runu bez pobierania artefaktu.
- Krok wysyłania artefaktu `lighthouse-results` (ścieżka `e2e/lighthouse-results`) z `if: always() &&
  env.FULL_CI == 'true'` i `if-no-files-found: ignore`: raport trafia do artefaktów zawsze, gdy pomiar ruszył, także
  przy niespełnionej asercji.
- `make e2e-check` (krok jobu `e2e` przed uruchomieniem stosu) uruchamia też testy `summary.mjs` (D10).

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Sklep w trybie `dev` | `make lighthouse`: komunikat, że pomiar wymaga `STORE_MODE=production make up`, kod `make` 2, bez pomiaru |
| Kontener `store` nie działa | komunikat, że trzeba uruchomić stos, kod `make` 2, bez pomiaru |
| Usługa nieosiągalna z kontenera `e2e` | komunikat `entrypoint.sh`, kontener kończy się kodem 1 |
| Adres z D6 nie zwraca 200 (np. zmienione dane demo) | `run.sh` kod 1, komunikat z adresem i statusem, bez pomiaru |
| Błąd zapisu raportu (`lhci upload`) albo brak `manifest.json` | `run.sh` kod ≠ 0, job `e2e` czerwony |
| Mediana SEO adresu < 0,9 | kod ≠ 0, raport i `medians.md` zapisane, w CI artefakt i podsumowanie; job `e2e` czerwony |
| Performance, LCP, CLS albo TBT poza progiem | ostrzeżenie w wyjściu `lhci`, kod 0 |
| Chromium nie startuje (błąd `CHROME_PATH`, flag) | kod ≠ 0 z błędem `lhci collect` |
| Przebieg z błędem Lighthouse (wartości `null`) | asercja według `lhci`; w `medians.md` przebieg pominięty w medianie metryki (D10) |

## Kryteria akceptacji

1. Po `STORE_MODE=production make up` i `make seed` cel `make lighthouse` mierzy trzy adresy z D6, po 3 przebiegi,
   po rozgrzewce, i kończy się kodem 0, gdy mediana SEO każdego adresu ≥ 0,9.
2. Konfiguracja ma profil mobilny, 3 przebiegi, `upload.target: filesystem`, asercje z D5, każda z
   `aggregationMethod: "median"`; tylko SEO ma poziom `error`.
3. Sklep w trybie `dev` i sklep zatrzymany: niezerowy kod `make` i komunikat właściwy dla przypadku, bez uruchomienia
   kontenera `e2e`.
4. Adres, który nie zwraca 200 w rozgrzewce: `run.sh` kończy się kodem 1 (linia `Error 1` w wyjściu `make`)
   z adresem i statusem, bez pomiaru. Pomiar bez `manifest.json` po `lhci`: kod ≠ 0.
5. Niespełniona asercja SEO (sprawdzana progiem nie do spełnienia): kod ≠ 0, a raport, `manifest.json` i `medians.md`
   są w `e2e/lighthouse-results/`.
6. `make e2e` i `make e2e-agent` nie są dotknięte pomiarem (argument inny niż `lighthouse` idzie do Playwrighta).
7. Job `e2e` CI uruchamia pomiar po `make e2e` i `init-env.sh tests`, wysyła artefakt `lighthouse-results` także po
   niespełnionej asercji i pokazuje mediany w podsumowaniu kroku; lista jobów i wymaganych checków bez zmian.
8. `summary.mjs` ma testy `node --test` uruchamiane w `make e2e-check`: mediana z 3 przebiegów dla każdej z pięciu
   metryk w jednostkach z D10, przebieg bez pliku i z wartością `null` (pominięty, „—” bez żadnej wartości), brak
   i pusty manifest (komunikat, kod 0).
9. `e2e/lighthouse-results/` nie trafia do gita.
10. `CLAUDE.md` i `docs/features/walking-skeleton/spec.md` opisują cel, krok CI i zależność.

## Testy

- **Skrypt median:** `node --test` w obrazie `e2e` (`make e2e-check`), na spreparowanych `manifest.json` i plikach lhr.
- **Cel i skrypt pomiaru:** komendy z kryteriów 1, 3, 4 i 5 na lokalnym stosie, na ścieżce poprawnej i ścieżkach
  błędu. Stos AO startuje w trybie `dev` (`.ao/setup`), więc przed pomiarem trzeba uruchomić
  `STORE_MODE=production make up` (pełny `next build`) i `make seed`.
- **CI:** job `e2e` (kryterium 7); `actionlint` w `make check` sprawdza workflow.

## Ryzyka i ograniczenia

- Wyniki na współdzielonych runnerach GitHub są niestabilne; dlatego tylko SEO blokuje, a mediana z 3 przebiegów
  ogranicza wpływ pojedynczego przebiegu.
- Throttling symulowany na runnerze CI nie odpowiada prawdziwemu telefonowi; mediany są wartością wyjściową dla #290,
  nie dowodem spełnienia Core Web Vitals.
- Pomiar wydłuża job `e2e` (ścieżka krytyczna CI każdego pełnego PR i zadania AO) o 9 przebiegów Lighthouse
  i rozrost obrazu `e2e` o `@lhci/cli`; ewentualne skrócenie (mniej przebiegów w PR) to
  decyzja w #290. Limit joba to 45 min.
- Zmiana danych demo (kategoria albo produkt z D6) albo test E2E, który zacznie zmieniać te dane, psuje albo
  zniekształca pomiar; rozgrzewka wykrywa brak strony (kod 1), ale nie zmianę zawartości.
- Pomiar idzie przez przekierowanie socat w kontenerze, bez Varnisha przed sklepem (jak w compose); w produkcji
  sklep stoi za ingressem i Cloudflare, więc TTFB może się różnić.
