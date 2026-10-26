<?php
/**
 * WordPress email service.
 *
 * @package MailerPress
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName, WordPress.NamingConventions.ValidVariableName, WordPress.WP.CapitalPDangit -- Follow the PSR-4 service interface and PHPMailer property names; "wordpress" is the provider key.

declare(strict_types=1);

namespace MailerPress\Core\EmailManager\services;

\defined( 'ABSPATH' ) || exit;

use MailerPress\Core\EmailManager\AbstractEmailService;
use MailerPress\Core\EmailManager\ListUnsubscribeMailer;
use MailerPress\Core\EmailManager\SmtpPluginDetector;
use MailerPress\Services\BounceParser;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends with the PHPMailer library bundled with WordPress, in mail() mode.
 *
 * The wp_mail() function and its hooks are bypassed, so SMTP plugins do not handle campaigns.
 * MailerPress Pro registers a service that calls wp_mail() under the same key.
 */
class WordPressService extends AbstractEmailService {

	/**
	 * Send an email from the site's server.
	 *
	 * @param array $emailData Email data: recipient, subject, body, sender and headers.
	 * @return bool
	 */
	public function sendEmail( array $emailData ): bool {
		$to           = (string) ( $emailData['to'] ?? '' );
		$subject      = (string) ( $emailData['subject'] ?? '' );
		$body         = (string) ( $emailData['body'] ?? '' );
		$conf         = (array) ( $this->config['conf'] ?? array() );
		$sender_email = (string) ( $emailData['sender_to'] ?? '' );
		$sender_email = '' !== $sender_email ? $sender_email : (string) ( $conf['default_email'] ?? get_option( 'admin_email', '' ) );
		$sender_name  = (string) ( $emailData['sender_name'] ?? $conf['default_name'] ?? wp_specialchars_decode( get_option( 'blogname', '' ), ENT_QUOTES ) );

		if ( ! is_email( $to ) || ! is_email( $sender_email ) || '' === $subject || '' === $body ) {
			return false;
		}

		try {
			$mailer = $this->createMailer();
			$mailer->isMail();
			$mailer->CharSet = PHPMailer::CHARSET_UTF8;
			$mailer->XMailer = 'MailerPress/WordPress-Service';
			$mailer->setFrom( $sender_email, $sender_name, false );
			$mailer->Sender = $sender_email;
			$mailer->addAddress( $to );

			$reply_to = (string) ( $emailData['reply_to_address'] ?? $sender_email );
			if ( '' !== $reply_to ) {
				$mailer->addReplyTo( $reply_to, (string) ( $emailData['reply_to_name'] ?? $sender_name ) );
			}

			$bounce_config = BounceParser::getValidatedConfig();
			if ( null !== $bounce_config && ! empty( $bounce_config['email'] ) ) {
				$mailer->addCustomHeader( 'Return-Path', '<' . ( $emailData['return_path'] ?? $bounce_config['email'] ) . '>' );
			}

			if ( ! empty( $emailData['custom_headers'] ) && \is_array( $emailData['custom_headers'] ) ) {
				foreach ( $emailData['custom_headers'] as $header_name => $header_value ) {
					$mailer->addCustomHeader( (string) $header_name, (string) $header_value );
				}
			}

			$mailer->isHTML( true === ( $emailData['html'] ?? false ) );
			$mailer->Subject = $subject;
			$mailer->Body    = $body;

			$result = $mailer->send();
		} catch ( PHPMailerException $e ) {
			$this->logEmail( $emailData, 'wordpress', false, $e->getMessage() );

			return false;
		}

		$this->logEmail( $emailData, 'wordpress', $result );

		return $result;
	}

