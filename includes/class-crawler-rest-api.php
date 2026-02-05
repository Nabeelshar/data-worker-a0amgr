<?php
/**
 * REST API endpoints for external crawler
 * 
 * Provides endpoints to create stories and chapters from external scripts
 */

class Fictioneer_Crawler_Rest_API {
    
    private $batch_cache_clear = array(); // Track posts to clear cache after batch
    private $batch_in_progress = false; // Flag to defer cache clearing
    private $batch_new_chapters = array(); // Track new chapters created in batch

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Create story endpoint
        register_rest_route('crawler/v1', '/story', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_story'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'url' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_url',
                ),
                'title' => array(
                    'required' => false,  // Changed to false for updates
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'title_zh' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'author' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'description' => array(
                    'required' => false,
                    'type' => 'string',
                ),
                'cover_url' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_url',
                ),
                'genres' => array(
                    'required' => false,
                    'type' => 'array',
                    'items' => array(
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'tags' => array(
                    'required' => false,
                    'type' => 'array',
                    'items' => array(
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
                'glossary' => array(
                    'required' => false,
                    'type' => 'object',  // JSON object/array
                ),
            ),
        ));
        
        // Create chapter endpoint
        register_rest_route('crawler/v1', '/chapter', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_chapter'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'url' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_url',
                ),
                'story_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint',
                ),
                'title' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'title_zh' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'content' => array(
                    'required' => true,
                    'type' => 'string',
                ),
                'chapter_number' => array(
                    'required' => false,
                    'type' => 'integer',
                ),
            ),
        ));
        
        // BULK: Create multiple chapters endpoint (OPTIMIZATION)
        register_rest_route('crawler/v1', '/chapters/bulk', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_chapters_bulk'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'chapters' => array(
                    'required' => true,
                    'type' => 'array',
                ),
            ),
        ));
        
        // Health check endpoint
        register_rest_route('crawler/v1', '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health_check'),
            'permission_callback' => '__return_true',
        ));
        
        // Check if chapter exists endpoint (OPTIMIZATION)
        register_rest_route('crawler/v1', '/chapter/exists', array(
            'methods' => 'GET',
            'callback' => array($this, 'check_chapter_exists'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'story_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'chapter_number' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
        
        // Get story chapter status - bulk check (SUPER OPTIMIZATION)
        register_rest_route('crawler/v1', '/story/(?P<id>\d+)/chapters', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_story_chapter_status'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'total_chapters' => array(
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
        
        // Debug endpoint to check story-chapter associations
        register_rest_route('crawler/v1', '/story/(?P<id>\d+)/debug', array(
            'methods' => 'GET',
            'callback' => array($this, 'debug_story'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Get current job endpoint
        register_rest_route('crawler/v1', '/job', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_current_job'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // Update job status endpoint
        register_rest_route('crawler/v1', '/job/status', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_job_status'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'status' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'message' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }
    
    /**
     * Check permission - requires API key
     */
    public function check_permission($request) {
        // Get API key from header or query parameter
        $api_key = $request->get_header('X-API-Key');
        
        if (empty($api_key)) {
            $api_key = $request->get_param('api_key');
        }
        
        if (empty($api_key)) {
            return new WP_Error('rest_forbidden', 'API key required. Provide X-API-Key header or api_key parameter.', array('status' => 401));
        }
        
        // Get stored API key from WordPress options
        $stored_key = get_option('fictioneer_crawler_api_key');
        
        // Generate key if it doesn't exist
        if (empty($stored_key)) {
            $stored_key = wp_generate_password(32, false);
            update_option('fictioneer_crawler_api_key', $stored_key);
        }
        
        // Verify API key
        if (!hash_equals($stored_key, $api_key)) {
            return new WP_Error('rest_forbidden', 'Invalid API key', array('status' => 403));
        }
        
        return true;
    }
    
    /**
     * Create story from crawler data
     */
    public function create_story($request) {
        $url = $request->get_param('url');
        $title = $request->get_param('title');
        $title_zh = $request->get_param('title_zh');
        $author = $request->get_param('author');
        $description = $request->get_param('description');
        $cover_url = $request->get_param('cover_url');
        
        // Check if story already exists
        $existing = get_posts(array(
            'post_type' => 'fcn_story',
            'meta_key' => 'crawler_source_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
        ));
        
        // Fallback: Check by title (to avoid duplicates when switching sources)
        if (empty($existing)) {
            // Search for posts with the same title
            // We use 's' (search) because querying by exact post_title isn't directly supported in get_posts
            // Then we verify the exact title match in the loop
            $existing_by_title = get_posts(array(
                'post_type' => 'fcn_story',
                's' => $title,
                'posts_per_page' => 5,
                'post_status' => 'any',
            ));
            
            foreach ($existing_by_title as $post) {
                if ($post->post_title === $title) {
                    $existing = array($post);
                    // Update the source URL to the new one so we find it by URL next time
                    update_post_meta($post->ID, 'crawler_source_url', $url);
                    break;
                }
            }
        }
        
        if (!empty($existing)) {
            $story_id = $existing[0]->ID;
            
            // STORY EXISTS: Update Title/Description if provided (fixes translation updates)
            $update_data = array('ID' => $story_id);
            $updated = false;
            
            if (!empty($title) && $title !== $existing[0]->post_title) {
                $update_data['post_title'] = $title;
                $updated = true;
            }
            
            if (!empty($description) && $description !== $existing[0]->post_content) {
                $update_data['post_content'] = $description;
                $updated = true;
            }
            
            if ($updated) {
                wp_update_post($update_data);
                if (!$this->batch_in_progress) {
                    clean_post_cache($story_id);
                }
            }
            
            // Update other meta regardless
            if ($author) update_post_meta($story_id, 'fictioneer_story_author', $author);
            if ($cover_url) $this->set_story_cover($story_id, $cover_url);
            
            // Update taxonomies
            $genres = $request->get_param('genres');
            if (!empty($genres) && is_array($genres)) {
                wp_set_object_terms($story_id, $genres, 'fcn_genre');
            }
            
            $tags = $request->get_param('tags');
            if (!empty($tags) && is_array($tags)) {
                wp_set_object_terms($story_id, $tags, 'post_tag');
            }

            // Update Glossary (Sync from Crawler)
            $glossary = $request->get_param('glossary');
            if (!empty($glossary)) {
                // Ensure it's stored as JSON string
                if (is_array($glossary) || is_object($glossary)) {
                    $glossary = json_encode($glossary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }
                update_post_meta($story_id, '_crawler_glossary', $glossary);
            }

            // Story exists - no need to trigger full hooks
            return array(
                'success' => true,
                'story_id' => $story_id,
                'message' => 'Story updated',
                'existed' => true,
            );
        }
        
        // Create story post
        $story_data = array(
            'post_type' => 'fcn_story',
            'post_title' => $title,
            'post_content' => $description ?: '',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        );
        
        $story_id = wp_insert_post($story_data);
        
        if (is_wp_error($story_id)) {
            return new WP_Error('story_creation_failed', $story_id->get_error_message(), array('status' => 500));
        }
        
        // Defer cache clearing if in batch mode
        if (!$this->batch_in_progress) {
            clean_post_cache($story_id);
            // Only trigger cache purge for external caches, not WordPress hooks yet
            do_action('fictioneer_cache_purge_post', $story_id);
        } else {
            $this->batch_cache_clear['stories'][] = $story_id;
        }
        
        // Store metadata
        update_post_meta($story_id, 'crawler_source_url', $url);
        
        if ($title_zh) {
            update_post_meta($story_id, 'fictioneer_story_title_original', $title_zh);
        }
        
        if ($author) {
            update_post_meta($story_id, 'fictioneer_story_author', $author);
        }
        
        // Set default story status
        update_post_meta($story_id, 'fictioneer_story_status', 'Ongoing');
        
        // Initialize crawler progress tracking
        update_post_meta($story_id, 'crawler_chapters_crawled', 0);
        update_post_meta($story_id, 'crawler_chapters_total', 0);
        update_post_meta($story_id, 'crawler_last_chapter', 0);
        
        // Download and set cover image if provided
        if ($cover_url) {
            $this->set_story_cover($story_id, $cover_url);
        }
        
        // Set taxonomies
        $genres = $request->get_param('genres');
        if (!empty($genres) && is_array($genres)) {
            wp_set_object_terms($story_id, $genres, 'fcn_genre');
        }
        
        $tags = $request->get_param('tags');
        if (!empty($tags) && is_array($tags)) {
            wp_set_object_terms($story_id, $tags, 'post_tag');
        }

        // Update Glossary (Sync from Crawler for new stories)
        $glossary = $request->get_param('glossary');
        if (!empty($glossary)) {
            if (is_array($glossary) || is_object($glossary)) {
                $glossary = json_encode($glossary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            update_post_meta($story_id, '_crawler_glossary', $glossary);
        }

        // Log activity
        $this->log_activity('Story created', array(
            'story_id' => $story_id,
            'title' => $title,
            'url' => $url,
        ));
        
        return array(
            'success' => true,
            'story_id' => $story_id,
            'message' => 'Story created successfully',
            'existed' => false,
        );
    }
    
    /**
     * Create chapter from crawler data
     */
    public function create_chapter($request) {
        $url = $request->get_param('url');
        $story_id = $request->get_param('story_id');
        $title = $request->get_param('title');
        $title_zh = $request->get_param('title_zh');
        $content = $request->get_param('content');
        $chapter_number = $request->get_param('chapter_number');
        
        // Debug logging
        $this->log_activity('Chapter create called', array(
            'story_id_received' => $story_id,
            'story_id_type' => gettype($story_id),
            'story_id_intval' => intval($story_id),
        ));
        
        // Verify story exists
        $story = get_post($story_id);
        if (!$story || $story->post_type !== 'fcn_story') {
            return new WP_Error('invalid_story', 'Story not found', array('status' => 404));
        }
        
        // Check if chapter already exists
        $existing = get_posts(array(
            'post_type' => 'fcn_chapter',
            'meta_key' => 'crawler_source_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
        ));
        
        if (!empty($existing)) {
            $chapter_id = $existing[0]->ID;
            
            // Update associations even for existing chapters
            update_post_meta($chapter_id, 'fictioneer_chapter_story', intval($story_id));
            update_post_meta($chapter_id, '_test_story_id', intval($story_id)); // Add working field too
            
            // Skip cache clearing for existing chapters in batch mode
            if (!$this->batch_in_progress) {
                clean_post_cache($chapter_id);
                do_action('fictioneer_cache_purge_post', $chapter_id);
            } else {
                $this->batch_cache_clear['chapters'][] = $chapter_id;
            }
            
            // CRITICAL: Add to story's chapter list if not already there
            $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
            if (!is_array($story_chapters)) {
                $story_chapters = array();
            }
            
            if (!in_array($chapter_id, $story_chapters)) {
                // If in batch mode, don't update meta yet to prevent race conditions/cache issues
                if ($this->batch_in_progress) {
                    if (!isset($this->batch_new_chapters[$story_id])) {
                         $this->batch_new_chapters[$story_id] = array();
                    }
                    if (!in_array($chapter_id, $this->batch_new_chapters[$story_id])) {
                         $this->batch_new_chapters[$story_id][] = $chapter_id;
                    }
                } else {
                    $story_chapters[] = $chapter_id;
                    update_post_meta($story_id, 'fictioneer_story_chapters', $story_chapters);
                }
                
                // Update crawler progress tracking (SAFE to update individually as these are simple ints)
                $chapters_crawled = (int) get_post_meta($story_id, 'crawler_chapters_crawled', true);
                $chapters_crawled++;
                update_post_meta($story_id, 'crawler_chapters_crawled', $chapters_crawled);
                if ($chapter_number) {
                    update_post_meta($story_id, 'crawler_last_chapter', $chapter_number);
                }
                
                // Defer all cache/hook operations in batch mode
                if (!$this->batch_in_progress) {
                    clean_post_cache($story_id);
                    $story_post = get_post($story_id);
                    do_action('save_post_fcn_story', $story_id, $story_post, true);
                    do_action('fictioneer_cache_purge_post', $story_id);
                } else {
                    $this->batch_cache_clear['stories'][] = $story_id;
                }
            }
            
            return array(
                'success' => true,
                'chapter_id' => $chapter_id,
                'message' => 'Chapter already exists',
                'existed' => true,
            );
        }
        
        // Create chapter post
        $chapter_data = array(
            'post_type' => 'fcn_chapter',
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'meta_input' => array(
                'fictioneer_chapter_story' => intval($story_id),
                'crawler_source_url' => $url,
                'fictioneer_chapter_number' => $chapter_number ? intval($chapter_number) : null,  // OPTIMIZATION: Store chapter number
                'fictioneer_chapter_url' => $url,
            ),
        );
        
        $chapter_id = wp_insert_post($chapter_data);
        
        if (is_wp_error($chapter_id)) {
            return new WP_Error('chapter_creation_failed', $chapter_id->get_error_message(), array('status' => 500));
        }
        
        // Defer cache clearing in batch mode
        if (!$this->batch_in_progress) {
            clean_post_cache($chapter_id);
            // Only trigger hooks if not in batch
            $chapter_post = get_post($chapter_id);
            do_action('save_post_fcn_chapter', $chapter_id, $chapter_post, false);
            do_action('fictioneer_cache_purge_post', $chapter_id);
        } else {
            $this->batch_cache_clear['chapters'][] = $chapter_id;
        }
        
        // Force update meta again after post creation (theme might be overwriting it)
        update_post_meta($chapter_id, 'fictioneer_chapter_story', intval($story_id));
        
        // Use a delayed action to set it again after all hooks have run
        add_action('shutdown', function() use ($chapter_id, $story_id) {
            update_post_meta($chapter_id, 'fictioneer_chapter_story', intval($story_id));
        }, 999);
        
        // Store metadata
        $story_id_int = intval($story_id);
        
        // Test: Save to both the correct key and a test key
        $saved = update_post_meta($chapter_id, 'fictioneer_chapter_story', $story_id_int);
        $saved_test = update_post_meta($chapter_id, '_test_story_id', $story_id_int);
        
        // Immediately read back what was saved
        $verify = get_post_meta($chapter_id, 'fictioneer_chapter_story', true);
        $verify_test = get_post_meta($chapter_id, '_test_story_id', true);
        
        update_post_meta($chapter_id, 'crawler_source_url', $url);
        
        // Log what we're saving
        $this->log_activity('Chapter meta saved', array(
            'chapter_id' => $chapter_id,
            'story_id_sent' => $story_id,
            'story_id_int' => $story_id_int,
            'update_result' => $saved,
            'update_test_result' => $saved_test,
            'verify_value' => $verify,
            'verify_test_value' => $verify_test,
            'verify_type' => gettype($verify),
        ));
        
        if ($title_zh) {
            update_post_meta($chapter_id, 'fictioneer_chapter_title_original', $title_zh);
        }
        
        // OPTIMIZATION: Use theme native function to append chapter if available
        // This ensures cache clearing, Next/Prev links, and word counts are updated correctly
        update_post_meta($chapter_id, '_fictioneer_chapter_appended', true);
        
        $appended_via_function = false;
        if (function_exists('fictioneer_append_chapter_to_story')) {
            fictioneer_append_chapter_to_story($chapter_id, intval($story_id));
            $appended_via_function = true;
        }

        // Fallback or Batch Mode Handling
        // Even if function exists, we might need manual handling if we are in specific batch modes
        // or if the function failed (though it returns void/null usually)
        
        // Append chapter to story's chapter list (avoid duplicates)
        // We still check this manual array for our internal tracking or if function is missing
        if (!$appended_via_function) {
            $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
            if (!is_array($story_chapters)) {
                $story_chapters = array();
            }
            
            // Only add if not already in the list
            if (!in_array($chapter_id, $story_chapters)) {
                // If in batch mode, don't update meta yet to prevent race conditions/cache issues
                if ($this->batch_in_progress) {
                    if (!isset($this->batch_new_chapters[$story_id])) {
                            $this->batch_new_chapters[$story_id] = array();
                    }
                    if (!in_array($chapter_id, $this->batch_new_chapters[$story_id])) {
                            $this->batch_new_chapters[$story_id][] = $chapter_id;
                    }
                } else {
                    $story_chapters[] = $chapter_id;
                    update_post_meta($story_id, 'fictioneer_story_chapters', $story_chapters);
                }
                
                // Update crawler progress tracking
                $chapters_crawled = (int) get_post_meta($story_id, 'crawler_chapters_crawled', true);
                $chapters_crawled++;
                update_post_meta($story_id, 'crawler_chapters_crawled', $chapters_crawled);
                update_post_meta($story_id, 'crawler_last_chapter', $chapter_number);
                
                // Defer cache clearing and hooks in batch mode
                if (!$this->batch_in_progress) {
                    clean_post_cache($story_id);
                    $story_post = get_post($story_id);
                    do_action('save_post_fcn_story', $story_id, $story_post, true);
                    do_action('fictioneer_cache_purge_post', $story_id);
                } else {
                    $this->batch_cache_clear['stories'][] = $story_id;
                }
                
                $this->log_activity('Chapter added to story list', array(
                    'chapter_id' => $chapter_id,
                    'story_id' => $story_id,
                    'total_chapters' => count($story_chapters),
                    'chapters_crawled' => $chapters_crawled,
                ));
            }
        } else {
             // Function handled it, but let's make sure we update our crawler stats
            $chapters_crawled = (int) get_post_meta($story_id, 'crawler_chapters_crawled', true);
            $chapters_crawled++;
            update_post_meta($story_id, 'crawler_chapters_crawled', $chapters_crawled);
            update_post_meta($story_id, 'crawler_last_chapter', $chapter_number);
        }
        
        // Log activity
        $this->log_activity('Chapter created', array(
            'chapter_id' => $chapter_id,
            'story_id' => $story_id,
            'title' => $title,
            'url' => $url,
            'chapter_number' => $chapter_number,
        ));
        
        return array(
            'success' => true,
            'chapter_id' => $chapter_id,
            'message' => 'Chapter created successfully',
            'existed' => false,
        );
    }
    
    /**
     * Create multiple chapters in bulk (OPTIMIZATION)
     * CRITICAL: Processes chapters in sequential order to maintain chapter order
     */
    public function create_chapters_bulk($request) {
        $chapters = $request->get_param('chapters');
        
        if (empty($chapters) || !is_array($chapters)) {
            return new WP_Error('invalid_data', 'Chapters array required', array('status' => 400));
        }
        
        // CRITICAL: Sort chapters by chapter_number to ensure sequential processing
        usort($chapters, function($a, $b) {
            $a_num = isset($a['chapter_number']) ? (int)$a['chapter_number'] : 0;
            $b_num = isset($b['chapter_number']) ? (int)$b['chapter_number'] : 0;
            return $a_num - $b_num;
        });
        
        // Enable batch mode to defer cache clearing
        $this->batch_in_progress = true;
        $this->batch_cache_clear = array('chapters' => array(), 'stories' => array());
        
        $results = array();
        $created_count = 0;
        $existed_count = 0;
        $failed_count = 0;
        
        // Process each chapter IN ORDER (critical for chapter sequence)
        foreach ($chapters as $chapter_data) {
            try {
                // Create a WP_REST_Request object for each chapter
                $chapter_request = new WP_REST_Request('POST', '/crawler/v1/chapter');
                $chapter_request->set_header('X-API-Key', $request->get_header('X-API-Key'));
                
                // Set parameters
                foreach ($chapter_data as $key => $value) {
                    $chapter_request->set_param($key, $value);
                }
                
                // Use existing create_chapter method
                $result = $this->create_chapter($chapter_request);
                
                if (is_wp_error($result)) {
                    $results[] = array(
                        'chapter_number' => $chapter_data['chapter_number'] ?? 0,
                        'success' => false,
                        'error' => $result->get_error_message()
                    );
                    $failed_count++;
                } else {
                    $results[] = array(
                        'chapter_number' => $chapter_data['chapter_number'] ?? 0,
                        'chapter_id' => $result['chapter_id'],
                        'success' => true,
                        'existed' => $result['existed']
                    );
                    
                    if ($result['existed']) {
                        $existed_count++;
                    } else {
                        $created_count++;
                    }
                }
            } catch (Exception $e) {
                $results[] = array(
                    'chapter_number' => $chapter_data['chapter_number'] ?? 0,
                    'success' => false,
                    'error' => $e->getMessage()
                );
                $failed_count++;
            }
        }
        
        // Disable batch mode
        $this->batch_in_progress = false;
        
        // NOW apply batch updates to story chapters (Prevent Race Conditions)
        if (!empty($this->batch_new_chapters)) {
             foreach ($this->batch_new_chapters as $story_id => $new_chapter_ids) {
                  // Re-fetch fresh meta
                  $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
                  if (!is_array($story_chapters)) {
                      $story_chapters = array();
                  }
                  
                  $added = 0;
                  foreach ($new_chapter_ids as $cid) {
                      if (!in_array($cid, $story_chapters)) {
                          $story_chapters[] = $cid;
                          $added++;
                      }
                  }
                  
                  if ($added > 0) {
                      // Sort by chapter number naturally (assuming IDs might not be sequential but we want valid order)
                      // Ideally sort by chapter number logic but append is usually safe for now
                      update_post_meta($story_id, 'fictioneer_story_chapters', $story_chapters);
                  }
             }
             // Clear temp store
             $this->batch_new_chapters = array();
        }

        // Clear caches once for all chapters
        if (!empty($this->batch_cache_clear['chapters'])) {
            foreach (array_unique($this->batch_cache_clear['chapters']) as $chapter_id) {
                clean_post_cache($chapter_id);
                wp_cache_delete($chapter_id, 'posts');
                wp_cache_delete($chapter_id, 'post_meta');
            }
        }
        
        // Clear caches and trigger hooks once per story (not per chapter!)
        if (!empty($this->batch_cache_clear['stories'])) {
            $unique_stories = array_unique($this->batch_cache_clear['stories']);
            foreach ($unique_stories as $story_id) {
                // CRITICAL: Clear cache BEFORE triggering hooks
                // Theme needs clean cache to rebuild chapter relationships
                clean_post_cache($story_id);
                wp_cache_delete($story_id, 'posts');
                wp_cache_delete($story_id, 'post_meta');
                
                $story_post = get_post($story_id);
                if ($story_post) {
                    // Trigger hooks to rebuild story data
                    do_action('save_post_fcn_story', $story_id, $story_post, true);
                    do_action('save_post', $story_id, $story_post, true);
                    
                    // Purge external caches (LiteSpeed, etc.)
                    do_action('fictioneer_cache_purge_post', $story_id);
                }
            }
        }
        
        return array(
            'success' => true,
            'total' => count($chapters),
            'created' => $created_count,
            'existed' => $existed_count,
            'failed' => $failed_count,
            'results' => $results,
        );
    }
    
    /**
     * Health check endpoint
     */
    public function health_check($request) {
        return array(
            'status' => 'ok',
            'timestamp' => current_time('mysql'),
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
        );
    }
    
    /**
     * Check if chapter exists (OPTIMIZATION)
     */
    public function check_chapter_exists($request) {
        $story_id = $request->get_param('story_id');
        $chapter_number = $request->get_param('chapter_number');
        
        // Get all chapters for this story
        $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
        
        if (!is_array($story_chapters)) {
            return array(
                'exists' => false,
                'chapter_id' => null,
            );
        }
        
        // Check each chapter's number
        foreach ($story_chapters as $chapter_id) {
            // First try to get stored chapter number from metadata
            $stored_chapter_number = get_post_meta($chapter_id, 'fictioneer_chapter_number', true);
            
            // Fallback: extract from title
            if (empty($stored_chapter_number)) {
                $chapter_title = get_the_title($chapter_id);
                if (preg_match('/Chapter\s+(\d+)/i', $chapter_title, $matches)) {
                    $stored_chapter_number = (int)$matches[1];
                }
            }
            
            // Fallback: extract from URL
            if (empty($stored_chapter_number)) {
                $source_url = get_post_meta($chapter_id, 'fictioneer_chapter_url', true);
                if (preg_match('/chapter[_-]?(\d+)/i', $source_url, $matches)) {
                    $stored_chapter_number = (int)$matches[1];
                }
            }
            
            if ($stored_chapter_number && (int)$stored_chapter_number === (int)$chapter_number) {
                return array(
                    'exists' => true,
                    'chapter_id' => $chapter_id,
                );
            }
        }
        
        return array(
            'exists' => false,
            'chapter_id' => null,
        );
    }
    
    /**
     * Get story chapter status - bulk check (SUPER OPTIMIZATION)
     */
    public function get_story_chapter_status($request) {
        $story_id = $request->get_param('id');
        $total_chapters = $request->get_param('total_chapters');
        
        // Get all chapters for this story
        $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
        
        if (!is_array($story_chapters)) {
            return array(
                'chapters_count' => 0,
                'is_complete' => false,
                'existing_chapters' => array(),
            );
        }
        
        $chapter_count = count($story_chapters);
        $existing_chapter_numbers = array();
        
        // Extract all chapter numbers
        foreach ($story_chapters as $chapter_id) {
            // First try to get stored chapter number from metadata
            $stored_chapter_number = get_post_meta($chapter_id, 'fictioneer_chapter_number', true);
            
            // Fallback: extract from title
            if (empty($stored_chapter_number)) {
                $chapter_title = get_the_title($chapter_id);
                if (preg_match('/Chapter\s+(\d+)/i', $chapter_title, $matches)) {
                    $stored_chapter_number = (int)$matches[1];
                }
            }
            
            // Fallback: extract from URL
            if (empty($stored_chapter_number)) {
                $source_url = get_post_meta($chapter_id, 'fictioneer_chapter_url', true);
                if (preg_match('/chapter[_-]?(\d+)/i', $source_url, $matches)) {
                    $stored_chapter_number = (int)$matches[1];
                }
            }
            
            if ($stored_chapter_number) {
                $existing_chapter_numbers[] = (int)$stored_chapter_number;
            }
        }
        
        // Check if complete (all chapters exist)
        $is_complete = false;
        if ($total_chapters && $chapter_count >= $total_chapters) {
            $is_complete = true;
        }
        
        return array(
            'chapters_count' => $chapter_count,
            'is_complete' => $is_complete,
            'existing_chapters' => $existing_chapter_numbers,
        );
    }
    
    /**
     * Debug story endpoint - check chapter associations
     */
    public function debug_story($request) {
        $story_id = $request->get_param('id');
        
        $story = get_post($story_id);
        if (!$story || $story->post_type !== 'fcn_story') {
            return new WP_Error('invalid_story', 'Story not found', array('status' => 404));
        }
        
        $story_chapters = get_post_meta($story_id, 'fictioneer_story_chapters', true);
        
        $chapter_details = array();
        if (is_array($story_chapters)) {
            foreach ($story_chapters as $chapter_id) {
                $chapter = get_post($chapter_id);
                $chapter_story_id = get_post_meta($chapter_id, 'fictioneer_chapter_story', true);
                $chapter_story_id_raw = get_post_meta($chapter_id, 'fictioneer_chapter_story', false);
                $chapter_story_id_test = get_post_meta($chapter_id, '_test_story_id', true);
                
                $chapter_details[] = array(
                    'id' => $chapter_id,
                    'title' => $chapter ? $chapter->post_title : 'Not found',
                    'status' => $chapter ? $chapter->post_status : 'N/A',
                    'story_id' => $chapter_story_id,
                    'story_id_raw' => $chapter_story_id_raw,
                    'story_id_test' => $chapter_story_id_test,
                    'story_id_type' => gettype($chapter_story_id),
                    'association_ok' => ($chapter_story_id == $story_id),
                );
            }
        }
        
        return array(
            'story_id' => $story_id,
            'story_title' => $story->post_title,
            'story_content' => $story->post_content, // Description
            'story_author' => get_post_meta($story_id, 'fictioneer_story_author', true),
            'story_status' => $story->post_status,
            'chapters_meta' => $story_chapters,
            'chapters_count' => is_array($story_chapters) ? count($story_chapters) : 0,
            'chapter_details' => $chapter_details,
        );
    }
    
    /**
     * Get current crawler job
     */
    public function get_current_job($request) {
        $job_queue = get_option('fictioneer_crawler_current_job');
        
        if (empty($job_queue)) {
            return array(
                'success' => true,
                'job_available' => false
            );
        }
        
        // Handle queue structure (array of jobs) vs single job
        $current_job = $job_queue;
        if (isset($job_queue[0])) {
            $current_job = $job_queue[0];
        }
        
        return array(
            'success' => true,
            'job_available' => true,
            'job' => $current_job,
        );
    }
    
    /**
     * Update crawler job status
     */
    public function update_job_status($request) {
        $status = $request->get_param('status');
        $message = $request->get_param('message');
        
        $job_queue = get_option('fictioneer_crawler_current_job');
        
        if (empty($job_queue)) {
            // Initialize if not valid, but log it
            $this->log_activity('Receiving status update for empty job', array('status' => $status));
            return array('success' => false, 'message' => 'No job found');
        }
        
        // Handle queue structure (array of jobs)
        if (isset($job_queue[0]) && is_array($job_queue[0])) {
            $job_queue[0]['status'] = $status;
            if (!empty($message)) {
                $job_queue[0]['message'] = $message;
            }
            $job_queue[0]['last_updated'] = current_time('mysql');
            
            // If completed or failed, maybe remove from queue? 
            // For now, keep it so user can see result in Control Panel, 
            // but Control Panel assumes job[0] is active.
            // Best practice: Keep it marked as completed.
        } else {
             // Backward compatibility or malformed structure
             $job_queue['status'] = $status;
             if (!empty($message)) {
                 $job_queue['message'] = $message;
             }
             $job_queue['last_updated'] = current_time('mysql');
        }
        
        update_option('fictioneer_crawler_current_job', $job_queue);
        
        return array(
            'success' => true,
            'job' => isset($job_queue[0]) ? $job_queue[0] : $job_queue,
        );
    }

    /**
     * Set story cover image
     */
    private function set_story_cover($story_id, $cover_url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $attachment_id = media_sideload_image($cover_url, $story_id, null, 'id');
        
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($story_id, $attachment_id);
        }
    }
    
    /**
     * Log activity
     */
    private function log_activity($message, $context = array()) {
        if (class_exists('Fictioneer_Crawler_Logger')) {
            Fictioneer_Crawler_Logger::info($message, $context);
        }
    }
}

// Initialize - Removed to be called from main plugin file
// new Fictioneer_Crawler_Rest_API();
