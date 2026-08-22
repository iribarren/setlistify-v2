<?php

declare(strict_types=1);

namespace App\Field;

use App\Service\Admin\EmailMasker;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * D-51: the only way an email reaches an admin template (AC-9.1) — every CRUD controller uses this
 * instead of `TextField::new('email')` directly, so masking can never be forgotten at a new call
 * site. `App\Controller\Admin\UserCrudController`'s reveal action (AC-9.2–AC-9.6) is the sole,
 * explicit, audited exception.
 *
 * Uses a custom template (`admin/field/masked_email.html.twig`), not just `formatValue()` — R-2:
 * EasyAdmin's stock `crud/field/text.html.twig` renders `title="{{ field.value }}"`, the field's
 * *raw* (pre-format) value, as a hover tooltip. `formatValue()` alone only changes
 * `field.formattedValue`; the raw email would still leak into that `title` attribute.
 */
final class MaskedEmailField
{
    public static function new(string $propertyName, ?string $label = null): TextField
    {
        return TextField::new($propertyName, $label)
            ->setTemplatePath('admin/field/masked_email.html.twig')
            ->formatValue(static function (mixed $value): string {
                if (!\is_string($value) || '' === $value) {
                    return '';
                }

                return EmailMasker::mask($value);
            });
    }
}
