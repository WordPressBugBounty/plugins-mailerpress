<?php
    defined('ABSPATH') || exit;

    if (!isset($title) || !isset($unsubscribe_url)) {
        wp_die(esc_html__('Invalid template parameters.', 'mailerpress'));
    }

    if (!wp_http_validate_url($unsubscribe_url)) {
        wp_die(esc_html__('Invalid unsubscribe URL.', 'mailerpress'));
    }

    $isPreview = isset($_GET['mp_preview']) && wp_validate_boolean(sanitize_text_field(wp_unslash($_GET['mp_preview'])));
?>

<p>
    <?php echo esc_html($title); ?>
    <br>
    <a href="<?php echo $isPreview ? '#' : esc_url($unsubscribe_url); ?>">
        <?php esc_html_e('Yes, unsubscribe me', 'mailerpress'); ?>
    </a>
</p>
