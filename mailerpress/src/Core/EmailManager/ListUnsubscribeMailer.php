<?php
/**
 * PHPMailer that keeps the List-Unsubscribe header unencoded.
 *
 * @package MailerPress
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidVariableName -- Follow PSR-4 file names and PHPMailer property names.

declare(strict_types=1);

namespace MailerPress\Core\EmailManager;

\defined( 'ABSPATH' ) || exit;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * In mail() mode, PHPMailer Q-encodes every header value longer than 47 characters, so the
 * List-Unsubscribe URL becomes "=?us-ascii?Q?<https://...?=" and Gmail and Yahoo no longer offer
 * one-click unsubscribe. This mailer writes that header as is when its value is printable ASCII.
 *
 * Load PHPMailer before this class.
 */
class ListUnsubscribeMailer extends PHPMailer {

	/**
	 * Create the mailer.
	 *
	 * @param bool|null $exceptions Whether to throw exceptions on errors.
	 */
	public function __construct( $exceptions = null ) {
		parent::__construct( $exceptions );

		// WP_PHPMailer was added in WordPress 6.8 and translates PHPMailer errors.
		if ( class_exists( 'WP_PHPMailer' ) ) {
			\WP_PHPMailer::setLanguage();
		}
	}

	/**
	 * Build the message headers, with List-Unsubscribe left unencoded.
	 *
	 * @return string
	 */
	public function createHeader() {
		$custom_headers = $this->CustomHeader;
		$raw_headers    = '';

		foreach ( $custom_headers as $key => $header ) {
			$name  = trim( (string) $header[0] );
			$value = trim( (string) $header[1] );

			// Keep the value within the 998-character line limit; longer values stay encoded.
			if ( 0 === strcasecmp( $name, 'List-Unsubscribe' )
				&& 1 === preg_match( '/^[\x20-\x7E]+$/', $value )
				&& \strlen( $name . ': ' . $value ) <= static::MAX_LINE_LENGTH
			) {
				$raw_headers .= $this->headerLine( $name, $value );
				unset( $this->CustomHeader[ $key ] );
			}
		}

		try {
			$headers = parent::createHeader();
		} finally {
			$this->CustomHeader = $custom_headers;
		}

		return $headers . $raw_headers;
	}
}
