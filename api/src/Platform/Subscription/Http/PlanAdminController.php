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
 * The plans: what is sold and for how much.
 *
 * Charged by SIZE, not by basic functionality. What separates one plan from
 * another are the ceilings — users, products, orders — and the modules that add
 * new capabilities, like the till or the channels.
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

        $plans = DB::table('plans')->orderBy('sort_order')->get();

        return response()->json([
            'data' => $plans->map(fn (object $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'priceCents' => $plan->price_cents,
                'trialDays' => $plan->trial_days,
                // `null` is UNLIMITED, never zero.
                'maxUsers' => $plan->max_users,
                'maxProducts' => $plan->max_products,
                'maxOrdersMonth' => $plan->max_orders_month,
                'modules' => ($modulesByPlan[$plan->code] ?? collect())->pluck('module_code')->values()->all(),
                'tenants' => DB::table('tenants')->where('plan_code', $plan->code)->whereNull('deleted_at')->count(),
            ])->all(),

            // The modules that EXIST, so a plan can be assembled without recalling
            // them from memory. Taken from the registry, not a hand-written list.
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

            // `null` is unlimited. Accepted explicitly so a ceiling can be REMOVED,
            // not only raised.
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
         * Changing a plan does NOT enable or disable modules for tenants that
         * already have it. `tenant_modules` rules per tenant; the plan is the
         * ceiling. Taking the till off a customer who has used it for months
         * because someone edited a plan would land mid-lunch.
         */
        $this->audit->record('plan.updated', null, ['plan' => $code]);

        return response()->json(['data' => ['code' => $code]]);
    }
}
