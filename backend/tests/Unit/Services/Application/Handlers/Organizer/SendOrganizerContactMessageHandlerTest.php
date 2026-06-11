<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Organizer;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Mail\Organizer\OrganizerContactEmail;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\SendOrganizerContactMessageDTO;
use HiEvents\Services\Application\Handlers\Organizer\SendOrganizerContactMessageHandler;
use HiEvents\Services\Infrastructure\Authorization\PublicEventAccessService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Mail\Mailer;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class SendOrganizerContactMessageHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_non_live_organizer_contact_requires_current_manage_permission(): void
    {
        [$handler, $mailer, $organizerRepository, $purifier, $publicEventAccessService] = $this->handler();
        $organizer = $this->organizer(OrganizerStatus::DRAFT);

        $organizerRepository
            ->shouldReceive('findById')
            ->once()
            ->with(22)
            ->andReturn($organizer);

        $publicEventAccessService
            ->shouldReceive('canAccessOrganizer')
            ->once()
            ->with($organizer, Permission::ORGANIZER_MANAGE)
            ->andReturn(false);

        $purifier->shouldNotReceive('purify');
        $mailer->shouldNotReceive('to');

        $this->expectException(ResourceNotFoundException::class);

        $handler->handle($this->dto());
    }

    public function test_non_live_organizer_contact_sends_when_current_user_has_manage_permission(): void
    {
        [$handler, $mailer, $organizerRepository, $purifier, $publicEventAccessService] = $this->handler();
        $organizer = $this->organizer(OrganizerStatus::DRAFT);

        $organizerRepository
            ->shouldReceive('findById')
            ->once()
            ->with(22)
            ->andReturn($organizer);

        $publicEventAccessService
            ->shouldReceive('canAccessOrganizer')
            ->once()
            ->with($organizer, Permission::ORGANIZER_MANAGE)
            ->andReturn(true);

        $purifier
            ->shouldReceive('purify')
            ->once()
            ->with('hello <script>bad()</script>')
            ->andReturn('hello');

        $mailer
            ->shouldReceive('to')
            ->once()
            ->with('organizer@example.test', 'Kampy')
            ->andReturnSelf();

        $mailer
            ->shouldReceive('send')
            ->once()
            ->with(m::type(OrganizerContactEmail::class));

        $handler->handle($this->dto());

        $this->assertTrue(true, 'Non-live organizer contact was allowed only after manage permission was granted.');
    }

    public function test_live_organizer_contact_does_not_require_internal_permission(): void
    {
        [$handler, $mailer, $organizerRepository, $purifier, $publicEventAccessService] = $this->handler();
        $organizer = $this->organizer(OrganizerStatus::LIVE);

        $organizerRepository
            ->shouldReceive('findById')
            ->once()
            ->with(22)
            ->andReturn($organizer);

        $publicEventAccessService->shouldNotReceive('canAccessOrganizer');

        $purifier
            ->shouldReceive('purify')
            ->once()
            ->with('hello <script>bad()</script>')
            ->andReturn('hello');

        $mailer
            ->shouldReceive('to')
            ->once()
            ->with('organizer@example.test', 'Kampy')
            ->andReturnSelf();

        $mailer
            ->shouldReceive('send')
            ->once()
            ->with(m::type(OrganizerContactEmail::class));

        $handler->handle($this->dto());

        $this->assertTrue(true, 'Live organizer contact did not require internal account permission.');
    }

    private function handler(): array
    {
        $mailer = m::mock(Mailer::class);
        $organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $purifier = m::mock(HtmlPurifierService::class);
        $publicEventAccessService = m::mock(PublicEventAccessService::class);

        return [
            new SendOrganizerContactMessageHandler(
                $mailer,
                $organizerRepository,
                $purifier,
                $publicEventAccessService,
            ),
            $mailer,
            $organizerRepository,
            $purifier,
            $publicEventAccessService,
        ];
    }

    private function organizer(OrganizerStatus $status): OrganizerDomainObject
    {
        return (new OrganizerDomainObject())
            ->setId(22)
            ->setAccountId(123)
            ->setName('Kampy')
            ->setEmail('organizer@example.test')
            ->setTimezone('America/Phoenix')
            ->setStatus($status->value)
            ->setCreatedAt('2026-06-01 00:00:00')
            ->setUpdatedAt('2026-06-01 00:00:00');
    }

    private function dto(): SendOrganizerContactMessageDTO
    {
        return SendOrganizerContactMessageDTO::from([
            'organizer_id' => 22,
            'name' => 'Sender',
            'email' => 'sender@example.test',
            'message' => 'hello <script>bad()</script>',
        ]);
    }
}
