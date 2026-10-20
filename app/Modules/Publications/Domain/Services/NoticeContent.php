<?php

namespace App\Modules\Publications\Domain\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class NoticeContent
{
    public static function text(string $value, int $limit, bool $required = false): string
    {
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $limit || strip_tags($value) !== $value
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('NOTICE_TEXT_INVALID');
        }

        return $value;
    }

    public static function url(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('NOTICE_URL_INVALID');
        }

        return $url;
    }

    public static function instant(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new InvalidArgumentException('NOTICE_INSTANT_INVALID');
        }
        try {
            $instant = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidArgumentException('NOTICE_INSTANT_INVALID');
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new InvalidArgumentException('NOTICE_INSTANT_INVALID');
        }

        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function window(?string $start, ?string $end, bool $publishing = false): void
    {
        if (($publishing && $start === null) || ($end !== null && ($start === null || $end <= $start))) {
            throw new InvalidArgumentException('NOTICE_WINDOW_INVALID');
        }
    }
}
