<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

final class PasswordResetRequestInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';
}
