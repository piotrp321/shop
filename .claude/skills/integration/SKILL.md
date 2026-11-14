---
name: integration
description: Globalna integracja epika po zmergowaniu zadań do gałęzi epika: Architecture Review, adversarial QA, przegląd UX (gdy diff dotyka frontów) i bramki jakości całego diffu, raport i wynik JSON dla AO.
argument-hint: "Epik <id>: specyfikacja `<ścieżka>`, plan `<ścieżka>`, gałąź `<gałąź>`, HEAD `<sha>`, tryb FULL|DELTA, raport `<ścieżka>`."
model: opus
effort: high
disable-model-invocation: true
---

Zintegruj epik opisany w: $ARGUMENTS. Jesteś orkiestratorem integracji: oceniasz **całość** epika po tym, jak
`/delivery` dostarczył i zmergowano wszystkie jego zadania do gałęzi epika. Pracę merytoryczną robią agenci
`architecture-reviewer`, `qa-adversarial` i, gdy diff dotyka frontów, `ux-reviewer`; ty ustalasz zakres, uruchamiasz bramki, weryfikujesz zgłoszenia,
przygotowujesz listę odbioru i składasz raport. Stosuj `CLAUDE.md` (także lokalne pliki aplikacji) i `docs/vision.md`.

## Wejście

Skill uruchamia AO headless, z `cwd` = worktree epika na HEAD jego gałęzi. Prompt ma postać:

`/integration Epik <epic_id>: specyfikacja `<spec_path>`, plan `<plan_path>`, gałąź `<branch>`, HEAD `<sha>`, tryb FULL|DELTA, raport `<report_path>`.`

Opcjonalnie prompt może podawać `poprzedni HEAD `<sha>``. Nie ma użytkownika do pytań: brak rutynowych danych uzupełnij
z repo, a decyzję spoza granic skilla zwróć jako bloker (sekcja „Blokery”).

## Granice odpowiedzialności

- **Robisz:** globalny Architecture Review, niezależny adversarial QA, przegląd UX zmienionych ekranów (gdy diff dotyka
  `apps/store/` albo `apps/admin/`), bramki jakości (`make check`, `make test`, `make e2e`), weryfikację zgłoszeń, raport do `<report_path>` i wynik JSON.
- **Nie robisz:** kodu i poprawek (naprawy to osobne zadania `/delivery` zlecane przez AO), `git commit`, `git push`,
  `git merge`, zmiany gałęzi, `gh pr` poza odczytem opisów PR zadań (krok 6), merge, deploymentu, sprzątania stosu (`.ao/teardown` należy do AO). Jedyny
  plik w repo i w katalogu raportu, który zapisujesz, to raport (oraz logi bramek i katalog agenta UX poza repo;
  w repo tylko chwilowo pliki generowane przy sprawdzeniu z kroku 3a, przywracane od razu). Testy napisane przez QA zostają w raporcie jako propozycje albo w polu
  `evidence` znaleziska; nie trafiają do repo. Skrypty, zrzuty i wyniki agenta UX zostają w `AGENT_DIR` poza
  repo (krok 3), więc `git status --porcelain` się nie zmienia.
- **Źródłem prawdy** jest diff `origin/main...HEAD`, specyfikacja i plan, nie raporty z PR zadań ani opinie
  poprzednich review. Opisy PR zadań czytasz tylko po sekcję „Decyzje bez pytania” do listy odbioru.

## Pierwszy plan i ostatni blok

AO czyta tylko ostatni `result` sesji. Pobudka, która przyjdzie po wyniku, otwiera nową turę, a jej tekst zastępuje
JSON i AO powtarza rundę. Dlatego:

- Nie planujesz pobudek ani pracy w tle: bez `ScheduleWakeup`, `CronCreate`, `Monitor`, `/loop`, `nohup`, `&`
  i bez `run_in_background` w Bash.
