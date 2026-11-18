# 01 – Architektura backendu (`apps/api`)

Audyt statyczny migawki `main` (`ef204620`). Ścieżki względem katalogu eksportu.

## Zakres i metoda

- Przedmiot: `apps/api/src` (1286 plików PHP, 61 045 linii), `apps/api/deptrac.yaml`, `apps/api/config/`,
  `apps/api/CLAUDE.md`, `docs/vision.md`, wybrane fragmenty specyfikacji (`walking-skeleton`, `payment`,
  `catalog-admin`, `discount-codes`, `checkout`), w zakresie wdrożenia tylko komenda workera i lista alertów.
- Przeczytane w całości albo w istotnej części: ok. 60 plików (agregaty `Order`, `Cart`, `StockItem`, `VariantPrice`,
  `Payment`; handlery `PlaceOrder`, `MarkOrderPaid`, `ExpireUnpaidOrder`, `ChangeOrderStatus`, `HandleProviderWebhook`,
  `StartPayment`, `ReserveStock`, `ReceiveStock`, `CreateProduct`, `EditProduct`, `PublishProduct`, `QuoteBasket`,
  `GetProductOffers`, `GetDashboard`; repozytoria zamówień, cen i stanów; cały `Shared/Infrastructure/Messaging`;
  adaptery międzymodułowe; subskrybenci zdarzeń; `messenger.yaml`, `doctrine.yaml`, `services.yaml`).
- Reszta kodu przeszukana wzorcami na komplecie plików: macierz importów `use` między modułami i warstwami,
  odwołania w pełnej kwalifikacji bez `use`, nazwy tabel innych modułów w SQL, klucze obce w migracjach, importy
  bibliotek w `Domain` i `Application`, miejsca publikacji zdarzeń, przypisanie handlerów do szyn i transportów,
  liczba portów i ich implementacji, liczba atrap in-memory w testach.
- Niczego nie uruchamiałem (zgodnie z zasadami). Wnioski o zachowaniu Deptraca, Messengera i PHP przy deserializacji
  wynikają z kodu, konfiguracji i znajomości tych narzędzi; miejsca, gdzie to ma znaczenie, są oznaczone.

Rozmiar modułów (pliki / linie): Catalog 318 / 15 995, Pricing 268 / 13 153, Ordering 237 / 11 554, Inventory
131 / 6 162, Cart 115 / 4 835, Payment 74 / 3 293, Shared 64 / 2 973, Backoffice 46 / 1 577, PaymentSimulator
27 / 1 294, Customer 5 / 196. Warstwy: Domain 12,3 tys. linii, Application 20,2 tys., Infrastructure 15,2 tys.,
UI 12,1 tys.

## Ocena ogólna obszaru: 8 / 10

To jest rzetelnie zbudowany modularny monolit, w którym deklarowane zasady są faktycznie przestrzegane w kodzie,
a nie tylko opisane:

- `Domain` wszystkich modułów nie importuje żadnej biblioteki (0 trafień na Symfony, Doctrine, Psr, Ramsey).
- Między modułami nie ma ani jednego zapytania SQL do cudzej tabeli, ani jednego klucza obcego i ani jednego importu
  cudzego `Domain` czy `Infrastructure`. Kontrakty między modułami to komendy, zapytania i widoki na typach prostych.
- Agregaty są bogate (przejścia statusów, niezmienniki, zdarzenia), handlery orkiestrują.
- Spójność krytycznych ścieżek (złożenie zamówienia, płatność, rezerwacja) jest synchroniczna w jednej transakcji,
  a zdarzenia przez outbox obsługują tylko dane pochodne (indeks, cache, e-maile). To dobry, proporcjonalny podział.

Punkty odjęte za: krótki budżet ponowień konsumentów zdarzeń zakończony kolejką `failed` bez automatu i bez
widoczności, regułę zgodności wiadomości, która nie pasuje do serializatora transportów Doctrine, luki w konfiguracji
Deptraca (dziś bez naruszeń, ale bez zabezpieczenia na przyszłość), kilka źle umiejscowionych odpowiedzialności
(oferty w Cart, podwójna rola Backoffice) oraz widoczną ceremonię (RabbitMQ za outboxem, ORM używany wbrew sobie,
duplikaty identyfikatorów). Nie znalazłem błędu poprawności w obszarze architektury, który dałoby się wskazać ścieżką
wykonania w obecnym kodzie; wszystkie znaleziska to ryzyka albo decyzje do dyskusji.

### Odpowiedzi wprost

**Czy poziom DDD/hexagonal jest proporcjonalny do problemu?** W Ordering, Inventory, Pricing, Payment i Cart tak: tam
są prawdziwe niezmienniki, współbieżność i pieniądze, a porty realnie pracują w testach (94 atrapy in-memory,
233 pliki testów jednostkowych, 18 testów współbieżności na procesach). W Catalog częściowo: agregat `Product`
i `CategoryTree` mają reguły, ale większość modułu to odczyty i indeks. W Backoffice i Customer warstw domenowych
słusznie nie ma. Ceremonią są: osobne klasy identyfikatorów UUID w każdym module (kopie różniące się komentarzem),
pięć identycznych portów `Clock`, szyna zapytań używana także wewnątrz modułu (54 `assert(... instanceof ...)` na
wynikach) i skok outbox → RabbitMQ przy jednej kolejce i jednym workerze.

