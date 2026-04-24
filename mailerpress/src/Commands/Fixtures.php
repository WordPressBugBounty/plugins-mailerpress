<?php

namespace MailerPress\Commands;

use MailerPress\Core\Attributes\Command;
use MailerPress\Core\Enums\Tables;

\defined('ABSPATH') || exit;

class Fixtures
{
	#[Command('mailerpress fixtures:generate')]
	public function generate(array $args, array $assoc_args): void
	{
		if (!\class_exists('\\WP_CLI')) {
			return;
		}

		\WP_CLI::log('⏳ Génération des fixtures MailerPress...');

		// 1) Créer quelques utilisateurs de test s'ils n'existent pas
		$userIds = $this->ensureTestUsers();

		// 2) Créer plusieurs automations ENABLED avec des steps variés
		$automationIds = [];
		$automationIds[] = $this->createWelcomeEmailAutomation();
		$automationIds[] = $this->createConditionalTagAutomation();
		$automationIds[] = $this->createDelayThenEmailAutomation();
		$automationIds[] = $this->createContactOptinWelcomeAutomation();

		$automationIds = \array_filter($automationIds, static fn($v) => !empty($v));

		\WP_CLI::success(\sprintf('Fixtures créées: %d automations, %d utilisateurs.', \count($automationIds), \count($userIds)));
	}

	private function ensureTestUsers(): array
	{
		$userSpecs = [
			['user_login' => 'mp_test1', 'user_email' => 'mp_test1@example.com', 'display_name' => 'MP Test 1'],
			['user_login' => 'mp_test2', 'user_email' => 'mp_test2@example.com', 'display_name' => 'MP Test 2'],
			['user_login' => 'mp_test3', 'user_email' => 'mp_test3@example.com', 'display_name' => 'MP Test 3'],
		];

		$userIds = [];
		foreach ($userSpecs as $spec) {
			$existing = \get_user_by('login', $spec['user_login']);
			if ($existing) {
				$userIds[] = (int)$existing->ID;
				continue;
			}

			$userId = \wp_insert_user([
				'user_login' => $spec['user_login'],
				'user_pass' => \wp_generate_password(18, true),
				'user_email' => $spec['user_email'],
				'role' => 'subscriber',
				'display_name' => $spec['display_name'],
			]);

			if (!\is_wp_error($userId)) {
				$userIds[] = (int)$userId;
			}
		}

		return $userIds;
	}

	private function createWelcomeEmailAutomation(): ?int
	{
		global $wpdb;
		$tableAutomations = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS;
		$tableSteps = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;

		$automationData = [
			'name' => 'Welcome email on user_register',
			'author' => \get_current_user_id() ?: 1,
			'status' => 'ENABLED',
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		];

		$inserted = $wpdb->insert($tableAutomations, $automationData);
		if (!$inserted) {
			return null;
		}

		$automationId = (int)$wpdb->insert_id;

		$triggerStepId = \wp_generate_uuid4();
		$actionStepId = \wp_generate_uuid4();

		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $triggerStepId,
			'type' => 'TRIGGER',
			'key' => 'contact_subscribed',
			'settings' => \wp_json_encode(['conditions' => []]),
			'next_step_id' => $actionStepId,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $actionStepId,
			'type' => 'ACTION',
			'key' => 'send_email',
			'settings' => \wp_json_encode([
				'subject' => 'Bienvenue {{user_name}}',
				'message' => 'Bonjour {{user_name}}, votre ID est {{user_id}}. Email: {{user_email}}',
			]),
			'next_step_id' => null,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		return $automationId;
	}

