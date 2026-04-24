<?php

declare(strict_types=1);

namespace MailerPress\Core\Esp\Brevo;

\defined('ABSPATH') || exit;

use MailerPress\Core\Esp\EspBase;
use MailerPress\Core\Interfaces\EmailServiceInterface;

class BrevoEsp extends EspBase implements EmailServiceInterface
{
    public $httpClient = HttpClient::class;

    public function send(): void
    {
        // TODO: Implement send() method.
    }

    /**
     * @return array
     *
     * @example [
     *      ['id' => 1, 'name': 'ListName1'],
     *      ['id' => 2, 'name': 'ListName2'],
     *      ['id' => 3, 'name': 'ListName3'],
     * ]
     */
    public function getLists()
    {
        delete_transient('mailerpress_list');
        $listTransient = get_transient('mailerpress_list');

        if ($listTransient) {
            return $listTransient;
        }

        $lists = $this->httpClient->getClient()->get('/contacts/lists?limit=50');

        $data = array_reduce((array) $lists->lists, static function ($acc, $item) {
            $acc[] = [
                'id' => $item->id,
                'name' => $item->name,
            ];

            return $acc;
        }, []);

        set_transient('mailerpress_list', $data, 60 * 60 * 12);

        return $data;
    }

    public function getContacts(): void
    {
        // TODO: Implement getContacts() method.
    }

    public function config(): array
    {
        return [
            'name' => 'Brevo',
            'link' => 'https://www.brevo.com/fr/pricing/',
            'image' => MAILERPRESS_ASSETS_DIR.'/img/brevo.svg',
            'createAccountLink' => 'https://onboarding.brevo.com/account/register',
            'linkApiKey' => 'https://app.brevo.com/settings/keys/api',
            'description' => __('Brevo is an all-in-one marketing platform offering email campaigns, automation, SMS, CRM, and API tools for effective communication.', 'mailerpress'),
            'recommended' => true,
        ];
    }

    public function getSendersList($formatted = false): array
    {
        $result = (array) $this->httpClient->getClient()->get('/senders');

        if (!isset($result['senders'])) {
            return [];
        }

        if (true === $formatted) {
            return apply_filters(
                'mailerpress_format_senders_list',
                method_exists($this, 'formatSendersList') ? $this->formatSendersList($result) : []
            );
        }

        return $result;
    }

    private function formatSendersList($data): array
    {
        if (isset($data['senders'])) {
            return array_reduce($data['senders'], static function ($acc, $item) {
                $acc[] = $item->email;

                return $acc;
            }, []);
        }

        return [];
    }
}
