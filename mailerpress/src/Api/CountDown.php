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
        $lang = sanitize_text_field($request['lang'] ?? '');

        // Custom font sizes
        $fontSizeNumParam = $request['font_size_number'] ?? 0;
        $fontSizeLblParam = $request['font_size_label'] ?? 0;

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
            $fontSizeLblParam
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
        // Cancel scheduled action if countdown finished
        if ($secondsLeft <= 0) {
            // Rebuild final expired GIF
            $this->buildGif($campaignId, $imageName, $config, $filePath, $hashPath);
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

        // Determine background pixel
        $bgPixel = (empty($bgColor) || $bgColor === '#') ? new ImagickPixel('transparent') : new ImagickPixel($bgColor);

        // Labels
        $labels = [
            __('Days', 'mailerpress'),
            __('Hours', 'mailerpress'),
            __('Minutes', 'mailerpress'),
            __('Seconds', 'mailerpress'),
        ];
        $translations = [
            'fr' => ['Jours', 'Heures', 'Minutes', 'Secondes'],
            'es' => ['Días', 'Horas', 'Minutos', 'Segundos'],
            'de' => ['Tage', 'Stunden', 'Minuten', 'Sekunden'],
            'it' => ['Giorni', 'Ore', 'Minuti', 'Secondi'],
        ];
        if ($lang && isset($translations[$lang])) {
            $labels = $translations[$lang];
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

        // Layout
        $blockWidth = intval($width / 4);
        $blockHeight = intval($height * 0.6);

        // Font sizes
        $fontSizeNum = $fontSizeNumParam > 0 ? intval($fontSizeNumParam) : intval($height * 0.3);
        $fontSizeLbl = $fontSizeLblParam > 0 ? intval($fontSizeLblParam) : intval($height * 0.15);

        if ($secondsLeft === 0) {
            // Expired frame
            $im = new Imagick();
            $im->newImage($width, $height, $bgPixel);
            $im->setImageFormat('gif');

            $draw = new ImagickDraw();
            $draw->setTextAlignment(Imagick::ALIGN_CENTER);
            $draw->setFillColor(new ImagickPixel($fontColor));
            $draw->setFontSize(intval($height * 0.25));
            $im->annotateImage($draw, $width / 2, $height / 2 + ($height * 0.08), 0, $passedLabel);

            $animation->addImage($im);
        } else {
            $framesCount = ($loopSec === 0) ? 1 : min($loopSec, $secondsLeft);

            for ($i = 0; $i < $framesCount; $i++) {
                $remaining = $secondsLeft - $i;

                $days = intdiv($remaining, 86400);
                $hours = intdiv($remaining % 86400, 3600);
                $minutes = intdiv($remaining % 3600, 60);
                $seconds = $remaining % 60;

                $values = [
                    sprintf('%02d', $days),
                    sprintf('%02d', $hours),
                    sprintf('%02d', $minutes),
                    sprintf('%02d', $seconds),
                ];

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

                    // Box
                    $box = new ImagickDraw();
                    $box->setFillColor(new ImagickPixel($boxColor));
                    $box->roundRectangle(
                        $xCenter - ($blockWidth * 0.4),
                        $yTop,
                        $xCenter + ($blockWidth * 0.4),
                        $yTop + $blockHeight,
                        10, 10
                    );
                    $im->drawImage($box);

                    // Number
                    $draw->setFontSize($fontSizeNum);
                    $draw->setFillColor(new ImagickPixel($numberColor));
                    $im->annotateImage($draw, $xCenter, $yTop + ($blockHeight / 2) + ($fontSizeNum / 3), 0, $val);

                    // Label
                    $draw->setFontSize($fontSizeLbl);
                    $draw->setFillColor(new ImagickPixel($fontColor));
                    $im->annotateImage($draw, $xCenter, $height - 10, 0, $labels[$idx]);
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

    // Helper to get WP timezone
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
