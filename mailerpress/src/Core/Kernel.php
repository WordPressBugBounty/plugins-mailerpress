<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

use DI\Container;
use DI\ContainerBuilder;
use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Command;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\ThirdParty\ContactForm\ContactFormHandler;

use function DI\autowire;

class Kernel
{
    public static array $config;
    protected static ?Container $container = null;
    private static string $tableNamespace = 'MailerPress\Core\Database\Tables\\';

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public static function setup(): void
    {
        self::$container = self::buildContainer();
        self::includes();
        self::executeActions();
        self::executeEndpoints();
        self::executeCliCommands(); // ← Add this
    }

    /**
     * @throws \Exception
     */
    public static function getContainer(): ?Container
    {
        if (null === self::$container) {
            self::$container = self::buildContainer();
        }

        return self::$container;
    }

    public static function includes(): void
    {
        if (file_exists(self::$config['root'] . '/src/functions.php')) {
            require_once self::$config['root'] . '/src/functions.php';
        }
    }

    /**
     * @throws \Exception
     */
    public static function execute(array $config): void
    {
        self::$config = $config;
        // Use priority 10 to ensure textdomain is loaded first (priority 0)
        // and WorkflowSystem is initialized (priority 5) before Kernel setup
        add_action('plugins_loaded', [__CLASS__, 'setup'], priority: 10);
    }

    public static function loadTables(): array
    {
        $tables = [];
        $directory = self::$config['root'] . '/src/Core/Database/Tables';

        if (is_dir($directory)) {
            $files = scandir($directory);
            $files = array_values(array_diff($files, ['.', '..']));
            foreach ($files as $file) {
                if ('php' === pathinfo($file, PATHINFO_EXTENSION)) {
                    $className = self::$tableNamespace . pathinfo($file, PATHINFO_FILENAME);
                    if (class_exists($className)) {
                        $tables[] = autowire($className);
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * @throws \Exception
     */
    private static function buildContainer(): Container
    {
        $containerBuilder = new ContainerBuilder();

        if (file_exists(self::$config['root'] . '/src/container-config.php')) {
            $containerBuilder->addDefinitions(self::$config['root'] . '/src/container-config.php');
            self::fillContainer($containerBuilder);
        }

        return $containerBuilder->build();
    }

    private static function fillContainer(ContainerBuilder $containerBuilder): void
    {
        $actions = self::buildClasses(self::$config['root'] . '/src/Actions', 'actions', 'MailerPress\Actions\\');

        // Ajouter manuellement CheckBounce s'il n'est pas trouvé
        if (!in_array('MailerPress\Actions\ActionScheduler\Processors\CheckBounce', $actions)) {
            $actions[] = 'MailerPress\Actions\ActionScheduler\Processors\CheckBounce';
        }

        $containerBuilder->addDefinitions([
            'actions' => $actions,
            'commands' => self::buildClasses(
                self::$config['root'] . '/src/Commands',
                'actions',
                'MailerPress\Commands\\'
            ),
            'endpoints' => self::buildClasses(self::$config['root'] . '/src/Api', 'actions', 'MailerPress\Api\\'),
            'tables' => self::loadTables(),
        ]);
    }

    private static function buildClasses($path, $type, $namespace): array
    {
        $services = [];

        if (is_dir($path)) {
            $files = array_diff(scandir($path), ['..', '.', 'Services', 'partials', 'templates', 'Interfaces']);

            foreach ($files as $filename) {
                $pathCheck = $path . '/' . $filename;

                if (is_dir($pathCheck)) {
                    $services[] = self::buildClasses($pathCheck, $type, $namespace . $filename . '\\');

                    continue;
                }

                $pathinfo = pathinfo($filename);

                if (
                    'php' !== $pathinfo['extension']
                    || 'view.blade' === $pathinfo['filename']
                    || strpos($pathinfo['filename'], '.blade')
                    || !\array_key_exists('extension', $pathinfo)
                ) {
                    continue;
                }

                $services[] = $namespace . str_replace('.php', '', $filename);
            }
        }

        return self::array_flatten($services);
    }

    private static function array_flatten($array): array|bool
    {
        if (!\is_array($array)) {
            return false;
        }
        $result = [];

        foreach ($array as $key => $value) {
            if (\is_array($value)) {
                $result = array_merge($result, self::array_flatten($value));
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    private static function executeActions(): void
    {
        foreach (self::$container->get('actions') as $action) {
            $actionClass = self::$container->get($action);
            $class = new \ReflectionClass($actionClass);

            foreach ($class->getMethods() as $method) {
                self::launch(
                    [
                        $method->getAttributes(Action::class, \ReflectionAttribute::IS_INSTANCEOF),
                        $method->getAttributes(Filter::class, \ReflectionAttribute::IS_INSTANCEOF),
                    ],
                    $actionClass,
                    $method
                );
            }
        }
    }

    private static function launch(array $array, $actionClass, $method): void
    {
        foreach ($array as $item) {
            if (empty($item)) {
                continue;
            }

            foreach ($item as $methodAttr) {
                $action = $methodAttr->newInstance();
                $action->execute([$actionClass, $method->getName()]);
            }
        }
    }

    /**
     * @throws \ReflectionException
     * @throws DependencyException
     * @throws NotFoundException
     */
    private static function executeEndpoints(): void
    {
        foreach (self::$container->get('endpoints') as $action) {
            $actionClass = self::$container->get($action);
            $class = new \ReflectionClass($actionClass);

            foreach ($class->getMethods() as $method) {
                self::launch(
                    [
                        $method->getAttributes(Attributes\Endpoint::class, \ReflectionAttribute::IS_INSTANCEOF),
                    ],
                    $actionClass,
                    $method
                );
            }
        }
    }

    /**
     * @throws \ReflectionException
     * @throws DependencyException
     * @throws NotFoundException
     */
    private static function executeCliCommands(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        foreach (self::$container->get('commands') as $commandClass) {
            $instance = self::$container->get($commandClass);
            $reflection = new \ReflectionClass($instance);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(
                    Command::class,
                    \ReflectionAttribute::IS_INSTANCEOF
                );

                foreach ($attributes as $attribute) {
                    $cliAttr = $attribute->newInstance();
                    $cliAttr->execute($instance, $method);
                }
            }
        }
    }
}
