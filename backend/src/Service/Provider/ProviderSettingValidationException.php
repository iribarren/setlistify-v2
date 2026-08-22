<?php

declare(strict_types=1);

namespace App\Service\Provider;

/**
 * AC-7.3: rejected when an admin write explicitly tries to make a *disabled* provider the default
 * (as opposed to disabling an *already-default* provider, which clears the default instead —
 * D-100, no exception). Thrown only by {@see ProviderSettingWriter}.
 */
final class ProviderSettingValidationException extends \RuntimeException
{
}
