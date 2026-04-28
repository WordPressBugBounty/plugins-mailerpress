<?php

namespace MailerPress\Core\Workflows\Services;

class TriggerRateLimiter
{
    private int $maxJobs;
    private int $windowSeconds;

    public function __construct(int $maxJobs = 5, int $windowSeconds = 60)
    {
        $this->maxJobs = apply_filters('mailerpress_trigger_rate_limit_max', $maxJobs);
        $this->windowSeconds = apply_filters('mailerpress_trigger_rate_limit_window', $windowSeconds);
    }

    public function isAllowed(string $triggerKey, int $userId): bool
    {
        $key = 'mp_rl_' . md5($triggerKey . '_' . $userId);
        $current = (int) get_transient($key);

        if ($current >= $this->maxJobs) {
            return false;
        }

        set_transient($key, $current + 1, $this->windowSeconds);
        return true;
    }
}
