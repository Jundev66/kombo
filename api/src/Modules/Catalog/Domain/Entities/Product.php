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
 * Something this tenant sells.
 *
 * Plain PHP — no Eloquent, no requests, no database; an architecture test
 * verifies it. The rules below have to hold identically from the till, the
 * portal, the bot and an Excel importer.
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
            // Stamped at birth so "how long since I reviewed this price" has an
            // answer from day one.
            priceUpdatedAt: $now ?? new DateTimeImmutable,
        );
    }

    /**
     * Rehydrates from the database without validating: blowing up on READ turns
     * stale data into a broken screen.
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
     * Changing the price.
     *
     * `price_updated_at` only moves if the price really changed. In a country
     * with inflation that date is what the owner scans for what has gone months
     * without adjustment, and dirtying it makes it useless.
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
     * Takes it off the menu without deleting it: old orders reference it and a
     * kitchen ticket from three months ago has to stay readable.
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
     * Can it be sold right now? Both reasons it cannot be — off the menu, or
     * sold out — so no screen has to remember to check both.
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
        // A MODIFIER may take money off ("no cheese", -0.50); a product may not.
        // A dish costing less than nothing is always a typo.
        if ($price->isNegative()) {
            throw new InvalidPrice('El precio no puede ser negativo.');
        }
    }
}
