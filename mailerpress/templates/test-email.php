<?php
/**
 * Branded HTML body of the sending method test email.
 *
 * @var string $logo_url     MailerPress logo URL.
 * @var string $check_url    Success icon URL.
 * @var string $service_name Name of the tested sending method.
 * @var string $sender       Sender name and address.
 * @var string $recipient    Recipient address.
 * @var string $site_name    Site name.
 * @var string $site_url     Site URL.
 * @var string $sent_at      Formatted sending date.
 * @var array  $links        MailerPress admin URLs.
 */

defined('ABSPATH') || exit;

$mp_heading_font = "'Dazzed', 'Instrument Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif";
$mp_body_font    = "'Instrument Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif";
$mp_details      = [
    __('Sending method', 'mailerpress') => $service_name,
    __('Sender', 'mailerpress')         => $sender,
    __('Recipient', 'mailerpress')      => $recipient,
    __('Sent', 'mailerpress')           => $sent_at,
];
$mp_steps        = [
    [
        'title' => __('Import your contacts', 'mailerpress'),
        'text'  => __('Bring in your audience from a CSV file or sync it from your site.', 'mailerpress'),
        'label' => __('Add contacts', 'mailerpress'),
        'url'   => $links['contacts'],
    ],
    [
        'title' => __('Create your first campaign', 'mailerpress'),
        'text'  => __('Start from a template and design your email with the drag-and-drop editor.', 'mailerpress'),
        'label' => __('Create a campaign', 'mailerpress'),
        'url'   => $links['campaign'],
    ],
    [
        'title' => __('Automate your emails', 'mailerpress'),
        'text'  => __('Welcome new subscribers and follow up automatically with workflows.', 'mailerpress'),
        'label' => __('Build a workflow', 'mailerpress'),
        'url'   => $links['workflow'],
    ],
];
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title><?php esc_html_e('Your sending method works', 'mailerpress'); ?></title>
    <style>
        @media only screen and (max-width: 620px) {
            .mp-container { width: 100% !important; }
            .mp-pad { padding-left: 24px !important; padding-right: 24px !important; }
            .mp-title { font-size: 28px !important; line-height: 32px !important; }
            .mp-detail-label, .mp-detail-value { display: block !important; width: 100% !important; text-align: left !important; }
            .mp-detail-label { padding-bottom: 0 !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">
    <?php
    printf(
        /* translators: %s: sending method name. */
        esc_html__('MailerPress delivered this test through %s. You are ready to send.', 'mailerpress'),
        esc_html($service_name)
    );
    ?>
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;">
    <tr>
        <td align="center" style="padding:40px 16px 48px 16px;">
            <table role="presentation" class="mp-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
                <tr>
                    <td align="center" style="padding:0 0 32px 0;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding-right:10px;vertical-align:middle;">
                                    <img src="<?php echo esc_url($logo_url); ?>" width="36" height="36" alt="" style="display:block;width:36px;height:36px;border:0;">
                                </td>
                                <td style="vertical-align:middle;font-family:<?php echo esc_attr($mp_heading_font); ?>;font-size:26px;line-height:26px;letter-spacing:-0.6px;color:#13141c;">
                                    <strong style="font-weight:700;">Mailer</strong><span style="font-weight:300;">Press</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#ffffff;border:1px solid #e3e4e8;border-radius:4px;overflow:hidden;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td class="mp-pad" style="background-color:#0129ff;padding:48px;">
                                    <img src="<?php echo esc_url($check_url); ?>" width="48" height="48" alt="" style="display:block;width:48px;height:48px;border:0;">
                                    <p style="margin:24px 0 8px 0;font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:14px;line-height:20px;font-weight:600;color:#c4f0ff;">
                                        <?php esc_html_e('Sending test passed', 'mailerpress'); ?>
                                    </p>
                                    <h1 class="mp-title" style="margin:0 0 16px 0;font-family:<?php echo esc_attr($mp_heading_font); ?>;font-size:36px;line-height:40px;font-weight:700;letter-spacing:-1px;color:#ffffff;">
                                        <?php esc_html_e('Your emails are ready for takeoff', 'mailerpress'); ?>
                                    </h1>
                                    <p style="margin:0;font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:16px;line-height:24px;color:#ffffff;">
                                        <?php
                                        printf(
                                            /* translators: %s: sending method name. */
                                            esc_html__('Good news: MailerPress delivered this email through %s. Your sending method is set up correctly, so your campaigns and automations can reach their inboxes.', 'mailerpress'),
                                            '<strong style="font-weight:700;">' . esc_html($service_name) . '</strong>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td class="mp-pad" style="padding:40px 48px 8px 48px;">
                                    <p style="margin:0 0 12px 0;font-family:<?php echo esc_attr($mp_heading_font); ?>;font-size:18px;line-height:24px;font-weight:700;letter-spacing:-0.3px;color:#13141c;">
                                        <?php esc_html_e('Test details', 'mailerpress'); ?>
                                    </p>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;border-radius:4px;">
                                        <tr>
                                            <td style="padding:8px 20px;">
                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                    <?php
                                                    $mp_index = 0;
                                                    foreach ($mp_details as $mp_label => $mp_value) :
                                                        if ('' === trim((string) $mp_value)) {
                                                            continue;
                                                        }
                                                        $mp_border = 0 === $mp_index++ ? 'none' : '1px solid #e3e4e8';
                                                        ?>
                                                        <tr>
                                                            <td class="mp-detail-label" style="padding:12px 16px 12px 0;border-top:<?php echo esc_attr($mp_border); ?>;font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:14px;line-height:20px;color:#5c5e6b;white-space:nowrap;vertical-align:top;">
                                                                <?php echo esc_html($mp_label); ?>
                                                            </td>
                                                            <td class="mp-detail-value" align="right" style="padding:12px 0;border-top:<?php echo esc_attr($mp_border); ?>;font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:14px;line-height:20px;font-weight:600;color:#13141c;word-break:break-word;">
                                                                <?php echo esc_html($mp_value); ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td class="mp-pad" style="padding:32px 48px 8px 48px;">
                                    <p style="margin:0 0 4px 0;font-family:<?php echo esc_attr($mp_heading_font); ?>;font-size:18px;line-height:24px;font-weight:700;letter-spacing:-0.3px;color:#13141c;">
                                        <?php esc_html_e('What’s next?', 'mailerpress'); ?>
                                    </p>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <?php foreach ($mp_steps as $mp_number => $mp_step) : ?>
                                            <tr>
                                                <td width="32" valign="top" style="width:32px;padding:16px 16px 16px 0;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td width="32" height="32" align="center" valign="middle" style="width:32px;height:32px;background-color:#c4f0ff;border-radius:4px;font-family:<?php echo esc_attr($mp_heading_font); ?>;font-size:16px;line-height:32px;font-weight:700;color:#0129ff;">
                                                                <?php echo esc_html((string) ($mp_number + 1)); ?>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td valign="top" style="padding:16px 0;<?php echo 0 < $mp_number ? 'border-top:1px solid #e3e4e8;' : ''; ?>">
                                                    <p style="margin:0 0 4px 0;font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:16px;line-height:22px;font-weight:700;color:#13141c;">
                                                        <?php echo esc_html($mp_step['title']); ?>
                                                    </p>
                                                    <p style="margin:0 0 8px 0;font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:14px;line-height:20px;color:#5c5e6b;">
                                                        <?php echo esc_html($mp_step['text']); ?>
                                                    </p>
                                                    <a href="<?php echo esc_url($mp_step['url']); ?>" target="_blank" style="font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:14px;line-height:20px;font-weight:700;color:#0129ff;text-decoration:none;">
                                                        <?php echo esc_html($mp_step['label']); ?>&nbsp;&rarr;
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </td>
                            </tr>
                            <tr>
                                <td class="mp-pad" style="padding:24px 48px 48px 48px;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td style="background-color:#0129ff;border-radius:4px;">
                                                <a href="<?php echo esc_url($links['dashboard']); ?>" target="_blank" style="display:inline-block;padding:16px 24px;font-family:<?php echo esc_attr($mp_heading_font); ?>;font-size:16px;line-height:24px;font-weight:700;letter-spacing:-0.2px;color:#ffffff;text-decoration:none;border-radius:4px;">
                                                    <?php esc_html_e('Go to MailerPress', 'mailerpress'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" class="mp-pad" style="padding:24px 48px 0 48px;font-family:<?php echo esc_attr($mp_body_font); ?>;font-size:13px;line-height:20px;color:#5c5e6b;">
                        <?php
                        printf(
                            /* translators: %s: site name linked to the site URL. */
                            esc_html__('You received this email because a sending test was run from the MailerPress settings on %s. No action is needed.', 'mailerpress'),
                            '<a href="' . esc_url($site_url) . '" target="_blank" style="color:#13141c;font-weight:600;text-decoration:underline;">' . esc_html($site_name) . '</a>'
                        );
                        ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
