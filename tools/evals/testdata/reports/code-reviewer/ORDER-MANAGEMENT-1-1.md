## Review ORDER-MANAGEMENT-1: tryb FULL, werdykt PASS

**SHA:** HEAD = `8803554aeb3bab0eec5772df98868b20ee43e650`. Sprawdziłem go na początku i na końcu przeglądu, drzewo czyste. Diff: `3bde636741fc5e9185fd27996bac4426a4dcf0a3..8803554` (3 commity: 3f8a2f0, 0c64a09, 8803554).

**Werdykt: PASS.** Nie ma BLOCKER ani IMPORTANT. Są cztery ustalenia Minor i dwie sugestie.

Uruchomiłem tylko testy celowane, bez całego `make test`. Przeszły:
- `CatalogSchemaTest`, czyli zgodność migracji z mapowaniem.
- `DbalOrderNotificationsTest`, `OrderNotificationsConcurrencyTest`, `SendOrderEmailsTest`, `OrderEmailMarkersMigrationTest`, testy `SmtpTimeout*`: 47/47 OK.
- `SendOrderEmailsConcurrencyTest`: OK.

### Trzy obowiązkowe kontrole

**1. Istniejące testy: bez uwag.** Żaden test nie jest pominięty ani wyłączony; nie ma `skip`, `only`, `markTestSkipped`, a konfiguracja suite się nie zmieniła. Wszystkie usunięte lub zmienione testy mają podstawę w A4:
- Usunięte: `SendOrderConfirmationTest`, `SendOrderStatusEmailsTest`, `OrderTest::testMarkConfirmationSentIsIdempotent`, `OrderPaymentTest::testTheNotificationMarkersKeepTheFirstMoment`, `DoctrineOrderRepositoryTest::testSaveWritesTheConfirmationMarkerAndRaisesTheVersion` oraz test „version conflict” w `SendOrderConfirmationFlowTest`. Sprawdzały metody i zachowanie usunięte z agregatu, czyli znaczniki w `Order` i konflikt wersji przy e-mailu.
- Ich przypadki są pokryte w `SendOrderEmailsTest`: wysyłka, druga dostawa, nieznane zamówienie, błąd i ponowienie.
- Zmiana `version` 3→2 w `testSaveWritesThePaymentAndRaisesTheVersion` wynika z tego, że znika zapis znacznika.
- W `MarkOrderPaidHandlerTest` konflikt wywołuje teraz `changeElsewhere`. Konflikt nadal powstaje, bo zapis kopii podnosi wersję.

**2. Zakres: bez uwag.** Wszystkie zmiany są w `apps/api`, `infra/docker-compose.yml` i dokumentach z kryteriów.
- Jedyne pliki poza listą `scope` to `apps/api/src/Shared/Infrastructure/Mail/SmtpTimeoutTransportFactory.php` i jego test. Mają podstawę w specyfikacji i w kryterium 7 („limit czasu SMTP krótszy niż lease, `MAILER_DSN`”).
- Symfony Mailer 8.1.10 nie ma opcji `timeout` w DSN SMTP; `EsmtpTransportFactory` czyta tylko auto_tls, source_ip, verify_peer i podobne.
- Bez dekoratora obowiązuje `default_socket_timeout` = 60 s. W kontenerze daje to 60 × 10 = 600 s, więcej niż 300 s lease, co pokazał RED z wpisu 10 („600.0 is less than 300”). Dekorator jest więc potrzebny.
- Nic z ORDER-MANAGEMENT-2: brak nowych statusów i nowych handlerów. `NotificationKind::Shipped` i `ReturnReceived` są tylko w enumie i w CHECK, zgodnie ze `scope`.

**3. RED przed implementacją: zgodne, `tdd.status` verified potwierdzone.**
- Wpis 0 (adapter): `dirty` bez plików `src/` i migracji. Błąd „Class DbalOrderNotifications not found”, w `output_tail` są nazwy testów.
- Wpis 2 (reset): pada z właściwego powodu, „cannot truncate a table referenced in a foreign key”; `DbalOrderingReset` jeszcze niezmieniony.
- Wpis 5 (handlery i migracja): head 3f8a2f0, w `dirty` brak `src/` i migracji 130000. Wynik: 35 testów, 24 errors, 10 failures.
- Wpisy 9 i 10 (SMTP): bez `src/Shared/Infrastructure/Mail` w `dirty`; RED z właściwego powodu.
- Uwaga do dziennika: we wpisie 5 `output_tail` jest ucięty. Widać tylko testy flow; nazw `SendOrderEmailsTest`, `SendOrderEmailsConcurrencyTest` i `OrderEmailMarkersMigrationTest` nie ma. Przy tym head nie istniały ani `NotificationSender`, ani plik migracji, więc te testy musiały paść. Liczba testów zgadza się z GREEN (35). Nie zgłaszam tego jako braku RED.
- Wpis 6 to „green” z exit 2, a 43 s później wpis 7 z exit 0, przy tej samej liście `dirty`. Po drodze poprawiono implementację; to nie jest odtworzenie RED.

