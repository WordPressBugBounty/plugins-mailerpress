<?php

declare(strict_types=1);

namespace MailerPress\Core\Attributes;

\defined('ABSPATH') || exit;

#[\Attribute]
class Action
{
    public array|string $actionName;

    private string $scope;
    private int $priority;
    private ?int $acceptedArgs;

    /**
     * @param string $actionName
     */
    public function __construct(array|string $actionName, string $scope = 'all', int $priority = 10, ?int $acceptedArgs = null)
    {
        $this->scope = $scope;
        $this->actionName = $actionName;
        $this->priority = $priority;
        $this->acceptedArgs = $acceptedArgs;
    }

    public function execute($callable): void
    {
        switch ($this->scope) {
            case 'front':
                if (!is_admin()) {
                    $this->launchAction($callable);
                }

                break;

            case 'admin':
                if (is_admin()) {
                    $this->launchAction($callable);
                }

                break;

            default:
                $this->launchAction($callable);

                break;
        }
    }

    private function launchAction($callable): void
    {
        if (!\is_callable($callable)) {
            return;
        }

        if (\is_array($this->actionName)) {
            foreach ($this->actionName as $action) {
                if ($this->acceptedArgs) {
                    add_action($action, $callable, $this->priority, $this->acceptedArgs);
                } else {
                    add_action($action, $callable, $this->priority);
                }
            }
        } else {
            if ($this->acceptedArgs) {
                add_action($this->actionName, $callable, $this->priority, $this->acceptedArgs);
            } else {
                add_action($this->actionName, $callable, $this->priority);
            }
        }
    }
}