- Agenci (`architecture-reviewer`, `qa-adversarial`, `ux-reviewer`) działają asynchronicznie, także bez
  `run_in_background`. Uruchomienie zwraca od razu, a wynik przychodzi jako powiadomienie o zakończeniu agenta. Po
  uruchomieniu zakończ turę jednym zdaniem statusu, bez JSON i bez pobudki. Powiadomienie otworzy kolejną turę.
  Następny krok zaczynasz dopiero po powiadomieniach wszystkich uruchomionych agentów.
- Bramki działają na pierwszym planie. Każda to osobne wywołanie Bash z parametrem `timeout: 600000`, z komendą
  w `timeout 570 …` i wyjściem do pliku logu w katalogu pliku `<report_path>`, potem `tail` logu. `make test` to
  cztery wywołania: `make -C apps/api test`, `make -C apps/store test`, `make -C apps/admin test` (każde
  z `RUN="docker compose exec -T"`) i `make varnish-test`. To te same kroki, które wykonuje `make test`.
  W wyniku występują razem jako jeden wpis `make test`: `passed`, gdy wszystkie przeszły, inaczej `failed`. `make seed` też jest osobnym wywołaniem.
- Kod 124 oznacza przekroczenie limitu czasu, nie błąd kodu. `make e2e` powtarzasz raz według kroku 4. Drugi kod
  124 albo kod 124 innej bramki to awaria środowiska z kroku 4: bramka ma `skipped`, a bloker `technical` dostaje
  komendę i koniec logu.
- Wynik wypisujesz dopiero po powiadomieniach wszystkich agentów i po ostatniej bramce, gdy nic nie działa w tle.
  Blok ```` ```json ```` jest ostatnim blokiem ostatniej wiadomości i nic po nim nie piszesz.
- Gdy po wypisaniu wyniku przyjdzie jakakolwiek wiadomość (pobudka, powiadomienie o zadaniu, wiadomość agenta,
  pytanie), odpowiadasz tylko tym samym blokiem ```` ```json ````, znak w znak, bez innego tekstu. Nie wznawiasz
  pracy i nie zmieniasz wyniku.

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
   `Przegląd HEAD:` z najnowszego (wg czasu modyfikacji) innego pliku `*.md` bezpośrednio w katalogu `<report_path>` (bez podkatalogów,
   więc bez raportów agenta UX `ux-<sha7>/report.md`), który jest przodkiem HEAD (`git merge-base --is-ancestor`). Zakres DELTA to `git diff <poprzedni>..HEAD` **oraz skutki**: kod, który
   te zmiany wywołuje lub którego kontrakty zmieniają (grep użyć, testy, OpenAPI, klienty). Bez wiarygodnego
   poprzedniego HEAD pracuj jak w `FULL`, napisz to w raporcie i zaznacz, że poprzednich znalezisk nie dało się
   porównać (lista do sprawdzenia pochodzi z tego samego poprzedniego raportu). Bramki uruchamiasz zawsze w całości
   (w `DELTA` testy mogą pochodzić z CI, niżej).
   **Testy z CI (tylko `DELTA`).** Uruchom `python3 .claude/skills/integration/delta.py ci --head <HEAD>`. AO
   zaczyna rundę na HEAD z zielonym CI, więc zwykle zwraca kod 0. Skrypt bierze ostatni run workflow CI z pusha
   dokładnie dla HEAD. Runy PR się nie liczą, bo pomijają pracę przy zmianach dokumentacji i narzędzi. Wynik
   obowiązuje w kroku 3a i 4. Kod 0 oznacza, że wymagane joby `checks` i `e2e` mają `success`. Kod 1 oznacza
   bramki lokalne: inny SHA, brak runu, run w toku, czerwony lub brakujący job albo błąd `gh`.
   W `DELTA` z wiarygodnym poprzednim HEAD rozpoznaj rodzaj zmiany:
   `python3 .claude/skills/integration/delta.py sync --previous <poprzedni> --head <HEAD> --main origin/main`.
   `kind: sync` oznacza, że każdy commit z `<poprzedni>..HEAD`, którego nie ma na `origin/main`, jest merge'em
   dwóch rodziców, a merge'e wnoszą commity z `main`. Taki merge różni się od automatycznego merge'a Gita
   (`git merge-tree`) tylko w plikach z konfliktem. Wtedy pracujesz w trybie **DELTA po syncu** (krok 3a). `kind: delta`
   to zwykła `DELTA`, a powód jest w `reason`. Może to być:
   - commit niebędący merge'em,
   - sync przez squash,
   - merge innej gałęzi,
   - zmiana zrobiona w samym merge'u (`extra`),
   - merge więcej niż dwóch rodziców,
   - merge bez commitów `main`.
   Kod wyjścia 2 oznacza, że poprzedni HEAD nie jest wiarygodny (jak wyżej, praca jak w `FULL`).
   Decyduje wyłącznie wynik skryptu, nie opis commitów.
