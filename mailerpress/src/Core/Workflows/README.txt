╔════════════════════════════════════════════════════════════════╗
║         MAILERPRESS WORKFLOW SYSTEM - GUIDE D'INSTALLATION     ║
╚════════════════════════════════════════════════════════════════╝

📦 CONTENU DU PACKAGE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Workflows/
├── Models/                      [5 fichiers]
│   ├── Automation.php
│   ├── Step.php
│   ├── StepBranch.php
│   ├── AutomationJob.php
│   └── AutomationLog.php
│
├── Repositories/                [4 fichiers]
│   ├── AutomationRepository.php
│   ├── StepRepository.php
│   ├── AutomationJobRepository.php
│   └── AutomationLogRepository.php
│
├── Handlers/                    [6 fichiers]
│   ├── StepHandlerInterface.php
│   ├── StepHandlerRegistry.php
│   ├── ConditionStepHandler.php
│   ├── DelayStepHandler.php
│   ├── SendEmailStepHandler.php
│   └── AddTagStepHandler.php
│
├── Services/                    [5 fichiers]
│   ├── ConditionEvaluator.php
│   ├── WorkflowExecutor.php
│   ├── TriggerManager.php
│   ├── ActionSchedulerManager.php
│   └── WorkflowManager.php
│
├── Results/                     [1 fichier]
│   └── StepResult.php
│
├── WorkflowSystem.php           [Fichier principal]
└── README.md                    [Documentation]

TOTAL: 22 fichiers PHP + 1 README


🚀 INSTALLATION EN 4 ÉTAPES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ÉTAPE 1: Copier les fichiers
────────────────────────────────────────────────────────────────
Copiez le dossier 'Workflows' dans votre plugin:

Source:      /Workflows/
Destination: /wp-content/plugins/mailerpress/Core/Workflows/


ÉTAPE 2: Inclure le système dans votre plugin
────────────────────────────────────────────────────────────────
Dans votre fichier principal (ex: mailerpress.php), ajoutez:

<?php
// Charger le système de workflows
require_once plugin_dir_path(__FILE__) . 'Core/Workflows/WorkflowSystem.php';


ÉTAPE 3: Enregistrer vos handlers (optionnel)
────────────────────────────────────────────────────────────────
Créez un fichier d'initialisation (ex: workflow-init.php):

<?php
use MailerPress\Core\Workflows\WorkflowSystem;
use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Workflows\Handlers\AddTagStepHandler;

add_action('init', function() {
    $system = WorkflowSystem::getInstance();
    $manager = $system->getManager();
    
    // Enregistrer les handlers
    $manager->registerStepHandler(new SendEmailStepHandler());
    $manager->registerStepHandler(new AddTagStepHandler());
    
    // Enregistrer des triggers personnalisés (WooCommerce, etc.)
    $manager->registerTrigger(
        'product_purchased',
        'woocommerce_order_status_completed',
        function ($orderId) {
            $order = wc_get_order($orderId);
            return [
                'user_id' => $order->get_user_id(),
                'order_id' => $orderId,
            ];
        }
    );
});

Puis incluez ce fichier dans votre plugin principal.


ÉTAPE 4: Installer Action Scheduler
────────────────────────────────────────────────────────────────
Le système utilise Action Scheduler pour gérer les délais.

Option 1 - Via Composer:
    composer require woocommerce/action-scheduler

Option 2 - Manuellement:
    1. Téléchargez depuis: https://github.com/woocommerce/action-scheduler
    2. Placez dans /lib/action-scheduler/
    3. Incluez: require_once 'lib/action-scheduler/action-scheduler.php';


✅ VÉRIFICATION DE L'INSTALLATION
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Pour vérifier que tout fonctionne, ajoutez ce code temporaire:

<?php
add_action('admin_notices', function() {
    $system = \MailerPress\Core\Workflows\WorkflowSystem::getInstance();
    $manager = $system->getManager();
    
    $automations = $manager->getActiveAutomations();
    
    echo '<div class="notice notice-success">';
    echo '<p>✅ Workflow System chargé! Automations actives: ' . count($automations) . '</p>';
    echo '</div>';
});


🎯 UTILISATION
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Une fois installé, le système fonctionne automatiquement:

1. Les workflows créés via votre UI React sont stockés dans la BDD
2. Les triggers WordPress déclenchent automatiquement les workflows
3. Les étapes s'exécutent dans l'ordre défini
4. Les délais sont gérés par Action Scheduler
5. Les logs sont enregistrés automatiquement


📊 EXEMPLE DE WORKFLOW DANS LA BDD
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Table: wp_mailerpress_automations
┌────┬──────────────────────┬──────────┐
│ id │ name                 │ status   │
├────┼──────────────────────┼──────────┤
│ 1  │ Welcome New Users    │ ENABLED  │
└────┴──────────────────────┴──────────┘

Table: wp_mailerpress_automations_steps
┌────┬─────────────┬────────────┬───────────┬──────────────┐
│ id │ step_id     │ type       │ key       │ next_step_id │
├────┼─────────────┼────────────┼───────────┼──────────────┤
│ 1  │ trigger_1   │ TRIGGER    │ user_reg  │ action_1     │
│ 2  │ action_1    │ ACTION     │ send_mail │ delay_1      │
│ 3  │ delay_1     │ DELAY      │ wait      │ action_2     │
│ 4  │ action_2    │ ACTION     │ add_tag   │ NULL         │
└────┴─────────────┴────────────┴───────────┴──────────────┘


