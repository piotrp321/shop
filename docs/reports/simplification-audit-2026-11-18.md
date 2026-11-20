# Audyt uproszczeń aplikacji: zależności, zbędny kod, nadmierna złożoność

- Repozytorium: `piotrp321/shop`, rewizja `1aeb5076` (`origin/main` z 2026-11-18).
- Zakres: `apps/api`, `apps/store`, `apps/admin`, `e2e`, `load`, `infra` (compose, chart, skrypty), produktowa część
  `.github` i `make`. Poza zakresem: infrastruktura Agentic Software House, skille, prompty, AO, `tools/`.
- Audyt niczego nie zmienił w repozytorium. Nie powstały issues ani PR-y. Eksperymenty działały na jednorazowej kopii
  kodu poza repo, w osobnych kontenerach.
- Załącznik z researchem alternatyw i pełną listą źródeł: `simplification-audit-2026-11-18/alternatywy-zaleznosci-i-uslug.md`.

## 1. Executive summary

**Aplikacja nie jest zaśmiecona martwym kodem ani zbędnymi zależnościami. Realnie da się usunąć około 6% kodu
produkcyjnego bez żadnej decyzji produktowej, a 10–12% po kilku decyzjach właściciela.** Kto oczekuje redukcji
o 30–50%, nie znajdzie jej w tym repozytorium.

Najważniejsze ustalenia:

1. **Martwego kodu jest bardzo mało.** Detektor (PHPStan + `shipmonk/dead-code-detector`) znalazł w API 17 martwych
   metod i 1 stałą (13 metod i stała w 69 tys. linii kodu produkcyjnego, reszta w testach), a kolejne około 55 jest
   używanych tylko przez testy. W sklepie jest 1 nieużywany plik,
   w panelu 35 nieużywanych plików prymitywów shadcn-vue (około 800 linii). To ostatnie sprawdziłem eksperymentem.
2. **Zależności są uzasadnione.** Każdy z 39 pakietów Composera i 56 pakietów npm jest używany. Bez decyzji
   architektonicznej nie da się usunąć żadnego pakietu. Jedyna realna redukcja to RabbitMQ: jedna usługa, jeden pakiet
   (`symfony/amqp-messenger`) i jedno rozszerzenie PHP (`amqp`).
3. **Własny kod w większości już korzysta z natywnych mechanizmów.** Uwierzytelnianie to natywny handler `oidc`
   Symfony, outbox to natywny transport Doctrine, sklep używa Metadata API, `revalidateTag`, `useOptimistic`.
   Natywne zamienniki, których jeszcze nie użyto, są małe: zegar PSR-20, atrybut `#[RateLimit]`, `components.responses`
   w OpenAPI, `RouterLink custom`, `storageState` w Playwrighcie.
4. **Największym źródłem zbędnej złożoności jest powielanie tego samego kodu technicznego między modułami i epikami**,
   a nie warstwy DDD. Ten sam kursor keyset istnieje w 8 kopiach (904 linie z wyjątkami), zegar w 5, subskrybent
   `no-store` w 4, parsowanie problem details w panelu w 7, klej list panelu w 9. Część tych kopii wymusza zasada
   „brak wspólnego kodu między contextami”, stosowana także do kodu czysto technicznego.
5. **Drugim źródłem jest kod zbudowany pod przyszłość, której jeszcze nie ma**: RabbitMQ jako drugi skok tej samej
   kolejki, pula 4317 zdjęć w R2 i rekoncyliacja kont Keycloak dla profili danych demo, których nie uruchamia żadna
   automatyczna ścieżka, 4,1 tys. linii kodu i testów kopii zapasowych przy kopiach wyłączonych w `prod`.
6. **Architektura (DDD, heksagon, modularny monolit) jest adekwatna w rdzeniu** (Ordering, Pricing, Inventory, Cart,
   Payment) i stosowana mechanicznie na obrzeżach: pulpit Backoffice, strona odczytu (porty dla zapytań SQL), opis
   kontraktu w dwóch konwencjach jednocześnie.
7. **Ślady pracy agentów są wyraźne, ale kosztują umiarkowanie**: kopiuj-wklej zamiast wyciągnięcia wspólnego kodu,
   209 defensywnych `\assert` po walidacji, 17,4% linii API to komentarze, pliki `CLAUDE.md` aplikacji opisują kod
   wbrew własnej zasadzie, testy bywają trzy razy większe od skryptu, który sprawdzają.

Pięć zmian na początek (szczegóły w sekcji 12):

1. Decyzja o RabbitMQ przed startem epiku ASYNC-RELIABILITY.
2. Wspólne klocki techniczne API (kursor, zegar, `no-store`, rozpakowanie błędu szyny, odpowiedzi OpenAPI).
3. Panel: usunięcie nieużywanych prymitywów i trzy małe helpery (lista, problem details, historia).
4. Dane demo: odchudzenie zakresu do tego, co dziś działa, i strażnik `prod` dla komend `demo-data:*`.
5. Testy generyczne z routera i OpenAPI zamiast kopii per endpoint, wspólny trait pomocników.

## 2. Metodyka

| Krok | Narzędzie i sposób | Wynik |
|---|---|---|
| Stan kodu | `git fetch`, worktree na `origin/main` (`1aeb5076`), `git ls-files` + `wc -l` | inwentarz w sekcji 3 |
| Zależności PHP | `composer show/outdated/audit`, `shipmonk/composer-dependency-analyser`, `composer-unused` | sekcja 4 |
| Zależności JS | `npm ci`, `npm ls/outdated/audit`, `knip` (tryb zwykły i `--production`) | sekcja 4 |
| Martwy kod PHP | PHPStan z `shipmonk/dead-code-detector` (świadomy Symfony i Doctrine), dwa przebiegi: testy liczone jako użycie i testy wyłączone | sekcja 5 |
| Martwy kod JS | `knip` z wtyczkami Next.js, Vue, Vite, vitest | sekcja 5 |
| Przegląd kodu | 9 niezależnych audytorów (Opus) na eksporcie bez `.git`, bez wcześniejszych raportów: Catalog, Pricing + Inventory, Ordering + Cart + Payment + Backoffice, mechanizmy przekrojowe API, testy API, sklep, panel, infrastruktura, dane demo + E2E | sekcje 5–9 |
| Research alternatyw | skill `deep-research`: 3 researcherów (zależności PHP, JS, usługi) i synteza | załącznik |
| Weryfikacja | własne pomiary krzyżowe (`grep`, `diff`, skrypty liczące) i dwa kontrolowane eksperymenty usunięcia kodu | sekcja 11 |

Narzędzia analityczne były instalowane wyłącznie w jednorazowej kopii (`git archive` do katalogu roboczego poza repo,
kontenery z obrazów `shop-*-api` i `node:24.22.3-alpine`). `composer.json` i `package.json` w repo są nietknięte.

Ograniczenia:

- Nie uruchamiałem testów API (wymagają całego stosu) ani prototypów zmian architektury. Rekomendacje dotyczące
  RabbitMQ, konwencji OpenAPI i danych demo są zweryfikowane lekturą kodu i konfiguracji, nie eksperymentem.
- Liczby linii pochodzą z `wc -l` i zawierają komentarze oraz puste linie, chyba że napisano inaczej.
- „Linie objęte” to kod, którego zmiana dotyczy. „Zysk” to szacowana różnica po zmianie. Przy każdej liczbie podaję,
  czy jest zmierzona, czy szacowana.
- Część faktów z researchu pochodzi ze źródeł wtórnych. Załącznik wskazuje je wprost.

## 3. Inwentaryzacja kodu

| Obszar | Pliki | Linie | Uwagi |
|---|---:|---:|---|
| `apps/api/src` | 1 378 | 69 447 | komentarze 12 108 (17,4%), puste 9 807, `use`/`namespace` 8 591, kod około 38 900 |
| `apps/api/tests` | 706 | 111 174 | 4 146 metod testowych, 1,6 linii testu na linię kodu |
| `apps/api/config` | 22 | 4 455 | w tym `reference.php` 1 947 (generowany) i `nelmio_api_doc.yaml` 1 450 |
| `apps/api/migrations` | 41 | 1 600 | |
| `apps/store` (kod / testy) | – | około 10 700 / 17 500 | bez generowanego `schema.d.ts` (3 454) |
| `apps/admin` (kod / testy) | – | około 21 800 / 30 400 | bez generowanego `schema.d.ts` (5 337) |
| `e2e` | 73 | 9 109 | 36 specyfikacji, 350 testów |
| `infra` | 159 | 24 083 | `infra/k8s` 21 790, z czego testy 9 995 i skrypty 5 047 |
| `.github` (część produktowa) | – | około 2 300 | workflowy 1 163, skrypty 361, testy skryptów 808 |
| `load`, `make`, Makefile | – | 867 + 346 | |

Moduły API (`src`, linie z `wc -l`) i stosunek testów do kodu:

| Moduł | Pliki | Linie | Testy / kod |
|---|---:|---:|---:|
| Catalog | 318 | 16 086 | 1,52 |
| Pricing | 268 | 13 164 | 1,36 |
| Ordering | 237 | 11 554 | 1,80 |
| DemoData | 92 | 8 300 | 1,05 |
| Inventory | 131 | 6 162 | 1,81 |
| Cart | 115 | 4 835 | 1,44 |
| Payment | 74 | 3 293 | 1,62 |
| Shared | 64 | 2 973 | 2,63 |
| Backoffice | 46 | 1 577 | 1,38 |
| PaymentSimulator | 27 | 1 294 | 1,60 |
| Customer | 5 | 196 | 1,10 |

Dwa fakty z tego inwentarza, które ustawiają resztę raportu:

