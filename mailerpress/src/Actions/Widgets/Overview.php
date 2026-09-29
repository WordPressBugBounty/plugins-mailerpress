<?php

declare(strict_types=1);

namespace MailerPress\Actions\Widgets;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Capabilities;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\ExternalLinks;

class Overview
{
    #[Action('wp_dashboard_setup', scope: 'admin')]
    public function register(): void
    {
        if (!current_user_can(Capabilities::MANAGE_CAMPAIGNS)) {
            return;
        }

        wp_add_dashboard_widget(
            'mailerpress_overview_widget',
            esc_html__('MailerPress Overview', 'mailerpress'),
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $stats = $this->getStats();
        $recentCampaigns = $this->getRecentCampaigns();
        $isProActive = $this->isProActive();
        $version = $this->getVersionLabel();
        $proLabel = $this->getProStatusLabel($isProActive);

        ?>
        <div class="mailerpress-dashboard-overview">
            <style>
                #mailerpress_overview_widget .inside {
                    margin: 11px 0 0;
                    padding: 0 12px 12px;
                }

                .mailerpress-dashboard-overview .dashicons {
                    color: #606a73;
                    font-size: 17px;
                    vertical-align: middle;
                }

                .mailerpress-dashboard-overview__header {
                    box-shadow: 0 5px 8px rgba(0, 0, 0, 0.05);
                    display: table;
                    margin: 0 -12px 8px;
                    padding: 0 12px 12px;
                    width: 100%;
                }

                .mailerpress-dashboard-overview__logo,
                .mailerpress-dashboard-overview__versions,
                .mailerpress-dashboard-overview__create {
                    display: table-cell;
                    vertical-align: middle;
                }

                .mailerpress-dashboard-overview__logo {
                    width: 30px;
                }

                .mailerpress-dashboard-overview__logo img {
                    display: inline-block;
                    height: 32.5px;
                    width: 32.5px;
                }

                .mailerpress-dashboard-overview__versions {
                    color: #3c434a;
                    font-size: 0.9em;
                    line-height: 1.5;
                    padding: 0 10px;
                }

                .mailerpress-dashboard-overview__version {
                    display: block;
                }

                .mailerpress-dashboard-overview__create {
                    text-align: end;
                }

                .mailerpress-dashboard-overview .button.mailerpress-dashboard-overview__primary-action {
                    align-items: center;
                    display: inline-flex;
                    gap: 4px;
                    white-space: nowrap;
                }

                .mailerpress-dashboard-overview .button.mailerpress-dashboard-overview__primary-action .dashicons {
                    color: inherit;
                    flex-shrink: 0;
                    font-size: 16px;
                    height: 16px;
                    line-height: 1;
                    width: 16px;
                }

                #dashboard-widgets .mailerpress-dashboard-overview h3.mailerpress-dashboard-overview__heading {
                    border-bottom: 1px solid #eee;
                    font-weight: 600;
                    margin: 0 -12px 13px;
                    padding: 6px 12px;
                }

                .mailerpress-dashboard-overview__section + .mailerpress-dashboard-overview__section {
                    margin-top: 18px;
                }

                .mailerpress-dashboard-overview__list,
                .mailerpress-dashboard-overview__summary {
                    margin: 0;
                }

                .mailerpress-dashboard-overview__list li,
                .mailerpress-dashboard-overview__summary li {
                    align-items: flex-start;
                    display: flex;
                    gap: 8px;
                    margin-bottom: 10px;
                }

                .mailerpress-dashboard-overview__list li:last-child,
                .mailerpress-dashboard-overview__summary li:last-child {
                    margin-bottom: 0;
                }

                .mailerpress-dashboard-overview__list .dashicons,
                .mailerpress-dashboard-overview__summary .dashicons {
                    margin-top: 1px;
                }

                .mailerpress-dashboard-overview__campaign-meta,
                .mailerpress-dashboard-overview__summary-meta {
                    color: #646970;
                    display: block;
                    margin-top: 2px;
                }

