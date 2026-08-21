<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\AbstractAdminCrudController;
use App\Entity\Band;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * AC-10.3: crawls every registered admin route as an authenticated admin and asserts the rendered
 * HTML contains none of: a bcrypt/argon hash prefix, a JWT, a base32 TOTP secret pattern, or the
 * literal names of secret-bearing entity fields. AC-10.4: a CRUD controller without an explicit
 * `configureFields()` fails.
 */
final class AdminNoSecretsRenderedTest extends AdminWebTestCase
{
    public function testNoSecretPatternInAnyAdminScreen(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        // A second admin + a second concert owner, so the lists this crawl visits aren't empty.
        $this->createAdmin();
        $subject = $this->apiRegisterAndLogin($client);
        $this->apiCreateConcert($client, $subject['accessToken'], [
            'date' => '2026-12-24',
            'timezone' => 'Europe/Madrid',
            'lineup' => [['name' => 'Crawl Band '.bin2hex(random_bytes(3))]],
        ]);

        $routes = [
            '/admin',
            '/admin/user',
            '/admin/concert',
            '/admin/band',
            '/admin/audit-log-entry',
        ];

        $forbiddenPatterns = [
            '/\$2y\$/' => 'bcrypt hash prefix',
            '/\$argon2/' => 'argon2 hash prefix',
            '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/' => 'JWT-shaped token',
            '/\b[A-Z2-7]{32}\b/' => 'base32 TOTP-secret-shaped string',
        ];
        $forbiddenFieldNames = ['totpSecretCipher', 'backupCodesHashed', 'password'];

        foreach ($routes as $route) {
            $client->request('GET', $route);
            self::assertResponseIsSuccessful($route);
            $html = (string) $client->getResponse()->getContent();

            foreach ($forbiddenPatterns as $pattern => $description) {
                self::assertDoesNotMatchRegularExpression($pattern, $html, \sprintf('%s must not render a %s', $route, $description));
            }

            foreach ($forbiddenFieldNames as $fieldName) {
                self::assertStringNotContainsString($fieldName, $html, \sprintf('%s must not render the field name "%s"', $route, $fieldName));
            }
        }
    }

    public function testCrudControllerWithoutFieldsFailsWhenUsed(): void
    {
        $controller = new class extends AbstractAdminCrudController {
            public static function getEntityFqcn(): string
            {
                return Band::class;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must override configureFields()');
        $controller->configureFields(Crud::PAGE_INDEX);
    }
}
