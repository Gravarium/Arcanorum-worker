<?php

declare(strict_types=1);

namespace App\Module\Series\Controller;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Entity\SeriesEpisode;
use App\Entity\Video;
use App\Repository\ContentSeriesRepository;
use App\Repository\SeriesEpisodeRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/episodes', name: 'admin_episode_')]
#[IsGranted('ROLE_ADMIN')]
final class SeriesEpisodeAdminController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        SeriesEpisodeRepository $episodes,
        ContentSeriesRepository $series,
    ): Response {
        $search = mb_substr(trim(self::input($request->query, 'q')), 0, 100);
        $seriesId = self::input($request->query, 'series');
        $seriesFilter = null;
        if (1 === preg_match('/\\A[a-f0-9]{32}\\z/D', $seriesId)) {
            $candidate = $series->find($seriesId);
            $seriesFilter = $candidate instanceof ContentSeries ? $candidate : null;
        }

        return $this->render('admin/episodes/index.html.twig', [
            'episodes' => $episodes->findForAdmin($search, $seriesFilter),
            'series_options' => $series->findForAdmin(),
            'series_filter' => $seriesFilter,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ContentSeriesRepository $series,
        SeriesEpisodeRepository $episodes,
        VideoRepository $videos,
        EntityManagerInterface $entityManager,
    ): Response {
        $defaultSeries = null;
        $seriesId = self::input($request->query, 'series');
        if (1 === preg_match('/\\A[a-f0-9]{32}\\z/D', $seriesId)) {
            $candidate = $series->find($seriesId);
            $defaultSeries = $candidate instanceof ContentSeries ? $candidate : null;
        }

        return $this->editForm($request, null, $defaultSeries, $series, $episodes, $videos, $entityManager);
    }

    #[Route(
        '/{id}/edit',
        name: 'edit',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET', 'POST'],
    )]
    public function edit(
        string $id,
        Request $request,
        SeriesEpisodeRepository $episodes,
        ContentSeriesRepository $series,
        VideoRepository $videos,
        EntityManagerInterface $entityManager,
    ): Response {
        $episode = $episodes->find($id);
        if (!$episode instanceof SeriesEpisode) {
            throw $this->createNotFoundException($this->translator->trans('Folge wurde nicht gefunden.'));
        }

        return $this->editForm($request, $episode, $episode->getSeries(), $series, $episodes, $videos, $entityManager);
    }

    private function editForm(
        Request $request,
        ?SeriesEpisode $episode,
        ?ContentSeries $defaultSeries,
        ContentSeriesRepository $series,
        SeriesEpisodeRepository $episodes,
        VideoRepository $videos,
        EntityManagerInterface $entityManager,
    ): Response {
        $values = [
            'series_id' => $episode?->getSeries()->getId() ?? $defaultSeries?->getId() ?? '',
            'video_id' => $episode?->getVideo()->getId() ?? '',
            'season_number' => null === $episode?->getSeasonNumber() ? '' : (string) $episode->getSeasonNumber(),
            'episode_number' => null === $episode?->getEpisodeNumber() ? '' : (string) $episode->getEpisodeNumber(),
            'title_override' => $episode?->getTitleOverride() ?? '',
            'description_override' => $episode?->getDescriptionOverride() ?? '',
            'status' => $episode?->getStatus()->value ?? PublishingStatus::Published->value,
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_episode_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'series_id' => self::input($request->request, 'series_id'),
                'video_id' => self::input($request->request, 'video_id'),
                'season_number' => self::input($request->request, 'season_number'),
                'episode_number' => self::input($request->request, 'episode_number'),
                'title_override' => trim(self::input($request->request, 'title_override')),
                'description_override' => self::input($request->request, 'description_override'),
                'status' => self::input($request->request, 'status'),
            ];

            $seriesItem = 1 === preg_match('/\\A[a-f0-9]{32}\\z/D', $values['series_id'])
                ? $series->find($values['series_id'])
                : null;
            $video = 1 === preg_match('/\\A[a-f0-9]{32}\\z/D', $values['video_id'])
                ? $videos->find($values['video_id'])
                : null;
            if (!$seriesItem instanceof ContentSeries) {
                $errors[] = $this->translator->trans('Bitte wähle eine vorhandene Serie.');
            }
            if (!$video instanceof Video) {
                $errors[] = $this->translator->trans('Bitte wähle ein vorhandenes Video.');
            }

            $seasonNumber = ctype_digit($values['season_number']) ? (int) $values['season_number'] : 0;
            $episodeNumber = ctype_digit($values['episode_number']) ? (int) $values['episode_number'] : 0;
            $status = PublishingStatus::tryFrom($values['status']);
            $titleOverride = '' === $values['title_override'] ? null : $values['title_override'];
            $descriptionOverride = '' === trim($values['description_override']) ? null : trim($values['description_override']);
            if ($seasonNumber < 1 || $seasonNumber > 999) {
                $errors[] = $this->translator->trans('Die Staffelnummer muss zwischen 1 und 999 liegen.');
            }
            if ($episodeNumber < 1 || $episodeNumber > 999) {
                $errors[] = $this->translator->trans('Die Folgenummer muss zwischen 1 und 999 liegen.');
            }
            if (null === $status) {
                $errors[] = $this->translator->trans('Bitte wähle einen gültigen Veröffentlichungsstatus.');
            }
            if (mb_strlen($values['title_override']) > 180) {
                $errors[] = $this->translator->trans('Der Folgen-Titel darf höchstens 180 Zeichen lang sein.');
            }
            if (mb_strlen($values['description_override']) > 20000) {
                $errors[] = $this->translator->trans('Die Folgen-Beschreibung darf höchstens 20.000 Zeichen lang sein.');
            }
            if (1 !== preg_match('//u', $values['title_override'])
                || 1 !== preg_match('//u', $values['description_override'])
                || 1 === preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', $values['title_override'])
                || 1 === preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u', $values['description_override'])
            ) {
                $errors[] = $this->translator->trans('Folgen-Metadaten dürfen keine unzulässigen Steuerzeichen enthalten.');
            }

            if ([] === $errors) {
                $position = $episodes->findAtPosition($seriesItem, $seasonNumber, $episodeNumber);
                if (null !== $position && (null === $episode || $position->getId() !== $episode->getId())) {
                    $errors[] = $this->translator->trans('Diese Staffel- und Folgenummer ist bereits belegt.');
                }
                $videoAssignment = $episodes->findForVideo($video);
                if (null !== $videoAssignment && (null === $episode || $videoAssignment->getId() !== $episode->getId())) {
                    $errors[] = $this->translator->trans('Dieses Video ist bereits einer Folge zugeordnet.');
                }
            }

            if ([] === $errors) {
                if (null === $episode) {
                    $episode = new SeriesEpisode(
                        $seriesItem,
                        $video,
                        $seasonNumber,
                        $episodeNumber,
                        $titleOverride,
                        $descriptionOverride,
                        $status,
                    );
                } else {
                    $episode->revise(
                        $seriesItem,
                        $video,
                        $seasonNumber,
                        $episodeNumber,
                        $titleOverride,
                        $descriptionOverride,
                        $status,
                    );
                }
                $entityManager->persist($episode);
                $entityManager->flush();
                $this->addFlash('success', $this->translator->trans('Folge wurde gespeichert.'));

                return $this->redirectToRoute('admin_episode_index', ['series' => $seriesItem->getId()]);
            }
        }

        return $this->render('admin/episodes/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => null !== $episode,
            'series_options' => $series->findForAdmin(),
            'video_options' => $videos->findForAdmin('', null, limit: 1000),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route(
        '/{id}/delete',
        name: 'delete',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function delete(
        string $id,
        Request $request,
        SeriesEpisodeRepository $episodes,
        EntityManagerInterface $entityManager,
    ): Response {
        $episode = $episodes->find($id);
        if (!$episode instanceof SeriesEpisode) {
            throw $this->createNotFoundException($this->translator->trans('Folge wurde nicht gefunden.'));
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_episode_delete_'.$episode->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $seriesId = $episode->getSeries()->getId();
        $entityManager->remove($episode);
        $entityManager->flush();
        $this->addFlash('success', $this->translator->trans('Folgenzuordnung wurde entfernt.'));

        return $this->redirectToRoute('admin_episode_index', ['series' => $seriesId]);
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->get($key);

        return is_string($value) ? $value : '';
    }
}
