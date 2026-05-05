<?php

declare(strict_types=1);

namespace MailerPress\Actions\Pages;

\defined('ABSPATH') || exit;

use DI\DependencyException;
use DI\NotFoundException;
use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Filter;
use MailerPress\Core\Kernel;
use MailerPress\Core\TemplateRenderer;
use MailerPress\Models\Contacts;

class Pages
{
    public const ACTION_CONFIRM = 'confirm';
    public const ACTION_CONFIRM_UNSUBSCRIBE = 'confirm_unsubscribe';
    public const ACTION_MANAGE = 'manage';
    public const ACTION_UNSUBSCRIBE = 'unsubscribe';

    private Contacts $contacts;

    public function __construct(Contacts $contacts)
    {
        $this->contacts = $contacts;
    }

    #[Action('init')]
    public function shortcodes(): void
    {
        add_shortcode('mailerpress_pages', [$this, 'renderMailerpressShortcode']);
        add_rewrite_tag('%dashboard%', '([^&]+)');
        add_rewrite_tag('%dashboard_page%', '([^&]+)');
        add_rewrite_rule('dashboard/([a-z0-9-]+)[/]?$', 'index.php?dashboard=1&dashboard_page=$matches[1]', 'top');
    }

    public function render($atts): bool|string
    {
        if (!isset($_GET['contact_id'])) {
            return esc_html__('The unsubscribe link is invalid.', 'mailerpress');
        }

        $contactId = absint(wp_unslash($_GET['contact_id']));
        if ($contactId === 0) {
            return esc_html__('The unsubscribe link is invalid.', 'mailerpress');
        }

        $contact = $this->contacts->get($contactId);

        ob_start();

        $theme_template = locate_template('unsubscribe-template.php');

        if ($theme_template) {
            // Load the theme's unsubscribe template
            include $theme_template;
        } else {
            if (file_exists(Kernel::$config['root'] . '/src/Templates/unsubscribe.php')) {
                include Kernel::$config['root'] . '/src/Templates/unsubscribe.php';
            }
        }

        return ob_get_clean();
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    #[Filter('the_title')]
    public function pageTitle($pageTitle)
    {
        global $post;
        if (
            empty($_GET['action'])
            || !isset($post)
            || $post->post_type !== Kernel::getContainer()->get('cpt-page-slug')
            || $pageTitle !== single_post_title('', false)
        ) {
            return $pageTitle;
        }

        $pageTitle = $this->getPageTitle(sanitize_text_field(wp_unslash($_GET['action'])));

        return $pageTitle;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \Exception
     */
    //    #[Filter('wp_title', scope: 'front', priority: 10, acceptedArgs: 3)]
    //    public function wpTitle($title, $separator, $separatorLocation = 'right')
    //    {
    //        global $post;
    //        if (
    //            $post->post_type === Kernel::getContainer()->get('cpt-page-slug')
    //            && (!empty($_GET['data']) || null !== $this->contacts->getContactByToken(sanitize_text_field(wp_unslash($_GET['data']))))
    //        ) {
    //            return implode('coucou', " {$separator} ");
    //        }
    //    }

    public function renderMailerpressShortcode($atts): string
    {
        // Parse shortcode attributes
        $atts = shortcode_atts([
            'redirect_url' => '',
            'after_unsubscribe_url' => '',
            'confirm_title' => '',
        ], $atts, 'mailerpress_pages');

        $renderer = TemplateRenderer::getInstance(
            Kernel::$config['root'] . '/templates'
        );

        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
        $content = '';

        $is_preview = $this->isBuilderPreview();

        switch ($action) {
            case self::ACTION_CONFIRM:
                if ( $is_preview ) {
                    $content = $renderer->render('double-option-confirmation', [
                        'contact' => null,
                        'title'   => ! empty( $atts['confirm_title'] )
                            ? esc_html( $atts['confirm_title'] )
                            : esc_html__( 'We have added you to our list. You will receive our next newsletter.', 'mailerpress' ),
                    ]);
                    break;
                }
                $contact = isset($_GET['cid']) ? $this->contacts->getByAccessToken(sanitize_text_field(wp_unslash($_GET['cid']))) : null;
                if ($contact) {
                    $this->contacts->subscribe($contact->contact_id);

                    $content = $renderer->render('double-option-confirmation', [
                        'contact' => $contact,
                        'title' => !empty($atts['confirm_title'])
                            ? esc_html($atts['confirm_title'])
                            : esc_html__(
                                'We have added you to our list. You will receive our next newsletter.',
                                'mailerpress'
                            ),
                    ]);
                }
                break;

            case self::ACTION_MANAGE:
                if ( ! $is_preview ) {
                    $contact = isset($_GET['cid']) ? $this->contacts->getByAccessToken(sanitize_text_field(wp_unslash($_GET['cid']))) : null;
                } else {
                    $admin_email = get_option('admin_email');
                    $user = get_user_by('email', $admin_email);

                    $contact = new \stdClass();
                    $contact->email = $admin_email;
                    $contact->first_name = get_user_meta($user->ID, 'first_name', true) ?? '';
                    $contact->last_name = get_user_meta($user->ID, 'last_name', true) ?? '';
                    $contact->subscription_status = 'unsubscribed';
                }
                $defaultSettings = get_option('mailerpress_default_settings', []);
                if (is_string($defaultSettings)) {
                    $defaultSettings = json_decode($defaultSettings, true) ?: [];
                }
                $disableListManagement = !empty($defaultSettings['disableListManagement']);

                $content = $renderer->render('manage-subscription', [
                    'contact' => $contact,
                    'disableListManagement' => $disableListManagement,
                ]);
                break;

            case self::ACTION_UNSUBSCRIBE:
                if ( $is_preview ) {
                    $content = $renderer->render('double-option-confirmation', [
                        'contact' => null,
                        'title'   => esc_html__( 'You have successfully unsubscribed from our emails.', 'mailerpress' ),
                    ]);
                    break;
                }
                $contact = isset($_GET['data']) ? $this->contacts->getContactByToken(sanitize_text_field(wp_unslash($_GET['data']))) : null;
                $batchId = !empty($_GET['batchId']) ? sanitize_text_field(wp_unslash($_GET['batchId'])) : null;
                if ($contact) {
                    $this->contacts->unsubscribe($contact->contact_id, $batchId);
                    do_action('mailerpress_contact_unsubscribed', (int) $contact->contact_id);
                }
                do_action('mailerpress_unsubscribe');

                $content = $renderer->render('double-option-confirmation', [
                    'contact' => $contact,
                    'title' => esc_html__('You have successfully unsubscribed from our emails.', 'mailerpress'),
                ]);
                break;

            case self::ACTION_CONFIRM_UNSUBSCRIBE:
                if ( ! $is_preview ) {
                    // Build unsubscribe URL with query parameters
                    $unsubscribe_params = [
                        'action' => 'unsubscribe',
                    ];

                    if (isset($_GET['data'])) {
                        $unsubscribe_params['data'] = sanitize_text_field(wp_unslash($_GET['data']));
                    }
                    if (isset($_GET['batchId'])) {
                        $unsubscribe_params['batchId'] = sanitize_text_field(wp_unslash($_GET['batchId']));
                    }

                    // Add after_unsubscribe_url as query parameter if provided
                    if (!empty($atts['after_unsubscribe_url'])) {
                        $unsubscribe_params['after_unsubscribe_url'] = urlencode($atts['after_unsubscribe_url']);
                    }

                    $unsubscribe_url = add_query_arg($unsubscribe_params, mailerpress_get_page('unsub_page'));

                    $content = $renderer->render('confirm-unsubscribe-template', [
                        'title' => esc_html__('Just click this link to unsubscribe from our emails.', 'mailerpress'),
                        'email' => 'john.doe@example.com', // Replace with actual logic
                        'unsubscribe_url' => $unsubscribe_url,
                    ]);
                } else {
                    // Build unsubscribe URL for preview
                    $unsubscribe_params = [
                        'action' => 'unsubscribe',
                    ];

                    // Add after_unsubscribe_url as query parameter if provided
                    if (!empty($atts['after_unsubscribe_url'])) {
                        $unsubscribe_params['after_unsubscribe_url'] = urlencode($atts['after_unsubscribe_url']);
                    }

                    $unsubscribe_url = add_query_arg($unsubscribe_params, mailerpress_get_page('unsub_page'));

                    $content = $renderer->render('confirm-unsubscribe-template', [
                        'title' => esc_html__('Just click this link to unsubscribe from our emails.', 'mailerpress'),
                        'email' => 'john.doe@example.com', // Replace with actual logic
                        'unsubscribe_url' => $unsubscribe_url,
                    ]);
                }
                break;

            default:
                if ( $is_preview ) {
                    $content = $this->renderPreviewDashboard( get_permalink() );
                }
                break;
        }

        return $content;
    }

    /**
     * Returns true when any known page-builder preview/edit mode is active.
     *
     * Supported builders:
     *  - Gutenberg / WordPress core  : is_preview() or ?preview=true
     *  - Manual override             : ?mp_preview=1
     *  - Elementor                   : ?elementor-preview=<post_id>
     *  - Bricks Builder              : ?bricks=run
     *  - Divi Visual Builder         : ?et_fb=1
     */
    private function isBuilderPreview(): bool
    {
        return is_preview()
            || ! empty( $_GET['mp_preview'] )
            || ! empty( $_GET['elementor-preview'] )
            || ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] )
            || ! empty( $_GET['et_fb'] );
    }

