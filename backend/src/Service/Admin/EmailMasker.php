<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * D-51: `local[0]***@domain[0]***.tld` (AC-9.1) — the only place an email's masking rule lives.
 * Used by `App\Field\MaskedEmailField`, the one field type every admin screen uses to render an
 * email, so the rule can't be reimplemented (and drift) at a second call site.
 */
final class EmailMasker
{
    public static function mask(string $email): string
    {
        $atPosition = strrpos($email, '@');
        if (false === $atPosition) {
            return $email;
        }

        $local = substr($email, 0, $atPosition);
        $domainFull = substr($email, $atPosition + 1);

        $dotPosition = strrpos($domainFull, '.');
        $domain = false !== $dotPosition ? substr($domainFull, 0, $dotPosition) : $domainFull;
        $tld = false !== $dotPosition ? substr($domainFull, $dotPosition) : '';

        $localMasked = '' !== $local ? mb_substr($local, 0, 1).'***' : '***';
        $domainMasked = '' !== $domain ? mb_substr($domain, 0, 1).'***' : '***';

        return $localMasked.'@'.$domainMasked.$tld;
    }
}
