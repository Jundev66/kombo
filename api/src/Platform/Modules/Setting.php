<?php

declare(strict_types=1);

namespace Platform\Modules;

/**
 * Una opción de configuración de un módulo.
 *
 * **El valor por defecto vive en el código, no en la base.** Añadir una opción
 * es una línea en `settings()` del manifiesto: cero migraciones, cero filas
 * que rellenar, y cambiarlo para todos los negocios es editar esa línea.
 * `tenant_settings` sólo guarda lo que un negocio cambió.
 *
 * Ese detalle es el que hace barato el segundo escalón de la regla de oro:
 * ante «el cliente X necesita que esto se comporte distinto», se añade una
 * opción con el valor por defecto IGUAL al comportamiento de hoy. Todos la
 * reciben, nadie lo nota, y el cliente X la cambia.
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

    /** En centavos, como todo el dinero del sistema. */
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

    /**
     * De cómo se guardó (siempre texto) a cómo se usa.
     */
    public function cast(mixed $raw): mixed
    {
        return match ($this->type) {
            SettingType::Bool => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            SettingType::Int, SettingType::Money => (int) $raw,
            SettingType::Text, SettingType::Enum => (string) $raw,
        };
    }

    /** Cómo se escribe en `tenant_settings`, que sólo guarda texto. */
    public function serialize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