🆘 SUPPORT & DÉPANNAGE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Problème: "Class not found"
Solution: Vérifiez le chemin du require_once et les namespaces

Problème: "Action Scheduler not found"
Solution: Installez Action Scheduler (voir ÉTAPE 4)

Problème: Les workflows ne se déclenchent pas
Solution: Vérifiez que le status de l'automation est "ENABLED"

Problème: Les délais ne fonctionnent pas
Solution: Vérifiez qu'Action Scheduler est bien initialisé


📚 DOCUMENTATION COMPLÈTE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Consultez le fichier README.md pour:
- API complète
- Exemples de code
- Liste des opérateurs de conditions
- Création de handlers personnalisés
- Structure des données JSON


🎯 UTILISATION


Vous pouvez également enregistrer des handlers depuis d'autres parties de votre plugin en utilisant le hook WordPress. Par exemple:

<?php
use MailerPress\Core\Workflows\WorkflowSystem;
use MailerPress\Core\Workflows\Handlers\SendEmailStepHandler;
use MailerPress\Core\Workflows\Handlers\AddTagStepHandler;

add_action('init', function() {
    $system = WorkflowSystem::getInstance();
    $manager = $system->getManager();
    
    // Enregistrer les handlers
    $manager->registerStepHandler(new SendEmailStepHandler());
    $manager->registerStepHandler(new AddTagStepHandler());
    
    // Enregistrer des triggers personnalisés (WooCommerce, etc.)
    $manager->registerTrigger(
        'product_purchased',
        'woocommerce_order_status_completed',
        function ($orderId) {
            $order = wc_get_order($orderId);
            return [
                'user_id' => $order->get_user_id(),
                'order_id' => $orderId,
            ];
        }
    );
});


📝 ENREGISTRER DES HANDLERS DEPUIS D'AUTRES MODULES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Au lieu de centraliser tous les handlers dans mailerpress.php, vous pouvez les enregistrer depuis différentes parties du plugin en utilisant le hook 'mailerpress_register_step_handlers':

EXEMPLE 1: Depuis un fichier d'Actions personnalisées
───────────────────────────────────────────────────────

Fichier: src/Actions/Workflows/RegisterHandlers.php

<?php
namespace MailerPress\Actions\Workflows;

use MailerPress\Core\Workflows\Handlers\StepHandlerInterface;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;

class MyCustomStepHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'my_custom_action';
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        // Récupérer les paramètres du step
        $settings = $step->getSettings();
        
        // Votre logique personnalisée ici
        $success = true; // À adapter selon votre logique
        
        if (!$success) {
            return StepResult::failed('Something went wrong');
        }

        return StepResult::success($step->getNextStepId(), [
            'action_completed' => true,
            'data' => $settings,
        ]);
    }
}

// Puis enregistrez-le:
add_action('mailerpress_register_step_handlers', function($manager) {
    $manager->registerStepHandler(new MyCustomStepHandler());
});


EXEMPLE 2: Depuis un module intégré (WooCommerce, etc.)
────────────────────────────────────────────────────────

Fichier: src/Actions/Gutenberg/woocommerce/RegisterWooHandlers.php

<?php
namespace MailerPress\Actions\Gutenberg\WooCommerce;

class WooCommerceWorkflowHandlers
{
    public static function register(): void
    {
        add_action('mailerpress_register_step_handlers', function($manager) {
            if (!function_exists('wc_get_products')) {
                return; // WooCommerce not active
            }
            
            $manager->registerStepHandler(new CreateProductNotificationHandler());
            $manager->registerStepHandler(new UpdateInventoryHandler());
        });
    }
}

// Dans mailerpress.php ou dans le Kernel:
WooCommerceWorkflowHandlers::register();


EXEMPLE 3: Format simple pour des handlers basiques
────────────────────────────────────────────────────

Si vous avez juste besoin d'ajouter un handler rapidement, créez le fichier et enregistrez-le:

// Fichier: src/Actions/Workflows/CustomHandler.php
<?php
namespace MailerPress\Actions\Workflows;

use MailerPress\Core\Workflows\Handlers\StepHandlerInterface;
use MailerPress\Core\Workflows\Models\Step;
use MailerPress\Core\Workflows\Models\AutomationJob;
use MailerPress\Core\Workflows\Results\StepResult;

class CustomHandler implements StepHandlerInterface
{
    public function supports(string $key): bool
    {
        return $key === 'custom_key';
    }

    public function handle(Step $step, AutomationJob $job, array $context = []): StepResult
    {
        // Do something with $step->getSettings() and $context
        return StepResult::success($step->getNextStepId());
    }
}

// Puis dans n'importe quel fichier (actions, services, etc.):
add_action('mailerpress_register_step_handlers', function($manager) {
    $manager->registerStepHandler(new CustomHandler());
});


✅ AVANTAGES DE CETTE APPROCHE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✓ Meilleure organisation du code
✓ Les handlers sont proches de leur logique métier
✓ Facile à ajouter de nouveaux handlers sans modifier mailerpress.php
✓ Permet à des extensions tierces d'ajouter leurs propres handlers
✓ Respecte le pattern des hooks WordPress
