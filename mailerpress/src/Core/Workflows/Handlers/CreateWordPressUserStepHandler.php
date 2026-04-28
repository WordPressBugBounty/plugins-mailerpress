<?php

namespace MailerPress\Core\Workflows\Handlers;

use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;

/**
 * Create WordPress User Step Handler
 *
 * Creates a new WordPress user from the workflow context, or updates an existing one.
 * Supports field mapping from any trigger (WooCommerce, form, contact, etc.).
 *
 * @since 1.3.0
 */
class CreateWordPressUserStepHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'create_wp_user';
    }

    public function getDefinition(): array
    {
        $roles = wp_roles()->get_names();
        $roleOptions = [];
        foreach ($roles as $roleKey => $roleName) {
            $roleOptions[] = [
                'value' => $roleKey,
                'label' => translate_user_role($roleName),
            ];
        }

        return [
            'key'         => 'create_wp_user',
            'label'       => __('Create WordPress User', 'mailerpress'),
            'description' => __('Create a new WordPress user from trigger data, or update an existing one by email.', 'mailerpress'),
            'icon'        => 'admin-users',
            'category'    => 'wordpress',
            'type'        => 'ACTION',
            'uses_trigger_mapping' => true,
            'settings_schema' => [
                // ===== Field Mapping =====
                [
                    'key'   => '_section_mapping',
                    'type'  => 'section',
                    'label' => __('Field Mapping', 'mailerpress'),
                    'description' => __('Map trigger data to WordPress user fields.', 'mailerpress'),
                ],
                [
                    'key'          => 'email',
                    'label'        => __('Email', 'mailerpress'),
                    'type'         => 'trigger_mapping',
                    'target_type'  => 'email',
                    'required'     => true,
                    'placeholder'  => __('Select email field from trigger...', 'mailerpress'),
                    'help'         => __('The email address for the WordPress user. Must be unique.', 'mailerpress'),
                ],
                [
                    'key'         => 'username',
                    'label'       => __('Username', 'mailerpress'),
                    'type'        => 'trigger_mapping',
                    'target_type' => 'string',
                    'required'    => false,
                    'placeholder' => __('Select username field or leave empty to generate from email...', 'mailerpress'),
                    'help'        => __('If left empty, the username will be generated from the email address.', 'mailerpress'),
                ],
                [
                    'key'         => 'first_name',
                    'label'       => __('First Name', 'mailerpress'),
                    'type'        => 'trigger_mapping',
                    'target_type' => 'string',
                    'required'    => false,
                    'placeholder' => __('Select first name field from trigger...', 'mailerpress'),
                ],
                [
                    'key'         => 'last_name',
                    'label'       => __('Last Name', 'mailerpress'),
                    'type'        => 'trigger_mapping',
                    'target_type' => 'string',
                    'required'    => false,
                    'placeholder' => __('Select last name field from trigger...', 'mailerpress'),
                ],

                // ===== User Settings =====
                [
                    'key'   => '_section_settings',
                    'type'  => 'section',
                    'label' => __('User Settings', 'mailerpress'),
                ],
                [
                    'key'      => 'role',
                    'label'    => __('Role', 'mailerpress'),
                    'type'     => 'select',
                    'required' => false,
                    'options'  => $roleOptions,
                    'default'  => 'subscriber',
                    'help'     => __('The WordPress role assigned to the new user.', 'mailerpress'),
                ],
                [
                    'key'     => 'send_notification',
                    'label'   => __('Send welcome email', 'mailerpress'),
                    'type'    => 'toggle',
                    'default' => true,
                    'help'    => __('Send the default WordPress new user notification email with login credentials.', 'mailerpress'),
                ],

                // ===== Advanced Settings =====
                [
                    'key'   => '_section_advanced',
                    'type'  => 'section',
                    'label' => __('Advanced Settings', 'mailerpress'),
                ],
                [
                    'key'     => 'update_existing',
                    'label'   => __('Update if user exists', 'mailerpress'),
                    'type'    => 'toggle',
                    'default' => false,
                    'help'    => __('If a WordPress user with this email already exists, update their role and name. If disabled, the action is skipped for existing users.', 'mailerpress'),
                ],
            ],
        ];
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        $settings = $step->getSettings();

        $email     = sanitize_email($this->replacePlaceholders($settings['email'] ?? '', $context));
        $username  = sanitize_user($this->replacePlaceholders($settings['username'] ?? '', $context));
        $firstName = sanitize_text_field($this->replacePlaceholders($settings['first_name'] ?? '', $context));
        $lastName  = sanitize_text_field($this->replacePlaceholders($settings['last_name'] ?? '', $context));
        $role             = $settings['role'] ?? 'subscriber';
        $sendNotification = (bool) ($settings['send_notification'] ?? true);
        $updateExisting   = (bool) ($settings['update_existing'] ?? false);

        if (empty($email) || !is_email($email)) {
            return StepResult::failed(
                sprintf(__('Invalid or missing email address: "%s"', 'mailerpress'), $email)
            );
        }

        // Check for existing WordPress user by email
        $existingUser = get_user_by('email', $email);

        if ($existingUser) {
            if (!$updateExisting) {
                return StepResult::success($step->getNextStepId(), [
                    'user_id' => $existingUser->ID,
                    'email'   => $email,
                    'status'  => 'already_exists',
                    'message' => __('WordPress user already exists, skipped.', 'mailerpress'),
                ]);
            }

            // Update existing user
            $updateData = ['ID' => $existingUser->ID];

            if (!empty($firstName)) {
                $updateData['first_name'] = $firstName;
            }
            if (!empty($lastName)) {
                $updateData['last_name'] = $lastName;
            }
            if (!empty($role)) {
                $updateData['role'] = $role;
            }

            $result = wp_update_user($updateData);

            if (is_wp_error($result)) {
                return StepResult::failed($result->get_error_message());
            }

            return StepResult::success($step->getNextStepId(), [
                'user_id' => $existingUser->ID,
                'email'   => $email,
                'status'  => 'updated',
            ]);
        }

        // Generate username from email if not provided
        if (empty($username)) {
            $username = $this->generateUsername($email);
        }

        // Ensure username is unique
        if (username_exists($username)) {
            $username = $this->makeUsernameUnique($username);
        }

        $password = wp_generate_password(16, true, false);

        $userId = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'display_name' => trim("$firstName $lastName") ?: $username,
            'role'         => $role,
        ]);

        if (is_wp_error($userId)) {
            return StepResult::failed($userId->get_error_message());
        }

        if ($sendNotification) {
            wp_new_user_notification($userId, null, 'both');
        }

        \do_action('mailerpress_wp_user_created', $userId, $context);

        return StepResult::success($step->getNextStepId(), [
            'user_id'  => $userId,
            'email'    => $email,
            'username' => $username,
            'role'     => $role,
            'status'   => 'created',
        ]);
    }

    /**
     * Generate a username from an email address (local part, sanitized).
     */
    private function generateUsername(string $email): string
    {
        $local = strstr($email, '@', true);
        return sanitize_user(preg_replace('/[^a-z0-9_\-]/', '', strtolower($local)));
    }

    /**
     * Append a numeric suffix until the username is unique.
     */
    private function makeUsernameUnique(string $base): string
    {
        $i = 1;
        do {
            $candidate = $base . $i;
            $i++;
        } while (username_exists($candidate));

        return $candidate;
    }

    /**
     * Replace {{placeholder}} tokens with context values.
     */
    private function replacePlaceholders(string $template, array $context): string
    {
        if (empty($template)) {
            return '';
        }

        $aliases = [
            'email'      => ['email', 'user_email', 'customer_email', 'billing_email'],
            'first_name' => ['first_name', 'billing_first_name', 'customer_first_name', 'user_first_name'],
            'last_name'  => ['last_name', 'billing_last_name', 'customer_last_name', 'user_last_name'],
        ];

        return preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', function ($matches) use ($context, $aliases) {
            $key = $matches[1];

            if (isset($context[$key]) && is_scalar($context[$key])) {
                return (string) $context[$key];
            }

            foreach ($aliases as $fieldName => $fieldAliases) {
                if ($key === $fieldName || in_array($key, $fieldAliases, true)) {
                    foreach ($fieldAliases as $alias) {
                        if (isset($context[$alias]) && is_scalar($context[$alias])) {
                            return (string) $context[$alias];
                        }
                    }
                }
            }

            return '';
        }, $template);
    }
}
