<?php

declare(strict_types=1);

namespace MailerPress\Core;

\defined('ABSPATH') || exit;

final class HtmlParser
{
    private string $htmlContent = '';
    private array $variables = [];
    private ?string $anonymousKey = null;

    /**
     * Initialize parser with HTML and variables.
     */
    public function init(string $htmlContent, array $variables): static
    {
        $this->htmlContent = $htmlContent;
        $this->variables = $variables;
        
        // Use anonymous key from variables if provided, or generate one if this is an anonymous email (contact_id = 0)
        // This key will be used consistently across all tracking tokens for this email
        if (isset($variables['ANONYMOUS_KEY']) && !empty($variables['ANONYMOUS_KEY'])) {
            $this->anonymousKey = $variables['ANONYMOUS_KEY'];
        } elseif (isset($variables['CONTACT_ID']) && (int) $variables['CONTACT_ID'] === 0) {
            $this->anonymousKey = bin2hex(random_bytes(16)); // 32 character hex string
        }

        // 🟢 Inject open-tracking pixel
        // IMPORTANT: This must be done BEFORE replaceVariables() to ensure tracking is always injected
        // even if plugins modify the HTML later
        // Check if TRACK_OPEN is set and not empty (use isset() to allow empty strings, but check they're not empty)
        if (isset($variables['TRACK_OPEN']) && !empty($variables['TRACK_OPEN']) && is_string($variables['TRACK_OPEN']) && trim($variables['TRACK_OPEN']) !== '') {
            $trackingUrl = htmlspecialchars($variables['TRACK_OPEN'], ENT_QUOTES);

            // Check if tracking URL is already present (to avoid duplicates)
            // Use a more flexible check that looks for the tracking URL in the HTML
            $trackingAlreadyPresent = (strpos($this->htmlContent, $trackingUrl) !== false);

            if (!$trackingAlreadyPresent) {
                $trackingTable = sprintf(
                    '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="1" height="1" style="display:none;"><tr><td><img src="%s" alt="" width="1" height="1" style="display:block;"/></td></tr></table>',
                    $trackingUrl
                );

                // Strategy 1: Try to inject after footer-email div
                $pattern = '#(<div[^>]*class=["\'][^"\']*footer-email[^"\']*["\'][^>]*>.*?</div>)#is';
                $footerFound = false;
                $this->htmlContent = preg_replace_callback($pattern, function ($matches) use ($trackingTable, &$footerFound) {
                    $footerFound = true;
                    return $matches[1] . $trackingTable;
                }, $this->htmlContent);

                // Strategy 2: If footer not found, inject before </body> tag
                if (!$footerFound) {
                    if (preg_match('#</body>#i', $this->htmlContent)) {
                        $this->htmlContent = preg_replace('#(</body>)#i', $trackingTable . '$1', $this->htmlContent);
                    } else {
                        // Strategy 3: If no </body> tag, inject before </html> tag
                        if (preg_match('#</html>#i', $this->htmlContent)) {
                            $this->htmlContent = preg_replace('#(</html>)#i', $trackingTable . '$1', $this->htmlContent);
                        } else {
                            // Strategy 4: Last resort - append at the very end
                            $this->htmlContent .= $trackingTable;
                        }
                    }
                }

                // Verify tracking was injected (for debugging)
                if (strpos($this->htmlContent, $trackingUrl) === false) {
                    // If still not found, force inject at the end as last resort
                    $this->htmlContent .= $trackingTable;
                }
            }
        }

        // NOTE: Click tracking is now done AFTER merge tags are replaced in replaceVariables()
        // This ensures dynamic URLs (like {{first_product_review_link}}) are properly replaced before tracking

        return $this;
    }

    /**
     * Supprime les spans d'emoji et garde uniquement l'emoji.
     *
     * @param string $html Le contenu HTML
     * @return string Le contenu HTML avec les spans d'emoji supprimés
     */
    private function removeEmojiSpans(string $html): string
    {
        // Pattern pour détecter les spans avec data-emoji-id et garder uniquement le contenu
        $pattern = '#<span[^>]*data-emoji-id=["\'][^"\']*["\'][^>]*>(.*?)</span>#i';

        return preg_replace_callback($pattern, function ($matches) {
            // Retourner uniquement le contenu du span (l'emoji)
            return $matches[1] ?? '';
        }, $html);
    }