	private function createConditionalTagAutomation(): ?int
	{
		global $wpdb;
		$tableAutomations = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS;
		$tableSteps = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;
		$tableBranches = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEP_BRANCHES;

		$automationData = [
			'name' => 'Conditional tag then email',
			'author' => \get_current_user_id() ?: 1,
			'status' => 'ENABLED',
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		];

		if (!$wpdb->insert($tableAutomations, $automationData)) {
			return null;
		}

		$automationId = (int)$wpdb->insert_id;

		$triggerId = \wp_generate_uuid4();
		$conditionId = \wp_generate_uuid4();
		$actionYesId = \wp_generate_uuid4();
		$actionNoId = \wp_generate_uuid4();

		// Trigger sur login utilisateur
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $triggerId,
			'type' => 'TRIGGER',
			'key' => 'user_login',
			'settings' => \wp_json_encode(['conditions' => []]),
			'next_step_id' => $conditionId,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		// Étape condition: si user_meta user_tags contient "vip"
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $conditionId,
			'type' => 'CONDITION',
			'key' => 'condition',
			'settings' => \wp_json_encode([
				'condition' => [
					['type' => 'user_meta_contains', 'meta_key' => 'user_tags', 'value' => 'vip'],
				],
			]),
			'next_step_id' => $actionYesId,
			'alternative_step_id' => $actionNoId,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		// Récupère l'ID numérique de l'étape condition pour créer la branche
		$conditionDbId = (int)$wpdb->insert_id;

		// Branche OUI -> condition explicite vers l'étape actionYesId
		$wpdb->insert($tableBranches, [
			'step_id' => $conditionDbId,
			'condition' => \wp_json_encode([
				['type' => 'user_meta_contains', 'meta_key' => 'user_tags', 'value' => 'vip'],
			]),
			'next_step_id' => $actionYesId,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		// Branche OUI -> envoyer email
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $actionYesId,
			'type' => 'ACTION',
			'key' => 'send_email',
			'settings' => \wp_json_encode([
				'subject' => 'Bonjour VIP {{user_name}}',
				'message' => 'Bienvenue cher VIP, {{user_name}}!',
			]),
			'next_step_id' => null,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		// Branche NON -> ajouter tag "newcomer"
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $actionNoId,
			'type' => 'ACTION',
			'key' => 'add_tag',
			'settings' => \wp_json_encode(['tag' => 'newcomer']),
			'next_step_id' => null,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		return $automationId;
	}

	private function createDelayThenEmailAutomation(): ?int
	{
		global $wpdb;
		$tableAutomations = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS;
		$tableSteps = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;

		$automationData = [
			'name' => 'Delay 1 minute then email after profile update',
			'author' => \get_current_user_id() ?: 1,
			'status' => 'ENABLED',
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		];

		if (!$wpdb->insert($tableAutomations, $automationData)) {
			return null;
		}

		$automationId = (int)$wpdb->insert_id;

		$triggerId = \wp_generate_uuid4();
		$delayId = \wp_generate_uuid4();
		$actionId = \wp_generate_uuid4();

		// Trigger sur mise à jour de profil
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $triggerId,
			'type' => 'TRIGGER',
			'key' => 'profile_updated',
			'settings' => \wp_json_encode(['conditions' => []]),
			'next_step_id' => $delayId,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		// Delay 1 minute
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $delayId,
			'type' => 'DELAY',
			'key' => 'delay',
			'settings' => \wp_json_encode(['delay' => ['value' => 1, 'unit' => 'minutes']]),
			'next_step_id' => $actionId,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		// Envoi email
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $actionId,
			'type' => 'ACTION',
			'key' => 'send_email',
			'settings' => \wp_json_encode([
				'subject' => 'Mise à jour prise en compte',
				'message' => 'Bonjour {{user_name}}, nous avons bien pris en compte votre mise à jour.',
			]),
			'next_step_id' => null,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		return $automationId;
	}

	private function createContactOptinWelcomeAutomation(): ?int
	{
		global $wpdb;
		$tableAutomations = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS;
		$tableSteps = $wpdb->prefix . Tables::MAILERPRESS_AUTOMATIONS_STEPS;

		$automationData = [
			'name' => __('Welcome email on contact opt-in', 'mailerpress'),
			'author' => \get_current_user_id() ?: 1,
			'status' => 'ENABLED',
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		];

		if (!$wpdb->insert($tableAutomations, $automationData)) {
			return null;
		}

		$automationId = (int)$wpdb->insert_id;

		$triggerId = \wp_generate_uuid4();
		$actionId = \wp_generate_uuid4();

		// Trigger sur opt-in de contact
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $triggerId,
			'type' => 'TRIGGER',
			'key' => 'contact_optin',
			'settings' => \wp_json_encode(['conditions' => []]),
			'next_step_id' => $actionId,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		// Envoi email
		$wpdb->insert($tableSteps, [
			'automation_id' => $automationId,
			'step_id' => $actionId,
			'type' => 'ACTION',
			'key' => 'send_email',
			'settings' => \wp_json_encode([
				'subject' => 'Bienvenue {{user_name}}',
				'message' => 'Bonjour {{user_name}}, votre ID est {{user_id}}. Email: {{user_email}}',
			]),
			'next_step_id' => null,
			'alternative_step_id' => null,
			'created_at' => \current_time('mysql'),
			'updated_at' => \current_time('mysql'),
		]);

		return $automationId;
	}
}


