<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

final class EmailVerificationConfirmInput
{
    #[Assert\NotBlank]
    public string $token = '';
}