    /**
     * Remplace les variables dynamiques dans le contenu HTML.
     *
     * @param string $clickTracking Click tracking mode: 'yes', 'no', or 'anonymously'
     * @return string le contenu HTML avec les variables remplacées par leurs valeurs
     */
    public function replaceVariables(string $clickTracking = 'yes'): string
    {
        // 0️⃣ Supprimer les spans d'emoji et garder uniquement l'emoji
        $replacedContent = $this->removeEmojiSpans($this->htmlContent);

        // 1️⃣ Remove merge-tag spans but keep placeholder inside
        // Match spans with merge-tag-span class (even if there are other classes) or data-merge-tag-id attribute
        // This pattern matches spans that have either:
        // - class attribute containing "merge-tag-span" (with word boundaries)
        // - data-merge-tag-id attribute
        // Use a more flexible pattern that handles multiple attributes and classes
        $replacedContent = preg_replace_callback(
            '#<span[^>]*(?:class=["\'][^"\']*\bmerge-tag-span\b[^"\']*["\']|data-merge-tag-id=["\'][^"\']*["\'])[^>]*>(.*?)</span>#is',
            fn($m) => $m[1] ?? '',
            $replacedContent
        );

        // 2️⃣ Replace {{VAR}} and {{VAR default="value"}}
        // Use a more permissive pattern that matches merge tags even in attributes
        $pattern = '/{{\s*([a-zA-Z0-9_]+)(?:\s+default=["\']([^"\']*)["\'])?\s*}}/';
        $content = preg_replace_callback($pattern, function ($matches) {
            $key = $matches[1];
            $default = $matches[2] ?? '';
            $value = $this->variables[$key] ?? ($default ?: '');
            $value = (string) $value; // cast to string

            return $value === '' ? '§EMPTY_VAR§' : $value;
        }, $replacedContent);

        // 3️⃣ Replace %VAR%
        foreach ($this->variables as $key => $value) {
            $pattern = sprintf('/%%%s%%/', preg_quote($key, '/'));
            $content = preg_replace($pattern, (string) ($value === '' ? '§EMPTY_VAR§' : $value), $content);
        }

        // 4️⃣ Clean up empty markers
        $content = preg_replace('/(&nbsp;|\s)+§EMPTY_VAR§/', '§EMPTY_VAR§', $content);
        $content = str_replace('§EMPTY_VAR§', '', $content);
        $content = preg_replace('/\s{2,}/', ' ', $content);
        $content = preg_replace('/>\s+</', '><', $content);

        // 5️⃣ Add click tracking to links that had merge tags (now replaced)
        // This ensures links with dynamic URLs (like {{first_product_review_link}}) are tracked
        // Use isset() instead of !empty() to allow CONTACT_ID = 0 for non-subscribers
        // Only apply click tracking if enabled (not 'no')
        if ('no' !== $clickTracking && isset($this->variables['CONTACT_ID']) && isset($this->variables['CAMPAIGN_ID'])) {
            // For anonymous tracking, use contact_id = 0
            $contactId = ('anonymously' === $clickTracking) ? 0 : (int) $this->variables['CONTACT_ID'];
            $campaignId = (int) $this->variables['CAMPAIGN_ID'];
            // Only add tracking if campaign_id is valid (contact_id can be 0 for non-subscribers or anonymous)
            if ($campaignId > 0) {
                $content = $this->appendClickTracking($content, $contactId, $campaignId);
            }
        }

        return trim($content);
    }

