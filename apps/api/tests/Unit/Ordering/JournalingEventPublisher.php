<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPublisher;

final class JournalingEventPublisher implements DomainEventPublisher
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function __construct(private readonly Journal $journal)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        $this->journal->record('publish');
        array_push($this->published, ...$events);
    }
}
