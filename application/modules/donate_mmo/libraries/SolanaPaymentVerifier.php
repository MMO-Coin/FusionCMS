<?php

final class SolanaPaymentVerifier
{
    public const TOKEN_PROGRAM = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

    public static function validAddress(string $address): bool
    {
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', $address)) {
            return false;
        }
        $number = gmp_init(0);
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        foreach (str_split($address) as $character) {
            $number = gmp_add(gmp_mul($number, 58), strpos($alphabet, $character));
        }
        $bytes = gmp_cmp($number, 0) === 0 ? '' : gmp_export($number);
        return strlen($bytes) + strspn($address, '1') === 32;
    }

    public static function amount(string $amount): string
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,9}))?$/D', $amount)) {
            throw new InvalidArgumentException('Enter a positive amount with at most nine decimal places.');
        }
        $normalized = rtrim(rtrim(str_contains($amount, '.') ? $amount : $amount . '.0', '0'), '.');
        if ($normalized === '' || $normalized === '0') {
            throw new InvalidArgumentException('The amount must be greater than zero.');
        }
        return $normalized;
    }

    public static function income(string $amount, string $rate): int
    {
        $units = static function (string $value): \GMP {
            [$whole, $fraction] = array_pad(explode('.', self::amount($value), 2), 2, '');
            return gmp_init($whole . str_pad($fraction, 9, '0'), 10);
        };
        $income = gmp_div_q(gmp_mul($units($amount), $units($rate)), gmp_pow(10, 18));
        if (gmp_cmp($income, 2147483647) > 0) {
            throw new InvalidArgumentException('The donation exceeds the income tracking limit.');
        }
        return gmp_intval($income);
    }

    public static function verifies(array $tx, array $invoice, string $signature): bool
    {
        $meta = $tx['meta'] ?? null;
        if (!is_array($meta) || !array_key_exists('err', $meta) || $meta['err'] !== null
            || ($tx['transaction']['signatures'][0] ?? null) !== $signature
            || !is_int($tx['blockTime'] ?? null)) {
            return false;
        }
        $created = strtotime($invoice['created_at'] . ' UTC');
        $expires = strtotime($invoice['expires_at'] . ' UTC');
        if ($created === false || $expires === false || $tx['blockTime'] < $created || $tx['blockTime'] >= $expires) {
            return false;
        }
        $reference = false;
        foreach ($tx['transaction']['message']['accountKeys'] ?? [] as $key) {
            if (is_array($key) && ($key['pubkey'] ?? null) === $invoice['reference']
                && ($key['signer'] ?? true) === false && ($key['writable'] ?? true) === false) {
                $reference = true;
            }
        }
        if (!$reference || !is_array($meta['preTokenBalances'] ?? null) || !is_array($meta['postTokenBalances'] ?? null)) {
            return false;
        }
        $total = gmp_init(0);
        $decimals = null;
        foreach (['preTokenBalances' => -1, 'postTokenBalances' => 1] as $field => $sign) {
            foreach ($meta[$field] as $balance) {
                if (($balance['mint'] ?? null) !== $invoice['token_mint'] || ($balance['owner'] ?? null) !== $invoice['merchant_wallet']) {
                    continue;
                }
                $token = $balance['uiTokenAmount'] ?? [];
                if (($balance['programId'] ?? null) !== self::TOKEN_PROGRAM
                    || !is_int($token['decimals'] ?? null) || $token['decimals'] < 0 || $token['decimals'] > 9
                    || !is_string($token['amount'] ?? null) || !preg_match('/^[0-9]{1,20}$/D', $token['amount'])
                    || ($decimals !== null && $decimals !== $token['decimals'])) {
                    return false;
                }
                $decimals = $token['decimals'];
                $total = gmp_add($total, gmp_mul(gmp_init($token['amount'], 10), $sign));
            }
        }
        if ($decimals === null) {
            return false;
        }
        try {
            [$whole, $fraction] = array_pad(explode('.', self::amount((string) $invoice['mmo_amount']), 2), 2, '');
        } catch (InvalidArgumentException $e) {
            return false;
        }
        if (strlen($fraction) > $decimals) {
            return false;
        }
        $expected = gmp_init($whole . str_pad($fraction, $decimals, '0'), 10);
        return gmp_cmp($total, $expected) >= 0;
    }
}
