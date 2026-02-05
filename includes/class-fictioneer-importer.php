<?php
/**
 * Fictioneer Importer - Imports novels into Fictioneer theme
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fictioneer_Importer {
    
    /**
     * Import novel as story
     * 
     * @param array $novel Novel data
     * @return int|false Story post ID or false on failure
     */
    public static function import_novel($novel) {
        if (empty($novel['title'])) {
            Fictioneer_Crawler_Logger::error('Cannot import novel without title');
            return false;
        }
        
        // Check if story already exists
        $existing = self::find_existing_story($novel['title']);
        if ($existing) {
            Fictioneer_Crawler_Logger::info('Story already exists', array('post_id' => $existing));
            return $existing;
        }
        
        // Prepare post data
        $post_data = array(
            'post_title' => $novel['title'],
            'post_content' => $novel['description'],
            'post_status' => 'publish',
            'post_type' => 'fcn_story',
            'post_author' => get_current_user_id()
        );
        
        // Insert story
        $story_id = wp_insert_post($post_data);
        
        if (is_wp_error($story_id)) {
            Fictioneer_Crawler_Logger::error('Failed to create story', array(
                'error' => $story_id->get_error_message()
            ));
            return false;
        }
        
        // Set author meta
        if (!empty($novel['author'])) {
            update_post_meta($story_id, 'fictioneer_story_author', sanitize_text_field($novel['author']));
        }
        
        // Set status meta
        if (!empty($novel['status'])) {
            $status = (strpos($novel['status'], '完') !== false) ? 'Completed' : 'Ongoing';
            update_post_meta($story_id, 'fictioneer_story_status', $status);
        }
        
        // Import and set featured image
        if (!empty($novel['cover']) && get_option('crawler_import_featured_image', true)) {
            $image_id = self::import_image($novel['cover'], $story_id);
            if ($image_id) {
                set_post_thumbnail($story_id, $image_id);
            }
        }
        
        // Set taxonomies if category exists
        if (!empty($novel['category'])) {
            wp_set_object_terms($story_id, $novel['category'], 'fcn_genre');
        }
        
        // Add source URL as meta
        if (!empty($novel['source_url'])) {
            update_post_meta($story_id, '_crawler_source_url', esc_url_raw($novel['source_url']));
        }
        
        Fictioneer_Crawler_Logger::info('Story imported successfully', array(
            'story_id' => $story_id,
            'title' => $novel['title']
        ));
        
        return $story_id;
    }
    
    /**
     * Import chapter
     * 
     * @param int $story_id Story post ID
     * @param array $chapter Chapter data
     * @return int|false Chapter post ID or false on failure
     */
    public static function import_chapter($story_id, $chapter) {
        if (empty($chapter['title']) || empty($chapter['content'])) {
            Fictioneer_Crawler_Logger::error('Cannot import chapter without title or content');
            return false;
        }
        
        // Check if chapter already exists
        $existing = self::find_existing_chapter($story_id, $chapter['title']);
        if ($existing) {
            Fictioneer_Crawler_Logger::info('Chapter already exists', array('post_id' => $existing));
            return $existing;
        }
        
        // Prepare post data
        $post_data = array(
            'post_title' => $chapter['title'],
            'post_content' => $chapter['content'],
            'post_status' => 'publish',
            'post_type' => 'fcn_chapter',
            'post_author' => get_current_user_id(),
            'menu_order' => isset($chapter['order']) ? $chapter['order'] : 0
        );
        
        // Insert chapter
        $chapter_id = wp_insert_post($post_data);
        
        if (is_wp_error($chapter_id)) {
            Fictioneer_Crawler_Logger::error('Failed to create chapter', array(
                'error' => $chapter_id->get_error_message()
            ));
            return false;
        }
        
        // Link chapter to story
        update_post_meta($chapter_id, 'fictioneer_chapter_story', $story_id);
        
        // Add chapter to story's chapter list
        $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
        if (!is_array($story_chapters)) {
            $story_chapters = array();
        }
        
        if (!in_array($chapter_id, $story_chapters)) {
            $story_chapters[] = $chapter_id;
            update_post_meta($story_id, 'fictioneer_story_chapters', $story_chapters);
        }
        
        // Add source URL as meta
        if (!empty($chapter['source_url'])) {
            update_post_meta($chapter_id, '_crawler_source_url', esc_url_raw($chapter['source_url']));
        }
        
        // Calculate word count
        $word_count = self::count_words($chapter['content']);
        update_post_meta($chapter_id, '_word_count', $word_count);
        
        Fictioneer_Crawler_Logger::info('Chapter imported successfully', array(
            'chapter_id' => $chapter_id,
            'story_id' => $story_id,
            'title' => $chapter['title']
        ));
        
        return $chapter_id;
    }
    
    /**
     * Find existing story by title
     * 
     * @param string $title Story title
     * @return int|false Story ID or false
     */
    private static function find_existing_story($title) {
        $args = array(
            'post_type' => 'fcn_story',
            'post_status' => 'any',
            'title' => $title,
            'posts_per_page' => 1,
            'fields' => 'ids'
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        
        return false;
    }
    
    /**
     * Find existing chapter by title and story
     * 
     * @param int $story_id Story ID
     * @param string $title Chapter title
     * @return int|false Chapter ID or false
     */
    private static function find_existing_chapter($story_id, $title) {
        $args = array(
            'post_type' => 'fcn_chapter',
            'post_status' => 'any',
            'title' => $title,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => 'fictioneer_chapter_story',
                    'value' => $story_id
                )
            )
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        
        return false;
    }
    
    /**
     * Import image from URL
     * 
     * @param string $image_url Image URL
     * @param int $post_id Post ID to attach to
     * @return int|false Attachment ID or false
     */
    private static function import_image($image_url, $post_id = 0) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Download image
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            Fictioneer_Crawler_Logger::error('Failed to download image', array(
                'url' => $image_url,
                'error' => $tmp->get_error_message()
            ));
            return false;
        }
        
        // Get file info
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );
        
        // Handle image
        $id = media_handle_sideload($file_array, $post_id);
        
        // Clean up temp file
        @unlink($tmp);
        
        if (is_wp_error($id)) {
            Fictioneer_Crawler_Logger::error('Failed to import image', array(
                'url' => $image_url,
                'error' => $id->get_error_message()
            ));
            return false;
        }
        
        return $id;
    }
    
    /**
     * Count words in content
     * 
     * @param string $content Content
     * @return int Word count
     */
    private static function count_words($content) {
        // Strip HTML tags
        $text = strip_tags($content);
        
        // Check if content is primarily Chinese
        $chinese_chars = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text);
        
        if ($chinese_chars > 100) {
            // Count Chinese characters
            return mb_strlen(preg_replace('/[^\x{4e00}-\x{9fa5}]/u', '', $text));
        } else {
            // Count English words
            return str_word_count($text);
        }
    }
    
    /**
     * Update story statistics after importing chapters
     * 
     * @param int $story_id Story ID
     */
    public static function update_story_stats($story_id) {
        $chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
        
        if (!is_array($chapters) || empty($chapters)) {
            return;
        }
        
        $total_words = 0;
        
        foreach ($chapters as $chapter_id) {
            $word_count = get_post_meta($chapter_id, '_word_count', true);
            if ($word_count) {
                $total_words += (int) $word_count;
            }
        }
        
        // Update story word count
        update_post_meta($story_id, 'fictioneer_story_word_count', $total_words);
        
        Fictioneer_Crawler_Logger::info('Story stats updated', array(
            'story_id' => $story_id,
            'total_words' => $total_words,
            'chapter_count' => count($chapters)
        ));
    }
}
