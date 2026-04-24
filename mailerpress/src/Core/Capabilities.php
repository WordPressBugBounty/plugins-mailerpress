<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

class Capabilities
{
    const MANAGE_SETTINGS = 'mailerpress_manage_settings'; // 'manage_options'
    const MANAGE_CAMPAIGNS = 'mailerpress_manage_campaigns'; // 'edit_posts'
    const EDIT_OTHERS_CAMPAIGNS = 'mailerpress_edit_others_campaings'; // 'edit_posts'
    const PUBLISH_CAMPAIGNS = 'mailerpress_publish_campaigns'; // 'edit_published_post'
    const DELETE_EMAIL_CAMPAIGNS = 'mailerpress_delete_email_campaigns'; // 'edit_posts'
    const DELETE_CONTACTS = 'mailerpress_delete_contacts'; // 'edit_post'
    const MANAGE_CONTACTS = 'mailerpress_manage_contacts'; // 'edit_post'
    const MANAGE_LISTS = 'mailerpress_manage_lists'; // 'manage_categories'
    const DELETE_LISTS = 'mailerpress_delete_lists'; // 'manage_categories'
    const MANAGE_TAGS = 'mailerpress_manage_tags'; // manage_categories
    const DELETE_TAGS = 'mailerpress_delete_tags'; // manage_categories
    const MANAGE_TEMPLATES = 'mailerpress_manage_templates'; // edit_themes
    const MANAGE_AUTOMATIONS = 'mailerpress_manage_automations'; //
    const MANAGE_CONTACT_SEGMENTATION = 'mailerpress_manage_segmentation'; //

    /**
     * Return all capabilities.
     */
    public static function get_capabilities(): array
    {
        return [
            self::MANAGE_SETTINGS,
            self::MANAGE_CAMPAIGNS,
            self::PUBLISH_CAMPAIGNS,
            self::MANAGE_CONTACTS,
            self::MANAGE_AUTOMATIONS,
            self::MANAGE_LISTS,
            self::MANAGE_TAGS,
            self::MANAGE_TEMPLATES,
            self::MANAGE_CONTACT_SEGMENTATION,
            self::DELETE_EMAIL_CAMPAIGNS,
            self::DELETE_LISTS,
            self::DELETE_TAGS,
            self::DELETE_CONTACTS,
            self::EDIT_OTHERS_CAMPAIGNS,
        ];
    }
}
