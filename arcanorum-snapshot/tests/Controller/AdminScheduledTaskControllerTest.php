<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskRunStatus;
use App\Entity\ScheduledTaskType;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\ScheduledTaskRepository;
use App\Repository\ScheduledTaskRunRepository;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminScheduledTaskControllerTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $testKey = base64_encode(str_repeat('A', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$testKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $testKey;

        parent::setUpBeforeClass();
    }

    public function testAnonymousVisitorsAndModeratorsCannotOpenAutomationAdministration(): void
    {
        $anonymous = static::createClient();
        $anonymous->request('GET', '/admin/automation');
        self::assertResponseRedirects('/admin/login');

        static::ensureKernelShutdown();
        [$moderator, $email] = $this->authenticatedClient(AdminRole::VideoModerator);
        try {
            $moderator->request('GET', '/admin/automation');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $this->removeFixtures($moderator, $email);
        }
    }

    public function testAdministratorCanCreateUpdatePauseAndDeleteATaskWithoutDeletingItsRunHistory(): void
    {
        [$client, $email] = $this->authenticatedClient(AdminRole::Administrator);
        $taskName = 'Sitemap-Abnahme-'.bin2hex(random_bytes(5));
        $updatedName = $taskName.'-aktualisiert';
        $taskKey = null;
        $runId = null;

        try {
            $client->request('GET', '/admin/automation');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorTextContains('body', 'Externe Videoquellen prüfen');

            $systemTask = $this->tasks($client)->findByTaskKey('video-source-health-check');
            self::assertInstanceOf(ScheduledTask::class, $systemTask);
            $client->request('GET', '/admin/automation/setup');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Private Scheduler-Einrichtung');

            $client->request('GET', '/admin/automation');
            $createForm = $client->getCrawler()->filter('form[action="/admin/automation/create"]')->eq(1);
            $createToken = (string) $createForm->filter('input[name="_token"]')->attr('value');
            self::assertNotSame('', $createToken);
            $client->request('POST', '/admin/automation/create', [
                '_token' => $createToken,
                'type' => ScheduledTaskType::SitemapRefresh->value,
                'name' => $taskName,
                'interval_minutes' => '60',
            ]);
            self::assertResponseRedirects('/admin/automation');

            $task = $this->tasks($client)->findOneBy(['name' => $taskName]);
            self::assertInstanceOf(ScheduledTask::class, $task);
            $taskKey = $task->getTaskKey();
            $taskId = $task->getId();

            $client->request('GET', '/admin/automation');
            $updateForm = $client->getCrawler()->filter(sprintf('form[action="/admin/automation/%s"]', $taskId));
            $updateToken = (string) $updateForm->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/automation/'.$taskId, [
                '_token' => $updateToken,
                'type' => ScheduledTaskType::SitemapRefresh->value,
                'name' => $updatedName,
                'interval_minutes' => '30',
            ]);
            self::assertResponseRedirects('/admin/automation');
            $task = $this->tasks($client)->find($taskId);
            self::assertInstanceOf(ScheduledTask::class, $task);
            self::assertSame($updatedName, $task->getName());
            self::assertSame(30, $task->getIntervalMinutes());

            $client->request('GET', '/admin/automation');
            $toggleForm = $client->getCrawler()->filter(sprintf('form[action="/admin/automation/%s/enabled"]', $taskId));
            $toggleToken = (string) $toggleForm->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/automation/'.$taskId.'/enabled', [
                '_token' => $toggleToken,
                'enabled' => '0',
            ]);
            self::assertResponseRedirects('/admin/automation');
            $task = $this->tasks($client)->find($taskId);
            self::assertInstanceOf(ScheduledTask::class, $task);
            self::assertFalse($task->isEnabled());

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $run = new ScheduledTaskRun($task, $now, $now, 'controller-test');
            $run->succeed($run->takeLeaseToken(), 'Sitemap wurde aktualisiert.', $now->modify('+1 second'));
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->persist($run);
            $entityManager->flush();
            $runId = $run->getId();

            $client->request('GET', '/admin/automation');
            self::assertSelectorTextContains('body', 'Sitemap wurde aktualisiert.');
            $deleteForm = $client->getCrawler()->filter(sprintf('form[action="/admin/automation/%s/delete"]', $taskId));
            $deleteToken = (string) $deleteForm->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/admin/automation/'.$taskId.'/delete', ['_token' => $deleteToken]);
            self::assertResponseRedirects('/admin/automation');

            $runs = $client->getContainer()->get(ScheduledTaskRunRepository::class);
            self::assertInstanceOf(ScheduledTaskRunRepository::class, $runs);
            $savedRun = $runs->find($runId);
            self::assertInstanceOf(ScheduledTaskRun::class, $savedRun);
            self::assertNull($savedRun->getTask());
            self::assertSame($taskKey, $savedRun->getTaskKey());
            self::assertSame($updatedName, $savedRun->getTaskName());
            self::assertSame(ScheduledTaskRunStatus::Succeeded, $savedRun->getStatus());
            self::assertNull($entityManager->getConnection()->fetchOne(
                'SELECT task_id FROM scheduled_task_runs WHERE id = :id',
                ['id' => $runId],
            ));
            self::assertFalse($this->tasks($client)->find($taskId) instanceof ScheduledTask);

            $csrf = $client->getContainer()->get(CsrfTokenManagerInterface::class);
            $requestStack = $client->getContainer()->get(RequestStack::class);
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrf);
            self::assertInstanceOf(RequestStack::class, $requestStack);
            $csrfRequest = Request::create('/admin/automation');
            $session = $client->getRequest()->getSession();
            $csrfRequest->setSession($session);
            $requestStack->push($csrfRequest);
            try {
                $systemDeleteToken = $csrf->getToken('admin_automation_delete_'.$systemTask->getId())->getValue();
            } finally {
                $requestStack->pop();
            }
            $session->save();
            $client->request('POST', '/admin/automation/'.$systemTask->getId().'/delete', ['_token' => $systemDeleteToken]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertInstanceOf(ScheduledTask::class, $this->tasks($client)->find($systemTask->getId()));
        } finally {
            $this->removeFixtures($client, $email, $taskKey, $runId);
        }
    }

    public function testCreateRejectsInvalidCsrfUnknownFieldsAndOversizedForms(): void
    {
        [$client, $email] = $this->authenticatedClient(AdminRole::Administrator);
        $taskName = 'Sitemap-invalid-'.bin2hex(random_bytes(5));

        try {
            $client->request('GET', '/admin/automation');
            $form = $client->getCrawler()->filter('form[action="/admin/automation/create"]')->eq(1);
            $token = (string) $form->filter('input[name="_token"]')->attr('value');

            $client->request('POST', '/admin/automation/create', [
                '_token' => 'invalid-token',
                'type' => ScheduledTaskType::SitemapRefresh->value,
                'name' => $taskName,
                'interval_minutes' => '60',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('POST', '/admin/automation/create', [
                '_token' => $token,
                'type' => ScheduledTaskType::SitemapRefresh->value,
                'name' => $taskName,
                'interval_minutes' => '60',
                'unexpected' => 'must-not-be-saved',
            ]);
            self::assertResponseRedirects('/admin/automation');
            self::assertNull($this->tasks($client)->findOneBy(['name' => $taskName]));

            $client->request('POST', '/admin/automation/create', [
                '_token' => $token,
                'type' => ScheduledTaskType::SitemapRefresh->value,
                'name' => str_repeat('x', 8200),
                'interval_minutes' => '60',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            self::assertNull($this->tasks($client)->findOneBy(['name' => $taskName]));
        } finally {
            $this->removeFixtures($client, $email);
        }
    }

    /** @return array{0: KernelBrowser, 1: string} */
    private function authenticatedClient(AdminRole $role): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $email = 'automation-controller-'.bin2hex(random_bytes(8)).'@example.test';
        $user = new AdminUser($email);
        $user->setRoles([$role->value]);
        $password = bin2hex(random_bytes(24));
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user->setPassword($hasher->hashPassword($user, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($user);

        $encryptedSecret = $user->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $email,
            '_password' => $password,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
        self::assertResponseRedirects('/admin');

        return [$client, $email];
    }

    private function tasks(KernelBrowser $client): ScheduledTaskRepository
    {
        $repository = $client->getContainer()->get(ScheduledTaskRepository::class);
        self::assertInstanceOf(ScheduledTaskRepository::class, $repository);

        return $repository;
    }

    private function removeFixtures(KernelBrowser $client, string $email, ?string $taskKey = null, ?string $runId = null): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        if (null !== $runId) {
            $connection->delete('scheduled_task_runs', ['id' => $runId]);
        }
        if (null !== $taskKey) {
            $connection->delete('scheduled_task_runs', ['task_key' => $taskKey]);
            $connection->delete('scheduled_tasks', ['task_key' => $taskKey]);
        }
        $connection->delete('admin_users', ['email' => $email]);
        $entityManager->clear();
    }
}
