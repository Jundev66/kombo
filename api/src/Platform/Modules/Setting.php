<?php

declare(strict_types=1);

namespace Platform\Modules;

/**
 * One of a module's settings.
 *
 * The default lives in the code, not the database: adding an option is a line
 * in the manifest's `settings()` — no migration, no rows to backfill — and
 * `tenant_settings` only stores what a tenant changed.
 *
 * That is what makes "customer X needs this to behave differently" cheap: add
 * an option whose default equals today's behaviour, and only X changes it.
 */
final readonly class Setting
{
    /**
     * @param  list<string>  $options
     * @param  list<string>  $rules
     */
    private function __construct(
        public SettingType $type,
        public mixed $default,
        public array $options = [],
        public array $rules = [],
    ) {}

    public static function bool(bool $default): self
    {
        return new self(SettingType::Bool, $default, rules: ['boolean']);
    }

    public static function int(int $default): self
    {
        return new self(SettingType::Int, $default, rules: ['integer']);
    }

    public static function text(string $default): self
    {
        return new self(SettingType::Text, $default, rules: ['string']);
    }

    /** In cents, like all money in the system. */
    public static function money(int $defaultCents): self
    {
        return new self(SettingType::Money, $defaultCents, rules: ['integer', 'min:0']);
    }

    /**
     * @param  list<string>  $options
     */
    public static function enum(array $options, string $default): self
    {
        return new self(
            SettingType::Enum,
            $default,
            options: $options,
            rules: ['string', 'in:'.implode(',', $options)],
        );
    }

    public function min(int $value): self
    {
        return new self($this->type, $this->default, $this->options, [...$this->rules, "min:{$value}"]);
    }

    public function max(int $value): self
    {
        return new self($this->type, $this->default, $this->options, [...$this->rules, "max:{$value}"]);
    }

    public function maxLength(int $value): self
    {
        return new self($this->type, $this->default, $this->options, [...$this->rules, "max:{$value}"]);
    }

    /** From how it was stored (always text) to how it is used. */
    public function cast(mixed $raw): mixed
    {
        return match ($this->type) {
            SettingType::Bool => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            SettingType::Int, SettingType::Money => (int) $raw,
            SettingType::Text, SettingType::Enum => (string) $raw,
        };
    }

    /** How it is written to `tenant_settings`, which only stores text. */
    public function serialize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
