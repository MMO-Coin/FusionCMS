<?php

use chillerlan\QRCode\QRCode;

class GoogleAuthenticator
{
    protected int $_codeLength = 6;
    private $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /**
     * Create new secret.
     * 32 characters by default, chosen from the allowed base32 characters.
     *
     * @param int $secretLength
     *
     * @return string
     * @throws Exception
     */
    public function createSecret(int $secretLength = 32): string
    {
        $validChars = $this->getBase32LookupTable();

        // Valid secret lengths are 80 to 640 bits
        if ($secretLength < 16 || $secretLength > 128) {
            throw new Exception('Bad secret length');
        }
        $secret = '';
        $rnd = false;
        if (function_exists('random_bytes')) {
            $rnd = random_bytes($secretLength);
        } elseif (function_exists('mcrypt_create_iv')) {
            $rnd = mcrypt_create_iv($secretLength, MCRYPT_DEV_URANDOM);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $rnd = openssl_random_pseudo_bytes($secretLength, $cryptoStrong);
            if (!$cryptoStrong) {
                $rnd = false;
            }
        }
        if ($rnd !== false) {
            for ($i = 0; $i < $secretLength; ++$i) {
                $secret .= $validChars[ord($rnd[$i]) & 31];
            }
        } else {
            throw new Exception('No source of secure random');
        }

        return $secret;
    }

    /**
     * Create new Totp Aes.
     */
    public function createTotpAes(string $secret): string
    {
        $secret = $this->Base32Decode($secret);
        if ($secret === false || $secret === '') throw new InvalidArgumentException('Invalid authenticator secret.');
        $key = $this->masterKey();
        if ($key === null) return $secret;
        // TrinityCore stores ciphertext followed by its 12-byte IV and tag.
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt($secret, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 12);
        if ($encrypted === false) throw new RuntimeException('Unable to encrypt the authenticator secret.');
        return $encrypted . $iv . $tag;
    }

    private function masterKey(): ?string
    {
        $key = $this->CI->config->item('TOTPMasterSecret');
        if ($key === null || $key === '' || $key === false) return null;
        if (!is_string($key) || !preg_match('/^[a-f0-9]{1,32}$/iD', $key)) {
            throw new RuntimeException('TOTPMasterSecret must be a hexadecimal 128-bit TrinityCore key.');
        }
        // TrinityCore reads the configured hex integer into little-endian bytes.
        return strrev(hex2bin(str_pad($key, 32, '0', STR_PAD_LEFT)));
    }

    public function storedSecretKey(string $secret): ?string
    {
        if ($secret === '' || strlen($secret) > 128) return null;
        if ($this->CI->config->item('totp_secret_name') !== 'totp_secret') {
            $key = $this->Base32Decode($secret);
            return $key === false || $key === '' ? null : $key;
        }
        $key = $this->masterKey();
        if ($key === null) return $secret;
        if (strlen($secret) <= 24) return null;
        $decoded = openssl_decrypt(substr($secret, 0, -24), 'aes-128-gcm', $key, OPENSSL_RAW_DATA,
            substr($secret, -24, 12), substr($secret, -12));
        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * Calculate the code, with given secret and point in time.
     *
     * @param string $secret
     * @param int|null $timeSlice
     *
     * @return string
     */
    public function getCode(string $secret, ?int $timeSlice = null): string
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretKey = $this->Base32Decode($secret);

        // Pack time into binary string
        $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);
        // Hash it with users secret key
        $hm = hash_hmac('SHA1', $time, $secretKey, true);
        // Use last nipple of result as index/offset
        $offset = ord(substr($hm, -1)) & 0x0F;
        // grab 4 bytes of the result
        $hashPart = substr($hm, $offset, 4);

        // Unpack binary value
        $value = unpack('N', $hashPart);
        $value = $value[1];
        // Only 32 bits
        $value = $value & 0x7FFFFFFF;

        $modulo = pow(10, $this->_codeLength);

