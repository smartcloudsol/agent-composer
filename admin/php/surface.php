<?php

namespace SmartCloud\AgentComposer\Admin;

use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;

/** Read-only status and bounded discovery/preset actions over the canonical lifecycle. */
trait EditingSurface {
	private function editingRequest(string $method, string $route, array $body = array()): array|\WP_Error {
		$request = new \WP_REST_Request($method, '/smartcloud-agent-composer/v1/' . $route);
		if ($method === 'GET' && $body) $request->set_query_params($body);
		if ($method !== 'GET') {
			$request->set_header('x_wp_nonce', wp_create_nonce('wp_rest'));
			$request->set_header('content-type', 'application/json');
			$request->set_body(wp_json_encode($body));
		}
		$response = rest_do_request($request);
		if (is_wp_error($response)) return $response;
		$data = $response->get_data();
		if ($response->get_status() >= 400) return new \WP_Error('composer_surface_request_failed', $data['message'] ?? __('Composer could not complete the operation.', 'smartcloud-agent-composer'), array('status' => $response->get_status()));
		return is_array($data) ? $data : array();
	}

	private function editingHelp(): array {
		$topics = array(
			array('active-configuration', __('Active configuration', 'smartcloud-agent-composer'), __('The active configuration governs site editing.', 'smartcloud-agent-composer'), array(__('Active configuration is read-only. Its name and entity count describe the selected configuration, not the number of pages or proposals. Configuration sets remain available in Advanced.', 'smartcloud-agent-composer'))),
			array('editing-boundary', __('Editing boundary', 'smartcloud-agent-composer'), __('Agents work on drafts within the configured editing rules.', 'smartcloud-agent-composer'), array(__('Composer validates agent changes against the active configuration. Human review is required before publication. Creating or activating a configuration does not publish site content.', 'smartcloud-agent-composer'), __('Agent authentication is configured separately in Advanced. This setup does not connect an agent or change its authentication.', 'smartcloud-agent-composer'))),
			array('preset-start', __('Start from a preset', 'smartcloud-agent-composer'), __('Create the first inactive configuration from an available preset.', 'smartcloud-agent-composer'), array(__('This action is available only when the site has no configuration sets. The preset supplies its default name. Review, validate and activate the resulting configuration in Advanced.', 'smartcloud-agent-composer'), __('SmartCloud Recommended provides a portable starting point for most sites. Universal Gutenberg uses the smallest core-block structure. Detected Theme Starter follows an available full-page pattern from the active theme; only available presets are listed.', 'smartcloud-agent-composer'), __('Starting from a preset does not activate a configuration, publish content or connect an agent. Existing configurations are edited only in Advanced.', 'smartcloud-agent-composer'))),
			array('site-rescan', __('Rescan site', 'smartcloud-agent-composer'), __('Refresh the site discovery snapshot.', 'smartcloud-agent-composer'), array(__('Rescan records the current site observations used by Composer discovery. It does not edit configuration sets, activate a preset or publish content.', 'smartcloud-agent-composer'), __('Rescanning requires permission to validate Composer configuration. Configuration editing and activation remain separate permissions.', 'smartcloud-agent-composer'))),
			array('proposal-review', __('Content proposal review', 'smartcloud-agent-composer'), __('The review count includes proposals ready for human review.', 'smartcloud-agent-composer'), array(__('The count and Content proposals link are shown only to users allowed to review and merge proposals. Other proposal states are not included. Configuration editing permission does not automatically grant proposal review permission.', 'smartcloud-agent-composer'))),
		);
		return array_map(static fn(array $topic): array => array('id' => $topic[0], 'title' => $topic[1], 'summary' => $topic[2], 'paragraphs' => $topic[3]), $topics);
	}

	private function editingRevision(array $sets): string {
		return hash('sha256', (string) wp_json_encode($sets));
	}

