<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Module\Member\Service\VideoEmailShareSender;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\VideoRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MEMBER')]
final class MemberVideoEmailShareController extends AbstractController
{
    #[Route(
        '/konto/videos/{id}/teilen',
        name: 'member_video_email_share',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function send(
        string $id,
        Request $request,
        VideoRepository $videos,
        VideoEmailShareSender $sender,
        #[Autowire(service: 'limiter.member_video_email_share_account')] RateLimiterFactory $accountLimiter,
        #[Autowire(service: 'limiter.member_video_email_share_ip')] RateLimiterFactory $ipLimiter,
        RateLimitKeyFactory $rateLimitKeys,
        LoggerInterface $logger,
    ): Response {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount || !$member->isActive() || !$member->isEmailVerified()) {
            return new Response('', Response::HTTP_FORBIDDEN, self::privateHeaders());
        }

        $video = $videos->findPublishedById($id);
        if (null === $video) {
            return new Response('', Response::HTTP_NOT_FOUND, self::privateHeaders());
        }

        $payload = $request->request->all();
        if ([] !== array_diff(array_keys($payload), ['_token', 'email', 'consent'])) {
            return new Response('', Response::HTTP_BAD_REQUEST, self::privateHeaders());
        }

        $token = $payload['_token'] ?? null;
        if (!is_string($token) || '' === $token || !$this->isCsrfTokenValid('member_video_email_share_'.$video->getId(), $token)) {
            return new Response('', Response::HTTP_FORBIDDEN, self::privateHeaders());
        }

        $rawEmail = $payload['email'] ?? null;
        $email = is_string($rawEmail) ? trim($rawEmail) : '';
        if (!is_string($rawEmail)
            || strlen($rawEmail) > 254
            || 1 !== preg_match('//u', $email)
            || 1 === preg_match('/[\x00-\x20\x7F]/', $email)
            || false === filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $this->addFlash('video_email_share_error', 'Bitte gib eine gültige Empfängeradresse ein.');

            return $this->redirectToVideo($id);
        }
        if ('1' !== ($payload['consent'] ?? null)) {
            $this->addFlash('video_email_share_error', 'Bitte bestätige, dass du die Erlaubnis hast, den Videolink zu senden.');

            return $this->redirectToVideo($id);
        }
        if (!$sender->isReady()) {
            $this->addFlash('video_email_share_error', 'Der E-Mail-Versand ist derzeit nicht verfügbar.');

            return $this->redirectToVideo($id);
        }

        try {
            $accountKey = $rateLimitKeys->forIdentity($member->getId(), 'member-video-email-share-account');
            $ipKey = $rateLimitKeys->forIp($request, 'member-video-email-share-ip');
            $accountLimit = $accountLimiter->create($accountKey)->consume(1);
            $ipLimit = $ipLimiter->create($ipKey)->consume(1);
        } catch (\Throwable $exception) {
            $logger->warning('Video email share rate limiting was unavailable.', [
                'exception_class' => $exception::class,
            ]);
            $this->addFlash('video_email_share_error', 'Der E-Mail-Versand ist derzeit nicht verfügbar.');

            return $this->redirectToVideo($id);
        }

        if (!$accountLimit->isAccepted() || !$ipLimit->isAccepted()) {
            $this->addFlash('video_email_share_error', 'Bitte warte, bevor du weitere Videolinks per E-Mail sendest.');

            return $this->redirectToVideo($id);
        }

        try {
            $sender->send($video, $email);
        } catch (\Throwable $exception) {
            $logger->warning('Video email share delivery failed.', [
                'exception_class' => $exception::class,
            ]);
            $this->addFlash('video_email_share_error', 'Der Videolink konnte derzeit nicht per E-Mail versendet werden.');

            return $this->redirectToVideo($id);
        }

        $this->addFlash('video_email_share_success', 'Der Videolink wurde per E-Mail versendet.');

        return $this->redirectToVideo($id);
    }

    private function redirectToVideo(string $id): RedirectResponse
    {
        $response = $this->redirectToRoute('video_show', ['id' => $id, '_fragment' => 'video-email-share']);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** @return array<string, string> */
    private static function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
