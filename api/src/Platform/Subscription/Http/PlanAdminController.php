<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Platform\Modules\ModuleRegistry;
use Platform\Subscription\PlatformAudit;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los planes: qué se vende y por cuánto.
 *
 * Se cobra por **tamaño**, no por funcionalidad básica: ningún negocio se queda
 * sin saber cuánto vendió porque no le alcanza. Lo que separa un plan de otro
 * son los techos —cuántos usuarios, cuántos productos, cuántos pedidos— y los
 * módulos que aportan capacidades nuevas, como la caja o los canales.
 */
final class PlanAdminController
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(): JsonResponse
    {
        $modulesByPlan = DB::table('plan_modules')->get()->groupBy('plan_code');

        $planes = DB::table('plans')->orderBy('sort_order')->get();

        return response()->json([
            'data' => $planes->map(fn (object $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'priceCents' => $plan->price_cents,
                'trialDays' => $plan->trial_days,
                // `null` es ILIMITADO, nunca cero.
                'maxUsers' => $plan->max_users,
                'maxProducts' => $plan->max_products,
                'maxOrdersMonth' => $plan->max_orders_month,
                'modules' => ($modulesByPlan[$plan->code] ?? collect())->pluck('module_code')->values()->all(),
                'tenants' => DB::table('tenants')->where('plan_code', $plan->code)->whereNull('deleted_at')->count(),
            ])->all(),

            // Los módulos que EXISTEN, para poder armar un plan sin
            // acordárselos de memoria. Salen del registro, no de una lista
            // escrita a mano que se quedaría vieja.
            'meta' => ['availableModules' => array_keys($this->modules->all())],
        ]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],

            // `null` es ilimitado. Se acepta explícitamente para poder QUITAR
            // un techo, no sólo subirlo.
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_products' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_orders_month' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string'],
        ]);

        if (! DB::table('plans')->where('code', $code)->exists()) {
            throw new NotFoundHttpException('Ese plan no existe.');
        }

        $modules = $data['modules'] ?? null;
        unset($data['modules']);

        DB::transaction(function () use ($code, $data, $modules): void {
            if ($data !== []) {
                DB::table('plans')->where('code', $code)->update([...$data, 'updated_at' => now()]);
            }

            if ($modules !== null) {
                DB::table('plan_modules')->where('plan_code', $code)->delete();

                foreach (array_unique($modules) as $module) {
                    DB::table('plan_modules')->insert(['plan_code' => $code, 'module_code' => $module]);
                }
            }
        });

        /*
         * Cambiar un plan NO enciende ni apaga módulos a quien ya lo tiene.
         *
         * `tenant_modules` es lo que manda en cada negocio, y el plan es el
         * techo. Apagarle un módulo a un cliente que lleva meses usándolo
         * porque alguien editó el plan sería quitarle la caja en mitad del
         * almuerzo.
         */
        $this->audit->record('plan.updated', null, ['plan' => $code]);

        return response()->json(['data' => ['code' => $code]]);
    }
}
