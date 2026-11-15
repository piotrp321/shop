## Review AGENT-SECURITY-1: PASS

- SHA: 27a86a7f3fa77f79a55696325e02f0711fa11fdc. HEAD pozostał ten sam przez cały review, drzewo jest czyste. Base to merge-base e3df78d6.
- Tryb: FULL.
- Werdykt: **PASS**. Nie ma ustaleń BLOCKER ani IMPORTANT, są 4 SUGGESTION (Minor).

### Testy, które uruchomiłem (z `-B`, bez zapisu plików)
- `tools/agent-security`: 34 testy, OK.
- `.claude/skills/groom`: 149 testów, OK.
- Dodatkowo `.claude/skills/ao-run` (regresja importów `next_epic` i `night_queue`): 472 testy, OK.
- Całego `script-tests.sh` nie uruchamiałem, bo tworzy `__pycache__`. Zestawy, których dotyczy diff, przechodzą.
- Na żywo tylko odczyt GitHuba:
  - `gh api --include …/collaborators/<nieistniejący>/permission` daje na stdout `HTTP/2.0 404` z nagłówkami i treścią, kod wyjścia 1. Bramka rozpoznaje to poprawnie.
  - `torvalds` dostaje 200 z `role_name: read`, czyli niezaufany.
  - `github-actions` (login bota w komentarzach, bez `[bot]`) dostaje 404, czyli niezaufany.
  - `github_trust.py issues --limit 3` daje poprawne rekordy z `trusted: true`, a `comments 498` daje `[]`.

### Trzy kontrole z punktu 4
1. **Istniejące testy:** bez uwag. `test_groom.py` z base się zmienia, ale nic nie usunięto, nie wyłączono ani nie osłabiono.
   - Zmieniła się oczekiwana lista `--json` w `gh issue list` (`number,author,url,title,body,labels,updatedAt`). Wynika to z R1: czytanie przez bramkę.
   - `CollectRunner` dostał odpowiedzi `gh api --include` i autora epików, a epiki są teraz też na głównej liście issues. To wynika z tego, że groom bierze epiki z listy, która przeszła przez bramkę.
   - Liczba testów wzrosła ze 141 do 149.
2. **Zakres:** bez uwag. Wszystkie 12 plików mieszczą się w `scope` zadania. Nie zmieniono `next_epic.py`, `night_queue.py`, `CLAUDE.md`, `/sweep`, `/retro` ani `/integration`.
3. **RED przed implementacją:** spełnione, `tdd.status` to `verified`.
   - Bramka: RED o 10:18:41 przy `head` e3df78d6, w `dirty` tylko `?? tools/agent-security/`. Wynik to `FileNotFoundError …github_trust.py`, więc pliku produkcyjnego nie było. GREEN o 10:19:43 (34 OK), potem commit 2aa1457b.
     - Zastrzeżenie: RED to błąd ładowania modułu ("Ran 1 test"), więc w `output_tail` nie ma nazw poszczególnych testów, jest tylko plik `test_github_trust.py`. Dla nowego modułu to naturalna i prawdziwa faza RED, dlatego nie zgłaszam tego jako ustalenia.
   - Groom: RED o 10:20:58 przy `head` 2aa1457b, w `dirty` tylko ` M test_groom.py`, więc `groom.py` był niezmieniony. Wynik to 12 failures i 2 errors, w końcówce widać nazwę `test_external_issue_of_the_current_data_cannot_be_proposed`. GREEN o 10:21:49, potem commit b4cd13c7.
   - Instrukcje skilli i agentów oraz dokumentacja nie podlegają TDD.

### Lista kontrolna z punktu 6
- **Błędy API (4xx zamiast 500):** nie dotyczy, diff nie zawiera endpointów HTTP.
- **Komunikaty po polsku:** sprawdzone.
  - Po polsku są komunikaty dla użytkownika w groom: `_external_error`, `external_summary` „Zgłoszenia zewnętrzne: #N od @autor, link”, błędy walidacji.
  - Stderr bramki (`github_trust: …`) jest diagnostyczny i po angielsku, tak jak `next_epic.py`. To narzędzie dewelopera, nie interfejs sklepu.
- **Unicode:** sprawdzone. `ensure_ascii=False`, a test ma tytuł z „ż”. Treść niezaufana nie przechodzi wcale, więc znaki sterujące w niej nie mają znaczenia.
- **Klawiatura, focus, etykiety:** nie dotyczy, nie ma UI.
- **Stany i ekran 375 px:** nie dotyczy, nie ma UI.
- **Przypadki brzegowe:** sprawdzone.
  - Limit 500 liczy rekordy zaufane i niezaufane razem.
  - Pusta lista zewnętrznych daje `external_summary: null`.
  - Login `None`, pusty, `ghost`, `[bot]`, `app/…`, `is_bot` i login próbujący wyjść ze ścieżki (`a/../../x`) są odrzucane bez wywołania API.
  - Przy porównaniu z `--current` liczy się suma `external` z obu plików.

