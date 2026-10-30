# QA-002/M3: utrata zdarzenia z outboxa przy braku kolejki `events`

Weryfikacja zgłoszenia M3 z [QA-002](qa-agent-002.md) przez orkiestratora. Rewizja `660f3f9`. Poprawki nie
wprowadzono.

## Wynik

M3 potwierdzone na prawdziwym procesie workera. Waga jest wyższa, niż ocenił QA: **Important** (QA: Minor,
„wąskie okno”).

- Gdy kolejki `events` nie ma, relay przekazuje zdarzenia do exchange'u, RabbitMQ je potwierdza (ack), a relay usuwa
  wiersz z outboxa. Zdarzenie znika bez śladu: brak wiersza w `outbox` i `failed`, brak wpisu w logu na poziomie
  warning lub error.
- Okno nie jest krótkie. Worker nie deklaruje kolejki ponownie (to twierdzenie QA było błędne). Przekazuje
  donikąd cały zaległy outbox, a dopiero gdy outbox jest pusty i worker sięga do `events`, kończy się błędem `404`.
  Kolejkę deklaruje dopiero nowy proces po restarcie (`restart: unless-stopped`).
- Łamie to główną gwarancję outboxa zapisaną w spec i w komentarzu w `messenger.yaml` („ack oznacza, że RabbitMQ
  ma wiadomość”). Publisher confirms chronią przed nack, ale nie przed wiadomością, której broker nie miał dokąd
  skierować.
- Dziś prawdopodobieństwo jest niskie: jeden exchange `fanout`, jedna kolejka, `auto_setup` przy starcie procesu.
  Musi zajść zewnętrzna przyczyna (patrz „Kiedy to zachodzi”).

## Przyczyna w kodzie

`vendor/symfony/amqp-messenger/Transport/Connection.php` (8.1.10):

- `publishOnExchange()` publikuje z flagami z `AmqpStamp`, domyślnie `AMQP_NOPARAM`, czyli bez `mandatory`.
  Bez `mandatory` RabbitMQ potwierdza wiadomość, której nie przypisał do żadnej kolejki.
