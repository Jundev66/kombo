<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use App\Models\Platform\PlatformUser;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

/**
 * Crear (o recuperar) al administrador de la plataforma.
 *
 * Es la ÚNICA vía en producción. El sembrador crea `admin@kombo.test` con una
 * contraseña que está escrita en este repositorio; en un servidor público eso
 * es una puerta con la llave puesta, así que allí no se siembra: se crea aquí.
 *
 * La contraseña se pide por teclado y no se pasa como argumento a propósito. Un
 * argumento se queda en el historial de la terminal, sale en `ps` mientras el
 * comando corre, y acaba pegado en el chat donde se coordinó el despliegue.
 *
 *     php artisan plataforma:admin tu@correo.com
 *
 * Sirve también para recuperar el acceso: si ya existe ese correo, cambia la
 * contraseña y reactiva la cuenta.
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'plataforma:admin {email} {--nombre=Administración}';

    protected $description = 'Crea o actualiza un administrador de la super administración';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("«{$email}» no es un correo válido.");

            return self::FAILURE;
        }

        $clave = $this->pedirClave();

        if ($clave === null) {
            return self::FAILURE;
        }

        $existente = PlatformUser::where('email', $email)->first();

        PlatformUser::updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $this->option('nombre'),
                // En crudo: el modelo tiene el atributo casteado a `hashed`, y
                // pasarlo ya cifrado lo cifraría dos veces. El síntoma es una
                // cuenta que se crea sin queja y con la que no se puede entrar.
                'password' => $clave,
                // Reactivar es parte del trabajo: la razón más común para
                // volver a correr esto es haber perdido el acceso.
                'is_active' => true,
            ],
        );

        $this->info($existente !== null
            ? "Contraseña actualizada para {$email}."
            : "Administrador {$email} creado.");

        $this->line('Entra en https://'.config('kombo.admin_host'));

        return self::SUCCESS;
    }

    private function pedirClave(): ?string
    {
        // Sin terminal interactiva —un despliegue automatizado, por ejemplo—
        // se acepta por variable de entorno. Es la única concesión, y va
        // documentada en `docs/despliegue.md`.
        $delEntorno = getenv('KOMBO_ADMIN_PASSWORD');

        if (is_string($delEntorno) && $delEntorno !== '') {
            return $this->validar($delEntorno);
        }

        $clave = password('Contraseña', required: true);
        $repetida = password('Repítela', required: true);

        if ($clave !== $repetida) {
            $this->error('Las dos contraseñas no coinciden.');

            return null;
        }

        return $this->validar($clave);
    }

    private function validar(string $clave): ?string
    {
        // Doce, y no ocho como para el personal de un negocio: esta cuenta ve
        // los datos de TODOS los clientes y su formulario de entrada está en
        // una dirección pública.
        if (mb_strlen($clave) < 12) {
            $this->error('Muy corta: mínimo 12 caracteres.');

            return null;
        }

        return $clave;
    }
}
