<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Entity\AdminUser;
use App\Entity\MemberActivityModerationStatus;
use App\Entity\MemberActivityType;
use App\Repository\MemberActivityAdminRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/member-activities', name: 'admin_member_activity_')]
#[IsGranted('ROLE_ADMIN')]
final class MemberActivityAdminController extends AbstractController
{
    private const int PAGE_SIZE = 50;
    private const int MAX_PAGE = 5000;
    private const int MAX_BATCH_SIZE = 100;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, MemberActivityAdminRepository $activities): Response
    {
        $query = $request->query->all();
        if (!$this->containsOnly($query, ['q', 'type', 'status', 'page'])) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $filters = $this->filters($query);
        if (null === $filters) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $total = $activities->countForAdmin($filters['search'], $filters['type'], $filters['status']);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($filters['page'], $pageCount);

        return $this->noStore($this->render('admin/member_activity/index.html.twig', [
            'activities' => $activities->findForAdmin(
                $filters['search'],
                $filters['type'],
                $filters['status'],
                self::PAGE_SIZE,
                ($page - 1) * self::PAGE_SIZE,
            ),
            'search' => $filters['search'],
            'type_filter' => $filters['type']->value ?? 'all',
            'status_filter' => $filters['status']->value ?? 'all',
            'activity_types' => MemberActivityType::cases(),
            'moderation_statuses' => MemberActivityModerationStatus::cases(),
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
        ]));
    }

    #[Route('/moderate', name: 'moderate', methods: ['POST'])]
    public function moderate(
        Request $request,
        MemberActivityAdminRepository $activities,
        TranslatorInterface $translator,
    ): Response {
        $payload = $request->request->all();
        if (!$this->containsOnly($payload, ['_token', 'action', 'activities', 'q', 'type', 'status', 'page'])) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $filters = $this->filters($payload);
        $token = $payload['_token'] ?? null;
        $action = $payload['action'] ?? null;
        $rawActivities = $payload['activities'] ?? null;
        if (null === $filters || !is_string($token) || !is_string($action)
            || !is_array($rawActivities) || !array_is_list($rawActivities)
            || [] === $rawActivities || count($rawActivities) > self::MAX_BATCH_SIZE
        ) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        if (!$this->isCsrfTokenValid('admin_member_activity_moderate', $token)) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $status = match ($action) {
            'hide' => MemberActivityModerationStatus::Hidden,
            'restore' => MemberActivityModerationStatus::Visible,
            'remove' => MemberActivityModerationStatus::Removed,
            default => null,
        };
        if (null === $status) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $selected = [];
        $unique = [];
        foreach ($rawActivities as $rawActivity) {
            if (!is_string($rawActivity) || 1 !== preg_match('/\A([a-z_]{1,32}):([a-f0-9]{32})\z/D', $rawActivity, $matches)) {
                return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
            }
            $type = MemberActivityType::tryFrom($matches[1]);
            if (null === $type || isset($unique[$rawActivity])) {
                return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
            }
            $unique[$rawActivity] = true;
            $selected[] = ['type' => $type, 'id' => $matches[2]];
        }

        $moderator = $this->getUser();
        if (!$moderator instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        try {
            $updated = $activities->moderateMany($selected, $status, $moderator);
        } catch (\InvalidArgumentException|ForeignKeyConstraintViolationException|UniqueConstraintViolationException) {
            return $this->noStore(new Response(
                $translator->trans('Die ausgewählte Aktivität ist nicht mehr verfügbar. Bitte aktualisiere die Liste.'),
                Response::HTTP_CONFLICT,
            ));
        }

        $this->addFlash('success', $translator->trans(
            'Mitgliederaktivitäten aktualisiert. %count% Einträge wurden geändert.',
            ['%count%' => $updated],
        ));

        return $this->noStore($this->redirectToRoute('admin_member_activity_index', [
            'q' => $filters['search'],
            'type' => $filters['type']->value ?? 'all',
            'status' => $filters['status']->value ?? 'all',
            'page' => $filters['page'],
        ]));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{search: string, type: ?MemberActivityType, status: ?MemberActivityModerationStatus, page: int}|null
     */
    private function filters(array $input): ?array
    {
        $search = $input['q'] ?? '';
        $typeValue = $input['type'] ?? 'all';
        $statusValue = $input['status'] ?? 'all';
        $rawPage = $input['page'] ?? '1';
        if (!is_string($search) || 1 !== preg_match('//u', $search) || 1 === preg_match('/[\x00-\x1F\x7F]/', $search) || mb_strlen($search, 'UTF-8') > 120
            || !is_string($typeValue) || !is_string($statusValue) || !is_string($rawPage)
            || 1 !== preg_match('/\A[1-9][0-9]{0,3}\z/D', $rawPage)
            || (int) $rawPage > self::MAX_PAGE
        ) {
            return null;
        }

        $type = 'all' === $typeValue ? null : MemberActivityType::tryFrom($typeValue);
        $status = 'all' === $statusValue ? null : MemberActivityModerationStatus::tryFrom($statusValue);
        if (('all' !== $typeValue && null === $type) || ('all' !== $statusValue && null === $status)) {
            return null;
        }

        return [
            'search' => trim($search),
            'type' => $type,
            'status' => $status,
            'page' => (int) $rawPage,
        ];
    }

    /** @param array<string, mixed> $input
     * @param list<string> $allowed
     */
    private function containsOnly(array $input, array $allowed): bool
    {
        return array_all(array_keys($input), static fn (int|string $key): bool => in_array($key, $allowed, true));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
