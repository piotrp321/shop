# SECURITY-PATCHING: aktualne komponenty i stała kontrola podatności

Epik zamyka zaległości z audytu bezpieczeństwa `docs/reports/security-audit-2026-11-18.md` (SA-01, SA-16, część
SA-38; rozdział 13, P0, i rozdział 14, epik 1) i dodaje kontrolę, która wykrywa kolejne. Każda sekcja K1–K4 to jedna
zmiana niezależna od pozostałych. Działanie funkcji opisują ich specyfikacje; ta opisuje tylko zmiany. Każde
twierdzenie o obecnym kodzie dotyczy `origin/main` z 2026-11-24 (`476d84c4`, po PR #918), a o wydaniach, obrazach
i komunikatach bezpieczeństwa: stanu rejestrów i stron dostawców z 2026-11-24.

Issues: #767

## Cel i zakres

- Keycloak działa w wersji, która ma wszystkie opublikowane poprawki bezpieczeństwa, w tym poprawkę przejęcia konta
  przez reset hasła (CVE-2026-18963) i poprawione środowisko Java (CVE-2026-114938); logowanie klienta, rejestracja
  i logowanie personelu z TOTP działają po aktualizacji; Keycloak nigdy nie działa w dwóch wersjach na jednej bazie
  (K1).
- `make check` kończy się błędem, gdy zależność produkcyjna API, sklepu albo panelu ma znaną podatność (K2).
- Obrazy bazowe w `Dockerfile` są przypięte skrótem, a `make check` nie przepuszcza obrazu bez skrótu (K3).
- GitHub zgłasza podatne zależności (alerty i aktualizacje bezpieczeństwa Dependabota), a nowe wersje obrazów
  przychodzą raz w tygodniu jako zgrupowane PR-y; PR, który zmienia obraz w compose bez digestu w chartcie, nie
  przechodzi CI (K4).

## Stan na `origin/main`

| Pozycja zgłoszenia | Stan | Do zrobienia |
|---|---|---|
| Keycloak (SA-01) | `quay.io/keycloak/keycloak:26.6.0` w `infra/docker-compose.yml:104` i `infra/k8s/charts/shop/values.yaml:38` (digest `sha256:b0e5dbce…`, wydanie z 2026-04-08, OpenJDK 21.0.10). PR #918 zmienił oba pliki, ale nie obraz Keycloaka. | tak: K1 |
| Alerty i aktualizacje bezpieczeństwa Dependabota (SA-16) | Wyłączone: `gh api repos/piotrp321/shop/vulnerability-alerts` → 404, `dependabot_security_updates: disabled`. Nie ma `.github/dependabot.yml`. | tak: K4 |
| `composer audit`, `npm audit --omit=dev` w `make check` (SA-16) | Żaden `Makefile` ani `make/*.mk` ich nie woła. `composer audit --locked --no-dev` na obecnym lockfile kończy się kodem 1: 13 ogłoszeń dla `web-token/jwt-library` 4.2.3 (naprawione w 4.2.4 i 4.2.6). `npm audit --omit=dev` sklepu i panelu: 0 podatności. | tak: K2 |
| Obrazy bazowe z digestem (SA-38) | Bez skrótu: `apps/api/Dockerfile:8` (FrankenPHP) i `:24` (Composer), `apps/store/Dockerfile:4` i `apps/admin/Dockerfile:4` (Node), `apps/admin/Dockerfile:36` (nginx), `e2e/Dockerfile:2` (Playwright). Ze skrótem: `infra/elasticsearch/Dockerfile:5`. | tak: K3 |
| Symfony 8.1.11 (SA-17), Next.js i Node 24.22.3 (SA-38) | Zrobione w #733 (Symfony, FrankenPHP 1.13.5) i #732 (`next` `^16.4.6`, `node:24.22.3-alpine`). | nie |

Poza audytami `--omit=dev`: zależności deweloperskie mają dziś ogłoszenia wagi „moderate” (sklep 7, panel 11: łańcuch
`typescript-eslint` i `@vue/test-utils`), a `e2e` 5 wagi „high” (łańcuch `@lhci/cli`). Nie trafiają do obrazów
produkcyjnych; po K4 pokażą je alerty Dependabota.

## Decyzje

Decyzje użytkownika z 2026-11-24:

| # | Decyzja | Wybór | Odrzucony wariant |
|---|---|---|---|
| D1 | Rejestracja klienta po aktualizacji Keycloaka | Nowy domyślny przebieg Keycloaka: dane → link z e-maila → ustawienie hasła → zalogowany; E2E sprawdza go aż do zalogowania | Przełącznik „Always set password on register form” (hasło w formularzu): oznaczony przez Keycloaka jako przestarzały i odradzany ze względów bezpieczeństwa |
| D2 | Zakres aktualizacji obrazów | Keycloak i obrazy bazowe `Dockerfile` z #767 | Wydania poprawkowe pozostałych usług (Postgres, Elasticsearch, imgproxy, RabbitMQ, Garage) i nowe linie (Mailpit, imgproxy 4) |
| D3 | Wykrywanie nowych wersji obrazów | Cotygodniowe, zgrupowane PR-y Dependabota dla obrazów | Same alerty i aktualizacje bezpieczeństwa (nie obejmują obrazów kontenerów) |
| D4 | Strategia wdrożenia Keycloaka w chartcie | `Recreate`, tylko dla Keycloaka, z testem chartu; pozostałe Deploymenty zostają przy `RollingUpdate` z `maxUnavailable: 0` | `RollingUpdate` także dla Keycloaka: przy zmianie wersji stary pod działa przez chwilę na zmigrowanej bazie, czego dostawca nie wspiera |
| D5 | Zgodność digestu w chartcie z rejestrem | Przy każdej aktualizacji obrazu sprawdzenie, że digest w chartcie to digest nowego tagu w rejestrze | Poleganie na istniejącym teście, który sprawdza tylko równość tagów z compose i format digestu |

Założenia techniczne, zaakceptowane razem ze specyfikacją 2026-11-24:

| # | Założenie | Uzasadnienie |
|---|---|---|
| T1 | Wersja docelowa Keycloaka to 26.8.2; compose dostaje tag `26.8.2`, chart ten sam tag z digestem bieżącego obrazu tego tagu | Sekcja K1, „Wybór wersji” |
| T2 | Sprawdzenie z D5 to krok aktualizacji obrazu z wynikiem w raporcie zadania albo w PR, nie stała kontrola w `make check` | Tagi w rejestrach są zmienne (`26.8.2` wskazywał w dwa tygodnie na cztery budowy), więc stałe porównanie z rejestrem zatrzymywałoby `checks` bez żadnej zmiany w repo i uzależniało je od dostępności rejestru. Skutek: nowa budowa pod tym samym tagiem może pozostać niezauważona („Ryzyka i ograniczenia”) |
| T3 | Audyt Composera blokuje przy każdym ogłoszeniu dla pakietu produkcyjnego, audyt npm od wagi „high” | Raport audytu zaleca blokowanie High i Critical w zależnościach produkcyjnych. Ogłoszenia Composera często nie mają wagi (wszystkie 13 obecnych), więc progu wagi nie da się tam zastosować |
| T4 | `scope.sh --k8s` odpowiada `yes` także przy zmianie `infra/docker-compose.yml` | Bez tego PR Dependabota, który zmienia obraz w compose, przechodzi CI mimo rozjazdu z chartem (`infra/k8s/CLAUDE.md`, „Chart a compose”); zmiana compose jest rzadka, więc koszt CI jest mały |
| T5 | Dependabot nie dostaje aktualizacji wersji dla Composera, npm ani akcji GitHuba | D3 dotyczy obrazów; podatne pakiety i akcje pokrywają alerty i aktualizacje bezpieczeństwa |
| T6 | Obraz Playwrighta (`e2e/Dockerfile`) dostaje digest, ale Dependabot go nie aktualizuje | Wersja obrazu musi być równa `@playwright/test` z `e2e/package.json`; zmienia się je razem, ręcznie |

## Poza zakresem

- Aktualizacja obrazów usług innych niż Keycloak (D2): Postgres 18.6 → 18.7, Elasticsearch 9.4.0, imgproxy v3.31.1,
  RabbitMQ 4.4.0, Garage v2.4.0, Mailpit v1.27.11. Pierwszy PR Dependabota dla compose pokaże je razem; przyjęcie to
  osobna zmiana.
- Skan obrazów (Trivy), CodeQL, suma kontrolna instalatora k3s i przypięcie chartów platformy (pozostałe części SA-16
  i SA-38). Zgłoszone przez Trivy podatności warstw obrazu `nginx-unprivileged:1.30.6-alpine` zostają: tag jest
  bieżący, a przypięcie skrótem ich nie usuwa.
- Wzmocnienia konfiguracji Keycloaka z audytu (SA-08…SA-11, SA-28): limity prób, zdarzenia, redirect URI.
- Dyrektywa `# syntax=docker/dockerfile:1` i `Dockerfile` wpisany w compose (`garage-init`): compose trzyma tagi,
  digesty obrazów usług są w chartcie.
- Wdrożenie na worf.pl. Epik kończy się merge'em; wdrożenie uruchamia użytkownik osobnym poleceniem.

## K1. Keycloak 26.8.2

### Wybór wersji

Obecna: 26.6.0 (2026-04-08). Docelowa: 26.8.2 (2026-11-05), najnowsze wydanie Keycloaka.

Oficjalny komunikat: GHSA-6qm4-x97f-55fh w repozytorium `keycloak/keycloak` (opublikowany 2026-11-19; w GitHub
Advisory Database jako GHSA-4gv3-mc9p-5wqc z 2026-08-18), CVE-2026-18963, waga Critical (CVSS 9.1):
nieuwierzytelniony atakujący kończy reset hasła dowolnego użytkownika bez linku z e-maila i ustawia własne hasło.
Zakres podatny: `>= 26.0.0, < 26.4.15`, `>= 26.5.0, < 26.6.6`, `>= 26.7.0, < 26.7.2`. Wersje z poprawką: 26.4.15,
26.6.6, 26.7.2. Komunikat nie podaje obejścia. W realmie `shop` reset hasła jest włączony.

| Linia | Najnowszy obraz na quay.io | Ocena |
|---|---|---|
| 26.6 | 26.6.4 (2026-06-26) | Bez poprawki CVE-2026-18963. Wersje 26.6.5 i 26.6.6 istnieją tylko jako tagi w repozytorium kodu, bez wydania i bez obrazu, więc poprawki nie da się wziąć w obecnej linii |
| 26.4 | 26.4.7 | Wersji 26.4.15 nie ma na quay.io |
| 26.7 | 26.7.5 (2026-09-30) | Ma poprawkę CVE-2026-18963, ale linia skończyła się dzień przed 26.8.0. Nie ma poprawek z 26.8.1 (19 pozycji CVE, m.in. CVE-2026-94218: obejście wymuszonej konfiguracji 2FA, CVE-2026-107121: SMTP bez STARTTLS) ani z 26.8.2 (33 pozycje, m.in. CVE-2026-107889: XSS na stronie logowania, CVE-2026-103112: przejęcie konta przez wyścig uwierzytelniania, CVE-2026-107124: przejęcie sesji przy wylogowaniu) |
| 26.8 | 26.8.2 (2026-11-05) | Jedyna linia z obrazem, która ma wszystkie opublikowane poprawki. GitHub Advisory Database: 34 ogłoszenia (bez duplikatów) obejmują `keycloak-services` 26.6.0, żadne 26.8.2 |

Obraz: tag `26.8.2` wskazuje od 2026-11-19 na budowę `26.8.2-3`
(`sha256:7e66a96e4739239070aeaf72e4c5decf0f7f682cfc725b9f7ea2b336eaba38b3`) z OpenJDK 21.0.13.1. Wpis zespołu
Keycloaka z 2026-11-18 (Java4Shell, CVE-2026-114938: błąd dekodera Base64 w OpenJDK, osiągalny przed
uwierzytelnieniem na procesorach x86-64 z AVX-512 VBMI) podaje tę budowę jako naprawioną; budowy `26.8.2-1` i `-2`
oraz obraz 26.6.0 (OpenJDK 21.0.10, zmierzone) mają niepoprawione środowisko Java.

Zadanie przed zmianą sprawdza stan jeszcze raz (tagi quay.io, lista komunikatów repozytorium `keycloak/keycloak`):
nowsza budowa albo wydanie poprawkowe linii 26.8 zastępuje 26.8.2 (po przeczytaniu not migracyjnych tego wydania);
nowa linia (26.9) nie, chyba że komunikat bezpieczeństwa nie ma poprawki w 26.8: wtedy decyzja użytkownika.

### Zmiany w compose i chartcie

| Plik | Zmiana |
|---|---|
| `infra/docker-compose.yml` | `image: quay.io/keycloak/keycloak:26.8.2` |
| `infra/k8s/charts/shop/values.yaml` | `images.keycloak: quay.io/keycloak/keycloak:26.8.2@sha256:7e66a96e…` (digest indeksu multi-arch tego tagu) |
| `infra/k8s/charts/shop/templates/_helpers.tpl` | `shop.deployment` przyjmuje opcjonalny parametr strategii; bez niego Deployment ma jak dziś `RollingUpdate`, `maxUnavailable: 0`, `maxSurge: 1` |
| `infra/k8s/charts/shop/templates/keycloak.yaml` | Deployment `keycloak` ze strategią `Recreate` (D4), bez bloku `rollingUpdate` (Kubernetes odrzuca go przy `Recreate`) |
| `infra/k8s/tests/test_workloads.py` | Test strategii: `keycloak` ma `Recreate`, każdy inny Deployment `RollingUpdate` z `maxUnavailable: 0` |

Nic więcej: zmienne środowiska, komenda startu, healthcheck, pliki realmów, `keycloak-config.sh`,
`create-admin-keycloak.sh`, pozostałe szablony chartu i profil `prod` zostają bez zmian (eksperyment niżej). Zmiana
tagu w compose i w chartcie jest w jednym commicie.

**Digest a rejestr (D5).** `make -C infra/k8s check` sprawdza tylko, że tag w chartcie jest taki jak w compose i że
digest ma poprawny format; digest innego tagu albo innej wersji by przeszedł. Dlatego aktualizacja obrazu obejmuje
sprawdzenie w rejestrze:

```sh
docker buildx imagetools inspect quay.io/keycloak/keycloak:<tag> --format '{{.Manifest.Digest}}'
```

Wynik jest równy digestowi w `images.keycloak`, a manifest jest indeksem z platformami `linux/amd64` (serwer, CI)
i `linux/arm64`. Raport zadania podaje komendę i wynik. Sesja prowadząca epik powtarza sprawdzenie przed oznaczeniem
końcowego PR jako gotowego: gdy tag wskazuje już na inną budowę, chart dostaje jej digest po sprawdzeniu, czym ta
budowa się różni, i kryteria 1 i 2 są sprawdzane ponownie.

**Strategia `Recreate` (D4).** Kubernetes zatrzymuje stary pod Keycloaka, zanim uruchomi nowy, więc bazę migruje
i obsługuje zawsze jedna wersja. Skutki:

- Przy zmianie szablonu poda Keycloaka (obraz, zmienne, zasoby) logowanie i odświeżanie tokenów nie działają od
  zatrzymania starego poda do gotowości nowego: czas pobrania obrazu, startu i migracji. Sesje użytkowników są
  w bazie i przetrwają restart.
- Wdrożenie, które nie zmienia Keycloaka, go nie restartuje: etykiety poda (`shop.labels`) nie zawierają rewizji.
- Nowy pod, który nie staje się gotowy, nie zostawia działającego starego (inaczej niż przy `maxUnavailable: 0`):
  logowanie nie działa do naprawy. Job `keycloak-config` czeka na gotowego Keycloaka, więc wdrożenie zatrzymuje się
  na nim. Reguły alertów chartu mają `for:` co najmniej 10 minut, więc sam restart ich nie wywoła.
- Pozostałe Deploymenty się nie zmieniają.

### Skutki zmiany linii 26.6 → 26.8

Noty migracyjne 26.6.1–26.8.2 z przewodnika aktualizacji, porównane z konfiguracją projektu. „Sprawdzone” oznacza
eksperyment z 2026-11-24: obraz `26.8.2-3` z plikami realmów repo i komendą startu z compose, na Postgresie 18.6,
raz na bazie założonej przez 26.6.0 (migracja), raz na pustej (świeży import).

| Zmiana w Keycloaku | Stan projektu | Skutek |
|---|---|---|
| 26.7.0: przy włączonej rejestracji i „Verify Email” formularz rejestracji nie ma pola hasła; hasło ustawia się po weryfikacji adresu | `shop`: `registrationAllowed` i `verifyEmail` | Zmienia się przebieg rejestracji, także w istniejącym realmie po migracji (sprawdzone). D1, sekcja „Rejestracja” |
| 26.8.2: Admin REST API wymaga tokenu z zakresem `kc-admin-api` i właściwym `aud` | `kcadm` (`keycloak-config`, `create-admin`), `KeycloakAdmin.php` (dane demo) i test dymny logują się klientem `admin-cli` realmu `master` | Bez zmian: `admin-cli` dostaje zakres przy migracji i w nowej instalacji. Sprawdzone: `GET /admin/realms/shop` i `partialImport` → 200, także przez adres inny niż `KC_HOSTNAME` (`kcadm` w kontenerze) |
| 26.7.0: „Configure OTP” i „Update password” po „Verify Email” w kolejności akcji wymaganych | `backoffice`: akcje z priorytetami w pliku realmu, `verifyEmail: false`; `create-admin` wysyła `UPDATE_PASSWORD` i `CONFIGURE_TOTP` | Bez skutku dla logowania. Po migracji istniejącej bazy priorytety to 54 i 57 (po `VERIFY_EMAIL` 50), po świeżym imporcie `backoffice` jak w pliku. Logowanie hasłem i TOTP sprawdzone w obu przypadkach |
| 26.6.3: wildcard w redirect URI nie obejmuje nazwy hosta | `${ADMIN_URL}/*` (wildcard w ścieżce), `${STORE_URL}/auth/callback` | Bez skutku |
| 26.6.3: żądania CORS z `Origin` spoza „Web Origins” dostają 403 od razu | `admin`: `webOrigins` = `${ADMIN_URL}`; `store` woła Keycloaka z serwera | Bez skutku |
| 26.7.3: redirect URI z parametrami OIDC odrzucane | `post.logout.redirect.uris`: `${STORE_URL}/` i `+` | Bez skutku |
| 26.8.1: przy włączonym STARTTLS wysyłka bez udanego STARTTLS kończy się błędem | Mailpit: `starttls: false`; Brevo (`prod`): `starttls: true`, port 587 | Bez skutku; po wdrożeniu potwierdza to e-mail z `create-admin` |
| 26.8.0: nieudane logowania zapisywane w bazie; wygaśnięcie refresh tokenu ograniczone bezczynnością sesji | `bruteForceProtected: true`; czasy sesji z plików realmów | Bez zmian konfiguracji; blokada konta przetrwa restart, a Keycloak odmawia odświeżenia tokenu po bezczynności sesji jak dotąd. Potwierdza pełne E2E |
| 26.6.2: introspekcja tokenu sprawdza `aud`; UserInfo odrzuca lekkie tokeny | API weryfikuje podpis JWT lokalnie; sklep i panel nie wołają introspekcji ani UserInfo | Bez skutku |
| 26.7.0 i 26.8.0: zmiany wyglądu strony logowania | Brak własnego motywu; E2E używa `#username`, `#password`, `#kc-login`, `#otp` | Identyfikatory bez zmian (sprawdzone) |
| 26.8.0: e-mail nie jest oznaczany jako zweryfikowany przez akcje inne niż weryfikacja | `create-admin` (`execute-actions-email`), `backoffice` bez `verifyEmail` | Bez skutku |
| Migracja schematu bazy jest automatyczna i jednokierunkowa | Baza `keycloak` w Postgresie (wolumen compose, PVC na serwerze) | Sekcja „Sposób przejścia” |
| 26.8.2: aktualizacja krocząca z wcześniejszych wersji nie jest wspierana (zalecane zatrzymanie wszystkich węzłów albo `Recreate`) | Chart: `RollingUpdate` z `maxSurge: 1` dla każdego Deploymentu | D4: `Recreate` dla Keycloaka |

### Rejestracja (D1)

Przebieg po aktualizacji, sprawdzony na realmie `shop` z repo:

1. Formularz rejestracji ma pola e-mail, imię i nazwisko (`#email`, `#firstName`, `#lastName`), bez hasła.
2. Po wysłaniu Keycloak pokazuje stronę „Weryfikacja e-maila” i wysyła wiadomość z linkiem.
3. Link otwiera stronę „Aktualizacja hasła” (`#password-new`, `#password-confirm`).
4. Hasło niezgodne z polityką realmu (L11 z `docs/features/abuse-limits/spec.md`) jest odrzucane na tej stronie
   z komunikatem Keycloaka („minimalna długość 12”, „nie może być nazwą użytkownika”) i strona zostaje.
5. Po ustawieniu hasła zgodnego z polityką klient jest zalogowany w sklepie.

Konto powstaje w kroku 1, bez hasła i z niezweryfikowanym adresem; do kroku 5 nie da się nim zalogować. Realm,
polityka haseł i `customerAccounts` się nie zmieniają. W `prod` rejestracja jest dziś wyłączona.

### Sposób przejścia

- **Lokalnie i w CI.** `make up` pobiera nowy obraz. Istniejąca baza `keycloak` z 26.6.0 migruje się przy starcie
  (sprawdzone: log „Migrating older model” do 26.8.2 dla `master`, `shop`, `backoffice`, start w kilkanaście sekund,
  konta demo logują się dalej). Powrót do 26.6.0 na zmigrowanej bazie nie jest wspierany: `make keycloak-reset`
  zakłada bazę od nowa.
- **Serwer.** Wdrożenie zatrzymuje pod Keycloaka 26.6.0 i dopiero potem uruchamia nowy (`Recreate`, D4), który
  migruje bazę przy starcie; logowanie nie działa w tym czasie. Powód: przy `RollingUpdate` stary pod odpowiadałby
  jeszcze na zmigrowanej bazie. Eksperyment w trybie `start` na jednej bazie pokazał, że nowy węzeł nie dołącza do
  starego, sam migruje bazę i oba zgłaszają gotowość, czyli nic takiego stanu nie blokuje. Cofnięcia wersji nie ma:
  awaryjnie baza `keycloak` wraca z kopii (`restore`) albo powstaje od nowa (`keycloak-config` zakłada realmy,
  `create-admin` konta personelu). Kopie na worf.pl są dziś wyłączone (#690).

### Kryteria akceptacji

1. `docker compose exec keycloak /opt/keycloak/bin/kc.sh --version` zwraca 26.8.2 (albo nowsze wydanie linii 26.8
   wybrane według „Wybór wersji”), a `java -version` w kontenerze co najmniej 21.0.13.1.
2. Tag Keycloaka w `infra/docker-compose.yml` i w `images.keycloak` chartu jest ten sam, a digest w chartcie jest
   równy digestowi, który rejestr zwraca dla tego tagu (`docker buildx imagetools inspect`, indeks z `linux/amd64`
   i `linux/arm64`); raport zadania pokazuje komendę i wynik. `make -C infra/k8s check` przechodzi.
3. Na stosie z bazą `keycloak` założoną przez 26.6.0 `make up` kończy się zdrowym Keycloakiem, log ma migrację
   realmów do nowej wersji, a `customer-login.spec.ts` i logowanie personelu z TOTP przechodzą bez
   `make keycloak-reset`.
4. E2E rejestracji (`e2e/tests/registration.spec.ts`) przechodzi nowy przebieg do końca: formularz bez pola hasła,
   link z Mailpita, ustawienie hasła, „Zalogowany jako …”; po wylogowaniu logowanie tym hasłem działa.
5. E2E rejestracji: hasło jednoznakowe, 11-znakowe i równe adresowi e-mail jest odrzucane na stronie ustawiania
   hasła komunikatem Keycloaka i klient nie zostaje zalogowany; hasło zgodne z polityką podane potem loguje.
6. Pełne `make e2e` (`STORE_MODE=production`), `make test` i `make check` przechodzą. Testy inne niż rejestracji nie
   zmieniają się z powodu aktualizacji; jeśli któryś wymaga zmiany, raport zadania podaje, która zmiana Keycloaka to
   powoduje.
7. `make -C infra/k8s smoke` przechodzi na nowym obrazie (Keycloak w trybie `start`, Job `keycloak-config`, Admin API,
   logowanie sklepu, `create-admin`).
8. Render chartu (`prod` i `ci`): Deployment `keycloak` ma `strategy.type: Recreate` bez `rollingUpdate`, każdy inny
   Deployment `RollingUpdate` z `maxUnavailable: 0`. Test w `infra/k8s/tests/test_workloads.py` sprawdza oba
   warunki i nie przechodzi, gdy `keycloak` wraca do `RollingUpdate` albo inny Deployment dostaje `Recreate`
   (raport pokazuje jego wynik przed zmianą szablonu i po niej).
9. `docs/features/walking-skeleton/spec.md` (wiersz „Rejestracja” tabeli realmów i scenariusz E2E 3)
   i `docs/features/abuse-limits/spec.md` (L11, kryterium 1) opisują nowy przebieg rejestracji,
   a `docs/features/deploy/spec.md` („Chart `shop`”) strategię Keycloaka.

### Sprawdzenie logowania klienta i administratora

| Gdzie | Klient (realm `shop`) | Administrator i personel (realm `backoffice`) |
|---|---|---|
| Compose (`make e2e`, w CI check `e2e`) | `customer-login.spec.ts`: logowanie kontem demo, wylogowanie, koniec sesji Keycloaka; `registration.spec.ts`: rejestracja do zalogowania | `loginStaff` (`e2e/support/staff-login.ts`): hasło i kod TOTP dla ról `admin`, `support`, `warehouse`, `marketing` we wszystkich testach panelu, np. `staff-dashboard.spec.ts` |
| API (`make -C apps/api test`, w CI check `checks`) | Suity `integration` i `functional` na działającym Keycloaku: discovery OIDC (`KeycloakDiscoveryTest`) i endpointy z tokenem (`MeEndpointTest`) | Suita `demo-data`: `partialImport` kont przez Admin API (`admin-cli`) |
| Klaster k3d (`make -C infra/k8s smoke`; ten sam test przed każdym wdrożeniem w `deploy.yml`) | `infra/k8s/smoke/checks.py`: pełne logowanie sklepu (code flow), sekret klienta `store` | Logowanie administratora Keycloaka i Admin API, Job `keycloak-config`, przekierowanie panelu na stronę logowania, `create-admin` z e-mailem |
| worf.pl po wdrożeniu (osobne polecenie użytkownika) | Rejestracja i link logowania są w `prod` wyłączone; logowanie sklepu sprawdza test dymny przed wdrożeniem | Użytkownik loguje się do panelu hasłem i TOTP; `make -C infra/k8s status` pokazuje działający pod Keycloaka i zakończony Job `keycloak-config` |

## K2. Audyty zależności w `make check`

**Stan.** `make check` nie sprawdza podatności zależności (tabela „Stan na `origin/main`”).

**Wymagane.**

- `make -C apps/api check` uruchamia `composer audit --locked --no-dev`: każde ogłoszenie dla pakietu produkcyjnego
  kończy cel błędem (T3). Pakiety porzucone są wypisywane, ale nie blokują (`--abandoned=report`).
- `make -C apps/store check` i `make -C apps/admin check` uruchamiają `npm audit --omit=dev --audit-level=high`.
- Audyt jest osobnym celem `audit` w `Makefile` aplikacji, wołanym przez jej `check`, z opisem przy celu. Główny
  `make check` i część `apps` w CI (`make check-code`) dostają go bez zmiany workflowu.
- `web-token/jwt-library` przechodzi z 4.2.3 na najnowsze wydanie 4.2.x (co najmniej 4.2.6), samą zmianą
  `composer.lock` (`composer.json` ma `^4.2`).
- Wyjątek od audytu Composera to wyłącznie wpis `config.audit.ignore` w `apps/api/composer.json` z identyfikatorem
  ogłoszenia i powodem. Audyt npm nie ma wyjątków: podatny pakiet produkcyjny się aktualizuje. Cele nie maskują
  kodu wyjścia (`|| true`, `-` przed komendą) i nie podnoszą progu.
- Błąd narzędzia albo brak sieci to błąd celu, nie wynik „czysto”.

**Skutek dla pracy.** Nowe ogłoszenie dla zależności produkcyjnej zatrzymuje `checks` w każdym PR o pełnym zakresie,
dopóki zależność nie zostanie zaktualizowana (PR aktualizacji bezpieczeństwa Dependabota z K4 albo ręcznie). PR-y
tylko z dokumentacją, narzędziami repo albo `infra/k8s` nie uruchamiają audytu.

**Kryteria akceptacji.**

1. Na lockfile sprzed aktualizacji `make -C apps/api audit` kończy się kodem różnym od 0 i wypisuje
   `web-token/jwt-library`; po aktualizacji kończy się kodem 0. Raport zadania pokazuje oba uruchomienia.
2. `make -C apps/store audit` i `make -C apps/admin audit` kończą się kodem 0; na kopii lockfile w katalogu
   tymczasowym, z pakietem produkcyjnym w wersji objętej ogłoszeniem wagi „high”, ta sama komenda kończy się kodem
   różnym od 0.
3. Bez sieci (kontener z `--network none`) komendy audytu Composera i npm kończą się kodem różnym od 0.
4. `make check` i `make test` przechodzą, w tym testy API z tokenem dostępu (weryfikuje go `web-token/jwt-library`).
5. Główny `CLAUDE.md` („Środowisko”), `CLAUDE.md` trzech aplikacji (lista komend), `README.md` i komentarz części
   `apps` w `.github/workflows/ci.yml` wymieniają audyt w `check`.

## K3. Obrazy bazowe z digestem

**Stan.** Sześć odwołań do obrazów bazowych nie ma skrótu (tabela „Stan na `origin/main`”), więc ten sam commit może
zbudować inny obraz produkcyjny.

**Wymagane.**

| Plik | Dziś | Po zmianie |
|---|---|---|
| `apps/api/Dockerfile` (`FROM`, etap `runtime`) | `dunglas/frankenphp:1.13.5-php8.5.12-trixie` | `dunglas/frankenphp:1.13.6-php8.5.13-trixie@sha256:…` (najnowsze wydanie poprawkowe linii 1.13; 1.14.0 to nowa linia) |
| `apps/api/Dockerfile` (`COPY --from`) | `composer:2.10.3` | ten sam tag z `@sha256:…` |
| `apps/store/Dockerfile`, `apps/admin/Dockerfile` (etap `node`) | `node:24.22.3-alpine` | ten sam tag z `@sha256:…` (bieżące wydanie linii 24) |
| `apps/admin/Dockerfile` (etap `prod`) | `nginxinc/nginx-unprivileged:1.30.6-alpine` | ten sam tag z `@sha256:…` (bieżące wydanie linii 1.30) |
| `e2e/Dockerfile` | `mcr.microsoft.com/playwright:v1.64.1-noble` | ten sam tag z `@sha256:…` (T6) |

- Skrót to digest indeksu multi-arch tagu (`docker buildx imagetools inspect <obraz:tag>`), jak w chartcie: ten sam
  `Dockerfile` buduje się na arm64 (lokalnie) i amd64 (CI, serwer).
- Zadanie sprawdza tagi jeszcze raz: nowsze wydanie poprawkowe tej samej linii zastępuje podane wyżej.
- `make check` dostaje kontrolę (fragment `make/base-images.mk`, cel w `CHECK_TARGETS`, skrypt i testy
  w `tools/base-images/`): każdy `FROM` i każde `COPY --from` z obrazem spoza `Dockerfile` w każdym śledzonym
  `Dockerfile` ma postać `nazwa:tag@sha256:<64 znaki hex>`. Odwołania do wcześniejszego etapu i `scratch` są
  dozwolone.

**Kryteria akceptacji.**

1. Wszystkie odwołania z tabeli mają tag i digest równy digestowi, który rejestr zwraca dla tego tagu
   (`docker buildx imagetools inspect`; raport zadania pokazuje komendy i wyniki); `infra/elasticsearch/Dockerfile`
   zostaje bez zmian.
2. Kontrola kończy się kodem różnym od 0 i wskazuje plik i linię dla: obrazu bez digestu, bez tagu, z tagiem `latest`,
   z samym digestem, z digestem o złej długości, z nazwą w zmiennej (`${IMAGE}`), dla `from` małymi literami,
   `FROM --platform=…`, `COPY --from=<obraz>` i dla nowego `Dockerfile` w dowolnym katalogu repo. Testy pokrywają
   każdy przypadek oraz etap pośredni i `scratch` jako poprawne.
3. Kontrola uruchomiona poza repozytorium gita albo bez żadnego `Dockerfile` kończy się błędem, nie wynikiem „czysto”.
4. Obrazy `prod` API, sklepu i panelu budują się z nowych baz; `make check`, `make test` i `make e2e`
   (`STORE_MODE=production`) przechodzą; `php -v` w kontenerze API pokazuje wersję z nowego tagu.
5. Komentarze „Pinned: …” w `Dockerfile` podają wersję i sposób odczytu digestu.

## K4. Dependabot i zgodność compose z chartem

**Stan.** GitHub nie zgłasza podatnych zależności, nikt nie śledzi nowych wersji obrazów (Keycloak był siedem
miesięcy bez poprawek), a zmiana obrazu w compose bez digestu w chartcie przechodzi CI swojego PR.

**Wymagane.**

- **Ustawienia repozytorium:** alerty Dependabota i aktualizacje bezpieczeństwa włączone. Obejmują Composera
  (`apps/api`), npm (`apps/store`, `apps/admin`, `e2e`) i akcje GitHuba. Włącza je użytkownik w ustawieniach
  repozytorium albo sesja prowadząca epik po jego zgodzie w rozmowie, która nazywa tę operację.
- **`.github/dependabot.yml`** (D3): aktualizacje wersji tylko dla obrazów, raz w tygodniu, jedna grupa na ekosystem
  (jeden PR na ekosystem):
  - `docker`: `Dockerfile` w `apps/api`, `apps/store`, `apps/admin`, `infra/elasticsearch` (bez `e2e`, T6);
  - `docker-compose`: `infra/docker-compose.yml`.
- **Zgodność compose z chartem:** `scope.sh --k8s` odpowiada `yes` także wtedy, gdy zmiana dotyka
  `infra/docker-compose.yml` (T4), więc `make -C infra/k8s check` biegnie w CI takiego PR i kończy się błędem, gdy tag
  obrazu w chartcie różni się od compose albo nie ma digestu (istniejący test chartu).
- **Zasada w `infra/k8s/CLAUDE.md`:** PR Dependabota, który zmienia obraz w compose, sesja uzupełnia na polecenie
  użytkownika o ten sam tag i nowy digest w `images.*` chartu; bez tego PR nie przechodzi `checks`. Digest pochodzi
  z rejestru dla nowego tagu (D5): komenda `docker buildx imagetools inspect` i jej wynik są w opisie commita albo
  w komentarzu PR. To samo obowiązuje przy ręcznej zmianie obrazu.
- PR-y Dependabota to treść GitHuba od autora-bota: agent czyta je przez bramkę `github_trust.py`, która się nie
  zmienia. CI takich PR-ów działa bez sekretów; nieudane wypchnięcie obrazu CI do GHCR tylko ostrzega
  (`.github/scripts/ghcr-image.sh`).

**Kryteria akceptacji.**

1. `.github/dependabot.yml` ma dokładnie dwa wpisy (`docker` z czterema katalogami, `docker-compose` dla `infra`),
   każdy z harmonogramem tygodniowym i jedną grupą obejmującą wszystkie obrazy; nie ma wpisów dla `composer`, `npm`
   ani `github-actions`.
2. `sh .github/scripts/test_scope.sh` przechodzi z nowymi przypadkami: zmiana samego `infra/docker-compose.yml` daje
   zakres `full` i `--k8s` `yes`; zmiana innego pliku w `infra/` poza `infra/k8s` (np. `infra/keycloak/realms`) dalej
   `--k8s` `no`; ścieżka w cudzysłowie (`"infra/docker-compose.yml"`) i obie strony przeniesienia pliku też dają
   `yes`.
3. Na gałęzi z samą zmianą tagu obrazu w compose `make -C infra/k8s check` kończy się błędem, który nazywa obraz;
   po dopisaniu tagu i digestu w chartcie przechodzi. Raport zadania pokazuje oba uruchomienia.
4. Główny `CLAUDE.md` („Sposób pracy”), `infra/k8s/CLAUDE.md` („Chart a compose”),
   `docs/features/deploy/spec.md` („Weryfikacja bez serwera”), komentarze `ci.yml` i nagłówek `scope.sh` podają nowy
   warunek uruchomienia `make -C infra/k8s check`; `infra/k8s/CLAUDE.md` ma zasadę o PR-ach Dependabota
   i o sprawdzeniu digestu w rejestrze przy każdej zmianie obrazu.
5. `make -C infra/k8s check` przechodzi (ma testy workflowów).

**Po merge'u** (poza zadaniami; Dependabot czyta konfigurację z `main`): alerty i aktualizacje bezpieczeństwa są
włączone (`gh api repos/piotrp321/shop/vulnerability-alerts` → 204, `dependabot_security_updates: enabled`), strona
Dependabota repozytorium nie pokazuje błędu konfiguracji, a pierwszy tygodniowy przebieg otwiera najwyżej dwa PR-y.

## Zmiany dokumentacji

Wykonywane razem z implementacją, w zadaniu, które zmienia opisane zachowanie:

| Dokument | Zmiana | Sekcja |
|---|---|---|
| `docs/features/walking-skeleton/spec.md` | Przebieg rejestracji w tabeli realmów i w scenariuszu E2E | K1 |
| `docs/features/abuse-limits/spec.md` | L11, kryterium 1: hasło odrzucane na kroku ustawiania hasła | K1 |
| `CLAUDE.md` | „Środowisko”: audyty w `check`; „Sposób pracy”: warunek `make -C infra/k8s check` w CI | K2, K4 |
| `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `apps/admin/CLAUDE.md`, `README.md` | Audyt na liście tego, co robi `check` | K2 |
| `infra/k8s/CLAUDE.md` | „Chart a compose”: warunek CI, PR-y Dependabota, sprawdzenie digestu w rejestrze | K4 |
| `docs/features/deploy/spec.md` | „Chart `shop`”: Keycloak ze strategią `Recreate`; „Weryfikacja bez serwera”: warunek uruchomienia `make -C infra/k8s check` w CI | K1, K4 |

`docs/vision.md` i ADR-y się nie zmieniają. Po ukończeniu epiku zostaje ta specyfikacja; plan się usuwa.

## Ryzyka i ograniczenia

- **Okno do wdrożenia.** Do wdrożenia tej zmiany worf.pl działa na Keycloaku 26.6.0 z niepoprawionym OpenJDK. Czy
  serwer spełnia warunek CVE-2026-114938 (procesor z AVX-512 VBMI), nie zostało sprawdzone. Doraźne zabezpieczenie
  z wpisu Keycloaka (`JAVA_OPTS_APPEND=-XX:-UseBASE64Intrinsics`) nie jest częścią epiku.
- **Migracja bazy jest jednokierunkowa**, a kopie na worf.pl są wyłączone (#690): awaryjna ścieżka to założenie bazy
  `keycloak` od nowa i ponowne `create-admin`.
- **Przerwa w logowaniu przy zmianie Keycloaka** (D4): od zatrzymania starego poda do gotowości nowego, a przy
  nieudanym starcie nowego do naprawy. Etap demo dopuszcza przestój.
- **Nowa budowa pod tym samym tagiem może pozostać niezauważona** (D5, T2): digest jest sprawdzany przy aktualizacji
  obrazu, nie stale, więc CI jej nie pokaże. Compose trzyma sam tag, a Dependabot porównuje wersje tagów, więc może
  nie zgłosić budowy opublikowanej pod tym samym tagiem (jak `26.8.2-3` po `26.8.2-2`). Chart działa wtedy dalej na
  przypiętym digeście, a CI (świeży runner) pobiera bieżącą budowę tagu. Lokalny `make up` nie pobiera obrazu, który
  już jest w pamięci Dockera: maszyna z wcześniejszą budową tagu zostaje na niej do `docker compose pull`.
- **Audyty zależą od usług zewnętrznych** (Packagist, rejestr npm): ich awaria zatrzymuje `checks` do ponowienia.
- **Dependabot proponuje też nowe linie** (imgproxy 4, FrankenPHP 1.14): PR to sygnał, nie zobowiązanie; przyjęcie
  nowej linii to osobna decyzja.
- **Różnica między compose a chartem przy zmiennym tagu:** compose pobiera obraz po tagu, chart po digeście. Kryterium
  K1.1 (`java -version`) potwierdza, że compose działa na poprawionej budowie.

## Źródła i eksperymenty

- Komunikaty: `gh api repos/keycloak/keycloak/security-advisories` (GHSA-6qm4-x97f-55fh),
  `gh api /advisories/GHSA-4gv3-mc9p-5wqc`, `gh api "/advisories?ecosystem=maven&affects=org.keycloak:keycloak-services@<wersja>"`.
- Wydania i obrazy: `gh api repos/keycloak/keycloak/releases/tags/<wersja>` (sekcje „Security fixes”),
  `https://quay.io/api/v1/repository/keycloak/keycloak/tag/` (tagi i digesty linii 26.6–26.8).
- Noty migracyjne: `https://www.keycloak.org/docs/latest/upgrading/` („Migrating to 26.6.1” … „26.8.2”).
- Java4Shell: `https://www.keycloak.org/2026/11/java4shell-vulnerability-cve-2026-114938`.
- Eksperyment 2026-11-24 (jednorazowe kontenery poza stosem projektu, Postgres 18.6, realmy z repo): 26.6.0 jako stan
  wyjściowy; `26.8.2-3` na tej samej bazie (migracja) i na pustej (import); logowanie klienta i personelu z TOTP,
  rejestracja w obu wariantach D1, polityka haseł, `admin-cli` i `kcadm`; dwa węzły różnych wersji w trybie `start`.
- Audyty: `composer audit --locked [--no-dev]` (Composer 2.10.3) i `npm audit --package-lock-only [--omit=dev]` na
  lockfile z `476d84c4`; ustawienia repozytorium przez `gh api repos/piotrp321/shop`.
