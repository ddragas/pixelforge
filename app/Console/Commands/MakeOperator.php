<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Console\Command;

class MakeOperator extends Command
{
    protected $signature = 'app:make-operator {email}';

    protected $description = 'Let a user see the operators\' report';

    public function handle(UserRepository $users): int
    {
        $user = $users->findByEmail((string) $this->argument('email'));

        if (! $user instanceof User) {
            $this->error('No user with that e-mail address.');

            return self::FAILURE;
        }

        $users->makeOperator($user);
        $this->info("{$user->email} can now open /admin/report.");

        return self::SUCCESS;
    }
}
