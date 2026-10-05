<?php
/**
 * AH5 Office - compatibility shims for shared hosting
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Some cPanel accounts ship PHP without mbstring, or with PHP 7.x.
 * These fallbacks keep the app running there.
 */

declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen(preg_replace('/[\x80-\xBF]/', '', (string) $string));
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        $chars = preg_split('//u', (string) $string, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) {
        return strtolower((string) $string);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null) {
        return strtoupper((string) $string);
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
