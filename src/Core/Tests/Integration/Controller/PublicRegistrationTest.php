<?php

namespace App\Core\Tests\Integration\Controller;

use App\Core\Controller\Panel\Setting\GeneralSettingCrudController;
use App\Core\Controller\Panel\Setting\SecuritySettingCrudController;
use App\Core\Entity\Permission;
use App\Core\Entity\Role;
use App\Core\Entity\Setting;
use App\Core\Entity\User;
use App\Core\Enum\PermissionEnum;
use App\Core\Enum\SettingContextEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Enum\SystemRoleEnum;
use App\Core\Service\SettingService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class PublicRegistrationTest extends WebTestCase
{
    public function testChangingTheSettingRequiresSecurityEditPermission(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get('doctrine')->getManager();
        $settingName = SettingEnum::PUBLIC_REGISTRATION_ENABLED->value;
        $setting = $manager->getRepository(Setting::class)->findOneBy([
            'name' => $settingName,
            'context' => SettingContextEnum::SECURITY->value,
        ]);
        self::assertNotNull($setting);
        $originalValue = $setting->getValue();

        $adminRole = $manager->getRepository(Role::class)->findOneBy(['name' => SystemRoleEnum::ROLE_ADMIN->value]);
        $editorRole = (new Role())->setName('ROLE_TEST_SETTINGS_EDITOR')->setDisplayName('Test settings editor');
        foreach ([PermissionEnum::ACCESS_SETTINGS_GENERAL, PermissionEnum::EDIT_SETTINGS_GENERAL] as $permission) {
            $editorRole->addPermission($manager->getRepository(Permission::class)->findOneBy([
                'code' => $permission->value,
            ]));
        }
        $editor = (new User())
            ->setEmail('registration-setting-editor@example.com')
            ->setName('Test')
            ->setSurname('Editor')
            ->setIsVerified(true)
            ->addUserRole($adminRole);
        $editor->setPassword(static::getContainer()->get('security.password_hasher')->hashPassword($editor, 'password'));

        $manager->persist($editorRole);
        $manager->persist($editor);
        $manager->flush();
        $editorId = $editor->getId();
        $editorRoleId = $editorRole->getId();

        try {
            $client->loginUser($editor);
            $editUrl = '/panel?crudAction=edit&entityId=' . $setting->getId() . '&crudControllerFqcn=';
            $crawler = $client->request('GET', $editUrl . urlencode(SecuritySettingCrudController::class));
            self::assertResponseIsSuccessful();
            $client->submit($crawler->selectButton('Save setting')->form(['Setting[value]' => '0']));
            self::assertResponseRedirects();

            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->clear();
            self::assertFalse(static::getContainer()->get(SettingService::class)->isPublicRegistrationEnabled());

            $editor = $manager->find(User::class, $editorId);
            $editor->setRoles([$manager->find(Role::class, $editorRoleId)]);
            $manager->flush();
            $client->loginUser($editor);

            $crawler = $client->request('GET', $editUrl . urlencode(GeneralSettingCrudController::class));
            self::assertResponseIsSuccessful();
            $client->submit($crawler->selectButton('Save setting')->form(['Setting[value]' => '1']));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            static::getContainer()->get('doctrine')->getManager()->clear();
            self::assertFalse(static::getContainer()->get(SettingService::class)->isPublicRegistrationEnabled());
        } finally {
            $manager = static::getContainer()->get('doctrine')->getManager();
            $manager->clear();
            static::getContainer()->get(SettingService::class)->saveSetting($settingName, $originalValue);
            $manager->remove($manager->find(User::class, $editorId));
            $manager->remove($manager->find(Role::class, $editorRoleId));
            $manager->flush();
        }
    }

    public function testDisablingRegistrationRejectsDirectRequestsAndPreviouslyOpenedForms(): void
    {
        $client = static::createClient();
        $settings = static::getContainer()->get(SettingService::class);
        $setting = SettingEnum::PUBLIC_REGISTRATION_ENABLED->value;
        $originalValue = $settings->getSetting($setting);
        $email = 'disabled-registration@example.com';

        try {
            $settings->saveSetting($setting, '1');
            $crawler = $client->request('GET', '/register');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('input[name="registration_form[_token]"]');

            $form = $crawler->selectButton('register-submit')->form([
                'registration_form[name]' => 'Test',
                'registration_form[surname]' => 'User',
                'registration_form[email]' => $email,
                'registration_form[plainPassword]' => 'SecurePass123!',
                'registration_form[agreeTerms]' => true,
            ]);

            $settings->saveSetting($setting, '0');
            $client->request('GET', '/register');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->submit($form);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertNull(static::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy([
                'email' => $email,
            ]));

            $settings->saveSetting($setting, '1');
            $client->request('GET', '/register');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[name="registration_form"]');
        } finally {
            $settings->saveSetting($setting, $originalValue);
        }
    }

    public function testPublicPagesOnlyLinkToRegistrationWhenEnabled(): void
    {
        $client = static::createClient();
        $settings = static::getContainer()->get(SettingService::class);
        $registrationSetting = SettingEnum::PUBLIC_REGISTRATION_ENABLED->value;
        $landingSetting = SettingEnum::LANDING_PAGE_ENABLED->value;
        $originalRegistrationValue = $settings->getSetting($registrationSetting);
        $originalLandingValue = $settings->getSetting($landingSetting);

        try {
            $settings->saveSetting($landingSetting, '1');
            $settings->saveSetting($registrationSetting, '1');

            foreach (['/login', '/', '/store'] as $url) {
                $client->request('GET', $url);
                self::assertResponseIsSuccessful();
                self::assertSelectorExists('a[href="/register"]', $url);
            }

            $settings->saveSetting($registrationSetting, '0');

            foreach (['/login', '/', '/store'] as $url) {
                $client->request('GET', $url);
                self::assertResponseIsSuccessful();
                self::assertSelectorNotExists('a[href="/register"]', $url);
            }
        } finally {
            $settings->saveSetting($registrationSetting, $originalRegistrationValue);
            $settings->saveSetting($landingSetting, $originalLandingValue);
        }
    }
}
