<?php

namespace MailerPress\Actions\ActionScheduler\Processors;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Kernel;

class AutomatedCampaign
{

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Action('mailerpress_run_campaign_once', priority: 10, acceptedArgs: 8)]
    public function mailerpress_run_campaign_once_callback(
        $post,
        $sendType,
        $conf,
        $scheduledAt,
        $recipientTargeting,
        $lists,
        $tags,
        $segment,
    ) {
        global $wpdb;
        $campaign = Kernel::getContainer()->get(\MailerPress\Models\Campaigns::class)->find($post);

        if (!$campaign) {
            return;
        }

        $config = json_decode($campaign->config, true);
        if (!$config || empty($config['automateSettings'])) {
            return;
        }

        $automateSettings = $config['automateSettings'];

        // Get last_run as DateTime or null
        $lastRun = null;
        if (!empty($automateSettings['last_run'])) {
            try {
                $lastRun = new \DateTime($automateSettings['last_run'], wp_timezone());
            } catch (\Exception $e) {
                $lastRun = null;
            }
        }

        if ($campaign->status === 'active') {
            // Trigger batch for active campaigns only
            $this->mailerpress_trigger_batch_for_automated_campaign(
                $sendType,
                $post,
                $conf,
                $scheduledAt,
                $recipientTargeting,
                $lists,
                $tags,
                $segment,
            );

            // Update last_run immediately after triggering batch
            $automateSettings['last_run'] = current_time('mysql');
            $config['automateSettings'] = $automateSettings;

            $wpdb->update(
                $wpdb->prefix . 'mailerpress_campaigns',
                ['config' => wp_json_encode($config), 'status' => 'active'],
                ['campaign_id' => $post]
            );

            // Use the updated last_run for next run calculation
            $lastRun = new \DateTime($automateSettings['last_run'], wp_timezone());
        }

        // Calculate next run (even if campaign is not active, to allow reactivation)
        $nextRun = mailerpress_calculate_next_run($automateSettings, $lastRun);

        // Schedule next run only if it is in the future
        if ($nextRun && $nextRun > new \DateTime('now', wp_timezone())) {
            $automateSettings['next_run'] = $nextRun->format('Y-m-d H:i:s');
            $config['automateSettings'] = $automateSettings;

            $wpdb->update(
                $wpdb->prefix . 'mailerpress_campaigns',
                ['config' => wp_json_encode($config)],
                ['campaign_id' => $post]
            );

            as_schedule_single_action(
                $nextRun->getTimestamp(),
                'mailerpress_run_campaign_once',
                [
                    $post,
                    $sendType,
                    $conf,
                    $scheduledAt,
                    $recipientTargeting,
                    $lists,
                    $tags,
                    $segment,
                ],
                'mailerpress'
            );
        }
    }


    public function mailerpress_trigger_batch_for_automated_campaign(
        $sendType,
        $post,
        $config,
        $scheduledAt,
        $recipientTargeting,
        $lists,
        $tags,
        $segment,
    ): void {
        as_schedule_single_action(
            time() + 5,
            'mailerpress_batch_email',
            [
                $sendType,
                $post,
                $config,
                $scheduledAt,
                $recipientTargeting,
                $lists,
                $tags,
                $segment,
            ],
            'mailerpress'
        );
    }

}