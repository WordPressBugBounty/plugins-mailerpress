<?php
/**
 * Template for My Lists form shortcode
 *
 * Available variables:
 * - $title (string)
 * - $button_text (string)
 * - $success_message (string)
 * - $error_message (string)
 * - $loading_text (string)
 * - $show_name_fields (bool)
 * - $show_list_descriptions (bool)
 * - $first_name (string)
 * - $last_name (string)
 * - $email (string)
 * - $all_lists (array)
 * - $user_lists (array)
 * - $is_logged_in (bool)
 */

defined('ABSPATH') || exit;

$style_attr = static function (string $style): string {
    return '' !== $style ? ' style="' . esc_attr($style) . '"' : '';
};
?>

<div class="<?php echo esc_attr($wrapper_classes); ?>"<?php echo $style_attr($wrapper_style); ?>>
    <?php if (!empty($title)) : ?>
        <h3 class="<?php echo esc_attr($title_classes); ?>"<?php echo $style_attr($title_style); ?>><?php echo esc_html($title); ?></h3>
    <?php endif; ?>

    <form class="<?php echo esc_attr($form_classes); ?>"<?php echo $style_attr($form_style); ?> data-success-message="<?php echo esc_attr($success_message); ?>" data-error-message="<?php echo esc_attr($error_message); ?>" data-loading-text="<?php echo esc_attr($loading_text); ?>" data-is-logged-in="<?php echo $is_logged_in ? '1' : '0'; ?>">

        <?php if (!$is_logged_in) : ?>
            <p class="<?php echo esc_attr($field_classes . ' woocommerce-form-row--wide form-row-wide'); ?>"<?php echo $style_attr($field_style); ?>>
                <label for="mailerpress-email" class="<?php echo esc_attr($label_classes); ?>"<?php echo $style_attr($label_style); ?>>
                    <?php echo esc_html($email_label); ?> <span class="required">*</span>
                </label>
                <input
                    type="email"
                    id="mailerpress-email"
                    name="email"
                    value=""
                    required
                    class="<?php echo esc_attr($input_classes); ?>"
                    placeholder="<?php echo esc_attr($email_placeholder); ?>"
                    <?php echo $style_attr($input_style); ?>
                />
            </p>
        <?php endif; ?>

        <?php if ($is_logged_in) : ?>
            <p class="<?php echo esc_attr($field_classes . ' woocommerce-form-row--wide form-row-wide'); ?>"<?php echo $style_attr($field_style); ?>>
                <label for="mailerpress-subscription-status" class="<?php echo esc_attr($label_classes); ?>"<?php echo $style_attr($label_style); ?>>
                    <?php echo esc_html($subscription_status_label); ?>
                </label>
                <select
                    id="mailerpress-subscription-status"
                    name="status"
                    class="<?php echo esc_attr($input_classes); ?>"
                    <?php echo $style_attr($input_style); ?>
                >
                    <option value="subscribed" <?php selected($subscription_status, 'subscribed'); ?>>
                        <?php echo esc_html($subscribed_label); ?>
                    </option>
                    <option value="unsubscribed" <?php selected($subscription_status, 'unsubscribed'); ?>>
                        <?php echo esc_html($unsubscribed_label); ?>
                    </option>
                </select>
            </p>
        <?php endif; ?>

        <?php if ($show_name_fields) : ?>
            <div class="mailerpress-my-lists-name-fields">
                <p class="<?php echo esc_attr($field_classes . ' woocommerce-form-row--first form-row-first'); ?>"<?php echo $style_attr($field_style); ?>>
                    <label for="mailerpress-first-name" class="<?php echo esc_attr($label_classes); ?>"<?php echo $style_attr($label_style); ?>>
                        <?php echo esc_html($first_name_label); ?>
                    </label>
                    <input
                        type="text"
                        id="mailerpress-first-name"
                        name="first_name"
                        value="<?php echo esc_attr($first_name); ?>"
                        class="<?php echo esc_attr($input_classes); ?>"
                        <?php echo $style_attr($input_style); ?>
                    />
                </p>

                <p class="<?php echo esc_attr($field_classes . ' woocommerce-form-row--last form-row-last'); ?>"<?php echo $style_attr($field_style); ?>>
                    <label for="mailerpress-last-name" class="<?php echo esc_attr($label_classes); ?>"<?php echo $style_attr($label_style); ?>>
                        <?php echo esc_html($last_name_label); ?>
                    </label>
                    <input
                        type="text"
                        id="mailerpress-last-name"
                        name="last_name"
                        value="<?php echo esc_attr($last_name); ?>"
                        class="<?php echo esc_attr($input_classes); ?>"
                        <?php echo $style_attr($input_style); ?>
                    />
                </p>
            </div>
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
        ?>
            <div class="<?php echo esc_attr($lists_classes); ?>"<?php echo $style_attr($lists_style); ?>>
                <?php if (!empty($lists_label)) : ?>
                    <label class="<?php echo esc_attr($lists_label_classes); ?>"<?php echo $style_attr($lists_label_style); ?>>
                        <?php echo esc_html($lists_label); ?>
                    </label>
                <?php endif; ?>

                <div class="<?php echo esc_attr($list_items_classes); ?>"<?php echo $style_attr($list_items_style); ?>>
                    <?php foreach ($all_lists as $index => $list) :
                        $list_id = (int)$list['list_id'];
                        $is_checked = in_array($list_id, $user_lists, true);
                        $is_hidden = $visible_lists > 0 && $index >= $visible_lists && $total_lists > $visible_lists;
                    ?>
                        <div class="<?php echo esc_attr($list_item_classes . ($is_hidden ? ' mailerpress-list-hidden' : '')); ?>"<?php echo $style_attr($list_item_style); ?>>
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
                                    <?php if ($show_list_descriptions && !empty($list['description'])) : ?>
                                        <span class="mailerpress-my-lists-list-description">
                                            <?php echo esc_html($list['description']); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($visible_lists > 0 && $total_lists > $visible_lists) : ?>
                    <button type="button" class="mailerpress-show-more-lists">
                        <?php
                        $hidden_count = $total_lists - $visible_lists;
                        if (!empty($show_more_text)) {
                            $show_more_label = str_replace('{count}', (string) $hidden_count, $show_more_text);
                            if (str_contains($show_more_label, '%s') || str_contains($show_more_label, '%d')) {
                                $show_more_label = sprintf($show_more_label, $hidden_count);
                            }

                            echo esc_html($show_more_label);
                        } else {
                            printf(
                                esc_html(_n('+ %s other list', '+ %s other lists', $hidden_count, 'mailerpress')),
                                $hidden_count
                            );
                        }
                        ?>
                    </button>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <p class="mailerpress-my-lists-no-lists">
                <?php echo esc_html($no_lists_message); ?>
            </p>
        <?php endif; ?>

        <div class="mailerpress-my-lists-submit-wrapper woocommerce-form-row form-row">
            <button
                type="submit"
                class="<?php echo esc_attr($button_classes); ?>"
                <?php echo $style_attr($button_style); ?>
            >
                <?php echo esc_html($button_text); ?>
            </button>
        </div>

        <div class="<?php echo esc_attr($message_classes); ?>" style="<?php echo esc_attr(trim('display: none; ' . $message_style)); ?>" role="alert"></div>
    </form>
</div>
