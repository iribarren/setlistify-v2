<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Admin\AdminUser;
use App\Service\Admin\EmailMasker;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * US-8: total users, total concerts, concerts in the last 7 days — three uncached `COUNT` queries
 * (D-53), plus links into every section (AC-8.3). Deliberately nothing else (AC-8.4) — no charts, no
 * retention, no funnels.
 */
#[AdminDashboard(routePath: '%admin.path_prefix%', routeName: 'admin_dashboard')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function index(): Response
    {
        $connection = $this->entityManager->getConnection();

        $totalUsers = self::toInt($connection->fetchOne('SELECT COUNT(*) FROM users'));
        $totalConcerts = self::toInt($connection->fetchOne('SELECT COUNT(*) FROM concerts'));
        $recentConcerts = self::toInt($connection->fetchOne(
            'SELECT COUNT(*) FROM concerts WHERE created_at >= :since',
            ['since' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s')],
        ));

        return $this->render('admin/dashboard.html.twig', [
            'total_users' => $totalUsers,
            'total_concerts' => $totalConcerts,
            'recent_concerts' => $recentConcerts,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Setlistify Admin');
    }

    /** @return iterable<MenuItemInterface> */
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(ConcertCrudController::class, 'Concerts', 'fa fa-music');
        yield MenuItem::linkTo(BandCrudController::class, 'Bands', 'fa fa-guitar');
        yield MenuItem::linkTo(AuditLogEntryCrudController::class, 'Audit log', 'fa fa-list');
    }

    /**
     * R-2: EasyAdmin's default user-menu widget calls `getUserIdentifier()`/`__toString()` on the
     * logged-in user and renders it as-is — an unmasked-email leak path that has nothing to do with
     * any CRUD field allowlist. Overridden so even the operator's *own* email is masked (D-51,
     * AC-9.1) in the one place EasyAdmin renders it outside this feature's own controllers.
     */
    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $label = $user instanceof AdminUser ? EmailMasker::mask($user->getUserIdentifier()) : 'Admin';

        return parent::configureUserMenu($user)->setName($label);
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }
}
