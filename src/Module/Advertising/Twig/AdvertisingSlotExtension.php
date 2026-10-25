<?php

declare(strict_types=1);

namespace App\Module\Advertising\Twig;

use App\Module\Advertising\Application\AdvertisingDelivery;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Twig\Markup;

final readonly class AdvertisingSlotExtension
{
    public function __construct(
        private AdvertisingDelivery $delivery,
        private LoggerInterface $logger,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'advertising_slot')]
    public function renderSlot(string $placementId): Markup
    {
        if (1 !== preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $placementId)) {
            return new Markup('', 'UTF-8');
        }

        try {
            $html = $this->delivery->renderPlacement($placementId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\DomainException) {
            return new Markup('', 'UTF-8');
        } catch (DbalException|\InvalidArgumentException $exception) {
            $this->logger->warning('Werbeplatz konnte nicht ausgegeben werden.', [
                'failure_type' => $exception::class,
                'placement_id' => $placementId,
            ]);

            return new Markup('', 'UTF-8');
        }

        // AdvertisingRenderer escapes every field and runs banner HTML through SafeHtml.
        return new Markup($html, 'UTF-8');
    }
}
