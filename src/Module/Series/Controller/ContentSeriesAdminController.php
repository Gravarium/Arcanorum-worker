<?php

declare(strict_types=1);

namespace App\Module\Series\Controller;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentSeriesRepository;
use App\Repository\SeriesEpisodeRepository;
use App\Service\SeriesArtworkUploadService;
use App\Service\SeriesGenreManager;
use App\Service\SeriesGenreParser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/series', name: 'admin_series_')]
#[IsGranted('ROLE_ADMIN')]
final class ContentSeriesAdminController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        ContentSeriesRepository $series,
        SeriesEpisodeRepository $episodes,
    ): Response {
        $search = mb_substr(trim(self::input($request->query, 'q')), 0, 100);
        $status = PublishingStatus::tryFrom(self::input($request->query, 'status'));
        $items = $series->findForAdmin($search, $status);
        $episodeCounts = [];
        foreach ($items as $item) {
            $episodeCounts[$item->getId()] = $episodes->countForSeries($item);
        }

        return $this->render('admin/series/index.html.twig', [
            'series' => $items,
            'episode_counts' => $episodeCounts,
            'search' => $search,
            'status' => $status instanceof PublishingStatus ? $status->value : '',
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ContentSeriesRepository $series,
        SeriesGenreManager $genreManager,
        EntityManagerInterface $entityManager,
    ): Response {
        return $this->editForm($request, null, $series, $genreManager, $entityManager);
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
        ContentSeriesRepository $series,
        SeriesGenreManager $genreManager,
        EntityManagerInterface $entityManager,
    ): Response {
        $item = $series->find($id);
        if (!$item instanceof ContentSeries) {
            throw $this->createNotFoundException($this->translator->trans('Serie wurde nicht gefunden.'));
        }

        return $this->editForm($request, $item, $series, $genreManager, $entityManager);
    }

    private function editForm(
        Request $request,
        ?ContentSeries $seriesItem,
        ContentSeriesRepository $series,
        SeriesGenreManager $genreManager,
        EntityManagerInterface $entityManager,
    ): Response {
        $isEdit = null !== $seriesItem;
        $values = [
            'title' => $seriesItem?->getTitle() ?? '',
            'slug' => $seriesItem?->getSlug() ?? '',
            'description' => $seriesItem?->getDescription() ?? '',
            'genres' => implode(', ', $seriesItem?->getGenres() ?? []),
            'status' => $seriesItem?->getStatus()->value ?? PublishingStatus::Draft->value,
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_series_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'title' => trim(self::input($request->request, 'title')),
                'slug' => trim(self::input($request->request, 'slug')),
                'description' => self::input($request->request, 'description'),
                'genres' => self::input($request->request, 'genres'),
                'status' => self::input($request->request, 'status'),
            ];
            $status = PublishingStatus::tryFrom($values['status']);
            $genres = [];
            try {
                $genres = SeriesGenreParser::parseNames($values['genres']);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $this->translator->trans($exception->getMessage());
            }

            if ('' === $values['title'] || mb_strlen($values['title']) > 180) {
                $errors[] = $this->translator->trans('Der Serientitel ist erforderlich und darf höchstens 180 Zeichen lang sein.');
            }
            if ('' === $values['slug'] || mb_strlen($values['slug']) > 200
                || 1 !== preg_match('/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/D', $values['slug'])
            ) {
                $errors[] = $this->translator->trans('Der URL-Name darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.');
            }
            if (mb_strlen($values['description']) > 50000) {
                $errors[] = $this->translator->trans('Die Serienbeschreibung darf höchstens 50.000 Zeichen lang sein.');
            }
            if (null === $status) {
                $errors[] = $this->translator->trans('Bitte wähle einen gültigen Veröffentlichungsstatus.');
            }

            $existing = '' === $values['slug'] ? null : $series->findOneBySlug($values['slug']);
            if (null !== $existing && (null === $seriesItem || $existing->getId() !== $seriesItem->getId())) {
                $errors[] = $this->translator->trans('Für diesen URL-Namen gibt es bereits eine Serie.');
            }

            if ([] === $errors) {
                $resolvedGenres = $genreManager->resolve($genres);
                if (null === $seriesItem) {
                    $seriesItem = new ContentSeries(
                        $values['title'],
                        $values['slug'],
                        $values['description'],
                        $status,
                        $resolvedGenres,
                    );
                } else {
                    $seriesItem->revise(
                        $values['title'],
                        $values['slug'],
                        $values['description'],
                        $status,
                        $resolvedGenres,
                    );
                }

                try {
                    $entityManager->persist($seriesItem);
                    $entityManager->flush();
                } catch (UniqueConstraintViolationException) {
                    $errors[] = $this->translator->trans('Ein Genre oder der URL-Name wurde inzwischen bereits vergeben. Bitte lade das Formular neu.');
                }
                if ([] !== $errors) {
                    return $this->render('admin/series/form.html.twig', [
                        'values' => $values,
                        'errors' => $errors,
                        'is_edit' => $isEdit,
                        'series' => $seriesItem,
                    ], new Response(status: Response::HTTP_CONFLICT));
                }
                $this->addFlash('success', $this->translator->trans('Serie wurde gespeichert.'));

                return $this->redirectToRoute('admin_series_index');
            }
        }

        return $this->render('admin/series/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => null !== $seriesItem,
            'series' => $seriesItem,
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
        ContentSeriesRepository $series,
        SeriesEpisodeRepository $episodes,
        SeriesArtworkUploadService $artworks,
        EntityManagerInterface $entityManager,
    ): Response {
        $item = $series->find($id);
        if (!$item instanceof ContentSeries) {
            throw $this->createNotFoundException($this->translator->trans('Serie wurde nicht gefunden.'));
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_series_delete_'.$item->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }
        if ($episodes->countForSeries($item) > 0) {
            $this->addFlash('error', $this->translator->trans('Die Serie enthält noch Folgen und kann nicht gelöscht werden.'));

            return $this->redirectToRoute('admin_series_index');
        }

        $artworkObjectId = $item->getArtworkObjectId();
        $entityManager->remove($item);
        $entityManager->flush();
        if (null !== $artworkObjectId) {
            try {
                $artworks->delete($artworkObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash(
                    'warning',
                    $this->translator->trans('Die Serie wurde gelöscht, aber die private Bilddatei konnte nicht bereinigt werden.'),
                );
            }
        }
        $this->addFlash('success', $this->translator->trans('Serie wurde gelöscht.'));

        return $this->redirectToRoute('admin_series_index');
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
