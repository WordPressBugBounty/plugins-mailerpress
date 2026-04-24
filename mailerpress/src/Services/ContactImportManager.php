<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

class ContactImportManager
{
    public function init(array $data): void
    {
        //		add_action( 'init', [ $this, 'schedule_import' ] );

        $this->fillData($data);

        if (!as_has_scheduled_action('batch_contact_import_process')) {
            as_schedule_single_action(
                time(),
                'batch_contact_import_process',
                []
            );
        }
    }

    private function fillData(array $data): void {}
}
