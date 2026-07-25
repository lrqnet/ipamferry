<?php

namespace App\Enums;

enum SupportedLocale: string
{
    case English = 'en';
    case PortugueseBrazil = 'pt_BR';
    case Spanish = 'es';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string,html:string,intl:string}> */
    public static function options(): array
    {
        return array_map(fn (self $locale) => [
            'value' => $locale->value,
            'label' => $locale->label(),
            'html' => $locale->html(),
            'intl' => $locale->intl(),
        ], self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::English => 'English', self::PortugueseBrazil => 'Português (Brasil)', self::Spanish => 'Español'
        };
    }

    public function html(): string
    {
        return match ($this) {
            self::English => 'en', self::PortugueseBrazil => 'pt-BR', self::Spanish => 'es'
        };
    }

    public function intl(): string
    {
        return match ($this) {
            self::English => 'en-US', self::PortugueseBrazil => 'pt-BR', self::Spanish => 'es-419'
        };
    }
}
