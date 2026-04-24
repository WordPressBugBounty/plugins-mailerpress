<?php

declare(strict_types=1);

namespace MailerPress\Actions\Setup;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Workflows\Handlers\AddTagStepHandler;
use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Workflows\WorkflowSystem;

class Init
{
    #[Action('init')]
    public function rewriteRule(): void
    {
        $this->registerDefaultTemplateCategories();
        $this->disableEmojiConversion();
        add_rewrite_rule(
            '^unsubscribe/([0-9]+)/([^/]+)/?',
            'index.php?unsubscribe_user=$matches[1]&unsubscribe_token=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^tracking-link/([^/]+)/?',
            'index.php?mailerpress_tracking_token=$matches[1]',
            'top'
        );
    }

    /**
     * Désactive la conversion automatique des emojis en images par WordPress
     * uniquement dans le contexte d'envoi d'emails MailerPress.
     * 
     * On utilise un filtre sur wp_mail avec une priorité élevée pour désactiver
     * temporairement wp_staticize_emoji_for_email uniquement pour les emails MailerPress.
     */
    private function disableEmojiConversion(): void
    {
        // Désactiver le filtre d'emoji juste avant wp_mail pour les emails MailerPress
        // Priorité 5 pour s'exécuter avant wp_staticize_emoji_for_email (priorité 10)
        add_filter('wp_mail', function ($args) {
            $message = $args['message'] ?? '';

            // Vérifier si c'est un email MailerPress en cherchant des marqueurs spécifiques
            $isMailerPressEmail = (
                strpos($message, 'mj-') !== false ||           // Tags MJML
                strpos($message, 'data-emoji-id') !== false || // Spans d'emoji MailerPress
                strpos($message, 'mailerpress') !== false      // Autres marqueurs MailerPress
            );

            if ($isMailerPressEmail) {
                // Désactiver temporairement le filtre d'emoji pour cet email
                remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

                // Le réactiver après l'envoi via shutdown pour ne pas affecter les autres emails
                add_action('shutdown', function () {
                    if (!has_filter('wp_mail', 'wp_staticize_emoji_for_email')) {
                        add_filter('wp_mail', 'wp_staticize_emoji_for_email', 10);
                    }
                }, 999);
            }

            return $args;
        }, 5);
    }

    #[Action('admin_init')]
    public function syncCap(): void
    {
        $current_version = defined('MAILERPRESS_VERSION_DEV')
            ? MAILERPRESS_VERSION_DEV   // dev override
            : MAILERPRESS_VERSION;      // release version

        $stored_version = get_option('mailerpress_version');

        // If the option doesn't exist, $stored_version will be false
        if ($stored_version === false || $stored_version !== $current_version) {
            \MailerPress\Core\CapabilitiesManager::addCapabilities();
            update_option('mailerpress_version', $current_version);

            // Flush rewrite rules when version changes to ensure new rewrite rules are registered
            $this->flushRewriteRules();
        }
    }

    /**
     * Flush rewrite rules to ensure new rules are registered
     * This is called automatically when the plugin version changes
     */
    private function flushRewriteRules(): void
    {
        // Delete the rewrite rules option to force WordPress to regenerate them
        delete_option('rewrite_rules');

        // Flush rewrite rules on next request
        // Using a transient to ensure it only happens once per version change
        $current_version = defined('MAILERPRESS_VERSION_DEV')
            ? MAILERPRESS_VERSION_DEV
            : MAILERPRESS_VERSION;

        $flush_key = 'mailerpress_flush_rewrite_' . $current_version;

        if (!get_transient($flush_key)) {
            flush_rewrite_rules(false); // false = don't hard flush, just update the option
            set_transient($flush_key, true, DAY_IN_SECONDS);
        }
    }


    #[Filter('query_vars')]
    public function queryVars($vars)
    {
        $vars[] = 'unsubscribe_user';
        $vars[] = 'unsubscribe_token';
        $vars[] = 'mailerpress_tracking_token';

        return $vars;
    }

    #[Action('switch_theme')]
    public function sanitize($vars)
    {
        delete_option('mailerpress_theme');
        update_option('mailerpress_theme', 'Core');
        return $vars;
    }

    private function registerDefaultTemplateCategories(): void
    {
        if (\function_exists('mailerpress_register_templates_category')) {
            mailerpress_register_templates_category([
                'mailerpress/core/communication' => [
                    'label' => __('Marketing communication', 'mailerpress'),
                ],
                'mailerpress/core/ecommerce' => [
                    'label' => __('Ecommerce', 'mailerpress'),
                ],
            ]);
        }
    }

    #[Filter('mailerpress_patterns', priority: 10, acceptedArgs: 1)]
    public function handle($patterns)
    {
        $patterns[] = [
            'ID' => 'custom-pattern',
            'post_title' => 'Welcome Header',
            'post_content' => '{"type":"section","data":{"columnCount":1,"border-style":"solid","size":"full"},"attributes":{"padding-left":"10px","padding-right":"10px","padding-bottom":"0px","padding-top":"0px"},"children":[{"type":"column","data":{"border-style":"solid"},"attributes":{"vertical-align":"top","padding-top":"0px","padding-bottom":"0px","padding-right":"10px","padding-left":"10px"},"children":[{"type":"text","data":{"content":"Hello"},"attributes":{"padding-top":"10px","padding-bottom":"10px","padding-left":"25px","padding-right":"25px"},"children":[],"clientId":"d55fbe96-f614-4954-8053-cc14e99cfe77"},{"type":"button","data":{"content":"Click Me","border-style":"solid"},"attributes":{"align":"left","border-radius":"0px","padding-top":"10px","padding-bottom":"10px","padding-left":"25px","padding-right":"25px"},"children":[],"clientId":"181484fe-deac-47ea-9112-097f3a5a6c75","custom":false,"icon":"\n    <svg viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\" width=\"24\" height=\"24\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M14.5 17.5H9.5V16H14.5V17.5Z M14.5 8H9.5V6.5H14.5V8Z M7 3.5H17C18.1046 3.5 19 4.39543 19 5.5V9C19 10.1046 18.1046 11 17 11H7C5.89543 11 5 10.1046 5 9V5.5C5 4.39543 5.89543 3.5 7 3.5ZM17 5H7C6.72386 5 6.5 5.22386 6.5 5.5V9C6.5 9.27614 6.72386 9.5 7 9.5H17C17.2761 9.5 17.5 9.27614 17.5 9V5.5C17.5 5.22386 17.2761 5 17 5Z M7 13H17C18.1046 13 19 13.8954 19 15V18.5C19 19.6046 18.1046 20.5 17 20.5H7C5.89543 20.5 5 19.6046 5 18.5V15C5 13.8954 5.89543 13 7 13ZM17 14.5H7C6.72386 14.5 6.5 14.7239 6.5 15V18.5C6.5 18.7761 6.72386 19 7 19H17C17.2761 19 17.5 18.7761 17.5 18.5V15C17.5 14.7239 17.2761 14.5 17 14.5Z\"></path></svg>\n    ","description":"Prompt visitors to take action with a button-style link.","disabledBlockType":[],"name":"Button","lock":false,"transforms":[{"type":"text"},{"type":"heading"}]}],"clientId":"6d787cbc-1947-4fe7-9201-1c14d090b7b6"}],"clientId":"f8ec502d-6acb-457f-bc6a-fbb0b211fdcf"}',
        ];
        return $patterns;
    }
}
