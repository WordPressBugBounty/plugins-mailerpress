<?php

namespace MailerPress\Api;

use DateTime;
use DateTimeZone;
use Exception;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use MailerPress\Core\Attributes\Endpoint;
use WP_Error;
use WP_REST_Request;

class CountDown
{
    #[Endpoint('countdown', permissionCallback: [Permissions::class, 'canManageCampaign'])]
    public function generate(WP_REST_Request $request): WP_Error|array
    {
        // Sanitize campaignId: only allow alphanumeric, dash, underscore (prevent path traversal)
        $campaignId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $request['campaign_id'] ?? '');
        $imageName = sanitize_file_name($request['name'] ?? 'countdown');

        if (empty($campaignId)) {
            return new WP_Error('missing_campaign', 'campaign_id is required', ['status' => 400]);
        }

        // Collect params with bounds to prevent excessive resource consumption
        $targetDate = sanitize_text_field($request['to'] ?? '2025-08-30T23:59:59');
        $width = min(max(intval($request['width'] ?? 400), 50), 1200);
        $height = min(max(intval($request['height'] ?? 120), 30), 400);
        $bgColor = '#' . preg_replace('/[^0-9a-f]/i', '', $request['bg'] ?? 'ffffff');
        $fontColor = '#' . preg_replace('/[^0-9a-f]/i', '', $request['color'] ?? '000000');
        $boxColor = '#' . preg_replace('/[^0-9a-f]/i', '', $request['box'] ?? '000000');
        $numberColor = '#' . preg_replace('/[^0-9a-f]/i', '', $request['number'] ?? 'ffffff');

        $loopSec = min(max(0, intval($request['loop'] ?? 60)), 120);
        $iterations = min(max(intval($request['iterations'] ?? 1), 0), 10);
        $delay = min(max(intval($request['delay'] ?? 100), 10), 1000);
        $lang = sanitize_text_field($request['lang'] ?? 'en');

        // Custom font sizes
        $fontSizeNumParam = $request['font_size_number'] ?? 0;
        $fontSizeLblParam = $request['font_size_label'] ?? 0;

        // Visible units
        $showLabels = ($request['show_labels'] ?? '1') === '1';
        $showDays = ($request['show_days'] ?? '1') === '1';
        $showHours = ($request['show_hours'] ?? '1') === '1';
        $showMinutes = ($request['show_minutes'] ?? '1') === '1';
        $showSeconds = ($request['show_seconds'] ?? '1') === '1';

        // Prepare hash config
        $hash = md5(json_encode([
            $targetDate,
            $width,
            $height,
            $bgColor,
            $fontColor,
            $boxColor,
            $numberColor,
            $loopSec,
            $iterations,
            $delay,
            $lang,
            $fontSizeNumParam,
            $fontSizeLblParam,
            $showLabels,
            $showDays,
            $showHours,
            $showMinutes,
            $showSeconds,
        ]));

        $config = [
            'targetDate' => $targetDate,
            'width' => $width,
            'height' => $height,
            'bgColor' => $bgColor,
            'fontColor' => $fontColor,
            'boxColor' => $boxColor,
            'numberColor' => $numberColor,
            'loopSec' => $loopSec,
            'iterations' => $iterations,
            'delay' => $delay,
            'lang' => $lang,
            'fontSizeNumParam' => $fontSizeNumParam,
            'fontSizeLblParam' => $fontSizeLblParam,
            'showLabels' => $showLabels,
            'showDays' => $showDays,
            'showHours' => $showHours,
            'showMinutes' => $showMinutes,
            'showSeconds' => $showSeconds,
            'hash' => $hash
        ];

        // Upload dir
        $uploadDir = wp_upload_dir();
        $baseDir = $uploadDir['basedir'] . '/mailerpress/' . $campaignId;
        $baseUrl = $uploadDir['baseurl'] . '/mailerpress/' . $campaignId;

        if (!file_exists($baseDir)) {
            wp_mkdir_p($baseDir);
        }

        $filePath = $baseDir . '/' . $imageName . '.gif';
        $hashPath = $baseDir . '/' . $imageName . '.hash';

        // Check cache
        if (file_exists($filePath) && file_exists($hashPath)) {
            $oldConfig = json_decode(file_get_contents($hashPath), true);
            if ($oldConfig && isset($oldConfig['hash']) && $oldConfig['hash'] === $hash) {
                return [
                    'url' => $baseUrl . '/' . $imageName . '.gif',
                    'cached' => true
                ];
            } else {
                @unlink($filePath);
                @unlink($hashPath);
            }
        }

        // Build gif
        $this->buildGif($campaignId, $imageName, $config, $filePath, $hashPath);

        // Schedule regeneration if needed
        $tz = $this->getWpTimezone();

        try {
            $target = new DateTime($config['targetDate'], $tz);
        } catch (Exception $e) {
            $target = new DateTime('now', $tz); // fallback
        }

        $now = new DateTime('now', $tz);
        $secondsLeft = max(0, $target->getTimestamp() - $now->getTimestamp());