	public function editingSurface(): array|\WP_Error {
		if (!current_user_can(Activation::CAP_VIEW_STATUS)) return new \WP_Error('composer_surface_forbidden', __('You cannot view Composer status.', 'smartcloud-agent-composer'), array('status' => 403));
		$status = $this->editingRequest('GET', 'status');
		if (is_wp_error($status)) return $status;
		$can_edit = current_user_can(Activation::CAP_EDIT_CONFIG);
		$sets = $can_edit ? $this->editingRequest('GET', 'config-sets') : array('items' => array(), 'active' => $status['active_config_set'] ?? '');
		if (is_wp_error($sets)) return $sets;
		$active = (string) ($sets['active'] ?? '');
		$items = $sets['items'] ?? array();
		$drafts = array_values(array_filter($items, static fn(array $set): bool => ($set['config_set'] ?? '') !== $active && empty($set['active'])));
		$configured = $active !== '' || count($items) > 0;
		$metrics = array(array('label' => __('Active configuration', 'smartcloud-agent-composer'), 'help_id' => 'active-configuration', 'value' => $active ?: __('None', 'smartcloud-agent-composer')), array('label' => __('Editing boundary', 'smartcloud-agent-composer'), 'help_id' => 'editing-boundary', 'value' => __('Agent-owned drafts; human review before publication', 'smartcloud-agent-composer')));
		if ($can_edit) {
			$metrics[] = array('label' => __('Inactive configurations', 'smartcloud-agent-composer'), 'help_id' => 'active-configuration', 'value' => count($drafts));
			foreach ($items as $set) if (($set['config_set'] ?? '') === $active) {
				$metrics[] = array('label' => __('Active configuration name', 'smartcloud-agent-composer'), 'help_id' => 'active-configuration', 'value' => (string) $set['label']);
				$metrics[] = array('label' => __('Active configuration entities', 'smartcloud-agent-composer'), 'help_id' => 'active-configuration', 'value' => (int) ($set['entity_count'] ?? 0));
			}
		}
		if (current_user_can(Activation::CAP_MERGE_PROPOSALS)) {
			$proposals = $this->editingRequest('GET', 'content-proposals', array('states' => 'ready-for-review', 'per_page' => 5));
			if (!is_wp_error($proposals)) $metrics[] = array('label' => __('Awaiting human review', 'smartcloud-agent-composer'), 'help_id' => 'proposal-review', 'value' => (int) ($proposals['total'] ?? 0));
		}
		$actions = array();
		if (!$configured && $can_edit) {
			$options = $this->editingPresetOptions();
			if (is_wp_error($options)) return $options;
			if ($options) $actions[] = array('id' => 'start-preset', 'label' => __('Start from a preset', 'smartcloud-agent-composer'), 'fields' => array(array('id' => 'preset', 'help_id' => 'preset-start', 'label' => __('Preset', 'smartcloud-agent-composer'), 'type' => 'select', 'required' => true, 'description' => __('Create an inactive starting configuration to review, validate and activate in the detailed settings.', 'smartcloud-agent-composer'), 'options' => $options)));
		}
		$advancedLink = array('label' => $can_edit ? __('Edit in Advanced', 'smartcloud-agent-composer') : __('View in Advanced', 'smartcloud-agent-composer'), 'url' => admin_url('admin.php?page=smartcloud-agent-composer' . ($can_edit ? '&section=configuration' : '')));
		$links = array($advancedLink);
		if (current_user_can(Activation::CAP_MERGE_PROPOSALS)) $links[] = array('label' => __('Content proposals', 'smartcloud-agent-composer'), 'url' => admin_url('admin.php?page=smartcloud-agent-composer&section=proposals'));
		$surface = array('schema_version' => 1, 'configured' => $configured, 'can_edit' => $can_edit || current_user_can(Activation::CAP_VALIDATE_CONFIG), 'revision' => $this->editingRevision($sets),
			'status' => array('state' => $active !== '' ? 'ready' : 'needs_setup', 'label' => $active !== '' ? __('Configuration active', 'smartcloud-agent-composer') : ($configured ? __('Configuration available', 'smartcloud-agent-composer') : ($can_edit ? __('Choose a preset', 'smartcloud-agent-composer') : __('No active configuration', 'smartcloud-agent-composer')))),
			'notice' => $configured ? __('Review and edit configuration in Advanced.', 'smartcloud-agent-composer') : ($can_edit ? __('Start from an available preset, then review its configuration in Advanced.', 'smartcloud-agent-composer') : __('Ask a configuration administrator to review the site setup.', 'smartcloud-agent-composer')),
			'introduction' => array(
                'title' => __('Getting started with AI Editing', 'smartcloud-agent-composer'),
                'description' => __('Agent Composer governs what an authenticated AI client can create and change on this WordPress site.', 'smartcloud-agent-composer'),
                'items' => array(
                    array('title' => __('Prepare your editing rules', 'smartcloud-agent-composer'), 'description' => __('Start from an available preset if you have no configuration. It creates an inactive configuration: review its site contract and page blueprints, validate it and explicitly activate it in the detailed settings.', 'smartcloud-agent-composer')),
                    array('title' => __('Connect an agent client', 'smartcloud-agent-composer'), 'description' => __('Install the WordPress MCP Adapter and connect a compatible MCP client using a dedicated agent account. Review MCP access in the detailed settings. A WP Suite subscription is not required; your chosen AI service may have its own costs.', 'smartcloud-agent-composer')),
                    array('title' => __('Review governed drafts', 'smartcloud-agent-composer'), 'description' => __('Agents load the blueprint and design context, choose approved patterns, validate changes and create drafts for human review. An active configuration does not connect an agent or automatically publish content.', 'smartcloud-agent-composer')),
                    array('title' => __('Inspect theme and providers', 'smartcloud-agent-composer'), 'description' => __('Theme & providers shows the current theme, blocks, patterns and provider capabilities. Rescan site saves a discovery snapshot and audit record, useful after theme or plugin changes; it does not repair, validate or activate a configuration.', 'smartcloud-agent-composer')),
                ),
            ),
            'help' => $this->editingHelp(), 'metrics' => $metrics, 'values' => array(), 'steps' => array(),
			'actions' => $actions, 'links' => $links);
		if ($configured) $surface['primary_link'] = $advancedLink;
		return $surface;
	}

