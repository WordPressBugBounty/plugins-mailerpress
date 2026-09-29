<?php

declare(strict_types=1);

namespace MailerPress\Actions\ThirdParty\Kadence;

defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Filter;

class Setup
{
    #[Filter('kadence_blocks_advanced_form_submission_reject', acceptedArgs: 4)]
    public function rejectWhenProIsUnavailable($rejected, array $formArgs, array $processedFields, $postId): bool
    {
        if ($rejected) {
            return true;
        }

        $actions = $formArgs['attributes']['actions'] ?? [];

        return is_array($actions)
            && in_array('mailerpress', $actions, true)
            && !class_exists('\MailerPressPro\Actions\ThirdParty\Kadence\Setup');
    }

    #[Filter('kadence_blocks_advanced_form_submission_reject_message', acceptedArgs: 4)]
    public function addUnavailableMessage(
        string $message,
        array $formArgs,
        array $processedFields,
        $postId
    ): string {
        $actions = $formArgs['attributes']['actions'] ?? [];
        if (
            is_array($actions)
            && in_array('mailerpress', $actions, true)
            && !class_exists('\MailerPressPro\Actions\ThirdParty\Kadence\Setup')
        ) {
            return __('This form integration is temporarily unavailable. Please try again later.', 'mailerpress');
        }

        return $message;
    }
}