- **Dane demo to 15% kodu produkcyjnego API.** Moduł `DemoData` (8 300 linii) i seedy modułów (2 396) mają razem
  10,7 tys. linii oraz 12,9 tys. linii testów.
- **Kontrolery to w jednej trzeciej dokumentacja.** 58 kontrolerów ma 5 430 linii, z czego 1 868 (34%) to atrybuty
  `#[OA\…]`.

## 4. Zależności: inwentaryzacja i ocena

### 4.1 Composer (`apps/api`)

29 pakietów w `require`, 10 w `require-dev`, w `composer.lock` 117 pakietów produkcyjnych i 63 deweloperskie.
`composer audit`: 0 ostrzeżeń, 0 pakietów porzuconych. `composer outdated --direct`: 13 pakietów, same wydania
poprawkowe i drobne.

| Pakiet | Użycie (dowód) | Kondycja | Werdykt |
|---|---|---|---|
| `symfony/*` 8.1 (framework, console, messenger, security, serializer, validator, mailer, http-client, rate-limiter, uid, yaml, runtime, flex, dotenv) | cały kod; `dotenv` przez `symfony/runtime` i `tests/bootstrap.php` | wydania bieżące | KEEP |
| `doctrine/orm`, `doctrine-bundle`, `doctrine-migrations-bundle` | rekordy ORM, 41 migracji; DBAL bezpośrednio w 687 miejscach | ORM 3.8.0 wydane 2026-11-14 | KEEP |
| `symfony/doctrine-messenger` | 6 transportów `doctrine://` w `messenger.yaml` | – | KEEP |
| `symfony/amqp-messenger` + `ext-amqp` | jeden transport `events` | wymaga rozszerzenia `amqp` | **REMOVE warunkowo** (sekcja 9.1) |
| `web-token/jwt-library` | brak importu w `src`; wymaga go natywny `OidcTokenHandler` | aktywny (4.2.7) | KEEP (narzędzia zgłaszają go jako nieużywany – fałszywy alarm) |
| `symfony/property-access`, `symfony/property-info` | `MapRequestPayload`, serializer transportu, Nelmio | – | KEEP |
| `elasticsearch/elasticsearch` ~9.4 | `Catalog/Infrastructure/Search` | 9.5.0 dostępne; ciągnie `elastic/transport`, `open-telemetry/api`, `php-http/discovery` | KEEP |
| `league/flysystem-bundle`, `flysystem-async-aws-s3` | 2 storage, oba na adapterze `asyncaws` | aktywne | KEEP (zamiana na `async-aws/s3` usuwa 3 pakiety, ale wymaga własnej klasy i łamie zasadę projektu; zysk nie pokrywa kosztu) |
| `nelmio/api-doc-bundle` | eksport OpenAPI, 1 968 użyć atrybutów `zircote/swagger-php` | 5.14.0 z 2026-11-17 | KEEP |
| `nelmio/cors-bundle` | CORS dla panelu | brak natywnego CORS w Symfony | KEEP |
| `sentry/sentry-symfony` | `sentry.yaml`, filtry w `Shared/Infrastructure/Observability` | aktywny | KEEP |
| `ext-iconv` | brak wywołań `iconv*` w `src` | pozostałość szkieletu Symfony | REMOVE (deklaracja, wartość kosmetyczna) |
| dev: PHPStan + 3 rozszerzenia, PHPUnit 13, Deptrac, PHP CS Fixer, Foundry, DAMA, browser-kit | wszystkie w `make check` / `make test`; 6 fabryk Foundry, każda używana | aktywne | KEEP |

Higiena, nie redukcja: analizator znalazł **40 zależności cienia**, czyli pakietów i rozszerzeń używanych w kodzie, ale
niezadeklarowanych w `composer.json`. Większość dostarcza `framework-bundle` i to jest w porządku. Warto zadeklarować
te, od których kod zależy mocno i których wersję dziś ustala inny pakiet: `doctrine/dbal` (687 użyć),
`zircote/swagger-php` (1 968), `ext-mbstring` (92), `ext-pdo_pgsql`, `ext-pcntl`, `psr/clock` albo `symfony/clock`.
To zmiana `composer.json` bez nowych pakietów w `vendor`, ale wymaga zgody zgodnie z zasadą projektu.

### 4.2 npm

| Aplikacja | Bezpośrednie | Zainstalowane | `node_modules` | `npm audit` |
|---|---:|---:|---:|---|
| `apps/store` | 12 + 15 dev | około 600 | 787 MB | 21 zgłoszeń (15 wysokich), łańcuch narzędzi build/test |
| `apps/admin` | 13 + 16 dev | około 380 | 320 MB | 22 zgłoszenia (12 wysokich), łańcuch narzędzi build/test |
| `e2e` | 5 dev | – | – | nie sprawdzano |

Zgłoszenia `npm audit` dotyczą głównie wspólnego łańcucha `picomatch` / `tinyglobby` / `fdir` (vite, vitest,
typescript-eslint). Nie oceniałem ich wpływu, to temat audytu bezpieczeństwa.

Werdykt dla wszystkich 56 pakietów: **KEEP**. Każdy ma użycie w kodzie, konfiguracji albo Makefile.

- `openapi-typescript`: `knip` zgłasza go jako nieużywany w obu aplikacjach. To fałszywy alarm, wywołuje go
  `make api-client` (`Makefile:70-71`).
- `class-variance-authority` + `clsx` + `tailwind-merge`: standardowy zestaw shadcn, bez nakładania się.
- `pinia`: jeden store (50 linii). Zamiana na `reactive` oszczędza tylko pakiet, nie warto.
- `@vueuse/core`: i tak jest zależnością `reka-ui`.
- `openid-client` (sklep) i `oidc-client-ts` (panel): zostają. Auth.js jest od września 2025 w trybie utrzymania pod
  opieką zespołu Better Auth, a Better Auth wymaga własnej bazy i nie daje rozproszonej blokady odświeżania tokenu.
- `redis` (node-redis 6): zostaje, Redis uznaje ioredis za zastąpiony.
- `sharp` jest importowany w `apps/store/tests/marketing-images.test.ts`, a nie ma go w `package.json` (przychodzi
  z `next`). Do dopisania w `devDependencies` albo do świadomego pozostawienia.

Ryzyka do obserwowania, bez migracji teraz:

- `openapi-fetch`: zespół zaproponował w grudniu 2025 tryb utrzymania, rozwój idzie w `openapi-typescript` 8.
- `@lhci/cli`: niska aktywność projektu.
- TypeScript 7.0.2 w `e2e` i 6.0.3 w aplikacjach. To zalecany układ przejściowy do wydania 7.1 (vue-tsc nie działa
  z 7.0), ale powód warto zapisać przy wersji.
- `PWTEST_SHARD_WEIGHTS` w `make/e2e.mk` to nieudokumentowana zmienna Playwrighta.

### 4.3 Usługi i obrazy

| Usługa | Kto jej używa | Werdykt | Uzasadnienie |
|---|---|---|---|
| PostgreSQL 18.6 | wszystkie moduły, Keycloak, 6 transportów Messengera | KEEP | źródło prawdy |
| Redis 8.10 | API: tylko pula rate limitera; sklep: sesje, licznik logowań, blokada odświeżania | KEEP | sesje sklepu muszą być współdzielone między replikami |
| Elasticsearch 9.4 + `analysis-stempel` | wyszukiwarka i fasety | KEEP | przy 10 mln produktów z fasetami i polskim stemmerem nie ma udokumentowanego zamiennika w Postgresie |
| **RabbitMQ 4.4 + `rabbitmq-init`** | jeden transport `events` jako relay z outboksu | **REPLACE_NATIVE** (decyzja) | sekcja 9.1 |
| Keycloak 26.6 | klienci i pracownicy, dwa realmy | KEEP | jedna instancja na dwa realmy to już konsolidacja |
| Garage 2.4 + `garage-init` | oryginały zdjęć | INVESTIGATE w `prod`, SIMPLIFY init | sekcja 9.2 |
| imgproxy 3.31 | podpisane adresy obrazów | KEEP | research wskazuje linię v4 z poprawkami bezpieczeństwa; do sprawdzenia przy aktualizacji |
| Varnish 9.1 | cache odpowiedzi API dla sklepu, purge po tagach | KEEP | do ponownej oceny po teście obciążeniowym; ISR trzyma tylko HTML stron |
| Mailpit | dev: SMTP; `prod`: potwierdzenia zamówień bez ingressu | KEEP dev, SIMPLIFY `prod` | w `prod` to skrzynka, której nikt nie czyta |
| FrankenPHP 1.13.5 / PHP 8.5.12, Node 24.22, nginx-unprivileged, Playwright 1.64 | obrazy aplikacji | KEEP | etapy `prod` i `dev` rozdzielone poprawnie |

Brakuje automatu do aktualizacji wersji: nie ma `dependabot.yml` ani `renovate.json`, a tagi i digesty 12 obrazów
w `values.yaml` oraz 13 obrazów narzędzi są podbijane ręcznie. Dependabot jest natywny dla GitHuba i obsłuży Composer,
npm, Dockerfile i akcje. Digesty w `values.yaml` wymagają Renovate, czyli nowej aplikacji GitHub i zgody właściciela.

## 5. Martwy i zbędny kod

### 5.1 Wyniki narzędzi

