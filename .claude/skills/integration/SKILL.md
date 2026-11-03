---
name: integration
description: Globalna integracja epika po zmergowaniu zadań do gałęzi epika: Architecture Review, adversarial QA i bramki jakości całego diffu, raport i wynik JSON dla AO.
argument-hint: "Epik <id>: specyfikacja `<ścieżka>`, plan `<ścieżka>`, gałąź `<gałąź>`, HEAD `<sha>`, tryb FULL|DELTA, raport `<ścieżka>`."
model: opus
effort: high
disable-model-invocation: true
---

Zintegruj epik opisany w: $ARGUMENTS. Jesteś orkiestratorem integracji: oceniasz **całość** epika po tym, jak
`/delivery` dostarczył i zmergowano wszystkie jego zadania do gałęzi epika. Pracę merytoryczną robią agenci
`architecture-reviewer` i `qa-adversarial`; ty ustalasz zakres, uruchamiasz bramki, weryfikujesz zgłoszenia
i składasz raport. Stosuj `CLAUDE.md` (także lokalne pliki aplikacji) i `docs/vision.md`.

## Wejście

Skill uruchamia AO headless, z `cwd` = worktree epika na HEAD jego gałęzi. Prompt ma postać:

`/integration Epik <epic_id>: specyfikacja `<spec_path>`, plan `<plan_path>`, gałąź `<branch>`, HEAD `<sha>`, tryb FULL|DELTA, raport `<report_path>`.`

Opcjonalnie prompt może podawać `poprzedni HEAD `<sha>``. Nie ma użytkownika do pytań: brak rutynowych danych uzupełnij
z repo, a decyzję spoza granic skilla zwróć jako bloker (sekcja „Blokery”).

## Granice odpowiedzialności

- **Robisz:** globalny Architecture Review, niezależny adversarial QA, bramki jakości (`make check`, `make test`,
  `make e2e`), weryfikację zgłoszeń, raport do `<report_path>` i wynik JSON.
- **Nie robisz:** kodu i poprawek (naprawy to osobne zadania `/delivery` zlecane przez AO), `git commit`, `git push`,
  `git merge`, zmiany gałęzi, `gh pr`, merge, deploymentu, sprzątania stosu (`.ao/teardown` należy do AO). Jedyny
  plik, który zapisujesz, to raport. Testy napisane przez QA zostają w raporcie jako propozycje albo w polu
  `evidence` znaleziska; nie trafiają do repo.
- **Źródłem prawdy** jest diff `origin/main...HEAD`, specyfikacja i plan, nie raporty z PR zadań ani opinie
  poprzednich review.

## Proces