        return str_pad($value % $modulo, $this->_codeLength, '0', STR_PAD_LEFT);
    }

    function generateTOTPCode($key)
    {
        // Assuming $key is the encrypted data
        $timestamp = floor(time() / 30);
        $binaryTimestamp = pack('N*', 0) . pack('N*', $timestamp);

        // Generate HMAC-SHA1 hash of the timestamp using the key
        $hash = hash_hmac('sha1', $binaryTimestamp, $key, true);

        // Extract a 4-byte dynamic binary code from the hash
        $offset = ord($hash[19]) & 0xf;
        $code = (
                ((ord($hash[$offset + 0]) & 0x7f) << 24) |
                ((ord($hash[$offset + 1]) & 0xff) << 16) |
                ((ord($hash[$offset + 2]) & 0xff) << 8) |
                (ord($hash[$offset + 3]) & 0xff)
            ) % 1000000;

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Render a local authenticator enrollment QR code.
     *
     * @param string $name
     * @param string $secret
     * @return string
     */
    public function getQRCode(string $name, string $secret): string
    {
        $otp = 'otpauth://totp/'.rawurlencode($name).'?secret='.rawurlencode($secret);

        return (new QRCode)->render($otp);
    }

    /**
     * Check if the code is correct. This will accept codes starting from $discrepancy*30sec ago to $discrepancy*30sec from now.
     *
     * @param string $secret
     * @param string $code
     * @param int $discrepancy      This is the allowed time drift in 30 second units (8 means 4 minutes before or after)
     * @param int|null $currentTimeSlice time slice if we want to use other that time()
     *
     * @return bool
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $currentTimeSlice = null): bool
    {
        return $this->matchingTimeStep($secret, $code, $discrepancy, $currentTimeSlice) !== null;
    }

    public function matchingTimeStep(string $secret, string $code, int $discrepancy = 1, ?int $currentTimeSlice = null): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code) || $discrepancy < 0 || $discrepancy > 1) return null;
        $key = $this->storedSecretKey($secret);
        if ($key === null) return null;
        $currentTimeSlice ??= (int) floor(time() / 30);
        for ($step = $currentTimeSlice + $discrepancy; $step >= max(0, $currentTimeSlice - $discrepancy); --$step) {
            $hash = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
            $offset = ord($hash[19]) & 15;
            $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
            if (hash_equals(str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT), $code)) return $step;
        }
        return null;
    }

    /**
     * Set the code length, should be >=6.
     *
     * @param int $length
     *
     * @return GoogleAuthenticator
     */
    public function setCodeLength($length): static
    {
        $this->_codeLength = $length;

        return $this;
    }

    /**
     * Helper class to decode base32.
     *
     * @param $secret
     *
     * @return bool|string
     */
    public function Base32Decode($secret): bool|string
    {
        if (!is_string($secret) || !preg_match('/^[A-Z2-7]{16,128}={0,6}$/D', $secret)) return false;

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(rtrim($secret, '=')) as $character) {
            $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $result .= chr(bindec($byte));
            elseif (bindec($byte) !== 0) return false;
        }
        return $result;
    }

    /**
     * Get array with all 32 characters for decoding from/encoding to base32.
     *
     * @return array
     */
    protected function getBase32LookupTable(): array
    {
        return array(
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
            'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
            'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
            'Y', 'Z', '2', '3', '4', '5', '6', '7', // 31
            '=',  // padding char
        );
    }

    /**
     * A timing safe equals comparison
     * more info here: http://blog.ircmaxell.com/2014/11/its-all-about-time.html.
     *
     * @param string $safeString The internal (safe) value to be checked
     * @param string $userString The user submitted (unsafe) value
     *
     * @return bool True if the two strings are identical
     */
    private function timingSafeEquals(string $safeString, string $userString): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($safeString, $userString);
        }
        $safeLen = strlen($safeString);
        $userLen = strlen($userString);

        if ($userLen != $safeLen) {
            return false;
        }

        $result = 0;

        for ($i = 0; $i < $userLen; ++$i) {
            $result |= (ord($safeString[$i]) ^ ord($userString[$i]));
        }

        // They are only identical strings if $result is exactly 0...
        return $result === 0;
    }
}
