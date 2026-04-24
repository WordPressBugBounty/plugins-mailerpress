<?php

declare(strict_types=1);

namespace MailerPress\Core\Interfaces;

\defined('ABSPATH') || exit;

interface JobInterface
{
    /**
     * Execute the job logic.
     *
     * @param array $data the data required for the job
     *
     * @throws \Exception if the job fails
     */
    public function handle(array $data): void;

    public function getData(): array;
}