1. **Preflight.** Sprawdź: `git rev-parse HEAD` równa się HEAD z promptu (inaczej bloker `technical`),
   `git branch --show-current` to gałąź z promptu, `git status --porcelain` jest puste (zapamiętaj to: na końcu ma
   być identycznie, z wyjątkiem raportu poza repo). Brudne drzewo przed pracą, pusty `git branch --show-current`
   (detached HEAD), nieudany `git fetch origin main` i `<report_path>` wewnątrz repo to bloker `technical`. Wykonaj `git fetch origin main`. Przeczytaj spec i plan; z manifestu
   planu (jeden blok ```` ```json ````) weź listę zadań, ich `scope` i kryteria akceptacji. Ustal stos: AO przygotował go
   przez `.ao/setup` (zmienne `AO_COMPOSE_PROJECT`, `AO_PORT_BASE`, plik `.env` w worktree). Sprawdź
   `docker compose ps`; gdy stos nie działa, a zmienne `AO_*` są ustawione, uruchom `.ao/setup` raz. Gdy nadal nie działa:
   bloker `technical`. Nie dotykaj kontenerów ani portów spoza własnego projektu Compose.
2. **Zakres.** `FULL`: cały diff `git diff origin/main...HEAD` (statystyka `--stat`, lista commitów
   `git log origin/main..HEAD`; mapuj pliki i commity na zadania planu po treści commitów i `scope`, gdy nie da się
   jednoznacznie, `task_id` ustaw na `null`). `DELTA`: poprzedni HEAD to `poprzedni HEAD` z promptu, a gdy go brak,
   `Przegląd HEAD:` z najnowszego (wg czasu modyfikacji) innego pliku `*.md` w katalogu `<report_path>`, który jest przodkiem
   HEAD (`git merge-base --is-ancestor`). Zakres DELTA to `git diff <poprzedni>..HEAD` **oraz skutki**: kod, który
   te zmiany wywołuje lub którego kontrakty zmieniają (grep użyć, testy, OpenAPI, klienty). Bez wiarygodnego
   poprzedniego HEAD pracuj jak w `FULL`, napisz to w raporcie i zaznacz, że poprzednich znalezisk nie dało się
   porównać (lista do sprawdzenia pochodzi z tego samego poprzedniego raportu). Bramki uruchamiasz zawsze w całości.
3. **Review równolegle.** W jednej wiadomości uruchom dwa agenty (każdy dostaje HEAD SHA, zakres z kroku 2, ścieżki
   spec i planu, listę zadań; **nie przekazuj** im swoich ani cudzych wniosków):
   - `architecture-reviewer`: zgodność z DDD, architekturą heksagonalną, `CLAUDE.md` i specyfikacją całego epika,
     spójność kontraktów między zadaniami, zbędna złożoność, dublowanie.
   - `qa-adversarial`: błędy funkcjonalne i bezpieczeństwa na styku zadań, transakcje, współbieżność, przypadki
     brzegowe, kryteria akceptacji ze specyfikacji, które żadne zadanie nie sprawdza end-to-end. Pracuje we
     własnym worktree (bez `.env`; kontenery stosu AO montują źródła worktree epika), więc **nie uruchamia
     `make` ani `docker`**, ale stos AO testuje jako czarną skrzynkę przez HTTP. Przekaż mu w zleceniu:
     - Adresy stosu z `.env` worktree epika (porty, które `.ao/setup` zapisuje od `AO_PORT_BASE`; bez wpisu
       obowiązuje domyślny port z `infra/docker-compose.yml`), wszystkie dostępne tylko z `127.0.0.1`:
       API `http://127.0.0.1:<API_PORT>`, Keycloak `http://localhost:<KEYCLOAK_PORT>`, panel
       `http://localhost:<ADMIN_PORT>`, sklep `http://localhost:<STORE_PORT>`. Przed zleceniem sprawdź
       `curl -fs --max-time 5 http://127.0.0.1:<API_PORT>/health`; gdy nie odpowiada, napisz QA, że stos jest
       niedostępny.
     - Dostęp do `/api/v1/admin`: token realmu `backoffice` z klienta publicznego `admin` (authorization code
       z PKCE S256, `redirect_uri` `http://localhost:<ADMIN_PORT>/`, formularz logowania Keycloak z kodem TOTP;
       direct grant jest wyłączony). Konta, hasła i sekrety TOTP to dane demo z `e2e/support/accounts.ts`
       (realm `infra/keycloak/realms/backoffice.json`). Kod TOTP jest jednorazowy, a token krótko ważny: jedno
       logowanie na konto, ten sam token we wszystkich żądaniach, także równoległych, nowy po wygaśnięciu.
     - Dostęp do `/api/v1` klienta: token realmu `shop` wymaga sekretu klienta `store` z `.env.local`, którego
       QA nie czyta. Scenariusze klienta wykonuje przez sklep (logowanie kontem demo z `e2e/support/accounts.ts`)
       albo zapisuje jako propozycję testu. Publiczne `GET` (`apps/api/config/packages/security.yaml`) nie
       wymagają tokenu.
     QA może uruchamiać przeciw tym adresom czarnoskrzynkowe skrypty HTTP (`curl`, `python3` z biblioteką
     standardową), także scenariusze współbieżne (np. 16 równoległych `PUT`). Zapisuje tylko dane, które odtwarza
     `make seed` (katalog, ceny, stany i rezerwacje); nie zakłada kont, nie zmienia Keycloaka ani konfiguracji
     usług, nie czyta `.env.local`, niczego nie zapisuje w worktree epika, bez commitów i pushy. Gdy stos jest
     niedostępny, pracuje statycznie (kod, samodzielne skrypty bez Dockera) i pisze to w raporcie. Raport QA
     oddziela **wykonane** (scenariusz uruchomiony przeciw stosowi: komenda lub skrypt i wynik) od **propozycji
     testu** (nieuruchomionej). Bramki uruchamiasz sam, po jego zakończeniu.
   W trybie DELTA przekaż zakres DELTA i listę znalezisk z poprzedniego raportu do sprawdzenia, czy zostały naprawione.
4. **Bramki.** Po zakończeniu obu agentów, sekwencyjnie, z limitem czasu każdej (np. `timeout`/Bash `timeout`):
   `make check RUN="docker compose exec -T"`, `make test RUN="docker compose exec -T"`, potem
   `make seed RUN="docker compose exec -T"` i `make e2e E2E_FLAGS=-T` (sesja nie ma terminala, więc bez `-T` komendy padają).
   Zapisy QA trafiły do bazy dev stosu. `make check` nie czyta danych, a `make test` działa na osobnych zasobach
   testowych (baza `shop_test`, indeks `test_products`, vhost `test`, Redis DB 1), więc jedyną bramką zależną od
   danych dev jest `make e2e`; `make seed` (reset katalogu, cen, stanów i rezerwacji) stoi bezpośrednio przed nią
   i nie przesuwaj go. Wszystkie uruchamiasz nawet po czerwonej wcześniejszej bramce. W wyniku nazwy bramek to `make check`, `make test`,
   `make e2e`. Zapisz kod wyjścia i koniec wyjścia każdej. Czerwona bramka to znalezisko
   (domyślnie `Important`, z `evidence` = komenda i końcówka wyjścia; `Critical` przy utracie danych lub luce
   bezpieczeństwa). `make e2e`, które padło, powtórz raz: gdy przejdzie, zapisz `passed` i znalezisko `Minor`
   („niestabilny test e2e”, z nazwą testu). Awaria środowiska (stos nie odpowiada, brak Dockera, brak miejsca) to
   nie błąd kodu: pozostałe bramki oznacz `skipped` i zwróć bloker `technical`.
5. **Weryfikacja.** Zgłoszenia agentów są hipotezami. Każde `Critical` i `Important` sprawdź w kodzie lub
   odtwórz komendą, zanim trafi do wyniku; niepotwierdzone, niepewne i stylistyczne wpisz do raportu jako uwagi
   albo odrzuć, nie jako znaleziska. Scal duplikaty. Waga: `Critical` utrata danych lub poważna luka
   bezpieczeństwa; `Important` błędne działanie w realnym scenariuszu, naruszenie kryterium akceptacji albo zasady
   z `CLAUDE.md` / architektury, którą da się naprawić; `Minor` przypadek brzegowy o niewielkim wpływie lub drobna jakość. Nie zawyżaj wagi.
   Werdykt `REWORK` albo „decyzja do rewizji” architekta, który wymaga decyzji użytkownika (sprzeczność ze
   specyfikacją, nowa zależność, zmiana granic modułów, świadome odstępstwo od `CLAUDE.md`), to bloker `architecture`, nie znalezisko; naprawialne
   naruszenie jest znaleziskiem.
6. **Stabilność znalezisk.** AO liczy odcisk znaleziska z `severity`, znormalizowanego `title` i posortowanych
   `files`, i przerywa pętlę napraw, gdy ten sam odcisk wraca po rundzie naprawczej. Dlatego: `title` opisuje problem,
   nie szczegół przebiegu (bez SHA, numerów linii i liczb); `files` to pliki, w których trzeba poprawić; ten sam
   niezmieniony problem zgłaszaj w kolejnych rundach z identycznym `title`, `severity` i `files`; nie przemianowuj
   ani nie dziel problemu, żeby uniknąć odcisku. Naprawiony problem znika z wyniku.
7. **Raport.** Zapisz Markdown po polsku do `<report_path>` (tworząc katalog, jeśli trzeba; ścieżka leży poza repo,
   nie dodawaj jej do Git). Pierwsze linie: `Epik:`, `Gałąź:`, `Przegląd HEAD: <sha>`, `Tryb:`, `Zakres:` (w DELTA
   z poprzednim HEAD). Dalej: werdykt, znaleziska (id, waga, zadanie, plik, dowód, sugerowana naprawa), bramki
   (komenda, kod wyjścia, koniec wyjścia), werdykt i uwagi architekta, wyniki QA w dwóch grupach: **wykonane**
   przeciw stosowi (scenariusz, komenda lub skrypt, wynik) i **propozycje testu** (kod w blokach), a gdy QA
   pracował statycznie, to zdanie z powodem, niepotwierdzone ryzyka, co sprawdzono bez uwag, bloker(y). Raport jest dowodem dla AO i
   użytkownika, więc nie wklejaj pełnych logów.
8. **Wynik.** Złóż JSON według kontraktu poniżej, zapisz go do pliku tymczasowego poza repo i zwaliduj:

   ```bash
   python3 .claude/skills/integration/integration_result.py validate <plik.json> --head <sha> --epic <epic_id> --mode <FULL|DELTA>
   ```

   Popraw każdy zgłoszony problem. Sprawdź `git status --porcelain`: ma być tak samo jak w preflighcie. Na końcu
   odpowiedzi (po krótkim podsumowaniu po polsku) wypisz dokładnie zwalidowany JSON w **ostatnim** bloku
   ```` ```json ````; po nim nic.

## Status

- `PASSED`: brak `Critical` i `Important`, bramki `make check`, `make test`, `make e2e` zielone, brak blokerów.
  Znaleziska `Minor` są dozwolone.
- `FINDINGS`: co najmniej jedno `Critical` lub `Important` (czerwona bramka jest takim znaleziskiem), brak blokerów.
- `BLOCKED`: potrzebna decyzja albo nie da się ocenić (`blockers` niepuste). Dokończ wcześniej pracę, której
  bloker nie zmienia, i dołącz znaleziska, które już masz.

## Blokery

Typy: `business` (zakres, niejednoznaczna specyfikacja), `architecture` (granice modułów, kontrakt spoza specyfikacji,
nowa zależność, odstępstwo od `CLAUDE.md`), `cost` (nowa usługa, płatne API), `technical` (awaria środowiska, HEAD
inny niż w prompcie, brak planu). Każdy wpis: pytanie, opcje ze skutkami, rekomendacja z uzasadnieniem, dowody.
Nie zadawaj pytań w tekście.

## Kontrakt wyniku

```json
{
  "schema_version": 1,
  "epic_id": "...",
  "status": "PASSED | FINDINGS | BLOCKED",
  "mode": "FULL | DELTA",
  "head_sha": "<40 hex>",
  "findings": [{"id": "F1", "severity": "Critical | Important | Minor", "title": "...", "detail": "...",
                "evidence": "...", "files": ["..."], "task_id": "PRICING-3 | null"}],
  "checks": [{"name": "make check | make test | make e2e", "status": "passed | failed | skipped"}],
  "blockers": [{"id": "B1", "type": "business | architecture | cost | technical", "question": "...",
                "options": [{"option": "...", "consequences": "..."}], "recommendation": "...", "evidence": "..."}],
  "report_file": "<report_path>"
}
```

`head_sha` to HEAD, który przeglądałeś (pełne 40 znaków, równe `git rev-parse HEAD`). `checks` zawiera zawsze trzy
bramki; dodatkowe wpisy (np. `architecture-review`, `qa-adversarial` ze statusem `passed`/`failed`) są dozwolone.
Pola poza kontraktem są niedozwolone. Pisz zwięźle po polsku.