    private function renderPreviewDashboard( string $base_url ): string
    {
        $actions = [
            self::ACTION_CONFIRM             => esc_html__( 'Subscription confirmed', 'mailerpress' ),
            self::ACTION_UNSUBSCRIBE         => esc_html__( 'Unsubscribed', 'mailerpress' ),
            self::ACTION_CONFIRM_UNSUBSCRIBE => esc_html__( 'Confirm unsubscribe', 'mailerpress' ),
            self::ACTION_MANAGE              => esc_html__( 'Manage subscription', 'mailerpress' ),
        ];

        $items = '';
        foreach ( $actions as $action_key => $label ) {
            $url    = esc_url( add_query_arg( [ 'action' => $action_key, 'mp_preview' => '1' ], $base_url ) );
            $items .= sprintf(
                '<li><a href="%s">%s</a></li>',
                $url,
                esc_html( $label )
            );
        }

        return sprintf(
            '<div class="mailerpress-shortcode-preview-notice" style="border:1px solid #c3c4c7;border-left:4px solid #72aee6;padding:16px 20px;background:#fff;font-family:sans-serif;">'
            . '<p style="margin:0 0 8px;font-weight:600;">%s</p>'
            . '<p style="margin:0 0 12px;color:#50575e;">%s</p>'
            . '<ul style="margin:0;padding-left:20px;">%s</ul>'
            . '</div>',
            esc_html__( 'MailerPress Dynamic Page — Preview mode', 'mailerpress' ),
            esc_html__( 'This shortcode renders different content depending on the URL parameters sent in email links. Click a state below to preview it:', 'mailerpress' ),
            $items
        );
    }


