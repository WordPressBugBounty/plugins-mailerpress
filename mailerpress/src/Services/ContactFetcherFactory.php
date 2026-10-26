<?php

namespace MailerPress\Services;

use MailerPress\Core\Interfaces\ContactFetcherInterface;

/**
 * Builds the contact fetcher of a campaign audience.
 *
 * Lists & tags and explicit contact IDs are handled here. Other targeting modes are
 * resolved by extensions through the `mailerpress_contact_fetcher` filter.
 */
final class ContactFetcherFactory
{
    /**
     * @param string|null $targeting Recipient targeting mode, "classic" when empty.
     * @param array $lists List IDs used by the "classic" mode.
     * @param array $tags Tag IDs used by the "classic" mode.
     * @param array $audience Targeting values of the other modes (contact IDs, extension values).
     */
    public static function make(?string $targeting, array $lists, array $tags, array $audience): ContactFetcherInterface
    {
        $targeting = $targeting ?: 'classic';

        if ('classic' === $targeting) {
            return new ClassicContactFetcher($lists, $tags);
        }

        if ('contact_ids' === $targeting) {
            return new ContactIdsFetcher($audience);
        }

        /**
         * Filters the contact fetcher of a targeting mode provided by an extension.
         *
         * @param ContactFetcherInterface|null $fetcher Fetcher to use, null when the mode is unknown.
         * @param string $targeting Recipient targeting mode.
         * @param array $audience Targeting values saved with the campaign.
         * @param array $lists List IDs of the campaign.
         * @param array $tags Tag IDs of the campaign.
         */
        $fetcher = apply_filters('mailerpress_contact_fetcher', null, $targeting, $audience, $lists, $tags);

        // Without the extension that owns this mode, nobody is targeted.
        return $fetcher instanceof ContactFetcherInterface ? $fetcher : new ContactIdsFetcher([]);
    }
}