| Narzędzie | Zgłoszenia | Po weryfikacji |
|---|---|---|
| dead-code-detector, testy liczone jako użycie | 303: 285 nieczytanych właściwości, 17 metod, 1 stała | 259 właściwości należy do klas `*Response` czytanych przez `JsonResponse` (fałszywy alarm). Metody i stała są martwe. |
| dead-code-detector, testy wyłączone | 461 | dodatkowe około 55 metod i stałych używanych wyłącznie przez testy |
| composer-dependency-analyser | 13 „nieużywanych” pakietów | 12 fałszywych alarmów (bundle, konfiguracja, runtime), 1 trafny (`ext-iconv`) |
| knip, sklep | 1 plik, 29 eksportów, 8 typów | plik trafny; eksporty to głównie części shadcn i stałe |
| knip, panel | 2 pliki, 105 eksportów, 9 typów | 1 plik to fałszywy alarm (`eslint.config.d.ts`, potrzebny testowi lintera) |

### 5.2 Co jest do usunięcia

| ID | Element | Dowód | Linie | Ryzyko |
|---|---|---|---:|---|
| R-1 | Panel: 35 plików nieużywanych prymitywów shadcn-vue (`checkbox/*`, 10 części `dropdown-menu`, 5 części `card`, części `combobox`, `select`, `sheet`, `table`, `dialog`, `alert`, `breadcrumb`) | eksperyment: usunięcie, potem `vue-tsc`, ESLint, 2 619 testów i `vite build` zielone | około 800 | niskie |
| R-2 | Sklep: `src/components/ui/checkbox.tsx` | 0 importów; eksperyment: typecheck i ESLint zielone | 32 | brak |
| R-3 | API: metoda `equals()` w 11 identyfikatorach i value objectach bez żadnego wywołania (`Cart\Domain\ProductId`, `VariantId`, `Catalog\…\ImageKey`, `ProductId`, `Inventory\…\ProductId`, `Ordering\Domain\OrderId`, `ProductId`, `VariantId`, `Payment\Domain\PaymentId`, `Pricing\…\OrderId`, `UsageLimit`) | detektor, także z testami | około 45 | niskie |
| R-4 | API: `CategoryRepository::save()` (zapis idzie przez `saveAll()`), `ProductRepository::all()` wraz z implementacjami | detektor; `grep` potwierdza brak wywołań w `src` | około 40 | niskie; implementacji Doctrine używają testy integracyjne |
| R-5 | API: `Inventory\Application\Command\InvalidReservation` i jego tłumaczenia w dwóch handlerach | żaden moduł go nie łapie, domena ma własny wyjątek z tym samym interfejsem | około 40 | niskie |
| R-6 | API: shim zgodności wersji, podwójna publikacja `ProductCreated` (`IntegrationEvents`, `IndexProduct`) | docblock „Remove it … in the next version” | około 20 + 25 w testach | niskie, po potwierdzeniu, że outbox i `events` na serwerze nie mają starych wiadomości |
| R-7 | API: `InvalidPromotion::STARTS_AT`, nieużywany provider `ProductTextRulesEndpointsTest::methodsAndFields`, `PER_PAGE_OPTIONS` w `apps/admin/src/customers/useCustomerOrders.ts` | detektor, eksperyment knip | około 15 | brak |
| R-8 | API: metody używane tylko przez testy: `Cart\Domain\Money::zero/add/multiply`, `NotificationClaim::isClaimed`, `occurredAt()` w 13 zdarzeniach i interfejsie `DomainEvent`, `equals()` w kolejnych 13 klasach | detektor z wyłączonymi testami | około 150 | niskie; `occurredAt()` to kontrakt zdarzenia, decyzja czy zostaje |

Czego **nie** znalazłem: martwych handlerów, kontrolerów, komend konsoli, endpointów, bundli, nieużywanych parametrów
`services.yaml`, martwych helperów w `e2e/support`, nieużywanych fabryk Foundry ani skryptów `load/`. Audytorzy
modułów potwierdzili nadawcę dla każdego handlera wiadomości.

### 5.3 Kod żywy, ale zbędny w obecnym kształcie

Ten kod działa i ma testy, lecz służy ścieżce, która dziś nie istnieje, albo dubluje inny mechanizm. Szczegóły są
w sekcjach 7–9. Najważniejsze pozycje:

- RabbitMQ jako drugi skok kolejki (około 277 linii klas API, 21 plików testów, 23 pliki infrastruktury i CI).
- Pula zdjęć demo w R2: 1 498 linii kodu i skryptów, 2 609 linii testów, manifest 38 860 linii w obrazie `prod`.
- Rekoncyliacja kont Keycloak dla danych demo: 1 054 linie kodu, 1 315 testów. Specyfikacja sama przyznaje, że tych
  kont nic nie czyta.
- `DiscountCodeBodyReader` + `MissingKey`: druga implementacja `#[RequiredKeysBody]` o innej semantyce.

## 6. Własne implementacje a mechanizmy natywne

Wniosek ogólny: **projekt rzadko wymyśla koło od nowa.** Tam, gdzie ma własny kod obok natywnego mechanizmu, zwykle
istnieje realna luka (polskie komunikaty per pole, statusy 4xx spoza zakresu atrybutu, bezpieczeństwo).

| Mechanizm | Własny kod | Natywny odpowiednik | Luka | Werdykt |
|---|---|---|---|---|
| Zegar | 5× port `Clock` + `SystemClock` (143 linie), 5× `Fixed*Clock` w testach (108) | PSR-20 `ClockInterface`, `MockClock` z `symfony/clock` (już w `vendor`, używa go `PaymentSimulator`) | `SystemClock` w Ordering i Payment obcina czas do sekundy; trzeba to przenieść w jedno miejsce | **REPLACE_NATIVE** |
| Rate limiting | `RateLimitSubscriber` + `RateLimit` (125) | atrybut `#[RateLimit]` w Symfony 8.1 (`http-kernel/Attribute/RateLimit.php`) | natywny działa po rozwiązaniu argumentów, nie w `kernel.request`; polski `detail` 429 to jeden wpis | **REPLACE_NATIVE**, ryzyko średnie |
| Odpowiedzi błędów w OpenAPI | 230 bloków `OA\Response` dla 400/401/403/415/429/503 (555 linii), 206 z nich to dosłowne powtórzenia | `components.responses` + `ref` (OpenAPI 3) | brak | **SIMPLIFY** |
| Sonda Redisa | `RedisProbe`, 130 linii surowego gniazda | `\Redis::ping()` z `ext-redis` (jest w obrazie) | sonda przestaje czekać równolegle z HTTP (pulpit administratora, do 1 s) | SIMPLIFY |
| Nagłówek `Vary` | ręczne parsowanie w `PublicCacheVarySubscriber` | `Response::getVary()` / `setVary()` | brak | SIMPLIFY |
| Indeks dostawców płatności | `PaymentProviders` (38) | `#[AutowireLocator]` z `defaultIndexMethod` | brak | REPLACE_NATIVE, mała wartość |
| Walidacja YAML danych demo | 1 869 linii ręcznej walidacji typów i kluczy | Serializer z `ALLOW_EXTRA_ATTRIBUTES=false`, `REQUIRE_ALL_PROPERTIES` | komunikaty z nazwą pliku, reguły krzyżowe zostają | REPLACE_NATIVE, pewność średnia |
| Parser tar puli zdjęć | `TarExtractor` (184) + transport archiwum 1,6 GB | obiekty `originals/<sha256>` i `rclone copy --checksum` (obraz już przypięty) | brak | REPLACE_OSS |
| Granica modułu DemoData | test z wyrażeniami regularnymi (62) | kolektory `bool` i `method` Deptraca | brak | REPLACE_NATIVE |
| Panel: klik z modyfikatorem | ręczny `guardEvent` w 3 komponentach | `RouterLink custom` / `useLink` | brak | REPLACE_NATIVE |
| Sklep: odmiana „produkt” | `formatProductCount` | `Intl.PluralRules("pl")` | brak | REPLACE_NATIVE, 4 linie |
| E2E: logowanie personelu | 20 wywołań `loginStaff`, retry TOTP do 30 s | projekt `setup` + `storageState` | sesja SSO musi żyć dłużej niż część zestawu | REPLACE_NATIVE, zysk w czasie CI |
| Problem details | `ProblemDetailsSubscriber` + `ExceptionStatus` (269) | `ProblemNormalizer`, `#[WithHttpStatus]` | natywny nie rozpakuje `HandlerFailedException`, daje inny `type`, a atrybut wymagałby Symfony w domenie | KEEP |
| Mapowanie treści żądania | DTO z `mixed` + `Sequentially`, `RequiredKeysBodyResolver` | `#[MapRequestPayload]` (używany w około 22 miejscach) | błąd typu po angielsku bez translatora; translator dałby komunikat ogólny, nie per pole | KEEP, scalić dwa czytniki |
| OIDC / JWT | dekorator `KeySourceOutageTokenHandler` (42) | handler `oidc` (już użyty) | 503 zamiast 401 przy niedostępnym JWKS | KEEP |
| Outbox, retry, opóźnienia | 55 linii własnych | transport Doctrine, `DelayStamp`, `doctrine_transaction` (już użyte) | – | KEEP |
| Paginacja | kursory keyset | Doctrine `Paginator` (offset) | offset nie działa przy 10 mln wierszy | KEEP, scalić kodek |
| Maszyna stanów zamówienia | 6 linii `match` w `OrderStatus` | `symfony/workflow` (niezainstalowany) | dodałby zależność bez usunięcia kodu | KEEP |
| Webhooki dostawców | parsowanie i podpis per dostawca | `symfony/webhook` (niezainstalowany) | kłóci się z synchroniczną semantyką 4xx i deduplikacją w transakcji | KEEP |
| Logowanie w sklepie | `src/auth` (około 810 linii) na `openid-client` | Auth.js, Better Auth | tryb utrzymania; brak blokady odświeżania; tokeny w ciasteczku | KEEP |
| Stan serwera w panelu | `useCursorPager`, liczniki `latest` | Pinia Colada, TanStack Query | specyfikacja wymaga innej semantyki stanów niż domyślna | KEEP, własny mały helper |
| Formularze panelu | 3 composables | vee-validate, TanStack Form | walidacja jest w API, front tylko mapuje 422 | KEEP |
| Mapa strony | route handlery + `buildUrlSet` | `generateSitemaps` | nie tworzy `sitemapindex`, wizja wymaga 503 zamiast pustej mapy | KEEP |
| Obrazy | `<img srcset>` z adresami imgproxy | `next/image` | drugi optymalizator obok imgproxy | KEEP |
| Filtry Sentry | `SentryEventFilter` (292) i odpowiedniki we frontach | scrubbing po stronie Sentry | działa dopiero po wysłaniu danych | KEEP |

