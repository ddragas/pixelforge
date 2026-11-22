<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeOperator extends Command
{
    protected $signature = 'app:make-operator {email}';

    protected $description = 'Let a user see the operators\' report';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that e-mail address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_operator' => true])->save();
        $this->info("{$user->email} can now open /admin/report.");

        return self::SUCCESS;
    }
}