3. **Review równolegle.** W trybie DELTA po syncu pomiń ten krok i wykonaj krok 3a.
   W jednej wiadomości uruchom `architecture-reviewer` i `qa-adversarial`; `ux-reviewer`
   dopiero po zakończeniu QA (niżej). Każdy agent dostaje HEAD SHA, zakres z kroku 2, ścieżki spec i planu, listę
   zadań; **nie przekazuj** im swoich ani cudzych wniosków:
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
     - Logowanie kontami demo (`e2e/support/accounts.ts`) robi skrypt `.claude/skills/integration/kc_login.py`
       (`python3`, biblioteka standardowa) tak jak przeglądarka; nie pisz własnego klienta formularza Keycloak.
       Keycloak, panel i sklep zawsze pod `localhost`, nie `127.0.0.1`. Token pracownika do `/api/v1/admin`
       (realm `backoffice`, klient `admin`, PKCE S256 i kod TOTP; konta `admin`, `support`, `warehouse`,
       `marketing`):
       `TOKEN=$(python3 .claude/skills/integration/kc_login.py staff --keycloak http://localhost:<KEYCLOAK_PORT> --admin http://localhost:<ADMIN_PORT> --account admin)`,
       potem `curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:<API_PORT>/api/v1/admin/...`. Token jest
       ważny kilka minut: jeden na konto, ten sam we wszystkich żądaniach, także równoległych, nowy po wygaśnięciu
       (401). Kod TOTP jest jednorazowy: dwa logowania jednego konta w tym samym oknie 30 s skrypt rozdziela sam.
       Oba realmy mają ochronę przed brute force, a `make seed` nie resetuje Keycloaka, więc bez celowo błędnych
       logowań na kontach demo (zablokowane konto psuje `make e2e`); skrypt nie ponawia odrzuconego hasła. Gdy
       logowanie się nie uda, QA nie ponawia prób: scenariusze zapisuje jako propozycje testu z komunikatem skryptu.
     - Klient (`/api/v1` klienta, realm `shop`): token wymaga sekretu klienta `store` z `.env.local`, którego QA
       nie czyta, więc scenariusze klienta idą przez BFF sklepu (`http://localhost:<STORE_PORT>/api/cart`,
       `/api/cart/items`, `/api/checkout`; zapis wymaga nagłówków `Origin: http://localhost:<STORE_PORT>`
       i `Content-Type: application/json`). Sesja to plik cookies wspólny z `curl -b <plik> -c <plik>`:
       `python3 .claude/skills/integration/kc_login.py customer --store http://localhost:<STORE_PORT> --cookie-jar <plik> --account jan`
       (konta `jan`, `anna`) loguje przez `/auth/login` sklepu, a powrót do `/auth/callback` scala koszyk gościa
       z `store_cart` tego pliku. Publiczne `GET` (`apps/api/config/packages/security.yaml`) nie wymagają tokenu.
     QA może uruchamiać przeciw tym adresom czarnoskrzynkowe skrypty HTTP (`curl`, `python3` z biblioteką
     standardową), także scenariusze współbieżne (np. 16 równoległych `PUT`). Zapisuje tylko dane, które odtwarza
     `make seed` (katalog, ceny, stany i rezerwacje); nie zakłada kont, nie zmienia Keycloaka ani konfiguracji
     usług, nie czyta `.env.local`, niczego nie zapisuje w worktree epika, bez commitów i pushy. Gdy stos jest
     niedostępny, pracuje statycznie (kod, samodzielne skrypty bez Dockera) i pisze to w raporcie. Raport QA
     oddziela **wykonane** (scenariusz uruchomiony przeciw stosowi: komenda lub skrypt i wynik) od **propozycji
     testu** (nieuruchomionej). Bramki uruchamiasz sam, po jego zakończeniu.
   W trybie DELTA przekaż zakres DELTA i listę znalezisk z poprzedniego raportu do sprawdzenia, czy zostały naprawione.
   - `ux-reviewer` (agent UX): uruchamiasz go tylko wtedy, gdy `git diff --name-only <zakres> -- apps/store apps/admin`
     (zakres z kroku 2) jest niepusty albo (`DELTA`) poprzedni raport ma znaleziska UX do sprawdzenia; inaczej `ux-review` ma status `skipped`, a raport mówi dlaczego. Uruchamiasz
     go **po zakończeniu `qa-adversarial`** (te same konta demo i kody TOTP, więc nie równolegle z QA) i **przed
     bramkami**. Pracuje w worktree epika (`<STOS>` = `cwd`) przeciw stosowi AO z `.env` tego worktree, przez
     `make e2e-agent`, i nie zmienia repo. Przekaż mu w zleceniu: tryb `integration`; `<STOS>`; adresy stosu z `.env`
     (jak dla QA, wraz ze sprawdzeniem `/health`; gdy stos nie odpowiada, napisz mu to); HEAD; ścieżkę
     specyfikacji epika; zakres `FULL` albo `DELTA`; listę zmienionych plików frontów; `AGENT_DIR`, czyli
     bezwzględną ścieżkę istniejącego katalogu poza repo obok `<report_path>` (np. `<katalog raportu>/ux-<sha7>`,
     utwórz go przed zleceniem); w `DELTA` jego własne znaleziska z poprzedniego raportu (sekcja wyników UX).
     **Nie przekazuj** wniosków `architecture-reviewer` ani `qa-adversarial`. Gdy `make e2e-agent` nie działa
     przy działającym stosie, agent zwraca to w uwagach: wpisz uwagę do raportu i `ux-review: skipped`, bez blokera.
