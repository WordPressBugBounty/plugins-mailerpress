<?php

declare(strict_types=1);

namespace MailerPress\Core\EmailManager;

\defined('ABSPATH') || exit;

class EmailServiceManager
{
    /**
     * Former service keys and their replacement.
     */
    private const LEGACY_SERVICE_KEYS = ['php' => 'wordpress'];

    /**
     * Service that sends emails when the primary service is no longer registered.
     */
    public const FALLBACK_SERVICE_KEY = 'wordpress';

    protected array $services = [];
    protected ?EmailServiceInterface $activeService = null;

    public static function normalizeServiceKey(string $key): string
    {
        return self::LEGACY_SERVICE_KEYS[$key] ?? $key;
    }

    /**
     * Replace former service keys in the mailerpress_email_services option.
     */
    public static function normalizeConfigurations(array $configurations): array
    {
        foreach (self::LEGACY_SERVICE_KEYS as $legacyKey => $key) {
            if (!\is_array($configurations['services'] ?? null) || !isset($configurations['services'][$legacyKey])) {
                continue;
            }

            $legacy = (array) $configurations['services'][$legacyKey];
            unset($configurations['services'][$legacyKey]);

            if (!isset($configurations['services'][$key])) {
                $configurations['services'][$key] = $legacy;
            } elseif ($legacyKey === ($configurations['default_service'] ?? null)) {
                // The legacy service was primary: keep its sender and the settings saved for the new key (e.g. Pro bounce provider).
                $current = (array) $configurations['services'][$key];
                $current['conf'] = array_merge((array) ($current['conf'] ?? []), (array) ($legacy['conf'] ?? []));
                $configurations['services'][$key] = $current;
            }
        }

        if (\is_string($configurations['default_service'] ?? null)) {
            $configurations['default_service'] = self::normalizeServiceKey($configurations['default_service']);
        }

        if (\is_array($configurations['activated'] ?? null)) {
            $configurations['activated'] = array_values(array_unique(array_map(
                static fn($key) => \is_string($key) ? self::normalizeServiceKey($key) : $key,
                $configurations['activated']
            )));
        }

        return $configurations;
    }

    public function registerService(string $key, EmailServiceInterface $service): void
    {
        $this->services[$key] = $service;
    }

    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Primary service that is configured but no longer registered, e.g. a Pro ESP
     * after MailerPress Pro was deactivated. WordPress then sends in its place.
     */
    public function getUnavailableServiceKey(?array $configurations = null): ?string
    {
        $configurations = $configurations ?? $this->getConfigurations();
        $key = $configurations['default_service'] ?? null;

        if (!\is_string($key) || '' === $key || isset($this->services[$key])
            || !isset($this->services[self::FALLBACK_SERVICE_KEY])) {
            return null;
        }

        // MailerPress Pro registers its services on init (priority 100).
        if (\defined('MAILERPRESS_PRO_VERSION') && (!did_action('init') || doing_action('init'))) {
            return null;
        }

        return $key;
    }

    /**
     * Service that actually sends emails: the primary service, or the fallback.
     */
    public function getEffectiveServiceKey(?array $configurations = null): ?string
    {
        $configurations = $configurations ?? $this->getConfigurations();
        $key = $configurations['default_service'] ?? null;

        if (\is_string($key) && isset($this->services[$key])) {
            return $key;
        }

        return null !== $this->getUnavailableServiceKey($configurations) ? self::FALLBACK_SERVICE_KEY : null;
    }

    /**
     * @throws \Exception
     */
    public function getActiveService(): ?EmailServiceInterface
    {
        // Always reload configuration from DB to ensure fresh sender settings
        $configurations = $this->getConfigurations();

        $configuredServiceKey = $configurations['default_service'] ?? null;
        $defaultServiceKey = $this->getEffectiveServiceKey($configurations);

        if (null === $defaultServiceKey) {
            throw new \Exception(
                esc_html__(
                    'No active service is set up, or the configured service is not registered.',
                    'mailerpress'
                )
            );
        }

        // Charger le service actif
        $service = $this->services[$defaultServiceKey];
        $config = $configurations['services'][$defaultServiceKey] ?? [];

        if ($defaultServiceKey !== $configuredServiceKey) {
            // Keep the sender chosen for the unavailable service.
            $configuredConf = (array) ($configurations['services'][$configuredServiceKey]['conf'] ?? []);
            $config['conf'] = (array) ($config['conf'] ?? []);
            foreach (['default_email', 'default_name'] as $field) {
                if (!empty($configuredConf[$field])) {
                    $config['conf'][$field] = $configuredConf[$field];
                }
            }
        }

        // Pour le service PHP, si la config est vide, passer un tableau vide
        // La méthode connect() du service gérera le cas des plugins tiers
        if (!$service->connect($config)) {
            throw new \Exception(
                esc_html(
                    \sprintf(
                        // translators: %s the service key name
                        esc_html__('Failed to connect to the active service %s.', 'mailerpress'),
                        esc_html($defaultServiceKey)
                    )
                )
            );
        }

        $this->activeService = $service;

        return $this->activeService;
    }
    /**
     * @throws \Exception
     */
    public function getActiveServiceByKey(string $defaultServiceKey): ?EmailServiceInterface
    {
        $defaultServiceKey = self::normalizeServiceKey($defaultServiceKey);

        // Récupérer la configuration en base
        $configurations = $this->getConfigurations();

        if (null === $defaultServiceKey || !isset($this->services[$defaultServiceKey])) {
            throw new \Exception(
                esc_html__(
                    'No active service is set up, or the configured service is not registered.',
                    'mailerpress'
                )
            );
        }

        // Charger le service actif
        $service = $this->services[$defaultServiceKey];
        $config = $configurations['services'][$defaultServiceKey] ?? [];

        // Pour le service PHP, si la config est vide, passer un tableau vide
        // La méthode connect() du service gérera le cas des plugins tiers
        if (!$service->connect($config)) {
            throw new \Exception(
                esc_html(
                    \sprintf(
                        // translators: %s the service key name
                        esc_html__('Failed to connect to the active service %s.', 'mailerpress'),
                        esc_html($defaultServiceKey)
                    )
                )
            );
        }

        $this->activeService = $service;

        return $this->activeService;
    }

