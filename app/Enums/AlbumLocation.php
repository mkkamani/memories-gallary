<?php

namespace App\Enums;

enum AlbumLocation: string
{
    case Rajkot = 'Rajkot';
    case Ahmedabad = 'Ahmedabad';
    case Anniversaries = 'Anniversaries';

    public static function fromSlug(string $slug): ?self
    {
        return match (strtolower($slug)) {
            'rajkot' => self::Rajkot,
            'ahmedabad' => self::Ahmedabad,
            'anniversaries' => self::Anniversaries,
            default => null,
        };
    }

    public static function slugs(): array
    {
        return [
            'rajkot' => self::Rajkot,
            'ahmedabad' => self::Ahmedabad,
            'anniversaries' => self::Anniversaries,
        ];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
