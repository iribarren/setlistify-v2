<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Validator\UniqueEmail;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The **entire** request surface of `POST /api/users` (AC-1.2, D-22). Deliberately exposes only
 * `email` and `password` — there is no `roles`, `isVerified` or `id` field to attack, which is what
 * makes AC-10.1–AC-10.3's guarantee structural rather than defensive.
 */
final class RegisterUserInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[UniqueEmail]
    public string $email = '';

    /**
     * Policy (AC-1.4): 12–4096 characters (4096 is the bcrypt/argon input bound), and rejected if
     * it appears in Symfony's compromised-password check. Hashed with the auto password hasher
     * (AC-1.5) — no algorithm is named here or anywhere in application code.
     */
    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\NotCompromisedPassword]
    public string $password = '';
}