    public function setActiveService(string $key): void
    {
        $key = self::normalizeServiceKey($key);
        $configurations = $this->getConfigurations();

        if (!isset($this->services[$key])) {
            throw new \Exception(
                esc_html(
                    \sprintf(
                        // translators: %s the service key name
                        esc_html__('The service %s is not registered.', 'mailerpress'),
                        esc_html($key)
                    )
                )
            );
        }

        // Le service WordPress fonctionne sans configuration (expéditeur par défaut).
        $config = $configurations['services'][$key] ?? [];

        // Pour les autres services, exiger une configuration
        if ('wordpress' !== $key && empty($config)) {
            throw new \Exception(
                esc_html(
                    \sprintf(
                        // translators: %s the service key name
                        esc_html__('No configuration found for the service %s.', 'mailerpress'),
                        esc_html($key)
                    )
                )
            );
        }

        $service = $this->services[$key];
        if (!$service->connect($config)) {
            throw new \Exception(
                esc_html(
                    \sprintf(
                        // translators: %s the service key name
                        esc_html__('Failed to connect to the service %s.', 'mailerpress'),
                        esc_html($key)
                    )
                )
            );
        }

        $this->activeService = $service;

        // Sauvegarder le service actif en BDD
        \MailerPress\Services\SendingFrequencyConfig::switchProvider(
            (string) ($configurations['default_service'] ?? 'wordpress'),
            $key
        );
        $this->saveConfigurations([
            'default_service' => $key,
            'activated' => $configurations['activated'],
            'services' => $configurations['services'],
        ]);
    }

    public function getConfigurations(): array
    {
        $siteName = get_option('blogname', '');
        $defaultConfig = [
            'default_service' => 'wordpress',
            'activated' => ['wordpress'],
            'services' => [
                'wordpress' => [
                    'conf' => [
                        'default_email' => get_option('admin_email', ''),
                        'default_name' => wp_specialchars_decode($siteName, ENT_QUOTES),
                    ],
                ],
            ],
        ];

        $config = get_option('mailerpress_email_services', null);

        // Si l'option n'existe pas, la créer avec la configuration par défaut
        if ($config === null) {
            add_option('mailerpress_email_services', $defaultConfig);
            return $defaultConfig;
        }

        // Handle JSON-encoded string (e.g. from settings import)
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            } else {
                return $defaultConfig;
            }
        }

        if (isset($config['activated']) && is_array($config['activated'])) {
            $config['activated'] = array_values($config['activated']);
        }

        if (!\is_array($config)) {
            return $defaultConfig;
        }

        $config = self::normalizeConfigurations($config);

        // Decode site-derived sender names saved before HTML entities were handled.
        if ($siteName === ($config['services']['wordpress']['conf']['default_name'] ?? null)) {
            $config['services']['wordpress']['conf']['default_name'] = $defaultConfig['services']['wordpress']['conf']['default_name'];
        }

        return $config;
    }

    public function getServiceByKey(string $key): ?EmailServiceInterface
    {
        $key = self::normalizeServiceKey($key);

        if (!isset($this->services[$key])) {
            throw new \Exception(
                esc_html(
                    \sprintf(
                        // translators: %s the service key name
                        esc_html__('The service %s is not registered.', 'mailerpress'),
                        esc_html($key)
                    )
                )
            );
        }

        return $this->services[$key];
    }

    public function sendEmail(array $emailData): bool
    {
        if (!$this->activeService) {
            throw new \Exception(
                esc_html__(
                    'No active provider is configured.',
                    'mailerpress'
                )
            );
        }

        return $this->activeService->sendEmail($emailData);
    }

    public function saveServiceConfiguration(string $key, array $config, ?bool $activated = null): void
    {
        $key = self::normalizeServiceKey($key);
        $configurations = $this->getConfigurations();

        if (null !== $activated) {
            if (true === $activated) {
                $configurations['activated'] = null === $configurations['activated'] ? [$key] : array_unique(array_merge(
                    $configurations['activated'],
                    [$key]
                ));
            } else {
                $configurations['activated'] = array_values(array_diff($configurations['activated'], [$key]));
            }
        }

        if (null === $activated) {
            $configurations['services'][$key] = $config;
        }

        // Sauvegarder en BDD
        $this->saveConfigurations($configurations);
    }

    public function removeService(string $key): void
    {
        $key = self::normalizeServiceKey($key);

        // Retrieve the current configurations
        $configurations = $this->getConfigurations();

        // Check if the service exists
        if (!isset($configurations['services'][$key])) {
            throw new \Exception(
                esc_html(
                    \sprintf(
                        // translators: %s the service key name
                        esc_html__('The service %s does not exist.', 'mailerpress'),
                        esc_html($key)
                    )
                )
            );
        }

        // Remove the service from the configurations
        unset($configurations['services'][$key]);

        // If the removed service was the default, reset the default service
        if ($configurations['default_service'] === $key) {
            $configurations['default_service'] = null;
        }

        // Save the updated configurations
        $this->saveConfigurations($configurations);
    }

    protected function saveConfigurations(array $configurations): void
    {
        update_option('mailerpress_email_services', $configurations);
    }
}
