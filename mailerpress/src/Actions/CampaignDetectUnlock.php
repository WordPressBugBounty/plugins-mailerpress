<?php

namespace MailerPress\Actions;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Enums\Tables;

class CampaignDetectUnlock
{
    #[Action('admin_init')]
    function maybeUnlockCampaign()
    {
        if (!isset($_COOKIE['mailerpress_lock_data'])) {
            return;
        }

        // Decode the cookie data safely
        $rawCookie = $_COOKIE['mailerpress_lock_data'];
        $lockData = json_decode($rawCookie, true);

        // Validate JSON parsing
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($lockData)) {
            $this->clearCookie();
            return;
        }

        // Validate required fields exist and are numeric
        if (empty($lockData['userId']) || empty($lockData['campaignId'])) {
            $this->clearCookie();
            return;
        }

        // Security: Verify that the cookie's userId matches the current WordPress user
        // This prevents attackers from forging cookies with other users' IDs
        $current_user_id = get_current_user_id();
        $cookie_user_id = intval($lockData['userId']);

        if ($current_user_id === 0 || $current_user_id !== $cookie_user_id) {
            $this->clearCookie();
            return;
        }

        global $wpdb;
        $table = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $campaign_id = intval($lockData['campaignId']);
        $current_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '';

        // If user left the editor page, unlock the campaign
        if (strpos($current_url, '/mailerpress-editor') === false) {
            $wpdb->update(
                $table,
                [
                    'editing_user_id' => null,
                    'editing_started_at' => null
                ],
                [
                    'campaign_id' => $campaign_id,
                    'editing_user_id' => $current_user_id
                ]
            );

            $this->clearCookie();
        }
    }

    /**
     * Clear the lock data cookie securely
     */
    private function clearCookie(): void
    {
        setcookie('mailerpress_lock_data', '', time() - 3600, '/', '', is_ssl(), true);
    }
}