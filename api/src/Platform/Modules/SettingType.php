<?php

declare(strict_types=1);

namespace Platform\Modules;

/**
 * De qué tipo es una opción de configuración.
 *
 * Existe para que **el panel genere el formulario solo**. Nadie escribe a mano
 * la pantalla de ajustes de un módulo: el manifiesto declara las opciones con
 * su tipo, y la interfaz sabe que un booleano es un interruptor, un
 * `Money` un campo con separador de miles, y un `Enum` un desplegable.
 */
enum SettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Text = 'text';
    case Money = 'money';
    case Enum = 'enum';
}