3a. **DELTA po syncu.** Zmiana od poprzedniego HEAD to tylko połączenie z `main`, więc przeglądasz sam, bez
   `architecture-reviewer`, `qa-adversarial` i `ux-reviewer` (`ux-review: skipped`, w raporcie powód „DELTA po
   syncu”). Zakres to merge'e z wyniku `delta.py sync`:
   - **Rozwiązania konfliktów.** Dla każdego pliku z `conflicts` porównaj wynik merge'a z jego rodzicami
     (`git diff <rodzic> <merge> -- <plik>`). Porównaj też zmiany obu stron od bazy:
     `git diff $(git merge-base <rodzic1> <rodzic2>) <rodzic> -- <plik>`. Zgubiona zmiana epiku albo `main` to
     znalezisko `Important` z `files` = ten plik. Dotyczy to także rozwiązania, które wzięło jedną stronę w całości.
     Pozostałe pliki Git połączył sam, a skrypt potwierdził, że merge ich nie zmienił.
   - **Pliki generowane** mają być wygenerowane od nowa z połączonych źródeł. Dotyczy to plików z `generated`, a także
     sytuacji, gdy `conflicts` dotyka kontrolerów, DTO albo konfiguracji OpenAPI. Gdy `delta.py ci` z kroku 2 zwrócił
     0, sprawdził to krok „Contract freshness” części `checks part apps`. W przeciwnym razie:
     1. Uruchom `make -C apps/api openapi RUN="docker compose exec -T"` i
        `make api-client RUN="docker compose exec -T"`.
     2. Uruchom
        `git diff --exit-code -- apps/api/openapi.json apps/api/openapi-admin.json apps/store/src/api/schema.d.ts apps/admin/src/api/schema.d.ts`.
     3. Przywróć te pliki (`git checkout -- <te cztery pliki>`), żeby `git status --porcelain` był jak w preflighcie.

     Różnica to znalezisko `Important` („pliki generowane nie odpowiadają źródłom”).
   - **Poprzednie znaleziska** przenosisz bez zmian (ten sam `title`, `severity`, `files`). Ponownie sprawdzasz tylko
     te, których pliki zmienił sync (`git diff --name-only <poprzedni>..HEAD`). Znaleziska naprawione przez `main`
     znikają.
   Potem bramki (krok 4), weryfikacja (krok 5) i lista odbioru (krok 6) jak zawsze.
