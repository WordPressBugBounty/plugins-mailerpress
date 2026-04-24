<?php

declare(strict_types=1);

namespace MailerPress\Actions\Shortcodes;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;

class CampaignEmail
{
    /**
     * Handle campaign URL early - before WordPress routing
     * Using wp_loaded hook to intercept before template loading
     */
    #[Action('wp_loaded', priority: 1)]
    public function handleCampaignRequest(): void
    {
        // Only run on frontend
        // Also skip in CLI context (wp-cli commands)
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri = strtok($request_uri, '?'); // Remove query string
        $request_uri = trim($request_uri, '/');

        // Remove site subdirectory if WordPress is installed in a subdirectory
        $home_path = parse_url(home_url(), PHP_URL_PATH);
        // Ensure $home_path is a string before passing to trim() (can be false in CLI context)
        $home_path = is_string($home_path) ? trim($home_path, '/') : '';
        if (!empty($home_path) && strpos($request_uri, $home_path) === 0) {
            $request_uri = trim(substr($request_uri, strlen($home_path)), '/');
        }

        // Check if URL matches: mp-email/{slug}
        if (!preg_match('#^mp-email/([^/]+)/?$#', $request_uri, $matches)) {
            return;
        }

        $campaign_slug = $matches[1];

        // Get campaign from database
        $campaign = $this->getCampaignBySlug($campaign_slug);

        if (!$campaign || $campaign['status'] !== 'sent') {
            status_header(404);
            nocache_headers();
            wp_die(
                __('Campaign not found or not available.', 'mailerpress'),
                __('Not Found', 'mailerpress'),
                ['response' => 404]
            );
        }

        $campaign_id = (int) $campaign['campaign_id'];

        // Get HTML content
        $html = get_option('mailerpress_batch_' . $campaign_id . '_html', '');

        if (empty($html)) {
            status_header(404);
            nocache_headers();
            wp_die(
                __('Campaign content not available.', 'mailerpress'),
                __('Not Found', 'mailerpress'),
                ['response' => 404]
            );
        }

        // Output raw HTML directly
        $this->outputRawHtml($html, $campaign_id);
        exit;
    }

    /**
     * Get campaign by slug (format: {id}-{slug})
     */
    private function getCampaignBySlug(string $slug): ?array
    {
        global $wpdb;
        $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        // Extract ID from the beginning of the slug (format: {id}-{slug})
        if (!preg_match('/^(\d+)-/', $slug, $matches)) {
            return null;
        }

        $campaign_id = (int) $matches[1];

        $campaign = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE campaign_id = %d",
                $campaign_id
            ),
            ARRAY_A
        );

        if (!$campaign) {
            return null;
        }

        // Verify the full slug matches (security check)
        $expected_slug = $campaign_id . '-' . sanitize_title($campaign['name'] ?? '');
        if ($expected_slug !== $slug) {
            return null;
        }

        return $campaign;
    }

    /**
     * Output raw HTML content
     */
    private function outputRawHtml(string $html, int $campaign_id): void
    {
        // Remove footer-email elements
        $html = $this->removeFooterEmailElements($html);

        // Clean merge tags from public view
        $html = $this->cleanMergeTags($html);

        /**
         * Filter whether campaign preview pages should be indexed by search engines.
         *
         * @since 1.4.0
         * @param bool $noindex Whether to block indexing. Default true (noindex).
         * @param int  $campaign_id The campaign ID.
         */
        $noindex = apply_filters('mailerpress_campaign_preview_noindex', true, $campaign_id);

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: script-src 'none'; object-src 'none'");

        if ($noindex) {
            // Add noindex meta tag to prevent SEO indexing
            $html = $this->addNoIndexMeta($html);
            header('X-Robots-Tag: noindex, nofollow');
        }

        nocache_headers();

        echo $html;
    }

    /**
     * Add noindex meta tag to HTML head
     */
    private function addNoIndexMeta(string $html): string
    {
        $noindex_meta = '<meta name="robots" content="noindex, nofollow">';

        // Try to insert after <head> tag
        if (preg_match('/<head[^>]*>/i', $html, $matches, \PREG_OFFSET_CAPTURE)) {
            $position = $matches[0][1] + strlen($matches[0][0]);
            $html = substr_replace($html, "\n" . $noindex_meta, $position, 0);
        }

        return $html;
    }

    /**
     * Clean merge tags from HTML for public display
     * Removes merge tag spans and their placeholders
     */
    private function cleanMergeTags(string $html): string
    {
        // Remove merge-tag-span elements completely (they contain {{placeholder}} text)
        $html = preg_replace(
            '/<span[^>]*class\s*=\s*["\'][^"\']*merge-tag-span[^"\']*["\'][^>]*>.*?<\/span>/is',
            '',
            $html
        ) ?? $html;

        // Also remove any remaining raw merge tag placeholders like {{contact_first_name}}
        $html = preg_replace('/\{\{[a-z_]+\}\}/i', '', $html) ?? $html;

        // Clean up any leftover &nbsp; that might be around removed tags
        $html = preg_replace('/(&nbsp;\s*)+(&nbsp;)?/i', ' ', $html) ?? $html;

        return $html;
    }

    /**
     * Remove footer-email elements from HTML
     */
    private function removeFooterEmailElements(string $html): string
    {
        // Simple regex approach - remove elements with footer-email class
        $patterns = [
            '/<table[^>]*class\s*=\s*["\'][^"\']*footer-email[^"\']*["\'][^>]*>.*?<\/table>/is',
            '/<div[^>]*class\s*=\s*["\'][^"\']*footer-email[^"\']*["\'][^>]*>.*?<\/div>/is',
            '/<[^>]*class\s*=\s*["\'][^"\']*footer-email[^"\']*["\'][^>]*>.*?<\/[^>]+>/is',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        return $html;
    }

    /**
     * Get the public URL for a campaign
     * Format: /mp-email/{id}-{slug}/
     *
     * @param int $campaign_id Campaign ID
     * @param string|null $campaign_title Campaign title (optional, will be fetched if not provided)
     * @return string Public URL
     */
    public static function getPublicUrl(int $campaign_id, ?string $campaign_title = null): string
    {
        global $wpdb;

        // If title not provided, fetch it from database
        if ($campaign_title === null) {
            $table_name = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
            $campaign = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT name FROM {$table_name} WHERE campaign_id = %d",
                    $campaign_id
                ),
                ARRAY_A
            );

            if ($campaign && !empty($campaign['name'])) {
                $campaign_title = $campaign['name'];
            } else {
                return home_url('/mp-email/' . $campaign_id . '-campaign/');
            }
        }

        $slug = $campaign_id . '-' . sanitize_title($campaign_title);
        return home_url('/mp-email/' . $slug . '/');
    }
}
