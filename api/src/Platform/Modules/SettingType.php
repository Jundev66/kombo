<?php

declare(strict_types=1);

namespace Platform\Modules;

/**
 * What kind of setting an option is.
 *
 * It exists so the dashboard can generate the form itself: a boolean is a
 * switch, a `Money` a thousands-separated field, an `Enum` a dropdown.
 */
enum SettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Text = 'text';
    case Money = 'money';
    case Enum = 'enum';
}
