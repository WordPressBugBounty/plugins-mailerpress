<?php

declare(strict_types=1);

namespace MailerPress\Actions\Workflows;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Workflows\Services\GoalManager;

/**
 * Register the goal-only (benchmark) events.
 *
 * Most goals are promoted from existing triggers by GoalManager. The events
 * below have no trigger counterpart — they only make sense as an *outcome* you
 * measure inside a running sequence, not as a way to start one.
 *
 * @since 1.3.0
 */
class RegisterWorkflowGoals
{
    /**
     * @param GoalManager $goalManager
     */
    #[Action('mailerpress_register_workflow_goals')]
    public function registerGoals($goalManager): void
    {
        if (!$goalManager instanceof GoalManager) {
            return;
        }

        $this->registerEmailOpened($goalManager);
        $this->registerEmailClicked($goalManager);
        $this->registerTagRemoved($goalManager);
        $this->registerListRemoved($goalManager);
        $this->registerUnsubscribed($goalManager);
        $this->registerAutomationCompleted($goalManager);
    }

    private function registerEmailOpened(GoalManager $goalManager): void
    {
        $goalManager->registerGoal(
            'mp_goal_email_opened',
            [
                'label' => __('Email opened', 'mailerpress'),
                'description' => __('Achieved when the contact opens the selected campaign. Leave the campaign empty to accept any email.', 'mailerpress'),
                'icon' => 'email',
                'category' => 'email',
                'settings_schema' => [
                    [
                        'key' => 'campaign_id',
                        'label' => __('Campaign', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'data_source' => 'campaigns',
                        'help' => __('Only count opens of this campaign. Leave empty for any campaign.', 'mailerpress'),
                    ],
                ],
                'output_fields' => [
                    ['key' => 'contact_id', 'label' => __('Contact ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                    ['key' => 'campaign_id', 'label' => __('Campaign ID', 'mailerpress'), 'type' => 'number', 'group' => 'email'],
                ],
            ],
            [
                [
                    'hook' => 'mailerpress_email_opened',
                    'context_builder' => static function (...$args): array {
                        $contactId = (int) ($args[0] ?? 0);

                        if (!$contactId) {
                            return [];
                        }

                        return [
                            'contact_id' => $contactId,
                            'campaign_id' => (int) ($args[1] ?? 0),
                            'batch_id' => $args[2] ?? null,
                        ];
                    },
                ],
            ]
        );
    }

    private function registerEmailClicked(GoalManager $goalManager): void
    {
        $goalManager->registerGoal(
            'mp_goal_email_clicked',
            [
                'label' => __('Link clicked', 'mailerpress'),
                'description' => __('Achieved when the contact clicks a link in one of your emails. Restrict it to a campaign, a URL, or both.', 'mailerpress'),
                'icon' => 'admin-links',
                'category' => 'email',
                'settings_schema' => [
                    [
                        'key' => 'campaign_id',
                        'label' => __('Campaign', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'data_source' => 'campaigns',
                        'help' => __('Only count clicks in this campaign. Leave empty for any campaign.', 'mailerpress'),
                    ],
                    [
                        'key' => 'link_url',
                        'label' => __('Link URL contains', 'mailerpress'),
                        'type' => 'text',
                        'required' => false,
                        'placeholder' => 'https://example.com/pricing',
                        'help' => __('Only count clicks whose destination contains this text. Leave empty for any link.', 'mailerpress'),
                    ],
                ],
                'output_fields' => [
                    ['key' => 'contact_id', 'label' => __('Contact ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                    ['key' => 'campaign_id', 'label' => __('Campaign ID', 'mailerpress'), 'type' => 'number', 'group' => 'email'],
                    ['key' => 'link_url', 'label' => __('Clicked URL', 'mailerpress'), 'type' => 'string', 'group' => 'email'],
                ],
            ],
            [
                [
                    'hook' => 'mailerpress_email_clicked',
                    'context_builder' => static function (...$args): array {
                        $contactId = (int) ($args[0] ?? 0);

                        if (!$contactId) {
                            return [];
                        }

                        return [
                            'contact_id' => $contactId,
                            'campaign_id' => (int) ($args[1] ?? 0),
                            'link_url' => (string) ($args[2] ?? ''),
                        ];
                    },
                ],
            ]
        );
    }

    private function registerTagRemoved(GoalManager $goalManager): void
    {
        $goalManager->registerGoal(
            'mp_goal_tag_removed',
            [
                'label' => __('Tag removed', 'mailerpress'),
                'description' => __('Achieved when the selected tag is removed from the contact.', 'mailerpress'),
                'icon' => 'mailerpress',
                'category' => 'mailerpress',
                'settings_schema' => [
                    [
                        'key' => 'tag_id',
                        'label' => __('Tag', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'data_source' => 'tags',
                        'help' => __('Leave empty to accept any tag removal.', 'mailerpress'),
                    ],
                ],
                'output_fields' => [
                    ['key' => 'contact_id', 'label' => __('Contact ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                    ['key' => 'tag_id', 'label' => __('Tag ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                ],
            ],
            [
                [
                    'hook' => 'mailerpress_contact_tag_removed',
                    'context_builder' => static function (...$args): array {
                        $contactId = (int) ($args[0] ?? 0);

                        if (!$contactId) {
                            return [];
                        }

                        return [
                            'contact_id' => $contactId,
                            'tag_id' => (int) ($args[1] ?? 0),
                        ];
                    },
                ],
            ]
        );
    }

    private function registerListRemoved(GoalManager $goalManager): void
    {
        $goalManager->registerGoal(
            'mp_goal_list_removed',
            [
                'label' => __('List removed', 'mailerpress'),
                'description' => __('Achieved when the contact is removed from the selected list.', 'mailerpress'),
                'icon' => 'mailerpress',
                'category' => 'mailerpress',
                'settings_schema' => [
                    [
                        'key' => 'list_id',
                        'label' => __('List', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'data_source' => 'lists',
                        'help' => __('Leave empty to accept any list removal.', 'mailerpress'),
                    ],
                ],
                'output_fields' => [
                    ['key' => 'contact_id', 'label' => __('Contact ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                    ['key' => 'list_id', 'label' => __('List ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                ],
            ],
            [
                [
                    'hook' => 'mailerpress_contact_list_removed',
                    'context_builder' => static function (...$args): array {
                        $contactId = (int) ($args[0] ?? 0);

                        if (!$contactId) {
                            return [];
                        }

                        return [
                            'contact_id' => $contactId,
                            'list_id' => (int) ($args[1] ?? 0),
                        ];
                    },
                ],
            ]
        );
    }

    private function registerUnsubscribed(GoalManager $goalManager): void
    {
        $goalManager->registerGoal(
            'mp_goal_unsubscribed',
            [
                'label' => __('Unsubscribed', 'mailerpress'),
                'description' => __('Achieved when the contact unsubscribes. Useful as an optional goal to measure the churn a sequence causes.', 'mailerpress'),
                'icon' => 'dismiss',
                'category' => 'mailerpress',
                'settings_schema' => [],
                'output_fields' => [
                    ['key' => 'contact_id', 'label' => __('Contact ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                ],
            ],
            [
                [
                    'hook' => 'mailerpress_contact_unsubscribed',
                    'context_builder' => static function (...$args): array {
                        $contactId = (int) ($args[0] ?? 0);

                        if (!$contactId) {
                            return [];
                        }

                        return ['contact_id' => $contactId];
                    },
                ],
            ]
        );
    }

    private function registerAutomationCompleted(GoalManager $goalManager): void
    {
        $goalManager->registerGoal(
            'mp_goal_automation_completed',
            [
                'label' => __('Automation completed', 'mailerpress'),
                'description' => __('Achieved when the contact finishes the selected automation. Lets you chain sequences together.', 'mailerpress'),
                'icon' => 'controls-repeat',
                'category' => 'mailerpress',
                'settings_schema' => [
                    [
                        'key' => 'completed_automation_id',
                        'label' => __('Automation', 'mailerpress'),
                        'type' => 'select',
                        'required' => false,
                        'data_source' => 'automations',
                        'help' => __('Leave empty to accept the completion of any automation.', 'mailerpress'),
                    ],
                ],
                'output_fields' => [
                    ['key' => 'contact_id', 'label' => __('Contact ID', 'mailerpress'), 'type' => 'number', 'group' => 'contact'],
                    ['key' => 'completed_automation_id', 'label' => __('Completed automation ID', 'mailerpress'), 'type' => 'number', 'group' => 'automation'],
                ],
            ],
            [
                [
                    'hook' => 'mailerpress_workflow_job_completed',
                    'context_builder' => static function (...$args): array {
                        $job = $args[0] ?? null;

                        if (!is_object($job) || !method_exists($job, 'getUserId')) {
                            return [];
                        }

                        $userId = (int) $job->getUserId();

                        if (!$userId) {
                            return [];
                        }

                        return [
                            'user_id' => $userId,
                            'completed_automation_id' => (int) $job->getAutomationId(),
                            'completed_job_id' => (int) $job->getId(),
                        ];
                    },
                ],
            ]
        );
    }
}