**Gdzie architektura realnie pomaga?** (1) `PlaceOrder`: cztery moduły w jednej transakcji przez porty, z ustaloną
kolejnością blokad, a mimo to każdy moduł testowalny osobno. (2) Domena niemapowana przez Doctrine: zagnieżdżony
`flush` cudzego modułu nie zapisze niczego z agregatu, którego repozytorium jeszcze nie zapisało. (3) Outbox w tej
samej transakcji co agregat, z publikacją w `save` repozytorium tam, gdzie agregat ma wiele ścieżek zapisu.
(4) Zdarzenia niosą tylko identyfikatory, a odbiorcy czytają stan bieżący, więc powtórzenia i zmiana kolejności są
nieszkodliwe.

**Które granice modułów są prawdziwe, a które pozorne?**

- Prawdziwe na poziomie kodu i danych: wszystkie siedem contextów. Brak wspólnych tabel, brak SQL do cudzych tabel,
  brak wspólnych encji, brak FK między modułami. Kierunek zależności w kodzie jest acykliczny: Catalog ← Pricing,
  Inventory ← Cart ← Ordering ← Payment.
- Świadomie nieprawdziwe na poziomie wykonania: moduły dzielą jedną bazę, jedno połączenie, jeden `EntityManager`
  i jedną transakcję (`PlaceOrder`, webhook płatności). To nie są granice wdrożeniowe i nie da się żadnego modułu
  wydzielić bez przeprojektowania spójności. Dla tej skali to dobra decyzja, tylko nie należy jej mylić
  z gotowością do rozdzielenia.
- Cienkie albo mylące: `Cart` jest też modułem „oferta sklepu” (znalezisko 6), `Backoffice` jest naraz kontraktem
  tożsamości pracownika i pulpitem zależnym od wszystkich (znalezisko 7), `Customer` to jeden interfejs i `/me`,
  a subskrybenci indeksu i cache sklepu mieszkają w `Pricing` i `Inventory` (znalezisko 8).

