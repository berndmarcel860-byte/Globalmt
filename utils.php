<?php

declare(strict_types=1);

function maskIban(string $iban): string
{
    $clean = preg_replace('/\s+/', '', $iban) ?? '';
    if (strlen($clean) <= 8) {
        return $clean;
    }

    return substr($clean, 0, 4) . ' •••• •••• ' . substr($clean, -4);
}
