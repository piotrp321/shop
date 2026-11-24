<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Integration\Ordering\BackofficeOrderFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/v1/admin/orders with the search by fragment (`q`) and the range of days (`placedFrom`, `placedTo`, days of
 * Europe/Warsaw), on PostgreSQL (shop_test, rolled back after each test).
 */
final class OrderSearchEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait {
        setUp as setUpClient;
    }

    private const string PATH = '/api/v1/admin/orders';
    private const string BAD_TERM = 'Szukana fraza to numer zamówienia albo tekst od 3 do 100 znaków bez znaków sterujących.';

    protected function setUp(): void
    {
        $this->setUpClient();
        BackofficeOrderFixture::noOrders($this->connection);
    }

    /**
     * @return iterable<string, array{string, string}> the term, the number of the one order it finds
     */
    public static function fragments(): iterable
    {
        yield 'the end of a number' => ['00456', 'R-100456'];
        yield 'the digits of a number with a dash' => ['-1004', 'R-100456'];
        yield 'a part of an email' => ['nowak@exam', 'R-100123'];
        yield 'a fragment of an email with an @' => ['jan@gm', 'R-100456'];
        yield 'a complete email in another letter case, exactly as before' => ['ANNA.NOWAK@EXAMPLE.COM', 'R-100123'];
        yield 'a surname' => ['wiśniew', 'R-100789'];
        yield 'a surname in capitals, with Polish letters' => ['ŻÓŁĆ', 'R-100456'];
        yield 'spaces around the fragment' => ['  Zofia  ', 'R-100789'];
        yield 'a name with a space inside' => ['Łukasz Żółć', 'R-100456'];
    }

    #[DataProvider('fragments')]
    public function testAFragmentOfTheNumberTheEmailOrTheSurnameFindsTheOrder(string $q, string $number): void
    {
        $this->seed();

        $list = $this->list('q='.rawurlencode($q));

        self::assertResponseStatusCodeSame(200);
        self::assertMatchesSchema('BackofficeOrderList', $list);
        self::assertSame([$number], $this->numbers($list));
        self::assertSame(['value' => 1, 'capped' => false], $list['total']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literals(): iterable
    {
        yield 'a percent sign' => ['50%', 'R-100901'];
        yield 'an underscore' => ['a_b', 'R-100902'];
        yield 'a backslash' => ['c\\d', 'R-100903'];
    }

    #[DataProvider('literals')]
    public function testPercentUnderscoreAndBackslashMatchLiterally(string $q, string $number): void
    {
        $this->seed();
        $this->order('R-100905', '-1 day', 'abc@example.com', 'Aaa Czzz');

        self::assertSame([$number], $this->numbers($this->list('q='.rawurlencode($q))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function patterns(): iterable
    {
        yield 'percent signs only' => ['%%%'];
        yield 'underscores only' => ['___'];
        yield 'a percent sign between letters' => ['a%c'];
        yield 'an underscore between letters' => ['a_c'];
    }

    #[DataProvider('patterns')]
    public function testACharacterOfAPatternIsNoPattern(string $q): void
    {
        $this->seed();
        $this->order('R-100905', '-1 day', 'abc@example.com', 'Aaa Czzz');

        self::assertSame([], $this->numbers($this->list('q='.rawurlencode($q))));
    }

    public function testTheExactNumberAndTheFullEmailBehaveAsBefore(): void
    {
        $this->order('R-1000020', '-4 days', 'jan.kowalski@example.com', 'Jan Kowalski');
        $wanted = $this->order('R-100002', '-3 days', 'Jan.Kowalski@Example.com', 'Jan Kowalski');
        $this->order('R-100003', '-2 days', 'kowalski@example.com', 'Anna Nowak');

        self::assertSame([$wanted], $this->ids($this->list('q=R-100002')));
        self::assertSame([$wanted], $this->ids($this->list('q=r-100002')));
        self::assertSame([$wanted], $this->ids($this->list('q=%20R-100002%20')));
        $byEmail = $this->ids($this->list('q='.rawurlencode('JAN.KOWALSKI@EXAMPLE.COM')));
        self::assertCount(2, $byEmail);
        self::assertContains($wanted, $byEmail);
        self::assertNotContains($this->idOf('R-100003'), $byEmail, 'kowalski@example.com holds no part of that email');
    }

    public function testACompleteEmailStaysExactSoTheOrdersOfAnotherCustomerAreNotListed(): void
    {
        $wanted = $this->order('R-100001', '-3 days', 'anna@example.com', 'Anna Nowak');
        $this->order('R-100002', '-2 days', 'joanna@example.com', 'Joanna Nowak');
        $this->order('R-100003', '-1 day', 'anna@example.com.pl', 'Anna Nowak');

        self::assertSame([$wanted], $this->ids($this->list('q='.rawurlencode('Anna@Example.com'))));
        self::assertSame(['R-100003', 'R-100002', 'R-100001'], $this->numbers($this->list('q='.rawurlencode('anna@example'))), 'without a top-level domain it is a fragment');
        self::assertSame(['R-100003', 'R-100002', 'R-100001'], $this->numbers($this->list('q='.rawurlencode('nna@ex'))));
    }

    public function testAnEmailTooLongForAFragmentIsStillFoundExactly(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.example.com';
        self::assertGreaterThan(100, \strlen($email));
        $wanted = $this->order('R-100001', '-2 days', $email, 'Jan Kowalski');
        $this->order('R-100002', '-1 day', 'x'.$email, 'Jan Kowalski');

        self::assertSame([$wanted], $this->ids($this->list('q='.rawurlencode(strtoupper($email)))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTerms(): iterable
    {
        yield 'two characters' => ['ab'];
        yield 'one character' => ['a'];
        yield 'two characters between spaces' => ['%20%20ab%20%20'];
        yield 'over a hundred characters, no email' => [str_repeat('a', 101)];
        yield 'a no-break space only' => ['%C2%A0%C2%A0%C2%A0%C2%A0'];
        yield 'a tab inside' => ['jan%09kowalski'];
        yield 'a line break inside' => ['jan%0Akowalski'];
        yield 'the separator of the index' => ['jan%1Fkowalski'];
        yield 'a line separator' => ['jan%E2%80%A8kowalski'];
        yield 'a number of 300 digits' => ['R-'.str_repeat('1', 300)];
        yield 'a NUL' => ['jan%00kowalski'];
        yield 'not UTF-8' => ['jan%FFkowalski'];
        // The rule of texts (spec api-input-contract, T1 and T3).
        yield 'three zero width spaces' => ['%E2%80%8B%E2%80%8B%E2%80%8B'];
        yield 'three Hangul fillers' => ['%E3%85%A4%E3%85%A4%E3%85%A4'];
        yield 'a C1 control inside' => ['jan%C2%9Fkowalski'];
    }

    #[DataProvider('refusedTerms')]
    public function testATermThatIsNeitherANumberNorAFragmentIsA400AtQ(string $q): void
    {
        $this->seed();

        $problem = $this->list('q='.$q);

        self::assertSame(['q'], $this->violationPaths(400, $problem));
        self::assertIsArray($problem['violations'] ?? null);
        self::assertSame(self::BAD_TERM, $this->firstViolationTitle($problem));
        self::assertStringNotContainsString('kowalski', (string) $this->response()->getContent());
    }

    /**
     * Every piece of the number, the email and the name of some orders is searched, as the fragments of one field and
     * as fragments over the end of one field and the start of the next: the list is exactly the orders with the
     * fragment in one of the three fields, so a fragment never reaches over the border of two.
     */
    public function testTheResultIsTheMatchInAnyFieldSeparatelyAndNeverAcrossTwoFields(): void
    {
        $specs = [
            ['R-100123', 'anna.nowak@example.com', 'Anna Nowak'],
            ['R-100456', 'Jan@Gmail.com', 'Łukasz Żółć'],
            ['R-100789', 'zofia_n@wp.pl', 'Zofia 50% Wiśniewska'],
            ['R-100790', 'a.b@b.pl', 'Ola a\\b'],
            ['R-120123', 'nowak@anna.pl', 'Nowak-Anna'],
        ];
        foreach ($specs as $n => [$number, $email, $name]) {
            $this->order($number, '-'.(10 - $n).' hours', $email, $name);
        }

        $fragments = [];
        foreach ($specs as [$number, $email, $name]) {
            foreach ([$number, $email, $name] as $field) {
                $length = mb_strlen($field);
                for ($start = 0; $start + 3 <= $length; ++$start) {
                    foreach ([3, 4, 6, $length - $start] as $size) {
                        $fragments[] = mb_substr($field, $start, $size);
                    }
                }
            }
            // The end of one field with the start of the next, as the joined text is read.
            foreach ([[$number, $email], [$email, $name], [$number, $name]] as [$left, $right]) {
                foreach ([1, 2, 3] as $tail) {
                    $fragments[] = mb_substr($left, -$tail).mb_substr($right, 0, 4 - $tail);
                    $fragments[] = mb_substr($left, -$tail).' '.mb_substr($right, 0, 4 - $tail);
                    $fragments[] = mb_substr($left, -4).mb_substr($right, 0, 4);
                }
            }
        }
        $checked = 0;
        foreach (array_unique($fragments) as $fragment) {
            // Not a fragment: an exact number, or a complete email, which is searched exactly.
            if (mb_strlen($fragment) < 3 || mb_strlen($fragment) > 100 || trim($fragment) !== $fragment || 1 === preg_match('/^[Rr]-[0-9]+$/D', $fragment) || 1 === preg_match('/^[^@\s]+@(?:[^@\s.]+\.)+[^@\s.]{2,}$/Du', $fragment)) {
                continue;
            }
            $expected = [];
            foreach ($specs as [$number, $email, $name]) {
                foreach ([$number, $email, $name] as $field) {
                    if (str_contains(mb_strtolower($field), mb_strtolower($fragment))) {
                        $expected[] = $number;
                        break;
                    }
                }
            }
            $found = $this->numbers($this->list('q='.rawurlencode($fragment)));
            sort($expected);
            sort($found);
            self::assertSame($expected, $found, 'fragment: '.$fragment);
            ++$checked;
        }
        self::assertGreaterThan(100, $checked);
        // The end of the number and the start of the email, which only the joined text holds.
        self::assertSame([], $this->numbers($this->list('q='.rawurlencode('R-100123anna'))));
        self::assertSame([], $this->numbers($this->list('q='.rawurlencode('comAnna'))));
    }

    public function testTheStatusTheDateSortAndThePagingKeepTheSearch(): void
    {
        $oldest = $this->order('R-100001', '-5 days', 'jan@example.com', 'Jan Kowalski', 'paid');
        $this->order('R-100002', '-4 days', 'anna@example.com', 'Anna Nowak', 'paid');
        $this->order('R-100003', '-3 days', 'jan@example.com', 'Jan Kowalski', 'shipped');
        $middle = $this->order('R-100004', '-2 days', 'jan@example.com', 'Jan Kowalski', 'paid');
        $newest = $this->order('R-100005', '-1 day', 'jan@example.com', 'Jan Kowalski', 'paid');

        self::assertSame([$newest, $middle, $oldest], $this->ids($this->list('status=paid&q=kowal')));
        self::assertSame([$oldest, $middle, $newest], $this->ids($this->list('status=paid&q=kowal&sort=placedAt')));
        $first = $this->list('status=paid&q=kowal&perPage=2');
        self::assertSame([$newest, $middle], $this->ids($first));
        self::assertSame(['value' => 3, 'capped' => false], $first['total']);
        self::assertIsString($first['nextCursor']);
        $second = $this->list('status=paid&q=kowal&perPage=2&after='.rawurlencode($first['nextCursor']));
        self::assertSame([$oldest], $this->ids($second));
        self::assertNull($second['nextCursor']);
    }

    /**
     * @return iterable<string, array{string, string, list<string>}> the range, and the numbers of the orders in it
     */
    public static function days(): iterable
    {
        // Warsaw is UTC+1 in winter and UTC+2 in summer: the day of 29 March 2026 starts at 28 March 23:00 UTC and has
        // 23 hours, that of 25 October starts at 24 October 22:00 UTC and has 25.
        yield 'the day the clocks go forward' => ['placedFrom=2026-03-29&placedTo=2026-03-29', 'spring', ['R-100002', 'R-100003']];
        yield 'the day the clocks go back' => ['placedFrom=2026-10-25&placedTo=2026-10-25', 'autumn', ['R-100012', 'R-100013']];
        yield 'from a day, to the end of the list' => ['placedFrom=2026-03-29', 'spring', ['R-100002', 'R-100003', 'R-100004', 'R-100011', 'R-100012', 'R-100013', 'R-100014']];
        yield 'up to a day, from the start of the list' => ['placedTo=2026-03-29', 'spring', ['R-100001', 'R-100002', 'R-100003']];
        yield 'several days' => ['placedFrom=2026-03-28&placedTo=2026-03-30', 'spring', ['R-100001', 'R-100002', 'R-100003', 'R-100004']];
        yield 'a day with no orders' => ['placedFrom=2026-03-27&placedTo=2026-03-27', 'spring', []];
    }

    /**
     * @param list<string> $numbers
     */
    #[DataProvider('days')]
    public function testTheDaysAreDaysOfWarsawAlsoWhenTheClocksChange(string $query, string $season, array $numbers): void
    {
        // Each order has the moment around the borders of the days of the change (all UTC).
        foreach ([
            'R-100001' => '2026-03-28 22:59:59', 'R-100002' => '2026-03-28 23:00:00', 'R-100003' => '2026-03-29 21:59:59', 'R-100004' => '2026-03-29 22:00:00',
            'R-100011' => '2026-10-24 21:59:59', 'R-100012' => '2026-10-24 22:00:00', 'R-100013' => '2026-10-25 22:59:59', 'R-100014' => '2026-10-25 23:00:00',
        ] as $number => $at) {
            BackofficeOrderFixture::order($this->connection, 'paid', $at.'+00:00', $number);
        }

        $found = $this->numbers($this->list($query));
        sort($found);

        self::assertSame($numbers, $found, $season);
    }

    public function testTheRangeCombinesWithTheStatusTheSearchAndTheDateSort(): void
    {
        $first = $this->order('R-100001', '2026-11-03 10:00:00', 'jan@example.com', 'Jan Kowalski', 'paid');
        $this->order('R-100002', '2026-11-03 11:00:00', 'jan@example.com', 'Jan Kowalski', 'shipped');
        $this->order('R-100003', '2026-11-03 12:00:00', 'anna@example.com', 'Anna Nowak', 'paid');
        $second = $this->order('R-100004', '2026-11-04 12:00:00', 'jan@example.com', 'Jan Kowalski', 'paid');
        $this->order('R-100005', '2026-11-05 12:00:00', 'jan@example.com', 'Jan Kowalski', 'paid');
        $query = 'status=paid&q=kowalski&placedFrom=2026-11-03&placedTo=2026-11-04';

        self::assertSame([$second, $first], $this->ids($this->list($query)));
        self::assertSame([$first, $second], $this->ids($this->list($query.'&sort=placedAt')));
        self::assertSame(['value' => 2, 'capped' => false], $this->list($query)['total']);
        self::assertSame([$first], $this->ids($this->list('q=R-100001&placedFrom=2026-11-03')));
        self::assertSame([], $this->ids($this->list('q=R-100001&placedFrom=2026-11-04')));
    }

    public function testThePagesOfARangeVisitEveryOrderOnce(): void
    {
        $expected = [];
        foreach ([9, 10, 10, 11, 12] as $n => $hour) {
            $expected[] = BackofficeOrderFixture::order($this->connection, 'paid', \sprintf('2026-11-05 %02d:00:00+01:00', $hour), 'R-'.(100_001 + $n));
        }
        BackofficeOrderFixture::order($this->connection, 'paid', '2026-11-06 00:30:00+01:00', 'R-100099');
        $seen = [];
        $after = null;
        for ($page = 0; $page < 5; ++$page) {
            $list = $this->list('placedFrom=2026-11-05&placedTo=2026-11-05&perPage=2'.(null === $after ? '' : '&after='.rawurlencode($after)));
            $seen = [...$seen, ...$this->ids($list)];
            $after = \is_string($list['nextCursor']) ? $list['nextCursor'] : null;
            if (null === $after) {
                break;
            }
        }

        self::assertEqualsCanonicalizing($expected, $seen);
        self::assertCount(5, array_unique($seen));
    }

    /**
     * @return iterable<string, array{string, list<string>}> the query and the fields with a violation
     */
    public static function badRanges(): iterable
    {
        yield 'a start after the end' => ['placedFrom=2026-11-05&placedTo=2026-11-04', ['placedTo']];
        yield 'a start in the wrong format' => ['placedFrom=05.11.2026', ['placedFrom']];
        yield 'an end with a time' => ['placedTo=2026-11-05T10:00', ['placedTo']];
        yield 'a day that does not exist' => ['placedFrom=2026-02-30', ['placedFrom']];
        yield 'an empty start' => ['placedFrom=', ['placedFrom']];
        yield 'a start as an array' => ['placedFrom[]=2026-11-05', ['placedFrom']];
        yield 'a start with a NUL' => ['placedFrom=2026-11-0%00', ['placedFrom']];
        yield 'a start with Arabic digits' => ['placedFrom=%D9%A2%D9%A0%D9%A2%D9%A6-%D9%A1%D9%A1-%D9%A0%D9%A5', ['placedFrom']];
        yield 'both ends bad' => ['placedFrom=x&placedTo=y', ['placedFrom', 'placedTo']];
        yield 'the amount with the start of a range' => ['sort=-total&placedFrom=2026-11-01', ['sort']];
        yield 'the ascending amount with the end of a range' => ['sort=total&placedTo=2026-11-01', ['sort']];
        yield 'the pending refunds with the start of a range' => ['refund=pending&placedFrom=2026-11-01', ['placedFrom']];
        yield 'the pending refunds with both ends' => ['refund=pending&placedFrom=2026-11-01&placedTo=2026-11-02', ['placedFrom', 'placedTo']];
        yield 'the overdue refunds with the start of a range' => ['refund=overdue&placedFrom=2026-11-01', ['placedFrom']];
        yield 'the overdue refunds with both ends' => ['refund=overdue&placedFrom=2026-11-01&placedTo=2026-11-02', ['placedFrom', 'placedTo']];
        yield 'the overdue refunds with the end of a range' => ['refund=overdue&placedTo=2026-11-02', ['placedTo']];
        yield 'the amount with a search' => ['sort=-total&q=kowalski', ['sort']];
        yield 'the amount with a bad term and a range' => ['sort=total&q=ab&placedFrom=2026-11-01', ['q', 'sort']];
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('badRanges')]
    public function testARangeNoIndexServesOrNoDateIsA400AtTheFieldToChange(string $query, array $paths): void
    {
        $this->seed();

        $problem = $this->list($query);

        $reported = $this->violationPaths(400, $problem);
        sort($reported);
        sort($paths);
        self::assertSame($paths, $reported);
    }

    public function testTheMessagesOfTheRangeArePolish(): void
    {
        $problem = $this->list('placedFrom=2026-11-05&placedTo=2026-11-04');
        self::assertSame('Data „Do” nie może być wcześniejsza niż data „Od”.', $this->firstViolationTitle($problem));

        $problem = $this->list('placedFrom=jutro');
        self::assertSame('Podaj datę w formacie RRRR-MM-DD, od 2000 do 2999 roku.', $this->firstViolationTitle($problem));

        $problem = $this->list('sort=total&placedFrom=2026-11-01');
        self::assertSame('Sortowania po kwocie nie da się połączyć z zakresem dat ani z wyszukiwaniem.', $this->firstViolationTitle($problem));

        $problem = $this->list('refund=pending&placedTo=2026-11-01');
        self::assertSame('Zwroty do wykonania nie łączą się z zakresem dat.', $this->firstViolationTitle($problem));
    }

    public function testThePendingRefundsStillTakeASearch(): void
    {
        $wanted = BackofficeOrderFixture::order($this->connection, 'returned', new \DateTimeImmutable('-3 days')->format('Y-m-d H:i:sP'), 'R-100001', 'jan@example.com', fullName: 'Jan Kowalski');
        BackofficeOrderFixture::order($this->connection, 'returned', new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:sP'), 'R-100002', 'anna@example.com', fullName: 'Anna Nowak');

        self::assertSame([$wanted], $this->ids($this->list('refund=pending&q=kowal')));
    }

    private function seed(): void
    {
        $this->order('R-100123', '-9 days', 'anna.nowak@example.com', 'Anna Nowak');
        $this->order('R-100456', '-8 days', 'Jan@Gmail.com', 'Łukasz Żółć');
        $this->order('R-100789', '-7 days', 'zofia@wp.pl', 'Zofia Wiśniewska');
        $this->order('R-100901', '-6 days', 'rabat@example.com', 'Rabat 50% Kowalczyk');
        $this->order('R-100902', '-5 days', 'x@example.com', 'Ola a_b');
        $this->order('R-100903', '-4 days', 'y@example.com', 'Ola c\\d');
    }

    private function order(string $number, string $placedAt, string $email, string $fullName, string $status = 'paid'): string
    {
        /** @var 'new'|'paid'|'shipped'|'delivered'|'cancelled'|'cancelled_by_staff'|'returned' $status */
        return BackofficeOrderFixture::order($this->connection, $status, new \DateTimeImmutable($placedAt)->format('Y-m-d H:i:sP'), $number, $email, fullName: $fullName);
    }

    /**
     * @param array<mixed> $problem
     */
    private function firstViolationTitle(array $problem): ?string
    {
        self::assertIsArray($problem['violations'] ?? null);
        $violation = $problem['violations'][0] ?? null;
        self::assertIsArray($violation);
        $title = $violation['title'] ?? null;

        return \is_string($title) ? $title : null;
    }

    private function idOf(string $number): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM ordering_order WHERE number = ?', [$number]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function list(string $query): array
    {
        return $this->getJson(self::PATH.('' === $query ? '' : '?'.$query), self::staff(['support']));
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<string>
     */
    private function ids(array $list): array
    {
        self::assertIsArray($list['items'] ?? null);

        return array_values(array_map(static function (mixed $item): string {
            \assert(\is_array($item) && \is_string($item['orderId']));

            return $item['orderId'];
        }, $list['items']));
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<string>
     */
    private function numbers(array $list): array
    {
        self::assertIsArray($list['items'] ?? null);

        return array_values(array_map(static function (mixed $item): string {
            \assert(\is_array($item) && \is_string($item['number']));

            return $item['number'];
        }, $list['items']));
    }
}
