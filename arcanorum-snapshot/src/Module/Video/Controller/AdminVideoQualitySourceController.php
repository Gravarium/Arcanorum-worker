<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoQualitySource;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\VideoQualitySourceRepository;
use App\Repository\VideoRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos/{id}/quality-sources', name: 'admin_video_quality_source_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminVideoQualitySourceController extends AbstractController
{
    #[Route('', name: 'save', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function save(
        string $id,
        Request $request,
        VideoRepository $videos,
        VideoQualitySourceRepository $sources,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('admin_video_quality_source_save_'.$video->getId(), self::input($request->request, '_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!self::hasOnlyKeys($request->request->all(), ['_token', 'resolution', 'source_url'])
            || [] !== $request->files->all()
        ) {
            $this->addFlash('error', 'Die Videoqualitätsanfrage enthält nicht erlaubte Felder.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }
        $currentStream = VideoSourceType::Stream === $video->getSourceType()
            ? $streamResolver->resolve($video->getSourceUrl())
            : null;
        if (!in_array($video->getSourceType(), [VideoSourceType::File, VideoSourceType::Stream], true)
            || $video->isAudio()
            || (VideoSourceType::Stream === $video->getSourceType()
                && (null === $currentStream || $currentStream->isAudio() || $currentStream->isHls()))
        ) {
            $this->addFlash('error', 'Qualitätsquellen sind nur für Videodateien und geprüfte Streams verfügbar.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $resolution = VideoQuality::tryFrom(self::input($request->request, 'resolution'));
        $sourceUrl = trim(self::input($request->request, 'source_url'));
        $resolvedSource = $streamResolver->resolve($sourceUrl);
        if (null === $resolution
            || !VideoQualitySource::isValidSourceUrl($sourceUrl)
            || null === $resolvedSource
            || $resolvedSource->isHls()
            || $resolvedSource->isAudio()
        ) {
            $this->addFlash('error', 'Bitte wähle eine feste Qualitätsstufe und eine HTTPS-MP4-, WebM- oder Ogg-Quelle von einem freigegebenen Medienhost.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        try {
            $entityManager->getConnection()->transactional(function (Connection $connection) use (
                $video,
                $resolution,
                $sourceUrl,
                $sources,
                $entityManager,
            ): void {
                $lockKeys = [
                    'video-quality-resolution:'.$video->getId().':'.$resolution->value,
                    'video-quality-source:'.$video->getId().':'.$sourceUrl,
                ];
                sort($lockKeys, SORT_STRING);
                foreach ($lockKeys as $lockKey) {
                    $connection->executeQuery(
                        'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
                        ['lock_key' => $lockKey],
                    );
                }

                $existingUrl = $sources->findForVideoAndSourceUrl($video, $sourceUrl);
                $source = $sources->findForVideoAndResolution($video, $resolution);
                if (null !== $existingUrl && $existingUrl !== $source) {
                    throw new \DomainException('Dieselbe Qualitätsquelle ist bereits für dieses Video hinterlegt.');
                }

                if (null === $source) {
                    $source = new VideoQualitySource($video, $resolution, $sourceUrl);
                } else {
                    $source->revise($resolution, $sourceUrl);
                }

                $entityManager->persist($source);
                $entityManager->flush();
            });
        } catch (\DomainException|UniqueConstraintViolationException) {
            $this->addFlash('error', 'Diese Qualitätsquelle ist bereits für das Video eingetragen.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $this->addFlash('success', 'Videoqualitätsquelle wurde gespeichert.');

        return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
    }

    #[Route('/{sourceId}/delete', name: 'delete', requirements: ['id' => '[a-f0-9]{32}', 'sourceId' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function delete(
        string $id,
        string $sourceId,
        Request $request,
        VideoRepository $videos,
        VideoQualitySourceRepository $sources,
        EntityManagerInterface $entityManager,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }
        $source = $sources->findForVideoAndId($video, $sourceId);
        if (!$source instanceof VideoQualitySource) {
            throw $this->createNotFoundException('Videoqualitätsquelle wurde nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('admin_video_quality_source_delete_'.$source->getId(), self::input($request->request, '_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!self::hasOnlyKeys($request->request->all(), ['_token', 'action'])
            || [] !== $request->files->all()
            || 'delete' !== self::input($request->request, 'action')
        ) {
            $this->addFlash('error', 'Die Löschanfrage für die Videoqualitätsquelle ist ungültig.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $entityManager->remove($source);
        $entityManager->flush();
        $this->addFlash('success', 'Videoqualitätsquelle wurde entfernt.');

        return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->all()[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $allowed
     */
    private static function hasOnlyKeys(array $input, array $allowed): bool
    {
        return [] === array_diff(array_keys($input), $allowed);
    }
}
