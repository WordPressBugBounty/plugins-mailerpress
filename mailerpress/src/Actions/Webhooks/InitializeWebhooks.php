<?php

declare(strict_types=1);

namespace MailerPress\Actions\Webhooks;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Webhooks\WebhookManager;
use MailerPress\Core\Webhooks\WebhookReceiver;
use MailerPress\Core\Webhooks\Events\ContactCreatedEvent;
use MailerPress\Core\Webhooks\Events\CampaignSentEvent;
use MailerPress\Core\Webhooks\Events\ContactUpdatedEvent;
use MailerPress\Core\Webhooks\Events\ContactTagAddedEvent;
use MailerPress\Core\Webhooks\Events\ContactTagRemovedEvent;
use MailerPress\Core\Webhooks\Events\ContactListAddedEvent;
use MailerPress\Core\Webhooks\Events\ContactListRemovedEvent;
use MailerPress\Core\Webhooks\Events\ContactCustomFieldUpdatedEvent;
use MailerPress\Core\Webhooks\Events\CampaignCreatedEvent;
use MailerPress\Core\Webhooks\Events\CampaignScheduledEvent;
use MailerPress\Core\Webhooks\Events\ContactDeletedEvent;
use MailerPress\Core\Webhooks\Events\ContactUnsubscribedEvent;
use MailerPress\Core\Webhooks\Events\SubscriptionConfirmedEvent;
use MailerPress\Core\Webhooks\Events\EmailOpenedEvent;
use MailerPress\Core\Webhooks\Events\EmailClickedEvent;
use MailerPress\Core\Webhooks\Events\EmailBouncedEvent;


/**
 * Initialise le système de webhooks
 *
 * @since 1.2.0
 */
class InitializeWebhooks
{
    /**
     * Vérifie si MailerPress Pro est actif
     *
     * @return bool True si Pro est actif
     */
    private function isProActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active')
            && \is_plugin_active('mailerpress-pro/mailerpress-pro.php');
    }

    /**
     * Initialise le système de webhooks
     */
    #[Action('init')]
    public function initialize(): void
    {
        // Vérifier que Pro est actif avant d'initialiser les webhooks
        if (!$this->isProActive()) {
            return;
        }

        $manager = WebhookManager::getInstance();

        // Enregistrer les événements par défaut - Contacts
        $manager->registerEvent('contact.created', ContactCreatedEvent::class);
        $manager->registerEvent('contact.updated', ContactUpdatedEvent::class);
        $manager->registerEvent('contact.tag.added', ContactTagAddedEvent::class);
        $manager->registerEvent('contact.tag.removed', ContactTagRemovedEvent::class);
        $manager->registerEvent('contact.list.added', ContactListAddedEvent::class);
        $manager->registerEvent('contact.list.removed', ContactListRemovedEvent::class);
        $manager->registerEvent('contact.custom_field.updated', ContactCustomFieldUpdatedEvent::class);

        // Enregistrer les événements par défaut - Contacts (lifecycle)
        $manager->registerEvent('contact.deleted', ContactDeletedEvent::class);
        $manager->registerEvent('contact.unsubscribed', ContactUnsubscribedEvent::class);
        $manager->registerEvent('subscription.confirmed', SubscriptionConfirmedEvent::class);

        // Enregistrer les événements par défaut - Campagnes
        $manager->registerEvent('campaign.created', CampaignCreatedEvent::class);
        $manager->registerEvent('campaign.scheduled', CampaignScheduledEvent::class);
        $manager->registerEvent('campaign.sent', CampaignSentEvent::class);

        // Enregistrer les événements par défaut - Email engagement
        $manager->registerEvent('email.opened', EmailOpenedEvent::class);
        $manager->registerEvent('email.clicked', EmailClickedEvent::class);
        $manager->registerEvent('email.bounced', EmailBouncedEvent::class);

        // Permettre aux extensions d'enregistrer leurs propres événements
        do_action('mailerpress_register_webhook_events', $manager);
    }
}
