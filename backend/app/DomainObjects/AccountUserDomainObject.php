<?php

namespace HiEvents\DomainObjects;

use Illuminate\Support\Collection;

class AccountUserDomainObject extends Generated\AccountUserDomainObjectAbstract
{
    public ?AccountDomainObject $account = null;

    /**
     * @var Collection<int, AccountUserEventAssignmentDomainObject>|null
     */
    private ?Collection $eventAssignments = null;

    public function getAccount(): ?AccountDomainObject
    {
        return $this->account;
    }

    public function setAccount(?AccountDomainObject $account): static
    {
        $this->account = $account;

        return $this;
    }

    /**
     * @return Collection<int, AccountUserEventAssignmentDomainObject>|null
     */
    public function getEventAssignments(): ?Collection
    {
        return $this->eventAssignments;
    }

    /**
     * @param Collection<int, AccountUserEventAssignmentDomainObject>|null $eventAssignments
     */
    public function setEventAssignments(?Collection $eventAssignments): static
    {
        $this->eventAssignments = $eventAssignments;

        return $this;
    }
}