- `setupExchangeAndQueues()` (deklaracja exchange'u, kolejki i bindingu) wykonuje się raz na obiekt połączenia:
  na końcu ustawia `autoSetupExchange = false`. `clear()` po rozłączeniu tej flagi nie przywraca. Długo działający
  worker nie odtworzy więc usuniętej kolejki ani bindingu.
- `get()` na nieistniejącej kolejce rzuca `AMQPQueueException (404)` → `TransportException` → `messenger:consume`
  kończy się kodem 1.
- `apps/api/src/Shared/Infrastructure/Messaging/RelayDomainEventToBroker.php:25` wysyła bez `AmqpStamp`.

Exchange'u to nie dotyczy: publikacja do nieistniejącego exchange'u to błąd kanału, relay zgłasza wyjątek,
a zdarzenie zostaje w outboxie.

## Odtworzenie

Izolowane środowisko: sieć `m3net`, `postgres:18.6`, `rabbitmq:4.4.0-management`, obraz `shop-t16-api`, `vendor`
z wolumenu `shop-t16_api-vendor` tylko do odczytu, kopia `apps/api` z komendą `qa2:publish` z audytu QA
(`src` i `config` identyczne z rewizją, sprawdzone `diff -rq`). Po weryfikacji kontenery i sieć usunięto.

Kontrola, broker niedostępny: 5 zdarzeń w outboxie, worker `messenger:consume outbox` ponawia
(`Could not connect to the AMQP server`, retry #6-7). Po starcie RabbitMQ: `outbox` 0, kolejka `events` 5.
Gwarancja działa.

Scenariusz A, worker relayu `messenger:consume outbox`:

```
$ rabbitmqctl delete_queue events
Queue was successfully deleted with 5 ready messages
$ bin/console qa2:publish 20
published 20 products
outbox/failed:        (brak wierszy)
list_queues:          (brak kolejki events)
list_exchanges:       events  fanout
list_bindings events: (brak)
```

20 zdarzeń utraconych, w logu workera brak błędów po usunięciu kolejki.

Scenariusz B, worker jak w compose `messenger:consume outbox events`:

```
queue declared by new worker: events 0
$ rabbitmqctl delete_queue events
$ bin/console qa2:publish 10
relay handled: 10, outbox/failed: (brak wierszy)
worker: running=false exit=1
[Symfony\Component\Messenger\Exception\TransportException]
Server channel error: 404, message: NOT_FOUND - no queue 'events' in vhost '/'
```

10 zdarzeń utraconych, po nich worker kończy się błędem. Restartu przez compose nie odtwarzano.

## Kiedy to zachodzi

Potwierdzone:

- Usunięcie kolejki `events` przy działającym workerze (panel RabbitMQ, `rabbitmqctl`, skrypt porządkowy).

Wynika z kodu, nieodtworzone:

- Usunięcie bindingu `events` → `events`.
- Przyszła zmiana topologii (kolejki per kontekst, `binding_keys`, inny typ exchange'u, vision.md zakłada zdarzenia
  między kontekstami). Błędny routing key albo brak bindingu da tę samą cichą utratę, i to bez ingerencji z
  zewnątrz. Najbardziej prawdopodobna przyczyna w przyszłości.
- Deploy, w którym nowy worker zmienia nazwę kolejki, a stary jeszcze relayuje (stara kolejka bez konsumenta
  zostaje, więc tu raczej porzucenie niż utrata).

Nie dotyczy (sprawdzone wcześniej lub wynika z kodu): broker niedostępny, nack (`reject-publish`), brak
exchange'u.

## Proponowany test regresyjny

W `apps/api/tests/Integration/Shared/Messaging/OutboxRelayTest.php`, obok
`testEventRejectedByTheBrokerStaysInTheOutbox`, z istniejącym helperem `managementApi()` i kernelem
`test_messaging`:

```php
public function testEventIsNotLostWhenTheEventsQueueIsMissing(): void
{
    // The first relay sets up the exchange, the queue and the binding, as a long-running worker does.
    $this->publisher()->publish($this->event());
    $this->consume('outbox');
    self::assertCount(1, $this->drainEvents());

    $this->managementApi('DELETE', '/api/queues/test/events');
    try {
        $this->publisher()->publish($this->event());
        $this->consume('outbox');
    } finally {
        $this->restoreEventsQueue();
    }

    // The broker had nowhere to route the event, so the outbox row must not be acked.
    self::assertSame(1, $this->rows('outbox') + $this->rows('failed'));
}
```

- `restoreEventsQueue()`: nowy helper. Ponownie deklaruje kolejkę i binding (`$this->transport('events')->setup()`
  w świeżo uruchomionym kernelu), żeby kolejne testy i `drainEvents()` w `setUp` działały.
- Dziś test nie przechodzi: `0` zamiast `1` (to samo dały scenariusz A i test scratch QA `Qa2LostTest`). Ten test
  w tej postaci nie był uruchamiany.
- Asercja sprawdza gwarancję (zdarzenie zostaje w outboxie albo trafia do `failed`), a nie mechanizm poprawki.
  Dzięki temu pasuje do każdego z wariantów poniżej.
- Drugi, opcjonalny test dla zmiany topologii: `DELETE /api/bindings/test/e/events/q/events/~` zamiast usunięcia
  kolejki, ta sama asercja.

## Możliwe poprawki (do decyzji, nie sprawdzone)

- Publikacja z `mandatory` (`new AmqpStamp(null, AMQP_MANDATORY)` w relayu) i obsługa `basic.return` jako błędu
  publikacji. Symfony 8.1 nie ustawia callbacku zwrotów, więc trzeba sprawdzić, czy zwrot dociera do relayu przed
  potwierdzeniem i jak go zamienić na wyjątek.
- `alternate-exchange` na exchange'u `events`: wiadomości bez trasy trafiają do osobnej kolejki zamiast znikać.
  Sama konfiguracja, ale zdarzenie nie wraca do outboxa, tylko wymaga obsługi z tej kolejki.
- Alarm w dashboardzie, gdy `events` nie ma konsumenta albo kolejki. Wykrywa problem, nie zapobiega utracie.
