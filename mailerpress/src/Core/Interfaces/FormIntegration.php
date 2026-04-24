<?php

declare(strict_types=1);

namespace MailerPress\Core\Interfaces;

interface FormIntegration
{
    /**
     * Register hooks for the form plugin.
     */
    public function register_hooks(): void;

    /**
     * Process form submission and add contact to newsletter.
     */
    public function handle_submission(array $data): void;
}