4. **Bramki.** Po zakończeniu agentów (także agenta UX, jeśli był uruchamiany) albo przeglądu z kroku 3a, sekwencyjnie,
   na pierwszym planie (sekcja „Pierwszy plan i ostatni blok”).
   Gdy `delta.py ci` z kroku 2 zwrócił 0, nie uruchamiasz lokalnie `make test`, `make seed` ani `make e2e`.
   W `checks` wyniku `make test` i `make e2e` mają wtedy `passed`, a obok dodajesz oba wpisy z pola `checks`
   skryptu (`ci:checks@<HEAD>`, `ci:e2e@<HEAD>`). W raporcie podajesz źródło: CI, SHA, numer runu. Przy awarii
   środowiska te cztery wpisy zostają bez zmian, a `skipped` dostaje tylko `make check`. Kod 1: bramki lokalne, a `reason` trafia do raportu. `make check`
   uruchamiasz zawsze lokalnie, a w `FULL` wszystkie bramki.
   Bramki lokalne:
   `make check RUN="docker compose exec -T"`, `make test` (cztery wywołania z sekcji „Pierwszy plan i ostatni blok”), potem
   `make seed RUN="docker compose exec -T"` i `make e2e E2E_FLAGS=-T` (sesja nie ma terminala, więc bez `-T` komendy padają).
   Zapisy QA i agenta UX trafiły do bazy dev stosu. `make check` nie czyta danych, a `make test` działa na osobnych zasobach
   testowych (baza `shop_test`, indeks `test_products`, vhost `test`, Redis DB 1), więc jedyną bramką zależną od
   danych dev jest `make e2e`; `make seed` (reset katalogu, cen, stanów i rezerwacji) stoi bezpośrednio przed nią
   i nie przesuwaj go. Wszystkie uruchamiasz nawet po czerwonej wcześniejszej bramce. W wyniku nazwy bramek to
   `make check`, `make test`, `make e2e`. Zapisz kod wyjścia i koniec wyjścia każdej. Czerwona bramka to znalezisko
   (domyślnie `Important`, z `evidence` = komenda i końcówka wyjścia; `Critical` przy utracie danych lub luce
   bezpieczeństwa). `make e2e`, które padło, powtórz raz: gdy przejdzie, zapisz `passed` i znalezisko `Minor`
   („niestabilny test e2e”, z nazwą testu). Awaria środowiska (stos nie odpowiada, brak Dockera, brak miejsca) to
   nie błąd kodu: pozostałe bramki oznacz `skipped` i zwróć bloker `technical`.
