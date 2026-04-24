<?php

declare(strict_types=1);

namespace MailerPress\Services;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\CapabilitiesManager;
use MailerPress\Core\Kernel;

class Activation
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function activate(): void
    {
        update_option('mailerpress_activated', 'yes');
        $this->addDefaultPage();
        \MailerPress\Core\CapabilitiesManager::addCapabilities();

        // Flush rewrite rules on activation to ensure rewrite rules are registered
        $this->flushRewriteRules();
    }

    /**
     * Flush rewrite rules to ensure new rules are registered
     * This is called during plugin activation
     */
    private function flushRewriteRules(): void
    {
        // Delete the rewrite rules option to force WordPress to regenerate them
        delete_option('rewrite_rules');

        // Flush rewrite rules
        // Using flush_rewrite_rules(false) to avoid hard flush during activation
        // The rules will be regenerated on the next page load
        flush_rewrite_rules(false);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function addDefaultPage(): void
    {
        $cptSlug = Kernel::getContainer()->get('cpt-page-slug');

        $existing = get_posts([
            'post_type'      => $cptSlug,
            'post_status'    => 'publish',
            'name'           => 'mailerpress',
            'posts_per_page' => 1,
        ]);

        if (!empty($existing)) {
            return;
        }

        wp_insert_post([
            'post_type'    => $cptSlug,
            'post_status'  => 'publish',
            'post_title'   => 'Mailerpress',
            'post_content' => '[mailerpress_pages]',
        ]);
    }
}