### Punkty z polecenia

**Claim pod READ COMMITTED: poprawny.**
- Upsert w `DbalOrderNotifications.php` (`claim`) działa tak: równoległy pierwszy INSERT czeka na spekulatywny wiersz drugiego, potem `DO UPDATE … WHERE` liczy się na najnowszej wersji zablokowanego wiersza. Wynik to brak `RETURNING`, a nie naruszenie unikalności.
- Pętla 3 prób obsługuje zwolnienie lub wygaśnięcie między dwoma zapytaniami. Fallback `inProgress($now)` daje opóźnienie 1 s.

**Precyzja TIMESTAMP(0): spójna.**
- DBAL formatuje `DATETIMETZ_IMMUTABLE` jako `'Y-m-d H:i:sO'` (sprawdzone w vendor), więc `until` i `now` są ucinane do sekund tak samo.
- Gdy SQL uznał, że ktoś trzyma zajęcie, `claimed_until ≥ floor(now)+1 > now`. Dlatego warunek PHP `$until > $now` i opóźnienie `until + 1 s − now` są zawsze dodatnie i zgodne z SQL.
- Efektywny lease wynosi 299–300 s.

**`markSent`** jako upsert z `COALESCE` zachowuje pierwszy moment i spełnia CHECK `sent_at IS NULL OR claimed_until IS NULL`.

**`NotificationSender` i handlery: zgodne ze specyfikacją.**
- Odczyt zamówienia przed claim: nieznane zamówienie daje `Unrecoverable` bez wiersza, jak dotychczas.
- `inProgress` to `RecoverableMessageHandlingException(retryDelay)` z `forceRetry` = true. `SendFailedMessageForRetryListener` bierze opóźnienie z wyjątku zagnieżdżonego w `HandlerFailedException`.
- Przy błędzie: `release` i wyjątek dalej. Handlery nie zapisują zamówienia.
- `event.bus` nie ma `doctrine_transaction`, więc każde zapytanie zatwierdza się od razu. `framework.mailer.message_bus: false` zostaje.

**Migracje: bez uwag.**
- Przeniesienie znaczników ustawia `claimed_until` NULL; usunięcie kolumn; FK bez CASCADE.
- `down()` przywraca kolumny i dane; obie migracje są testowane, a `OrderPaymentCycleMigrationTest` najpierw cofa nowszą.
- Schemat zgadza się z rekordem Doctrine (`CatalogSchemaTest` OK). Jeden `TRUNCATE` obejmuje trzy tabele.

**SMTP.** Dekorator dostaje tag `mailer.transport_factory` (DecoratorServicePass przenosi tagi). `SmtpTimeoutWithinTheLeaseTest` sprawdza transport zbudowany przez `mailer.transport_factory` z kontenera. Zmienną `MAILER_DSN` z timeoutem ma również `worker` (anchor `*api-environment`), co sprawdziłem `printenv`.

**Testy współbieżności są realne.** Każdy uczestnik ma osobny proces (`pcntl_fork`) i osobne połączenie, start wyznacza bariera na advisory lock, a wiersze zatwierdzają się i są sprzątane w `tearDown`.

### Ustalenia

**Minor 1. Ponowienia `inProgress` zużywają limit ponowień transportu `events`.**
- Gdzie: `apps/api/src/Ordering/Application/EventHandler/NotificationSender.php:46` i fallback w `DbalOrderNotifications.php:67`.
- Mechanizm: każde ponowienie dokłada `RedeliveryStamp`, a `MultiplierRetryStrategy::isRetryable` (vendor) porównuje łączną liczbę z `max_retries: 3`.
- Scenariusz: worker A umiera w trakcie wysyłki, więc zajęcie trzyma się do 5 min. Ponowna dostawa B dostaje `inProgress`, więc licznik ma już 1. Potem SMTP leży. B dostaje wtedy 3 próby SMTP zamiast 4, a pierwsze opóźnienie to 3 s zamiast 1 s (`1000 × 3^retryCount`).
- Specyfikacja mówi „transport ponawia (bez zmian w polityce `events`)”. Polityka się nie zmieniła, ale jej limit jest dzielony.
- Skutek jest ograniczony: mniej prób przed `failed`. Do decyzji: zaakceptować i opisać albo zostawić.

