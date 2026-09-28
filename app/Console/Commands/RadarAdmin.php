<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Models\User;
use Illuminate\Console\Command;

final class RadarAdmin extends Command
{
    use Translates;

    protected $signature = 'radar:admin
        {email : The account\'s email address}
        {--revoke : Take admin rights away instead}';

    protected $description = 'Give an account admin rights (statistics and Horizon), or take them away';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->firstWhere('email', $email);

        if ($user === null) {
            $this->components->error($this->t('radar.admin.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $user->is_admin = ! $this->option('revoke');
        $user->save();

        $this->components->info($this->t($user->is_admin ? 'radar.admin.granted' : 'radar.admin.revoked', ['email' => $email]));

        return self::SUCCESS;
    }
}
