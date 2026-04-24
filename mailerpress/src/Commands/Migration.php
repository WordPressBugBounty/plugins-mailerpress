<?php

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Core\Kernel;

\defined('ABSPATH') || exit;


class Migration
{
    #[Command('mailerpress make:migration')]
    public function sayHello(array $args, array $assoc_args): void
    {
        $name = $args[0] ?? null;

        if (!$name) {
            \WP_CLI::error('Migration name is required.');
        }

        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $path = Kernel::$config['root'] . '/src/Core/Migrations/migrations';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $className = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));


        $content = "<?php\n\nuse MailerPress\\Core\\Migrations\\SchemaBuilder;\nuse MailerPress\\Core\\Migrations\\CustomTableManager;\n\nreturn function(SchemaBuilder \$schema) {\n    // Example:\n    // \$schema->create('example', function(CustomTableManager \$table) {\n    //     \$table->id();\n    //     \$table->string('name');\n    //     \$table->setVersion('0.0.1');\n    // });\n};\n";

        $filepath = "$path/$filename";

        if (file_put_contents($filepath, $content)) {
            \WP_CLI::success("Migration created: $filename");
        } else {
            \WP_CLI::error("Failed to create migration: $filename");
        }
    }
}
