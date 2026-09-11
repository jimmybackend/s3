<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class UserIdentityPresenter
{
    public static function alias(string $identifier): string
    {
        $local = self::localPart($identifier);
        return '@' . ($local !== '' ? $local : 'usuario');
    }

    public static function initials(string $identifier): string
    {
        $local = self::localPart($identifier);
        if ($local === '') {
  return 'U';
        }

        $normalized = preg_replace('/[._\-]+/u', ' ', $local) ?? $local;
        $parts = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) >= 2) {
  return self::upper(self::first($parts[0]) . self::first($parts[count($parts) - 1]));
        }

        return self::upper(self::first($local));
    }

    private static function localPart(string $identifier): string
    {
        $value = trim($identifier);
        if ($value === '') {
  return '';
        }

        if (str_starts_with($value, '@')) {
  $value = ltrim($value, '@');
        }

        $at = strpos($value, '@');
        if ($at !== false) {
  $value = substr($value, 0, $at);
        }

        return trim($value);
    }

    private static function first(string $value): string
    {
        if ($value === '') {
  return '';
        }

        return function_exists('mb_substr')
  ? (string) mb_substr($value, 0, 1, 'UTF-8')
  : substr($value, 0, 1);
    }

    private static function upper(string $value): string
    {
        return function_exists('mb_strtoupper')
  ? (string) mb_strtoupper($value, 'UTF-8')
  : strtoupper($value);
    }
}
