<?php

namespace MailerPress\Services;

/** Shared model compatibility for editor and workflow requests. */
class DeepSeekConfig
{
    public static function normalize(array $config): array
    {
        $model = $config['model'] ?? 'deepseek-flash';
        if (in_array($model, ['deepseek-chat', 'deepseek-reasoner'], true)) {
            $config['thinking'] = $model === 'deepseek-reasoner' ? 'enabled' : 'disabled';
            $model = 'deepseek-flash';
        } elseif (in_array($model, ['deepseek-v4-flash', 'deepseek-v4-flash-vision-exp', ''], true)) {
            $model = 'deepseek-flash';
        }
        $config['model'] = $model;
        $config['thinking'] = ($config['thinking'] ?? 'disabled') === 'enabled' ? 'enabled' : 'disabled';
        return $config;
    }

    public static function normalizeSettings(array $settings): array
    {
        if (($settings['text_ai']['provider'] ?? '') === 'deepseek') {
            $settings['text_ai'] = self::normalize($settings['text_ai']);
        }
        return $settings;
    }

    public static function prepareRequest(array $body, array $config): array
    {
        $config = self::normalize(array_merge($config, ['model' => $body['model'] ?? 'deepseek-flash']));
        $body['model'] = $config['model'];
        $body['thinking'] = ['type' => $config['thinking']];
        if ($config['thinking'] === 'enabled') {
            unset($body['temperature'], $body['top_p'], $body['presence_penalty'], $body['frequency_penalty']);
        }
        return $body;
    }
}
