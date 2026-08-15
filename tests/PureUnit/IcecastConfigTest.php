<?php

declare(strict_types=1);

namespace PureUnit;

use App\Radio\Frontend\IcecastConfig;
use App\Xml\Writer;
use PHPUnit\Framework\TestCase;

final class IcecastConfigTest extends TestCase
{
    public function testIcecast25ReservesSourceSlotsForMountsAndFallbackFiles(): void
    {
        self::assertSame(2, IcecastConfig::getSourceLimit(1));
        self::assertSame(6, IcecastConfig::getSourceLimit(3));
    }

    public function testIcecast25UsesNamedFallbackOverrideMode(): void
    {
        self::assertSame('all', IcecastConfig::getFallbackOverride());
    }

    public function testTrustedProxyVirtualSocketDefaultsToBuiltInProxy(): void
    {
        $xml = Writer::toString(
            ['listen-socket' => IcecastConfig::getListenSockets(8000)],
            'icecast',
            false
        );

        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="public">
                    <port>8000</port>
                    <trusted-proxy>#azuracast-proxy</trusted-proxy>
                </listen-socket>
            XML,
            $xml
        );
        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="azuracast-proxy" type="virtual">
                    <client-address>127.0.0.1</client-address>
                </listen-socket>
            XML,
            $xml
        );
    }

    public function testConfiguredExternalProxyIsAddedWithoutRemovingBuiltInProxy(): void
    {
        $xml = Writer::toString(
            ['listen-socket' => IcecastConfig::getListenSockets(8000, '192.168.1.50')],
            'icecast',
            false
        );

        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="public">
                    <port>8000</port>
                    <trusted-proxy>#azuracast-proxy</trusted-proxy>
                    <trusted-proxy>#external-proxy</trusted-proxy>
                </listen-socket>
            XML,
            $xml
        );
        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="azuracast-proxy" type="virtual">
                    <client-address>127.0.0.1</client-address>
                </listen-socket>
            XML,
            $xml
        );
        self::assertStringContainsString(
            <<<'XML'
            <listen-socket id="external-proxy" type="virtual">
                    <client-address>192.168.1.50</client-address>
                </listen-socket>
            XML,
            $xml
        );
    }

    public function testLoopbackAddressDoesNotCreateDuplicateTrustedProxy(): void
    {
        $xml = Writer::toString(
            ['listen-socket' => IcecastConfig::getListenSockets(8000, '127.0.0.1')],
            'icecast',
            false
        );

        self::assertSame(1, substr_count($xml, '<trusted-proxy>'));
        self::assertSame(1, substr_count($xml, '<client-address>127.0.0.1</client-address>'));
    }
}
