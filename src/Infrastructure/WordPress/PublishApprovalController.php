<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Execution\Execution_Exception;
use SmartCloud\AgentComposer\Security\PublishApprovalService;

final class PublishApprovalController {
	public function __construct( private readonly PublishApprovalService $approvals ) {}

	public function hooks(): void {
		add_action( 'admin_post_smartcloud_composer_publish_review', array( $this, 'review' ) );
		add_action( 'admin_post_nopriv_smartcloud_composer_publish_review', array( $this, 'review' ) );
		add_action( 'admin_post_smartcloud_composer_publish_approval_asset', array( $this, 'asset' ) );
		add_action( 'admin_post_nopriv_smartcloud_composer_publish_approval_asset', array( $this, 'asset' ) );
		add_action( 'admin_post_smartcloud_composer_publish_decide', array( $this, 'decide' ) );
	}

	public function review(): never {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview uses a short-lived opaque token; no state is changed.
		$id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See the token-bound read-only preview above.
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		try {
			$review = $this->approvals->review( $id, $token );
			$this->render_review( $review, $token );
		} catch ( Execution_Exception $error ) {
			$this->render_message( __( 'Publish review unavailable', 'smartcloud-agent-composer' ), $error->getMessage(), 410 );
		}
	}

	public function decide(): never {
		if ( ! is_user_logged_in() || ! current_user_can( Activation::CAP_APPROVE_PUBLISH ) ) {
			wp_die( esc_html__( 'You are not allowed to approve Composer publication requests.', 'smartcloud-agent-composer' ), '', array( 'response' => 403 ) );
		}
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		check_admin_referer( 'smartcloud_composer_publish_decision_' . $id );
		try {
			$result = $this->approvals->decide( $id, $token, $decision, get_current_user_id() );
			$title = 'approved' === $result['status'] ? __( 'Content published', 'smartcloud-agent-composer' ) : __( 'Publish request rejected', 'smartcloud-agent-composer' );
			$this->render_message( $title, __( 'The decision was recorded in the Composer audit log.', 'smartcloud-agent-composer' ), 200 );
		} catch ( Execution_Exception $error ) {
			$this->render_message( __( 'Decision not applied', 'smartcloud-agent-composer' ), $error->getMessage(), 409 );
		}
	}