                .mailerpress-dashboard-overview__footer {
                    border-top: 1px solid #eee;
                    margin: 13px -12px 0;
                    padding: 12px 12px 0;
                }

                .mailerpress-dashboard-overview__footer ul {
                    display: flex;
                    flex-wrap: wrap;
                    list-style: none;
                    margin: 0;
                    padding: 0;
                }

                .mailerpress-dashboard-overview__footer li {
                    border-left: 1px solid #ddd;
                    margin: 0;
                    padding: 0 10px;
                }

                .mailerpress-dashboard-overview__footer li:first-child {
                    border: 0;
                    padding-left: 0;
                }

                .mailerpress-dashboard-overview__footer a {
                    align-items: center;
                    display: inline-flex;
                    font-size: 14px;
                    font-weight: 600;
                    gap: 6px;
                    line-height: 1.5;
                    text-decoration: none;
                }

                .mailerpress-dashboard-overview__footer .dashicons {
                    font-size: 17px;
                    height: 17px;
                    width: 17px;
                }
            </style>

            <div class="mailerpress-dashboard-overview__header">
                <div class="mailerpress-dashboard-overview__logo" aria-hidden="true">
                    <img src="<?php echo esc_url($this->getLogoUrl()); ?>" alt="">
                </div>
                <div class="mailerpress-dashboard-overview__versions">
                    <?php if ($version !== '') : ?>
                        <span class="mailerpress-dashboard-overview__version"><?php echo esc_html($version); ?></span>
                    <?php endif; ?>
                    <span class="mailerpress-dashboard-overview__version"><?php echo esc_html($proLabel); ?></span>
                </div>

