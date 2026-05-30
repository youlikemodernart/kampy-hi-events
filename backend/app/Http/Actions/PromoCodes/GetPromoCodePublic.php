<?php

namespace HiEvents\Http\Actions\PromoCodes;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\PromoCodeDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetPromoCodePublic extends BaseAction
{
    public function __construct(
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly EventRepositoryInterface     $eventRepository,
    )
    {
    }

    public function __invoke(int $eventId, string $promoCode, Request $request): JsonResponse
    {
        $event = $this->eventRepository->findFirstWhere([
            'id' => $eventId,
        ]);
        if (!$event || !$this->canUserViewEvent($event)) {
            return $this->jsonResponse([
                'valid' => false,
            ]);
        }

        // intentionally not returning a 404
        $promoCode = $this->promoCodeRepository->findFirstWhere([
            PromoCodeDomainObjectAbstract::CODE => strtolower(trim($promoCode)),
            PromoCodeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        return $this->jsonResponse([
            'valid' => $promoCode !== null && $promoCode->isValid(),
        ]);
    }

    private function canUserViewEvent(EventDomainObject $event): bool
    {
        if ($event->getStatus() === EventStatus::LIVE->name) {
            return true;
        }

        if ($this->isUserAuthenticated() && $event->getAccountId() === $this->getAuthenticatedAccountId()) {
            return true;
        }

        $authenticatedUserRole = $this->isUserAuthenticated() ? $this->getAuthenticatedUserRole() : null;

        return $authenticatedUserRole === Role::SUPERADMIN
            || $authenticatedUserRole === Role::SUPERADMIN->value;
    }
}
