<?php

namespace App\Gateway;

class Signature
{
    public static function header(string $body, int $timestamp, string $secret): string
    {
        return "t={$timestamp},v1=".self::hash($body, $timestamp, $secret);
    }

    public static function hash(string $body, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