    /**
     * Append click tracking query params to all <a> links.
     */
    private function appendClickTracking(string $html, int $contactId, int $campaignId): string
    {
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        // Load as a full HTML document so DOMDocument does not hoist <style> tags out of the
        // document structure. Wrapping in a <div> caused DOMDocument to move the MJML-generated
        // <style> block (containing .mj-column-per-* width rules) to <head>, which was then lost
        // when only the wrapper's childNodes were extracted — resulting in columns stacking vertically.
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');

        $trackedLinks = 0;

        // Get optional jobId and stepId for automation emails
        $jobId = isset($this->variables['JOB_ID']) ? (int) $this->variables['JOB_ID'] : null;
        $stepId = isset($this->variables['STEP_ID']) ? (string) $this->variables['STEP_ID'] : null;

        foreach ($nodes as $link) {
            /** @var \DOMElement $link */
            $href = $link->getAttribute('href');

            // Skip links that contain merge tags (they will be replaced later in replaceVariables())
            // Merge tags are in format {{VAR}} or %VAR%
            if (preg_match('/{{[^}]+}}|%[^%]+%/', $href)) {
                continue;
            }

            // Skip links that already have mp_utm tracking
            if (strpos($href, 'mp_utm=') !== false) {
                continue;
            }

            // Handle both internal and external links for A/B testing tracking
            // For external links, we still want to track clicks
            $host = parse_url($href, PHP_URL_HOST);
            // Note: We track both internal and external links for A/B testing

            // Generate tracking token
            $anonymousKey = ($contactId === 0) ? $this->anonymousKey : null;
            $token = $this->generateContactTrackingToken($contactId, $campaignId, $href, $jobId, $stepId, $anonymousKey);

            // Build tracking URL in format: %WP_DOMAIN/tracking-link/{token}
            // The token is already base64url encoded (safe for URLs), so we can use it directly
            // But we need to ensure it's properly encoded for the URL path
            $trackingUrl = \home_url('/tracking-link/' . rawurlencode($token));

            // If WooCommerce product, we need to add product info to the token data
            // Since the token already contains the URL, we can check it on redirect
            $post_id = $this->mailerpress_product_url_to_id($href);
            if ($post_id && \get_post_type($post_id) === 'product') {
                // Add product info as query params to the tracking URL
                // These will be preserved and checked in Frontend.php
                $trackingUrl = \add_query_arg([
                    'mp_track_product' => 1,
                    'mp_product_id' => $post_id,
                ], $trackingUrl);
            }

            $link->setAttribute('href', $trackingUrl);
            $trackedLinks++;
        }

        // Remove the xml encoding pseudo-attribute injected for UTF-8 handling, then return the
        // full serialised document so the <style> block in <head> is preserved intact.
        return str_replace('<?xml encoding="UTF-8">', '', $dom->saveHTML());
    }

