<?php

namespace MailerPress\Core\Notifications\Messages;

defined('ABSPATH') || exit;

use MailerPress\Core\Notifications\AbstractNotificationMessage;
use MailerPress\Core\Enums\Tables;

class WorkflowFailedJobsNotification extends AbstractNotificationMessage
{
    protected string $type = 'warning';
    protected ?int $duration = null;
    protected bool $persistent = false;

    public function getMessage(): string
    {
        $count = $this->getFailedJobsCount();
        return sprintf(
            __('You have %d failed workflow job(s) in the last 24 hours. Check your workflow dashboard for details.', 'mailerpress'),
            $count
        );
    }

    public function shouldDisplay(): bool
    {
        if (!parent::shouldDisplay()) {
            return false;
        }
        return $this->getFailedJobsCount() > 0;
    }

    public function getAction(): ?array
    {
        return [
            'label' => __('View Dashboard', 'mailerpress'),
            'url' => admin_url('admin.php?page=mailerpress%2Fcampaigns.php&path=%2Fhome%2Fworkflow&activeView=performance-dashboard'),
        ];
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    private function getFailedJobsCount(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_JOBS;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'FAILED' AND updated_at >= %s",
            $cutoff
        ));
    }
}
