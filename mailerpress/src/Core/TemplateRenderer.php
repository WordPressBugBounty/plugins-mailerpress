<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

class TemplateRenderer
{
    /**
     * The single instance of the class.
     */
    private static ?TemplateRenderer $instance = null;

    /**
     * Directory where the templates are stored.
     */
    private string $templateDirectory;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct(string $templateDirectory)
    {
        $this->setTemplateDirectory($templateDirectory);
    }

    /**
     * Gets the singleton instance of the class.
     *
     * @throws \InvalidArgumentException
     */
    public static function getInstance(string $templateDirectory): self
    {
        if (null === self::$instance) {
            self::$instance = new self($templateDirectory);
        }

        return self::$instance;
    }

    /**
     * Sets the template directory.
     *
     * @throws \InvalidArgumentException
     */
    public function setTemplateDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(
                esc_html(
                    \sprintf(
                        // translators: %s the directory path of the template
                        esc_html__('The provided template directory does not exist: %s', 'mailerpress'),
                        esc_url($directory)
                    )
                )
            );
        }
        $this->templateDirectory = rtrim($directory, '/\\').\DIRECTORY_SEPARATOR;
    }

    /**
     * Renders a template file with the provided data.
     *
     * @throws \RuntimeException
     */
    public function render(string $templateName, array $data = []): string
    {
        $templatePath = $this->templateDirectory.$templateName.'.php';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException(
                esc_html(
                    \sprintf(
                        // translators: %s the relative path of the template
                        esc_html__('Template file not found: %s', 'mailerpress'),
                        esc_url($templatePath)
                    )
                )
            );
        }

        // Extract variables for use in the template.
        extract($data, EXTR_SKIP);

        // Start output buffering.
        ob_start();

        try {
            include $templatePath;
        } catch (\Throwable $e) {
            // Clean output buffer and rethrow the exception.
            ob_end_clean();

            throw new \RuntimeException(
                esc_html(
                    \sprintf(
                        // translators: %s the error message
                        esc_html__('An error occurred while rendering the template: %s', 'mailerpress'),
                        esc_html($e->getMessage())
                    )
                ),
                0,
                esc_html($e)
            );
        }

        // Get the output and clean the buffer.
        return ob_get_clean();
    }
}
