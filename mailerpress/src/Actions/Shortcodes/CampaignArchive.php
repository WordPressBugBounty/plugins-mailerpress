<?php

declare(strict_types=1);

namespace MailerPress\Actions\Shortcodes;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;

class CampaignArchive
{
    #[Action('init')]
    public function registerShortcode(): void
    {
        add_shortcode('mailerpress_archive', [$this, 'render']);
    }

    /**
     * Render the campaign archive shortcode
     *
     * Usage: [mailerpress_archive]
     * Parameters:
     *   - year: Filter by year (e.g., 2024)
     *   - limit: Number of campaigns to show (default: -1 for all)
     *   - order: ASC or DESC (default: DESC)
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render($atts): string
    {
        $atts = shortcode_atts([
            'year' => '',
            'limit' => -1,
            'order' => 'DESC',
        ], $atts, 'mailerpress_archive');

        $year = sanitize_text_field($atts['year']);
        $limit = (int) $atts['limit'];
        $order = strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Get campaigns
        $campaigns = $this->getCampaigns($year, $limit, $order);

        if (empty($campaigns)) {
            return '<p class="mp-archive-empty">' . esc_html__('No campaigns found.', 'mailerpress') . '</p>';
        }

        // Build HTML
        $html = $this->buildStyles();
        $html .= '<ul class="mp-archive-list">';

        foreach ($campaigns as $campaign) {
            $html .= $this->renderCampaignItem($campaign);
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Get sent campaigns from database
     */
    private function getCampaigns(string $year, int $limit, string $order): array
    {
        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $sql = "SELECT campaign_id, name, created_at, updated_at
                FROM {$table}
                WHERE status = 'sent'";

        if (!empty($year) && is_numeric($year)) {
            $sql .= $wpdb->prepare(" AND YEAR(created_at) = %d", (int) $year);
        }

        $sql .= " ORDER BY created_at {$order}";

        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Render a single campaign item
     */
    private function renderCampaignItem(array $campaign): string
    {
        $url = CampaignEmail::getPublicUrl((int) $campaign['campaign_id'], $campaign['name']);
        $date = date_i18n(get_option('date_format'), strtotime($campaign['created_at']));
        $name = esc_html($campaign['name']);

        return sprintf(
            '<li class="mp-archive-item">
                <a href="%s" class="mp-archive-link" target="_blank">
                    <span class="mp-archive-name">%s</span>
                    <span class="mp-archive-date">%s</span>
                </a>
            </li>',
            esc_url($url),
            $name,
            esc_html($date)
        );
    }

    /**
     * Build minimal CSS styles
     */
    private function buildStyles(): string
    {
        return '<style>
            .mp-archive-empty {
                color: #666;
                font-style: italic;
            }
            .mp-archive-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .mp-archive-item {
                margin: 0;
                padding: 0;
            }
            .mp-archive-link {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.5rem 0;
                border-bottom: 1px solid #eee;
                text-decoration: none;
                color: inherit;
            }
            .mp-archive-link:hover .mp-archive-name {
                text-decoration: underline;
            }
            .mp-archive-name {
                color: #1a1a1a;
            }
            .mp-archive-date {
                font-size: 0.875em;
                color: #666;
                margin-left: 1rem;
            }
        </style>';
    }
}
