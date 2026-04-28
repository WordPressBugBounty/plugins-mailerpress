<?php
/**
 * Template for My Lists form shortcode
 *
 * Available variables:
 * - $title (string)
 * - $button_text (string)
 * - $success_message (string)
 * - $error_message (string)
 * - $show_name_fields (bool)
 * - $first_name (string)
 * - $last_name (string)
 * - $email (string)
 * - $all_lists (array)
 * - $user_lists (array)
 * - $is_logged_in (bool)
 */

defined('ABSPATH') || exit;
?>

<div class="mailerpress-my-lists-wrapper">
    <?php if (!empty($title)) : ?>
        <h3 class="mailerpress-my-lists-title"><?php echo esc_html($title); ?></h3>
    <?php endif; ?>

    <form class="mailerpress-my-lists-form woocommerce-form" data-success-message="<?php echo esc_attr($success_message); ?>" data-error-message="<?php echo esc_attr($error_message); ?>" data-is-logged-in="<?php echo $is_logged_in ? '1' : '0'; ?>">

        <?php if (!$is_logged_in) : ?>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="mailerpress-email">
                    <?php esc_html_e('Email Address', 'mailerpress'); ?> <span class="required">*</span>
                </label>
                <input
                    type="email"
                    id="mailerpress-email"
                    name="email"
                    value=""
                    required
                    class="woocommerce-Input woocommerce-Input--text input-text"
                    placeholder="<?php esc_attr_e('your@email.com', 'mailerpress'); ?>"
                />
            </p>
        <?php endif; ?>

        <?php if ($show_name_fields) : ?>
            <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
                <label for="mailerpress-first-name">
                    <?php esc_html_e('First Name', 'mailerpress'); ?>
                </label>
                <input
                    type="text"
                    id="mailerpress-first-name"
                    name="first_name"
                    value="<?php echo esc_attr($first_name); ?>"
                    class="woocommerce-Input woocommerce-Input--text input-text"
                />
            </p>

            <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
                <label for="mailerpress-last-name">
                    <?php esc_html_e('Last Name', 'mailerpress'); ?>
                </label>
                <input
                    type="text"
                    id="mailerpress-last-name"
                    name="last_name"
                    value="<?php echo esc_attr($last_name); ?>"
                    class="woocommerce-Input woocommerce-Input--text input-text"
                />
            </p>
        <?php endif; ?>

        <!-- Honeypot field for bot protection - hidden from users -->
        <div style="position: absolute; left: -9999px; opacity: 0; pointer-events: none;" aria-hidden="true">
            <label for="mailerpress-website">Website</label>
            <input
                type="text"
                id="mailerpress-website"
                name="website"
                value=""
                tabindex="-1"
                autocomplete="off"
            />
        </div>

        <?php if (!empty($all_lists)) :
            $total_lists = count($all_lists);
            $visible_lists = 10;
        ?>
            <div class="mailerpress-my-lists-lists">
                <label class="mailerpress-my-lists-lists-label">
                    <?php esc_html_e('Newsletter Subscriptions', 'mailerpress'); ?>
                </label>

                <?php foreach ($all_lists as $index => $list) :
                    $list_id = (int)$list['list_id'];
                    $is_checked = in_array($list_id, $user_lists, true);
                    $is_hidden = $index >= $visible_lists && $total_lists > $visible_lists;
                ?>
                    <div class="mailerpress-my-lists-list-item <?php echo $is_hidden ? 'mailerpress-list-hidden' : ''; ?>">
                        <label>
                            <input
                                type="checkbox"
                                name="lists[]"
                                value="<?php echo esc_attr($list_id); ?>"
                                <?php checked($is_checked); ?>
                                class="mailerpress-my-lists-checkbox"
                                aria-label="<?php echo esc_attr($list['name']); ?>"
                            />
                            <span class="mailerpress-my-lists-list-content">
                                <span class="mailerpress-my-lists-list-name">
                                    <?php echo esc_html($list['name']); ?>
                                </span>
                                <?php if (!empty($list['description'])) : ?>
                                    <span class="mailerpress-my-lists-list-description">
                                        <?php echo esc_html($list['description']); ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </label>
                    </div>
                <?php endforeach; ?>

                <?php if ($total_lists > $visible_lists) : ?>
                    <button type="button" class="mailerpress-show-more-lists">
                        <?php
                        $hidden_count = $total_lists - $visible_lists;
                        printf(
                            esc_html(_n('+ %s other list', '+ %s other lists', $hidden_count, 'mailerpress')),
                            $hidden_count
                        );
                        ?>
                    </button>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <p class="mailerpress-my-lists-no-lists">
                <?php esc_html_e('No newsletter lists available at the moment.', 'mailerpress'); ?>
            </p>
        <?php endif; ?>

        <div class="woocommerce-form-row form-row">
            <button
                type="submit"
                class="mailerpress-my-lists-submit woocommerce-Button button wp-element-button"
            >
                <?php echo esc_html($button_text); ?>
            </button>
        </div>

        <div class="mailerpress-my-lists-message" style="display: none;" role="alert"></div>
    </form>
</div>