    #[Filter('document_title_parts', priority: 10, acceptedArgs: 1)]
    public function setWindowTitleParts($meta = [])
    {
        global $post;

        if (empty($post)) {
            return $meta;
        }

        if (
            $post->post_type === Kernel::getContainer()->get('cpt-page-slug')
            && (!empty($_GET['data']) && null !== $this->contacts->getContactByToken(sanitize_text_field(wp_unslash($_GET['data']))))
            && (!empty($_GET['cid']) && null !== $this->contacts->get((int)wp_unslash($_GET['cid'])))
        ) {
            if (!empty($_GET['action'])) {
                $meta['title'] = $this->getPageTitle(sanitize_text_field(wp_unslash($_GET['action'])));
            }
        }

        return $meta;
    }

    #[Action('template_redirect', priority: 5)]
    public function handleEarlyRedirects(): void
    {
        global $post;

        if ( empty( $post ) ) {
            return;
        }

        if (
            $post->post_type !== Kernel::getContainer()->get( 'cpt-page-slug' ) &&
            ! has_shortcode( $post->post_content, 'mailerpress_pages' )
        ) {
            return;
        }

        if ( $this->isBuilderPreview() ) {
            return;
        }

        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

        if ( '' === $action ) {
            return;
        }

        $atts = $this->getShortcodeAttributesFromContent( $post->post_content );

        switch ( $action ) {
            case self::ACTION_CONFIRM:
                $redirect_url      = '';
                $confirmation_data = mailerpress_get_signup_confirmation_option();
                if ( $confirmation_data && ! empty( $confirmation_data['confirmRedirectUrl'] ) ) {
                    $redirect_url = $confirmation_data['confirmRedirectUrl'];
                    if ( is_numeric( $redirect_url ) ) {
                        $redirect_url = get_permalink( (int) $redirect_url );
                    }
                }

                if ( ! empty( $atts['redirect_url'] ) ) {
                    $redirect_url = $atts['redirect_url'];
                }

                if ( ! empty( $redirect_url ) ) {
                    $contact = isset( $_GET['cid'] ) ? $this->contacts->getByAccessToken( sanitize_text_field( wp_unslash( $_GET['cid'] ) ) ) : null;
                    if ( $contact ) {
                        $this->contacts->subscribe( $contact->contact_id );
                    }
                    wp_redirect( esc_url_raw( $redirect_url ) );
                    exit;
                }
                break;

            case self::ACTION_UNSUBSCRIBE:
                $after_unsubscribe_url = ! empty( $atts['after_unsubscribe_url'] ) ? $atts['after_unsubscribe_url'] : '';

                if ( ! empty( $after_unsubscribe_url ) ) {
                    $contact = isset( $_GET['data'] ) ? $this->contacts->getContactByToken( sanitize_text_field( wp_unslash( $_GET['data'] ) ) ) : null;
                    $batchId = ! empty( $_GET['batchId'] ) ? sanitize_text_field( wp_unslash( $_GET['batchId'] ) ) : null;
                    if ( $contact ) {
                        $this->contacts->unsubscribe( $contact->contact_id, $batchId );
                        do_action( 'mailerpress_contact_unsubscribed', (int) $contact->contact_id );
                    }
                    do_action( 'mailerpress_unsubscribe' );
                    wp_redirect( esc_url_raw( $after_unsubscribe_url ) );
                    exit;
                }
                break;

            case self::ACTION_CONFIRM_UNSUBSCRIBE:
                if ( ! empty( $atts['redirect_url'] ) ) {
                    wp_redirect( esc_url_raw( $atts['redirect_url'] ) );
                    exit;
                }
                break;
        }
    }