    private function mailerpress_product_url_to_id($url)
    {
        $parsed = \wp_parse_url($url);
        if (empty($parsed['path'])) {
            return 0;
        }

        $path = trim($parsed['path'], '/');
        $segments = explode('/', $path);
        $slug = end($segments);

        if (!$slug) {
            return 0;
        }

        global $wpdb;
        $product_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
             WHERE post_name = %s
             AND post_type = 'product'
             AND post_status = 'publish'
             LIMIT 1",
                $slug
            )
        );

        return $product_id ?: 0;
    }


    /**
     * Generate a secure tracking token for email link clicks.
     *
     * For automation emails: includes jobId and stepId to enable workflow re-evaluation
     * For newsletter emails: only includes contactId, campaignId, and url
     *
     * @param int $contactId Contact ID
     * @param int $campaignId Campaign ID
     * @param string $url Original URL to track
     * @param int|null $jobId Optional job ID for automation emails
     * @param string|null $stepId Optional step ID for automation emails
     * @param string|null $anonymousKey Optional anonymous key for anonymous tracking
     * @return string Base64url-encoded tracking token
     */
    private function generateContactTrackingToken(int $contactId, int $campaignId, string $url, ?int $jobId = null, ?string $stepId = null, ?string $anonymousKey = null): string
    {
        $payloadData = [
            'cid' => $contactId,
            'cmp' => $campaignId,
            'url' => $url,
            'ts'  => time(),
        ];

        // Add anonymous key for anonymous tracking (contact_id = 0)
        if ($contactId === 0 && !empty($anonymousKey)) {
            $payloadData['ank'] = $anonymousKey;
        }

        // Add jobId and stepId for automation emails (workflow)
        if ($jobId !== null && $jobId > 0) {
            $payloadData['job'] = $jobId;
        }
        if ($stepId !== null && $stepId !== '') {
            $payloadData['step'] = $stepId;
        }

        $payload = json_encode($payloadData);

        $secret = \wp_salt('auth');
        $signature = hash_hmac('sha256', $payload, $secret);

        $encoded = base64_encode($payload . '::' . $signature);

        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    /**
     * Generate a secure tracking token for email open tracking (TRACK_OPEN).
     *
     * This token contains all necessary information to track email opens for both
     * automation emails (workflow) and newsletter emails (campaign).
     *
     * For automation emails: includes contactId, campaignId, jobId, stepId
     * For newsletter emails: includes contactId, campaignId, batchId
     *
     * @param int $contactId Contact ID
     * @param int $campaignId Campaign ID
     * @param int|null $batchId Optional batch ID for newsletter emails
     * @param int|null $jobId Optional job ID for automation emails
     * @param string|null $stepId Optional step ID for automation emails
     * @param string|null $anonymousKey Optional anonymous key for anonymous tracking
     * @return string Base64url-encoded tracking token
     */
    public static function generateTrackOpenToken(int $contactId, int $campaignId, ?int $batchId = null, ?int $jobId = null, ?string $stepId = null, ?string $anonymousKey = null): string
    {
        $payloadData = [
            'cid' => $contactId,
            'cmp' => $campaignId,
            'ts'  => time(),
        ];

        // Add anonymous key for anonymous tracking (contact_id = 0)
        if ($contactId === 0 && !empty($anonymousKey)) {
            $payloadData['ank'] = $anonymousKey;
        }

        // Add batchId for newsletter emails
        if ($batchId !== null && $batchId > 0) {
            $payloadData['batch'] = $batchId;
        }

        // Add jobId and stepId for automation emails (workflow)
        if ($jobId !== null && $jobId > 0) {
            $payloadData['job'] = $jobId;
        }
        if ($stepId !== null && $stepId !== '') {
            $payloadData['step'] = $stepId;
        }

        $payload = json_encode($payloadData);

        $secret = \wp_salt('auth');
        $signature = hash_hmac('sha256', $payload, $secret);

        $encoded = base64_encode($payload . '::' . $signature);

        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    /**
     * Decode a tracking token and return the data.
     *
     * @param string $token The base64url-encoded token
     * @return array|null Decoded data or null if invalid
     */
    public static function decodeTrackingToken(string $token): ?array
    {
        $remainder = strlen($token) % 4;
        if ($remainder) {
            $token .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'));
        if (!$decoded) {
            return null;
        }

        [$payloadJson, $signature] = explode('::', $decoded, 2) + [null, null];
        if (!$payloadJson || !$signature) {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $payloadJson, wp_salt('auth'));
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return json_decode($payloadJson, true);
    }

    /**
     * Sanitize HTML content for test emails by removing/replacing merge tags.
     *
     * This method removes merge tag wrapper spans and replaces merge tag variables
     * with their default values or empty strings. This prevents ESP parsing errors
     * when test emails are sent without contact context.
     *
     * Handles:
     * - {{variable}} → empty string
     * - {{variable default="value"}} → "value"
     * - %variable% → empty string
     * - Removes merge-tag-span wrappers
     * - Cleans up emoji spans
     *
     * @param string $htmlContent Raw HTML content with merge tags
     * @return string Sanitized HTML content safe for test emails
     */
    public static function sanitizeTestEmail(string $htmlContent): string
    {
        // 1️⃣ Remove emoji spans (keep only emoji content)
        $pattern = '#<span[^>]*data-emoji-id=["\'][^"\']*["\'][^>]*>(.*?)</span>#i';
        $content = preg_replace_callback($pattern, function ($matches) {
            return $matches[1] ?? '';
        }, $htmlContent);

        // 2️⃣ Remove merge-tag wrapper spans but keep placeholder inside
        // Match spans with merge-tag-span class or data-merge-tag-id attribute
        $content = preg_replace_callback(
            '#<span[^>]*(?:class=["\'][^"\']*\bmerge-tag-span\b[^"\']*["\']|data-merge-tag-id=["\'][^"\']*["\'])[^>]*>(.*?)</span>#is',
            fn($m) => $m[1] ?? '',
            $content
        );

        // 3️⃣ Replace {{VAR}} and {{VAR default="value"}} merge tags
        // Use default value if specified, otherwise replace with empty string
        $pattern = '/{{\s*([a-zA-Z0-9_]+)(?:\s+default=["\']([^"\']*)["\'])?\s*}}/';
        $content = preg_replace_callback($pattern, function ($matches) {
            $default = $matches[2] ?? '';
            // Return default value or empty string
            return $default !== '' ? $default : '';
        }, $content);

        // 4️⃣ Replace %VAR% merge tags with empty string
        $content = preg_replace('/(%[a-zA-Z0-9_]+%)/', '', $content);

        // 5️⃣ Clean up whitespace and formatting
        // Remove excessive spaces around empty variables
        $content = preg_replace('/(&nbsp;|\s)+\s*/', ' ', $content);
        // Remove multiple consecutive spaces
        $content = preg_replace('/\s{2,}/', ' ', $content);
        // Remove spaces between tags
        $content = preg_replace('/>\s+</', '><', $content);

        return trim($content);
    }
}