## 7. Architektura i abstrakcje

### 7.1 Ocena ogólna

DDD z heksagonem jest uzasadniony tam, gdzie są niezmienniki i współbieżność: składanie zamówienia (jedna transakcja,
ustalona kolejność blokad, idempotencja), ceny z VAT i dyrektywą Omnibus, promocje bez nakładania się, rezerwacje stanu
pod `FOR UPDATE`, webhooki z deduplikacją. Ślady end-to-end pokazują proporcję: w `PlaceOrder` około 600 linii logiki
na 35 plików, w rezerwacji stanu prawie cały kod to uzasadniona współbieżność.

Wzorce są stosowane mechanicznie w trzech miejscach.

**Pulpit Backoffice.** Jedna odpowiedź przechodzi przez 4 kopie tych samych pól: widok modułu-właściciela, DTO portu,
sekcja Application, klasa `*Response`. Z około 1,1 tys. linii prawdziwa logika to około 150.

**Strona odczytu.** Adapter DBAL zwraca tablicę, handler przepisuje ją na widok, UI przepisuje widok na `*Response`.
Porty czytników SQL mają dublery in-memory, które odtwarzają zapytanie w PHP, a te same scenariusze i tak sprawdzają
testy funkcjonalne. `ProductEditSource` i `DemoCatalogSource` nie mają żadnego dublera, więc interfejs niczego nie
izoluje.

**Opis kontraktu.** Repo ma dwie konwencje jednocześnie: 57 klas `*Response` z atrybutami (2 070 linii) dla jednej
połowy API i 55 ręcznie pisanych schematów w `nelmio_api_doc.yaml` (1 450 linii) dla drugiej. Zmiana pola zamówienia
dotyka trzech miejsc: widoku, ręcznie budowanej tablicy JSON i YAML.

### 7.2 Interfejsy z jedną implementacją

W `src` jest 116 interfejsów (110 unikalnych nazw). Policzone skryptem:

| Grupa | Liczba |
|---|---:|
| jedna implementacja produkcyjna, dubel w testach | 84 |
| jedna implementacja produkcyjna, bez dubla | 11 |
| dwie lub więcej implementacji produkcyjnych | 14 (większość to znaczniki wyjątków: `Conflict`, `InvalidInput`, `NotFound`) |

Prawdziwy polimorfizm produkcyjny mają tylko `PaymentProvider` (2), `BackfillStep` (4), `ConcurrentServiceProbe` (2)
i dekorator `SitemapProductSource`. To samo w sobie nie jest wadą: dublery repozytoriów, blokad i portów do innych
contextów dają 2 179 szybkich testów jednostkowych bez bazy i warto je zostawić. Zbędne są porty z drugiej i trzeciej
kategorii poniżej:

1. Porty do innego contextu albo usługi zewnętrznej: **KEEP**.
2. Porty czytników SQL w tym samym module, których dubel odtwarza zapytanie: **SIMPLIFY** (adapter zwraca widok,
   testy jednostkowe handlera z dublem znikają).
3. Porty bez dubla i bez drugiej implementacji (11): **REMOVE** interfejsu tam, gdzie Deptrac na to pozwoli.

### 7.3 Zasady projektu, które same generują kod

| Zasada | Skutek zmierzony | Ocena |
|---|---|---|
| Brak wspólnych value objectów między contextami | identyczne `ProductId` w 5 modułach, `UuidFormat` ×2 (diff 0 poza namespace), `IdempotencyKey` ×2, a przez analogię także kod czysto techniczny: kursor ×8, zegar ×5, `no-store` ×4 | Zasada ma sens dla pojęć domenowych (`Money`, `Currency`, `ProductId`). Nie powinna obejmować kodu technicznego. Wystarczy jedno zdanie doprecyzowania w `apps/api/CLAUDE.md`. |
| Application bez OpenAPI | klasy `*Response` kopiujące widoki albo ręczny YAML | Samonarzucona: Deptrac pozwala warstwie Application na `Vendor`. Do decyzji razem z konwencją kontraktu. |
| Wartości wejścia jak w JSON, nigdy rzutowane | 112 właściwości `public mixed`, 209 wywołań `\assert(` po walidacji, ręczny walidator zapisu produktu (345 linii) | Powód jest realny (polski komunikat per pole). Koszt też. Do zawężenia: typowane DTO zagnieżdżone tam, gdzie komunikat ogólny wystarczy. |
| Domena niemapowana przez Doctrine | 1 458 linii rekordów, około 400 linii mapowania, ręczne wykrywanie zmian w `DoctrineProductRepository` | **KEEP.** Mapowanie domeny wymagałoby `Collection` w agregatach i typów DBAL dla 22 identyfikatorów, a blokady i wyjątki są zbudowane na rekordach. Ryzyko wysokie, zysk niepewny. |
| Każde wywołanie między contextami przez szynę | 49 bloków `catch (HandlerFailedException …)`, 32 wywołania `getWrappedExceptions`, 3 kopie helpera | Szyna zostaje. Rozpakowanie do jednego helpera. |

### 7.4 Powielone fragmenty o tej samej odpowiedzialności

| Fragment | Kopie | Linie objęte | Zysk szacowany |
|---|---|---:|---:|
| Kursor keyset (`*Cursor.php`) i wyjątki `Invalid*Cursor` | 8 + 9 | 904 | 450–550 |
| Plastry „historia” i „lista panelu” w Pricing i Inventory | 2 moduły, diff 0–19 linii na parę plików | około 2 290 | 350–450 |
| ACL do Catalog w Pricing i Inventory | 2 | około 700 | około 300 (zmiana zasady) |
| Handlery e-maili zamówień i porty mailera | 5 + 2 | 349 | 180–200 |
| `*ResponseCacheSubscriber` | 4 | 139 | około 100 |
| Rozpakowanie `HandlerFailedException` | 3 helpery, 49 miejsc | około 160 | 100–150 |
| Czytniki treści „wszystkie klucze wymagane” i `Idempotency-Key` | 2 + 3 | około 260 | około 115 |
| Panel: klej composables list | 9 | około 440 | około 250 |
| Panel: parsowanie problem details, szkielet formularza, historia | 7 + 3 + 2 | około 690 | około 380 |
| Panel: `permissions.ts` i `beforeEnter` | 7 + 6 | około 116 | około 70 |
| Sklep: wywołania BFF w `client.ts`, odczyt `API_CATALOG_URL`, wrappery zależności, wybór dostawcy | 7 + 5 + 2 + 2 | około 365 | około 180 |
| Strażnik `--force-reset` i seedy, które tylko kasują | 6 + 3 | 148 | około 100 |

## 8. Testy i konfiguracja

Zestaw testów API (111 tys. linii, 4 146 metod) **nie jest masą bezwartościowych testów**. Fakty:

- Mocków jest 18 (`createMock` / `createStub`). Projekt używa ręcznych dublerów, co jest dobrym wyborem.
- Adaptery in-memory (54 pliki, 3 669 linii) celowo nie odtwarzają SQL. Warstwa jednostkowa na nich zostaje.
- Testów getterów i DTO prawie nie ma. Martwej infrastruktury testowej nie ma.
- Testy współbieżności (19 plików), planów zapytań i indeksów oraz tokenów JWT to ochrona, której nie wolno ruszać.

Realny nadmiar:

| ID | Znalezisko | Linie | Klasyfikacja |
|---|---|---:|---|
| T-1 | 33 testy 401 dla endpointów panelu powtarzają `AdminRouteAccessTest`, który bierze trasy z routera | 231 + 48 | REMOVE |
| T-2 | Zduplikowane helpery testów funkcjonalnych (`assertKeysMatchSchema` ×10, `assertProblem` ×10, `request` ×15) | około 310 | CONSOLIDATE |
| T-3 | Metody skopiowane i różniące się jedną wartością | 592 | SIMPLIFY |
| T-4 | Brak testu z routera dla tras sklepu; zły JSON, 415 i brakujący klucz sprawdzane per endpoint | około 420 | CONSOLIDATE (jeden test sterowany OpenAPI chroni też nowe trasy) |
| T-5 | Te same decyzje sprawdzane na 2–3 poziomach (domena, handler, HTTP). Próba 384 testów: 30 prawdziwych duplikatów | 2 500–4 000 (ekstrapolacja z próby) | CONSOLIDATE, pewność średnia |
| T-6 | 14 testów historycznych migracji danych (`up()` i `down()` migracji, które przeszły na wszystkich bazach) | 2 087 | INVESTIGATE (zasada: usuwać po wdrożeniu) |
| T-7 | Panel: zachowania pagera testowane w `useCursorPager.test.ts` i ponownie w 8 plikach | 1 500–2 500 | SIMPLIFY |
| T-8 | E2E: test „adres bez wpisu historii” na 5 ekranach, 122 lokalne `test.setTimeout` | około 380 | CONSOLIDATE |
| T-9 | Testy skryptów `bin/` 2,76 linii na linię skryptu (`UploadDemoPhotosScriptTest` 545 dla 171) | 1 138 | SIMPLIFY do ścieżek błędów i strażnika |

