<?php

/*
 * Only the components section is overridden; every other blade-icons option
 * keeps the package default (Laravel merges package config at the top level).
 *
 * The default "icon" class component is disabled because the public site
 * ships its own anonymous <x-icon> component (resources/views/components/icon.blade.php).
 * Filament renders its icons through svg() and is unaffected.
 */
return [
    'components' => [
        'disabled' => false,
        'default' => null,
    ],
];