	/**
	 * Create a mailer like the one used by wp_mail(), without calling wp_mail().
	 *
	 * @return PHPMailer
	 */
	private function createMailer(): PHPMailer {
		if ( ! class_exists( PHPMailer::class ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		}

		if ( ! class_exists( PHPMailerException::class ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		// WP_PHPMailer was added in WordPress 6.8 and translates PHPMailer errors.
		if ( ! class_exists( 'WP_PHPMailer' ) && file_exists( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
		}

		return new ListUnsubscribeMailer( true );
	}

	/**
	 * Sending from the server needs no connection test.
	 *
	 * @return bool
	 */
	public function testConnection(): bool {
		return true;
	}

	/**
	 * Provider card shown in the admin.
	 *
	 * @return array
	 */
	public function config(): array {
		$smtp_plugin = SmtpPluginDetector::detect();
		$description = __( 'Sends from your site’s server with the mailer built into WordPress. No setup needed, but emails often land in spam. Best for testing.', 'mailerpress' );

		if ( null !== $smtp_plugin ) {
			$description .= ' ' . ( '' === $smtp_plugin
				? __( 'SMTP plugin detected: campaigns don’t go through it. With MailerPress Pro, they do.', 'mailerpress' )
				: \sprintf(
					/* translators: %s: SMTP plugin name, e.g. WP Mail SMTP. */
					__( '%s detected: campaigns don’t go through it. With MailerPress Pro, they do.', 'mailerpress' ),
					$smtp_plugin
				) );
		}

		return array(
			'key'               => 'wordpress',
			'name'              => __( 'WordPress', 'mailerpress' ),
			'icon'              => '<svg xmlns="http://www.w3.org/2000/svg" role="img" width="28" height="28" viewBox="0 0 28 28"><path fill="currentColor" d="M13.6052 0.923525C16.1432 0.923525 18.6137 1.67953 20.7062 3.09703C22.7447 4.47403 24.3512 6.41803 25.3097 8.68603C26.9837 12.6415 26.5382 17.164 24.1352 20.7145C22.7582 22.753 20.8142 24.3595 18.5462 25.318C14.5907 26.992 10.0682 26.5465 6.51772 24.1435C4.47922 22.7665 2.87272 20.8225 1.91422 18.5545C0.240225 14.599 0.685725 10.0765 3.08872 6.52603C4.46572 4.48753 6.40973 2.88103 8.67772 1.92253C10.2302 1.26103 11.9177 0.923525 13.6052 0.923525ZM13.6052 0.113525C6.15322 0.113525 0.105225 6.16153 0.105225 13.6135C0.105225 21.0655 6.15322 27.1135 13.6052 27.1135C21.0572 27.1135 27.1052 21.0655 27.1052 13.6135C27.1052 6.16153 21.0572 0.113525 13.6052 0.113525Z"/><path fill="currentColor" d="M2.36011 13.6133C2.36011 17.9198 4.81711 21.8618 8.70511 23.7383L3.33211 9.03684C2.68411 10.4813 2.36011 12.0338 2.36011 13.6133ZM21.2061 13.0463C21.2061 11.6558 20.7066 10.6973 20.2746 9.94134C19.8426 9.18534 19.1676 8.22684 19.1676 7.30884C19.1676 6.39084 19.9506 5.31084 21.0576 5.31084H21.2061C16.6296 1.11234 9.51511 1.42284 5.31661 6.01284C4.91161 6.45834 4.53361 6.93084 4.20961 7.43034H4.93861C6.11311 7.43034 7.93561 7.28184 7.93561 7.28184C8.54311 7.24134 8.61061 8.13234 8.00311 8.21334C8.00311 8.21334 7.39561 8.28084 6.72061 8.32134L10.8111 20.5118L13.2681 13.1273L11.5131 8.32134C10.9056 8.28084 10.3386 8.21334 10.3386 8.21334C9.73111 8.17284 9.79861 7.25484 10.4061 7.28184C10.4061 7.28184 12.2691 7.43034 13.3626 7.43034C14.4561 7.43034 16.3596 7.28184 16.3596 7.28184C16.9671 7.24134 17.0346 8.13234 16.4271 8.21334C16.4271 8.21334 15.8196 8.28084 15.1446 8.32134L19.2081 20.4173L20.3691 16.7453C20.8821 15.1388 21.1926 14.0048 21.1926 13.0328L21.2061 13.0463ZM13.7946 14.5853L10.4196 24.3998C12.6876 25.0613 15.1041 25.0073 17.3316 24.2243L17.2506 24.0758L13.7946 14.5853ZM23.4741 8.21334C23.5281 8.59134 23.5551 8.98284 23.5551 9.37434C23.5551 10.5218 23.3391 11.8043 22.7046 13.3973L19.2621 23.3333C24.5271 20.2688 26.4036 13.5593 23.4741 8.21334Z"/></svg>',
			'link'              => 'https://mailerpress.com/docs/how-to-send-emails-with-wordpress-default-wp_mail/',
			'createAccountLink' => '',
			'linkApiKey'        => '',
			'description'       => $description,
			'recommended'       => false,
			// Lets the admin show sender settings and the SMTP plugin notice for this edition.
			'native'            => true,
			'smtp_plugin'       => $smtp_plugin,
			'sending_frequency' => array(
				'numberEmail' => 25,
				'frequency'   => array(
					'value' => 5,
					'unit'  => 'minutes',
				),
			),
		);
	}
}