Bilans dla testów API: 5–8% zestawu, nie 30–50%.

Konfiguracja:

- `config/packages/test/` i `test_messaging/` mają dwa identyczne pliki (18 linii). Około 150 linii `when@test`
  w `services.yaml` powtarza produkcyjne argumenty tylko po to, żeby serwis był publiczny.
- Wszystkie bundle i parametry `services.yaml` są używane. Resztek Fleksa nie ma.
- `apps/admin`: `--host 0.0.0.0` w skrypcie `dev` dubluje `server.host` w `vite.config.ts`.
- 51 zmiennych środowiska jest wpisanych ręcznie w compose i w chartcie i **nic tego nie pilnuje**. Wystarczy test na
  około 30 linii. Pliki konfiguracji (VCL, realmy, `garage.toml`) są w chartcie symlinkami, tu duplikacji nie ma.

## 9. Infrastruktura, wdrożenie, dane demo

### 9.1 RabbitMQ: drugi skok tej samej kolejki

Stan zweryfikowany w `apps/api/config/packages/messenger.yaml` i kodzie:

- Z 7 transportów 6 działa na Doctrine (`outbox`, `scheduled`, `payment_simulator`, `promotions`, `jobs`, `failed`).
  Tylko `events` to AMQP.
- Każde zdarzenie domenowe jest najpierw zapisywane w tabeli outboksu w Postgresie. Handler
  `RelayDomainEventToBroker` (`fromTransport: 'outbox'`) przekazuje je do RabbitMQ, a 17 handlerów czyta je
  z `events`.
- Ten sam proces workera konsumuje `outbox` i `events`. Kolejka `events` jest jedna, więc broker nie daje fan-outu.
- Żaden ADR nie uzasadnia RabbitMQ. Wizja opisuje go jako transport „indeksowania w ES, e-maili, rewalidacji”, co jest
  nieprawdą: komendy opóźnione, zadania i webhooki idą przez Doctrine.

Broker nie odciąża bazy, bo zdarzenie i tak jest zapisywane i odczytywane z Postgresa. Dokłada drugi zapis i drugi
odczyt oraz: 2 usługi compose, workload, PVC, limit 1 GiB RAM, sekret, rozszerzenie `amqp`, etap odtwarzania, klasy
`RoutedAmqpTransport*` (126 linii, obejście tego, że natywny transport gubi wiadomość bez kolejki), relay, sondę
i statystyki (razem około 277 linii), 21 plików testów API i 23 pliki infrastruktury.

Zmiana: trasować `DomainEvent` wprost do transportu Doctrine o nazwie `events`. Handlery z `fromTransport: 'events'`
zostają bez zmian. Outbox staje się kolejką zdarzeń.

**Dlaczego teraz:** planowany epik ASYNC-RELIABILITY jest w dużej części zaprojektowany wokół awarii brokera (dwa
workery, 200 ponowień outboksu, alert na zator `events`, eksperyment E1 „odbiór bez brokera”). Bez brokera te
elementy znikają albo się upraszczają.

Co tracimy i co trzeba zmierzyć: gotowość na fan-out do niezależnych usług (dziś niepotrzebna), przepustowość kolejki
w Postgresie przy skali docelowej (publikowane pomiary to pojedyncze blogi: rząd kilku tysięcy wiadomości na sekundę
bez strojenia, ryzykiem jest bloat tabeli). Jeśli RabbitMQ ma zostać jako element pokazowy architektury, to też jest
uprawniona decyzja, ale warto ją zapisać w ADR razem z kosztem.

### 9.2 Pozostałe znaleziska infrastruktury

| ID | Znalezisko | Klasyfikacja | Ryzyko |
|---|---|---|---|
| I-2 | Garage w `prod`: oryginały na PVC 20 Gi jednego węzła, backup kopiuje je na drugi wolumen 25 Gi. Nie skaluje się do 10 mln produktów. R2 jest już kontem projektu, API używa ogólnego adaptera S3. | INVESTIGATE (łamie zasadę „chart odzwierciedla compose”, decyzja właściciela) | średnie |
| I-3 | `garage-init` montuje binarkę przez image volume, co jest jedynym powodem `kubeVersion >=1.36`. Admin API v2 Garage robi to samo przez `curl`. | SIMPLIFY | niskie |
| I-4 | **Kopie zapasowe są w `prod` wyłączone** (`backup.enabled: false`, repozytorium to placeholder), a ADR i wizja piszą, że są. 4 122 linie kodu i testów kopii nie chronią dziś niczego. | nie usuwać: włączyć albo poprawić dokumentację | wysokie przy pozostawieniu |
| I-5 | `keycloak-config.sh` (110 linii) nie przenosi zmian istniejącego klienta do klastra (luka opisana w `infra/k8s/CLAUDE.md`). `keycloak-config-cli` ją zamyka. | REPLACE_OSS (nowy obraz, zgoda) | średnie |
| I-6 | Blok budowania obrazów (~100 linii) powtórzony dwa razy w `ci.yml` | SIMPLIFY (composite action) | niskie |
| I-7 | Filtr `infra/**` w `deploy.yml` uruchamia pełny smoke (4 obrazy, k3d, backup i restore) także dla README i testów | SIMPLIFY (wzorce `!`) | niskie |
| I-8 | Brak Dependabota i Renovate | REPLACE_NATIVE | niskie |
| I-9 | Mailpit w `prod` jako skrzynka bez ingressu | SIMPLIFY (`null://null`) | niskie |
| I-10 | Bucket testowy `product-images-test` tworzony i dopuszczony w imgproxy także w `prod` | REMOVE z `prod` | niskie |
| I-11 | Testy `infra/k8s`: 9 995 linii wobec 2 207 linii szablonów; `selftest.sh` z 90 przypadkami mutacji i regułą „każdy nowy test dostaje przypadek” | SIMPLIFY procesu dla testów niezwiązanych z bezpieczeństwem | niskie–średnie |
| I-12 | Dwie ścieżki „produkcyjnego” sklepu: `next build` w compose dla E2E i etap `prod` Dockerfile dla wdrożenia | INVESTIGATE | średnie |

### 9.3 Dane demo

Rdzeń generatora (katalog, ceny, zamówienia, stany przez `COPY`) jest uzasadniony i nie ma gotowego zamiennika:
Foundry zapisuje przez ORM, Faker ma globalny generator losowy, a oba są tylko w `require-dev`.

