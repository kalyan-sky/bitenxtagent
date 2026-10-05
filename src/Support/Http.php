<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * One reusable curl handle per host for the current request. Reusing the
 * handle keeps the connection open, so Firestore, Magento and the AI provider
 * each cost one TLS handshake per message instead of one per call.
 */
final class Http
{
    /** @var array<string, \CurlHandle> */
    private static array $handles = [];

    public static function handle(string $url): \CurlHandle
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ch = self::$handles[$host] ??= curl_init();
        curl_reset($ch);
        curl_setopt($ch, CURLOPT_URL, $url);

        return $ch;
    }
}
