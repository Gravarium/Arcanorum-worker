<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\FeatureModule;
use App\Entity\MemberActivityType;
use App\Entity\SiteSettings;
use App\Module\Settings\Service\ModuleAvailability;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings/modules', name: 'admin_module_settings_')]
final class AdminModuleSettingsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ModuleAvailability $modules, SiteSettingsProvider $settings): Response
    {
        return $this->renderSettings($modules, $settings, []);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(
        Request $request,
        ModuleAvailability $modules,
        SiteSettingsManager $manager,
        SiteSettingsProvider $settings,
        LoggerInterface $logger,
    ): Response {
        $submittedToken = $request->request->get('token');
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_feature_modules', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $input = $request->request->all();
        unset($input['token']);

        $knownFields = [
            ...array_map(static fn (FeatureModule $module): string => $module->inputName(), FeatureModule::cases()),
            'social_following_limit',
            ...array_map(SiteSettings::memberActivityInputName(...), MemberActivityType::cases()),
        ];
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $knownFields, true)) {
                return $this->renderSettings($modules, $settings, $input, [
                    'form' => 'Die Anfrage enthält eine unbekannte Moduleinstellung.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        foreach (array_slice($knownFields, count(FeatureModule::cases())) as $field) {
            if (!array_key_exists($field, $input)) {
                return $this->renderSettings($modules, $settings, $input, [
                    $field => 'Bitte geben Sie alle Community-Einstellungen an.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $errors = $modules->unavailableEnableErrors($input);
        if ([] !== $errors) {
            return $this->renderSettings($modules, $settings, $input, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $errors = $manager->updateFeatureModules($input);
        } catch (DbalException $exception) {
            $logger->error('Integrierte Module konnten nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->renderSettings($modules, $settings, $input, [
                'form' => 'Die Moduleinstellungen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ([] !== $errors) {
            return $this->renderSettings($modules, $settings, $input, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Die Moduleinstellungen wurden gespeichert. Deaktivierte Module behalten ihre Inhalte.');

        return $this->privateResponse($this->redirectToRoute('admin_module_settings_index'));
    }

    /** @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function renderSettings(
        ModuleAvailability $modules,
        SiteSettingsProvider $settingsProvider,
        array $input,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        $catalog = $modules->configuration();
        $form = [];
        foreach (FeatureModule::cases() as $feature) {
            $field = $feature->inputName();
            $submitted = $input[$field] ?? null;
            $fallback = false;
            foreach ($catalog as $module) {
                if ($module['id'] === $feature->value) {
                    $fallback = true === $module['configured_enabled'];

                    break;
                }
            }
            $form[$feature->value] = in_array($submitted, [true, 1, '1'], true)
                ? '1'
                : (in_array($submitted, [false, 0, '0'], true) ? '0' : ($fallback ? '1' : '0'));
        }
        $settings = $settingsProvider->current();
        $submittedLimit = $input['social_following_limit'] ?? null;
        $form['social_following_limit'] = is_string($submittedLimit) && 1 === preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', $submittedLimit)
            ? $submittedLimit
            : (string) $settings->getSocialFollowingLimit();
        foreach (MemberActivityType::cases() as $activityType) {
            $field = SiteSettings::memberActivityInputName($activityType);
            $submitted = $input[$field] ?? null;
            $form[$field] = in_array($submitted, [true, 1, '1'], true)
                ? '1'
                : (in_array($submitted, [false, 0, '0'], true) ? '0' : ($settings->isMemberActivityEnabled($activityType) ? '1' : '0'));
        }

        return $this->privateResponse($this->render('admin/settings/modules.html.twig', [
            'modules' => $catalog,
            'form' => $form,
            'errors' => $errors,
        ], new Response(status: $status)));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
