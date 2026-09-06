<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use App\Models\Platform\PlatformUser;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

/**
 * Creates (or recovers) the platform administrator.
 *
 *     php artisan platform:admin you@example.com
 *
 * The ONLY route in production: the seeder creates `admin@kombo.test` with a
 * password published in this repository. The password is prompted for rather
 * than passed as an argument — arguments survive in shell history, show up in
 * `ps`, and end up pasted into the deployment chat.
 *
 * It also recovers access: an existing email gets a new password and is
 * reactivated.
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:admin {email} {--name=Administración}';

    protected $description = 'Crea o actualiza un administrador de la super administración';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("«{$email}» no es un correo válido.");

            return self::FAILURE;
        }

        $password = $this->requestDeviceToken();

        if ($password === null) {
            return self::FAILURE;
        }

        $existing = PlatformUser::where('email', $email)->first();

        PlatformUser::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $this->option('name'),
                // Raw: the model casts the attribute as `hashed`, so passing it already
                // hashed would hash it twice. The symptom is an account that is created
                // without complaint and cannot be signed into.
                'password' => $password,
                // Reactivating is part of the job: the usual reason to run this again is
                // having lost access.
                'is_active' => true,
            ],
        );

        $this->info($existing !== null
            ? "Contraseña actualizada para {$email}."
            : "Administrador {$email} creado.");

        $this->line('Entra en https://'.config('kombo.admin_host'));

        return self::SUCCESS;
    }

    private function requestDeviceToken(): ?string
    {
        // With no interactive terminal — an automated deployment — it is accepted
        // from an environment variable. The one concession, documented in
        // `docs/despliegue.md`.
        $fromEnv = getenv('KOMBO_ADMIN_PASSWORD');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $this->validate($fromEnv);
        }

        $password = password('Contraseña', required: true);
        $repeated = password('Repítela', required: true);

        if ($password !== $repeated) {
            $this->error('Las dos contraseñas no coinciden.');

            return null;
        }

        return $this->validate($password);
    }

    private function validate(string $password): ?string
    {
        // Twelve, not eight as for shop staff: this account sees EVERY customer's
        // data and its sign-in form is at a public address.
        if (mb_strlen($password) < 12) {
            $this->error('Muy corta: mínimo 12 caracteres.');

            return null;
        }

        return $password;
    }
}
