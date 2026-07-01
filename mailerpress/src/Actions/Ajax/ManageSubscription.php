<?php

namespace MailerPress\Actions\Ajax;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;
use MailerPress\Models\Contacts;

class ManageSubscription
{
    private Contacts $contactModel;

    /**
     * @param Contacts $contactModel
     */
    public function __construct(Contacts $contactModel)
    {
        $this->contactModel = $contactModel;
    }

    #[Action(['wp_ajax_update_mailerpress_contact', 'wp_ajax_nopriv_update_mailerpress_contact'])]
    public function handle()
    {
        global $wpdb;
        $contactTable = Tables::get(Tables::MAILERPRESS_CONTACT);
        $contactListTable = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);

        check_ajax_referer('mailerpress_update_contact_nonce', 'mailerpress_nonce');

        $accessToken = isset($_POST['mailerpress_cid']) ? sanitize_text_field(wp_unslash($_POST['mailerpress_cid'])) : '';
        if (empty($accessToken)) {
            wp_send_json_error([
                'message' => __('Invalid contact token.', 'mailerpress'),
            ], 400);
        }

        // Validate and sanitize names
        $firstName = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $lastName = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';

        // Retrieve contact by token
        $contactEntity = $this->contactModel->getByAccessToken($accessToken);
        if (!$contactEntity) {
            wp_send_json_error([
                'message' => __('Contact not found.', 'mailerpress'),
            ], 404);
        }

        // Check if contact is active
        if ($contactEntity->status === 'deleted' || $contactEntity->status === 'disabled') {
            wp_send_json_error([
                'message' => __('This contact is no longer active.', 'mailerpress'),
            ], 403);
        }

        $contactIdInt = (int) $contactEntity->contact_id;
        $previousStatus = $contactEntity->subscription_status ?? '';

        $allowed_statuses = [ 'subscribed', 'unsubscribed' ];
        $raw_status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        if ( '' === $raw_status ) {
            $newStatus = in_array( $previousStatus, $allowed_statuses, true ) ? $previousStatus : 'subscribed';
        } elseif ( in_array( $raw_status, $allowed_statuses, true ) ) {
            $newStatus = $raw_status;
        } else {
            wp_send_json_error([
                'message' => __('Invalid subscription status.', 'mailerpress'),
            ], 400);
        }

        $skipLists      = ! empty( $_POST['skip_lists'] );

        if ($skipLists) {
            $update = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$contactTable}
                 SET subscription_status = %s, first_name = %s, last_name = %s
                 WHERE access_token = %s",
                    $newStatus,
                    $firstName,
                    $lastName,
                    $accessToken
                )
            );
        } else {
            // Lists sent from form (array of list IDs)
            $submittedLists = isset($_POST['subscribed_lists']) ? array_map('intval', $_POST['subscribed_lists']) : [];

            // Update contact
            $update = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$contactTable}
                 SET subscription_status = %s, first_name = %s, last_name = %s
                 WHERE access_token = %s",
                    $newStatus,
                    $firstName,
                    $lastName,
                    $accessToken
                )
            );

            // Remove all current list associations
            $wpdb->delete($contactListTable, ['contact_id' => $contactIdInt]);

            // Insert new list subscriptions if any
            if (!empty($submittedLists)) {
                foreach ($submittedLists as $listId) {
                    $wpdb->insert($contactListTable, [
                        'contact_id' => $contactIdInt,
                        'list_id'    => $listId,
                    ]);
                }
            }
        }

        if ($update !== false) {
            if ($newStatus === 'unsubscribed') {
                do_action('mailerpress_contact_unsubscribed', $contactIdInt);
            }

            // Trigger workflow when contact becomes subscribed (re-subscription)
            if ($newStatus === 'subscribed' && $previousStatus !== 'subscribed') {
                \do_action('mailerpress_contact_created', $contactIdInt);
            }

            wp_send_json_success([
                'message' => __('Your subscription preferences have been updated successfully.', 'mailerpress'),
            ]);
        }

        wp_send_json_error([
            'message' => __('We couldn’t update your subscription preferences. Please try again later.', 'mailerpress'),
        ]);
    }


}
