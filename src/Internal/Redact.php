<?php

declare(strict_types=1);

namespace Flute\Sdk\Internal;

/**
 * Best-effort masking of sensitive data before it reaches exception messages,
 * structured error details, and logs. Defense in depth: the SDK never
 * deliberately puts card data or secrets in a message, but an upstream error
 * string could echo a submitted value.
 *
 * Three levels:
 *  - text() masks any card-like 13-19 digit run (Luhn gate removed so mistyped
 *    or non-Luhn PAN-like values are still masked). Short numeric ids (amounts,
 *    status codes, correlation ids) stay untouched.
 *  - message() is for top-level gateway free text (Title/Details). It masks
 *    PAN-shaped runs AND opaque mixed-alphanumeric tokens (leaked secrets /
 *    access tokens), but deliberately leaves short numeric runs alone so amounts,
 *    status codes, and order numbers stay readable for diagnostics.
 *  - details() is key-aware: messages echoed under a sensitive field name
 *    (accountNumber, securityCode, clientSecret, ...) get an aggressive scrub
 *    that also masks short digit runs such as CVVs and opaque token strings.
 *
 * @internal not part of the public SDK surface
 */
final class Redact
{
    private const MASK = '*';

    /**
     * Card-data field-name fragments. A value echoed under one of these is
     * digit-shaped (PAN/CVV), so content masking is reliable: details() applies
     * the partial sensitive() scrub, which masks the digits while preserving
     * benign diagnostic prose ("123 is not a valid security code"). Matched
     * case-insensitively as substrings after stripping non-alphanumerics, so
     * "security_code", "securityCode", and "Security Code" all match.
     *
     * @var list<string>
     */
    private const CARD_KEYS = [
        'accountnumber',
        'cardnumber',
        'securitycode',
        'cvv',
        'cvc',
        'cardcode',
    ];

    /**
     * Card-data field names matched by EXACT normalized equality, not substring.
     * "pan" is short enough that a substring match would overmatch benign keys —
     * it sits inside "company" and "companyName" — so it must match the whole
     * normalized key only. Values are digit-shaped like CARD_KEYS, so these get
     * the same partial sensitive() scrub, not wholesale redaction.
     *
     * @var list<string>
     */
    private const CARD_KEYS_EXACT = [
        'pan',
    ];

    /**
     * Credential field-name fragments. A value echoed under one of these can be
     * alphabetic or mostly-alphabetic (an OAuth secret, bearer token, password,
     * proxy credential, or a raw-body echo), which content masking cannot
     * reliably tell from surrounding prose. The field name proves intent, so
     * details() redacts the whole message wholesale rather than relying on the
     * value containing digits. Substring-matched like CARD_KEYS.
     *
     * @var list<string>
     */
    private const CREDENTIAL_KEYS = [
        'clientsecret',
        'accesstoken',
        'authorization',
        'password',
        'secret',
        'token',
        'rawbody',
    ];

    /**
     * Prohibited SAD field names matched by EXACT normalized equality, not
     * substring. Their tokens are short/common enough that a substring match
     * would overmatch benign keys — "pin" is a substring of "shipping", "track"
     * of "trackingNumber" — so these must match the whole normalized key only.
     * PIN/PIN-block and magnetic-stripe track data are PCI sensitive
     * authentication data (must not be stored after authorization); like
     * credential keys they are redacted wholesale.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS_EXACT = [
        'pin',
        'pinblock',
        'track1',
        'track2',
        'trackdata',
    ];

    /** Placeholder for a sensitive non-string scalar (a CVV/PIN given as int). */
    private const PLACEHOLDER = '***';

    /** Mask any card-like digit run (13-19 digits) to its last four. */
    public static function text(string $value): string
    {
        $masked = preg_replace_callback(
            '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/',
            static function (array $match): string {
                $digits = (string) preg_replace('/\D/', '', $match[0]);
                $length = strlen($digits);
                /*
                 * Currently unreachable: the regex above already bounds a match to
                 * 13-19 digits. Kept as belt-and-suspenders so a future regex edit
                 * that loosens that bound can't mask an out-of-range run.
                 */
                if ($length < 13 || $length > 19) {
                    return $match[0];
                }

                return str_repeat(self::MASK, $length - 4) . substr($digits, -4);
            },
            $value,
        );

        return $masked ?? $value;
    }