**Minor 2. `release` nie sprawdza, kto trzyma zajęcie.**
- Gdzie: `apps/api/src/Ordering/Infrastructure/Persistence/Doctrine/DbalOrderNotifications.php:81-87`.
- Jest zgodne z literą specyfikacji („`claimed_until` null”).
- Scenariusz: wysyłka A trwa dłużej niż lease, więc B przejmuje zajęcie i wysyła. Wtedy A dostaje błąd i zeruje zajęcie B, a trzecia dostawa C może wysłać równolegle z B.
- Wymaga przekroczenia lease, a timeout 10 s na operację gniazda czyni to mało prawdopodobnym. Do rozważenia warunek `AND claimed_until = :until`, czyli token właściciela. Podobnie `markSent` zeruje cudze zajęcie, ale to niczego nie psuje.

**Minor 3. Dokumentacja przesadnie stanowczo opisuje ryzyko drugiego e-maila.**
- Gdzie: `docs/features/payment/spec.md:611`: „Drugi e-mail … jest możliwy **tylko** przy awarii procesu między wysyłką a zapisem znacznika”.
- Kod daje drugi e-mail także w trzech innych przypadkach:
  - `markSent` rzuca, np. błąd bazy, a proces żyje. Zajęcie zostaje, a po wygaśnięciu lease ponowienie wysyła drugi raz.
  - Błąd SMTP po przyjęciu wiadomości przez serwer, np. timeout odpowiedzi po DATA. Następuje `release`, a ponowienie wysyła ponownie.
  - Wysyłka dłuższa niż lease.
- `order-management/spec.md` i `checkout/spec.md` nie mają słowa „tylko”. Proponuję usunąć „tylko” albo wymienić te przypadki.

**Minor 4. Okno czasowe w `SendOrderEmailsConcurrencyTest` może być łamliwe.**
- Gdzie: `apps/api/tests/Integration/Ordering/Mail/SlowOrderStatusMailer.php:22` (`usleep(500_000)`).
- Test oczekuje dokładnie `['handled', 'retried: RecoverableMessageHandlingException']`. Jeśli drugi proces dojdzie do `claim` później niż wysyłka plus 0,5 s pierwszego, dostanie `alreadySent` i asercja padnie, choć zachowanie jest poprawne (jeden e-mail).
- U mnie test przeszedł, łamliwości nie zaobserwowałem; to ryzyko pod obciążeniem CI. Można wydłużyć wstrzymanie albo przyjąć też wynik `['handled','handled']` z jednym e-mailem.

**Sugestia 5.** Commit 0c64a09 ma typ `refactor(ordering)`, a zmienia zachowanie: wersja się nie zmienia, pojawia się `Recoverable` przy zajęciu. Przy squash-merge do `epic/*` tytuł PR i tak to nadpisze.

**Sugestia 6.** Gdy `release` w `NotificationSender.php` (blok `catch`) sam rzuci, np. przy niedostępnej bazie, pierwotny błąd SMTP ginie, bo nie jest dołączony jako `previous`. Zajęcie wygaśnie po lease, więc to tylko gorsza diagnostyka.

### Pokrycie kryteriów akceptacji
1. Wersja bez zmian po wysłaniu e-maila: `SendOrderStatusEmailsFlowTest` (asercja `version`) oraz `testTheLeaseLeavesTheOrderAndItsVersionAlone`.
2. Testy jednostkowe trzech handlerów: `SendOrderEmailsTest`, z data providerem po trzech rodzajach i opóźnieniem 241000 ms oraz 1250 ms.
3. Adapter na PostgreSQL: `DbalOrderNotificationsTest` i `OrderNotificationsConcurrencyTest` (8 połączeń × 5 rund).
4. Ponowienia Messengera: `SendOrderConfirmationFlowTest::testAHeldClaimComesBackAfterTheLeaseAndEndsWithOneEmail` i `testASmtpFailureIsRetried…`.
5. Dwie równoległe dostawy: `SendOrderEmailsConcurrencyTest`.
6. Migracja i reset: `OrderEmailMarkersMigrationTest` i `SeedDemoOrdersTest::testRemovesTheNotificationsOfTheOrders`.
7. Timeout SMTP krótszy niż lease: `SmtpTimeoutWithinTheLeaseTest`.
8. Dokumentacja: `apps/api/CLAUDE.md`, `payment/spec.md` i `checkout/spec.md` opisują stan obecny, bez historii zmian. Wyjątek to sformułowanie z Minor 3.
9. `make check` i `make test`: według raportu developera; całego zestawu nie powtarzałem.