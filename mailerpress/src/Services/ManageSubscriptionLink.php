<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

/**
 * Signed, time-limited links to the Manage subscription page.
 *
 * The page shows the contact's email, name and lists, so the contact's access
 * token alone is not enough to open it: the link also carries an expiry date
 * and an HMAC signature. Rotating the signing key invalidates every link sent.
 */
final class ManageSubscriptionLink
{
    public const KEY_OPTION = 'mailerpress_manage_link_key';

    public const VALID = 'valid';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    private const DEFAULT_TTL_DAYS = 30;

    /**
     * How long a link stays valid, in seconds (filterable, at least one hour).
     */
    public static function ttl(): int
    {
        $ttl = (int) apply_filters(
            'mailerpress_manage_subscription_link_ttl',
            self::DEFAULT_TTL_DAYS * DAY_IN_SECONDS
        );

        return max(HOUR_IN_SECONDS, $ttl);
    }

    /**
     * Builds the Manage subscription URL for a contact access token.
     */
    public static function url(string $accessToken, ?int $expires = null): string
    {
        if ('' === $accessToken) {
            return '';
        }

        $expires ??= time() + self::ttl();

        return add_query_arg(
            [
                'cid' => rawurlencode($accessToken),
                'exp' => $expires,
                'sig' => self::sign($accessToken, $expires),
            ],
            mailerpress_get_page('manage_page')
        );
    }

    /**
     * Reads cid, exp and sig from a request array ($_GET or $_POST).
     *
     * @return array{cid: string, exp: string, sig: string}
     */
    public static function params(array $source, string $prefix = ''): array
    {
        $read = static function (string $key) use ($source, $prefix): string {
            $value = $source[$prefix . $key] ?? '';

            return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
        };

        return [
            'cid' => $read('cid'),
            'exp' => $read('exp'),
            'sig' => $read('sig'),
        ];
    }

    /**
     * Returns VALID, EXPIRED (authentic but past its date) or INVALID
     * (missing or wrong signature, including links sent before signing existed).
     */
    public static function check(string $accessToken, string $expires, string $signature): string
    {
        if ('' === $accessToken || '' === $signature || !ctype_digit($expires)) {
            return self::INVALID;
        }

        if (!hash_equals(self::sign($accessToken, (int) $expires), $signature)) {
            return self::INVALID;
        }

        return (int) $expires < time() ? self::EXPIRED : self::VALID;
    }

    /**
     * Invalidates every Manage subscription link sent so far.
     */
    public static function rotateKey(): void
    {
        update_option(self::KEY_OPTION, self::newKey(), true);
    }

    private static function sign(string $accessToken, int $expires): string
    {
        return hash_hmac('sha256', 'manage|' . $accessToken . '|' . $expires, self::key());
    }

    private static function key(): string
    {
        $key = get_option(self::KEY_OPTION);

        if (!is_string($key) || strlen($key) < 32) {
            // add_option() fails when a concurrent request created the key first: read it back.
            add_option(self::KEY_OPTION, self::newKey(), '', true);
            $key = (string) get_option(self::KEY_OPTION);
        }

        return $key . wp_salt('auth');
    }

    private static function newKey(): string
    {
        return wp_generate_password(64, true, true);
    }
}
