<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use MailerPress\Services\RateLimiter;
use MailerPress\Services\RateLimitConfig;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class MyLists
{
    // Form identifier for rate limiting
    private const MY_LISTS_FORM_IDENTIFIER = 'my_lists_form';

    /**
     * Get current logged-in user's contact data and lists
     */
    #[Endpoint(
        'my-lists/current',
        methods: 'GET'
    )]
    public function getCurrentUserLists(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'not_logged_in',
                __('You must be logged in to access this resource.', 'mailerpress'),
                ['status' => 401]
            );
        }

        global $wpdb;
        $user = wp_get_current_user();
        $email = $user->user_email;

        $contact_table = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contact = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$contact_table} WHERE email = %s", $email)
        );

        // Get all available lists
        $lists_table = Tables::get(Tables::MAILERPRESS_LIST);
        $all_lists = $wpdb->get_results("SELECT * FROM {$lists_table} ORDER BY name ASC", ARRAY_A);

        $user_lists = [];
        $subscription_status = 'subscribed';

        if ($contact) {
            $subscription_status = sanitize_key($contact->subscription_status ?? 'subscribed');
            $subscription_status = $subscription_status === 'subscribed' ? 'subscribed' : 'unsubscribed';

            // Get contact's current lists
            $contact_lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
            if ($subscription_status === 'subscribed') {
                $user_lists = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT list_id FROM {$contact_lists_table} WHERE contact_id = %d",
                        $contact->contact_id
                    )
                );
                $user_lists = array_map('intval', $user_lists);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'contact' => $contact ? [
                    'contact_id' => $contact->contact_id,
                    'email' => $contact->email,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'subscription_status' => $subscription_status,
                ] : null,
                'all_lists' => $all_lists,
                'user_lists' => $user_lists,
            ],
        ]);
    }

    /**
     * Update current logged-in user's lists subscriptions
     */
    #[Endpoint(
        'my-lists/update',
        methods: 'POST'
    )]
    public function updateUserLists(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'not_logged_in',
                __('You must be logged in to update subscriptions.', 'mailerpress'),
                ['status' => 401]
            );
        }

        // Honeypot Check (Bot Protection)
        if (RateLimitConfig::isHoneypotEnabled()) {
            $honeypot = sanitize_text_field($request->get_param('website') ?? '');
            if (!empty($honeypot)) {
                // Silently reject bot submissions
                return new WP_REST_Response([
                    'success' => true,
                    'message' => __('Your subscription preferences have been updated successfully.', 'mailerpress'),
                ], 200);
            }
        }

        // Rate Limiting Check
        if (RateLimitConfig::isEnabled()) {
            $ipAddress = $this->getClientIp();
            $limit = RateLimitConfig::getLimit();
            $window = RateLimitConfig::getWindow();

            if (!RateLimiter::checkLimit(self::MY_LISTS_FORM_IDENTIFIER, $ipAddress, $limit, $window)) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    __('Too many requests. Please try again later.', 'mailerpress'),
                    ['status' => 429, 'retry_after' => $window]
                );
            }
        }

        global $wpdb;
        $user = wp_get_current_user();
        $email = $user->user_email;

        // Validate email
        if (!is_email($email)) {
            return new WP_Error(
                'invalid_email',
                __('Invalid email address.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Get and sanitize input data
        $first_name = sanitize_text_field($request->get_param('first_name') ?? '');
        $last_name = sanitize_text_field($request->get_param('last_name') ?? '');
        $lists = $request->get_param('lists') ?? [];
        $raw_status = sanitize_key($request->get_param('status') ?? '');
        $allowed_statuses = ['subscribed', 'unsubscribed'];

        if ($raw_status !== '' && ! in_array($raw_status, $allowed_statuses, true)) {
            return new WP_Error(
                'invalid_subscription_status',
                __('Invalid subscription status.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Validate lists is an array
        if (!is_array($lists)) {
            $lists = [];
        }

        // Sanitize and validate list IDs
        $lists = array_map('intval', $lists);
        $lists = array_filter($lists, fn($id) => $id > 0);

        // Validate that all lists exist
        $lists_table = Tables::get(Tables::MAILERPRESS_LIST);
        $valid_lists = [];

        if (!empty($lists)) {
            $placeholders = implode(',', array_fill(0, count($lists), '%d'));
            $valid_lists = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT list_id FROM {$lists_table} WHERE list_id IN ({$placeholders})",
                    ...$lists
                )
            );
            $valid_lists = array_map('intval', $valid_lists);
        }

        // Get or create contact
        $contact_table = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contact = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$contact_table} WHERE email = %s", $email)
        );
        $previous_status = $contact ? sanitize_key($contact->subscription_status ?? '') : '';
        $subscription_status = $raw_status !== ''
            ? $raw_status
            : ($contact && $previous_status !== 'subscribed' ? 'unsubscribed' : 'subscribed');
        $contact_was_created = false;

        if ($subscription_status === 'unsubscribed') {
            $valid_lists = [];
        }

        if ($contact) {
            // Update existing contact
            $contact_id = $contact->contact_id;

            // Update name fields if provided
            $update_data = [
                'subscription_status' => $subscription_status,
                'updated_at' => current_time('mysql'),
            ];
            $update_format = ['%s', '%s'];

            if (!empty($first_name)) {
                $update_data['first_name'] = $first_name;
                $update_format[] = '%s';
            }

            if (!empty($last_name)) {
                $update_data['last_name'] = $last_name;
                $update_format[] = '%s';
            }

            $wpdb->update(
                $contact_table,
                $update_data,
                ['contact_id' => $contact_id],
                $update_format,
                ['%d']
            );

            do_action('mailerpress_contact_updated', $contact_id);
        } else {
            // Create new contact
            // User is already logged in, so they're confirmed
            $unsubscribe_token = wp_generate_uuid4();

            // Get user meta for first/last name if not provided
            if (empty($first_name)) {
                $first_name = get_user_meta($user->ID, 'first_name', true) ?: $user->first_name ?: '';
            }
            if (empty($last_name)) {
                $last_name = get_user_meta($user->ID, 'last_name', true) ?: $user->last_name ?: '';
            }

            $wpdb->insert(
                $contact_table,
                [
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'subscription_status' => $subscription_status,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'unsubscribe_token' => $unsubscribe_token,
                    'opt_in_source' => 'my_lists_form',
                    'opt_in_details' => 'Subscribed via My Lists form',
                    'access_token' => bin2hex(random_bytes(32)),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            $contact_id = $wpdb->insert_id;
            $contact_was_created = true;

            if (!$contact_id) {
                return new WP_Error(
                    'contact_creation_failed',
                    __('Failed to create contact.', 'mailerpress'),
                    ['status' => 500]
                );
            }

            if ($subscription_status === 'subscribed') {
                do_action('mailerpress_contact_created', $contact_id);
            }
        }

        if (!$contact_was_created && $subscription_status === 'unsubscribed' && $previous_status !== 'unsubscribed') {
            do_action('mailerpress_contact_unsubscribed', $contact_id);
        }

        if (!$contact_was_created && $subscription_status === 'subscribed' && $previous_status !== 'subscribed') {
            do_action('mailerpress_contact_created', $contact_id);
        }

        // Update lists associations
        $contact_lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        // Get current lists
        $current_lists = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT list_id FROM {$contact_lists_table} WHERE contact_id = %d",
                $contact_id
            )
        );
        $current_lists = array_map('intval', $current_lists);

        // Determine lists to add and remove
        $lists_to_add = array_diff($valid_lists, $current_lists);
        $lists_to_remove = array_diff($current_lists, $valid_lists);

        // Remove from unchecked lists
        foreach ($lists_to_remove as $list_id) {
            $wpdb->delete(
                $contact_lists_table,
                [
                    'contact_id' => $contact_id,
                    'list_id' => $list_id,
                ],
                ['%d', '%d']
            );
            do_action('mailerpress_user_unsubscribed_from_list', $contact_id, $list_id);
        }

        // Add to new lists
        foreach ($lists_to_add as $list_id) {
            $wpdb->insert(
                $contact_lists_table,
                [
                    'contact_id' => $contact_id,
                    'list_id' => $list_id,
                ],
                ['%d', '%d']
            );
            do_action('mailerpress_user_subscribed_to_list', $contact_id, $list_id);
        }

        // Trigger global lists updated hook
        if (!empty($lists_to_add) || !empty($lists_to_remove)) {
            do_action('mailerpress_user_lists_updated', $contact_id, $current_lists, $valid_lists);
        }

        // Get updated contact data
        $updated_contact = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$contact_table} WHERE contact_id = %d", $contact_id)
        );

        $updated_lists = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT list_id FROM {$contact_lists_table} WHERE contact_id = %d",
                $contact_id
            )
        );

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Your subscription preferences have been updated successfully.', 'mailerpress'),
            'data' => [
                'contact' => [
                    'contact_id' => $updated_contact->contact_id,
                    'email' => $updated_contact->email,
                    'first_name' => $updated_contact->first_name,
                    'last_name' => $updated_contact->last_name,
                    'subscription_status' => $updated_contact->subscription_status,
                ],
                'lists' => $updated_contact->subscription_status === 'subscribed'
                    ? array_map('intval', $updated_lists)
                    : [],
            ],
        ]);
    }

    /**
     * Subscribe a non-logged-in user to lists
     * Creates contact and sends confirmation email
     */
    #[Endpoint(
        'my-lists/subscribe',
        'POST',
        '__return_true' // Allow non-logged-in users
    )]
    public function subscribeVisitor(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        // Honeypot Check (Bot Protection)
        if (RateLimitConfig::isHoneypotEnabled()) {
            $honeypot = sanitize_text_field($request->get_param('website') ?? '');
            if (!empty($honeypot)) {
                // Silently reject bot submissions
                return new WP_REST_Response([
                    'success' => true,
                    'message' => __('Thank you for subscribing! Please check your email to confirm your subscription.', 'mailerpress'),
                ], 200);
            }
        }

        // Rate Limiting Check
        if (RateLimitConfig::isEnabled()) {
            $ipAddress = $this->getClientIp();
            $limit = RateLimitConfig::getLimit();
            $window = RateLimitConfig::getWindow();

            if (!RateLimiter::checkLimit(self::MY_LISTS_FORM_IDENTIFIER, $ipAddress, $limit, $window)) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    __('Too many requests. Please try again later.', 'mailerpress'),
                    ['status' => 429, 'retry_after' => $window]
                );
            }
        }

        global $wpdb;

        // Get and validate email
        $email = sanitize_email($request->get_param('email'));

        if (empty($email) || !is_email($email)) {
            return new WP_Error(
                'invalid_email',
                __('Please provide a valid email address.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Get and sanitize input data
        $first_name = sanitize_text_field($request->get_param('first_name') ?? '');
        $last_name = sanitize_text_field($request->get_param('last_name') ?? '');
        $lists = $request->get_param('lists') ?? [];

        // Validate lists is an array
        if (!is_array($lists)) {
            $lists = [];
        }

        // Sanitize and validate list IDs
        $lists = array_map('intval', $lists);
        $lists = array_filter($lists, fn($id) => $id > 0);

        // Require at least one list
        if (empty($lists)) {
            return new WP_Error(
                'no_lists_selected',
                __('Please select at least one newsletter to subscribe to.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Validate that all lists exist
        $lists_table = Tables::get(Tables::MAILERPRESS_LIST);
        $valid_lists = [];

        if (!empty($lists)) {
            $placeholders = implode(',', array_fill(0, count($lists), '%d'));
            $valid_lists = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT list_id FROM {$lists_table} WHERE list_id IN ({$placeholders})",
                    ...$lists
                )
            );
            $valid_lists = array_map('intval', $valid_lists);
        }

        if (empty($valid_lists)) {
            return new WP_Error(
                'invalid_lists',
                __('The selected newsletters are not available.', 'mailerpress'),
                ['status' => 400]
            );
        }

        // Check if contact already exists
        $contact_table = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contact = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$contact_table} WHERE email = %s", $email)
        );

        $contact_id = null;

        if ($contact) {
            // Contact exists - update it
            $contact_id = $contact->contact_id;

            // Update name fields if provided
            $update_data = ['updated_at' => current_time('mysql')];
            $update_format = ['%s'];

            if (!empty($first_name)) {
                $update_data['first_name'] = $first_name;
                $update_format[] = '%s';
            }

            if (!empty($last_name)) {
                $update_data['last_name'] = $last_name;
                $update_format[] = '%s';
            }

            $wpdb->update(
                $contact_table,
                $update_data,
                ['contact_id' => $contact_id],
                $update_format,
                ['%d']
            );
        } else {
            // Create new contact
            $unsubscribe_token = wp_generate_uuid4();

            // Check double opt-in settings for non-logged-in users
            $signupConfirmation = mailerpress_get_signup_confirmation_option();

            // Determine subscription status based on double opt-in settings
            $subscriptionStatus = 'subscribed'; // Default for logged-in users
            if (!empty($signupConfirmation) && true === $signupConfirmation['enableSignupConfirmation']) {
                $subscriptionStatus = 'pending'; // Requires email confirmation
            }

            $wpdb->insert(
                $contact_table,
                [
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'subscription_status' => $subscriptionStatus,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'unsubscribe_token' => $unsubscribe_token,
                    'opt_in_source' => 'my_lists_form',
                    'opt_in_details' => 'Subscribed via My Lists form (public)',
                    'access_token' => bin2hex(random_bytes(32)),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            $contact_id = $wpdb->insert_id;

            if (!$contact_id) {
                return new WP_Error(
                    'contact_creation_failed',
                    __('Failed to create your subscription. Please try again.', 'mailerpress'),
                    ['status' => 500]
                );
            }

            // Store contact language for translated confirmation emails
            $lang = apply_filters('wpml_current_language', null);
            if ($lang) {
                $customFieldsTable = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
                $wpdb->replace(
                    $customFieldsTable,
                    [
                        'contact_id' => $contact_id,
                        'field_key' => '_language',
                        'field_value' => $lang,
                    ],
                    ['%d', '%s', '%s']
                );
            }

            // This hook will automatically send double opt-in email if status is 'pending'
            do_action('mailerpress_contact_created', $contact_id);
        }

        // Add to selected lists
        $contact_lists_table = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        // Get current lists for this contact
        $current_lists = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT list_id FROM {$contact_lists_table} WHERE contact_id = %d",
                $contact_id
            )
        );
        $current_lists = array_map('intval', $current_lists);

        // Add to new lists (don't remove existing ones)
        $lists_to_add = array_diff($valid_lists, $current_lists);

        foreach ($lists_to_add as $list_id) {
            $wpdb->insert(
                $contact_lists_table,
                [
                    'contact_id' => $contact_id,
                    'list_id' => $list_id,
                ],
                ['%d', '%d']
            );
            do_action('mailerpress_user_subscribed_to_list', $contact_id, $list_id);
        }

        // Trigger global subscription hook
        if (!empty($lists_to_add)) {
            do_action('mailerpress_visitor_subscribed', $contact_id, $valid_lists);
        }

        // Get subscription status to determine success message
        $final_contact = $wpdb->get_row(
            $wpdb->prepare("SELECT subscription_status FROM {$contact_table} WHERE contact_id = %d", $contact_id)
        );

        // Different message based on subscription status
        $message = $final_contact && $final_contact->subscription_status === 'pending'
            ? __('Thank you for subscribing! Please check your email to confirm your subscription.', 'mailerpress')
            : __('Thank you for subscribing! You have been added to our mailing list.', 'mailerpress');

        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data' => [
                'contact_id' => $contact_id,
                'requires_confirmation' => $final_contact && $final_contact->subscription_status === 'pending',
            ],
        ]);
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $remote_addr = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');

        /** @see mailerpress_trusted_proxies filter in ApiAuthentication::getClientIp() */
        $trusted_proxies = apply_filters('mailerpress_trusted_proxies', []);

        $is_trusted = false;
        foreach ($trusted_proxies as $proxy) {
            if ($remote_addr === $proxy) {
                $is_trusted = true;
                break;
            }
        }

        if ($is_trusted) {
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                return sanitize_text_field(trim($_SERVER['HTTP_CF_CONNECTING_IP']));
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                return sanitize_text_field(trim($ips[0]));
            }
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                return sanitize_text_field(trim($_SERVER['HTTP_X_REAL_IP']));
            }
        }

        return $remote_addr;
    }
}
