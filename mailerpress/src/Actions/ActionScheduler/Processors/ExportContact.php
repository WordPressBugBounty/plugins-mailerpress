<?php

declare(strict_types=1);

namespace MailerPress\Actions\ActionScheduler\Processors;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Core\Attributes\Endpoint;
use MailerPress\Core\EmailManager\EmailServiceManager;
use MailerPress\Core\Enums\Tables;
use MailerPress\Core\Kernel;
use MailerPress\Services\DebugFileLogger;
use ZipArchive;
use WP_REST_Request;

class ExportContact
{
    private const DIRECTORY = 'mailerpress_exports';
    private const PURGE_HOOK = 'mailerpress_purge_export';
    private const TTL = DAY_IN_SECONDS;

    /**
     * Root directory of contact exports (uploads/mailerpress_exports), created and protected
     * against direct HTTP access on first use.
     */
    public static function getExportDirectory(): string
    {
        $upload_dir = wp_upload_dir(null, false);
        $directory = trailingslashit($upload_dir['basedir']) . self::DIRECTORY;

        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        DebugFileLogger::protectDirectory($directory);

        return $directory;
    }

    /**
     * Delete an export (archive, leftover CSV directory and its option).
     */
    public static function purge(string $export_id): void
    {
        if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', $export_id)) {
            return;
        }

        $root = self::getExportDirectory();
        $zip = $root . '/' . $export_id . '.zip';
        $dir = $root . '/' . $export_id;

        if (is_file($zip)) {
            @unlink($zip);
        }