    /**
     * Scrub top-level gateway free text (Title/Details) before it lands in a
     * public exception message. Masks PAN-shaped runs and opaque mixed
     * alphanumeric tokens (a leaked client secret or access token echoed by the
     * gateway), but leaves short numeric runs untouched so amounts, status
     * codes, and order numbers remain visible for support triage. There is no
     * field name here to prove a short number is a CVV rather than an amount, so
     * aggressive digit masking is left to details() where the key proves intent.
     */
    public static function message(string $value): string
    {
        // Opaque tokens first, before any digit masking can fragment them.
        $value = self::maskOpaqueTokens($value);

        return self::text($value);
    }

    /**
     * Apply key-aware redaction to a field => messages map. Messages under a
     * sensitive field name are scrubbed aggressively; all others get the same
     * conservative free-text treatment as message() — PAN-shaped runs AND opaque
     * mixed-alphanumeric tokens. The redaction boundary is keyed-aggressive,
     * free-text-conservative: a sensitive key proves intent, so its values also
     * get short-digit (CVV) masking; every other key gets exactly the free-text
     * treatment, no more and no less. text() alone would mask only
     * PAN-shaped runs, letting an opaque secret echoed under a non-sensitive key
     * (e.g. an "apiKey" validation error) pass through unmasked — weaker than the
     * top-level message scrub for the field most likely to echo submitted input.
     *
     * @param array<string, list<string>> $details
     *
     * @return array<string, list<string>>
     */
    public static function details(array $details): array
    {
        $out = [];
        foreach ($details as $field => $messages) {
            /*
             * Prohibited SAD keys (pin/pinBlock/track*) AND credential keys
             * (clientSecret/accessToken/password/...) are redacted WHOLESALE:
             * their error text can carry magnetic-stripe / cardholder fragments
             * (e.g. "DOE/JOHN" in a track1 sentinel) or an alphabetic secret
             * (e.g. "CORRECTHORSEBATTERYSTAPLE") that content-based masking
             * leaves intact because they are neither digit runs nor mixed
             * alphanumeric tokens. The field name proves intent, so the whole
             * message is replaced — support-triage readability loses to
             * redaction. Card keys keep the partial sensitive() scrub (their
             * values are digit-shaped, so masking is reliable and benign prose
             * survives); everything else gets message().
             */
            if (self::isWholesaleRedactKey($field)) {
                $out[$field] = array_map(static fn (): string => self::PLACEHOLDER, $messages);
                continue;
            }

            $scrub = self::isCardKey($field) ? self::sensitive(...) : self::message(...);
            $out[$field] = array_map($scrub, $messages);
        }

        return $out;
    }

    /**
     * Key-aware scrub of a decoded payload (a field => value map such as a
     * retained raw API response) for safe generic serialization/debug output.
     * Applies the same key classification as details(): a value under a
     * credential/SAD key is replaced wholesale, a value under a card key is
     * content-masked, and a sensitive parent key taints its whole subtree so a
     * nested credential cannot escape under a benign child key. Other string
     * values get the conservative free-text scrub. Nested arrays recurse; the
     * input is expected to be JSON-decoded (arrays/scalars, no objects).
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function payload(array $data): array
    {
        return self::scrubPayload($data, false);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function scrubPayload(array $data, bool $parentSensitive): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            /*
             * Check the key before recursing so a sensitive parent taints every
             * descendant regardless of the child key.
             */
            $wholesale = $parentSensitive
                || (is_string($key) && self::isWholesaleRedactKey($key));
            $card = is_string($key) && self::isCardKey($key);

            if (is_array($value)) {
                $out[$key] = self::scrubPayload($value, $wholesale);
                continue;
            }

            if ($wholesale) {
                $out[$key] = self::PLACEHOLDER;
                continue;
            }

            if ($card) {
                /*
                 * Card values are digit-shaped; mask a string, replace a non-string
                 * scalar (e.g. an int PAN) wholesale since sensitive() needs a string.
                 */
                $out[$key] = is_string($value) ? self::sensitive($value) : self::PLACEHOLDER;
                continue;
            }

