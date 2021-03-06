<?php

declare(strict_types=1);

namespace Tickets\Domain;

use Lcobucci\Clock\Clock;
use Marcosh\LamPHPda\Either;
use Marcosh\LamPHPda\Maybe;
use Tickets\Domain\Ticket\Status;
use Tickets\Domain\User\Admin;
use Tickets\Error\AnswerError;
use Tickets\Event\Event;
use Tickets\Event\NewAnswerArrived;
use Tickets\Event\TicketOpened;
use Tickets\Specification\UserCanAnswer;

final class Ticket
{
    /**
     * @var Id
     * @psalm-var Id<Ticket>
     */
    private $ticketId;

    /** @var \DateTimeImmutable */
    private $openedAt;

    /** @var \DateTimeImmutable */
    private $lastEditedAt;

    /** @var User */
    private $openedBy;

    /**
     * @var Message[]
     * @psalm-var non-empty-list<Message>
     */
    private $messages;

    /** @var Status */
    private $status;

    /**
     * @param Id $ticketId
     * @psalm-param Id<Ticket> $ticketId
     * @param \DateTimeImmutable $openedAt
     * @param \DateTimeImmutable $lastEditedAt
     * @param User $openedBy
     * @param Message[] $messages
     * @psalm-param non-empty-list<Message> $messages
     * @param Status $status
     * @psalm-pure
     */
    private function __construct(
        Id $ticketId,
        \DateTimeImmutable $openedAt,
        \DateTimeImmutable $lastEditedAt,
        User $openedBy,
        array $messages,
        Status $status
    ) {
        $this->ticketId = $ticketId;
        $this->openedAt = $openedAt;
        $this->lastEditedAt = $lastEditedAt;
        $this->openedBy = $openedBy;
        $this->messages = $messages;
        $this->status = $status;
    }

    /**
     * @return Id
     * @psalm-return Id<Ticket>
     * @psalm-pure
     */
    public function ticketId(): Id
    {
        return $this->ticketId;
    }

    /**
     * @return \DateTimeImmutable
     * @psalm-pure
     */
    public function openedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    /**
     * @return \DateTimeImmutable
     * @psalm-pure
     */
    public function lastEditedAt(): \DateTimeImmutable
    {
        return $this->lastEditedAt;
    }

    /**
     * @return User
     * @psalm-pure
     */
    public function openedBy(): User
    {
        return $this->openedBy;
    }

    /**
     * @return Maybe
     * @psalm-return Maybe<User<Admin>>
     * @psalm-pure
     */
    public function assignedTo(): Maybe
    {
        return $this->status->assignedTo();
    }

    /**
     * @return array
     * @psalm-return non-empty-list<Message>
     * @psalm-pure
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return Status
     * @psalm-pure
     */
    public function status(): Status
    {
        return $this->status;
    }

    /**
     * @return bool
     * @psalm-pure
     */
    public function isNew(): bool
    {
        return $this->status->isNew();
    }

    /**
     * @param User $user
     * @return bool
     * @psalm-pure
     */
    public function isAssignedTo(User $user): bool
    {
        return $this->assignedTo()->eval(
            false,
            fn(User $assignedUser) => $assignedUser->id() == $user->id()
        );
    }

    /**
     * @param User $user
     * @return bool
     * @psalm-pure
     */
    public function wasCreatedBy(User $user): bool
    {
        return $this->openedBy->id() == $user->id();
    }

    /**
     * @param Id $ticketId
     * @psalm-param Id<Ticket> $ticketId
     * @param Message $message
     * @param Clock $clock
     * @return Event[]
     */
    public static function open(
        Id $ticketId,
        Message $message,
        Clock $clock
    ): array {
        $openedAt = $clock->now();

        return [
            new TicketOpened(
                $ticketId,
                $message,
                $openedAt
            )
        ];
    }

    /**
     * @param TicketOpened $event
     * @return Ticket
     * @psalm-pure
     */
    public static function onTicketOpened(TicketOpened $event): self
    {
        return new self(
            $event->ticketId(),
            $event->openedAt(),
            $event->openedAt(),
            $event->user(),
            [
                $event->message()
            ],
            Status::new()
        );
    }

    /**
     * @param Message $message
     * @param Clock $clock
     * @return Either
     * @psalm-return Either<AnswerError, Event[]>
     */
    public function answer(
        Message $message,
        Clock $clock
    ): Either {
        $maybeError = (new UserCanAnswer())->isSatisfiedBy($this, $message->user());

        $event = new NewAnswerArrived(
            $this->ticketId,
            $message,
            $clock->now()
        );

        /** @psalm-var Either<AnswerError, Event[]> $successCase */
        $successCase = Either::right([$event]);

        return $maybeError->eval(
            $successCase,
            /**
             * @psalm-param AnswerError $error
             * @psalm-return Either<AnswerError, Event[]>
             */
            fn($error) => Either::left($error)
        );
    }

    /**
     * @param NewAnswerArrived $event
     * @return Ticket
     * @psalm-pure
     */
    public function onNewAnswerArrived(NewAnswerArrived $event): self
    {
        return new self(
            $this->ticketId,
            $this->openedAt,
            $event->answeredAt(),
            $this->openedBy,
            array_merge($this->messages, [$event->message()]),
            $event->user()->isAdmin() ? $this->status->adminAnswered($event->user()) : $this->status
        );
    }
}
