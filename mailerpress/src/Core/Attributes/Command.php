<?php

declare(strict_types=1);

namespace MailerPress\Core\Attributes;

use WP_CLI;

\defined('ABSPATH') || exit;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Command
{
    private string $command;
    private ?string $beforeInvokeMethod;

    /**
     * @param string $command WP-CLI command name (e.g. "mailerpress sync")
     * @param string|null $beforeInvokeMethod Optional method name to call before the main method
     */
    public function __construct(string $command, ?string $beforeInvokeMethod = null)
    {
        $this->command = $command;
        $this->beforeInvokeMethod = $beforeInvokeMethod;
    }

    public function execute(object $instance, \ReflectionMethod $method): void
    {
        if (!class_exists('\WP_CLI')) {
            return;
        }

        $handler = function (...$args) use ($instance, $method) {
            if ($this->beforeInvokeMethod && method_exists($instance, $this->beforeInvokeMethod)) {
                $instance->{$this->beforeInvokeMethod}(...$args);
            }

            return $method->invokeArgs($instance, $args);
        };

        WP_CLI::add_command($this->command, $handler);
    }
}
