<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

final class PasswordResetConfirmInput
{
    #[Assert\NotBlank]
    public string $token = '';

    /** Same policy as registration (AC-1.4, AC-6.3). */
    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\NotCompromisedPassword]
    public string $password = '';
}