	public function asset(): never {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset access is bound to the opaque approval token.
		$id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset access is bound to the opaque approval token.
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset access is bound to the opaque approval token.
		$asset_id = sanitize_text_field( wp_unslash( $_GET['asset'] ?? '' ) );
		try {
			$asset = $this->approvals->asset( $id, $token, $asset_id );
			$content = (string) $asset['content'];
			if ( 'stylesheet' === $asset['kind'] ) {
				$content = $this->rewrite_asset_references( $content, $id, $token );
			}
			status_header( 200 );
			nocache_headers();
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Type: ' . (string) $asset['mime_type'] );
			header( 'Content-Length: ' . strlen( $content ) );
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated bounded preview asset bytes.
			exit;
		} catch ( Execution_Exception $error ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
	}

	private function render_review( array $review, string $token ): never {
		$approval = $review['approval'];
		$post = $review['post'];
		$preview = $review['preview']['document'] ?? array();
		$html = (string) ( $preview['html'] ?? '' );
		$html = (string) apply_filters( 'smartcloud_agent_composer_publish_approval_html', $html, $approval, null );
		$assets = (array) apply_filters( 'smartcloud_agent_composer_publish_approval_assets', (array) ( $preview['assets'] ?? array() ), $approval );
		$html = $this->rewrite_asset_references( $html, (string) $approval['id'], $token );
		$stylesheets = array_values( array_filter( $assets, static fn( mixed $asset ): bool => is_array( $asset ) && 'stylesheet' === ( $asset['kind'] ?? '' ) && ! empty( $asset['asset_id'] ) ) );
		$stylesheet_contents = (array) ( $preview['approval_stylesheets'] ?? array() );
		$login_url = wp_login_url( add_query_arg( array( 'action' => 'smartcloud_composer_publish_review', 'id' => $approval['id'], 'token' => $token ), admin_url( 'admin-post.php' ) ) );
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		?><!doctype html><html><head><meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Publish review', 'smartcloud-agent-composer' ); ?></title>
		<style>body{margin:0;background:#f6f7f7;color:#1d2327;font:15px/1.55 system-ui,sans-serif}.shell{max-width:1100px;margin:32px auto;padding:0 20px}.card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:24px;margin-bottom:20px}.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.label{color:#646970;font-size:12px;text-transform:uppercase}.preview{overflow:auto;max-height:65vh;border:1px solid #dcdcde;border-radius:8px;padding:clamp(16px,4vw,40px)}button,.button{border:0;border-radius:4px;padding:10px 18px;font-weight:600;text-decoration:none;cursor:pointer}.publish{background:#00a32a;color:#fff}.reject{background:#f0f0f1;color:#b32d2e;margin-right:8px}.notice{background:#fcf9e8;border-left:4px solid #dba617;padding:12px}.actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}</style><?php foreach ( $stylesheets as $stylesheet ) : $asset_id = (string) $stylesheet['asset_id']; if ( isset( $stylesheet_contents[ $asset_id ] ) ) : ?><style media="<?php echo esc_attr( (string) ( $stylesheet['media'] ?? 'all' ) ); ?>"><?php echo $this->rewrite_asset_references( (string) $stylesheet_contents[ $asset_id ], (string) $approval['id'], $token ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded preview CSS is sanitized and URL-rewritten by Rendered_Preview_Service. ?></style><?php endif; endforeach; ?></head><body><main class="shell">
		<div class="card"><h1><?php /* translators: %s: post title. */ echo esc_html( sprintf( __( 'Publish “%s”?', 'smartcloud-agent-composer' ), get_the_title( $post ) ) ); ?></h1><p class="notice"><?php esc_html_e( 'This approval is bound to the exact revision and content hash shown here. Any later change invalidates it.', 'smartcloud-agent-composer' ); ?></p><div class="meta">
		<div><div class="label"><?php esc_html_e( 'Requested by', 'smartcloud-agent-composer' ); ?></div><?php echo esc_html( $approval['principal_id'] ); ?></div><div><div class="label"><?php esc_html_e( 'Through client', 'smartcloud-agent-composer' ); ?></div><?php echo esc_html( $approval['requesting_client_id'] ); ?></div><div><div class="label"><?php esc_html_e( 'Revision', 'smartcloud-agent-composer' ); ?></div><?php echo esc_html( $approval['revision'] ); ?></div><div><div class="label"><?php esc_html_e( 'Expires', 'smartcloud-agent-composer' ); ?></div><?php echo esc_html( $approval['expires_gmt'] ); ?> UTC</div></div></div>
		<div class="card"><h2><?php esc_html_e( 'Exact content preview', 'smartcloud-agent-composer' ); ?></h2><div class="preview"><?php echo wp_kses_post( $html ); ?></div>
		<?php if ( is_user_logged_in() && current_user_can( Activation::CAP_APPROVE_PUBLISH ) ) : ?><form class="actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="smartcloud_composer_publish_decide"><input type="hidden" name="id" value="<?php echo esc_attr( $approval['id'] ); ?>"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'smartcloud_composer_publish_decision_' . $approval['id'] ); ?><button class="reject" name="decision" value="reject"><?php esc_html_e( 'Reject', 'smartcloud-agent-composer' ); ?></button><button class="publish" name="decision" value="approve"><?php esc_html_e( 'Publish this revision', 'smartcloud-agent-composer' ); ?></button></form>
		<?php else : ?><p class="actions"><a class="button publish" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Sign in to decide', 'smartcloud-agent-composer' ); ?></a></p><?php endif; ?></div></main></body></html><?php
		exit;
	}

	private function rewrite_asset_references( string $content, string $id, string $token ): string {
		$rewritten = preg_replace_callback(
			'~smartcloud-preview-asset://(pa_[A-Za-z0-9_-]{43})~',
			fn( array $match ): string => $this->asset_url( $id, $token, (string) $match[1] ),
			$content
		);
		$rewritten = null === $rewritten ? $content : $rewritten;
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $rewritten;
		}
		$tags = new \WP_HTML_Tag_Processor( $rewritten );
		while ( $tags->next_tag( 'IMG' ) ) {
			$asset_id = (string) $tags->get_attribute( 'data-smartcloud-preview-asset' );
			if ( preg_match( '/^pa_[A-Za-z0-9_-]{43}$/', $asset_id ) ) {
				$tags->set_attribute( 'src', $this->asset_url( $id, $token, $asset_id ) );
			}
			$tags->remove_attribute( 'data-smartcloud-preview-asset' );
		}
		return $tags->get_updated_html();
	}

	private function asset_url( string $id, string $token, string $asset_id ): string {
		return add_query_arg(
			array( 'action' => 'smartcloud_composer_publish_approval_asset', 'id' => $id, 'token' => $token, 'asset' => $asset_id ),
			admin_url( 'admin-post.php' )
		);
	}

	private function render_message( string $title, string $message, int $status ): never {
		status_header( $status ); nocache_headers(); header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		?><!doctype html><html><head><meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?></title><style>body{background:#f6f7f7;font:16px/1.5 system-ui,sans-serif;color:#1d2327}.card{max-width:680px;margin:12vh auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:32px}</style></head><body><main class="card"><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $message ); ?></p></main></body></html><?php
		exit;
	}
}