**Moduły bez `Domain` i płaski `PaymentSimulator`.** Uzasadnione. `Backoffice` składa pulpit z zapytań właścicieli
i daje kontrakt `AuthenticatedStaffMember`; `Customer` daje `AuthenticatedCustomer`; żaden nie ma reguł biznesowych.
`PaymentSimulator` to atrapa systemu zewnętrznego: nie importuje niczego z `App\` poza sobą, a sklep rozmawia z nim
wyłącznie przez HTTP. Warstwowanie go byłoby czystą ceremonią. Uwaga porządkowa: Deptrac ma zdefiniowane puste
warstwy `Customer_Domain`, `Backoffice_Domain` i `Shared_Application` (`deptrac.yaml:24`, `:40`, `:145`).

## Znaleziska

### 1. Konsument zdarzeń ma 13 s na ponowienia, potem `failed` bez automatu, licznika i alertu

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:** `apps/api/config/packages/messenger.yaml:48-51` (transport `events`):

  ```yaml
  retry_strategy:
      max_retries: 3
      delay: 1000
      multiplier: 3
  ```

  Dla porównania przekaźnik outboxa (`:35-39`) ponawia 30 razy, ok. 25 minut. Błąd wysyłki e-maila idzie do ponowień
  transportu (`apps/api/src/Ordering/Application/EventHandler/NotificationSender.php:49-55`: `release` i `throw`),
  tak samo niedostępny Elasticsearch, Varnish albo sklep (`ReindexProductOnPriceChange.php:59`,
  `InvalidateStorefrontOnPriceChange`). Pulpit pokazuje tylko outbox
  (`apps/api/src/Backoffice/Infrastructure/MessagingStatisticsAdapter.php:35`:
  `SELECT count(*) FROM messenger_messages WHERE queue_name = ?` z `'outbox'`). Lista alertów
  (`infra/k8s/charts/shop/templates/00-alerts.yaml`) to `BackupFailed`, `BackupMissing`, `BackupVerifyFailed`,
  `BackupVerifyMissing`, `NodeDiskFull`, `ClusterSilent`, `WorkloadNotReady`. W chartcie nie ma zadania, które woła
  `messenger:failed:retry`, ani okresowej przebudowy indeksu.
- **Warunki i prawdopodobieństwo:** dowolna zależność konsumenta niedostępna dłużej niż ok. 13 s: restart
  Elasticsearcha albo Varnisha, przerwa SMTP, restart sklepu. Na jednowęzłowym klastrze to zdarzenie rutynowe
  (każda wymiana poda), więc prawdopodobieństwo jest wysokie.
- **Konsekwencja:** wszystkie zdarzenia z okna awarii lądują w `failed` i tam zostają. Klient nie dostaje
  potwierdzenia zamówienia ani „Płatność przyjęta”; indeks wyszukiwania trzyma starą cenę i dostępność produktu do jego
  następnej zmiany albo ręcznego `catalog:reindex`; Varnish i ISR trzymają stare dane do końca `s-maxage`. Nic nie
  ginie (wiadomości czekają w `failed`), ale jedynym sygnałem jest wpis `critical` w logu i zdarzenie Sentry workera.
  Operator bez Sentry nie dowie się, że trzeba uruchomić `messenger:failed:retry`.
- **Rekomendacja:** (a) wydłużyć ponowienia `events` do rzędu minut (jak outbox: wykładniczo z `max_delay`), bo
  handlery są idempotentne; (b) dodać licznik `failed` do pulpitu obok `outboxPending` i alert na `failed > 0`
  utrzymujące się np. 15 minut; (c) rozważyć zadanie okresowe ponawiające `failed` dla błędów przejściowych.
- **Pewność:** wysoka co do konfiguracji i braku automatu (przeczytane: `messenger.yaml`, handlery, adapter pulpitu,
  alerty, komendy w chartcie). Specyfikacje przyjmują `failed` jako stan końcowy świadomie
  (`docs/features/checkout/spec.md:527`), ale nie uzasadniają 13 s ani braku widoczności.

### 2. Reguła „dodawaj pola jako opcjonalne” nie działa dla wiadomości na transportach Doctrine

- **Waga:** Medium. **Klasa:** RYZYKO (ukryte, uruchomi je pierwsza zmiana pól wiadomości).
- **Dowód:** reguła w `apps/api/CLAUDE.md:1187-1190`: „Pola dodawaj jako opcjonalne z wartością domyślną”. Tylko
  transport `events` ma serializer JSON (`messenger.yaml:42`: `serializer: messenger.transport.symfony_serializer`);
  `outbox`, `scheduled`, `payment_simulator`, `promotions`, `jobs` i `failed` używają domyślnego serializatora PHP
  (`serialize`/`unserialize`), co potwierdza sam `CLAUDE.md` („przechodzi przez serializer PHP transportów Doctrine”).
  Wszystkie wiadomości to klasy `final readonly` z promowanymi właściwościami, np.
  `apps/api/src/Ordering/Domain/OrderPlaced.php:13` i `apps/api/src/Ordering/Application/Command/ExpireUnpaidOrder.php`:

  ```php
  final readonly class ExpireUnpaidOrder
  {
      public function __construct(public string $orderId)
  ```

- **Mechanizm:** `unserialize` nie woła konstruktora, więc wartość domyślna parametru nie zadziała. Nowy kod czytający
  starą wiadomość dostaje obiekt z niezainicjowaną właściwością i kończy się błędem przy pierwszym odczycie pola.
  Stary kod czytający nową wiadomość trafia na nieznaną właściwość klasy `readonly`, co w PHP ≥ 8.2 jest błędem
  („Cannot create dynamic property”), a Messenger traktuje błąd dekodowania jako wiadomość do odrzucenia.
- **Warunki:** wdrożenie, które dodaje pole do zdarzenia albo komendy, gdy w kolejce są wiadomości drugiej wersji.
  Dla `ExpireUnpaidOrder` to zawsze prawda (każde zamówienie z ostatnich 35 minut ma wiadomość w `scheduled`), dla
  outboxa w czasie wymiany podów („stary worker działa do końca wymiany”). Każde zdarzenie przechodzi przez outbox,
  więc dotyczy to też zdarzeń, które potem idą JSON-em do RabbitMQ.
- **Konsekwencja:** przy takim wdrożeniu część wiadomości trafia do `failed` (nieopłacone zamówienia zostają `new`
  z zarezerwowanym stanem), a zdarzenia zapisane przez nowe API i pobrane przez starego workera mogą zostać usunięte
  z kolejki bez obsługi (brak e-maila, brak reindeksacji). Zespół stosujący regułę z `CLAUDE.md` uzna taką zmianę za
  bezpieczną.
- **Rekomendacja:** ustawić serializer JSON jako domyślny dla wszystkich transportów (ignoruje nieznane pola i woła
  konstruktor z wartościami domyślnymi) albo poprawić regułę (nowe pole = nowa klasa wiadomości w dwóch wersjach)
  i dodać test kontraktu: zapisane w repo serializacje wiadomości poprzedniej wersji muszą się dekodować i obsłużyć.
- **Pewność:** średnia. Konfiguracja i klasy są przeczytane; zachowanie `unserialize` dla klas `readonly` i odrzucanie
  niedekodowalnej wiadomości przez odbiornik Doctrine znam z dokumentacji i kodu tych narzędzi, ale nie mogłem tego
  uruchomić. Nie znalazłem testu zgodności wersji wiadomości (`tests/Unit/Shared/Messaging`,
  `tests/Integration/Shared/Messaging`).

### 3. Deptrac pilnuje dzisiejszych granic, ale ma luki na przyszłe zmiany

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Stan faktyczny:** macierz rzeczywistych importów jest czysta. Jedyne zależności między modułami to
  `X\Infrastructure` albo `X\UI` → `Y\Application`, a importowane klasy to wyłącznie komendy, zapytania, widoki,
  zdarzenia integracyjne, kontrakty bezpieczeństwa oraz dwa porty i jeden serwis Catalog. Reguły więc działają. Luki:

  1. **Kod poza warstwami nie jest sprawdzany.** `apps/api/Makefile:15`: `vendor/bin/deptrac analyse --no-progress`,
     bez `--fail-on-uncovered`. Warstwy łapią tylko `App\<Moduł>\{Domain,Application,Infrastructure,UI}\`. Klasa
     w `App\Catalog\Service\…` albo cały nowy moduł bez wpisu w `deptrac.yaml` może importować cokolwiek, a zależność
     od niej jest „uncovered”, nie naruszeniem. Dziś poza warstwami jest tylko `App\Kernel`.
  2. **`Application` może importować całą warstwę `Vendor`** (`deptrac.yaml:176-180`), choć specyfikacja mówi
     o atrybutach i interfejsach Messengera (`docs/features/walking-skeleton/spec.md:208`). Doctrine DBAL,
     HttpFoundation czy Security w handlerze przejdą `make check`. Faktyczne importy to dziś Messenger, `Uid`,
     `Psr\Log`, atrybuty DI i jeden `OpenApi\Attributes`
     (`apps/api/src/Catalog/Application/Query/SitemapProduct.php:7`), więc dryf już się zaczął.
  3. **Publicznym API modułu jest cała jego warstwa `Application`.** Komentarze obiecują więcej, niż reguła wymusza
     (`deptrac.yaml`, przy `Backoffice_Infrastructure`: „the queries and the views of Ordering and Inventory, nothing
     else”). Reguła pozwala `Backoffice_Infrastructure` wysłać `ChangeOrderStatus` albo wstrzyknąć port
     `Ordering\Application\Port\SalesHours`, a `Payment_Infrastructure` dowolną komendę Ordering.
  4. **Martwe, zbyt szerokie zezwolenia:** `Catalog_Infrastructure → Customer_Application` (`deptrac.yaml:188`),
     `Customer_Infrastructure → Catalog_Application, Backoffice_Application` (`:217-218`),
     `Backoffice_Infrastructure → Customer_Application` (`:242`). Żadnego z nich kod nie używa.
  5. **Brak kontroli cykli między modułami.** Reguły dopuszczają `Backoffice ↔ Ordering/Inventory/Catalog`
     (znalezisko 7) i `Catalog ↔ Customer`.
- **Konsekwencja:** granice trzymają się dziś dyscypliną autorów i przeglądów, a nie narzędzia. Przy kodzie pisanym
  w większości przez agentów to istotne: agent, któremu `make check` przechodzi, uzna zmianę za zgodną z architekturą.
- **Rekomendacja:** `--fail-on-uncovered` i warstwa zbierająca resztę `App\`; zawęzić `Vendor` w `Application` do
  listy przestrzeni nazw; wydzielić w `Application` kontrakt publiczny (np. kolektor na `Command|Query|Event|View|
  Security`) i tylko jego wpuszczać z innych modułów; usunąć cztery nieużywane zezwolenia.
- **Pewność:** wysoka co do reguł i importów (przeczytany cały `deptrac.yaml`, policzone wszystkie `use` między
  modułami, sprawdzone odwołania bez `use`). Zachowanie Deptraca wobec klas poza warstwami znam z narzędzia, nie
  z uruchomienia.

### 4. Jeden worker, jedna kolejka `events` dla wszystkich odbiorców, transporty w ścisłej kolejności

- **Waga:** Medium. **Klasa:** RYZYKO.
- **Dowód:** `infra/k8s/charts/shop/templates/api.yaml:136` i `infra/docker-compose.yml:350`:
  `messenger:consume outbox events scheduled payment_simulator promotions jobs`. Wszystkie 17 handlerów zdarzeń
  ma `fromTransport: 'events'`, czyli jedną kolejkę. Unieważnienie cache ma limity 5 s (Varnish) i 30 s (sklep):
  `apps/api/src/Shared/Infrastructure/Http/Cache/HttpStorefrontCache.php:24-25`.
- **Warunki:** seria zdarzeń (granica promocji z 1000 wariantów, zmiana klasy podatkowej, przyjęcia magazynowe) przy
  wolnym albo niedostępnym Varnishu, sklepie czy Elasticsearchu. Worker bierze kolejny transport dopiero, gdy
  poprzedni jest pusty.
- **Konsekwencja:** e-maile z potwierdzeniem zamówienia czekają za reindeksacją i unieważnieniami w tej samej
  kolejce, a `scheduled` (anulowanie nieopłaconych zamówień, więc zwolnienie rezerwacji) i webhooki symulatora czekają
  na opróżnienie `events`. Przy 1000 zdarzeń i zawieszonym Varnishu to ponad godzina blokady samego pierwszego
  podejścia. `CLAUDE.md` odnotowuje opóźnienie webhooków, ale nie e-maili i wygaśnięć.
- **Rekomendacja:** osobny proces workera dla `scheduled` i `promotions` oraz osobna kolejka (albo choć osobny
  worker) dla e-maili; to zmiana konfiguracji, nie kodu. Handlery są idempotentne, więc kilka replik jest bezpieczne.
- **Pewność:** średnia: konfiguracja przeczytana, skala opóźnień wyliczona z limitów czasu, nie zmierzona.

### 5. RabbitMQ za outboxem nie daje dziś nic, czego nie daje transport Doctrine

- **Waga:** Medium. **Klasa:** PREFERENCJA (zbędna złożoność, bez wykazanej szkody).
- **Dowód:** `messenger.yaml:107-113`: wszystkie zdarzenia idą najpierw do `outbox` (tabela `messenger_messages`),
  stamtąd `RelayDomainEventToBroker` przenosi je do jedynej kolejki AMQP `events`, którą czyta ten sam proces workera.
  Pięć innych kolejek (`scheduled`, `promotions`, `jobs`, `payment_simulator`, `failed`) działa już na transporcie
  Doctrine. Skok wymaga własnego transportu (`RoutedAmqpTransport`, `RoutedAmqpTransportFactory`: publikacja
  `mandatory`, potwierdzenia), osobnej usługi w compose i chartcie, sondy, testów na vhoście i drugiego serializatora.
- **Ocena:** nie ma fan-outu, routingu, wielu grup konsumentów ani odciążenia Postgresa (każde zdarzenie i tak jest
  wierszem outboxa). Odbiorcy mogliby czytać `outbox` bezpośrednio z tą samą gwarancją at-least-once. Broker ma sens,
  gdy pojawią się osobne kolejki na odbiorcę (co rozwiązałoby znalezisko 4) albo zewnętrzni konsumenci.
- **Rekomendacja:** albo wykorzystać brokera (kolejka na grupę odbiorców), albo zapisać w ADR, że jest wyborem
  pokazowym. Nie rekomenduję usuwania działającego i przetestowanego rozwiązania bez innego powodu.
- **Pewność:** wysoka co do stanu, ocena jest opinią.

### 6. „Oferta produktu” mieszka w `Cart`, a Catalog zależy od niego w czasie wykonania

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** `apps/api/src/Cart/UI/Http/ProductOffersController.php:40` (`/api/v1/product-offers`),
  `apps/api/src/Cart/Application/Query/GetProductOffersHandler.php:26` (składa warianty Catalog, ceny Pricing i stany
  Inventory), reguła „możliwy do kupienia” w `apps/api/src/Cart/Domain/ProductOffer.php`. Indeks wyszukiwania Catalog
  bierze te dane przez port: `apps/api/src/Cart/Infrastructure/Catalog/QueryBusProductOfferFacts.php:39`
  (`new GetProductOffers($batch, withLowestPriorPrice: false)`), a `GetProductOffers` woła z powrotem Catalog
  (`ProductCatalog::variantsOf`).
- **Konsekwencja:** importy są acykliczne, ale w czasie wykonania jest pętla Catalog → Cart → Catalog, Pricing,
  Inventory, której Deptrac nie widzi. Moduł o nazwie „koszyk” jest właścicielem kafelka listingu, ceny „od” i danych
  indeksu, więc zmiana w Cart może zepsuć wyszukiwarkę. Nowa osoba nie będzie tam szukać reguły dostępności.
- **Rekomendacja:** przy najbliższej większej zmianie wydzielić mały moduł `Offer` (zapytanie, widoki, reguła
  kupowalności), z którego korzystają Cart i indeks Catalog. Do tego czasu opisać tę rolę Cart w tabeli contextów.
- **Pewność:** wysoka.

### 7. `Backoffice` ma dwie role i tworzy cykl między modułami

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** `apps/api/src/Ordering/Infrastructure/Security/OrderVoter.php:7`
  (`use App\Backoffice\Application\Security\AuthenticatedStaffMember;`) i w drugą stronę
  `apps/api/src/Backoffice/Infrastructure/QueryBusOrderQueues.php:9`
  (`use App\Ordering\Application\Query\GetOrderQueues;`). To samo dla Inventory i Catalog.
- **Konsekwencja:** kontrakt tożsamości pracownika (od którego zależy każdy moduł) i pulpit (który zależy od każdego
  modułu) są jednym modułem, więc graf modułów ma cykle. Skutek uboczny reguł warstw: nazwy uprawnień są powielane
  literałami, bo UI nie może importować voterów. `'MANAGE_PRODUCTS'` jest zdefiniowane w pięciu kontrolerach Catalog,
  w `ProductVoter` i w `Backoffice/UI/Http/DashboardController.php:34`. Literówka kończy się odmową dostępu
  (bezpiecznie), ale wykryje ją dopiero test ról.
- **Rekomendacja:** rozdzielić na `Staff` (kontrakt tożsamości, bez zależności) i `Backoffice` (pulpit). Stałe
  uprawnień trzymać w `Application\Security` modułu-właściciela.
- **Pewność:** wysoka.

### 8. Subskrybenci indeksu i cache sklepu mieszkają w modułach, które publikują zdarzenia

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** `apps/api/src/Pricing/Infrastructure/Search/ReindexProductOnPriceChange.php:29-59`: handler w Pricing
  woła `App\Catalog\Application\Search\ProductIndexing` i zna wyjątki indeksu. Bliźniacze klasy:
  `Pricing/Infrastructure/Search/ReindexProductOnPromotionChange`, `Inventory/Infrastructure/Search/
  ReindexProductOnStockLevelChange` i trzy `InvalidateStorefrontOn…` (razem sześć prawie identycznych handlerów).
- **Przyczyna:** `VariantPriceChanged` i `StockLevelChanged` są w `Domain`, którego nikt obcy nie może importować,
  więc odbiorca musi stać po stronie nadawcy. Zdarzeniami integracyjnymi (w `Application`) są tylko trzy zdarzenia
  Catalog.
- **Konsekwencja:** Pricing i Inventory wiedzą o indeksie wyszukiwania i o Varnishu. Nowy odbiorca zmiany ceny wymaga
  edycji Pricing, czyli odwrotnie niż obiecuje komunikacja zdarzeniami. Logika „kiedy reindeksować” leży
  w `Infrastructure`. Do zdarzenia domenowego trafił też powód techniczny: `PriceChangeSource::Seed` służy do
  pomijania odbiorców przy seedzie (`:47`).
- **Rekomendacja:** opublikować zmianę ceny i poziomu stanu jako zdarzenia integracyjne (wzorzec już istnieje:
  `Catalog\Application\Event\IntegrationEvents`) i przenieść odbiorców do Catalog; sześć handlerów zredukuje się do
  dwóch.
- **Pewność:** wysoka.

### 9. `ProductRepository::all()` ładuje cały katalog do pamięci i nie ma użytkownika w kodzie produkcyjnym

- **Waga:** Low. **Klasa:** RYZYKO.
- **Dowód:** `apps/api/src/Catalog/Domain/Product/ProductRepository.php:36` (`public function all(): iterable;`)
  i `apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DoctrineProductRepository.php:78-93`:

  ```php
  ->leftJoin('product.additionalCategories', 'additional')
  ->leftJoin('product.variants', 'variant')
  ->orderBy('product.slug')
  ->addOrderBy('variant.position')
  ->getQuery()
  ->getResult();
  ```

  Jedyni wołający to trzy miejsca w `tests/Integration/Catalog/Persistence/DoctrineProductRepositoryTest.php`.
- **Konsekwencja:** dziś żadna. Metoda leży jednak w porcie domeny i wygląda na gotową do użycia; przy docelowych
  10 mln produktów pierwsze użycie (eksport, raport, komenda) wyczerpie pamięć. Główny `CLAUDE.md` zakazuje
  ładowania całego katalogu do pamięci.
- **Rekomendacja:** usunąć metodę z portu i implementacji, testy przepisać na `get()`.
- **Pewność:** wysoka (przeszukane `src` i `tests`).

### 10. Dwa agregaty czytają zegar systemowy w domenie

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** `apps/api/src/Inventory/Domain/Stock/StockItem.php:218` (`$this->updatedAt = new \DateTimeImmutable();`,
  ten czas trafia do historii stanów i do `StockLevelChanged`) oraz
  `apps/api/src/Pricing/Domain/Price/VariantPrice.php:43` i `:154` (`$at ?? new \DateTimeImmutable()`). `Order`,
  `Cart`, `Payment` i `Product` dostają `now` z portu `Clock`. Inventory jako jedyny moduł z agregatami nie ma portu
  `Clock`.
- **Konsekwencja:** czas wpisu historii stanu i historii cen (podstawa Omnibusa przy `reprice`) nie jest sterowalny
  w testach, a zasada jest niespójna między modułami. Bez wpływu na produkcję.
- **Rekomendacja:** przekazywać `now` jak w pozostałych agregatach.
- **Pewność:** wysoka.

### 11. Wyjątki domenowe niosą identyfikatory RFC 9457 i komunikaty dla użytkownika

- **Waga:** Low. **Klasa:** PREFERENCJA.
- **Dowód:** `apps/api/src/Ordering/Domain/OrderTransitionNotAllowed.php:15-19`:

  ```php
  public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/order-transition-not-allowed';
  …
  parent::__construct('Tej zmiany nie można wykonać w obecnym statusie zamówienia.');
  ```

  29 klas w `*/Domain` implementuje `TypedProblem`, a znaczniki `NotFound`, `Conflict`, `InvalidInput`
  (`Shared/Domain/Exception`) odpowiadają jeden do jednego statusom HTTP.
- **Ocena:** domena nie zna liczb statusów, ale zna słownik protokołu i polskie teksty interfejsu. To pragmatyczne
  (jedno miejsce prawdy, brak warstwy tłumaczeń) i przy jednym kliencie i jednym języku nie szkodzi. Koszt pojawi
  się przy drugim języku (poza zakresem według wizji).
- **Rekomendacja:** zostawić; zapisać jako świadomą decyzję w ADR.
- **Pewność:** wysoka.

## Mocne strony

1. **Czysta domena, sprawdzona w kodzie.** Brak importów bibliotek w `*/Domain` i `Shared/Domain`. `Application`
   importuje z bibliotek tylko Messenger, `Uid`, `Psr\Log` i atrybuty DI. Żadna warstwa niższa nie importuje wyższej
   (0 trafień `Application|Domain|Infrastructure → UI`, `UI|Application|Domain → własne Infrastructure`).
2. **Prawdziwa izolacja danych.** Żadnego SQL do tabeli innego modułu, żadnego FK między modułami w migracjach (13 FK,
   wszystkie wewnątrz modułu). Jedyna wspólna tabela to `messenger_messages`. Dane cudzego modułu są kopiowane
   zdarzeniami (`inventory_stock_item.product_id` przez `SyncStockRecordsOnProductChange`) albo zamrażane
   (`OrderLine`).
3. **Kontrakty międzymodułowe na typach prostych.** `QuotedBasket`, `CartLineForOrder`, `OrderForPayment`,
   `MarkOrderPaid`, `StockLevel`, `VariantSummary` mają wyłącznie `string`, `int`, `bool`, `DateTimeImmutable`
   i własne widoki. Obiekt domeny nie przecieka przez wynik zapytania, więc Deptrac nie jest obchodzony typami
   zwracanymi.
4. **Bogate agregaty.** `Order` (`apps/api/src/Ordering/Domain/Order.php:179-306`): `markPaid` decyduje po czasie
   wykonania płatności i zwraca wynik domenowy, przejścia pracownika z tabeli `OrderStatus`, zwrot jako value object,
   historia tylko dopisywana. `StockItem` pilnuje `0 ≤ reserved ≤ physical` i zapisuje ruch z każdą zmianą. `Payment`
   rozstrzyga `succeed` po wcześniejszej odmowie.
5. **Outbox zrobiony poprawnie.** Zdarzenie jest wierszem w tej samej transakcji (`MessengerDomainEventPublisher`,
   transport Doctrine na połączeniu agregatów). Gdzie agregat ma wiele ścieżek zapisu, publikuje repozytorium wewnątrz
   transakcji zapisu (`DoctrineVariantPriceRepository.php:104-107`: `wrapInTransaction` z `write` i `publish`).
   Przekaźnik potwierdza wiersz dopiero po potwierdzeniu brokera, a `RoutedAmqpTransport` wykrywa wiadomość bez
   trasy. Każdy handler zdarzenia ma jawne `fromTransport`, więc nie ma pętli ani podwójnej obsługi. Testy:
   `OutboxAtomicityTest`, `OutboxRelayTest` (30 ponowień, `failed`, ponowienie po powrocie brokera), `EventFlowTest`.
6. **Zdarzenia cienkie, odbiorcy idempotentni.** Zdarzenia niosą identyfikator i czas; odbiorca czyta stan bieżący
   (`IndexProduct`, `RepriceOnTaxClassChange`), indeks odrzuca starszy odczyt (`external_gte`), e-maile mają zajęcie
   z lease (`NotificationSender`).
7. **Rozsądny podział synchroniczne / asynchroniczne.** Wszystko, co decyduje o pieniądzach i stanie, jest w jednej
   transakcji (`PlaceOrderHandler`: koszyk, kod, rezerwacja, zamówienie, outbox, opóźnione wygaśnięcie). Bez sag
   i kompensacji, których ta skala nie potrzebuje. Handler nigdy nie łapie wyjątku zagnieżdżonej komendy, a adaptery
   tłumaczą tylko błędy biznesowe (`CommandBusStockReservations.php:41-49`).
8. **Porty mają realnych użytkowników.** 86 interfejsów w `Application/Port`, 94 atrapy w testach; `PaymentProvider`
   ma dwa adaptery o różnych protokołach, a `SitemapProductSource` dekorator z cache.
9. **CQRS w proporcji.** Trzy szyny z jawnym przypisaniem każdego handlera (44 zapytania, 45 komend, 18 zdarzeń, żaden
   bez `bus:`), żaden handler zapytań nie zapisuje. Listy czytają DBAL z kursorem keyset, a modele odczytu
   (`ordering_sales_hour`, `ordering_customer`) są aktualizowane w transakcji zapisu i mają przebudowę odporną na
   równoległy ruch.
10. **Cienkie UI.** Kontrolery mapują wejście na komendę i wynik na osobne klasy odpowiedzi; jedyny dostęp do
    Doctrine w UI to rozpoznanie utraty połączenia w `DashboardController`.

## Zbędna złożoność

- **RabbitMQ i przekaźnik** przy jednej kolejce i jednym workerze (znalezisko 5).
- **ORM używany wbrew sobie.** Zasada „persystencja przez rekordy Doctrine” ma w `apps/api/CLAUDE.md` kilkanaście
  opisanych wyjątków (Inventory, koszyk, promocje, drzewo kategorii, użycie kodu, sprzedaż godzinowa, klienci, zdarzenia
  webhooków, znaczniki e-maili, przebiegi reindeksacji). `Doctrine\DBAL\Connection` wstrzykuje 41 klas,
  `EntityManagerInterface` 18. W repozytoriach ORM jest 15 miejsc z `refresh`/`HINT_REFRESH`, ręczne ustawianie
  wersji rekordu przed `flush` (`DoctrineVariantPriceRepository.php:137`) i `flush` w dwóch krokach
  (`DoctrineOrderRepository::save`). Identity map i UnitOfWork są tu głównie źródłem pułapek, a nie korzyści. Wizja
  zapowiadała ocenę osobnego modelu persystencji „po walking skeleton” (`docs/vision.md:380`); wyniku nie ma.
  Uczciwy wniosek z kodu: zapis przez DBAL (jak w Inventory) byłby prostszy i spójniejszy niż dzisiejsza mieszanka.
- **Kopie identyfikatorów.** `VariantId` i `ProductId` istnieją w pięciu modułach; wersje Pricing i Inventory
  różnią się jednym słowem w komentarzu. Osobne `Money`/`Amount`/`Price` mają uzasadnienie semantyczne, osobne
  opakowania UUID nie.
- **Pięć identycznych par `Clock` + `SystemClock`** oraz trzy kopie rozpakowania `HandlerFailedException`
  (`Ordering/Infrastructure/BusFailure.php`, `Cart/Infrastructure/QueryBusFailure.php`,
  `Backoffice/Infrastructure/AsksQueryBus.php`). To mogłoby być w `Shared` bez naruszania granic domen.
- **Szyna zapytań wewnątrz modułu.** Zysk jest na granicy modułów (adapter zna tylko klasę zapytania). Wewnątrz
  modułu kontroler → szyna → handler kosztuje utratę typów: 54 `\assert(… instanceof …)` i 52 użycia `HandleTrait`.
- **Middleware `validation` na `command.bus`** (`messenger.yaml:12`) nie ma czego walidować: żadna klasa
  w `*/Application` nie ma ograniczeń walidatora (0 plików), wszystkie 33 są w `UI`.
- **Łańcuch reprezentacji ceny** przy złożeniu zamówienia: domena Pricing → `QuotedBasket` → DTO portu Ordering →
  `PriceCopy`/`LineAmounts` → rekord → widok → klasa odpowiedzi. Na granicy Pricing/Ordering to cena za zamrożoną
  kopię i jest warta kosztu; warto tylko wiedzieć, że nowe pole ceny to sześć miejsc do zmiany.

## Braki

- **Brak ADR dla decyzji backendu.** `docs/adr/` ma trzy pliki (biblioteka UI panelu, kopie i obserwowalność, k3s).
  `docs/vision.md:371-398` wymienia jako „Do opisania” m.in. „Dlaczego DDD + hexagonal w tym systemie (i gdzie to
  przesada)”, outbox, model persystencji, osobne value objecty i model blokad. Uzasadnienia żyją w `apps/api/CLAUDE.md`
  (1218 linii, 136 kB), który jest instrukcją pracy, a nie zapisem decyzji, i miesza zasady ze szczegółami
  pojedynczych endpointów.
- **Wizja opisuje context, którego nie ma.** `docs/vision.md:203` i `:89`: „Customer: Profil klienta, adresy”.
  W kodzie Customer to `AuthenticatedCustomer` i `GET /me` (5 plików, 196 linii), a akapit niżej wizja sama stwierdza,
  że „Context Customer zostaje przy tożsamości z tokenu”. Tabela contextów nie wymienia też `Backoffice`,
  `PaymentSimulator` i `Shared`.
- **Brak testu architektury poza Deptrakiem:** nic nie sprawdza, że każdy handler zdarzenia ma `fromTransport`
  (dziś reguła ze specyfikacji, `walking-skeleton/spec.md`, punkt 4 trasy zdarzeń) ani że nowa wiadomość jest
  zgodna z poprzednią wersją (znalezisko 2).
- **Brak widoczności i obsługi `failed`** (znalezisko 1) oraz kolejek Doctrine innych niż outbox na pulpicie.
- **Brak zdefiniowanego publicznego API modułu** (znalezisko 3, punkt 3): konwencja jest dobra, ale niezapisana
  i niewymuszana.

## Czego nie zweryfikowano

- Nie uruchomiłem Deptraca, PHPStana ani testów. Twierdzenie „reguły dziś nie są naruszone” opiera się na własnej
  macierzy importów `use` i wyszukaniu odwołań w pełnej kwalifikacji, nie na wyniku narzędzia.
- Zachowanie Deptraca dla klas poza warstwami (znalezisko 3.1) oraz PHP i Messengera przy deserializacji wiadomości
  o zmienionych polach (znalezisko 2) nie zostało potwierdzone uruchomieniem; katalogu `vendor` nie ma w eksporcie.
- Opóźnienia z jednego workera (znalezisko 4) są wyliczone z limitów czasu, nie zmierzone. Liczby replik workera
  w środowisku wdrożeniowym nie ustalałem.
- Nie czytałem w całości Catalog (wyszukiwanie, indeks Elasticsearch, drzewo kategorii), promocji i kodów w Pricing
  ani `PaymentSimulator`; oceniłem je po strukturze, kontraktach, importach i wybranych handlerach.
- Poprawność blokad, kolejności blokad i zapytań SQL (zakleszczenia, plany) opisana w `CLAUDE.md` nie była
  przedmiotem tego audytu poza sprawdzeniem, że transakcje i publikacja zdarzeń obejmują to, co deklarują.
- Świadomie przyjęte w specyfikacjach ryzyka, których nie zgłaszam jako znalezisk: wywołania HTTP dostawcy
  w transakcji `StartPayment` (`docs/features/payment/spec.md:643-650`), odczyt stanu bez blokady przy usuwaniu
  wariantu (`docs/features/catalog-admin/spec.md:408`), brak zwolnienia użycia kodu przy anulowaniu przez pracownika
  (`docs/features/discount-codes/spec.md:51`).
