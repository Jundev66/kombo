<?php

declare(strict_types=1);

namespace Platform\Modules;

/**
 * What a module is.
 *
 * A module is a directory PLUS this manifest, and everything follows: enabling
 * one is a row in `tenant_modules` with nothing to deploy; its permissions
 * exist only while it does; the dashboard generates its settings form from the
 * `Setting` types; and its routes are loaded under `module:{code}`, so
 * `routes/api.php` is never touched to add a module.
 */
abstract class ModuleManifest
{
    /** Stable identifier. Used in `tenant_modules` and in `module:{code}`. */
    abstract public function code(): string;

    /** How it reads in the owner's menu. In Spanish, no jargon. */
    abstract public function name(): string;

    /**
     * In counter language, not programmer language: "Cobrar en el local y
     * entregar una nota", not "POS module".
     */
    public function description(): string
    {
        return '';
    }

    /**
     * Which other modules it depends on, BY CODE.
     *
     * By code rather than by importing their class: a module that names
     * another's class can no longer be deleted without breaking boot.
     *
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * The permissions this module contributes.
     *
     * Convention for the PIN authorization flow: `orders.void` executes,
     * `orders.void_request` starts. They are not the same permission and nobody
     * holds both.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [];
    }

    /** Path to this module's route file, if it has one. */
    public function routes(): ?string
    {
        return null;
    }

    /** Its own migrations directory, if it has one. */
    public function migrations(): ?string
    {
        return null;
    }

    /**
     * Core: every tenant always has it, it does not depend on the plan and it
     * cannot be switched off.
     */
    public function isCore(): bool
    {
        return false;
    }
}
