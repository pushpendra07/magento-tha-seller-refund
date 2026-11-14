<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Event;

/**
 * Strips credentials, tokens, bank details beyond the required transaction reference, and
 * customer PII from a payload before it is written to the audit log.
 */
class PayloadRedactor
{
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'authorization',
        'secret',
        'api_key',
        'apikey',
        'credential',
        'bank_account',
        'account_number',
        'iban',
        'swift',
        'card',
        'cvv',
        'email',
        'phone',
        'customer_name',
        'address',
    ];

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            // A sensitive key is redacted whole, even when its value is a nested structure whose own
            // keys look harmless; only non-sensitive branches are walked further.
            if ($this->isSensitive((string) $key)) {
                $result[$key] = self::REDACTED;
                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
