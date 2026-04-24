<?php

use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Migrations\SchemaBuilder;
use MailerPress\Core\Migrations\CustomTableManager;

return function (SchemaBuilder $schema) {
    /**
     * ✅ Optimisation: Ajouter des index composites pour améliorer les performances
     * des requêtes fréquentes, notamment avec WooCommerce et autres plugins actifs
     */
    
    // Index composite pour les requêtes filtrant par user_id et status (requête "mine")
    $schema->table(Tables::MAILERPRESS_CAMPAIGNS, function (CustomTableManager $table) {
        $table->addIndex(['user_id', 'status']);
        // Only add campaign_type index if column exists (will be checked in migrate())
        $table->addIndex(['campaign_type', 'status']);
        $table->addIndex('batch_id'); // Pour les JOINs avec email_batches
        $table->setVersion('1.2.0');
    });

    // Index pour les requêtes de filtrage par status sur email_batches
    $schema->table(Tables::MAILERPRESS_EMAIL_BATCHES, function (CustomTableManager $table) {
        $table->addIndex(['campaign_id', 'status']);
        $table->addIndex(['scheduled_at', 'status']); // Pour les requêtes de planning
        $table->setVersion('1.2.0');
    });

    // Index pour les requêtes de contact_stats triées par updated_at
    $schema->table(Tables::MAILERPRESS_CONTACT_STATS, function (CustomTableManager $table) {
        $table->addIndex('updated_at');
        $table->addIndex(['campaign_id', 'updated_at']); // Pour les requêtes de dernière activité
        $table->setVersion('1.2.0');
    });

    // Index pour les requêtes de tracking filtrées par opened_at/unsubscribed_at
    $schema->table(Tables::MAILERPRESS_EMAIL_TRACKING, function (CustomTableManager $table) {
        $table->addIndex('opened_at');
        $table->addIndex('unsubscribed_at');
        $table->addIndex(['batch_id', 'opened_at']); // Pour les requêtes de statistiques par batch
        $table->setVersion('1.2.0');
    });

    // Index pour les requêtes de click_tracking triées par created_at
    $schema->table(Tables::MAILERPRESS_CLICK_TRACKING, function (CustomTableManager $table) {
        $table->addIndex('created_at');
        $table->addIndex(['campaign_id', 'created_at']); // Pour les requêtes de clics par campagne
        $table->setVersion('1.2.0');
    });

    // Index pour les requêtes filtrant les contacts par subscription_status (très fréquent)
    $schema->table(Tables::MAILERPRESS_CONTACT, function (CustomTableManager $table) {
        $table->addIndex('subscription_status');
        $table->addIndex(['subscription_status', 'updated_at']); // Pour les requêtes de dashboard
        $table->setVersion('1.2.0');
    });

    // Index composite pour contact_custom_fields (recherche fréquente)
    $schema->table(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS, function (CustomTableManager $table) {
        $table->addIndex(['contact_id', 'field_key']); // Pour éviter les doublons et améliorer les recherches
        $table->setVersion('1.2.0');
    });
};

