<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DateFormatter
{
    private const MONTHS = [
        1 => 'janvier',
        2 => 'février',
        3 => 'mars',
        4 => 'avril',
        5 => 'mai',
        6 => 'juin',
        7 => 'juillet',
        8 => 'août',
        9 => 'septembre',
        10 => 'octobre',
        11 => 'novembre',
        12 => 'décembre',
    ];

    public static function human(?string $value, bool $withTime = true): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '—';
        }

        try {
            // SQLite CURRENT_TIMESTAMP est stocké en UTC.
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $date = $date->setTimezone(new DateTimeZone('Africa/Casablanca'));
        } catch (Throwable) {
            return $value;
        }

        $text = (int)$date->format('j') . ' ' . self::MONTHS[(int)$date->format('n')] . ' ' . $date->format('Y');

        if ($withTime) {
            $text .= ' à ' . $date->format('H:i');
        }

        return $text;
    }

    public static function timestamp(int $timestamp, bool $withTime = true): string
    {
        try {
            $date = (new DateTimeImmutable('@' . $timestamp))
                ->setTimezone(new DateTimeZone('Africa/Casablanca'));
        } catch (Throwable) {
            return '—';
        }

        $text = (int)$date->format('j') . ' ' . self::MONTHS[(int)$date->format('n')] . ' ' . $date->format('Y');

        if ($withTime) {
            $text .= ' à ' . $date->format('H:i');
        }

        return $text;
    }
}
