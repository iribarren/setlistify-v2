<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Admin\AdminUser;
use App\Security\Admin\TotpSecretEncryptor;
use App\Service\Admin\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * D-49: forced 2FA enrollment for a `ROLE_ADMIN` account with no TOTP secret yet. Reachable only
 * from a partially-authenticated (2FA-in-progress) session for such an account — see
 * `App\EventSubscriber\ForceTwoFactorEnrollmentSubscriber`, which redirects everything else here.
 *
 * The secret and backup codes are generated and shown **once**, staged in the session until the
 * operator proves possession by submitting a valid code (AC-5.2, AC-5.4) — nothing is persisted
 * until that confirmation, so an abandoned enrollment leaves no half-written state.
 */
final class TwoFactorEnrollmentController extends AbstractController
{
    private const string SESSION_SECRET_KEY = '_admin_2fa_pending_secret';
    private const string SESSION_BACKUP_CODES_KEY = '_admin_2fa_pending_backup_codes';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly TotpSecretEncryptor $encryptor,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly string $totpIssuer,
    ) {
    }

    public function enroll(Request $request): Response
    {
        $adminUser = $this->requirePendingEnrollment();
        $session = $request->getSession();

        $secret = $session->get(self::SESSION_SECRET_KEY);
        if (!\is_string($secret) || '' === $secret) {
            $secret = Base32::encodeUpper(random_bytes(20));
            $session->set(self::SESSION_SECRET_KEY, $secret);
        }
        \assert('' !== $secret);

        $backupCodes = $session->get(self::SESSION_BACKUP_CODES_KEY);
        if (!\is_array($backupCodes)) {
            $backupCodes = array_map(static fn (): string => bin2hex(random_bytes(5)), range(1, 10));
            $session->set(self::SESSION_BACKUP_CODES_KEY, $backupCodes);
        }
        /** @var list<string> $backupCodes */

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($adminUser->getUserIdentifier());
        \assert('' !== $this->totpIssuer, 'ADMIN_TOTP_ISSUER must not be empty');
        $totp->setIssuer($this->totpIssuer);

        return $this->render('admin/2fa/enroll.html.twig', [
            'secret' => $secret,
            'qr_data_uri' => $this->buildQrDataUri($totp->getProvisioningUri()),
            'backup_codes' => $backupCodes,
            'confirm_path' => $this->generateUrl('admin_2fa_enroll_confirm'),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $adminUser = $this->requirePendingEnrollment();
        $session = $request->getSession();

        $secret = $session->get(self::SESSION_SECRET_KEY);
        $backupCodes = $session->get(self::SESSION_BACKUP_CODES_KEY);
        if (!\is_string($secret) || '' === $secret || !\is_array($backupCodes)) {
            return $this->redirectToRoute('admin_2fa_enroll');
        }

        // This action completes admin authentication and persists the TOTP secret + backup codes —
        // the single most sensitive write in the whole feature. It is a bespoke controller action,
        // not routed through form_login/scheb's own CSRF-protected listeners, so it must validate
        // explicitly (devops-security-engineer review, 2026-08-21: a cross-origin POST here
        // previously succeeded unconditionally).
        if (!$this->isCsrfTokenValid('admin_2fa_confirm', (string) $request->request->get('_csrf_token', ''))) {
            $csrfFailTotp = TOTP::createFromSecret($secret);
            $csrfFailTotp->setLabel($adminUser->getUserIdentifier());
            \assert('' !== $this->totpIssuer, 'ADMIN_TOTP_ISSUER must not be empty');
            $csrfFailTotp->setIssuer($this->totpIssuer);

            return $this->render('admin/2fa/enroll.html.twig', [
                'secret' => $secret,
                'qr_data_uri' => $this->buildQrDataUri($csrfFailTotp->getProvisioningUri()),
                'backup_codes' => $backupCodes,
                'confirm_path' => $this->generateUrl('admin_2fa_enroll_confirm'),
                'error' => 'Your session expired — please try again.',
            ], new Response(status: 422));
        }

        $submittedCode = (string) $request->request->get('code', '');
        if ('' === $submittedCode) {
            $submittedCode = '000000';
        }
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($adminUser->getUserIdentifier());
        \assert('' !== $this->totpIssuer, 'ADMIN_TOTP_ISSUER must not be empty');
        $totp->setIssuer($this->totpIssuer);

        if (!$totp->verify($submittedCode, null, 1)) {
            return $this->render('admin/2fa/enroll.html.twig', [
                'secret' => $secret,
                'qr_data_uri' => $this->buildQrDataUri($totp->getProvisioningUri()),
                'backup_codes' => $backupCodes,
                'confirm_path' => $this->generateUrl('admin_2fa_enroll_confirm'),
                'error' => 'That code did not match — try the current code from your authenticator app.',
            ], new Response(status: 422));
        }

        $user = $adminUser->getWrappedUser();
        $hasher = $this->passwordHasherFactory->getPasswordHasher(User::class);

        $user->setTotpSecretCipher($this->encryptor->encrypt($secret));
        /** @var list<string> $backupCodes */
        $user->setBackupCodesHashed(array_map(
            static fn (string $code): string => $hasher->hash($code),
            $backupCodes,
        ));
        $this->entityManager->flush();

        $this->auditLogger->log(
            actor: $user,
            action: '2fa_enrolled',
            subjectType: 'User',
            subjectId: $user->getId() ?? 0,
        );

        $session->remove(self::SESSION_SECRET_KEY);
        $session->remove(self::SESSION_BACKUP_CODES_KEY);

        // Complete 2FA the same way scheb's own CheckTotpAuthenticationListener does: swap the
        // TwoFactorToken for the real, fully-authenticated token it wraps. This does NOT re-run
        // scheb's authenticator (that only intercepts unauthenticated/2FA-pending requests), so it
        // cannot loop back into enrollment.
        $token = $this->tokenStorage->getToken();
        \assert($token instanceof TwoFactorTokenInterface);
        $this->tokenStorage->setToken($token->getAuthenticatedToken());

        return new RedirectResponse($this->generateUrl('admin_dashboard'));
    }

    private function buildQrDataUri(string $provisioningUri): string
    {
        // SVG, not PNG: the base image (docker/backend/Dockerfile) has no `gd` extension, and SVG
        // needs none.
        $builder = new Builder(writer: new SvgWriter(), data: $provisioningUri, size: 240, margin: 10);

        return $builder->build()->getDataUri();
    }

    private function requirePendingEnrollment(): AdminUser
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TwoFactorTokenInterface) {
            throw new AccessDeniedException('2FA enrollment is only reachable mid-login.');
        }

        $user = $token->getUser();
        if (!$user instanceof AdminUser) {
            throw new AccessDeniedException();
        }

        return $user;
    }
}
