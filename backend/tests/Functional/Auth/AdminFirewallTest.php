<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

/**
 * AC-10.5: the API's JWT firewall grants no access to `/admin`. The backoffice itself doesn't
 * exist yet (prompt 08), so today `/admin` simply 404s under the default firewall regardless of
 * what's in the `Authorization` header — this test pins that a valid API JWT never turns it into
 * something else (e.g. an authenticated 200).
 */
final class AdminFirewallTest extends AuthWebTestCase
{
    public function testValidApiJwtDoesNotAuthenticateAnAdminRequest(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->request('GET', '/admin', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$login['accessToken'],
        ]);

        $status = $client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status, 'a bearer JWT must never grant access to /admin');
    }
}
