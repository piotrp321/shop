Zrób pełne (FULL) code review taska ORDER-MANAGEMENT-1. Nie modyfikuj kodu.

- Repo/worktree: {{CHECKOUT}} (używaj ścieżek absolutnych, `git -C`).
- Zamrożony HEAD: 8803554aeb3bab0eec5772df98868b20ee43e650. Diff taska: `git diff 3bde636741fc5e9185fd27996bac4426a4dcf0a3..8803554aeb3bab0eec5772df98868b20ee43e650` (merge-base z origin/epic/order-management).
- Specyfikacja: `docs/features/order-management/spec.md` — decyzja A4, sekcja „E-maile (P5, A4)” → „Znaczniki wysyłki poza agregatem”, „Persystencja” (znaczniki, DbalOrderingReset), kryteria 10 i 13, „Zadania dokumentacji”. Manifest i kryteria akceptacji taska: `docs/features/order-management/plan.md`, task ORDER-MANAGEMENT-1 (pola `scope` i `acceptance_criteria`). Zasady: `CLAUDE.md`, `apps/api/CLAUDE.md`.
- Ryzyko: CRITICAL (współbieżność, migracja danych, wysyłka e-maili przy ponowieniach).
- tdd.status: verified. Dziennik dowodów (RED/GREEN, commity, `dirty` drzewa przy RED): `{{INPUT_DIR}}/tdd-evidence.jsonl`.
- Lokalnie przeszły: `make -C apps/api check` (PHPStan, Deptrac, CS) i `make -C apps/api test` (3564 testy OK, 1 pominięty istniejący). Nie musisz ich powtarzać; możesz uruchomić celowane testy przez `docker compose exec -T api vendor/bin/phpunit --filter …` z katalogu worktree, jeśli potrzebujesz dowodu.

Sprawdź w szczególności:
1. Poprawność atomowego claim (`INSERT … ON CONFLICT … DO UPDATE … WHERE … RETURNING`) w `DbalOrderNotifications` przy READ COMMITTED, precyzja kolumn (TIMESTAMP(0)) a porównania lease, pętla ponowień claim, `markSent` jako upsert, `release` bez sprawdzania właściciela.
2. `NotificationSender` i trzy handlery: kolejność (odczyt zamówienia przed claim dla nieznanego zamówienia — czy zachowanie „jak dziś”), opóźnienie `until + 1 s`, zwolnienie przy błędzie, brak zapisu zamówienia/wersji, brak transakcji wokół handlera na `event.bus`.
3. Migracje: przeniesienie znaczników, usunięcie kolumn, FK bez CASCADE, `down()`, zgodność schematu z rekordem Doctrine; `DbalOrderingReset`.
4. `SmtpTimeoutTransportFactory` (dekorator `mailer.transport_factory.smtp` w `src/Shared/Infrastructure/Mail`) — poprawność, czy faktycznie wpływa na transport użyty przez mailer, czy wyjście poza listę `scope` jest uzasadnione; `MAILER_DSN` w `infra/docker-compose.yml` i `phpunit.dist.xml`.
5. Testy: czy pokrywają kryteria akceptacji (w tym test ponowień Messengera na transporcie z polityką `events`, dwie równoległe dostawy, test migracji), czy testy współbieżności są realne (osobne połączenia) i niełamliwe; czy istniejące testy nie zostały osłabione; przebieg TDD (RED przed kodem produkcyjnym, z właściwego powodu).
6. Dokumentacja: `apps/api/CLAUDE.md`, `docs/features/payment/spec.md`, `docs/features/checkout/spec.md` opisują stan obecny (bez historii zmian).
7. Zbędna złożoność, zakres (nic z ORDER-MANAGEMENT-2).

Wynik: lista znalezisk z wagą (Critical / Important / Minor), plik:linia, opis, scenariusz awarii i dowód; na końcu werdykt (passed / findings). Zgłaszaj tylko to, co zweryfikowałeś w kodzie.

Uwaga do tego przebiegu (dopisana przez ewaluację, nie przez orkiestratora): stos Dockera nie działa i nie ma sieci. Nie uruchamiaj testów, `make` ani `docker`; pracuj na odczycie kodu i dziennika dowodów.
