<?php

declare(strict_types=1);

namespace MailerPress\Actions;

\defined('ABSPATH') || exit;

use MailerPress\Core\Attributes\Action;
use MailerPress\Services\DatabaseDiagnostic;
use MailerPress\Services\DatabaseRepairLogger;

class DatabaseUpdateNotice
{
	const OPTION_KEY = 'mailerpress_v2_db_update_needed';
	const OPTION_DONE = 'mailerpress_v2_db_update_done';

	#[Action('admin_notices')]
	public function render(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( self::OPTION_DONE ) ) {
			return;
		}

		if ( ! get_option( self::OPTION_KEY ) ) {
			return;
		}

		$issueCount = (int) get_option( self::OPTION_KEY );

		if ( ! is_textdomain_loaded( 'mailerpress' ) && function_exists( 'load_plugin_textdomain' ) ) {
			$plugin_file = defined( 'MAILERPRESS_PLUGIN_DIR_PATH' )
				? MAILERPRESS_PLUGIN_DIR_PATH . '../mailerpress.php'
				: __FILE__;
			load_plugin_textdomain( 'mailerpress', false, dirname( plugin_basename( $plugin_file ) ) . '/languages' );
		}

		$nonce = wp_create_nonce( 'mailerpress_db_update' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<div class="notice notice-warning mailerpress-db-update-notice" id="mailerpress-db-update-notice">
			<style>
				.mailerpress-db-update-notice {
					padding: 16px 20px;
					border-left-color: #dba617;
				}
				.mailerpress-db-update-notice h3 {
					margin: 0 0 8px 0;
					font-size: 14px;
				}
				.mailerpress-db-update-notice p {
					margin: 4px 0;
					font-size: 13px;
				}
				.mailerpress-db-update-notice .mailerpress-db-backup-warning {
					background: #fef8ee;
					border: 1px solid #f0c36d;
					border-radius: 4px;
					padding: 10px 14px;
					margin: 12px 0;
					display: flex;
					align-items: flex-start;
					gap: 8px;
				}
				.mailerpress-db-update-notice .mailerpress-db-backup-warning .dashicons {
					color: #dba617;
					margin-top: 1px;
					flex-shrink: 0;
				}
				.mailerpress-db-update-notice .mailerpress-db-actions {
					margin-top: 14px;
					display: flex;
					align-items: center;
					gap: 12px;
				}
				.mailerpress-db-update-notice .mailerpress-db-spinner {
					display: none;
					vertical-align: middle;
				}
				.mailerpress-db-update-notice .mailerpress-db-result {
					display: none;
					margin-top: 12px;
					padding: 10px 14px;
					border-radius: 4px;
				}
				.mailerpress-db-update-notice .mailerpress-db-result.success {
					display: block;
					background: #edfaef;
					border: 1px solid #46b450;
					color: #1e4620;
				}
				.mailerpress-db-update-notice .mailerpress-db-result.error {
					display: block;
					background: #fbeaea;
					border: 1px solid #dc3232;
					color: #8b1a1a;
				}
			</style>

			<h3><?php esc_html_e( 'MailerPress — Database update required', 'mailerpress' ); ?></h3>

			<p>
				<?php
				printf(
					/* translators: %d: number of database issues detected */
					esc_html__( 'MailerPress has detected %d issue(s) in your database that need to be fixed for optimal performance with this version. This update adds missing indexes and optimizes the database structure.', 'mailerpress' ),
					$issueCount
				);
				?>
			</p>

			<div class="mailerpress-db-backup-warning">
				<span class="dashicons dashicons-warning"></span>
				<div>
					<strong><?php esc_html_e( 'Important: Back up your database before proceeding.', 'mailerpress' ); ?></strong><br>
					<?php esc_html_e( 'While this process is safe and has been extensively tested, we strongly recommend creating a database backup before running the update. If anything goes wrong, you will be able to restore your data.', 'mailerpress' ); ?>
				</div>
			</div>

			<div class="mailerpress-db-actions" id="mailerpress-db-actions">
				<button type="button" class="button button-primary" id="mailerpress-run-db-update">
					<?php esc_html_e( 'Update MailerPress Database', 'mailerpress' ); ?>
				</button>
				<span class="spinner mailerpress-db-spinner" id="mailerpress-db-spinner"></span>
			</div>

			<div class="mailerpress-db-result" id="mailerpress-db-result"></div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var btn = document.getElementById('mailerpress-run-db-update');
			var spinner = document.getElementById('mailerpress-db-spinner');
			var resultEl = document.getElementById('mailerpress-db-result');
			var notice = document.getElementById('mailerpress-db-update-notice');

			if (!btn) return;

			btn.addEventListener('click', function() {
				btn.disabled = true;
				btn.textContent = <?php echo wp_json_encode( __( 'Updating database…', 'mailerpress' ) ); ?>;
				spinner.style.display = 'inline-block';
				spinner.classList.add('is-active');
				resultEl.style.display = 'none';
				resultEl.className = 'mailerpress-db-result';

				fetch(<?php echo wp_json_encode( esc_url( $ajax_url ) ); ?>, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: new URLSearchParams({
						action: 'mailerpress_run_db_update',
						nonce: <?php echo wp_json_encode( $nonce ); ?>
					})
				})
				.then(function(response) { return response.json(); })
				.then(function(data) {
					spinner.style.display = 'none';
					spinner.classList.remove('is-active');

					if (data.success) {
						var d = data.data;
						resultEl.className = 'mailerpress-db-result success';
						resultEl.innerHTML = '<strong>' + d.message + '</strong>';
						if (d.fixed_count > 0) {
							resultEl.innerHTML += '<br>' + d.fixed_summary;
						}
						resultEl.style.display = 'block';

						btn.style.display = 'none';

						setTimeout(function() {
							notice.style.display = 'none';
						}, 5000);
					} else {
						resultEl.className = 'mailerpress-db-result error';
						resultEl.innerHTML = '<strong>' + (data.data && data.data.message ? data.data.message : <?php echo wp_json_encode( __( 'An error occurred during the database update.', 'mailerpress' ) ); ?>) + '</strong>';
						resultEl.style.display = 'block';

						btn.disabled = false;
						btn.textContent = <?php echo wp_json_encode( __( 'Retry Database Update', 'mailerpress' ) ); ?>;
					}
				})
				.catch(function() {
					spinner.style.display = 'none';
					spinner.classList.remove('is-active');

					resultEl.className = 'mailerpress-db-result error';
					resultEl.textContent = <?php echo wp_json_encode( __( 'Network error. Please try again.', 'mailerpress' ) ); ?>;
					resultEl.style.display = 'block';

					btn.disabled = false;
					btn.textContent = <?php echo wp_json_encode( __( 'Retry Database Update', 'mailerpress' ) ); ?>;
				});
			});
		});
		</script>
		<?php
	}

	#[Action('wp_ajax_mailerpress_run_db_update')]
	public function handleUpdate(): void
	{
		check_ajax_referer( 'mailerpress_db_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'You do not have permission to perform this action.', 'mailerpress' ),
			], 403 );
		}

		try {
			$diagnostic = new DatabaseDiagnostic();
			DatabaseRepairLogger::init();

			$result = $diagnostic->repair();

			if ( $result['success'] ) {
				$postDiagnostic = $diagnostic->diagnose();

				if ( $postDiagnostic['healthy'] ) {
					delete_option( self::OPTION_KEY );
					update_option( self::OPTION_DONE, true );

					$fixedCount = count( $result['fixed_issues'] ?? [] );

					wp_send_json_success( [
						'message'       => __( 'Database updated successfully! All issues have been resolved.', 'mailerpress' ),
						'fixed_count'   => $fixedCount,
						'fixed_summary' => sprintf(
							/* translators: %d: number of fixes applied */
							__( '%d fix(es) applied.', 'mailerpress' ),
							$fixedCount
						),
					] );
				} else {
					$remaining = $postDiagnostic['summary']['total_issues'] ?? 0;
					update_option( self::OPTION_KEY, $remaining );

					wp_send_json_success( [
						'message'       => sprintf(
							/* translators: %d: number of remaining issues */
							__( 'Database partially updated. %d issue(s) remaining — please run the update again or contact support.', 'mailerpress' ),
							$remaining
						),
						'fixed_count'   => count( $result['fixed_issues'] ?? [] ),
						'fixed_summary' => sprintf(
							/* translators: %d: number of fixes applied */
							__( '%d fix(es) applied so far.', 'mailerpress' ),
							count( $result['fixed_issues'] ?? [] )
						),
					] );
				}
			} else {
				wp_send_json_error( [
					'message' => $result['message'] ?? __( 'Database repair failed.', 'mailerpress' ),
				] );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'mailerpress' ),
					$e->getMessage()
				),
			] );
		}
	}

	/**
	 * Run diagnostic after migrations and set the flag if issues are found.
	 * Called from TableManager after migrations complete on version change.
	 */
	public static function checkAfterMigrations(): void
	{
		if ( get_option( self::OPTION_DONE ) ) {
			return;
		}

		try {
			$diagnostic = new DatabaseDiagnostic();
			$result = $diagnostic->diagnose();

			if ( ! $result['healthy'] ) {
				$issueCount = $result['summary']['total_issues'] ?? 1;
				update_option( self::OPTION_KEY, $issueCount );
			} else {
				delete_option( self::OPTION_KEY );
			}
		} catch ( \Throwable $e ) {
			// Silently fail — don't block plugin load
		}
	}
}
