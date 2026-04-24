<?php

declare(strict_types=1);

namespace MailerPress\Actions\MailerpressSendingService;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;

class JobCreated
{
    #[Action('mailerpress_sending_service_job_created', priority: 10, acceptedArgs: 2)]
    public function processJobCreated($batch_id, $response): void
    {
        $optionName = \sprintf('mailerpress_job_created_%s', $batch_id);
        $option = get_option($optionName);

        if (empty($option)) {
            update_option(
                $optionName,
                [$response['id']]
            );
        } else {
            update_option(
                $optionName,
                array_merge(
                    [$response['id']],
                    $option
                )
            );
        }
    }
}