5. **Weryfikacja.** Zgłoszenia agentów (także agenta UX) są hipotezami. Każde `Critical` i `Important` sprawdź w kodzie lub
   odtwórz komendą, zanim trafi do wyniku; niepotwierdzone, niepewne i stylistyczne wpisz do raportu jako uwagi
   albo odrzuć, nie jako znaleziska. Scal duplikaty. Wagi:
   - `Critical`: utrata danych lub poważna luka bezpieczeństwa.
   - `Important`: odpowiedź 500 (każde 5xx) na żądanie, które da się wysłać do API albo z frontu, także przy
     nietypowych danych wejściowych; niedziałająca ścieżka użytkownika; regresja funkcjonalna względem `main` albo
     specyfikacji; komunikat widoczny dla użytkownika nie po polsku (ekran, a w API `title` i `detail` błędu,
     `violations[].title`); istotny problem dostępności: element niedostępny z klawiatury albo kontrolka bez
     etykiety; naruszenie kryterium akceptacji albo zasady z `CLAUDE.md`
     lub architektury, które da się naprawić; przypadki `Important` z kroków 3a, 4 i 6. To, że przypadek jest
     brzegowy albo panel go nie wysyła, nie obniża wagi, gdy problem da się wywołać.
   - `Minor`: kosmetyka (wygląd, odstępy, nazewnictwo), drobna jakość kodu (duplikacja, brakujący test zachowania,
     które działa poprawnie) i pozostałe potwierdzone problemy o niewielkim wpływie, których nie ma na liście
     `Important`.
   Przykłady `Important`: NUL w nazwie produktu daje 500 (#247), duplikat
   wariantu różniący się wielkością liter daje 500 zamiast 422 (#248), notatka z samych białych znaków Unicode
   przechodzi mimo wymagania specyfikacji (#256), angielski komunikat 422 (#257).
   Każde znalezisko ma w `detail` jedno zdanie „Wpływ: …”, które uzasadnia wagę skutkiem dla użytkownika albo
   systemu. Waga wynika z tej listy, nie z liczby znalezisk: problemu spoza listy `Important` nie podnosisz, a
   problemu z listy nie obniżasz.
   Dla UX: `Important` tylko problem odtworzony (2/2, powtórz komendę z `Odtworzenie` raportu UX albo przeczytaj
   jego skrypt i wynik) na ekranie lub w zachowaniu zmienionym przez epik, który uniemożliwia albo myli wykonanie
   zadania, także osobie korzystającej z klawiatury lub czytnika ekranu; należy tu element niedostępny z klawiatury,
   kontrolka bez etykiety i komunikat nie po polsku (utratę danych lub lukę opisaną przez agenta UX jako `Important`
   oceń według wag powyżej). Pozostałe odtworzone problemy na zmienionych ekranach to `Minor`. Problemy na niezmienionych ekranach i hipotezy idą do raportu jako uwagi, nie znaleziska.
   Bezpieczeństwo przy specyfikacji z sekcją `## Model zagrożeń`: droga z listy w zakresie, której kryterium nie
   działa, to co najmniej `Important`. Droga spoza listy to `Critical`, gdy jest poważną luką (realna, możliwa do
   wykorzystania droga do danych, sekretów albo uprawnień bez założeń z listy „poza zakresem”); blokuje tak samo
   jak każde `Critical`. Inna droga spoza listy, której epik nie wprowadza, to `Minor` z „Wpływ: …” i proponowanym
   tytułem zgłoszenia (etykiety `agent`, `bug`), nie nowe kryterium. Tak samo oceniasz pozycje z sekcji
   `## Poza modelem zagrożeń` opisów PR zadań (lista odbioru, „Decyzje bez pytania”).
   Kod (także kontrakt API i zachowanie frontu) niezgodny ze specyfikacją, która jednoznacznie określa oczekiwane
   zachowanie, to znalezisko `Important` do naprawy według specyfikacji, nie bloker: w opisie podaj fragment
   specyfikacji i zmiany, których wymaga naprawa (np. kontrakt API, eksport OpenAPI, klient TS, testy).
   Werdykt `REWORK` albo „decyzja do rewizji” architekta, który wymaga decyzji użytkownika (specyfikacja
   niejednoznaczna albo milczy, naprawa wymaga zmiany specyfikacji, nowa zależność, zmiana granic modułów, świadome
   odstępstwo od `CLAUDE.md`), to bloker `architecture`, nie znalezisko; naprawialne naruszenie jest znaleziskiem.
6. **Lista odbioru.** Przygotuj ją dla właściciela, zawsze dla całego epiku (`origin/main...HEAD`), także w trybie
   `DELTA`. Sprawdzasz sam, bez agentów:
   - **Istniejące testy:** pliki testów zmienione, usunięte albo przemianowane względem `origin/main`
     (`git diff --name-status -M origin/main...HEAD`). Usunięty, wyłączony albo osłabiony test lub zmieniona wartość
     oczekiwana bez podstawy w specyfikacji to znalezisko `Important`.
   - **Migracje:** nowe i zmienione pliki w `apps/api/migrations`: jakie tabele i kolumny zmieniają, czy usuwają albo
     przepisują dane. Operacja niszcząca bez podstawy w specyfikacji to znalezisko `Important`, a przy utracie danych
     `Critical`.
   - **Pliki spoza zakresów zadań:** pliki, które nie pasują do `scope` żadnego zadania manifestu ani do specyfikacji. Pliki zadań poprawek i napraw (`<EPIK>-FIX-<n>`, `<EPIK>-REPAIR-<n>`) oceniasz względem ich zgłoszeń.
     Zmiana bez podstawy w specyfikacji to znalezisko: `Important`, gdy zmienia zachowanie, inaczej `Minor`.
   - **Decyzje bez pytania:** sekcje `## Decyzje bez pytania` z opisów zmergowanych PR zadań, tylko odczyt:
     `gh pr list --base epic/<epic_id> --state merged --limit 200 --json number,title,body`. Przepisz je z numerem PR,
     razem z pozycjami sekcji `## Poza modelem zagrożeń` (z wagą według kroku 5).
     Pusta lista przy zmergowanych zadaniach (np. inna nazwa gałęzi epiku) to pozycja „Sprawdź:”, nie „OK”.
     Decyzja sprzeczna ze specyfikacją to znalezisko albo bloker według kroku 5. Gdy `gh` nie działa, napisz to w
     liście; to nie jest bloker.
   - **Odstępstwa od specyfikacji:** potwierdzone w kroku 5.
   - **Synchronizacja z `main`:** `git merge-tree --write-tree HEAD origin/main`. Konflikt to pozycja „Sprawdź:” z listą
     plików, nie znalezisko ani bloker. Usuwa go zadanie REPAIR w AO (konflikt końcowego PR, prawdziwy
     `git merge origin/main` zmergowany do `epic/<epik>` merge commitem) albo PR `sync/<epik>-main` z sekcji 6a
     `/ao-run`. Zadanie poprawek QA jest mergowane squashem, więc nie przesunie punktu wspólnego z `main` i konflikt
     wróci. Treść rozwiązania konfliktu oceniasz dopiero w rundzie DELTA po syncu (krok 3a).
   Każda pozycja ma wynik „OK” albo „Sprawdź:” z plikiem lub PR i jednym zdaniem, dlaczego.
7. **Stabilność znalezisk.** AO liczy odcisk znaleziska z `severity`, znormalizowanego `title` i posortowanych
   `files`, i przerywa pętlę napraw, gdy ten sam odcisk wraca po rundzie naprawczej. Dlatego: `title` opisuje problem,
   nie szczegół przebiegu (bez SHA, numerów linii i liczb); `files` to pliki, w których trzeba poprawić; ten sam
   niezmieniony problem zgłaszaj w kolejnych rundach z identycznym `title`, `severity` i `files`; nie przemianowuj
   ani nie dziel problemu, żeby uniknąć odcisku. Naprawiony problem znika z wyniku.
8. **Raport.** Zapisz Markdown po polsku do `<report_path>` (tworząc katalog, jeśli trzeba; ścieżka leży poza repo,
   nie dodawaj jej do Git). Pierwsze linie: `Epik:`, `Gałąź:`, `Przegląd HEAD: <sha>`, `Tryb:`, `Zakres:` (w DELTA
   z poprzednim HEAD, a po syncu z dopiskiem „DELTA po syncu”, merge'ami i plikami `conflicts` z `delta.py sync`). Dalej: werdykt, sekcja
   `## Lista odbioru` (pozycje z kroku 6, wynik QA na stosie w jednym zdaniu, liczba znalezisk `Minor`), znaleziska (id, waga, zadanie, plik, dowód, sugerowana naprawa), bramki
   (komenda, kod wyjścia, koniec wyjścia; dla testów z CI: run, SHA i joby z `delta.py ci`), werdykt i uwagi architekta, wyniki UX (sekcja `## Wyniki UX`: ekrany i stany z obejrzanymi zrzutami, znaleziska, w `DELTA` poprzednie
   znaleziska „naprawione”/„nadal”, uwagi, ścieżka `AGENT_DIR` ze zrzutami, albo powód pominięcia), sekcja `## Weryfikacja zgłoszeń epiku`, gdy
   specyfikacja epiku ma sekcję „Zgłoszenia zamykane przez epik”: tabela (zgłoszenie, test, wynik), wiersz na zgłoszenie z jego
   kolumny „Weryfikacja”; test to plik i nazwa z numerem zgłoszenia (`grep -rn "#<nr>" apps e2e/tests`; brak testu to wynik
   „nie przechodzi”), a wynik to `przechodzi`, `nie przechodzi` z dowodem albo `niesprawdzone` z powodem. Wiersze dotyczące
   interfejsu bierzesz z tabeli agenta UX, pozostałe z bramek `make test` i `make e2e` (test z numerem jest w uruchomionym
   zestawie, a bramka przeszła). Zgłoszenie bez pozytywnego wyniku wypisz w werdykcie: nie zostaje w `Closes` końcowego
   PR (właściciel sprawdza tę tabelę przed merge'em), wyniki QA w dwóch grupach: **wykonane**
   przeciw stosowi (scenariusz, komenda lub skrypt, wynik) i **propozycje testu** (kod w blokach), a gdy QA
   pracował statycznie, to zdanie z powodem, niepotwierdzone ryzyka, co sprawdzono bez uwag, bloker(y). Raport
   jest dowodem dla AO i użytkownika, więc nie wklejaj pełnych logów.
9. **Wynik.** Złóż JSON według kontraktu poniżej, zapisz go do pliku tymczasowego poza repo i zwaliduj:

   ```bash
   python3 .claude/skills/integration/integration_result.py validate <plik.json> --head <sha> --epic <epic_id> --mode <FULL|DELTA>
   ```

   Popraw każdy zgłoszony problem. Sprawdź `git status --porcelain`: ma być tak samo jak w preflighcie. Na końcu
   odpowiedzi (po krótkim podsumowaniu po polsku) wypisz dokładnie zwalidowany JSON w **ostatnim** bloku
   ```` ```json ````; po nim nic. Każda późniejsza wiadomość dostaje w odpowiedzi ten sam blok (sekcja „Pierwszy plan
   i ostatni blok”).

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
Nie zadawaj pytań w tekście. Niezgodność kodu z jednoznaczną specyfikacją nie jest blokerem, tylko znaleziskiem
(krok 5). Decyzji właściciela z promptu (odpowiedzi na blokery tej rundy) nie zgłaszaj ponownie jako bloker.

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
Wpis `ux-review` ma status `passed` (po weryfikacji z kroku 5 brak znaleziska UX `Important`), `failed` (jest takie
znalezisko) albo `skipped` (diff nie dotyka frontów, `make e2e-agent` nie działa albo agent nie zwrócił raportu;
uwaga w raporcie).
Testy z CI (krok 4, tylko `DELTA`) to wpisy `ci:checks@<head_sha>` i `ci:e2e@<head_sha>`, zawsze oba, ze statusem
`passed`, obok `make test` i `make e2e` ze statusem `passed`. Walidator odrzuca inny SHA, tryb `FULL`, jeden wpis bez
drugiego i każdą inną nazwę zaczynającą się od `ci:`.
Pola poza kontraktem są niedozwolone. Pisz zwięźle po polsku.
