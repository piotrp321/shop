# Eksperyment QA-001: audyt uwierzytelniania

Pierwszy eksperyment z agentami `qa-adversarial` i `architecture-reviewer` (definicje w `.claude/agents/`, commit
`660f3f9`). Cel: sprawdzić, co każdy z nich niezależnie znajduje w tym samym kodzie i jak różnią się ich wyniki.

## Warunki

- Data: 2026-10-30.
- Rewizja: `660f3f971aeb74be43f0d090ff7edb1892872b19` (`main`, czyste drzewo).
- Zakres: system uwierzytelniania (API, BFF sklepu, logowanie panelu, realmy Keycloak).
- Oba agenty uruchomione równolegle, w tle, przez orkiestratora (główna sesja Claude Code, Opus 5.5).
- Agenty nie dostały wcześniejszych review, wyników drugiego agenta ani wskazówek, czego szukać.
- Zakaz modyfikacji repozytorium; pliki tymczasowe tylko w scratchpadzie sesji (osobne katalogi `qa/` i `arch/`).

Polecenie (identyczne dla obu agentów poza ścieżką scratchpadu i ostatnim akapitem):

> Przeprowadź niezależny audyt systemu uwierzytelniania w repozytorium shop. Repozytorium: `<worktree>`.
> Rewizja: 660f3f9… (HEAD, czyste drzewo). Audytuj wyłącznie tę rewizję. Zasady projektu są w CLAUDE.md w katalogu
> głównym i w CLAUDE.md poszczególnych aplikacji. Ograniczenia: nie modyfikuj żadnych plików w repozytorium; inny
> audytor pracuje równolegle na tym samym katalogu; pliki tymczasowe w `<scratchpad>/qa|arch`; nie wykonuj
> commitów, nie zmieniaj branchy; nie uruchamiaj php/composer/npm/node na hoście; nie zatrzymuj ani nie restartuj
> stosu Docker. Raport po polsku: plik:linia, opis, scenariusz/uzasadnienie, ważność, potwierdzone (jak) czy
> przypuszczenie; na końcu, czego nie udało się sprawdzić i dlaczego.

## Zużycie

| Agent | Tokeny | Wywołania narzędzi | Czas |
|---|---|---|---|
| `qa-adversarial` | 201 030 | 80 | 14 min 28 s |
| `architecture-reviewer` | 132 378 | 35 | 6 min 1 s |

Dane z powiadomień o zakończeniu agentów. Bez kosztu weryfikacji przez orkiestratora.

## Środowisko i ograniczenia

- Worktree audytu nie miał uruchomionego stosu (`docker compose ps` pusty) ani własnego `.env`.
- Oba agenty użyły obrazów i wolumenów (`vendor`, `node_modules`) stosu `shop-t16` innego worktree
  (`tasks-16-24-skeleton`). Zgodność zależności sprawdzona: `composer.lock` identyczny z rewizją audytu
  (`diff` u architekta, hash `vendor/.composer-lock-hash` u QA). `node_modules` nie weryfikowano poza przejściem testów.
- Nagłówki JWKS obaj sprawdzili na Keycloaku stosu `shop-t16` (tylko GET), nie na stosie tej rewizji.
- QA pracował we własnej kopii (worktree agenta z tym samym HEAD i kopia w scratchpadzie), z wrapperami
  podmieniającymi `RUN` w Makefile'ach: kontenery `docker run --network none`, vendor tylko do odczytu.
- Nie uruchomiono: `make check`, pełnego `make -C apps/api test`, `make e2e`, prawdziwego logowania przez Keycloak
  (QA celowo, by nie wyzwolić brute force detection na kontach demo stosu `shop-t16`).
- Repozytorium po audycie: `git status` czysty.

## Wyniki testów (QA)

- `make -C apps/api test-unit`: OK, 211 testów, 597 asercji.
- API funkcjonalne `SecurityProblemDetailsTest`: OK, 11 testów.
- API funkcjonalne `CorsTest`, `Customer`, `StaffMeEndpointTest`, `InvalidTokenOnPublicEndpointTest`,
  `KeycloakOutageTest`: 110 testów, 99 OK, 11 błędów środowiskowych (7 bez Elasticsearch/Postgresa, 4 bez Keycloaka
  w izolowanej sieci).
