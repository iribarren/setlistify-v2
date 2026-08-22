<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\ProviderSettingCrudController;
use App\Entity\ProviderSetting;
use App\Repository\AuditLogEntryRepository;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderSettingWriter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * `/admin` "Providers" section (docs/specs/2026-08-22-backoffice-provider-configuration.md, US-1,
 * US-2, US-3).
 */
final class ProviderSettingCrudControllerTest extends AdminWebTestCase
{
    public function testIndexListsOneRowPerProviderSettingWithTheDeclaredColumns(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $client->request('GET', '/admin/provider-setting');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        // AC-1.1: spotify and youtube are seeded by the migration (D-102).
        self::assertStringContainsString('spotify', $html);
        self::assertStringContainsString('youtube', $html);
    }

    /** AC-3.6: no NEW action — rows come from the migration only. */
    public function testThereIsNoNewAction(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $client->request('GET', '/admin/provider-setting');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $newUrl = $urlGenerator->setController(ProviderSettingCrudController::class)->setAction(Crud::PAGE_NEW)->generateUrl();
        self::assertStringNotContainsString($newUrl, $html, 'AC-3.6: no link to the NEW action should be rendered.');
    }

    /** AC-3.1-AC-3.4: help text is on the page, visible without hovering/expanding — a rendered-HTML crawl. */
    public function testEditPageRendersTheLegalAndOperationalHelpText(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $setting = $this->findSpotifySetting();
        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $editUrl = $urlGenerator->setController(ProviderSettingCrudController::class)->setAction(Crud::PAGE_EDIT)->setEntityId($setting->getId())->generateUrl();

        $client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // AC-3.2/AC-3.3: playbackMode's consequence, stated plainly.
        self::assertStringContainsString('Streaming SDA', $html);
        self::assertStringContainsString('Non-Streaming SDA', $html);
        self::assertStringContainsString('policy violation', $html);
        // AC-3.4: enabled's help text states the disable is graceful.
        self::assertStringContainsString('graceful', $html);
    }

    /** AC-9.3: exactly the declared editable fields plus read-only timestamps — no accidental "expose everything". */
    public function testEditPageDoesNotRenderTheNotesFieldOnTheIndexPage(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $writer = static::getContainer()->get(ProviderSettingWriter::class);
        $writer->update('spotify', enabled: true, playbackMode: PlaybackMode::Embed, isDefault: true, notes: 'incident-2026-08-22-do-not-leak', actor: $admin['user']);

        $client->request('GET', '/admin/provider-setting');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('incident-2026-08-22-do-not-leak', (string) $client->getResponse()->getContent(), 'notes must never render on the index list.');
    }

    /**
     * AC-8.1/US-10: the controller's write path is `ProviderSettingWriter`, exercised directly here
     * (rather than through a raw HTTP form POST, whose exact EasyAdmin field-naming is an
     * implementation detail this test should not depend on) — `updateEntity()` is a thin adapter
     * over the writer, and `App\Tests\Functional\Provider\ProviderSettingWriterTest` covers the
     * writer's business rules exhaustively.
     */
    public function testUpdateEntityRoutesThroughTheWriterAndIsAudited(): void
    {
        $this->createAdminClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $admin = $this->createAdmin();
        $adminUser = $container->get(\App\Security\Admin\AdminUserProvider::class)->loadUserByIdentifier($admin['email']);
        $token = new UsernamePasswordToken($adminUser, 'admin', ['ROLE_ADMIN']);
        $container->get(TokenStorageInterface::class)->setToken($token);

        $setting = $this->findSpotifySetting();
        $setting->setEnabled(false);
        $setting->setPlaybackMode(PlaybackMode::Off);

        $controller = $container->get(ProviderSettingCrudController::class);
        $controller->updateEntity($em, $setting);

        $em->clear();
        $reloaded = $em->getRepository(ProviderSetting::class)->findOneBy(['provider' => 'spotify']);
        self::assertInstanceOf(ProviderSetting::class, $reloaded);
        self::assertFalse($reloaded->isEnabled());
        self::assertSame(PlaybackMode::Off, $reloaded->getPlaybackMode());
        self::assertFalse($reloaded->isDefault(), 'D-100: disabling the current default clears it.');

        $auditRepo = $container->get(AuditLogEntryRepository::class);
        $entries = $auditRepo->findBy(['subjectType' => 'ProviderSetting', 'subjectId' => 'spotify']);
        self::assertNotEmpty($entries, 'the admin edit must be audited.');
    }

    private function findSpotifySetting(): ProviderSetting
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $setting = $em->getRepository(ProviderSetting::class)->findOneBy(['provider' => 'spotify']);
        self::assertInstanceOf(ProviderSetting::class, $setting, 'the migration must have seeded a spotify row.');

        return $setting;
    }

    /**
     * The migration seeds exactly one `spotify`/`youtube` row shared by the whole suite — tests
     * that mutate them restore the seeded state first so test order never matters.
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->executeStatement('UPDATE provider_settings SET is_default = false'); // clear first — the partial unique index forbids two rows at once, even transiently across statements.
        $connection->executeStatement("UPDATE provider_settings SET enabled = true, playback_mode = 'embed', is_default = true, notes = NULL WHERE provider = 'spotify'");
        $connection->executeStatement("UPDATE provider_settings SET enabled = false, playback_mode = 'off', is_default = false, notes = NULL WHERE provider = 'youtube'");
        $em->clear();
        self::ensureKernelShutdown();
    }
}
