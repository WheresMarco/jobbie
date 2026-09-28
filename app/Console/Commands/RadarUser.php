<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\Translates;
use App\Models\User;
use Illuminate\Console\Command;

final class RadarUser extends Command
{
    use Translates;

    public const int MIN_PASSWORD_LENGTH = 8;

    protected $signature = 'radar:user
        {email : Email address to log in with}
        {--name= : Display name (default: the part of the address before @)}';

    protected $description = 'Create an account, or set a new password for an existing one';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error($this->t('radar.user.invalid_email', ['email' => $email]));

            return self::INVALID;
        }

        $password = (string) $this->secret($this->t('radar.user.password'));
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->components->error($this->t('radar.user.too_short', ['min' => self::MIN_PASSWORD_LENGTH]));

            return self::INVALID;
        }

        if ((string) $this->secret($this->t('radar.user.password_again')) !== $password) {
            $this->components->error($this->t('radar.user.mismatch'));

            return self::INVALID;
        }

        $user = User::query()->firstWhere('email', $email);
        $name = $this->option('name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        if ($user === null) {
            User::query()->create([
                'email' => $email,
                'name' => $name ?? strstr($email, '@', true),
                'password' => $password,
            ]);
            $this->components->info($this->t('radar.user.created', ['email' => $email]));

            return self::SUCCESS;
        }

        $user->password = $password;
        if ($name !== null) {
            $user->name = $name;
        }
        $user->save();
        $this->components->info($this->t('radar.user.updated', ['email' => $email]));

        return self::SUCCESS;
    }
}
