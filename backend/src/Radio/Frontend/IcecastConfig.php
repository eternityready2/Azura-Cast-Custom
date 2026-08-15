<?php

declare(strict_types=1);

namespace App\Radio\Frontend;

final class IcecastConfig
{
    private const string PUBLIC_SOCKET_ID = 'public';
    private const string PROXY_SOCKET_ID = 'azuracast-proxy';
    private const string EXTERNAL_PROXY_SOCKET_ID = 'external-proxy';
    private const string DEFAULT_PROXY_CLIENT_ADDRESS = '127.0.0.1';

    /**
     * Icecast 2.5 reserves one source slot for the fallback file associated
     * with each configured station mount.
     */
    public static function getSourceLimit(int $mountCount): int
    {
        return $mountCount * 2;
    }

    public static function getFallbackOverride(): string
    {
        return 'all';
    }

    /**
     * @return array<int, array<string, int|string|array<int, string>>>
     */
    public static function getListenSockets(int $port, ?string $proxyClientAddress = null): array
    {
        $proxyClientAddress = trim($proxyClientAddress ?? '');

        $trustedProxies = ['#' . self::PROXY_SOCKET_ID];
        $proxySockets = [
            [
                '@id' => self::PROXY_SOCKET_ID,
                '@type' => 'virtual',
                'client-address' => self::DEFAULT_PROXY_CLIENT_ADDRESS,
            ],
        ];

        if ('' !== $proxyClientAddress && self::DEFAULT_PROXY_CLIENT_ADDRESS !== $proxyClientAddress) {
            $trustedProxies[] = '#' . self::EXTERNAL_PROXY_SOCKET_ID;
            $proxySockets[] = [
                '@id' => self::EXTERNAL_PROXY_SOCKET_ID,
                '@type' => 'virtual',
                'client-address' => $proxyClientAddress,
            ];
        }

        return [
            [
                '@id' => self::PUBLIC_SOCKET_ID,
                'port' => $port,
                'trusted-proxy' => $trustedProxies,
            ],
            ...$proxySockets,
        ];
    }
}
