<?php

defined('ABSPATH') || exit;

/**
 * Shown instead of the Manage subscription form when the link is expired, unsigned
 * (sent before links expired) or tampered with. No personal data is displayed here.
 *
 * @var array  $linkParams cid, exp and sig from the link.
 * @var string $notice     Result of a previous request: sent, wait, unsubscribed or error.
 * @var int    $ttlDays    How many days a new link stays valid.
 */

$linkParams = isset($linkParams) && is_array($linkParams) ? $linkParams : ['cid' => '', 'exp' => '', 'sig' => ''];
$notice = isset($notice) ? (string) $notice : '';
$ttlDays = isset($ttlDays) ? (int) $ttlDays : 30;

$notices = [
    'sent' => __('We have sent a new link to your email address. Check your inbox.', 'mailerpress'),
    'wait' => __('A new link was sent a few minutes ago. Check your inbox and your spam folder.', 'mailerpress'),
    'unsubscribed' => __('You have been unsubscribed from our emails.', 'mailerpress'),
    'error' => __('Something went wrong. Please try again.', 'mailerpress'),
];
?>

<div class="mailerpress-manage-subscription mailerpress-manage-subscription--expired">
    <?php if (isset($notices[$notice])) : ?>
        <p class="mailerpress-manage-subscription-notice" role="status">
            <strong><?php echo esc_html($notices[$notice]); ?></strong>
        </p>
    <?php endif; ?>

    <?php if ('unsubscribed' !== $notice) : ?>
        <p>
            <?php
            echo esc_html(sprintf(
                /* translators: %d: number of days a link stays valid */
                _n(
                    'This link has expired. To protect your personal information, links to manage your subscription are valid for %d day.',
                    'This link has expired. To protect your personal information, links to manage your subscription are valid for %d days.',
                    $ttlDays,
                    'mailerpress'
                ),
                $ttlDays
            ));
            ?>
        </p>

        <?php if ('' !== $linkParams['cid']) : ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="mailerpress_manage_link">
                <input type="hidden" name="cid" value="<?php echo esc_attr($linkParams['cid']); ?>">
                <input type="hidden" name="exp" value="<?php echo esc_attr($linkParams['exp']); ?>">
                <input type="hidden" name="sig" value="<?php echo esc_attr($linkParams['sig']); ?>">
                <?php wp_nonce_field('mailerpress_manage_link', 'mailerpress_nonce'); ?>

                <p>
                    <button class="button mailerpress-submit-btn" type="submit" name="request" value="resend">
                        <?php esc_html_e('Email me a new link', 'mailerpress'); ?>
                    </button>
                </p>
                <p>
                    <?php esc_html_e('Just want to stop receiving our emails?', 'mailerpress'); ?>
                    <button class="button-link mailerpress-unsubscribe-btn" type="submit" name="request" value="unsubscribe">
                        <?php esc_html_e('Unsubscribe from all emails', 'mailerpress'); ?>
                    </button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
