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

// Get novel ID from source URL (Flexible pattern for different sites)
$story_url = get_post_meta($story_id, 'crawler_source_url', true);
echo "Story Source URL: $story_url\n";

// Find all chapters linked to this story ID
// We search by the link meta matching the story ID
$chapters = get_posts(array(
    'post_type' => 'fcn_chapter',
    'posts_per_page' => -1,
    'meta_query' => array(
        'relation' => 'OR',
        array(
            'key' => 'fictioneer_chapter_story',
            'value' => $story_id,
            'compare' => '='
        ),
        // Fallback: Try to match by source URL content if possible (commented out to be safe)
        // array('key' => 'crawler_source_url', 'value' => '...', 'compare' => 'LIKE')
    ),
    'orderby' => 'ID',
    'order' => 'ASC'
));

echo "Found " . count($chapters) . " chapters linked to Story ID $story_id\n";

if (empty($chapters)) {
    echo "Warning: No chapters found linked to this story ID via meta 'fictioneer_chapter_story'.\n";
    echo "Attempting to find by crawler_source_url match...\n";
    
    // Attempt fallback logic if parsing allows
    // For ttkan.co/novel/chapters/ID -> chapters match?
    // This is tricky without exact pattern. 
    // Let's rely on manual fixing or re-crawling for now.
    die("No chapters found! Try re-crawling to auto-link them.\n");
}

// Build new chapter list and sort by chapter number
usort($chapters, function($a, $b) {
    $a_num = get_post_meta($a->ID, 'fictioneer_chapter_number', true);
    $b_num = get_post_meta($b->ID, 'fictioneer_chapter_number', true);
    
    // Fallback if meta missing
    if (!$a_num && preg_match('/Chapter\s+(\d+)/i', $a->post_title, $m)) $a_num = $m[1];
    if (!$b_num && preg_match('/Chapter\s+(\d+)/i', $b->post_title, $m)) $b_num = $m[1];
    
    return (int)$a_num - (int)$b_num;
});

$chapter_ids = array();
foreach ($chapters as $chapter) {
    $chapter_ids[] = $chapter->ID;
    
    // Ensure the association is firm
    update_post_meta($chapter->ID, 'fictioneer_chapter_story', intval($story_id));
    
    
    $ch_num = get_post_meta($chapter->ID, 'fictioneer_chapter_number', true);
    echo "  ✓ Chapter {$ch_num} (ID:{$chapter->ID}): {$chapter->post_title}\n";
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