    private function getShortcodeAttributesFromContent( string $content ): array
    {
        $defaults = [
            'redirect_url'         => '',
            'after_unsubscribe_url' => '',
            'confirm_title'        => '',
        ];

        $pattern = get_shortcode_regex( [ 'mailerpress_pages' ] );
        if ( preg_match( "/$pattern/s", $content, $matches ) ) {
            $parsed = shortcode_parse_atts( $matches[3] );
            if ( ! is_array( $parsed ) ) {
                $parsed = [];
            }

            return shortcode_atts( $defaults, $parsed, 'mailerpress_pages' );
        }

        return $defaults;
    }

    #[Action('template_redirect', priority: 10)]
    public function redirectTo404(): void
    {
        global $post, $wp_query;

        if (empty($post)) {
            return;
        }

        if (
            (
                $post->post_type === Kernel::getContainer()->get('cpt-page-slug') ||
                has_shortcode($post->post_content, 'mailerpress_pages')
            )
        ) {
            if (
                isset($_GET['action']) &&
                $_GET['action'] === 'confirm_unsubscribe' &&
                ! $this->isBuilderPreview() && // only validate if not a preview
                (
                    empty($_GET['cid']) ||
                    empty($_GET['data']) ||
                    empty($_GET['batchId'])
                )
            ) {
                $wp_query->set_404();
                status_header(404);
                nocache_headers();

                $template_404 = get_query_template( '404' );
                if ( $template_404 ) {
                    include $template_404;
                }
                exit;
            }
        }


        if ((
            $post->post_type === Kernel::getContainer()->get('cpt-page-slug') ||
            has_shortcode($post->post_content, 'mailerpress_pages')
        )) {
            if ( ! empty( $_GET['action'] ) && $_GET['action'] === 'manage' && empty( $_GET['cid'] ) && ! $this->isBuilderPreview() ) {
                $wp_query->set_404();
                status_header(404);
                nocache_headers();

                $template_404 = get_query_template( '404' );
                if ( $template_404 ) {
                    include $template_404;
                }
                exit;
            }
        }

        if (
            (
                $post->post_type === Kernel::getContainer()->get('cpt-page-slug') ||
                has_shortcode($post->post_content, 'mailerpress_pages')
            )
            && (
                (!empty($_GET['data']) && null === $this->contacts->getContactByToken(sanitize_text_field(wp_unslash($_GET['data']))))
                || (!empty($_GET['cid']) && null === $this->contacts->getByAccessToken(sanitize_text_field(wp_unslash($_GET['cid']))))
            )
        ) {
            $wp_query->set_404();
            status_header(404);
            nocache_headers();

            $template_404 = get_query_template( '404' );
            if ( $template_404 ) {
                include $template_404;
            }
            exit;
        }
    }


    #[Action('wp_head')]
    public function wp_head(): void
    {
        global $post;

        if (empty($post)) {
            return;
        }

        if (
            $post->post_type === Kernel::getContainer()->get('cpt-page-slug')
            && (!empty($_GET['data']) && null !== $this->contacts->getContactByToken(sanitize_text_field(wp_unslash($_GET['data']))))
            && (!empty($_GET['cid']) && null !== $this->contacts->get((int)wp_unslash($_GET['cid'])))
        ) {
            remove_action('wp_head', 'noindex', 1);
            echo '<meta name="robots" content="noindex,nofollow">';
        }
    }

    private function getPageTitle(mixed $action)
    {
        $pageTitle = '';
        if (!empty($action)) {
            switch ($action) {
                case self::ACTION_CONFIRM:
                    $pageTitle = esc_html__('You have successfully subscribed.', 'mailerpress');

                    break;

                case self::ACTION_UNSUBSCRIBE:
                    $pageTitle = esc_html__('You have successfully unsubscribed.', 'mailerpress');

                    break;

                case self::ACTION_CONFIRM_UNSUBSCRIBE:
                    $pageTitle = esc_html__('Confirm your unsubscribe request.', 'mailerpress');

                    break;

                case self::ACTION_MANAGE:
                    $pageTitle = esc_html__('Manage your email subscription.', 'mailerpress');

                    break;
            }
        }

        return $pageTitle;
    }
}
