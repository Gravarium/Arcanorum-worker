<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Service\AdminSystemEventSeverity;
use App\Service\SystemCapabilitySummary;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SystemCapabilitySummaryTest extends TestCase
{
    public function testCreatesPrivateSafeEventsWithSeverityAndOriginalObservationTime(): void
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $probe = new SystemCapabilitySummaryTestProbe($checkedAt);
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $message, array $parameters = []): string => strtr($message, $parameters),
        );

        $result = new SystemCapabilitySummary(new HostingProfileDetector([$probe]), $translator)->current();

        self::assertCount(1, $result['events']);
        self::assertCount(1, $result['notices']);
        self::assertSame(AdminSystemEventSeverity::Critical, $result['events'][0]->severity);
        self::assertSame('PHP-Laufzeit: Fehlgeschlagen.', $result['events'][0]->message);
        self::assertSame($checkedAt, $result['events'][0]->observedAt);

        $serialized = json_encode($result['events'][0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('/private/config', $serialized);
        self::assertStringNotContainsString('private diagnostic detail', $serialized);
    }
}

/** @internal */
final readonly class SystemCapabilitySummaryTestProbe implements CapabilityProbeInterface
{
    public function __construct(private \DateTimeImmutable $checkedAt)
    {
    }

    /**
     * @return iterable<CapabilityEvidence>
     */
    public function probe(): iterable
    {
        foreach (HostingCapability::cases() as $capability) {
            yield new CapabilityEvidence(
                $capability,
                HostingCapability::PhpRuntime === $capability ? CapabilityStatus::Failed : CapabilityStatus::Verified,
                '/private/config',
                'private diagnostic detail',
                $this->checkedAt,
            );
        }
    }
}