        if ($secondsLeft > 0) {
            // First, clear any existing scheduled actions for this campaign/image
            as_unschedule_all_actions(
                'mailerpress_regenerate_countdown',
                [$campaignId, $imageName],
                'mailerpress'
            );

            // Schedule a new one
            as_schedule_recurring_action(
                time() + 10,   // start in 1 minute
                30,            // repeat every 1 minute
                'mailerpress_regenerate_countdown',
                [$campaignId, $imageName],
                'mailerpress'
            );
        }

        return [
            'url' => $baseUrl . '/' . $imageName . '.gif',
            'cached' => false
        ];
    }

    public function regenerateGif(string $campaignId, string $imageName)
    {
        $uploadDir = wp_upload_dir();
        $baseDir = $uploadDir['basedir'] . '/mailerpress/' . $campaignId;
        $filePath = $baseDir . '/' . $imageName . '.gif';
        $hashPath = $baseDir . '/' . $imageName . '.hash';

        if (!file_exists($hashPath)) {
            return;
        }

        $config = json_decode(file_get_contents($hashPath), true);
        if (!$config) {
            return;
        }

        $tz = $this->getWpTimezone();

        try {
            $target = new DateTime($config['targetDate'], $tz);
        } catch (Exception $e) {
            $target = new DateTime('now', $tz);
        }

        $now = new DateTime('now', $tz);
        $secondsLeft = max(0, $target->getTimestamp() - $now->getTimestamp());
            if ($secondsLeft <= 0) {
            $this->buildGif($campaignId, $imageName, $config, $filePath, $hashPath);
            self::markForCleanup($campaignId, $imageName);
            return;
        }

        // Otherwise rebuild GIF
        $this->buildGif($campaignId, $imageName, $config, $filePath, $hashPath);
    }

    private function buildGif(string $campaignId, string $imageName, array $config, string $filePath, string $hashPath)
    {
        $width = $config['width'];
        $height = $config['height'];
        $bgColor = trim($config['bgColor'] ?? '');
        $fontColor = $config['fontColor'];
        $boxColor = $config['boxColor'];
        $numberColor = $config['numberColor'];
        $loopSec = $config['loopSec'];
        $iterations = $config['iterations'];
        $delay = $config['delay'];
        $lang = $config['lang'];
        $fontSizeNumParam = $config['fontSizeNumParam'];
        $fontSizeLblParam = $config['fontSizeLblParam'];

        $bgPixel = $this->safePixel( $bgColor, 'transparent' );

        // Labels
        $allTranslations = [
            'en' => ['Days', 'Hours', 'Minutes', 'Seconds'],
            'fr' => ['Jours', 'Heures', 'Minutes', 'Secondes'],
            'es' => ['Días', 'Horas', 'Minutos', 'Segundos'],
            'de' => ['Tage', 'Stunden', 'Minuten', 'Sekunden'],
            'it' => ['Giorni', 'Ore', 'Minuti', 'Secondi'],
        ];
        $allLabels = $allTranslations[$lang] ?? $allTranslations['en'];

        // Visible units
        $showLabels  = $config['showLabels'] ?? true;
        $showDays    = $config['showDays'] ?? true;
        $showHours   = $config['showHours'] ?? true;
        $showMinutes = $config['showMinutes'] ?? true;
        $showSeconds = $config['showSeconds'] ?? true;

        $visibleMask = [$showDays, $showHours, $showMinutes, $showSeconds];
        $labels = [];
        foreach ( $allLabels as $i => $label ) {
            if ( $visibleMask[$i] ) {
                $labels[] = $label;
            }
        }

        $unitCount = count($labels);
        if ( 0 === $unitCount ) {
            $unitCount   = 4;
            $labels      = $allLabels;
            $visibleMask = [true, true, true, true];
        }

        $passedLabel = __('This offer has expired', 'mailerpress');

        $tz = $this->getWpTimezone();

        try {
            $target = new DateTime($config['targetDate'], $tz);
        } catch (\Exception $e) {
            $target = new DateTime('now', $tz);
        }

        $now = new DateTime('now', $tz);
        $secondsLeft = max(0, $target->getTimestamp() - $now->getTimestamp());

        $animation = new Imagick();

        // Layout — divide width equally among visible units
        $blockWidth  = intval($width / $unitCount);
        $blockHeight = intval($height * 0.6);

        // Font sizes
        $fontSizeNum = $fontSizeNumParam > 0 ? intval($fontSizeNumParam) : intval($height * 0.3);
        $fontSizeLbl = $fontSizeLblParam > 0 ? intval($fontSizeLblParam) : intval($height * 0.15);

        if ($secondsLeft === 0) {
            $im = new Imagick();
            $im->newImage($width, $height, $bgPixel);
            $im->setImageFormat('gif');

            $draw = new ImagickDraw();
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->setFillColor($this->safePixel( $fontColor, '#000000' ));
            $draw->setFontSize(intval($height * 0.25));
            $im->annotateImage($draw, $width / 2, $height / 2 + ($height * 0.08), 0, $passedLabel);

            $animation->addImage($im);
        } else {
            $framesCount = ($loopSec === 0) ? 1 : min($loopSec, $secondsLeft);

            for ($i = 0; $i < $framesCount; $i++) {
                $remaining = $secondsLeft - $i;

                $allValues = [
                    sprintf('%02d', intdiv($remaining, 86400)),
                    sprintf('%02d', intdiv($remaining % 86400, 3600)),
                    sprintf('%02d', intdiv($remaining % 3600, 60)),
                    sprintf('%02d', $remaining % 60),
                ];

                $values = [];
                foreach ( $allValues as $idx => $val ) {
                    if ( $visibleMask[$idx] ) {
                        $values[] = $val;
                    }
                }

                $im = new Imagick();
                $im->newImage($width, $height, $bgPixel);
                $im->setImageFormat('gif');

                $draw = new ImagickDraw();
                $draw->setTextAlignment(Imagick::ALIGN_CENTER);
                $draw->setStrokeAntialias(true);
                $draw->setTextAntialias(true);

                foreach ($values as $idx => $val) {
                    $xCenter = ($blockWidth * $idx) + ($blockWidth / 2);
                    $yTop = $height * 0.15;

                    $box = new ImagickDraw();
                    $box->setFillColor($this->safePixel( $boxColor, '#000000' ));
                    $box->roundRectangle(
                        $xCenter - ($blockWidth * 0.4),
                        $yTop,
                        $xCenter + ($blockWidth * 0.4),
                        $yTop + $blockHeight,
                        10, 10
                    );
                    $im->drawImage($box);

                    $draw->setFontSize($fontSizeNum);
                    $draw->setFillColor($this->safePixel( $numberColor, '#ffffff' ));
                    $im->annotateImage($draw, $xCenter, $yTop + ($blockHeight / 2) + ($fontSizeNum / 3), 0, $val);

                    if ( $showLabels ) {
                        $draw->setFontSize($fontSizeLbl);
                        $draw->setFillColor($this->safePixel( $fontColor, '#000000' ));
                        $im->annotateImage($draw, $xCenter, $height - 10, 0, $labels[$idx]);
                    }
                }

                $im->setImageDelay($delay);
                $animation->addImage($im);
            }
        }

        // Finalize
        $animation->setImageIterations($iterations);
        $animation = $animation->coalesceImages();
        $animation = $animation->optimizeImageLayers();

        // Save
        file_put_contents($filePath, $animation->getImagesBlob());
        file_put_contents($hashPath, json_encode($config));
    }

    private function safePixel( string $color, string $fallback = 'transparent' ): ImagickPixel {
        $hex = ltrim( $color, '#' );
        if ( '' !== $hex && preg_match( '/^[0-9a-f]{3}([0-9a-f]{3})?$/i', $hex ) ) {
            return new ImagickPixel( '#' . $hex );
        }
        return new ImagickPixel( $fallback );
    }

    public static function markForCleanup(string $campaignId, string $imageName): void
    {
        $pending = get_option('mailerpress_countdown_cleanup', []);
        $key = $campaignId . '::' . $imageName;
        $pending[$key] = ['campaignId' => $campaignId, 'imageName' => $imageName];
        update_option('mailerpress_countdown_cleanup', $pending, false);
    }

    public static function cleanupExpired(): void
    {
        if (!\function_exists('as_unschedule_all_actions')) {
            return;
        }

        $pending = get_option('mailerpress_countdown_cleanup', []);
        if (empty($pending)) {
            return;
        }

        foreach ($pending as $entry) {
            as_unschedule_all_actions(
                'mailerpress_regenerate_countdown',
                [$entry['campaignId'], $entry['imageName']],
                'mailerpress'
            );
        }

        delete_option('mailerpress_countdown_cleanup');
    }

    public static function unscheduleForCampaign(string $campaignId): void
    {
        if (!\function_exists('as_get_scheduled_actions')) {
            return;
        }

        $actions = as_get_scheduled_actions([
            'hook'   => 'mailerpress_regenerate_countdown',
            'group'  => 'mailerpress',
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ], 'ARRAY_A');

        foreach ($actions as $action) {
            $args = $action['args'] ?? [];
            if (!empty($args[0]) && (string) $args[0] === $campaignId) {
                as_unschedule_all_actions(
                    'mailerpress_regenerate_countdown',
                    $args,
                    'mailerpress'
                );
            }
        }
    }

    private function getWpTimezone(): DateTimeZone
    {
        $tzString = get_option('timezone_string');
        if ($tzString) {
            return new DateTimeZone($tzString);
        }
        $offset = (float)get_option('gmt_offset');
        $hours = (int)$offset;
        $minutes = ($offset - $hours) * 60;
        $offsetString = sprintf('%+03d:%02d', $hours, $minutes);
        return new DateTimeZone($offsetString);
    }

}
