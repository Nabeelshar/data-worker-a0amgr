<?php
/**
 * Repair script to rebuild story chapter list from existing chapters
 * Usage: php repair-story-chapters.php STORY_ID
 */

if ( php_sapi_name() !== 'cli' ) { die('Access denied'); }

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (empty($argv[1])) {
    die("Usage: php repair-story-chapters.php STORY_ID\n");
}

$story_id = intval($argv[1]);

echo "Repairing story ID: $story_id\n";

// Get story
$story = get_post($story_id);
if (!$story) {
    die("Error: Story not found!\n");
}

echo "Story: {$story->post_title}\n";

// Get novel ID from source URL
$story_url = get_post_meta($story_id, 'crawler_source_url', true);
if (!$story_url || !preg_match('/books\/(\d+)/', $story_url, $matches)) {
    die("Error: Could not extract novel ID from story URL: $story_url\n");
}

$novel_id = $matches[1];
echo "Novel ID: $novel_id\n\n";

// Find all chapters by exact novel ID match in source URL
$chapters = get_posts(array(
    'post_type' => 'fcn_chapter',
    'posts_per_page' => -1,
    'meta_query' => array(
        array(
            'key' => 'crawler_source_url',
            'value' => "books/{$novel_id}/",
            'compare' => 'LIKE'
        )
    ),
    'orderby' => 'ID',
    'order' => 'ASC'
));

echo "Found " . count($chapters) . " chapters\n";

if (empty($chapters)) {
    die("No chapters found for this story!\n");
}

// Build new chapter list
$chapter_ids = array();
foreach ($chapters as $chapter) {
    $chapter_ids[] = $chapter->ID;
    
    // Fix the association
    update_post_meta($chapter->ID, 'fictioneer_chapter_story', intval($story_id));
    update_post_meta($chapter->ID, '_test_story_id', intval($story_id));
    
    echo "  ✓ Chapter {$chapter->ID}: {$chapter->post_title}\n";
}

// Update story meta
update_post_meta($story_id, 'fictioneer_story_chapters', $chapter_ids);
update_post_meta($story_id, 'crawler_chapters_crawled', count($chapter_ids));
update_post_meta($story_id, 'crawler_chapters_total', count($chapter_ids));

echo "\n✓ Updated story with " . count($chapter_ids) . " chapters\n";

// Clear caches
clean_post_cache($story_id);
wp_cache_delete($story_id, 'posts');
wp_cache_delete($story_id, 'post_meta');

foreach ($chapter_ids as $chapter_id) {
    clean_post_cache($chapter_id);
}

// Trigger hooks
$story_post = get_post($story_id);
do_action('save_post_fcn_story', $story_id, $story_post, true);
do_action('save_post', $story_id, $story_post, true);
do_action('fictioneer_cache_purge_post', $story_id);

wp_cache_flush();

echo "✓ Cache cleared and hooks triggered\n";
echo "\nDone! Visit: " . get_permalink($story_id) . "\n";
