<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\User;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\CannotUpdateResourceException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\User\DTO\UpdateUserDTO;
use HiEvents\Services\Application\Handlers\User\UpdateUserHandler;
use HiEvents\Services\Domain\Account\AccountUserEventAssignmentService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class UpdateUserHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_refuses_to_update_ambiguous_account_user_rows(): void
    {
        $userRepository = m::mock(UserRepositoryInterface::class);
        $accountUserRepository = m::mock(AccountUserRepositoryInterface::class);
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $handler = $this->handler($userRepository, $accountUserRepository, $assignmentService);

        $accountUserRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([
                'user_id' => 55,
                'account_id' => 123,
            ])
            ->andReturn(new Collection([
                $this->accountUser(1, Role::ADMIN),
                $this->accountUser(2, Role::CHECK_IN),
            ]));

        $userRepository->shouldNotReceive('updateWhere');
        $accountUserRepository->shouldNotReceive('updateWhere');
        $assignmentService->shouldNotReceive('replaceAssignmentsForRole');

        $this->expectException(CannotUpdateResourceException::class);

        $handler->handle($this->dto());
    }

    public function test_updates_single_account_user_row_by_id(): void
    {
        $userRepository = m::mock(UserRepositoryInterface::class);
        $accountUserRepository = m::mock(AccountUserRepositoryInterface::class);
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $handler = $this->handler($userRepository, $accountUserRepository, $assignmentService);

        $accountUserRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([
                'user_id' => 55,
                'account_id' => 123,
            ])
            ->andReturn(new Collection([
                $this->accountUser(77, Role::REPORTING),
            ]));

        $userRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with([
                'first_name' => 'Updated',
                'last_name' => 'User',
            ], [
                'id' => 55,
            ]);

        $accountUserRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with([
                'role' => Role::FINANCE->name,
                'status' => UserStatus::ACTIVE->name,
            ], [
                'id' => 77,
            ]);

        $assignmentService
            ->shouldReceive('replaceAssignmentsForRole')
            ->once()
            ->with(m::on(fn(AccountUserDomainObject $accountUser) => $accountUser->getId() === 77), Role::FINANCE, [], 123, 10)
            ->andReturn(new Collection());

        $expectedUser = $this->user();
        $userRepository
            ->shouldReceive('findByIdAndAccountId')
            ->once()
            ->with(55, 123)
            ->andReturn($expectedUser);

        $this->assertSame($expectedUser, $handler->handle($this->dto()));
    }

    private function handler(
        UserRepositoryInterface $userRepository,
        AccountUserRepositoryInterface $accountUserRepository,
        AccountUserEventAssignmentService $assignmentService,
    ): UpdateUserHandler
    {
        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn($callback) => $callback());

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->zeroOrMoreTimes();

        return new UpdateUserHandler(
            $userRepository,
            $logger,
            $accountUserRepository,
            $assignmentService,
            $databaseManager,
        );
    }

    private function dto(): UpdateUserDTO
    {
        return UpdateUserDTO::fromArray([
            'id' => 55,
            'account_id' => 123,
            'first_name' => 'Updated',
            'last_name' => 'User',
            'role' => Role::FINANCE,
            'status' => UserStatus::ACTIVE,
            'updated_by_user_id' => 10,
        ]);
    }

    private function accountUser(int $id, Role $role): AccountUserDomainObject
    {
        return (new AccountUserDomainObject())
            ->setId($id)
            ->setUserId(55)
            ->setAccountId(123)
            ->setRole($role->name)
            ->setStatus(UserStatus::ACTIVE->name)
            ->setIsAccountOwner(false);
    }

    private function user(): UserDomainObject
    {
        return (new UserDomainObject())
            ->setId(55)
            ->setEmail('updated@example.test')
            ->setFirstName('Updated')
            ->setLastName('User')
            ->setPassword('hashed')
            ->setTimezone('America/Phoenix')
            ->setLocale('en');
    }
}
