<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\MemberAccountBan;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityMailService;
use App\Repository\MemberAccountBanRepository;
use App\Repository\MemberAccountRepository;
use App\Service\MemberAccountCsvWriter;
use App\Service\MemberAccountDeletionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/members', name: 'admin_member_')]
#[IsGranted('ROLE_ADMIN')]
final class MemberAccountAdminController extends AbstractController
{
    private const int PAGE_SIZE = 25;

    private const int INVITATION_TTL_HOURS = 48;

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        MemberAccountRepository $members,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $values = ['email' => '', 'handle' => '', 'display_name' => ''];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_account_new', $request->request->getString('_token'))) {
                return new Response('', Response::HTTP_FORBIDDEN, [
                    'Cache-Control' => 'no-store, private',
                    'X-Robots-Tag' => 'noindex, nofollow',
                ]);
            }

            $rawEmail = $request->request->getString('email');
            $rawHandle = $request->request->getString('handle');
            $rawDisplayName = $request->request->getString('display_name');
            $values = ['email' => trim($rawEmail), 'handle' => trim($rawHandle), 'display_name' => trim($rawDisplayName)];

            if (strlen($rawEmail) > 1016 || strlen($rawHandle) > 128 || strlen($rawDisplayName) > 320) {
                $errors[] = $translator->trans('Die Eingabe ist zu groß.');
            } elseif (1 !== preg_match('//u', $values['email'])
                || 1 !== preg_match('//u', $values['handle'])
                || 1 !== preg_match('//u', $values['display_name'])
            ) {
                $errors[] = $translator->trans('Die Eingabe muss gültiges UTF-8 enthalten.');
            } else {
                $email = mb_strtolower($values['email'], 'UTF-8');
                $handle = mb_strtolower($values['handle'], 'UTF-8');
                $values['email'] = $email;
                $values['handle'] = $handle;

                if ('' === $email || mb_strlen($email, 'UTF-8') > 254 || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = $translator->trans('Bitte gib eine gültige E-Mail-Adresse ein.');
                }
                if (1 !== preg_match('/\A[a-z0-9][a-z0-9_-]{2,31}\z/D', $handle)) {
                    $errors[] = $translator->trans('Der Kanalname muss 3 bis 32 Zeichen lang sein und darf Buchstaben, Zahlen, Unterstriche und Bindestriche enthalten.');
                }
                if ('' === $values['display_name']
                    || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['display_name'])
                    || mb_strlen($values['display_name'], 'UTF-8') > 80
                ) {
                    $errors[] = $translator->trans('Der Anzeigename muss 1 bis 80 Zeichen lang sein.');
                }

                if ([] === $errors && (null !== $members->findOneBy(['email' => $email])
                    || null !== $members->findOneBy(['handle' => $handle]))) {
                    $errors[] = $translator->trans('Diese E-Mail-Adresse oder dieser Kanalname ist bereits vergeben.');
                }

                if ([] === $errors) {
                    $account = null;
                    $code = null;
                    try {
                        $account = new MemberAccount($email, $handle, $values['display_name']);
                        $account->setActive(false);
                        $code = $this->issueInvitation($account);
                        $entityManager->persist($account);
                        $entityManager->flush();
                    } catch (\InvalidArgumentException $exception) {
                        $errors[] = $translator->trans($exception->getMessage());
                    } catch (UniqueConstraintViolationException) {
                        $errors[] = $translator->trans('Diese E-Mail-Adresse oder dieser Kanalname ist bereits vergeben.');
                    }

                    if ([] === $errors) {
                        if (!$account instanceof MemberAccount || null === $code) {
                            throw new \LogicException('Das Mitgliedskonto konnte nicht eingerichtet werden.');
                        }

                        $this->addFlash('success', $translator->trans('Mitgliedskonto angelegt. Der Einrichtungscode wird auf der nächsten Seite einmalig angezeigt.'));
                        $this->addFlash('member_invitation', $code);

                        $response = $this->redirectToRoute('admin_member_edit', ['id' => $account->getId()]);
                        $response->headers->set('Cache-Control', 'no-store, private');
                        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

                        return $response;
                    }
                }
            }
        }

        $response = $this->render('admin/members/new.html.twig', [
            'values' => $values,
            'errors' => $errors,
        ]);
        $response->setStatusCode([] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        MemberAccount $account,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $values = [
            'display_name' => $account->getDisplayName(),
            'bio' => $account->getBio() ?? '',
            'public_profile' => $account->isPublicProfile(),
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(
                'member_account_edit_'.$account->getId(),
                $request->request->getString('_token'),
            )) {
                return new Response('', Response::HTTP_FORBIDDEN, [
                    'Cache-Control' => 'no-store, private',
                    'X-Robots-Tag' => 'noindex, nofollow',
                ]);
            }

            $displayName = $request->request->getString('display_name');
            $bio = $request->request->getString('bio');
            $publicProfile = $request->request->getString('public_profile');

            if (strlen($displayName) > 320 || strlen($bio) > 2000) {
                $errors[] = $translator->trans('Der Anzeigename oder die Profilbeschreibung ist zu lang.');
            } elseif (!in_array($publicProfile, ['', '1'], true)) {
                $errors[] = $translator->trans('Die Profilsichtbarkeit ist ungültig.');
            } else {
                $values = [
                    'display_name' => $displayName,
                    'bio' => $bio,
                    'public_profile' => '1' === $publicProfile,
                ];

                try {
                    $account->updateProfile($displayName, $bio, '1' === $publicProfile);
                    $entityManager->flush();
                    $this->addFlash('success', $translator->trans('Mitgliederprofil gespeichert.'));

                    $response = $this->redirectToRoute('admin_member_edit', ['id' => $account->getId()]);
                    $response->headers->set('Cache-Control', 'no-store, private');
                    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

                    return $response;
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $translator->trans($exception->getMessage());
                }
            }
        }

        $response = $this->render('admin/members/edit.html.twig', [
            'account' => $account,
            'values' => $values,
            'errors' => $errors,
            'identity_values' => ['email' => $account->getEmail(), 'handle' => $account->getHandle()],
            'identity_errors' => [],
        ]);
        $response->setStatusCode([] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{id}/invitation', name: 'invitation', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function invitation(
        Request $request,
        MemberAccount $account,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        if (!$this->isCsrfTokenValid(
            'member_account_invitation_'.$account->getId(),
            $request->request->getString('_token'),
        )) {
            return new Response('', Response::HTTP_FORBIDDEN, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        if ($account->isActive()) {
            return new Response($translator->trans('Nur für inaktive Konten kann ein Einrichtungscode ausgestellt werden.'), Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $code = $this->issueInvitation($account);
        $entityManager->flush();
        $this->addFlash('success', $translator->trans('Ein neuer Einrichtungscode wurde ausgestellt; der vorherige Code ist ungültig.'));
        $this->addFlash('member_invitation', $code);

        $response = $this->redirectToRoute('admin_member_edit', ['id' => $account->getId()]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{id}/password-reset', name: 'password_reset', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function passwordReset(
        Request $request,
        MemberAccount $account,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (!$this->isCsrfTokenValid(
            'member_account_password_reset_'.$account->getId(),
            $request->request->getString('_token'),
        )) {
            return new Response('', Response::HTTP_FORBIDDEN, $headers);
        }

        if (!$account->isActive()) {
            return new Response($translator->trans('Ein Passwort-Reset kann nur für aktive Konten gestartet werden.'), Response::HTTP_UNPROCESSABLE_ENTITY, $headers + [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $account->setActive(false);
        $code = $this->issueInvitation($account);
        $entityManager->flush();
        $this->addFlash('success', $translator->trans('Das Mitgliedskonto wurde bis zur Passwortneufestlegung gesperrt. Der Reset-Code wird nur einmal angezeigt.'));
        $this->addFlash('member_invitation_mode', 'password_reset');
        $this->addFlash('member_invitation', $code);

        $response = $this->redirectToRoute('admin_member_edit', ['id' => $account->getId()]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{id}/identity', name: 'identity', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function identity(
        Request $request,
        MemberAccount $account,
        MemberAccountRepository $members,
        EntityManagerInterface $entityManager,
        MemberSecurityMailService $mailService,
        TranslatorInterface $translator,
    ): Response {
        if (!$this->isCsrfTokenValid(
            'member_account_identity_'.$account->getId(),
            $request->request->getString('_token'),
        )) {
            return new Response('', Response::HTTP_FORBIDDEN, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        $values = [
            'email' => $request->request->getString('email'),
            'handle' => $request->request->getString('handle'),
        ];
        $errors = [];

        if (strlen($values['email']) > 1016 || strlen($values['handle']) > 128) {
            $errors[] = $translator->trans('Die E-Mail-Adresse oder der Kanalname ist zu lang.');
        } elseif (1 !== preg_match('//u', $values['email']) || 1 !== preg_match('//u', $values['handle'])) {
            $errors[] = $translator->trans('Die Eingabe muss gültiges UTF-8 enthalten.');
        } else {
            $email = mb_strtolower(trim($values['email']), 'UTF-8');
            $handle = mb_strtolower(trim($values['handle']), 'UTF-8');
            $emailOwner = $members->findOneBy(['email' => $email]);
            $handleOwner = $members->findOneBy(['handle' => $handle]);

            if (($emailOwner instanceof MemberAccount && $emailOwner->getId() !== $account->getId())
                || ($handleOwner instanceof MemberAccount && $handleOwner->getId() !== $account->getId())
            ) {
                $errors[] = $translator->trans('Diese E-Mail-Adresse oder dieser Kanalname ist bereits vergeben.');
            } else {
                $emailChanged = $account->getEmail() !== $email;
                try {
                    $account->updateIdentity($values['email'], $values['handle']);
                    $entityManager->flush();
                    if ($emailChanged) {
                        $sent = $mailService->send($account, MemberEmailChallengePurpose::EmailVerification);
                        $this->addFlash(
                            'success',
                            $sent
                            ? $translator->trans('E-Mail-Adresse und Kanalname gespeichert. Eine Bestätigungsnachricht wurde an die neue E-Mail-Adresse gesendet.')
                                : $translator->trans('E-Mail-Adresse und Kanalname gespeichert. Das Mitglied kann die Bestätigungsnachricht über die Anmeldeseite erneut anfordern.'),
                        );
                    } else {
                        $this->addFlash('success', $translator->trans('E-Mail-Adresse und Kanalname gespeichert.'));
                    }

                    $response = $this->redirectToRoute('admin_member_edit', ['id' => $account->getId()]);
                    $response->headers->set('Cache-Control', 'no-store, private');
                    $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

                    return $response;
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $translator->trans($exception->getMessage());
                } catch (UniqueConstraintViolationException) {
                    return new Response($translator->trans('E-Mail-Adresse oder Kanalname ist bereits vergeben.'), Response::HTTP_CONFLICT, [
                        'Cache-Control' => 'no-store, private',
                        'X-Robots-Tag' => 'noindex, nofollow',
                        'Content-Type' => 'text/plain; charset=UTF-8',
                    ]);
                }
            }
        }

        $response = $this->render('admin/members/edit.html.twig', [
            'account' => $account,
            'values' => [
                'display_name' => $account->getDisplayName(),
                'bio' => $account->getBio() ?? '',
                'public_profile' => $account->isPublicProfile(),
            ],
            'errors' => [],
            'identity_values' => $values,
            'identity_errors' => $errors,
        ]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{id}/status', name: 'status', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function status(
        Request $request,
        MemberAccount $account,
        MemberAccountBanRepository $bans,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $active = $request->request->getString('is_active');
        if (!in_array($active, ['0', '1'], true)) {
            return new Response($translator->trans('Ungültiger Kontostatus.'), Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        if (!$this->isCsrfTokenValid(
            'member_account_status_'.$account->getId().'_'.$active,
            $request->request->getString('_token'),
        )) {
            return new Response('', Response::HTTP_FORBIDDEN, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        $changed = $entityManager->getConnection()->transactional(function () use ($account, $active, $bans, $entityManager): bool {
            $entityManager->refresh($account, LockMode::PESSIMISTIC_WRITE);
            if ('1' === $active && null !== $bans->findActiveForMember($account, true)) {
                return false;
            }

            $account->setActive('1' === $active);
            $entityManager->flush();

            return true;
        });
        if (!$changed) {
            return new Response($translator->trans('Eine gesperrte Mitgliedschaft kann nur über die Bannliste aufgehoben werden.'), Response::HTTP_CONFLICT, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }
        $this->addFlash(
            'success',
            $translator->trans('1' === $active ? 'Mitgliedskonto aktiviert.' : 'Mitgliedskonto gesperrt.'),
        );

        $response = $this->redirectToRoute('admin_member_edit', ['id' => $account->getId()]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function delete(
        Request $request,
        MemberAccount $account,
        MemberAccountDeletionService $deletions,
        TranslatorInterface $translator,
    ): Response {
        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $payload = $request->request->all();
        if ([] !== array_diff(array_keys($payload), ['_token', 'confirm'])) {
            return new Response('', Response::HTTP_BAD_REQUEST, $headers);
        }

        $token = $payload['_token'] ?? null;
        if (!is_string($token)
            || '' === $token
            || !$this->isCsrfTokenValid('member_account_delete_'.$account->getId(), $token)
        ) {
            return new Response('', Response::HTTP_FORBIDDEN, $headers);
        }
        if (!is_string($payload['confirm'] ?? null) || '1' !== $payload['confirm']) {
            return new Response($translator->trans('Bestätige die dauerhafte Löschung des Mitgliedskontos.'), Response::HTTP_UNPROCESSABLE_ENTITY, $headers + [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $result = $deletions->delete($account);
        if (!$result->deleted) {
            return new Response('', Response::HTTP_NOT_FOUND, $headers);
        }

        $message = $result->pendingPrivateObjects > 0
            ? $translator->trans('Mitgliedskonto gelöscht. Einige private Dateien warten noch auf sichere Bereinigung.')
            : $translator->trans('Mitgliedskonto und private Kontodaten gelöscht.');
        $this->addFlash('success', $message);

        return $this->noStoreRedirect('admin_member_index');
    }

    #[Route('/{id}/ban', name: 'ban', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function ban(
        Request $request,
        MemberAccount $account,
        MemberAccountBanRepository $bans,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (!$this->isCsrfTokenValid(
            'member_account_ban_'.$account->getId(),
            $request->request->getString('_token'),
        )) {
            return new Response('', Response::HTTP_FORBIDDEN, $headers);
        }

        $payload = $request->request->all();
        $rawReason = $payload['reason'] ?? null;
        $reason = is_string($rawReason) ? trim($rawReason) : '';
        if (!is_string($rawReason)
            || strlen($rawReason) > 2000
            || 1 !== preg_match('//u', $reason)
            || '' === $reason
            || mb_strlen($reason, 'UTF-8') > 500
            || 1 === preg_match('/\p{Cc}/u', $reason)
        ) {
            return new Response($translator->trans('Die Begründung muss gültigen Text mit 1 bis 500 Zeichen enthalten.'), Response::HTTP_UNPROCESSABLE_ENTITY, $headers + [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $admin = $this->getUser();
        if (!$admin instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        $created = $entityManager->getConnection()->transactional(function () use ($account, $admin, $bans, $entityManager, $reason): bool {
            $entityManager->refresh($account, LockMode::PESSIMISTIC_WRITE);
            if (null !== $bans->findActiveForMember($account, true)) {
                return false;
            }

            $wasActive = $account->isActive();
            $account->setActive(false);
            $account->clearInvitation();
            $entityManager->persist(new MemberAccountBan($account, $admin, $reason, $wasActive));
            $entityManager->flush();

            return true;
        });
        if (!$created) {
            return new Response($translator->trans('Dieses Mitglied ist bereits gebannt.'), Response::HTTP_CONFLICT, $headers + [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $this->addFlash('success', $translator->trans('Das Mitglied wurde gebannt.'));

        return $this->noStoreRedirect('admin_member_edit', ['id' => $account->getId()]);
    }

    #[Route('/bans/{id}/revoke', name: 'ban_revoke', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function revokeBan(
        string $id,
        Request $request,
        MemberAccountBanRepository $bans,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (!$this->isCsrfTokenValid('member_account_ban_revoke_'.$id, $request->request->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN, $headers);
        }

        $ban = $bans->find($id);
        if (!$ban instanceof MemberAccountBan) {
            throw $this->createNotFoundException('Mitgliedersperre wurde nicht gefunden.');
        }

        $admin = $this->getUser();
        if (!$admin instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        $revoked = $entityManager->getConnection()->transactional(function () use ($ban, $bans, $admin, $entityManager): bool {
            $member = $ban->getMember();
            $entityManager->refresh($member, LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($ban, LockMode::PESSIMISTIC_WRITE);
            $currentBan = $bans->findActiveForMember($member, true);
            if (!$ban->isActive() || null === $currentBan || $currentBan->getId() !== $ban->getId()) {
                return false;
            }

            $ban->revoke($admin);
            $member->setActive($ban->wasActive());
            $entityManager->flush();

            return true;
        });
        if (!$revoked) {
            return new Response($translator->trans('Diese Mitgliedersperre ist nicht mehr aktiv.'), Response::HTTP_CONFLICT, $headers + [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $this->addFlash('success', $translator->trans('Die Mitgliedersperre wurde aufgehoben.'));

        return $this->noStoreRedirect('admin_member_ban_index');
    }

    #[Route('/bans', name: 'ban_index', methods: ['GET'])]
    public function bans(Request $request, MemberAccountBanRepository $bans): Response
    {
        $rawSearch = $request->query->get('q');
        $search = is_string($rawSearch) && 1 === preg_match('//u', $rawSearch)
            ? mb_substr(trim($rawSearch), 0, 100, 'UTF-8')
            : '';
        $requestedPage = $request->query->get('page');
        $page = is_string($requestedPage) && ctype_digit($requestedPage) ? (int) $requestedPage : 1;
        $page = max(1, min(100000, $page));
        $total = $bans->countActiveForAdmin($search);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pageCount);

        return $this->noStore($this->render('admin/members/bans.html.twig', [
            'bans' => $bans->findActiveForAdmin($search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
        ]));
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(
        Request $request,
        MemberAccountRepository $members,
        EntityManagerInterface $entityManager,
        MemberAccountCsvWriter $csvWriter,
    ): Response {
        $search = mb_substr(trim($request->query->getString('q')), 0, 100, 'UTF-8');
        $status = $request->query->getString('status');
        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            $status = 'all';
        }
        $active = 'active' === $status ? true : ('suspended' === $status ? false : null);
        $banned = 'banned' === $status ? true : (in_array($status, ['active', 'suspended'], true) ? false : null);
        $total = $members->countForAdmin($search, $active, $banned);

        $locale = $request->getLocale();
        $response = new StreamedResponse(function () use ($members, $entityManager, $csvWriter, $search, $active, $banned, $total, $locale): void {
            $output = fopen('php://output', 'wb');
            if (false === $output) {
                return;
            }

            $csvWriter->write(
                $this->exportAccounts($members, $entityManager, $search, $total, $active, $banned),
                $output,
                $locale,
            );
            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="arcanorum-mitglieder.csv"');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/export-contacts', name: 'export_contacts', methods: ['GET'])]
    public function exportContacts(
        Request $request,
        MemberAccountRepository $members,
        EntityManagerInterface $entityManager,
        MemberAccountCsvWriter $csvWriter,
    ): Response {
        $search = mb_substr(trim($request->query->getString('q')), 0, 100, 'UTF-8');
        $status = $request->query->getString('status');
        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            $status = 'all';
        }
        $active = 'active' === $status ? true : ('suspended' === $status ? false : null);
        $banned = 'banned' === $status ? true : (in_array($status, ['active', 'suspended'], true) ? false : null);
        $total = $members->countForAdmin($search, $active, $banned);

        $response = new StreamedResponse(function () use ($members, $entityManager, $csvWriter, $search, $active, $banned, $total): void {
            $output = fopen('php://output', 'wb');
            if (false === $output) {
                return;
            }

            $csvWriter->writeOutlookContacts(
                $this->exportAccounts($members, $entityManager, $search, $total, $active, $banned),
                $output,
            );
            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="arcanorum-mitglieder-kontakte.csv"');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, MemberAccountRepository $members): Response
    {
        $search = mb_substr(trim($request->query->getString('q')), 0, 100, 'UTF-8');
        $status = $request->query->getString('status');
        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            $status = 'all';
        }
        $active = 'active' === $status ? true : ('suspended' === $status ? false : null);
        $banned = 'banned' === $status ? true : (in_array($status, ['active', 'suspended'], true) ? false : null);
        $requestedPage = $request->query->getString('page');
        $page = ctype_digit($requestedPage) ? (int) $requestedPage : 1;
        $page = max(1, min(100000, $page));

        $total = $members->countForAdmin($search, $active, $banned);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pageCount);
        $accounts = $members->findForAdmin(
            search: $search,
            limit: self::PAGE_SIZE,
            offset: ($page - 1) * self::PAGE_SIZE,
            active: $active,
            banned: $banned,
        );

        $response = $this->render('admin/members/index.html.twig', [
            'accounts' => $accounts,
            'search' => $search,
            'status' => $status,
            'page' => $page,
            'page_count' => $pageCount,
            'total' => $total,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function issueInvitation(MemberAccount $account): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ->modify('+'.self::INVITATION_TTL_HOURS.' hours');
        $account->setInvitation(hash('sha256', $code), $expiresAt);

        return $code;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** @param array<string, string|int> $parameters */
    private function noStoreRedirect(string $route, array $parameters = []): Response
    {
        return $this->noStore($this->redirectToRoute($route, $parameters));
    }

    /**
     * @return \Generator<int, MemberAccount>
     */
    private function exportAccounts(
        MemberAccountRepository $members,
        EntityManagerInterface $entityManager,
        string $search,
        int $total,
        ?bool $active,
        ?bool $banned,
    ): \Generator {
        for ($offset = 0; $offset < $total; $offset += 500) {
            foreach ($members->findForAdmin($search, 500, $offset, $active, $banned) as $account) {
                yield $account;
            }

            $entityManager->clear();
        }
    }
}
