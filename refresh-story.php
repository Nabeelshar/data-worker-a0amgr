<?php
/**
 * Quick script to refresh a story's cache and trigger hooks
 * Usage: php refresh-story.php STORY_ID
 */

if ( php_sapi_name() !== 'cli' ) { die('Access denied'); }

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (empty($argv[1])) {
    die("Usage: php refresh-story.php STORY_ID\n");
}

$story_id = intval($argv[1]);

echo "Refreshing story ID: $story_id\n";

// Clear WordPress caches
clean_post_cache($story_id);
wp_cache_delete($story_id, 'posts');
wp_cache_delete($story_id, 'post_meta');

// Get story post
$story_post = get_post($story_id);

if (!$story_post) {
    die("Error: Story not found!\n");
}

echo "Story found: {$story_post->post_title}\n";

// Get chapter count
$chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
if (is_array($chapters)) {
    echo "Chapters in meta: " . count($chapters) . "\n";
} else {
    echo "No chapters array found in meta\n";
}

// Trigger hooks to rebuild
do_action('save_post_fcn_story', $story_id, $story_post, true);
do_action('save_post', $story_id, $story_post, true);
do_action('fictioneer_cache_purge_post', $story_id);

echo "Hooks triggered!\n";

// Clear cache again after hooks
clean_post_cache($story_id);
wp_cache_flush();

echo "Cache cleared!\n";
echo "Done! Check story page: " . get_permalink($story_id) . "\n";