            $out[$key] = is_string($value) ? self::message($value) : $value;
        }

        return $out;
    }

    /**
     * Aggressive scrub for values known to be sensitive by field name: masks
     * PAN-shaped runs to their last four, fully masks any other run of 3+
     * digits (e.g. a CVV or partial), and masks long opaque tokens (client
     * secrets, access tokens, raw-body echoes).
     */
    public static function sensitive(string $value): string
    {
        /*
         * Opaque tokens first: masking digit runs before this would split a
         * token like "abc123XYZ789def0" into fragments the token regex can no
         * longer match, leaving recognizable alpha pieces behind.
         */
        $value = self::maskOpaqueTokens($value);

        // PAN-shaped runs next, preserving last four for support triage.
        $value = self::text($value);

        /*
         * Any remaining run of 3+ digits (CVV, partial PAN) -> full mask.
         * The asterisk lookarounds keep an already-masked PAN's last four intact.
         */
        return (string) preg_replace_callback(
            '/(?<![\d*])\d{3,}(?![\d*])/',
            static fn (array $m): string => str_repeat(self::MASK, strlen($m[0])),
            $value,
        );
    }

    /**
     * Mask opaque token-like runs: 12+ chars mixing letters and digits (e.g.
     * client secrets / access tokens). Pure words and pure numbers are left
     * untouched; this only fires on mixed alphanumeric tokens.
     */
    private static function maskOpaqueTokens(string $value): string
    {
        /*
         * Match each candidate run once, then check letter+digit composition in
         * the callback. Doing the composition check as in-pattern lookaheads
         * ((?=...*[A-Za-z])(?=...*\d)) is O(n^2) on a long letterless run with
         * dashes: a dash is not a \w char, so every dash resets the (?<![\w*])
         * boundary into a fresh start position, and each lookahead rescans the
         * whole forward run. PCRE does not trip its backtrack limit on this, so
         * it silently burns CPU instead of failing closed. Keep this linear.
         */
        return (string) preg_replace_callback(
            '/(?<![\w*])[A-Za-z0-9_\-]{12,}(?![\w*])/',
            static function (array $m): string {
                if (preg_match('/[A-Za-z]/', $m[0]) === 1 && preg_match('/\d/', $m[0]) === 1) {
                    return str_repeat(self::MASK, strlen($m[0]));
                }

                return $m[0];
            },
            $value,
        );
    }

    /**
     * True for field names whose error text must be redacted wholesale: prohibited
     * SAD keys (exact pin/pinBlock/track*) and credential keys (substring
     * clientSecret/accessToken/password/...). Content masking cannot reliably tell
     * these values from prose, and the key proves intent. Checked before isCardKey,
     * so a key matching both is redacted wholesale (the stricter treatment wins).
     */
    private static function isWholesaleRedactKey(string $field): bool
    {
        $normalized = self::normalizeKey($field);
        if ($normalized === '') {
            return false;
        }

        /*
         * Exact-match family first (pin/track*): substring matching would
         * overmatch benign keys like "shipping" (contains "pin").
         */
        if (in_array($normalized, self::SENSITIVE_KEYS_EXACT, true)) {
            return true;
        }

        foreach (self::CREDENTIAL_KEYS as $key) {
            if (str_contains($normalized, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for card-data field names (substring accountNumber/cardNumber/
     * securityCode/cvv/cvc/cardCode, exact pan). Their values are digit-shaped,
     * so details() applies the partial sensitive() scrub that masks digits while
     * keeping prose.
     */
    private static function isCardKey(string $field): bool
    {
        $normalized = self::normalizeKey($field);
        if ($normalized === '') {
            return false;
        }

        /*
         * Exact-match family first (pan): substring matching would overmatch
         * benign keys like "company".
         */
        if (in_array($normalized, self::CARD_KEYS_EXACT, true)) {
            return true;
        }

        foreach (self::CARD_KEYS as $key) {
            if (str_contains($normalized, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Lower-case and strip non-alphanumerics so key variants compare equal. */
    private static function normalizeKey(string $field): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $field));
    }
}
