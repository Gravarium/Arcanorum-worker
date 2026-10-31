<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Module\Admin\Service\AdminModeratorAccessManager;
use App\Module\Admin\Service\AdminRoleChangeResult;
use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/staff', name: 'admin_staff_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminStaffController extends AbstractController
{
    private const int PAGE_SIZE = 25;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, AdminUserRepository $adminUsers, LoggerInterface $logger, TranslatorInterface $translator): Response
    {
        return $this->renderIndexSafely($request, $adminUsers, $logger, $translator);
    }

    #[Route('/{id}/role', name: 'role_update', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function updateRole(
        string $id,
        Request $request,
        AdminUserRepository $adminUsers,
        AdminModeratorAccessManager $accessManager,
        LoggerInterface $logger,
        TranslatorInterface $translator,
    ): Response {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_staff_role_'.$id, $token)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $input = $request->request->all();
        if ([] !== array_diff(array_keys($input), ['_token', 'role'])) {
            return $this->renderIndexSafely($request, $adminUsers, $logger, $translator, ['form' => 'Die Anfrage enthält unbekannte Rollenfelder.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $roleValue = $request->request->get('role');
        if (!is_string($roleValue) || strlen($roleValue) > 64) {
            return $this->renderIndexSafely($request, $adminUsers, $logger, $translator, ['form' => 'Bitte wählen Sie eine gültige Verwaltungsrolle.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role = '' === $roleValue ? null : AdminRole::tryFrom($roleValue);
        if ('' !== $roleValue && null === $role) {
            return $this->renderIndexSafely($request, $adminUsers, $logger, $translator, ['form' => 'Die gewählte Verwaltungsrolle wird nicht unterstützt.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actor = $this->getUser();
        if (!$actor instanceof AdminUser) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $result = $accessManager->assign($actor, $id, $role, csrfVerified: true);
        } catch (DbalException $exception) {
            $logger->error('Verwaltungsrollen konnten nicht gespeichert werden.', ['failure_type' => $exception::class]);

            return $this->unavailableResponse($translator);
        }

        $error = match ($result) {
            AdminRoleChangeResult::Updated, AdminRoleChangeResult::Unchanged => null,
            AdminRoleChangeResult::SelfChangeForbidden => 'Die eigene Rolle kann in dieser Ansicht nicht geändert werden.',
            AdminRoleChangeResult::MfaRequired => 'Das Zielkonto muss zuerst mit MFA eingerichtet werden, bevor Verwaltungszugriff vergeben wird.',
            AdminRoleChangeResult::ActorMfaRequired => 'Ihr Konto muss für eine Rollenänderung mit MFA eingerichtet sein.',
            AdminRoleChangeResult::CsrfRequired => 'Die Sicherheitsprüfung der Anfrage ist fehlgeschlagen.',
            AdminRoleChangeResult::LastAdministratorProtected => 'Der letzte Administrator kann nicht herabgestuft werden.',
            AdminRoleChangeResult::InvalidRosterState => 'Der Verwaltungsrollenstand ist ungültig und wurde nicht verändert.',
            AdminRoleChangeResult::InvalidRole => 'Die gewählte Verwaltungsrolle wird nicht unterstützt.',
            AdminRoleChangeResult::TargetNotFound => null,
            AdminRoleChangeResult::ActorNoLongerAdministrator => 'Ihre Administratorberechtigung ist nicht mehr gültig. Melden Sie sich erneut an.',
        };

        if (AdminRoleChangeResult::TargetNotFound === $result) {
            return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
        }
        if (AdminRoleChangeResult::ActorNoLongerAdministrator === $result) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }
        if (AdminRoleChangeResult::CsrfRequired === $result) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }
        if (null !== $error) {
            return $this->renderIndexSafely($request, $adminUsers, $logger, $translator, ['form' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash(
            'success',
            AdminRoleChangeResult::Updated === $result
                ? 'Die Verwaltungsrolle wurde gespeichert. Die bisherige Sitzung des betroffenen Kontos wird beim nächsten Zugriff ungültig.'
                : 'Die Verwaltungsrolle ist bereits so eingestellt.',
        );

        $response = $this->redirectToRoute('admin_staff_index', ['page' => self::requestedPage($request)]);

        return $this->privateResponse($response);
    }

    /** @param array<string, string> $errors */
    private function renderIndexSafely(
        Request $request,
        AdminUserRepository $adminUsers,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        try {
            return $this->renderIndex($request, $adminUsers, $errors, $status);
        } catch (DbalException $exception) {
            $logger->error('Verwaltungsrollen konnten nicht sicher gelesen werden.', ['failure_type' => $exception::class]);

            return $this->unavailableResponse($translator);
        }
    }

    /** @param array<string, string> $errors */
    private function renderIndex(
        Request $request,
        AdminUserRepository $adminUsers,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        $total = $adminUsers->countForRoleManagement();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(self::requestedPage($request), $pageCount);
        $accounts = $adminUsers->findForRoleManagement(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        $response = $this->render('admin/staff/index.html.twig', [
            'accounts' => $accounts,
            'errors' => $errors,
            'page' => $page,
            'page_count' => $pageCount,
            'role_options' => self::roleOptions(),
            'selected_roles' => array_combine(
                array_map(static fn (AdminUser $account): string => $account->getId(), $accounts),
                array_map(self::selectedRole(...), $accounts),
            ),
            'total' => $total,
            'viewer_id' => $this->getUser() instanceof AdminUser ? $this->getUser()->getId() : '',
        ], new Response(status: $status));

        return $this->privateResponse($response);
    }

    /** @return list<array{value: string, label: string}> */
    private static function roleOptions(): array
    {
        return [
            ['value' => '', 'label' => 'Kein Verwaltungszugriff'],
            ['value' => AdminRole::Administrator->value, 'label' => 'Administrator'],
            ['value' => AdminRole::Moderator->value, 'label' => 'Moderator für Videos und Kommentare'],
            ['value' => AdminRole::VideoModerator->value, 'label' => 'Videomoderation'],
            ['value' => AdminRole::CommentModerator->value, 'label' => 'Kommentarmoderation'],
        ];
    }

    private static function selectedRole(AdminUser $account): string
    {
        $roles = $account->getRoles();
        if (in_array(AdminRole::Administrator->value, $roles, true)) {
            return AdminRole::Administrator->value;
        }
        if (in_array(AdminRole::Moderator->value, $roles, true)
            || (in_array(AdminRole::VideoModerator->value, $roles, true)
                && in_array(AdminRole::CommentModerator->value, $roles, true))
        ) {
            return AdminRole::Moderator->value;
        }
        if (in_array(AdminRole::VideoModerator->value, $roles, true)) {
            return AdminRole::VideoModerator->value;
        }
        if (in_array(AdminRole::CommentModerator->value, $roles, true)) {
            return AdminRole::CommentModerator->value;
        }

        return '';
    }

    private static function requestedPage(Request $request): int
    {
        $rawPage = $request->query->all()['page'] ?? '1';
        if (!is_string($rawPage) || strlen($rawPage) > 6 || !ctype_digit($rawPage)) {
            return 1;
        }

        return max(1, min(100_000, (int) $rawPage));
    }

    private function unavailableResponse(TranslatorInterface $translator): Response
    {
        $response = new Response($translator->trans('Die Verwaltungsrollen sind derzeit nicht verfügbar.'), Response::HTTP_SERVICE_UNAVAILABLE, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);

        return $this->privateResponse($response);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