Koszt siedzi w trzech częściach, które służą profilom `medium`, `server` i `full`. Tych profili nie uruchamia żadna
automatyczna ścieżka, bo część 2 epiku (#486) nie powstała.

| ID | Część | Kod / testy | Propozycja | Co traci produkt |
|---|---|---:|---|---|
| D-3 | Pula 4 317 zdjęć z Commons w R2 (manifest, wysyłka, parser tar) | 1 498 / 2 609 | warianty generowane z 37 zdjęć z repo; albo zostawić pulę, ale jako obiekty z `rclone` | różnorodność wizualną demo; pomiar cache zostaje |
| D-4 | Konta Keycloak z rekoncyliacją własności | 1 054 / 1 315 | sam import z `SKIP`, bez usuwania; albo przenieść do epiku, w którym konta zaczną być czytane | sprzątanie kont po zmianie ziarna na trwałym stosie |
| D-5 | `demo-data:verify` liczy ceny od nowa | 789 / 550 | zostawić tanie niezmienniki SQL | sprawdzenie cen linii na próbce przy `full` |
| D-1 | Ręczna walidacja YAML | 1 869 / 1 572 | Serializer + value objecty | brzmienie komunikatów błędów |
| D-6 | Trzy seedy, które tylko kasują, strażnik w 6 kopiach | 148 / 402 | jedna komenda resetu przez tagowane serwisy | nic |
| D-7 | **Komendy `demo-data:*` są w obrazie `prod` i nie mają strażnika `prod`** (strażnik ma tylko skrypt `bin/generate-demo`). Uruchomione ręcznie dopisują wiersze do prawdziwej bazy, a `demo-data:accounts` usuwa członków grupy. | około +10 | dodać ten sam `--force-reset` | – |

## 10. Przykłady konkretnych uproszczeń

### 10.1 Jeden kodek kursora zamiast ośmiu klas

`PriceHistoryCursor`, `StockHistoryCursor`, `PromotionCursor` i `DiscountCodeCursor` po zamianie nazw różnią się kilkoma
liniami. Każda powtarza: base64url bez paddingu, JSON o głębokości 2, limit 2 048 znaków, wzorzec czasu UTC,
odrzucenie roku 0, wzorzec UUID.

```php
// Shared: technika, nie value object domeny
final class KeysetCursorCodec
{
    /** @param list<string> $keys @return array<string, string> @throws InvalidCursor */
    public static function decode(string $text, array $keys): array;
    /** @param array<string, string> $fields */
    public static function encode(array $fields): string;
    public static function utcInstant(string $value): \DateTimeImmutable;
    public static function uuid(string $value): string;
}

// moduł: około 20 linii zamiast 80
['t' => $t, 'i' => $i] = KeysetCursorCodec::decode($text, ['t', 'i']);
return new self(KeysetCursorCodec::utcInstant($t), KeysetCursorCodec::uuid($i));
```

### 10.2 Wspólne odpowiedzi błędów w OpenAPI

```php
// przed: 3–8 linii, powtórzone 66 razy dla samego 401
new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, …).',
    content: new OA\MediaType(mediaType: 'application/problem+json',
        schema: new OA\Schema(ref: '#/components/schemas/Problem'))),

// po: opis raz w nelmio_api_doc.yaml (components.responses)
new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
```

Diff `openapi.json` ogranicza się do `$ref`, typy klienta TS się nie zmieniają, CI już sprawdza aktualność eksportu.

### 10.3 Zdarzenia bez brokera

```yaml
# przed
transports:
    outbox: { dsn: 'doctrine://default?queue_name=outbox…' }      # zapis w transakcji agregatu
    events: { dsn: 'amqp://…/events' }                             # relay z outbox
routing:
    App\Shared\Domain\Event\DomainEvent: outbox

# po: jedna kolejka, zapis nadal w transakcji agregatu
transports:
    events: { dsn: 'doctrine://default?queue_name=events&auto_setup=false&redeliver_timeout=300' }
routing:
    App\Shared\Domain\Event\DomainEvent: events
```

Znikają `RelayDomainEventToBroker`, `RoutedAmqpTransport`, `RoutedAmqpTransportFactory`, `RabbitMqProbe`, statystyki
kolejek AMQP, `rabbitmq`, `rabbitmq-init`, `wait-for-rabbitmq`, sekret, etap `stage_rabbitmq` i rozszerzenie `amqp`.

### 10.4 Zegar PSR-20

```php
// przed: własny port w każdym module
public function __construct(private Clock $clock) {}

// po: natywny serwis, w testach Symfony\Component\Clock\MockClock
public function __construct(private \Psr\Clock\ClockInterface $clock) {}
```

Uwaga z weryfikacji: `SystemClock` w Ordering i Payment zwraca czas obcięty do sekundy
(`new \DateTimeImmutable('@'.time())`), a w Cart, Catalog i Pricing z mikrosekundami. Obcięcie trzeba zachować w jednym
miejscu, inaczej zmieni się odpowiedź powtórzonego `PlaceOrder`.

### 10.5 Lista w panelu

```ts
// przed: w każdym z 9 composables
if (query.q !== null) { apiQuery.q = query.q; }          // 37 takich bloków
if (query.status !== null) { apiQuery.status = query.status; }
return { items: pager.items, loading: pager.loading, /* … 13 pól */ };

// po: openapi-fetch sam pomija undefined
const page = await fetchListPage(() => api.GET("/api/v1/admin/products", {
  params: { query: { q: query.q ?? undefined, status: query.status ?? undefined } },
}));
return { ...pager };
```

## 11. Weryfikacja najważniejszych rekomendacji

Co sprawdziłem sam, poza raportami audytorów:

| Rekomendacja | Sposób weryfikacji | Wynik |
|---|---|---|
| R-1, prymitywy panelu | usunięcie 35 plików w kopii, potem `vue-tsc --noEmit`, `eslint .`, `vitest run`, `vite build` | wszystko zielone, 102 pliki i 2 619 testów przechodzą |
| R-2, `checkbox.tsx` w sklepie | usunięcie w kopii, `npm run typecheck`, `eslint` | zielone; testy jak przed zmianą (1 test `marketing-images` pada tak samo przed i po, bo kontener eksperymentu nie ma `sharp`) |
| Automatyczne `knip --fix` | uruchomienie w kopii panelu | **typecheck i lint padły**: `knip` usunął `eslint.config.d.ts`, którego potrzebuje test lintera. Wniosek: listy z narzędzi wymagają przeglądu, nie stosuje się ich hurtem. |
| RabbitMQ | lektura `messenger.yaml`, `grep fromTransport`, polecenie workera w compose i chartcie, ADR-y | fakty potwierdzone; decyzja nieprototypowana |
| `#[RateLimit]` | `vendor/symfony/http-kernel/Attribute/RateLimit.php` w zainstalowanej wersji 8.1 | istnieje; `key` przyjmuje closure |
| Zegar | odczyt 5 par `Clock` / `SystemClock` | potwierdzone, z niuansem sekundy w 2 modułach |
| Kursory, subskrybenty cache, OpenAPI | własne skrypty liczące i `diff` | 904 linie w 17 plikach; 4 pliki po 34–35 linii różniące się 8 liniami; 230 bloków i 555 linii |
| Martwe metody | detektor + `grep` | potwierdzone; jeden audytor twierdził, że każda metoda repozytorium ma wywołanie, co detektor obalił (`CategoryRepository::save`) |
| Kopie zapasowe w `prod` | `infra/k8s/environments/prod/values.yaml:74-76` | `enabled: false`, placeholder repozytorium |
| Strażnik `prod` w `demo-data:*` | `grep kernel.environment src/DemoData` | 0 trafień przy 24 komendach `#[AsCommand]` |

Czego **nie** zweryfikowałem eksperymentem: testów API po zmianach (wymagają stosu), zamiany walidacji YAML na
Serializer, przeniesienia zdarzeń na transport Doctrine, zmiany konwencji OpenAPI. Dla nich podaję niżej konkretną
weryfikację przed wdrożeniem.

## 12. Szacowana redukcja

### 12.1 Metodyka szacowania

- Podstawą są linie zmierzone `wc -l` dla plików wskazanych w znaleziskach („objęte”).
- „Zysk” to różnica po zmianie: dla usunięć równa liniom objętym, dla konsolidacji szacowana z `diff` kopii
  (wspólna część zostaje raz), dla zamian natywnych pomniejszona o kod łączący.
- Znaleziska o tym samym kodzie z różnych przeglądów policzyłem raz (kursory, zegar, subskrybenty cache).
- Przedziały oddają niepewność. Wartości ekstrapolowane z próby są oznaczone.
- Udział procentowy liczę do linii z komentarzami, bo tak mierzone są obie strony.

### 12.2 Kod produkcyjny

| Obszar | Baza (linie) | Bez decyzji właściciela | Dodatkowo po decyzjach |
|---|---:|---:|---:|
| API bez danych demo | 58 750 | 2 800–3 400 | 1 300–2 100 (konwencja OpenAPI 400–1 100, RabbitMQ około 280, ACL i techniczne VO około 450, porty czytników około 190) |
| Dane demo (DemoData + seedy) | 10 700 | 1 200–1 500 | 2 500–3 300 (zdjęcia, konta, weryfikator) |
| Panel | 21 800 | 1 500–1 600 | – |
| Sklep | 10 700 | 250–300 | 300–500 (historia zamówień jako Server Components, zmiana zdania w wizji) |
| **Razem** | **około 102 000** | **5 750–6 800 (około 6%)** | **4 100–5 900 (razem 10–12%)** |

Poza tym: E2E około 400 linii, infrastruktura około 300 linii przy RabbitMQ i `garage-init`, CI około 150.

### 12.3 Testy

| Obszar | Baza | Bez decyzji | Dodatkowo po decyzjach |
|---|---:|---:|---:|
| API | 111 000 | 4 500–6 200 (w tym 2 500–4 000 ekstrapolowane) + około 2 000 idących za zmianami kodu | 2 100 (migracje) + około 4 400 (dane demo) |
| Panel | 30 400 | 1 700–2 700 | – |
| Sklep | 17 500 | około 150 | około 800 |

### 12.4 Zależności i usługi

| Rodzaj | Do usunięcia bez decyzji | Po decyzji o RabbitMQ |
|---|---|---|
| Pakiety Composera | 0 (tylko deklaracja `ext-iconv`) | 1 (`symfony/amqp-messenger`) + rozszerzenie `amqp` |
| Pakiety npm | 0 | 0 |
| Usługi i obrazy | 0 | `rabbitmq`, `rabbitmq-init` (obraz `curlimages/curl`); w `prod` opcjonalnie Mailpit i Garage |

### 12.5 Złożoność

Liczba linii nie oddaje głównego zysku. Mierzalne skutki dla rozwoju:

- Nowa lista w panelu API: dziś własny kursor (80 linii), wyjątek kursora, strona, zapytanie, odpowiedź. Po zmianie
  około 20 linii specyficznych dla listy.
- Nowy endpoint: 4 standardowe odpowiedzi błędów to 4 linie zamiast 12–30.
- Zmiana pola odpowiedzi: jedno miejsce zamiast trzech (po ujednoliceniu konwencji).
- Asynchroniczność: jedna technologia kolejki zamiast dwóch, jeden scenariusz awarii mniej.
- Nowa lista w panelu admina: composable bez 50–60 linii powtarzanego kleju.

## 13. Ryzyko regresji i weryfikacja

| Grupa zmian | Ryzyko | Dlaczego | Weryfikacja |
|---|---|---|---|
| Usunięcie martwego kodu (R-1…R-8) | niskie | brak użyć potwierdzony narzędziem i eksperymentem | `make check`, `make test` aplikacji; dla R-6 sprawdzenie outboksu na serwerze celem `infra/k8s/Makefile` |
| Kodek kursora | niskie | czysta funkcja; kursory wydane przed wdrożeniem dadzą 400 i klient zacznie od pierwszej strony | testy kursorów i funkcjonalne list z `after`; próba obejścia: kursor innego sortu |
| Zegar PSR-20 | niskie | jedyna pułapka to obcięcie do sekundy | `make -C apps/api check test`, testy powtórzenia `PlaceOrder` |
| `components.responses` | niskie | zmienia się tylko opis | `make -C apps/api openapi && make api-client`, diff ograniczony do `$ref` |
| Subskrybent `no-store`, helper szyny, czytniki treści | niskie | zachowanie bez zmian | testy funkcjonalne nagłówków i statusów, w tym 401 z firewalla |
| `#[RateLimit]` | średnie | funkcja nowa w 8.1, inna kolejność zdarzeń | `RateLimitEndpointTest` (klient bez użytkownika, powtórzony klucz, Redis wyłączony); świadoma akceptacja, że żądanie odrzucone przez resolver nie zużywa tokenu |
| Helpery panelu | niskie–średnie | różnice per lista muszą zostać parametrami | istniejące testy `use*List`, `use*Form` i widoków bez zmian asercji |
| Pulpit Backoffice, strona odczytu | średnie | testy jednostkowe handlera opierają się na dublerach | test funkcjonalny pulpitu dla każdej roli, `DashboardTimingTest` |
| Jedna konwencja OpenAPI | średnie | nazwy schematów trafiają do klientów TS | pusty diff `openapi*.json` poza przeniesieniem; testy funkcjonalne |
| Zdarzenia na transporcie Doctrine | średnie | zmiana semantyki dostarczania i ponowień | `EventFlowTest`, `StorefrontInvalidationFlowTest`, `OutboxAtomicityTest`, E2E, `make -C infra/k8s smoke`, pomiar `make -C infra/k8s load` przed i po (opóźnienie unieważnień, rozmiar tabeli kolejki) |
| Dane demo | średnie; dla wyglądu demo wysokie przy rezygnacji z puli zdjęć | komunikaty błędów, format puli | `RealDemo*Test`, `make seed`, `make seed-scale PROFILE=small`, `make e2e` |
| Konsolidacja testów na poziomach | średnie | łatwo usunąć test, który wygląda na duplikat, a sprawdza kolejność kroków | porównanie pokrycia per moduł przed i po; Infection byłby lepszy, ale to nowa zależność dev |
| Garage → R2 w `prod` | średnie | migracja obiektów, wyjątek od zasady „chart odzwierciedla compose” | smoke z zewnętrznym S3, test zdjęcia w E2E |

Zasada dla wszystkich zmian: zielone testy to warunek konieczny, nie dowód. Przy usunięciach dowodem jest brak użyć
w `src`, konfiguracji, atrybutach i dynamicznych odwołaniach, potwierdzony narzędziem świadomym frameworka.

## 14. Priorytetowy plan uproszczeń

Kolejność według stosunku zysku do ryzyka. „Decyzja” oznacza, że potrzebna jest zgoda właściciela zgodnie z zasadami
projektu.

**Etap 0. Natychmiast, bez refaktoryzacji (ochrona, nie upraszczanie)**

1. Strażnik `prod` w komendach `demo-data:*` (D-7).
2. Kopie zapasowe: włączyć w `prod` albo poprawić ADR i wizję (I-4).
3. Test porównujący zmienne środowiska compose i chartu.

**Etap 1. Decyzje, które odblokowują resztę**

4. RabbitMQ: zostaje czy znika. Przed startem ASYNC-RELIABILITY.
5. Doprecyzowanie zasady: kod techniczny (kursor, format UUID, klucz idempotencji) może być wspólny w `Shared`.
6. Jedna konwencja opisu odpowiedzi API (klasy `*Response` albo atrybuty na widokach).
7. Zakres danych demo do czasu części 2: pula zdjęć, konta Keycloak, weryfikator.

**Etap 2. Mechaniczne, niskie ryzyko**

8. Usunięcie martwego kodu (R-1…R-7).
9. API: kodek kursora, zegar PSR-20, `components.responses`, jeden subskrybent `no-store`, helper szyny, jeden czytnik
   treści i nagłówka `Idempotency-Key`.
10. Panel: `listPage`, `problem.ts`, `useCursorHistory`, `hasRole` + `meta` w routerze, `RouterLink custom`.
11. Sklep: `requestBff`, jeden klient katalogu, `withDeps`.
12. Testy: T-1, T-2, T-3; E2E: `storageState`, limit czasu w projekcie zamiast 122 wywołań.
13. CI: composite action dla obrazów, zawężenie filtra smoke, Dependabot.

**Etap 3. Średnie ryzyko, po etapie 1**

14. Zdarzenia na transporcie Doctrine (jeśli taka decyzja).
15. Ujednolicenie kontraktu OpenAPI i strony odczytu (adapter zwraca widok, pulpit Backoffice bez pośrednich DTO).
16. Plastry „historia” i „lista panelu” w Pricing i Inventory na wspólnych generykach.
17. Dane demo: Serializer dla YAML, jedna komenda resetu, pula zdjęć jako obiekty.
18. Testy generyczne z routera i OpenAPI (T-4), potem konsolidacja poziomów (T-5) z porównaniem pokrycia.
19. `#[RateLimit]`, handlery e-maili, walidacja zapisu produktu.

**Nie teraz:** mapowanie domeny przez Doctrine, Server Actions w sklepie, biblioteka stanu serwera w panelu, wymiana
Keycloaka, Varnisha, Elasticsearcha.

## 15. Propozycje epików

| Epik | Zakres | Warunek | Ryzyko |
|---|---|---|---|
| **SIMPLIFY-SHARED** | Etap 2, punkty 8–9 i 12 (API): martwy kod, kodek kursora, zegar, odpowiedzi OpenAPI, `no-store`, helper szyny, czytniki; testy T-1…T-3 | decyzja 5 | FAST / STANDARD |
| **ADMIN-CONSOLIDATION** | punkty 10 i T-7: prymitywy, helpery list, problem details, historia, uprawnienia, odchudzenie testów pagera | brak | STANDARD |
| **EVENTS-ON-POSTGRES** | punkt 14 z ADR; aktualizacja specyfikacji ASYNC-RELIABILITY przed jej startem | decyzja 4 | CRITICAL (transakcje, dostarczanie) |
| **API-CONTRACT** | punkty 15–16: jedna konwencja odpowiedzi, strona odczytu, pulpit, plastry Pricing i Inventory | decyzja 6 | STANDARD |
| **DEMO-DATA-TRIM** | punkt 17 i decyzja 7; strażnik `prod` wcześniej, osobno | decyzja 7 | STANDARD |

Drobne zmiany sklepu, CI i E2E mieszczą się w zwykłych małych PR-ach, bez epiku. Testy generyczne (T-4, T-5) pasują do
SIMPLIFY-SHARED jako druga fala albo do osobnego epiku TEST-CONSOLIDATION, jeśli pierwsza fala potwierdzi zysk.

## 16. Odpowiedzi wprost

**Ile kodu aplikacyjnego można realnie usunąć?**
Około 5,8–6,8 tys. linii kodu produkcyjnego (około 6%) bez decyzji produktowych i do 10–12% po decyzjach o RabbitMQ,
konwencji kontraktu i zakresie danych demo. W testach odpowiednio około 8–11 tys. linii (5–7%) i kolejne 6–7 tys. po
decyzjach. Martwego kodu w ścisłym sensie jest około 1,1 tys. linii, z czego 800 to prymitywy UI panelu.

**Ile zależności można usunąć?**
Bez decyzji: żadnej. Po decyzji o RabbitMQ: jeden pakiet Composera, jedno rozszerzenie PHP, jedną usługę z kontenerem
inicjującym. W npm nic.

**Co jest największym źródłem zbędnej złożoności?**
Powielanie tego samego kodu technicznego w każdym module i przy każdym epiku, połączone z wieloetapowym przepisywaniem
tych samych pól (wiersz → DTO portu → widok → `*Response` → schemat). Na drugim miejscu jest kod zbudowany pod
przyszłość, której jeszcze nie ma (duże profile danych demo, drugi skok kolejki).

**Gdzie niepotrzebnie wymyślamy własne rozwiązania?**
Rzadko. Konkretnie: zegar (PSR-20), limit żądań (`#[RateLimit]`), powtarzane odpowiedzi OpenAPI
(`components.responses`), sonda Redisa (`\Redis::ping()`), walidacja YAML danych demo (Serializer), parser tar,
ręczny `guardEvent` w panelu, logowanie personelu w E2E (`storageState`), relay outbox → AMQP (transport Doctrine).

**Czy architektura jest adekwatna do skali i potrzeb projektu?**
W rdzeniu tak. Granice contextów, agregaty, blokady, idempotencja, outbox i kursory keyset odpowiadają realnym
wymaganiom i skali docelowej. Nieadekwatna jest na obrzeżach: pulpit Backoffice, porty czytników SQL, dwie konwencje
kontraktu. Nadmiarowa względem dzisiejszej rzeczywistości jest część operacyjna: `infra/k8s` ma 21,8 tys. linii,
z czego 10 tys. to testy, a kopie zapasowe są wyłączone.

**Czy widoczne są oznaki nadmiernego komplikowania kodu przez agentów?**
Tak, powtarzalne i mierzalne:

- kopiuj-wklej zamiast wyciągnięcia wspólnego kodu przy kolejnym epiku (kursor ×8, `PER_PAGE_OPTIONS` ×8,
  `failureMessage` ×15, `characters()` ×4, komentarz „25 is the default of the API” ×8);
- obrona przed niemożliwym: 209 `\assert(` po walidacji, `LogicException` dla przypadków wykluczonych wyżej;
- komentarze i docblocki powtarzające specyfikację: 17,4% linii API;
- pliki `CLAUDE.md` aplikacji opisują działanie kodu (API 455 linii, sklep 485, panel 254) wbrew zasadzie z głównego
  `CLAUDE.md`; sklep ma regułę dla plików z vercel/shop, których jest 0;
- testy większe od kodu przy skryptach jednorazowych (545 linii testu na 171 linii skryptu), test per warstwa dla tej
  samej ścieżki, test testów (`selftest.sh`, 90 przypadków);
- funkcje na zapas: `diskPeakGb` czytają tylko testy, opcje `--seed`, `--products`, `--orders` w 9 komendach.

Skala problemu jest umiarkowana. Agenci nie produkują martwego kodu ani zbędnych zależności, tylko nie konsolidują.

**Co zyskalibyśmy dzięki uproszczeniom?**
Krótszą ścieżkę typowej zmiany (nowa lista, nowy endpoint, nowe pole), jedną technologię kolejki i prostszy epik
ASYNC-RELIABILITY, mniej miejsc do aktualizacji przy zmianie kontraktu, krótsze CI (logowanie E2E, filtr smoke),
mniejszy kontekst do wczytania przez agenta przy każdym zadaniu. Nie zyskamy istotnie mniejszego builda ani mniejszej
liczby pakietów.

**Które pięć zmian warto wykonać w pierwszej kolejności?**
1. Rozstrzygnąć RabbitMQ przed ASYNC-RELIABILITY.
2. Wspólne klocki techniczne API wraz z usunięciem martwego kodu (epik SIMPLIFY-SHARED).
3. Panel: usunięcie 35 prymitywów i trzy helpery (ADMIN-CONSOLIDATION).
4. Dane demo: strażnik `prod` od razu, potem odchudzenie zakresu.
5. Testy z routera i OpenAPI oraz wspólny trait zamiast kopii per endpoint.

**Których elementów nie należy upraszczać i dlaczego?**
Lista w sekcji 17.

## 17. Czego nie upraszczać

| Element | Dlaczego zostaje |
|---|---|
| Granice contextów i ACL między Cart, Ordering, Pricing, Inventory, Payment | tłumaczą typy i wyjątki, chronią domeny przed zmianami sąsiadów |
| `PlaceOrderHandler`, kolejność blokad, `Idempotency-Key`, `RequestFingerprint` | wymagania spójności z wizji; każda linia ma scenariusz współbieżności |
| Blokady: `FOR UPDATE` w Inventory, blokada doradcza drzewa kategorii i promocji, `FOR NO KEY UPDATE` kodów | udokumentowane zabezpieczenia przed zakleszczeniem i wyścigiem |
| Outbox na transporcie Doctrine, `DelayStamp`, lease „wyślij e-mail raz” | natywne i minimalne; chronią przed utratą i duplikatem |
| Kursory keyset i `BoundedCounter` | `OFFSET` i pełny `COUNT` nie działają przy 10 mln wierszy |
| Rekordy ORM oddzielone od domeny | zmiana o wysokim ryzyku i niepewnym zysku; blokady i wyjątki stoją na rekordach |
| `ProblemDetailsSubscriber`, entry point i failure handler 401, `KeySourceOutageTokenHandler` | natywne odpowiedniki nie dają tego kontraktu ani 503 przy awarii JWKS |
| DTO z polskim komunikatem per pole | wymóg listy jakości; translator dałby komunikaty ogólne |
| Elasticsearch z przebudową przez alias, reindeks partiami | skala docelowa i polski stemmer |
| Varnish z tagami, imgproxy z podpisem, jedna lista rozmiarów obrazów | cache odpowiedzi API dla wielu replik sklepu; klucz podpisu poza frontami |
| Keycloak dla klientów i pracowników | jedna instancja na dwa realmy; MFA personelu |
| Logowanie sklepu na `openid-client` z sesją w Redisie | wzorzec BFF zalecany przez RFC 10017; alternatywy w trybie utrzymania albo cięższe |
| Sprawdzenia CSRF, flagi ciasteczek, nagłówki bezpieczeństwa, filtry danych osobowych przed Sentry | zabezpieczenia |
| `PaymentSimulator` | jedyny dostawca publicznego demo; testuje prawdziwą ścieżkę podpisu, deduplikacji i ponowień |
| Adaptery in-memory i warstwa testów jednostkowych | 2 179 testów bez bazy; dublery nie odtwarzają SQL |
| Testy współbieżności, planów zapytań, indeksów, tokenów JWT | jedyna automatyczna ochrona przy skali docelowej i bezpieczeństwie |
| Sealed Secrets, gałęzie `deploy`, smoke test na k3d, polityki i maskowanie logów w monitoringu | kontrola łańcucha dostaw i danych osobowych |
| `useCursorPager`, `useListUrlState`, zarządzanie fokusem w panelu | jedyne implementacje reguł ze specyfikacji; wymóg dostępności |
| Rdzeń generatora danych demo (`COPY`, strumienie losowe, reguły domeny) | brak gotowego zamiennika, potrzebny do pomiarów skali |

## 18. Znaleziska poboczne

Nie są uproszczeniami, ale wyszły w audycie i warto je znać:

- Wyjątki domeny 4xx (`NotFound`, `Conflict`, `InvalidInput`) docierają do natywnego `ErrorListener` owinięte
  w `HandlerFailedException` i są logowane jako `CRITICAL`. W `prod` uruchamia to `fingers_crossed` przy każdym takim
  404, 409 i 422. Do potwierdzenia na logach produkcyjnych.
- `ProblemDetailsSubscriber::GENERIC_DETAILS` i komunikat braku nagłówka `Idempotency-Key` są po angielsku, a ten
  drugi trafia do `violations[].title`. Narusza to zasadę „komunikaty po polsku”.
- `docs/vision.md` błędnie opisuje role Redisa (cache odczytów katalogu) i RabbitMQ (indeksowanie, e-maile).
- `apps/admin/CLAUDE.md` nazywa `useNameSearch` jedynym miejscem reguł wyszukiwania, a `useDiscountCodeList` ma własne.
- Środowisko `test_oidc` nie występuje w `services.yaml` ani `sentry.yaml`. Do sprawdzenia, czy to celowe.

## 19. Źródła

Dokumentacja mechanizmów wskazanych jako zamienniki:

- Symfony Rate Limiter i atrybut `#[RateLimit]`: https://symfony.com/doc/current/rate_limiter.html
- Symfony Clock, `MockClock`: https://symfony.com/doc/current/components/clock.html
- Symfony Messenger, transport Doctrine, `use_notify`: https://symfony.com/doc/current/messenger.html
- Symfony Security, handler `oidc`: https://symfony.com/doc/current/security/access_token.html
- Symfony Serializer, deserializacja: https://symfony.com/doc/current/serializer.html#deserializing-an-object
- Symfony, tagowane serwisy i lokatory: https://symfony.com/doc/current/service_container/tags.html#reference-tagged-services
- Symfony Process: https://symfony.com/doc/current/components/process.html#running-processes-asynchronously
- Symfony ObjectMapper: https://symfony.com/doc/current/object_mapper.html
- Symfony Lock: https://symfony.com/doc/current/components/lock.html
- Deptrac, kolektory: https://deptrac.github.io/deptrac/collectors/
- Next.js, cache i self-hosting: https://nextjs.org/docs/app/getting-started/caching,
  https://nextjs.org/docs/app/guides/self-hosting
- Next.js Server Actions: https://nextjs.org/docs/app/guides/server-actions
- Next.js `generateSitemaps`: https://nextjs.org/docs/app/api-reference/functions/generate-sitemaps
- React `useActionState`: https://react.dev/reference/react/useActionState
- `Intl.PluralRules`: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Intl/PluralRules
- Vue Router, `meta`, `RouterLink custom`, leniwe trasy: https://router.vuejs.org/guide/advanced/meta.html,
  https://router.vuejs.org/guide/advanced/extending-router-link.html,
  https://router.vuejs.org/guide/advanced/lazy-loading.html
- openapi-fetch, `querySerializer`: https://openapi-ts.dev/openapi-fetch/api#queryserializer
- Pinia Colada: https://pinia-colada.esm.dev/guide/queries.html
- Playwright, uwierzytelnianie i `storageState`: https://playwright.dev/docs/auth
- Playwright, `expect.poll` i sharding: https://playwright.dev/docs/test-assertions#expectpoll,
  https://playwright.dev/docs/test-sharding
- Garage, admin API v2: https://garagehq.deuxfleurs.fr/api/garage-admin-v2.html
- keycloak-config-cli: https://github.com/adorsys/keycloak-config-cli
- GitHub Actions, composite action i filtry ścieżek:
  https://docs.github.com/en/actions/sharing-automations/creating-actions/creating-a-composite-action,
  https://docs.github.com/en/actions/writing-workflows/workflow-syntax-for-github-actions#filter-pattern-cheat-sheet
- rclone `copy`: https://rclone.org/commands/rclone_copy/
- Knip, wtyczki: https://knip.dev/reference/plugins

Kondycja projektów i alternatywy (wybór; pełna lista 101 adresów w załączniku):

- Auth.js pod opieką Better Auth: https://better-auth.com/blog/authjs-joins-better-auth
- OAuth 2.0 for Browser-Based Applications (RFC 10017): https://www.rfc-editor.org/rfc/rfc10017.html
- openapi-fetch, propozycja trybu utrzymania: https://github.com/openapi-ts/openapi-typescript/discussions/2559
- TypeScript 7.1 beta: https://infoq.com/news/2026/11/typescript-7-1-beta
- Kolejki w Postgresie, granice: https://dbos.dev/blog/making-postgres-queues-scale
- Elasticsearch `analysis-stempel`: https://www.elastic.co/docs/reference/elasticsearch/plugins/analysis-stempel
- Varnish i Vinyl Cache: https://vinyl-cache.org/organization/on_vinyl_cache_and_varnish_cache.html
- Cloudflare, purge po tagach na wszystkich planach:
  https://developers.cloudflare.com/changelog/post/2025-04-01-purge-for-all/index.md
- Cloudflare R2, cennik: https://developers.cloudflare.com/r2/pricing/
- imgproxy, wydania: https://github.com/imgproxy/imgproxy/releases
- node-redis jako następca ioredis: https://redis.io/docs/latest/develop/clients/nodejs/migration/
- Pakiety Composera, daty wydań: https://repo.packagist.org/p2/shipmonk/dead-code-detector.json,
  https://repo.packagist.org/p2/web-token/jwt-library.json, https://repo.packagist.org/p2/doctrine/orm.json