- `make -C apps/store test` (z Redisem w prywatnym kontenerze): 22 pliki, 234 testy OK (w tym fuzz QA; bez niego
  21 plików, 231 testów).
- `make -C apps/admin test`: 11 plików, 95 testów OK.
- Testy własne QA (tylko w scratchpadzie):
  - `QaMalformedTokenTest`: 43 warianty nagłówka i payloadu × 2 endpointy = 86 przypadków, 4 z 5xx (B1).
  - `QaAlgTypeTest`: 10/10 z 500 (B1).
  - `poc/alg-type.php`, `poc/rotation.php`: potwierdzają B1 i B2.
  - `qa-fuzz.test.ts`: `sanitizeReturnTo`, 2 × 300 000 losowych wejść (`\`, `%2f`, `%5c`, `%2e`, tab, nowa linia,
    znaki fullwidth, `//`); każdy wynik to ścieżka własnego origin. OK.
  - 12 zniekształconych wariantów ciasteczka sesji: wszystkie odrzucone.

Architecture-reviewer nie uruchamiał testów (brak stosu, zakaz jego stawiania). Użył `curl`, `grep`, `diff`
i lektury kodu vendor w kontenerze.

## Zgłoszenia

### Potwierdzone

**1. Rotacja kluczy Keycloak wylogowuje klientów sklepu.** Zgłosili obaj, niezależnie (QA: Important, B2;
architekt: Medium, P2).

- `apps/api/config/packages/framework.yaml:15-17`: pula `cache.oidc` z TTL z odpowiedzi JWKS.
- Keycloak zwraca `Cache-Control: no-cache` bez `max-age` i `Expires` (`curl -D -` na `/protocol/openid-connect/certs`),
  więc `OidcTokenHandler` przyjmuje domyślne 3600 s.
- Handler nie pobiera JWKS ponownie przy nieznanym `kid`.
- `apps/store/app/auth/session/route.ts:33-35`: 401 z `/api/v1/me` kasuje sesję i ciasteczko.
- Skutek: przez godzinę po aktywacji nowego klucza (rotacja albo `make keycloak-reset`) każdy klient, który odświeży
  token, zostaje wylogowany; panel pokazuje `SESSION_REJECTED_MESSAGE`. Cache jest na filesystemie w bind mouncie,
  więc w dev przeżywa restart kontenera.
- Dowód (PoC QA, powtórzony przez orkiestratora, `poc/rotation.php`, handler jak w produkcji, `MockHttpClient`
  z `Cache-Control: no-cache`):

  ```
  token signed with k1 (JWKS cached): ACCEPTED (requests so far: 2)
  token signed with new k2, JWKS already served by Keycloak: REJECTED (InvalidSignatureException) (requests so far: 2)
  again: REJECTED (InvalidSignatureException) (requests so far: 2)
  same k2 token with an empty cache: ACCEPTED (requests so far: 4)
  ```

- Propozycje: QA – jednorazowe przeładowanie JWKS przy nieznanym `kid` z limitem częstotliwości albo krótszy TTL,
  sklep nie kasuje sesji po pierwszym 401. Architekt – bez kodu, procedura rotacji w spec (klucz pasywny dłużej
  niż TTL cache).

**2. Token z nie-stringowym `alg` daje 500 zamiast 401.** Tylko QA (Minor, B1).

- `apps/api/src/Shared/Infrastructure/Security/KeySourceOutageTokenHandler.php:29-37` łapie tylko
  `BadCredentialsException`; `OidcTokenHandler` łapie tylko `\Exception`. Dla `{"alg":["RS256"]}` i `{"alg":123}`
  `AlgorithmManager::get()` rzuca `TypeError` (`\Error`), który dochodzi do kernela.
- Dotyczy każdego endpointu, także publicznych (`/api/v1/products`, `/api/v1/categories`), bez logowania. Odpowiedź:
  `{"type":"about:blank","title":"Internal Server Error","status":500}`. Brak obejścia autoryzacji i wycieku danych;
  anonimowy klient może generować błędy poziomu error.
- Dowód (`poc/alg-type.php`, powtórzony przez orkiestratora):

  ```
  {"alg":["RS256"]}            => TypeError | AlgorithmManager::get(): Argument #1 ($algorithm) must be of type string, array given
  {"alg":123}                  => TypeError | AlgorithmManager::get(): Argument #1 ($algorithm) must be of type string, int given
  {"alg":"RS256","kid":["k1"]} => BadCredentialsException
  {"alg":"RS256","kid":"k1"}   => BadCredentialsException
  ```

  oraz test funkcjonalny `QaAlgTypeTest` (10/10 odpowiedzi 500).
- Propozycja: w dekoratorze zamieniać inne `\Throwable` na `BadCredentialsException`.

**3. Wywołanie API z `/auth/session` bez limitu czasu.** Tylko architekt (Low/Medium, P3).

- `apps/store/src/api/client.ts:10-15`: `createClient` bez `signal` i własnego `fetch`; `apps/store/src/auth/me.ts:10`.
- `grep -rn -E "signal|timeout|AbortSignal" src app` w `apps/store`: tylko `oidc.ts:123` (Keycloak, 2 s).
- Do Keycloaka i Redisa spec ustala 2 s; zawieszone API trzyma żądanie do domyślnych limitów undici.
- Potwierdzone lekturą kodu i grepem; skutku nie odtworzono.

**4. Pracownik bez żadnej roli dostaje `ROLE_STAFF`.** Obaj (architekt: Low, P4; QA: informacyjne, R6).

- `apps/api/src/Backoffice/Infrastructure/Security/StaffUser.php:44-47` zawsze zwraca `[ROLE_STAFF]`.
- Testy to akceptują: `StaffMeEndpointTest.php:38-39`, `DashboardEndpointTest.php:130-131`.
- Spec: „pracownik (dowolna rola)”. Do rozstrzygnięcia, czy chodzi o „co najmniej jedną rolę”.

### Potwierdzone w kodzie, nieodtworzone

**5. Utknięta blokada odświeżania kończy się wylogowaniem.** Tylko architekt (Medium, P1).

- `apps/store/src/auth/refresh.ts:161-162`: po 5 s z istniejącą blokadą zwraca `null`;
  `app/auth/session/route.ts:23-26`: `null` kasuje sesję z ważnym refresh tokenem.
- Każda inna awaria odświeżania kończy się 503 (`RefreshUnavailableError`) bez kasowania sesji.
- TTL blokady i czas czekania to po 5000 ms, więc ścieżka zachodzi głównie, gdy po wygaśnięciu blokady inne żądanie
  zakłada nową. Zachowanie opisane w spec (wiersze 745-746).
- Wyścigu nikt nie odtworzył. Testy QA refresh tokena przeszły, ale tego scenariusza nie obejmowały.

**6. Ciasteczko sesji sklepu bez `Max-Age`.** Tylko architekt (Low, P5).

- `apps/store/src/auth/cookies.ts:32-39`. Ciasteczko żyje do zamknięcia przeglądarki, a wizja zakłada długą sesję.
  Uzasadnienie w spec („o czasie życia sesji decyduje Redis”) jest nieścisłe; klucz w Redisie zostaje osierocony do
  wygaśnięcia TTL. Logowanie wraca bez hasła dzięki sesji SSO Keycloak.
- Zachowania przeglądarki nie testowano.

### Przypuszczenia (tylko QA, lektura kodu i konfiguracji)

- R1: brak `passwordPolicy` w obu realmach, `registrationAllowed: true` w `shop`.
- R2: konta demo z hasłami i sekretami TOTP w `infra/keycloak/realms/*.json`, bez rozdzielenia konfiguracji realmu
  od danych demo.
- R3: przy zimnym cache i niedziałającym Keycloaku każdy token Bearer (także śmieciowy) wywołuje discovery i JWKS
  (do 5 s) przed walidacją; możliwe blokowanie wątków PHP. Niemierzone.
- R4: token `header..sig` wywołuje deprecation `json_decode(null)` (`OidcTokenHandler.php:262`); odpowiedź nadal 401.
- R5: każdy odrzucony token loguje `error` ze stack trace; w prod `fingers_crossed` zrzuca bufor logów.
- R7: redirect URI klienta `admin` to `${ADMIN_URL}/*` (sklep ma dokładny `/auth/callback`).
- R8: `/auth/login` bez rate limitu, każdy GET tworzy klucz w Redisie (TTL 10 min).
- R9: wylogowanie przy awarii Redisa lub Keycloaka może zostawić aktywną sesję Redis lub SSO (opisane w kodzie).
- R10: Redis w compose bez hasła, tokeny w sesjach jako JSON (tylko dev).

## Sprawdzone bez zastrzeżeń

Obaj agenci zgodnie ocenili dobrze:

- Rozdział firewalli `admin` i `shop`: `iss`/`aud` per firewall, osobne klucze cache discovery, mapper audience tylko
  w access tokenie, `directAccessGrantsEnabled: false`.
- Wbudowany handler `oidc` Symfony bez własnego parsowania JWT; tylko RS256; `max_redirects=0`.
- BFF sklepu: tokeny tylko w Redisie, podpisane HMAC id w ciasteczku (`timingSafeEqual`), jednorazowa transakcja
  logowania (`GETDEL`), nowe id sesji przy logowaniu, `redirect_uri` z `STORE_URL`, blokada odświeżania przez
  `SET NX PX` ze zwolnieniem w Lua.
- Panel: tokeny w `InMemoryWebStorage`, w `sessionStorage` tylko PKCE i znacznik czasu, ograniczona pętla 401.
- CORS tylko dla `ADMIN_URL`, bez `allow_credentials`.

Werdykty: architekt – KEEP (architektura właściwa, punktowe poprawki); QA – INVESTIGATE (B1 i B2 do rozstrzygnięcia).

## Porównanie agentów

| | `qa-adversarial` | `architecture-reviewer` |
|---|---|---|
| Metoda | dynamiczna: testy, fuzz, PoC w izolowanych kontenerach | statyczna: kod vs spec i wizja, kod vendor, `curl`, `grep` |
| Zgłoszenia potwierdzone | 2 (B1, B2) | 5 (P1-P5), z czego 2 odtworzone dopiero przez innych |
| Przypuszczenia | 10 (R1-R10) | 0 (oznaczał, czego nie odtworzył) |
| Unikalne trafienia | B1 (500 przy `alg`) | P1, P3, P5 |
| Wspólne | rotacja kluczy (B2 = P2), rola pracownika (R6 = P4) | |
| Koszt | 201 tys. tokenów, 14,5 min | 132 tys. tokenów, 6 min |

Obserwacje:

- Najmocniejsze ustalenie (rotacja kluczy) oba agenty znalazły niezależnie, z tą samą diagnozą i tymi samymi
  plikami. Różniły się propozycją: QA – zmiana w kodzie, architekt – procedura operacyjna.
- B1 wymagał fuzzowania wejścia; lektura kodu go nie ujawniła.
- P1, P3 i P5 to niespójności semantyki między częściami systemu i względem wizji. Testy ich nie łapią, bo
  odzwierciedlają spec.
- QA ocenia ważność wyżej przy problemach odtworzonych, architekt wyżej przy skutkach skali (60 tys. aktywnych).
- Oba agenty respektowały ograniczenia: brak zmian w repozytorium, brak zatrzymywania stosu, nikt nie logował się do
  współdzielonego Keycloaka.

## Weryfikacja przez orkiestratora

- Powtórzone `poc/alg-type.php` i `poc/rotation.php` (kontener `--network none`, vendor tylko do odczytu): wyniki
  jak wyżej.
- Przeczytane fragmenty kodu z P1, P3, P4, P5 i dekorator z B1: zgodne z opisem agentów.
- Przypuszczeń R1-R10 nie weryfikowano.