### Model zagrożeń, droga 1 w zakresie zadania (`/groom`, `/prepare-epic`, `spec-critic`)
- **Czy treść autora bez prawa zapisu dociera do agenta albo `input.json`:** nie znalazłem takiej drogi.
  - `collect` bierze issues i epiki wyłącznie z `Gate.issues`.
  - `next_epic.board_epics` czyta tytuły i treść epików bez bramki, ale groom bierze z niego tylko stan i kolejność epików z `by_number`, czyli zaufanych. Niczego z niego nie wypisuje.
  - `external` zawiera tylko `number`, `author`, `url` i `trusted`.
  - `apply` czyta tylko `state,updatedAt`.
  - `origin` czyta `state,labels,title`, porównuje ID i tytułu nie wypisuje.
  - `--current` powstaje przez ten sam `collect`.
  - `/prepare-epic` bierze z tablicy przez `--jq` same numery, resztę czyta przez bramkę.
  - `backlog-groomer` ma teraz zakaz `gh`.
  - W groom, prepare-epic, spec-critic i backlog-groomer nie zostało żadne bezpośrednie `gh … --json body/title/comments`. Pozostałe wywołania `gh` w prepare-epic dotyczą własnego PR skilla.
- **Ocena zaufania:** sprawdzone.
  - Wymagane jest jednocześnie `role_name` ∈ {admin, maintain, write} i `permission` ∈ {admin, write}. Rola własna albo niespójne pola dają brak zaufania.
  - 404 rozpoznaje linia statusu HTTP, nie tekst błędu.
  - Każdy inny status, brak `stdout` u runnera albo zły JSON daje `GateError`, kod 1 i pusty stdout. Testują to CLI i groom: brak `input.json`, kod 1.
  - Cache działa per login bez rozróżniania wielkości liter i per instancja `Gate`.
  - Komentarze są oceniane każdy osobno; komentarzy niezaufanego issue nie ma w polu `comments`.
  - `cmd_collect` używa `github_trust.make_runner`, który zachowuje stdout, więc 404 nie zatrzymuje całego grooma.
- **validate i apply:** numer z `external` jest odrzucany w każdej pozycji, w `keep` (bo `keep` ∈ `issues`) i w `fix_epic`. Każdy numer spoza zaufanego `original` też jest odrzucany, więc ochrona nie zależy od samej listy `external`.
- **ADR:** zgodny ze specyfikacją.
  - Ma wprost zdanie, że sandbox nie chroni przed celowym obejściem przez Dockera i lifecycle AO.
  - Ma luki z #498, drogę 15, hipotezę P13, lukę sesji interaktywnych z bypassem oraz zalecenia dotyczące tokenu `gh` i hasła klucza SSH.
  - Wiersz w `agentic-software-house.md` wskazuje ten ADR.
  - Dokumenty opisują stan docelowy bez sekcji historii.

### Ustalenia
1. **SUGGESTION**: `<home>/Development/claude/shop-AGENT-SECURITY-1/tools/agent-security/github_trust.py:24` i `:270`.
   - Docstring mówi „The comments of an untrusted issue are never returned” pod nagłówkiem obejmującym też komendę `comments`.
   - Tymczasem `Gate.comments(n)` nie sprawdza autora issue i zwraca zaufane komentarze także pod issue niezaufanym. Test `test_comments_command_reads_the_issue_comments` tego nie rozstrzyga.
   - Waga: zachowanie jest zgodne z R1 (komentarze ocenia się po ich autorze), więc nie ma wycieku. Ryzyko polega na tym, że AGENT-SECURITY-2 (`retro.py`) oprze się na błędnym opisie kontraktu.
   - Propozycja: doprecyzować docstring do pola `comments` albo ujednolicić zachowanie.
2. **SUGGESTION**: `<home>/Development/claude/shop-AGENT-SECURITY-1/.claude/agents/backlog-groomer.md:27`.
   - Zdanie „walidacja odrzuca listę z ich numerem” wymienia też `overflow` i `hypotheses`, a `check()` w `groom.py` sprawdza tylko `items` i `fix_epic.issues`. Odwołań `#N` w `fix_epic.body` też nie sprawdza.
   - Waga: agent nie zna treści zgłoszeń zewnętrznych, a `/prepare-epic` i tak przepuszcza issues z treści epiku przez bramkę i przenosi je do „Poza zakresem”. Nie ma więc wpływu na dane, jest tylko nieścisłość instrukcji.
3. **SUGGESTION**: `<home>/Development/claude/shop-AGENT-SECURITY-1/docs/adr/agent-security.md:103`.
   - R6 mówi, że sonda sprawdza próby obejścia „z dróg 2–15”. Według tabeli drogę 10 sprawdza test workflowów, a drogę 14 testy celów i `ao-prepare`, nie sonda.
   - Waga: drobna nieścisłość dokumentacji, bez wpływu na działanie.
4. **SUGGESTION**, `poza modelem: propozycja zgłoszenia` „/groom: zgłoszenia zewnętrzne zużywają limit 500 i zapytania o uprawnienia”.
   - Masowe issues z wielu kont mogą zablokować `collect` (limit 500 był już w base) i każde zużywa jedno wywołanie API uprawnień.
   - Diff tego nie wprowadza, bo limit istniał wcześniej. Chodzi tylko o dostępność, bez wycieku.

Kodu nie modyfikowałem. Główne pliki:
- `<home>/Development/claude/shop-AGENT-SECURITY-1/tools/agent-security/github_trust.py`
- `<home>/Development/claude/shop-AGENT-SECURITY-1/.claude/skills/groom/groom.py`
- `<home>/Development/claude/shop-AGENT-SECURITY-1/.claude/skills/prepare-epic/SKILL.md`
- `<home>/Development/claude/shop-AGENT-SECURITY-1/docs/adr/agent-security.md`