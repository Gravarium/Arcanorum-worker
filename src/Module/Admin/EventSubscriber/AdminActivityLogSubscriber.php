<?php

declare(strict_types=1);

namespace App\Module\Admin\EventSubscriber;

use App\Entity\AdminActivityEntry;
use App\Entity\AdminRole;
use App\Entity\AdminUser;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class AdminActivityLogSubscriber implements EventSubscriberInterface
{
    private const array MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const array EXCLUDED_ROUTES = ['admin_login', 'admin_logout', 'admin_activity_clear'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['recordSuccessfulMutation', -100]];
    }

    public function recordSuccessfulMutation(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getResponse()->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            return;
        }

        $request = $event->getRequest();
        if (!in_array($request->getMethod(), self::MUTATION_METHODS, true)) {
            return;
        }

        $routeName = $request->attributes->get('_route');
        if (!is_string($routeName)
            || !str_starts_with($routeName, 'admin_')
            || in_array($routeName, self::EXCLUDED_ROUTES, true)
        ) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $actor = $token?->getUser();
        if (!$actor instanceof AdminUser || !AdminRole::hasStaffAccess($actor->getRoles())) {
            return;
        }

        try {
            $this->entityManager->persist(new AdminActivityEntry($actor, $routeName, $request->getMethod()));
            $this->entityManager->flush();
        } catch (DbalException $exception) {
            $this->logger->error('Admin-Aktivität konnte nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);
        }
    }
}