        if (is_dir($dir)) {
            foreach ((array) glob($dir . '/*') as $file) {
                if (\is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }

        delete_option("mailerpress_export_{$export_id}");
    }

    #[Action('mailerpress_purge_export', priority: 10, acceptedArgs: 1)]
    public function purgeScheduled($export_id): void
    {
        self::purge((string) $export_id);
    }

    /**
     * Remove every export older than the TTL (safety net for purge jobs that never ran, and
     * for archives produced by previous versions), plus expired export options whose file is
     * already gone.
     */
    public static function purgeStaleExports(): void
    {
        $root = self::getExportDirectory();
        $cutoff = time() - self::TTL;

        foreach ((array) glob($root . '/*.zip') as $zip) {
            if (\is_string($zip) && is_file($zip) && filemtime($zip) < $cutoff) {
                self::purge(basename($zip, '.zip'));
            }
        }

        foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $dir) {
            if (\is_string($dir) && filemtime($dir) < $cutoff) {
                self::purge(basename($dir));
            }
        }

        global $wpdb;
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('mailerpress_export_') . '%'
            )
        );

        foreach ((array) $names as $name) {
            $data = get_option($name);
            $expires = \is_array($data) ? (int) ($data['expires'] ?? 0) : 0;
            if ($expires < time()) {
                self::purge(substr((string) $name, \strlen('mailerpress_export_')));
            }
        }
    }

    /**
     * Upgrade routine for sites that already have an export directory created by a previous
     * version: protect it against direct HTTP access and drop stale archives right away,
     * without waiting for the next export.
     */
    public static function hardenExistingExports(): void
    {
        $upload_dir = wp_upload_dir(null, false);
        if (!empty($upload_dir['error']) || empty($upload_dir['basedir'])) {
            return;
        }

        if (!is_dir(trailingslashit($upload_dir['basedir']) . self::DIRECTORY)) {
            return;
        }

        self::getExportDirectory();
        self::purgeStaleExports();
    }

    /**
     * Neutralise spreadsheet formula injection: a cell starting with = + - @ or a tab/CR would
     * be evaluated by Excel/LibreOffice when the admin opens the export.
     */
    private static function csvSafe($value): string
    {
        $value = (string) $value;

        if ('' !== $value && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
    #[Action('mailerpress_export_contact_batch', priority: 10, acceptedArgs: 4)]
    public function run($export_id, $status = null, $offset = null, $contact_ids = []): void
    {
        global $wpdb;
        $table_contacts = Tables::get(Tables::MAILERPRESS_CONTACT);
        $table_custom   = Tables::get(Tables::MAILERPRESS_CONTACT_CUSTOM_FIELDS);
        $table_custom_definitions = Tables::get(Tables::MAILERPRESS_CUSTOM_FIELD_DEFINITIONS);
        $table_contact_tags = Tables::get(Tables::CONTACT_TAGS);
        $table_tags = Tables::get(Tables::MAILERPRESS_TAGS);
        $table_contact_lists = Tables::get(Tables::MAILERPRESS_CONTACT_LIST);
        $table_lists = Tables::get(Tables::MAILERPRESS_LIST);
        $batch_size     = 200;

        $contacts = [];

        if (!empty($contact_ids) && is_array($contact_ids)) {
            $contact_ids_safe = array_map('intval', $contact_ids);
            $placeholders = implode(',', array_fill(0, count($contact_ids_safe), '%d'));
            $sql = "SELECT contact_id, email, first_name, last_name, subscription_status, opt_in_source, opt_in_details, created_at, updated_at FROM $table_contacts WHERE contact_id IN ($placeholders)";
            $prepared = $wpdb->prepare($sql, $contact_ids_safe);
            $contacts = $wpdb->get_results($prepared, ARRAY_A);
        } elseif (isset($status, $offset)) {
            $contacts = $wpdb->get_results($wpdb->prepare(
                "SELECT contact_id, email, first_name, last_name, subscription_status, opt_in_source, opt_in_details, created_at, updated_at FROM $table_contacts WHERE subscription_status = %s LIMIT %d OFFSET %d",
                $status,
                $batch_size,
                $offset
            ), ARRAY_A);
        }

        if (empty($contacts)) return;

        $contact_ids_batch = array_column($contacts, 'contact_id');
        $in_clause = implode(',', array_map('intval', $contact_ids_batch));

        // Fetch ALL custom field definitions (not just those with values in this batch)
        $custom_field_definitions = $wpdb->get_results(
            "SELECT field_key FROM $table_custom_definitions ORDER BY field_key",
            ARRAY_A
        );

        // Extract all field keys to ensure consistent columns across all batches
        $all_custom_keys = array_column($custom_field_definitions, 'field_key');

        // Fetch custom field values for contacts in this batch
        $custom_fields = $wpdb->get_results(
            "SELECT contact_id, field_key, field_value FROM $table_custom WHERE contact_id IN ($in_clause)",
            ARRAY_A
        );

        $grouped_custom = [];
        foreach ($custom_fields as $cf) {
            $grouped_custom[$cf['contact_id']][$cf['field_key']] = $cf['field_value'] ?? '';
        }

        // Fetch tags for all contacts in batch
        $tags_results = $wpdb->get_results(
            "SELECT ct.contact_id, t.name
            FROM $table_contact_tags ct
            INNER JOIN $table_tags t ON ct.tag_id = t.tag_id
            WHERE ct.contact_id IN ($in_clause)",
            ARRAY_A
        );

        $tags_by_contact = [];
        foreach ($tags_results as $tag) {
            $tags_by_contact[$tag['contact_id']][] = $tag['name'];
        }

        // Fetch lists for all contacts in batch
        $lists_results = $wpdb->get_results(
            "SELECT cl.contact_id, l.name as list_name
            FROM $table_contact_lists cl
            INNER JOIN $table_lists l ON cl.list_id = l.list_id
            WHERE cl.contact_id IN ($in_clause)",
            ARRAY_A
        );

        $lists_by_contact = [];
        foreach ($lists_results as $list) {
            $lists_by_contact[$list['contact_id']][] = $list['list_name'];
        }

        // Prepare CSV (inside the protected export directory)
        if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', (string) $export_id)) {
            return;
        }

        $export_dir = self::getExportDirectory() . '/' . $export_id;
        if (!file_exists($export_dir)) wp_mkdir_p($export_dir);

        $status_label = $status ?? 'export';
        $file_path = $export_dir . "/{$status_label}.csv";
        $is_new_file = !file_exists($file_path);

        $file = fopen($file_path, 'a');

        // Header
        if ($is_new_file && isset($contacts[0])) {
            $base_header = array_keys($contacts[0]);
            $header = array_merge($base_header, ['lists', 'tags'], $all_custom_keys);
            fputcsv($file, $header);
        }

        // Rows
        foreach ($contacts as $contact) {
            $contact_id = $contact['contact_id'];

            // Get lists and tags for this contact (empty array if none)
            $lists_array = $lists_by_contact[$contact_id] ?? [];
            $tags_array = $tags_by_contact[$contact_id] ?? [];

            // Join with pipe separator
            $lists_string = implode('|', $lists_array);
            $tags_string = implode('|', $tags_array);

            // Build custom field values
            $custom_values = [];
            foreach ($all_custom_keys as $key) {
                $custom_values[] = $grouped_custom[$contact_id][$key] ?? '';
            }

            // Merge: base contact data + lists + tags + custom fields
            fputcsv($file, array_map([self::class, 'csvSafe'], array_merge(
                array_values($contact),
                [$lists_string, $tags_string],
                $custom_values
            )));
        }

        fclose($file);
    }

    #[Action('mailerpress_finalize_export_contact_zip', priority: 10, acceptedArgs: 2)]
    public function handleZip($export_id, $email): void
    {
        if (!$export_id) {
            return;
        }

        if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', (string) $export_id)) {
            return;
        }

        $root = self::getExportDirectory();
        $export_dir = "{$root}/{$export_id}/";
        $zip_path = "{$root}/{$export_id}.zip";

        // Housekeeping: drop exports that outlived their link.
        self::purgeStaleExports();

        if (!is_dir($export_dir)) {
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return;
        }

        foreach (glob($export_dir . "*.csv") as $file) {
            $filename = basename($file);
            $zip->addFile($file, $filename);
        }

        $zip->close();

        array_map('unlink', glob($export_dir . "*.csv"));
        rmdir($export_dir);

        $token = bin2hex(random_bytes(32));
        $expires = time() + self::TTL;

        $export_data = [
            'zip_path' => $zip_path,
            'expires'  => $expires,
            'token'    => $token,
        ];

        update_option("mailerpress_export_{$export_id}", $export_data, false);

        // The archive contains personal data: delete it as soon as the link expires.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($expires + MINUTE_IN_SECONDS, self::PURGE_HOOK, [$export_id], 'mailerpress');
        }

        $download_url = rest_url("mailerpress/v1/export/{$export_id}?token={$token}");

        if ($email && is_email($email)) {
            try {
                // Get the active email service configured in MailerPress
                $mailer = Kernel::getContainer()->get(EmailServiceManager::class)->getActiveService();

                $config = $mailer->getConfig();

                // Get sender configuration
                if (empty($config['conf']['default_email']) || empty($config['conf']['default_name'])) {
                    $defaultSettings = get_option('mailerpress_default_settings', []);
                    if (is_string($defaultSettings)) {
                        $defaultSettings = json_decode($defaultSettings, true) ?: [];
                    }

                    if (!empty($defaultSettings['fromAddress']) && !empty($defaultSettings['fromName'])) {
                        $config['conf']['default_email'] = $defaultSettings['fromAddress'];
                        $config['conf']['default_name'] = $defaultSettings['fromName'];
                    } else {
                        $globalSender = get_option('mailerpress_global_email_senders');
                        if (is_string($globalSender)) {
                            $globalSender = json_decode($globalSender, true);
                        }
                        if (is_array($globalSender)) {
                            $config['conf']['default_email'] = $globalSender['fromAddress'] ?? get_option('admin_email');
                            $config['conf']['default_name'] = $globalSender['fromName'] ?? get_option('blogname');
                        }
                    }
                }

                // Get Reply-To settings
                $defaultSettings = get_option('mailerpress_default_settings', []);
                if (is_string($defaultSettings)) {
                    $defaultSettings = json_decode($defaultSettings, true) ?: [];
                }

                $replyToName = !empty($defaultSettings['replyToName'])
                    ? $defaultSettings['replyToName']
                    : ($config['conf']['default_name'] ?? '');
                $replyToAddress = !empty($defaultSettings['replyToAddress'])
                    ? $defaultSettings['replyToAddress']
                    : ($config['conf']['default_email'] ?? '');

                // Prepare HTML body
                $htmlBody = '<html><body>';
                $htmlBody .= '<p>' . esc_html__('Hello,', 'mailerpress') . '</p>';
                $htmlBody .= '<p>' . esc_html__('Your export is complete. You can download it here:', 'mailerpress') . '</p>';
                $htmlBody .= '<p><a href="' . esc_url($download_url) . '">' . esc_html__('Download Export', 'mailerpress') . '</a></p>';
                $htmlBody .= '<p>' . esc_html__('This link will expire in 24 hours.', 'mailerpress') . '</p>';
                $htmlBody .= '</body></html>';

                // Send email using MailerPress ESP
                $emailData = [
                    'to' => $email,
                    'subject' => esc_html__('Your MailerPress Export is Ready', 'mailerpress'),
                    'body' => $htmlBody,
                    'html' => true,
                    'sender_name' => $config['conf']['default_name'],
                    'sender_to' => $config['conf']['default_email'],
                    'reply_to_name' => $replyToName,
                    'reply_to_address' => $replyToAddress,
                    'apiKey' => $config['conf']['api_key'] ?? '',
                ];

                $mailer->sendEmail($emailData);

            } catch (\Exception) {
            }
        }
    }
}
