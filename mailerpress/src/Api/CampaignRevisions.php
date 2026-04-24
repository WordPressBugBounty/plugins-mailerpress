<?php

namespace MailerPress\Api;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\Enums\Tables;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class CampaignRevisions
{
    // Add a new revision
    #[Endpoint('campaign/revision/(?P<id>\d+)', methods: 'POST', permissionCallback: [Permissions::class, 'canManageCampaign'])]
    public function addRevision(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $campaign_id = intval($request['id']);
        $new_json = wp_json_encode($request->get_param('json')); // encode new revision
        $user_id = get_current_user_id();
        $tableName = Tables::get(Tables::MAILERPRESS_CAMPAIGN_REVISIONS);

        // Get the latest revision
        $latest_revision = $wpdb->get_var($wpdb->prepare("
        SELECT json
        FROM $tableName
        WHERE campaign_id = %d
        ORDER BY created_at DESC
        LIMIT 1
    ", $campaign_id));

        // If the new revision is identical, do not insert
        if ($latest_revision && $latest_revision === $new_json) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No major changes detected, revision not added.'
            ]);
        }

        // Insert new revision
        $wpdb->insert(
            $tableName,
            [
                'campaign_id' => $campaign_id,
                'json' => $new_json,
                'created_by' => $user_id,
                'created_at' => current_time('mysql'),
            ]
        );

        $revision_id = $wpdb->insert_id;

        // Allow developers to filter the max revisions to keep
        $max_revisions = apply_filters('mailerpress_max_revisions', 5);

        // Keep only the last N revisions
        $wpdb->query($wpdb->prepare("
        DELETE FROM $tableName
        WHERE campaign_id = %d
        AND revision_id NOT IN (
            SELECT revision_id FROM (
                SELECT revision_id
                FROM $tableName
                WHERE campaign_id = %d
                ORDER BY created_at DESC
                LIMIT %d
            ) AS keep_revisions
        )
    ", $campaign_id, $campaign_id, $max_revisions));

        return new WP_REST_Response([
            'success' => true,
            'revision_id' => $revision_id
        ]);
    }

    // Get a single revision
    #[Endpoint('campaign/(?P<id>\d+)/revision/(?P<revision_id>\d+)', methods: 'GET', permissionCallback: [Permissions::class, 'canManageCampaign'])]
    public function getRevision(WP_REST_Request $request): WP_Error|array|\stdClass
    {
        global $wpdb;
        $campaign_id = intval($request['id']);
        $revision_id = intval($request['revision_id']);
        $tableName = Tables::get(Tables::MAILERPRESS_CAMPAIGN_REVISIONS);

        $revision = $wpdb->get_row($wpdb->prepare("
            SELECT revision_id, created_at, created_by, json
            FROM $tableName
            WHERE revision_id = %d AND campaign_id = %d
        ", $revision_id, $campaign_id), ARRAY_A);

        if (!$revision) {
            return new WP_Error('not_found', 'Revision not found', ['status' => 404]);
        }

        return $revision;
    }

    // Get all revisions for a campaign
    #[Endpoint('campaign/(?P<id>\d+)/revisions', methods: 'GET', permissionCallback: [Permissions::class, 'canManageCampaign'])]
    public function getAllRevisions(WP_REST_Request $request): array|WP_Error
    {
        global $wpdb;
        $campaign_id = intval($request['id']);
        $tableName = Tables::get(Tables::MAILERPRESS_CAMPAIGN_REVISIONS);

        $revisions = $wpdb->get_results($wpdb->prepare("
            SELECT revision_id, created_at, created_by, json
            FROM $tableName
            WHERE campaign_id = %d
            ORDER BY created_at DESC
        ", $campaign_id), ARRAY_A);

        return $revisions ?: [];
    }

    // Restore a revision
    #[Endpoint('campaign/(?P<id>\d+)/restore-revision/(?P<revision_id>\d+)', methods: 'POST', permissionCallback: [Permissions::class, 'canManageCampaign'])]
    public function restoreRevision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $campaign_id = intval($request['id']);
        $revision_id = intval($request['revision_id']);
        $tableName = Tables::get(Tables::MAILERPRESS_CAMPAIGN_REVISIONS);
        $campaignTable = Tables::get(Tables::MAILERPRESS_CAMPAIGNS);

        $revision = $wpdb->get_row($wpdb->prepare("
            SELECT json FROM $tableName
            WHERE revision_id = %d AND campaign_id = %d
        ", $revision_id, $campaign_id), ARRAY_A);

        if (!$revision) {
            return new WP_Error('not_found', 'Revision not found', ['status' => 404]);
        }

        // Update campaign JSON with revision
        $wpdb->update(
            $campaignTable,
            ['content_html' => $revision['json']],
            ['campaign_id' => $campaign_id]
        );

        return new WP_REST_Response(['success' => true]);
    }
}
