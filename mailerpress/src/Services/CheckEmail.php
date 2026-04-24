<?php

namespace MailerPress\Services;

class CheckEmail
{
    public static function validateEmailWithGetMxrr(string $email): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'reason' => 'Invalid email format'];
        }

        $domain = substr(strrchr($email, "@"), 1);
        $hosts = [];
        $weights = [];

        if (!getmxrr($domain, $hosts, $weights) || empty($hosts)) {
            return ['valid' => false, 'reason' => "No MX record found for domain $domain"];
        }

        return ['valid' => true, 'reason' => 'Email looks valid'];
    }

}