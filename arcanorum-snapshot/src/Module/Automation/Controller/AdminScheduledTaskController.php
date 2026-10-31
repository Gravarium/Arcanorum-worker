<?php

declare(strict_types=1);

namespace App\Module\Automation\Controller;

use App\Entity\ContentCategoryType;
use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;
use App\Module\Automation\Application\ScheduledTaskConfigurationValidator;
use App\Module\Automation\Application\SchedulerRequestAuthenticator;
use App\Repository\ContentCategoryRepository;
use App\Repository\ScheduledTaskRepository;
use App\Repository\ScheduledTaskRunRepository;
use App\Repository\VideoRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminScheduledTaskController extends AbstractController
{
    private const int MAX_TASKS = 50;
    private const int MAX_FORM_BYTES = 8192;

    #[Route('/admin/automation', name: 'admin_automation_index', methods: ['GET'])]
    public function index(
        ScheduledTaskRepository $tasks,
        ScheduledTaskRunRepository $runs,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
    ): Response {
        $response = $this->render('admin/automation/index.html.twig', [
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'maxTasks' => self::MAX_TASKS,
            'runs' => $runs->findRecent(100),
            'tasks' => $tasks->findForAdmin(),
            'unavailableVideos' => $videos->findUnavailableExternalSources(100),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/admin/automation/setup', name: 'admin_automation_setup', methods: ['GET'])]
    public function setup(SchedulerRequestAuthenticator $authenticator): Response
    {
        $response = $this->render('admin/automation/setup.html.twig', ['configured' => $authenticator->isConfigured()]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/admin/automation/create', name: 'admin_automation_create', methods: ['POST'])]
    public function create(
        Request $request,
        Connection $connection,
        EntityManagerInterface $entityManager,
        ScheduledTaskRepository $tasks,
        ContentCategoryRepository $categories,
        ScheduledTaskConfigurationValidator $validator,
    ): Response {
        $values = $request->request->all();
        if ($this->isFormTooLarge($request)) {
            return self::requestTooLargeResponse();
        }
        $this->requireCsrf($values, 'admin_automation_create');
        try {
            $type = ScheduledTaskType::tryFrom(self::string($values, 'type'));
            if (!$type instanceof ScheduledTaskType || $type->isSystemOnly()) {
                throw new \InvalidArgumentException('Dieser Auftragstyp kann nicht manuell angelegt werden.');
            }
            $name = self::string($values, 'name');
            $interval = self::interval($values);
            if (ScheduledTaskType::ProviderImport === $type) {
                self::assertFields($values, ['_token', 'type', 'name', 'interval_minutes', 'provider', 'mode', 'query', 'channel_id', 'category', 'exclude_terms']);
                $configuration = $validator->normalize($type, [
                    'provider' => self::string($values, 'provider'),
                    'mode' => self::string($values, 'mode'),
                    'query' => self::string($values, 'query'),
                    'channel_id' => self::string($values, 'channel_id'),
                    'category' => self::string($values, 'category'),
                    'exclude_terms' => self::terms($values),
                ]);
                $category = $configuration['category'] ?? null;
                if (is_string($category) && null === $categories->findForTypeAndName(ContentCategoryType::Video, $category)) {
                    throw new \InvalidArgumentException('Die Zielkategorie ist nicht mehr vorhanden.');
                }
            } else {
                self::assertFields($values, ['_token', 'type', 'name', 'interval_minutes']);
                $configuration = $validator->normalize($type, []);
            }

            $task = ScheduledTask::create($type, $name, $interval, $configuration, self::now());
            $connection->transactional(function (Connection $transaction) use ($tasks, $entityManager, $task): void {
                $transaction->fetchOne('SELECT pg_advisory_xact_lock(:lock_key)', ['lock_key' => 73521942]);
                if ($tasks->countAll() >= self::MAX_TASKS) {
                    throw new \InvalidArgumentException('Die Höchstzahl an Aufgaben ist erreicht.');
                }
                $entityManager->persist($task);
                $entityManager->flush();
            });
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_automation_index');
        }

        $this->addFlash('success', 'Die Aufgabe wurde angelegt.');

        return $this->redirectToRoute('admin_automation_index');
    }

    #[Route('/admin/automation/{id}', name: 'admin_automation_update', methods: ['POST'])]
    public function update(
        string $id,
        Request $request,
        ScheduledTaskRepository $tasks,
        ContentCategoryRepository $categories,
        ScheduledTaskConfigurationValidator $validator,
        EntityManagerInterface $entityManager,
    ): Response {
        $values = $request->request->all();
        if ($this->isFormTooLarge($request)) {
            return self::requestTooLargeResponse();
        }
        $this->requireCsrf($values, 'admin_automation_update_'.$id);
        $task = $tasks->find($id);
        if (!$task instanceof ScheduledTask) {
            throw $this->createNotFoundException();
        }

        try {
            $interval = self::interval($values);
            if ($task->isSystemTask()) {
                self::assertFields($values, ['_token', 'interval_minutes']);
                $task->revise($task->getName(), $interval, $task->getConfiguration(), self::now());
            } elseif (ScheduledTaskType::SitemapRefresh === $task->getType()) {
                self::assertFields($values, ['_token', 'type', 'name', 'interval_minutes']);
                if (self::string($values, 'type') !== $task->getType()->value) {
                    throw new \InvalidArgumentException('Der Aufgabentyp ist nach dem Anlegen unveränderlich.');
                }
                $task->revise(self::string($values, 'name'), $interval, [], self::now());
            } else {
                self::assertFields($values, ['_token', 'type', 'name', 'interval_minutes', 'provider', 'mode', 'query', 'channel_id', 'category', 'exclude_terms']);
                if (self::string($values, 'type') !== $task->getType()->value) {
                    throw new \InvalidArgumentException('Der Aufgabentyp ist nach dem Anlegen unveränderlich.');
                }
                $configuration = $validator->normalize($task->getType(), [
                    'provider' => self::string($values, 'provider'),
                    'mode' => self::string($values, 'mode'),
                    'query' => self::string($values, 'query'),
                    'channel_id' => self::string($values, 'channel_id'),
                    'category' => self::string($values, 'category'),
                    'exclude_terms' => self::terms($values),
                ]);
                $category = $configuration['category'] ?? null;
                if (is_string($category) && null === $categories->findForTypeAndName(ContentCategoryType::Video, $category)) {
                    throw new \InvalidArgumentException('Die Zielkategorie ist nicht mehr vorhanden.');
                }
                $task->revise(self::string($values, 'name'), $interval, $configuration, self::now());
            }
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_automation_index');
        }

        $this->addFlash('success', 'Die Aufgabe wurde aktualisiert.');

        return $this->redirectToRoute('admin_automation_index');
    }

    #[Route('/admin/automation/{id}/enabled', name: 'admin_automation_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request, ScheduledTaskRepository $tasks, EntityManagerInterface $entityManager): Response
    {
        $values = $request->request->all();
        if ($this->isFormTooLarge($request)) {
            return self::requestTooLargeResponse();
        }
        $this->requireCsrf($values, 'admin_automation_toggle_'.$id);
        try {
            self::assertFields($values, ['_token', 'enabled']);
            $enabled = self::string($values, 'enabled');
            if (!in_array($enabled, ['0', '1'], true)) {
                throw new \InvalidArgumentException('Der Aktivierungswert ist ungültig.');
            }
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_automation_index');
        }
        $task = $tasks->find($id);
        if (!$task instanceof ScheduledTask) {
            throw $this->createNotFoundException();
        }

        $task->setEnabled('1' === $enabled, self::now());
        $entityManager->flush();

        return $this->redirectToRoute('admin_automation_index');
    }

    #[Route('/admin/automation/{id}/delete', name: 'admin_automation_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        Connection $connection,
        ScheduledTaskRepository $tasks,
        ScheduledTaskRunRepository $runs,
        EntityManagerInterface $entityManager,
    ): Response {
        $values = $request->request->all();
        if ($this->isFormTooLarge($request)) {
            return self::requestTooLargeResponse();
        }
        $this->requireCsrf($values, 'admin_automation_delete_'.$id);
        try {
            self::assertFields($values, ['_token']);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_automation_index');
        }
        $task = $tasks->find($id);
        if (!$task instanceof ScheduledTask) {
            throw $this->createNotFoundException();
        }
        if ($task->isSystemTask() || $task->getType()->isSystemOnly()) {
            throw $this->createAccessDeniedException();
        }

        $connection->transactional(function () use ($task, $runs, $entityManager): void {
            $runs->detachTaskReferences($task);
            $entityManager->remove($task);
            $entityManager->flush();
        });

        $this->addFlash('success', 'Die Aufgabe wurde gelöscht; die begrenzten Laufprotokolle bleiben erhalten.');

        return $this->redirectToRoute('admin_automation_index');
    }

    /** @param array<string, mixed> $values */
    private function requireCsrf(array $values, string $tokenId): void
    {
        $token = $values['_token'] ?? null;
        if (!is_string($token) || '' === $token || !$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function isFormTooLarge(Request $request): bool
    {
        $length = $request->headers->get('Content-Length');
        if (is_string($length) && ctype_digit($length) && (int) $length > self::MAX_FORM_BYTES) {
            return true;
        }

        $bytes = 0;
        foreach ($request->request->all() as $key => $value) {
            $bytes += strlen($key) + 1;
            if (!is_string($value)) {
                return true;
            }
            $bytes += strlen($value);
            if ($bytes > self::MAX_FORM_BYTES) {
                return true;
            }
        }

        return false;
    }

    private static function requestTooLargeResponse(): Response
    {
        return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /** @param array<string, mixed> $values
     * @param list<string> $allowed
     */
    private static function assertFields(array $values, array $allowed): void
    {
        if ([] !== array_diff(array_keys($values), $allowed)) {
            throw new \InvalidArgumentException('Die Anfrage enthält unbekannte Felder.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Alle Formularwerte müssen einzelne Textwerte sein.');
            }
        }
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? '';
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Ein Formularfeld hat einen ungültigen Typ.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function interval(array $values): int
    {
        $raw = self::string($values, 'interval_minutes');
        if (1 !== preg_match('/\A[0-9]{1,5}\z/D', $raw)) {
            throw new \InvalidArgumentException('Das Intervall muss eine ganze Zahl in Minuten sein.');
        }

        return (int) $raw;
    }

    /** @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private static function terms(array $values): array
    {
        $raw = self::string($values, 'exclude_terms');
        if (mb_strlen($raw, 'UTF-8') > 1600) {
            throw new \InvalidArgumentException('Die Ausschlussliste ist zu lang.');
        }
        $terms = preg_split('/\R/u', $raw);
        if (!is_array($terms)) {
            throw new \InvalidArgumentException('Die Ausschlussliste ist ungültig.');
        }

        return array_values(array_filter(array_map(trim(...), $terms), static fn (string $term): bool => '' !== $term));
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