	private function editingPresetOptions(): array|\WP_Error {
		$presets = $this->editingRequest('GET', 'presets');
		if (is_wp_error($presets)) return $presets;
		return array_map(static fn(array $preset): array => array('value' => $preset['id'], 'label' => $preset['label']), array_values(array_filter($presets['items'] ?? array(), static fn(array $preset): bool => !empty($preset['available']))));
	}

	/** Legacy direct callers cannot use the retired simplified editor. */
	public function saveEditingSurface(array $values, string $revision): \WP_Error {
		return new \WP_Error('composer_surface_read_only', __('Edit Composer configuration in Advanced.', 'smartcloud-agent-composer'), array('status' => 403));
	}

	public function editingSurfaceAction(string $action, array $input): array|\WP_Error {
		if (!current_user_can(Activation::CAP_VIEW_STATUS)) return new \WP_Error('composer_surface_forbidden', __('You cannot perform this Composer operation.', 'smartcloud-agent-composer'), array('status' => 403));
		if ($action === 'rescan-site') {
			if (!current_user_can(Activation::CAP_VALIDATE_CONFIG)) return new \WP_Error('composer_surface_forbidden', __('You cannot rescan this site.', 'smartcloud-agent-composer'), array('status' => 403));
			if ($input) return new \WP_Error('composer_surface_invalid', __('Rescan does not accept configuration changes.', 'smartcloud-agent-composer'), array('status' => 400));
			$result = $this->editingRequest('POST', 'discovery');
			if (is_wp_error($result)) return $result;
			return array('message' => __('Site discovery snapshot refreshed. Configuration and content are unchanged.', 'smartcloud-agent-composer'));
		}
		if ($action !== 'start-preset' || !current_user_can(Activation::CAP_EDIT_CONFIG)) return new \WP_Error('composer_surface_forbidden', __('You cannot perform this Composer operation.', 'smartcloud-agent-composer'), array('status' => 403));
		if (array_diff(array_keys($input), array('preset')) || !is_string($input['preset'] ?? null) || !preg_match('/^[a-z0-9_-]+$/D', $input['preset'])) return new \WP_Error('composer_surface_invalid', __('Choose an available preset.', 'smartcloud-agent-composer'), array('status' => 400));
		$sets = $this->editingRequest('GET', 'config-sets');
		if (is_wp_error($sets)) return $sets;
		if (!empty($sets['active']) || !empty($sets['items'])) return new \WP_Error('composer_surface_conflict', __('A configuration already exists. Review it in Advanced.', 'smartcloud-agent-composer'), array('status' => 409));
		$options = $this->editingPresetOptions();
		if (is_wp_error($options)) return $options;
		if (!in_array($input['preset'], array_column($options, 'value'), true)) return new \WP_Error('composer_surface_invalid', __('Choose an available preset.', 'smartcloud-agent-composer'), array('status' => 400));
		$result = $this->editingRequest('POST', 'presets/' . rawurlencode($input['preset']) . '/instantiate', array('label' => ''));
		if (is_wp_error($result)) return $result;
		return array('message' => __('Preset configuration created but not activated. Review, validate and activate it in Advanced.', 'smartcloud-agent-composer'), 'redirect' => admin_url('admin.php?page=smartcloud-agent-composer&section=configuration'));
	}
}
