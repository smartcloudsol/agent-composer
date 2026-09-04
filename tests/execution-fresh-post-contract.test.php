<?php

declare(strict_types=1);

namespace {
	final class WP_Post {
		public int $ID;
		public string $post_content;
		public string $post_modified_gmt;

		public function __construct(object $row) {
			$this->ID = (int) $row->ID;
			$this->post_content = (string) $row->post_content;
			$this->post_modified_gmt = (string) $row->post_modified_gmt;
		}
	}

	final class Fresh_Post_Wpdb_Stub {
		public string $posts = 'wp_posts';

		public function prepare(string $sql, int $post_id): array {
			return array($sql, $post_id);
		}

		public function get_row(array $prepared): object {
			return (object) array(
				'ID' => $prepared[1],
				'post_content' => 'committed-content',
				'post_modified_gmt' => '2026-09-04 12:10:39',
			);
		}
	}

	$wpdb = new Fresh_Post_Wpdb_Stub();
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	$cleaned_post_ids = array();

	function clean_post_cache(int $post_id): void {
		global $cleaned_post_ids;
		$cleaned_post_ids[] = $post_id;
	}

	require_once dirname(__DIR__) . '/src/Execution/Draft_Service.php';
	require_once dirname(__DIR__) . '/src/Execution/Content_Proposal_Service.php';

	$assert = static function (bool $condition, string $message): void {
		if (!$condition) {
			throw new RuntimeException($message);
		}
	};

	foreach (array(Draft_Service::class, Content_Proposal_Service::class) as $service_class) {
		$service = (new \ReflectionClass($service_class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod($service_class, 'fresh_post');
		$method->setAccessible(true);
		$post = $method->invoke($service, 16441);
		$assert($post instanceof \WP_Post, $service_class . ' must hydrate a WP_Post from the committed row.');
		$assert('committed-content' === $post->post_content, $service_class . ' must not return a stale cached post body.');
	}

	$assert(array(16441, 16441) === $cleaned_post_ids, 'Each committed-row read must invalidate the local WordPress post cache first.');

	echo "fresh-post-contract: ok\n";
}
