<?php

declare(strict_types=1);

namespace MailerPress\Core\Abstract;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\JobInterface;

abstract class BaseJob implements JobInterface
{
    /**
     * Data for the job.
     */
    protected array $data;

    /**
     * Constructor to set job data.
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Public getter for the data property.
     *
     * @return array the job data
     */
    public function getData(): array
    {
        return $this->data;
    }
}
