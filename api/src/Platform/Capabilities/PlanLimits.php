<?php

declare(strict_types=1);

namespace Platform\Capabilities;

/**
 * Los techos del plan.
 *
 * **`null` significa ILIMITADO, nunca cero.** Cero sería «ninguno», que es una
 * respuesta distinta y mucho peor de depurar: un plan con `max_users = 0`
 * dejaría al dueño fuera de su propio negocio, y el mensaje de error diría
 * «alcanzaste el límite de usuarios» sin que nadie entienda por qué.
 */
final readonly class PlanLimits
{
    public function __construct(
        public ?int $maxUsers = null,
        public ?int $maxProducts = null,
        public ?int $maxOrdersMonth = null,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $data = (array) $row;

        return new self(
            maxUsers: isset($data['max_users']) ? (int) $data['max_users'] : null,
            maxProducts: isset($data['max_products']) ? (int) $data['max_products'] : null,
            maxOrdersMonth: isset($data['max_orders_month']) ? (int) $data['max_orders_month'] : null,
        );
    }

    /**
     * ¿Añadir uno más pasaría del techo?
     *
     * Se pregunta con el conteo ACTUAL, antes de crear. `>=` y no `>` porque
     * con 8 de 8 usuarios el noveno ya no cabe.
     */
    public function exceeds(?int $limit, int $current): bool
    {
        return $limit !== null && $current >= $limit;
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'maxUsers' => $this->maxUsers,
            'maxProducts' => $this->maxProducts,
            'maxOrdersMonth' => $this->maxOrdersMonth,
        ];
    }
}
