<?php

declare(strict_types=1);

namespace MailerPress\Core\Attributes;

\defined('ABSPATH') || exit;

use MailerPress\Core\Kernel;

#[\Attribute]
class Endpoint
{
    private string|array $methods;
    private array|string $route;
    private $permissionCallback;
    private array $args;

    public function __construct(
        array|string $route,
        string|array $methods = 'GET',
        $permissionCallback = null,
        array $args = []
    ) {
        $this->route = $route;
        $this->methods = $methods;
        $this->permissionCallback = $permissionCallback;
        $this->args = $args;
    }

    public function execute($callable): void
    {
        if (true === Kernel::getContainer()->get('enable_rest')) {
            add_action('rest_api_init', function () use ($callable): void {
                register_rest_route(
                    Kernel::getContainer()->get('rest_namespace'),
                    '/' . $this->route,
                    [
                        'methods' => $this->methods,
                        'callback' => $callable,
                        'permission_callback' => $this->resolvePermissionCallback($callable),
                        'args' => $this->args,
                    ]
                );
            });
        }
    }

    private function resolvePermissionCallback($callable): callable
    {
        if (\is_array($this->permissionCallback) && count($this->permissionCallback) === 2) {
            // If permissionCallback is an array like [ClassName::class, 'methodName']
            $className = $this->permissionCallback[0];
            $methodName = $this->permissionCallback[1];

            return function (...$args) use ($className, $methodName) {
                if (is_callable([$className, $methodName])) {
                    return \call_user_func([$className, $methodName], ...$args);
                }
                return false;
            };
        } elseif (\is_string($this->permissionCallback)) {
            // Check if it's a global function first (like __return_true)
            if (\function_exists($this->permissionCallback)) {
                return $this->permissionCallback;
            }
            // Otherwise, treat it as a method on the same class
            return function (...$args) use ($callable) {
                return \call_user_func([$callable[0], $this->permissionCallback], ...$args);
            };
        } elseif (\is_callable($this->permissionCallback)) {
            // If permissionCallback is already callable, use it directly
            return $this->permissionCallback;
        }

        // Default to allowing all requests
        return '__return_true';
    }
}
