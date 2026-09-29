<?php

namespace MailerPress\Core\Workflows\Services;

/**
 * Registry of the goal (benchmark) events a workflow can measure.
 *
 * A goal definition mirrors a trigger definition on purpose: the editor renders
 * both with the same components, and most goals are simply existing triggers
 * flagged with `can_be_goal` and re-exposed here.
 *
 * Shape of a definition:
 *   [
 *     'key'             => 'tag_added',
 *     'label'           => 'Tag applied',
 *     'description'     => '…',
 *     'icon'            => 'mailerpress',
 *     'category'        => 'mailerpress',
 *     'type'            => 'GOAL',
 *     'settings_schema' => [ …event filters…, …goal options… ],
 *     'output_fields'   => [ … ],
 *   ]
 *
 * Hooks are stored aside from the definition since the editor never needs them:
 *   [ ['hook' => 'mailerpress_contact_tag_added', 'context_builder' => callable|null], … ]
 */
class GoalRegistry
{
    private static ?GoalRegistry $instance = null;

    /** @var array<string, array> */
    private array $definitions = [];

    /** @var array<string, array<int, array{hook: string, context_builder: callable|null}>> */
    private array $hooks = [];

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a goal event.
     *
     * @param string $key        Goal key (equal to the trigger key when promoted)
     * @param array  $definition Editor-facing definition
     * @param array  $hooks      List of ['hook' => string, 'context_builder' => callable|null]
     */
    public function register(string $key, array $definition, array $hooks = []): void
    {
        $definition['key'] = $key;
        $definition['type'] = 'GOAL';
        $definition['settings_schema'] = array_merge(
            $definition['settings_schema'] ?? [],
            self::getGoalOptionsSchema()
        );

        $this->definitions[$key] = $definition;

        foreach ($hooks as $hook) {
            if (empty($hook['hook'])) {
                continue;
            }

            $this->hooks[$key][] = [
                'hook' => $hook['hook'],
                'context_builder' => $hook['context_builder'] ?? null,
            ];
        }
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function getDefinition(string $key): ?array
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return array<string, array>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<int, array{hook: string, context_builder: callable|null}>
     */
    public function getHooks(string $key): array
    {
        return $this->hooks[$key] ?? [];
    }

    public function reset(): void
    {
        $this->definitions = [];
        $this->hooks = [];
    }

    /**
     * Options shared by every goal, appended after the event-specific filters.
     */
    public static function getGoalOptionsSchema(): array
    {
        return [
            [
                'key' => 'goal_mode',
                'label' => __('Goal type', 'mailerpress'),
                'type' => 'select',
                'required' => false,
                'default' => 'essential',
                'options' => [
                    [
                        'value' => 'essential',
                        'label' => __('Essential — wait until the goal is achieved', 'mailerpress'),
                    ],
                    [
                        'value' => 'optional',
                        'label' => __('Optional — keep going and only measure', 'mailerpress'),
                    ],
                ],
                'help' => __('An essential goal holds the contact here until the event happens. An optional goal lets the workflow continue and only records the conversion.', 'mailerpress'),
            ],
            [
                'key' => 'goal_allow_entry',
                'label' => __('Allow contact entry', 'mailerpress'),
                'type' => 'toggle',
                'required' => false,
                'default' => false,
                'help' => __('Any contact achieving this goal enters the automation at this step. Contacts already running the automation jump forward to it.', 'mailerpress'),
            ],
            [
                'key' => 'goal_ignore_run_once',
                'label' => __('Ignore "run once per subscriber"', 'mailerpress'),
                'type' => 'toggle',
                'required' => false,
                'default' => false,
                'help' => __('Let a contact enter through this goal even if they already went through the automation.', 'mailerpress'),
            ],
            [
                'key' => 'goal_timeout_value',
                'label' => __('Give up after', 'mailerpress'),
                'type' => 'number',
                'required' => false,
                'help' => __('Leave empty to wait indefinitely. Only applies to essential goals.', 'mailerpress'),
            ],
            [
                'key' => 'goal_timeout_unit',
                'label' => __('Timeout unit', 'mailerpress'),
                'type' => 'select',
                'required' => false,
                'default' => 'days',
                'options' => [
                    ['value' => 'minutes', 'label' => __('Minutes', 'mailerpress')],
                    ['value' => 'hours', 'label' => __('Hours', 'mailerpress')],
                    ['value' => 'days', 'label' => __('Days', 'mailerpress')],
                    ['value' => 'weeks', 'label' => __('Weeks', 'mailerpress')],
                ],
            ],
        ];
    }

    /**
     * Keys of the shared goal options, so the event filters can be told apart
     * from the goal plumbing when validating settings.
     */
    public static function getGoalOptionKeys(): array
    {
        return array_column(self::getGoalOptionsSchema(), 'key');
    }
}
