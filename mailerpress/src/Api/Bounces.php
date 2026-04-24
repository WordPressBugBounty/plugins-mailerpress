<?php

declare(strict_types=1);

namespace MailerPress\Api;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Services\BounceParser;
use WP_REST_Request;
use WP_REST_Response;

/**
 * API endpoints pour la gestion des bounces
 */
class Bounces
{
    /**
     * Récupère les logs de bounce
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'bounces/logs',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function getLogs(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) $request->get_param('limit') ?: 100;
        $logs = BounceParser::getLogs($limit);

        return rest_ensure_response([
            'success' => true,
            'logs' => $logs,
            'count' => count($logs)
        ]);
    }

    /**
     * Efface les logs de bounce
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'bounces/logs',
        methods: 'DELETE',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function clearLogs(WP_REST_Request $request): WP_REST_Response
    {
        BounceParser::clearLogs();

        return rest_ensure_response([
            'success' => true,
            'message' => 'Logs cleared successfully'
        ]);
    }

    /**
     * Force l'exécution du check de bounces
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'bounces/check',
        methods: 'POST',
        permissionCallback: [Permissions::class, 'canEdit'],
    )]
    public function forceCheck(WP_REST_Request $request): WP_REST_Response
    {
        $logs = BounceParser::forceCheck();

        return rest_ensure_response([
            'success' => true,
            'message' => 'Bounce check completed',
            'logs' => $logs
        ]);
    }

    /**
     * Récupère la configuration de bounce
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'bounces/config',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function getConfig(WP_REST_Request $request): WP_REST_Response
    {
        $config = BounceParser::getValidatedConfig();

        // Ne pas retourner le mot de passe
        if ($config && isset($config['password'])) {
            $config['password'] = '***';
        }

        return rest_ensure_response([
            'success' => true,
            'config' => $config,
            'is_valid' => $config !== null
        ]);
    }

    /**
     * Récupère le statut de l'action schedulée
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    #[Endpoint(
        'bounces/status',
        methods: 'GET',
        permissionCallback: [Permissions::class, 'canView'],
    )]
    public function getStatus(WP_REST_Request $request): WP_REST_Response
    {
        $hasScheduledAction = function_exists('as_has_scheduled_action')
            && as_has_scheduled_action('mailerpress_check_bounces');

        $nextScheduled = null;
        if (function_exists('as_next_scheduled_action')) {
            $nextScheduled = as_next_scheduled_action('mailerpress_check_bounces');
        }

        $interval = get_option('mailerpress_check_bounces_interval', null);

        return rest_ensure_response([
            'success' => true,
            'is_scheduled' => $hasScheduledAction,
            'next_run' => $nextScheduled ? date('Y-m-d H:i:s', $nextScheduled) : null,
            'interval' => $interval,
            'interval_human' => $interval ? human_time_diff(0, $interval) : null
        ]);
    }
}

