<?php
/**
 * Campaign Archive Block - Server-side render
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

defined('ABSPATH') || exit;

use MailerPress\Actions\Shortcodes\CampaignEmail;
use MailerPress\Core\Enums\Tables;

// Get attributes with defaults
$year = $attributes['year'] ?? '';
$limit = (int) ($attributes['limit'] ?? -1);
$order = strtoupper($attributes['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$showDate = $attributes['showDate'] ?? true;
$datePosition = $attributes['datePosition'] ?? 'right';
$showSeparator = $attributes['showSeparator'] ?? true;
$separatorWidth = (int) ($attributes['separatorWidth'] ?? 1);
$separatorColor = $attributes['separatorColor'] ?? '#eeeeee';
$itemSpacing = (int) ($attributes['itemSpacing'] ?? 8);
$dateColor = $attributes['dateColor'] ?? '#666666';

// Get campaigns
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

$campaigns = $wpdb->get_results($sql, ARRAY_A) ?: [];

// Build wrapper attributes
$wrapper_attributes = get_block_wrapper_attributes();

if (empty($campaigns)) {
    ?>
    <div <?php echo $wrapper_attributes; ?>>
        <p><?php esc_html_e('No campaigns found.', 'mailerpress'); ?></p>
    </div>
    <?php
    return;
}

$total = count($campaigns);
$flexDirection = $datePosition === 'left' ? 'row-reverse' : 'row';
?>
<div <?php echo $wrapper_attributes; ?>>
    <ul style="list-style: none; margin: 0; padding: 0;">
        <?php foreach ($campaigns as $index => $campaign) :
            $url = CampaignEmail::getPublicUrl((int) $campaign['campaign_id'], $campaign['name']);
            $date = date_i18n(get_option('date_format'), strtotime($campaign['created_at']));
            $name = esc_html($campaign['name']);
            $isLast = ($index === $total - 1);
            $borderBottom = ($showSeparator && !$isLast)
                ? sprintf('%dpx solid %s', $separatorWidth, esc_attr($separatorColor))
                : 'none';
        ?>
            <li style="display: flex; flex-direction: <?php echo esc_attr($flexDirection); ?>; justify-content: space-between; align-items: center; padding: <?php echo esc_attr($itemSpacing); ?>px 0; border-bottom: <?php echo $borderBottom; ?>;">
                <a href="<?php echo esc_url($url); ?>" target="_blank" style="text-decoration: none; color: inherit;">
                    <?php echo $name; ?>
                </a>
                <?php if ($showDate) : ?>
                    <span style="color: <?php echo esc_attr($dateColor); ?>; font-size: 0.9em;"><?php echo esc_html($date); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
