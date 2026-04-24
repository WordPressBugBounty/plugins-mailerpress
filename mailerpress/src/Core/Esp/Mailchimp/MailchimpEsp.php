<?php

declare(strict_types=1);

namespace MailerPress\Core\Esp\Mailchimp;

\defined('ABSPATH') || exit;

use MailerPress\Core\Esp\EspBase;
use MailerPress\Core\Interfaces\EmailServiceInterface;

class MailchimpEsp extends EspBase implements EmailServiceInterface
{
    protected $httpClient = HttpClient::class;

    public function send(): void {}

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
        $listTransient = get_transient('mailerpress_list');

        if ($listTransient) {
            return $listTransient;
        }

        $lists = $this->httpClient->getClient()->get('/lists');

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

    public function getContacts(): void {}

    public function config(): array
    {
        return [
            'name' => 'Mailchimp',
            'image' => MAILERPRESS_ASSETS_DIR.'/img/mailchimp.svg',
            'link' => 'https://mailchimp.com/pricing',
            'createAccountLink' => 'https://login.mailchimp.com/signup/?locale=fr',
            'linkApiKey' => 'https://login.mailchimp.com/?referrer=%2Faccount%2Fapi%2F',
            'description' => __('Win new customers with the #1 email marketing and automations platform* that recommends ways to get more opens, clicks, and sales.', 'mailerpress'),
        ];
    }

    public function getSendersList($formatted = false): ?array
    {
        $data = (array) $this->httpClient->getClient()->get('/');

        if (true === $formatted) {
            $sender = get_option('mailerpress_senders');

            return apply_filters(
                'mailerpress_format_senders_list',
                method_exists($this, 'formatSendersList') ? $this->formatSendersList($data) : [
                    $sender ? $sender['from_to'] : get_option('admin_email'),
                ]
            );
        }

        return $data;
    }
}
