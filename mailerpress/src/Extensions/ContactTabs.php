<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName -- PSR-4 autoloading requires the class filename.
/**
 * Declarative contact-tab API for third-party plugins.
 *
 * @package MailerPress
 */

declare(strict_types=1);

namespace MailerPress\Extensions;

\defined( 'ABSPATH' ) || exit;

/** Registry and validated presentation contract for contact extensions. */
final class ContactTabs {
	/**
	 * Registered providers.
	 *
	 * @var array
	 */
	private array $tabs = array();

	/**
	 * Register a namespaced tab during mailerpress_register_contact_tabs.
	 *
	 * @param string $id Namespaced identifier.
	 * @param array  $definition Provider definition.
	 * @return bool|\WP_Error Registration result.
	 */
	public function register( string $id, array $definition ): bool|\WP_Error {
		if ( ! preg_match( '/^[a-z][a-z0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id ) || 80 < strlen( $id ) ) {
			return new \WP_Error( 'mailerpress_invalid_tab_id', 'Use a vendor-prefixed tab ID, for example acme-orders.' );
		}
		if ( isset( $this->tabs[ $id ] ) || 20 <= count( $this->tabs ) ) {
			return new \WP_Error( 'mailerpress_duplicate_or_excess_tab', 'Tab already registered or tab limit reached.' );
		}
		if ( 1 !== ( $definition['api_version'] ?? null ) || ! self::is_text( $definition['title'] ?? null, 80 ) || ! is_callable( $definition['permission_callback'] ?? null ) || ! is_callable( $definition['render_callback'] ?? null ) ) {
			return new \WP_Error( 'mailerpress_invalid_tab', 'A version, title, permission callback and render callback are required.' );
		}
		$title = sanitize_text_field( $definition['title'] );
		if ( '' === trim( $title ) ) {
			return new \WP_Error( 'mailerpress_invalid_tab', 'Tab title must not be empty.' );
		}
		$this->tabs[ $id ] = array(
			'id'                  => $id,
			'title'               => $title,
			'priority'            => is_int( $definition['priority'] ?? null ) ? $definition['priority'] : 10,
			'permission_callback' => $definition['permission_callback'],
			'render_callback'     => $definition['render_callback'],
		);
		return true;
	}

	/**
	 * List metadata without running render callbacks.
	 *
	 * @param array $contact Persisted contact context.
	 * @return array Visible tabs.
	 */
	public function listing( array $contact ): array {
		$tabs = array_filter( $this->tabs, fn( $tab ) => $this->allowed( $tab, $contact ) );
		usort( $tabs, static fn( $a, $b ) => $a['priority'] === $b['priority'] ? strcmp( $a['id'], $b['id'] ) : ( $a['priority'] <=> $b['priority'] ) );
		return array_map(
			static fn( $tab ) => array(
				'id'    => $tab['id'],
				'title' => $tab['title'],
			),
			$tabs
		);
	}

	/**
	 * Resolve a tab after checking permissions again.
	 *
	 * @param string $id Tab identifier.
	 * @param array  $contact Persisted contact context.
	 * @return array|\WP_Error Validated content or generic failure.
	 */
	public function content( string $id, array $contact ): array|\WP_Error {
		$tab = $this->tabs[ $id ] ?? null;
		if ( null === $tab || ! $this->allowed( $tab, $contact ) ) {
			return new \WP_Error( 'mailerpress_tab_unavailable', __( 'This contact tab is unavailable.', 'mailerpress' ), array( 'status' => 404 ) );
		}
		try {
			$result = ( $tab['render_callback'] )( $contact );
			if ( is_array( $result ) && array_key_exists( 'blocks', $result ) ) {
				$blocks = self::validate_blocks( $result['blocks'] );
				if ( null !== $blocks ) {
					return array(
						'api_version' => 1,
						'blocks'      => $blocks,
					);
				}
				return new \WP_Error( 'mailerpress_tab_failed', __( 'Unable to load this contact tab.', 'mailerpress' ), array( 'status' => 502 ) );
			}
			$cards = self::validate_cards( $result );
			if ( null !== $cards ) {
				return array(
					'api_version' => 1,
					'cards'       => $cards,
				);
			}
		} catch ( \Throwable $error ) {
			// Provider failures must not expose exception details or break core tabs.
			$result = null;
		}
		return new \WP_Error( 'mailerpress_tab_failed', __( 'Unable to load this contact tab.', 'mailerpress' ), array( 'status' => 502 ) );
	}

	/**
	 * Check the provider policy, denying on errors.
	 *
	 * @param array $tab Provider definition.
	 * @param array $contact Contact context.
	 * @return bool Whether access is granted.
	 */
	private function allowed( array $tab, array $contact ): bool {
		try {
			return true === ( $tab['permission_callback'] )( $contact );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Check a required bounded string.
	 *
	 * @param mixed $value Candidate text.
	 * @param int   $limit Byte limit.
	 * @return bool Whether valid.
	 */
	private static function is_text( mixed $value, int $limit ): bool {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= $limit;
	}

	/**
	 * Validate bounded presentation blocks using the field and link allowlist.
	 *
	 * @param mixed $blocks Provider blocks.
	 * @return array|null Validated blocks or failure.
	 */
	private static function validate_blocks( mixed $blocks ): ?array {
		if ( ! is_array( $blocks ) || ! array_is_list( $blocks ) || 12 < count( $blocks ) ) {
			return null;
		}
		$output = array();
		$ids    = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! in_array( $block['type'] ?? null, array( 'section', 'card', 'metrics', 'timeline', 'table', 'notice' ), true ) || ! in_array( $block['width'] ?? 'full', array( 'full', 'half' ), true ) ) {
				return null;
			}
			$type = $block['type'];
			$base = $block;
			if ( in_array( $type, array( 'table', 'notice' ), true ) ) {
				$base['fields'] = array();
			}
			$validated = self::validate_cards( array( 'cards' => array( $base ) ) );
			if ( null === $validated || isset( $ids[ $validated[0]['id'] ] ) ) {
				return null;
			}
			$clean               = $validated[0];
			$ids[ $clean['id'] ] = true;
			$clean['type']       = $type;
			$clean['width']      = $block['width'] ?? 'full';
			if ( 'notice' === $type ) {
				if ( ! in_array( $block['status'] ?? 'info', array( 'info', 'success', 'warning', 'error' ), true ) ) {
					return null;
				}
				$clean['status'] = $block['status'] ?? 'info';
			}
			if ( isset( $block['link'] ) ) {
				$link = self::validate_link( $block['link'] );
				if ( null === $link ) {
					return null;
				}
				$clean['link'] = $link;
			}
			if ( 'table' === $type ) {
				if ( ! is_array( $block['columns'] ?? null ) || ! array_is_list( $block['columns'] ) || array() === $block['columns'] || 10 < count( $block['columns'] ) || ! is_array( $block['rows'] ?? null ) || ! array_is_list( $block['rows'] ) || 100 < count( $block['rows'] ) ) {
					return null;
				}
				$clean['columns'] = array();
				$column_ids       = array();
				foreach ( $block['columns'] as $column ) {
					if ( ! is_array( $column ) || ! self::is_text( $column['id'] ?? null, 80 ) || ! preg_match( '/^[a-z][a-z0-9-]*$/D', $column['id'] ) || isset( $column_ids[ $column['id'] ] ) || ! self::is_text( $column['label'] ?? null, 160 ) || '' === trim( sanitize_text_field( $column['label'] ) ) ) {
						return null;
					}
					$column_ids[ $column['id'] ] = true;
					$clean['columns'][]          = array(
						'id'    => $column['id'],
						'label' => sanitize_text_field( $column['label'] ),
					);
				}
				$clean['rows'] = array();
				$row_ids       = array();
				foreach ( $block['rows'] as $row ) {
					if ( ! is_array( $row ) || ! self::is_text( $row['id'] ?? null, 80 ) || ! preg_match( '/^[a-z0-9-]+$/D', $row['id'] ) || isset( $row_ids[ $row['id'] ] ) || ! is_array( $row['cells'] ?? null ) ) {
						return null;
					}
					$row_ids[ $row['id'] ] = true;
					$cells                 = array();
					foreach ( $clean['columns'] as $column ) {
						$cell = $row['cells'][ $column['id'] ] ?? null;
						if ( ! is_array( $cell ) ) {
							return null;
						}
						$cell['label'] = $column['label'];
						$checked       = self::validate_cards(
							array(
								'cards' => array(
									array(
										'id'     => 'cell',
										'title'  => 'Cell',
										'fields' => array( $cell ),
									),
								),
							)
						);
						if ( null === $checked ) {
							return null;
						}
						$cells[ $column['id'] ] = $checked[0]['fields'][0];
					}
					$clean['rows'][] = array(
						'id'    => $row['id'],
						'cells' => $cells,
					);
				}
			}
			$output[] = $clean;
		}
		$encoded = wp_json_encode( $output );
		return false !== $encoded && strlen( $encoded ) <= 262144 ? $output : null;
	}

	/**
	 * Validate an optional block link with the field label and URL rules.
	 *
	 * @param mixed $link Provider link with label and url.
	 * @return array|null Validated link or failure.
	 */
	private static function validate_link( mixed $link ): ?array {
		if ( ! is_array( $link ) || ! isset( $link['url'] ) ) {
			return null;
		}
		$checked = self::validate_cards(
			array(
				'cards' => array(
					array(
						'id'     => 'link',
						'title'  => 'Link',
						'fields' => array(
							array(
								'label' => $link['label'] ?? null,
								'value' => $link['label'] ?? null,
								'url'   => $link['url'],
							),
						),
					),
				),
			)
		);
		if ( null === $checked || ! isset( $checked[0]['fields'][0]['url'] ) ) {
			return null;
		}
		return array(
			'label' => $checked[0]['fields'][0]['label'],
			'url'   => $checked[0]['fields'][0]['url'],
		);
	}

	/**
	 * Project provider output onto the card schema.
	 *
	 * @param mixed $data Provider content.
	 * @return array|null Validated cards or failure.
	 */
	private static function validate_cards( mixed $data ): ?array {
		if ( ! is_array( $data ) || ! isset( $data['cards'] ) || ! is_array( $data['cards'] ) || ! array_is_list( $data['cards'] ) || 12 < count( $data['cards'] ) ) {
			return null;
		}
		$cards = array();
		$ids   = array();
		foreach ( $data['cards'] as $card ) {
			if ( ! is_array( $card ) || ! self::is_text( $card['id'] ?? null, 80 ) || ! preg_match( '/^[a-z0-9-]+$/D', $card['id'] ) || isset( $ids[ $card['id'] ] ) || ! self::is_text( $card['title'] ?? null, 160 ) || ! is_array( $card['fields'] ?? null ) || ! array_is_list( $card['fields'] ) || 30 < count( $card['fields'] ) ) {
				return null;
			}
			$ids[ $card['id'] ] = true;
			$clean              = array(
				'id'     => $card['id'],
				'title'  => sanitize_text_field( $card['title'] ),
				'fields' => array(),
			);
			if ( '' === trim( $clean['title'] ) ) {
				return null;
			}
			if ( isset( $card['description'] ) ) {
				if ( ! is_string( $card['description'] ) || 2000 < strlen( $card['description'] ) ) {
					return null;
				}
				$clean['description'] = sanitize_textarea_field( $card['description'] );
			}
			foreach ( $card['fields'] as $field ) {
				if ( ! is_array( $field ) || ! self::is_text( $field['label'] ?? null, 160 ) || ! isset( $field['value'] ) || ! is_scalar( $field['value'] ) || 2000 < strlen( (string) $field['value'] ) ) {
					return null;
				}
				$row = array(
					'label' => sanitize_text_field( $field['label'] ),
					'value' => sanitize_textarea_field( (string) $field['value'] ),
				);
				if ( '' === trim( $row['label'] ) ) {
					return null;
				}
				if ( isset( $field['url'] ) ) {
					if ( ! is_string( $field['url'] ) || 2048 < strlen( $field['url'] ) ) {
						return null;
					}
					if ( ! preg_match( '#^https?://#i', $field['url'] ) || '' === trim( $row['value'] ) ) {
						return null;
					}
					$url = esc_url_raw( $field['url'], array( 'http', 'https' ) );
					if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
						return null;
					}
					$row['url'] = $url;
				}
				$clean['fields'][] = $row;
			}
			$cards[] = $clean;
		}
		return $cards;
	}
}
