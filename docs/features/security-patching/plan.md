# SECURITY-PATCHING – plan zadań

Specyfikacja: `docs/features/security-patching/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi
subagentami. Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie
realizuje subagent we własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi
epiku i wypycha ją. Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan
jest dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | SECURITY-PATCHING-1, SECURITY-PATCHING-2, SECURITY-PATCHING-3, SECURITY-PATCHING-4 | w toku: epik natywny, gałąź `feat/security-patching`; SECURITY-PATCHING-2 zmergowane (1eb2b39c); SECURITY-PATCHING-1 zmergowane (d97dc98a) |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

Kroki poza zadaniami, które prowadzi sesja orkiestrująca:

- Włączenie alertów i aktualizacji bezpieczeństwa Dependabota w ustawieniach repozytorium (specyfikacja, K4): robi to
  użytkownik albo sesja po jego zgodzie w rozmowie, która nazywa tę operację.
- Ponowne sprawdzenie digestu Keycloaka w rejestrze przed oznaczeniem końcowego PR jako gotowego (specyfikacja, K1,
  „Digest a rejestr”): gdy tag wskazuje na inną budowę, sesja zleca poprawkę digestu i ponowne sprawdzenie kryteriów.
- Sprawdzenia „Po merge'u” z K4 i sprawdzenie logowania po wdrożeniu z K1 (wiersz „worf.pl po wdrożeniu”): po merge'u
  końcowego PR sesja wpisuje je do zgłoszenia ze sprawdzeniami po wdrożeniu; wdrożenie uruchamia użytkownik osobnym
  poleceniem.

## Praca szeregowa

- SECURITY-PATCHING-1, SECURITY-PATCHING-4: `docs/features/deploy/spec.md`; jeden dokument opisuje i strategię
  wdrożenia Keycloaka („Chart `shop`”), i warunek kontroli chartu w CI („Weryfikacja bez serwera”), a pliku nie da się
  podzielić między zadania.
- SECURITY-PATCHING-2, SECURITY-PATCHING-4: `CLAUDE.md`, `.github/workflows/ci.yml`; oba zmieniają opis tego, co
  uruchamia `make check` i CI (audyt w `check`, warunek kontroli chartu), w tych samych dwóch plikach, których nie
  da się podzielić między zadania.

Zadanie 4 idzie więc po zadaniach 1 i 2 albo przed nimi; z zadaniem 3 może iść naraz.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "SECURITY-PATCHING",
  "spec": "docs/features/security-patching/spec.md",
  "tasks": [
    {
      "id": "SECURITY-PATCHING-1",
      "title": "Keycloak 26.8.2 w compose i chartcie ze strategią Recreate, nowy przebieg rejestracji w E2E",
      "scope": "Specyfikacja, K1 (Wybór wersji, Zmiany w compose i chartcie, Skutki zmiany linii, Rejestracja, Sposób przejścia, Kryteria akceptacji) i decyzje D1, D4, D5, T1, T2. Infra: tag obrazu Keycloaka w infra/docker-compose.yml oraz ten sam tag z digestem indeksu multi-arch w images.keycloak chartu, w jednym commicie; digest sprawdzony w rejestrze dla nowego tagu (D5). Chart: shop.deployment z opcjonalnym parametrem strategii, Deployment keycloak ze strategią Recreate, test strategii w infra/k8s/tests/test_workloads.py (D4). Przed zmianą ponowne sprawdzenie tagów quay.io i komunikatów keycloak/keycloak według reguły z „Wybór wersji”. E2E: registration.spec.ts przechodzi na przebieg dane → link z e-maila → ustawienie hasła → zalogowany, przypadki słabych haseł na kroku ustawiania hasła. Dokumentacja: walking-skeleton (wiersz „Rejestracja” i scenariusz E2E 3), abuse-limits (L11, kryterium 1), deploy („Chart shop”: strategia Keycloaka). Nie obejmuje: zmiennych środowiska, komendy startu, plików realmów, skryptów keycloak-config i create-admin, strategii pozostałych Deploymentów, innych obrazów usług (D2), stałej kontroli digestu w make check (T2), wdrożenia na serwer.",
      "acceptance_criteria": [
        "kc.sh --version w kontenerze keycloak stosu compose zwraca 26.8.2 (albo nowsze wydanie linii 26.8 wybrane według reguły z K1), a java -version co najmniej 21.0.13.1",
        "Tag Keycloaka w infra/docker-compose.yml i w images.keycloak chartu jest ten sam, a digest w chartcie jest równy digestowi, który rejestr zwraca dla tego tagu (docker buildx imagetools inspect, indeks z linux/amd64 i linux/arm64); raport zadania pokazuje komendę i wynik; make -C infra/k8s check przechodzi",
        "Render chartu (prod i ci): Deployment keycloak ma strategy.type Recreate bez rollingUpdate, każdy inny Deployment RollingUpdate z maxUnavailable 0; test w infra/k8s/tests/test_workloads.py sprawdza oba warunki i nie przechodzi, gdy keycloak wraca do RollingUpdate albo inny Deployment dostaje Recreate. To celowa zmiana istniejącej reguły: test strategii zmienia się razem z nią, a raport pokazuje jego wynik przed zmianą szablonu i po niej",
        "Na stosie z bazą keycloak założoną przez 26.6.0 make up kończy się zdrowym Keycloakiem, log ma migrację realmów master, shop i backoffice do nowej wersji, a customer-login.spec.ts i logowanie personelu z TOTP przechodzą bez make keycloak-reset",
        "E2E rejestracji przechodzi nowy przebieg do końca: formularz bez pola hasła, link z Mailpita, ustawienie hasła, „Zalogowany jako …”; po wylogowaniu logowanie tym hasłem działa. To celowa zmiana zachowania: scenariusz rejestracji w registration.spec.ts zmienia się razem z nią",
        "E2E rejestracji: hasło jednoznakowe, 11-znakowe i równe adresowi e-mail jest odrzucane na stronie ustawiania hasła komunikatem Keycloaka i klient nie zostaje zalogowany; hasło zgodne z polityką podane potem loguje",
        "Pełne make e2e (STORE_MODE=production), make test i make check przechodzą; testy inne niż rejestracji nie zmieniają się z powodu aktualizacji, a jeśli któryś wymaga zmiany, raport zadania podaje, która zmiana Keycloaka to powoduje",
        "make -C infra/k8s smoke przechodzi na nowym obrazie (Keycloak w trybie start, Job keycloak-config, Admin API, logowanie sklepu, create-admin)",
        "docs/features/walking-skeleton/spec.md i docs/features/abuse-limits/spec.md opisują nowy przebieg rejestracji i krok, na którym działa polityka haseł, a docs/features/deploy/spec.md („Chart shop”) strategię Recreate Keycloaka"
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "infra/docker-compose.yml",
        "infra/k8s/charts/shop/values.yaml",
        "infra/k8s/charts/shop/templates/_helpers.tpl",
        "infra/k8s/charts/shop/templates/keycloak.yaml",
        "infra/k8s/tests/test_workloads.py",
        "infra/k8s/smoke/",
        "e2e/tests/registration.spec.ts",
        "e2e/support/",
        "docs/features/walking-skeleton/spec.md",
        "docs/features/abuse-limits/spec.md",
        "docs/features/deploy/spec.md"
      ],
      "parallel_with": ["SECURITY-PATCHING-2", "SECURITY-PATCHING-3"]
    },
    {
      "id": "SECURITY-PATCHING-2",
      "title": "Audyty Composer i npm w make check, aktualizacja web-token/jwt-library",
      "scope": "Specyfikacja, K2 i założenie T3. apps/api, apps/store, apps/admin: cel audit w Makefile każdej aplikacji, wołany przez jej check (composer audit --locked --no-dev --abandoned=report; npm audit --omit=dev --audit-level=high). apps/api: aktualizacja web-token/jwt-library do najnowszego 4.2.x (co najmniej 4.2.6) samą zmianą composer.lock. Kolejność: najpierw cel audytu (kończy się błędem na obecnym lockfile), potem aktualizacja. Dokumentacja: główny CLAUDE.md („Środowisko”), CLAUDE.md trzech aplikacji, README.md, komentarz części apps w ci.yml. Nie obejmuje: zależności deweloperskich, e2e/package-lock.json, skanu obrazów, nowego joba CI.",
      "acceptance_criteria": [
        "Na lockfile sprzed aktualizacji make -C apps/api audit kończy się kodem różnym od 0 i wypisuje web-token/jwt-library; po aktualizacji kończy się kodem 0; raport zadania pokazuje oba uruchomienia",
        "make -C apps/store audit i make -C apps/admin audit kończą się kodem 0; na kopii lockfile w katalogu tymczasowym, z pakietem produkcyjnym w wersji objętej ogłoszeniem wagi high, ta sama komenda kończy się kodem różnym od 0",
        "Bez sieci (kontener z --network none) komendy audytu Composera i npm kończą się kodem różnym od 0",
        "check każdej z trzech aplikacji woła jej audit; cele nie maskują kodu wyjścia i nie podnoszą progu; jedyny wyjątek to wpis config.audit.ignore w apps/api/composer.json z identyfikatorem ogłoszenia i powodem (zadanie nie dodaje żadnego)",
        "make check i make test przechodzą, w tym testy API z tokenem dostępu",
        "Główny CLAUDE.md, CLAUDE.md trzech aplikacji, README.md i komentarz części apps w .github/workflows/ci.yml wymieniają audyt w check"
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/Makefile",
        "apps/api/composer.json",
        "apps/api/composer.lock",
        "apps/api/config/reference.php",
        "apps/store/Makefile",
        "apps/admin/Makefile",
        "apps/api/CLAUDE.md",
        "apps/store/CLAUDE.md",
        "apps/admin/CLAUDE.md",
        "CLAUDE.md",
        "README.md",
        ".github/workflows/ci.yml"
      ],
      "parallel_with": ["SECURITY-PATCHING-1", "SECURITY-PATCHING-3"]
    },
    {
      "id": "SECURITY-PATCHING-3",
      "title": "Obrazy bazowe Dockerfile z digestem i kontrola w make check",
      "scope": "Specyfikacja, K3 i założenie T6. Dockerfile API, sklepu, panelu i e2e: każdy FROM i COPY --from z obrazem zewnętrznym dostaje tag i digest indeksu multi-arch; FrankenPHP przechodzi na najnowsze wydanie poprawkowe linii 1.13 (tagi sprawdzane ponownie przed zmianą). Nowa kontrola: skrypt i testy w tools/base-images/, fragment make/base-images.mk z celem w CHECK_TARGETS. Nie obejmuje: infra/elasticsearch/Dockerfile (już ma digest), Dockerfile wpisanego w compose (garage-init), dyrektywy # syntax, obrazów usług, skanu obrazów.",
      "acceptance_criteria": [
        "Odwołania z tabeli K3 mają tag i digest równy digestowi, który rejestr zwraca dla tego tagu (docker buildx imagetools inspect; raport zadania pokazuje komendy i wyniki): dunglas/frankenphp (wydanie poprawkowe linii 1.13), composer, node w sklepie i panelu, nginxinc/nginx-unprivileged, mcr.microsoft.com/playwright; infra/elasticsearch/Dockerfile zostaje bez zmian",
        "Kontrola kończy się kodem różnym od 0 i wskazuje plik i linię dla: obrazu bez digestu, bez tagu, z tagiem latest, z samym digestem, z digestem o złej długości, z nazwą w zmiennej, dla from małymi literami, FROM --platform=…, COPY --from=<obraz> i dla nowego Dockerfile w dowolnym katalogu repo; testy pokrywają każdy przypadek oraz etap pośredni i scratch jako poprawne",
        "Kontrola uruchomiona poza repozytorium gita albo bez żadnego Dockerfile kończy się błędem, nie wynikiem „czysto”",
        "make check uruchamia kontrolę przez CHECK_TARGETS, a testy skryptu znajduje .github/scripts/script-tests.sh",
        "Obrazy prod API, sklepu i panelu budują się z nowych baz; make check, make test i make e2e (STORE_MODE=production) przechodzą; php -v w kontenerze API pokazuje wersję z nowego tagu",
        "Komentarze „Pinned: …” w Dockerfile podają wersję i sposób odczytu digestu"
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/Dockerfile",
        "apps/store/Dockerfile",
        "apps/admin/Dockerfile",
        "e2e/Dockerfile",
        "tools/base-images/",
        "make/base-images.mk"
      ],
      "parallel_with": ["SECURITY-PATCHING-1", "SECURITY-PATCHING-2", "SECURITY-PATCHING-4"]
    },
    {
      "id": "SECURITY-PATCHING-4",
      "title": "Dependabot dla obrazów i kontrola zgodności compose z chartem w CI",
      "scope": "Specyfikacja, K4 i decyzje D3, D5, T4, T5, T6. .github/dependabot.yml: aktualizacje wersji docker (apps/api, apps/store, apps/admin, infra/elasticsearch) i docker-compose (infra), raz w tygodniu, jedna grupa na ekosystem. .github/scripts/scope.sh: --k8s odpowiada yes także przy zmianie infra/docker-compose.yml, z przypadkami w test_scope.sh. Dokumentacja: główny CLAUDE.md („Sposób pracy”), infra/k8s/CLAUDE.md („Chart a compose”: warunek CI i zasada o PR-ach Dependabota), docs/features/deploy/spec.md („Weryfikacja bez serwera”), komentarze ci.yml, nagłówek scope.sh i komentarz w infra/k8s/tests/test_deploy_workflow.py. Nie obejmuje: ustawień repozytorium (krok sesji orkiestrującej za zgodą użytkownika), aktualizacji wersji dla composer, npm i github-actions, zmian w bramce github_trust.py, realmów Keycloaka jako warunku --k8s.",
      "acceptance_criteria": [
        ".github/dependabot.yml ma dokładnie dwa wpisy (docker z czterema katalogami, docker-compose dla infra), każdy z harmonogramem tygodniowym i jedną grupą obejmującą wszystkie obrazy; nie ma wpisów dla composer, npm ani github-actions; katalogu e2e nie ma na liście",
        "sh .github/scripts/test_scope.sh przechodzi z nowymi przypadkami: zmiana samego infra/docker-compose.yml daje zakres full i --k8s yes; zmiana innego pliku w infra poza infra/k8s (np. infra/keycloak/realms) dalej --k8s no; ścieżka w cudzysłowie i obie strony przeniesienia pliku też dają yes",
        "Na gałęzi z samą zmianą tagu obrazu w compose make -C infra/k8s check kończy się błędem, który nazywa obraz; po dopisaniu tagu i digestu w chartcie przechodzi; raport zadania pokazuje oba uruchomienia, a zmiany próbne nie trafiają do commitów",
        "Główny CLAUDE.md, infra/k8s/CLAUDE.md, docs/features/deploy/spec.md, komentarze ci.yml i nagłówek scope.sh podają nowy warunek uruchomienia make -C infra/k8s check; infra/k8s/CLAUDE.md ma zasadę, że PR Dependabota zmieniający obraz w compose sesja uzupełnia na polecenie użytkownika o tag i digest w images.* chartu, oraz że przy każdej zmianie obrazu digest pochodzi z rejestru dla nowego tagu, z komendą i wynikiem w opisie commita albo w komentarzu PR (D5)",
        "make -C infra/k8s check przechodzi"
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        ".github/dependabot.yml",
        ".github/scripts/scope.sh",
        ".github/scripts/test_scope.sh",
        ".github/workflows/ci.yml",
        "CLAUDE.md",
        "infra/k8s/CLAUDE.md",
        "infra/k8s/tests/test_deploy_workflow.py",
        "docs/features/deploy/spec.md"
      ],
      "parallel_with": ["SECURITY-PATCHING-3"]
    }
  ]
}
```
