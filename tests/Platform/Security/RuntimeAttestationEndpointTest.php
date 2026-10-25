<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\RuntimeAttestationEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class RuntimeAttestationEndpointTest extends TestCase
{
    public function testRequiresAnExactAllowedFullyQualifiedHost(): void
    {
        self::assertTrue(RuntimeAttestationEndpoint::isValid(
            'https://monitor.example.test/v1/report',
            ['monitor.example.test'],
        ));
        self::assertTrue(RuntimeAttestationEndpoint::isValid(
            'https://MONITOR.EXAMPLE.TEST/v1/report',
            ['monitor.example.test'],
        ));
        self::assertFalse(RuntimeAttestationEndpoint::isValid(
            'https://other.example.test/v1/report',
            ['monitor.example.test'],
        ));
    }

    public function testRejectsMissingOrMalformedHostAllowLists(): void
    {
        foreach ([
            [],
            ['*.example.test'],
            ['127.0.0.1'],
            ['0177.0.0.1'],
            ['0x7f.0x0.0x0.0x1'],
            ['monitor.example.test', 'monitor.example.test'],
            [' monitor.example.test'],
            ['monitor.example.test:443'],
            ['localhost'],
        ] as $allowedHosts) {
            self::assertFalse(RuntimeAttestationEndpoint::isValid(
                'https://monitor.example.test/v1/report',
                $allowedHosts,
            ));
        }
    }

    public function testRejectsPrivateAddressLiteralsEvenIfTheyAppearInTheAllowList(): void
    {
        foreach ([
            'https://127.0.0.1/v1/report',
            'https://10.0.0.1/v1/report',
            'https://0177.0.0.1/v1/report',
            'https://0x7f.0x0.0x0.0x1/v1/report',
            'https://[::1]/v1/report',
            'https://[::ffff:127.0.0.1]/v1/report',
        ] as $endpoint) {
            self::assertFalse(RuntimeAttestationEndpoint::isValid(
                $endpoint,
                ['127.0.0.1', '10.0.0.1', '::1', '::ffff:127.0.0.1'],
            ));
        }
    }

    public function testNoPrivateNetworkClientBlocksLoopbackAndPrivateRangesBeforeDispatch(): void
    {
        $requests = 0;
        $client = new NoPrivateNetworkHttpClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('', ['http_code' => 204]);
            },
        ));

        foreach ([
            'https://127.0.0.1/',
            'https://10.0.0.1/',
            'https://192.168.0.1/',
            'https://169.254.169.254/',
            'https://[::1]/',
            'https://[::ffff:127.0.0.1]/',
            'https://[2002:7f00:1::]/',
            'https://[64:ff9b::7f00:1]/',
            'https://[2001::1]/',
            'https://localhost/',
        ] as $endpoint) {
            try {
                $client->request('GET', $endpoint)->getStatusCode();
                self::fail(sprintf('The private endpoint "%s" must be blocked.', $endpoint));
            } catch (TransportExceptionInterface) {
            }
        }

        self::assertSame(0, $requests);
    }

    public function testNoPrivateNetworkClientBlocksAnAllowedHostnameResolvedToPrivateAddress(): void
    {
        $endpoint = 'https://monitor.example.test/v1/report';
        self::assertTrue(RuntimeAttestationEndpoint::isValid($endpoint, ['monitor.example.test']));

        $requests = 0;
        $client = new NoPrivateNetworkHttpClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('', ['http_code' => 204]);
            },
        ));

        try {
            $client->request('POST', $endpoint, [
                'resolve' => ['monitor.example.test' => '10.0.0.7'],
            ])->getStatusCode();
            self::fail('Ein erlaubter Attestierungs-Hostname mit privater DNS-Auflösung muss gesperrt werden.');
        } catch (TransportExceptionInterface) {
        }

        self::assertSame(0, $requests);
    }
}
