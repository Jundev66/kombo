<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Entities;

use DateTimeImmutable;
use Modules\Catalog\Domain\Exceptions\InvalidPrice;
use Modules\Catalog\Domain\ValueObjects\PrepTime;
use Modules\Catalog\Domain\ValueObjects\ProductName;
use Modules\Catalog\Domain\ValueObjects\StockPolicy;
use Shared\Domain\ValueObjects\Money;

/**
 * Algo que este negocio vende.
 *
 * PHP puro: ni Eloquent, ni peticiones, ni base de datos. Una prueba de
 * arquitectura lo verifica. La razón práctica es que las reglas de abajo —qué
 * precio es válido, cuándo se considera que cambió, si hay existencias— tienen
 * que valer igual llamadas desde la caja, desde el portal, desde el bot y
 * desde un importador de Excel. Metidas en un modelo, valen sólo donde alguien
 * se acordó de llamarlas.
 */
final class Product
{
    private function __construct(
        public readonly string $id,
        private ProductName $name,
        private Money $price,
        private StockPolicy $stock,
        private PrepTime $prepTime,
        private ?string $categoryId,
        private ?string $description,
        private ?string $photoUrl,
        private bool $active,
        private ?DateTimeImmutable $priceUpdatedAt,
    ) {}

    public static function create(
        string $id,
        string $name,
        Money $price,
        ?string $categoryId = null,
        ?string $description = null,
        ?string $photoUrl = null,
        ?StockPolicy $stock = null,
        ?PrepTime $prepTime = null,
        ?DateTimeImmutable $now = null,
    ): self {
        self::assertSellablePrice($price);

        return new self(
            id: $id,
            name: ProductName::of($name),
            price: $price,
            stock: $stock ?? StockPolicy::untracked(),
            prepTime: $prepTime ?? PrepTime::none(),
            categoryId: $categoryId,
            description: $description,
            photoUrl: $photoUrl,
            active: true,
            // Se sella al nacer para que «desde cuándo no reviso este precio»
            // tenga respuesta desde el primer día.
            priceUpdatedAt: $now ?? new DateTimeImmutable,
        );
    }

    /**
     * Rehidrata desde la base. No valida: lo que ya está guardado, está
     * guardado, y reventar al LEER convierte un dato viejo en una pantalla
     * caída.
     */
    public static function rehydrate(
        string $id,
        ProductName $name,
        Money $price,
        StockPolicy $stock,
        PrepTime $prepTime,
        ?string $categoryId,
        ?string $description,
        ?string $photoUrl,
        bool $active,
        ?DateTimeImmutable $priceUpdatedAt,
    ): self {
        return new self($id, $name, $price, $stock, $prepTime, $categoryId, $description, $photoUrl, $active, $priceUpdatedAt);
    }

    /**
     * Cambiar el precio.
     *
     * **`price_updated_at` sólo se mueve si el precio cambió de verdad.**
     * Guardar el formulario sin tocar el precio no puede hacer parecer que se
     * revisó: en un país con inflación, esa fecha es justo lo que el dueño mira
     * para saber qué lleva meses sin ajustar, y ensuciarla la vuelve inútil.
     */
    public function changePriceTo(Money $newPrice, ?DateTimeImmutable $now = null): void
    {
        self::assertSellablePrice($newPrice);

        if ($this->price->equals($newPrice)) {
            return;
        }

        $this->price = $newPrice;
        $this->priceUpdatedAt = $now ?? new DateTimeImmutable;
    }

    public function rename(string $name): void
    {
        $this->name = ProductName::of($name);
    }

    public function describeAs(?string $description): void
    {
        $this->description = $description;
    }

    public function useCategory(?string $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function usePhoto(?string $photoUrl): void
    {
        $this->photoUrl = $photoUrl;
    }

    public function takesToPrepare(PrepTime $prepTime): void
    {
        $this->prepTime = $prepTime;
    }

    public function useStockPolicy(StockPolicy $stock): void
    {
        $this->stock = $stock;
    }

    /**
     * Sacarlo de la carta sin borrarlo.
     *
     * Nunca se borra un producto que ya se vendió: los pedidos viejos lo
     * referencian y una comanda de hace tres meses tiene que poder leerse.
     */
    public function deactivate(): void
    {
        $this->active = false;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    /**
     * ¿Se le puede vender a alguien ahora mismo?
     *
     * Junta las dos razones por las que no se puede —lo sacaron de la carta, o
     * se acabó— para que ninguna pantalla tenga que acordarse de comprobar las
     * dos por su cuenta.
     */
    public function isSellable(int $quantity = 1): bool
    {
        return $this->active && $this->stock->allows($quantity);
    }

    public function name(): ProductName
    {
        return $this->name;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function stock(): StockPolicy
    {
        return $this->stock;
    }

    public function prepTime(): PrepTime
    {
        return $this->prepTime;
    }

    public function categoryId(): ?string
    {
        return $this->categoryId;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function photoUrl(): ?string
    {
        return $this->photoUrl;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function priceUpdatedAt(): ?DateTimeImmutable
    {
        return $this->priceUpdatedAt;
    }

    private static function assertSellablePrice(Money $price): void
    {
        // Un MODIFICADOR sí puede descontar («sin queso», -0,50). Un producto
        // no: un plato que cuesta menos que nada es siempre un error de
        // tecleo, y descubrirlo al cuadrar la caja es tarde.
        if ($price->isNegative()) {
            throw new InvalidPrice('El precio no puede ser negativo.');
        }
    }
}