                <?php if (current_user_can(Capabilities::MANAGE_CAMPAIGNS)) : ?>
                    <div class="mailerpress-dashboard-overview__create">
                        <a class="button mailerpress-dashboard-overview__primary-action" href="<?php echo esc_url($this->adminUrl('/home', ['view' => 'create-campaign'])); ?>">
                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                            <?php esc_html_e('New Campaign', 'mailerpress'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$isProActive) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <strong><?php esc_html_e('Unlock MailerPress Pro!', 'mailerpress'); ?></strong><br>
                        <?php esc_html_e('Get advanced automation, integrations, personalization, and more.', 'mailerpress'); ?>
                    </p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(ExternalLinks::get('pricing')); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Upgrade to Pro', 'mailerpress'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new window)', 'mailerpress'); ?></span>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="mailerpress-dashboard-overview__section">
                <h3 class="mailerpress-dashboard-overview__heading"><?php esc_html_e('At a Glance', 'mailerpress'); ?></h3>
                <ul class="mailerpress-dashboard-overview__summary">
                    <?php foreach ($stats as $stat) : ?>
                        <li>
                            <span class="dashicons <?php echo esc_attr($stat['icon']); ?>" aria-hidden="true"></span>
                            <span>
                                <strong><?php echo esc_html($stat['label']); ?></strong>
                                <span class="mailerpress-dashboard-overview__summary-meta">
                                    <?php echo esc_html($stat['description']); ?>
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="mailerpress-dashboard-overview__section">
                <h3 class="mailerpress-dashboard-overview__heading"><?php esc_html_e('Continue Editing', 'mailerpress'); ?></h3>

                <?php if (empty($recentCampaigns)) : ?>
                    <p><?php esc_html_e('No editable campaigns need your attention.', 'mailerpress'); ?></p>
                <?php else : ?>
                    <ul class="mailerpress-dashboard-overview__list">
                        <?php foreach ($recentCampaigns as $campaign) : ?>
                            <li>
                                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                <span>
                                    <a href="<?php echo esc_url($this->campaignEditUrl((int) $campaign->campaign_id)); ?>">
                                        <?php echo esc_html($campaign->name ?: sprintf(__('Campaign #%d', 'mailerpress'), (int) $campaign->campaign_id)); ?>
                                    </a>
                                    <span class="mailerpress-dashboard-overview__campaign-meta">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                /* translators: 1: campaign type, 2: campaign status or schedule details. */
                                                __('%1$s - %2$s', 'mailerpress'),
                                                $this->formatCampaignType((string) $campaign->campaign_type),
                                                $this->formatCampaignMeta($campaign)
                                            )
                                        );
                                        ?>
                                    </span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="mailerpress-dashboard-overview__footer">
                <ul>
                    <?php if (current_user_can(Capabilities::MANAGE_CONTACTS)) : ?>
                        <li>
                            <a href="<?php echo esc_url($this->adminUrl('/home/contacts')); ?>">
                                <?php esc_html_e('Audience', 'mailerpress'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (current_user_can(Capabilities::MANAGE_AUTOMATIONS)) : ?>
                        <li>
                            <a href="<?php echo esc_url($this->adminUrl('/home/workflow')); ?>">
                                <?php esc_html_e('Automations', 'mailerpress'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li>
                        <a href="<?php echo esc_url(ExternalLinks::get('docs.getting_started')); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Docs', 'mailerpress'); ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new window)', 'mailerpress'); ?></span>
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    private function getStats(): array
    {
        $draftCount = $this->countCampaignsByStatus(['draft']);
        $queuedCount = $this->countCampaignsByStatus(['scheduled', 'pending', 'in_progress']);

        $stats = [
            [
                'label' => __('Drafts', 'mailerpress'),
                'icon' => 'dashicons-edit-page',
                'description' => sprintf(
                    /* translators: %s is the number of draft campaigns. */
                    _n('%s campaign waiting to be finished.', '%s campaigns waiting to be finished.', $draftCount, 'mailerpress'),
                    number_format_i18n($draftCount)
                ),
            ],
            [
                'label' => __('Queued sends', 'mailerpress'),
                'icon' => 'dashicons-clock',
                'description' => sprintf(
                    /* translators: %s is the number of queued campaigns. */
                    _n('%s campaign scheduled or sending.', '%s campaigns scheduled or sending.', $queuedCount, 'mailerpress'),
                    number_format_i18n($queuedCount)
                ),
            ],
        ];

        if (current_user_can(Capabilities::MANAGE_CONTACTS)) {
            $subscribersCount = $this->getSubscribersCount();
            $stats[] = [
                'label' => __('Active subscribers', 'mailerpress'),
                'icon' => 'dashicons-groups',
                'description' => sprintf(
                    /* translators: %s is the number of active subscribers. */
                    _n('%s subscribed contact can receive campaigns.', '%s subscribed contacts can receive campaigns.', $subscribersCount, 'mailerpress'),
                    number_format_i18n($subscribersCount)
                ),
            ];
        }

        return $stats;
    }

    private function getSubscribersCount(): int
    {
        if (!$this->tableExists(Tables::MAILERPRESS_CONTACT)) {
            return 0;
        }

        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CONTACT);

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE subscription_status = %s",
                'subscribed'
            )
        );
    }

    private function countCampaignsByStatus(array $statuses): int
    {
        if (!$this->tableExists(Tables::MAILERPRESS_CAMPAIGNS) || empty($statuses)) {
            return 0;
        }

        global $wpdb;

        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        [$userWhere, $userParams] = $this->currentUserCampaignWhere();

        $sql = "SELECT COUNT(*) FROM {$table} WHERE campaign_type = %s AND status IN ({$placeholders}){$userWhere}";

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...array_merge(['newsletter'], $statuses, $userParams)));
    }

    private function getRecentCampaigns(): array
    {
        if (!$this->tableExists(Tables::MAILERPRESS_CAMPAIGNS)) {
            return [];
        }

        global $wpdb;

        $campaignsTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);
        [$userWhere, $userParams] = $this->currentUserCampaignWhere('c');
        $sql = "SELECT c.campaign_id, c.name, c.status, c.campaign_type, c.updated_at
            FROM {$campaignsTable} c
            WHERE c.campaign_type = %s AND c.status = %s{$userWhere}
            ORDER BY c.updated_at DESC
            LIMIT 3";

        return $wpdb->get_results(
            $wpdb->prepare($sql, ...array_merge(['newsletter', 'draft'], $userParams))
        );
    }

    private function currentUserCampaignWhere(string $tableAlias = ''): array
    {
        if (current_user_can(Capabilities::EDIT_OTHERS_CAMPAIGNS)) {
            return ['', []];
        }

        $column = $tableAlias !== '' ? "{$tableAlias}.user_id" : 'user_id';

        return [" AND {$column} = %d", [get_current_user_id()]];
    }

    private function tableExists(string $table): bool
    {
        return Tables::exists(Tables::get($table));
    }

    private function adminUrl(string $path, array $args = []): string
    {
        return add_query_arg(
            array_merge(
                [
                    'page' => 'mailerpress/campaigns.php',
                    'path' => $path,
                ],
                $args
            ),
            admin_url('admin.php')
        );
    }

    private function campaignEditUrl(int $campaignId): string
    {
        return add_query_arg(
            [
                'page' => 'mailerpress/new',
                'edit' => $campaignId,
            ],
            admin_url('admin.php')
        );
    }

    private function getLogoUrl(): string
    {
        if (\defined('MAILERPRESS_PLUGIN_DIR_URL')) {
            return MAILERPRESS_PLUGIN_DIR_URL . 'build/public/images/admin-menu-logo.svg';
        }

        return '';
    }

    private function getVersionLabel(): string
    {
        $version = $this->getPluginVersion(
            'mailerpress/mailerpress.php',
            ['MAILERPRESS_VERSION'],
            [],
            'mailerpress/readme.txt'
        );

        if ($version === '') {
            return '';
        }

        return sprintf(
            /* translators: %s is the MailerPress plugin version. */
            __('MailerPress v%s', 'mailerpress'),
            $version
        );
    }

    private function getProStatusLabel(bool $isProActive): string
    {
        if (!$isProActive) {
            return __('MailerPress Pro not active', 'mailerpress');
        }

        $version = $this->getPluginVersion(
            'mailerpress-pro/mailerpress-pro.php',
            ['MAILERPRESS_PRO_VERSION'],
            ['mailerpress_pro_version'],
            'mailerpress-pro/readme.txt'
        );

        if ($version === '') {
            return __('MailerPress Pro active', 'mailerpress');
        }

        return sprintf(
            /* translators: %s is the MailerPress Pro plugin version. */
            __('MailerPress Pro v%s', 'mailerpress'),
            $version
        );
    }

    private function getPluginVersion(string $pluginFile, array $constantNames = [], array $optionNames = [], string $readmeFile = ''): string
    {
        $this->ensurePluginFunctionsLoaded();

        if (\defined('WP_PLUGIN_DIR') && function_exists('get_plugin_data')) {
            $pluginPath = WP_PLUGIN_DIR . '/' . $pluginFile;

            if (file_exists($pluginPath)) {
                $pluginData = get_plugin_data($pluginPath, false, false);
                $version = isset($pluginData['Version']) ? (string) $pluginData['Version'] : '';

                if ($this->isDisplayableVersion($version)) {
                    return $version;
                }
            }
        }

        foreach ($constantNames as $constantName) {
            if (\defined($constantName) && $this->isDisplayableVersion((string) \constant($constantName))) {
                return (string) \constant($constantName);
            }
        }

        foreach ($optionNames as $optionName) {
            $version = (string) get_option($optionName, '');

            if ($this->isDisplayableVersion($version)) {
                return $version;
            }
        }

        if ($readmeFile !== '') {
            $version = $this->getReadmeVersion($readmeFile);

            if ($version !== '') {
                return $version;
            }
        }

        return '';
    }

    private function isDisplayableVersion(string $version): bool
    {
        return $version !== '' && !str_starts_with($version, '{{');
    }

    private function getReadmeVersion(string $readmeFile): string
    {
        if (!\defined('WP_PLUGIN_DIR')) {
            return '';
        }

        $path = WP_PLUGIN_DIR . '/' . $readmeFile;

        if (!file_exists($path) || !is_readable($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return '';
        }

        if (preg_match('/^=\\s*([0-9]+(?:\\.[0-9]+)+(?:[-.][A-Za-z0-9]+)?)\\s*=/m', $contents, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    private function isProActive(): bool
    {
        if (\defined('MAILERPRESS_PRO_VERSION')) {
            return true;
        }

        $this->ensurePluginFunctionsLoaded();

        if (function_exists('is_plugin_active')) {
            return is_plugin_active('mailerpress-pro/mailerpress-pro.php')
                || (
                    function_exists('is_plugin_active_for_network')
                    && is_plugin_active_for_network('mailerpress-pro/mailerpress-pro.php')
                );
        }

        return false;
    }

    private function ensurePluginFunctionsLoaded(): void
    {
        if (function_exists('get_plugin_data') && function_exists('is_plugin_active')) {
            return;
        }

        if (!\defined('ABSPATH')) {
            return;
        }

        $pluginFunctions = ABSPATH . 'wp-admin/includes/plugin.php';

        if (file_exists($pluginFunctions)) {
            require_once $pluginFunctions;
        }
    }

    private function formatCampaignType(string $type): string
    {
        return match ($type) {
            '' => __('Campaign', 'mailerpress'),
            'newsletter' => __('Newsletter', 'mailerpress'),
            'automated' => __('Automated campaign', 'mailerpress'),
            'automation' => __('Automation email', 'mailerpress'),
            'wp_email' => __('WordPress email', 'mailerpress'),
            'wc_email' => __('WooCommerce email', 'mailerpress'),
            'confirm_email' => __('Confirmation email', 'mailerpress'),
            'ab_test' => __('A/B test', 'mailerpress'),
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    private function formatCampaignMeta(object $campaign): string
    {
        $status = $this->formatCampaignStatus((string) $campaign->status);
        $scheduledAt = isset($campaign->scheduled_at) ? (string) $campaign->scheduled_at : '';
        $totalEmails = isset($campaign->total_emails) ? (int) $campaign->total_emails : 0;

        if ($scheduledAt !== '' && in_array((string) $campaign->status, ['scheduled', 'pending', 'in_progress'], true)) {
            $meta = sprintf(
                /* translators: %s is the scheduled send date. */
                __('Scheduled for %s', 'mailerpress'),
                $this->formatDate($scheduledAt)
            );

            if ($totalEmails > 0) {
                $meta .= sprintf(
                    /* translators: %s is the number of recipients. */
                    __(' to %s recipients', 'mailerpress'),
                    number_format_i18n($totalEmails)
                );
            }

            return $meta;
        }

        return sprintf(
            /* translators: 1: campaign status, 2: last updated date. */
            __('%1$s - updated %2$s', 'mailerpress'),
            $status,
            $this->formatDate((string) $campaign->updated_at)
        );
    }

    private function formatCampaignStatus(string $status): string
    {
        return match ($status) {
            'draft' => __('Draft', 'mailerpress'),
            'scheduled' => __('Scheduled', 'mailerpress'),
            'in_progress' => __('In progress', 'mailerpress'),
            'sent' => __('Sent', 'mailerpress'),
            'pending' => __('Pending', 'mailerpress'),
            'error' => __('Error', 'mailerpress'),
            'active' => __('Active', 'mailerpress'),
            'inactive' => __('Inactive', 'mailerpress'),
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }

    private function formatDate(string $date): string
    {
        $timezone = wp_timezone();
        $dateTime = date_create_immutable($date, $timezone);

        if (!$dateTime) {
            return __('Unknown date', 'mailerpress');
        }

        return wp_date(
            sprintf('%s, %s', get_option('date_format'), get_option('time_format')),
            $dateTime->getTimestamp(),
            $timezone
        );
    }
}
