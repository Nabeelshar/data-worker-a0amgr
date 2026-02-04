<?php
/**
 * Plugin Name: Fictioneer Novel Crawler (REST API)
 * Description: REST API for external crawler script to create stories and chapters
 * Version: 2.2.3
 * Author: Your Name
 * License: GPL v2 or later
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Plugin constants
define('FICTIONEER_CRAWLER_VERSION', '2.1.0');
define('FICTIONEER_CRAWLER_PATH', plugin_dir_path(__FILE__));

/**
 * Main Plugin Class
 */
class Fictioneer_Novel_Crawler_REST {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        // Fix chapter-story associations by using our working field as fallback
        add_filter('get_post_metadata', array($this, 'fix_chapter_story_meta'), 10, 4);
    }
    
    /**
     * Filter to fix fictioneer_chapter_story meta by using _test_story_id as fallback
     */
    public function fix_chapter_story_meta($value, $object_id, $meta_key, $single) {
        // Only intercept fictioneer_chapter_story requests
        if ($meta_key !== 'fictioneer_chapter_story') {
            return $value;
        }
        
        // Prevent infinite recursion
        static $in_filter = false;
        if ($in_filter) {
            return $value;
        }
        $in_filter = true;
        
        // Check the database directly without triggering filters
        global $wpdb;
        $stored_value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $object_id,
            'fictioneer_chapter_story'
        ));
        
        // If it's "0" or empty, use our working test field instead
        if (empty($stored_value) || $stored_value === '0') {
            $test_value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $object_id,
                '_test_story_id'
            ));
            
            $in_filter = false;
            
            if (!empty($test_value)) {
                return $single ? $test_value : array($test_value);
            }
        }
        
        $in_filter = false;
        return $value;
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load REST API
        require_once FICTIONEER_CRAWLER_PATH . 'includes/class-crawler-rest-api.php';
        require_once FICTIONEER_CRAWLER_PATH . 'includes/class-crawler-logger.php';
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Novel Crawler',
            'Novel Crawler',
            'manage_options',
            'fictioneer-crawler',
            array($this, 'render_admin_page'),
            'dashicons-book-alt',
            30
        );
        
        add_submenu_page(
            'fictioneer-crawler',
            'Logs',
            'Logs',
            'manage_options',
            'fictioneer-crawler-logs',
            array($this, 'render_logs_page')
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get or generate API key
        $api_key = get_option('fictioneer_crawler_api_key');
        if (empty($api_key)) {
            $api_key = wp_generate_password(32, false);
            update_option('fictioneer_crawler_api_key', $api_key);
        }

        // Handle Actions
        $this->handle_admin_actions();

        // Active Tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'jobs';
        ?>
        <div class="wrap">
            <h1>Novel Crawler - Manager</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=fictioneer-crawler&tab=jobs" class="nav-tab <?php echo $active_tab === 'jobs' ? 'nav-tab-active' : ''; ?>">Jobs</a>
                <a href="?page=fictioneer-crawler&tab=glossaries" class="nav-tab <?php echo $active_tab === 'glossaries' ? 'nav-tab-active' : ''; ?>">Glossaries</a>
                <a href="?page=fictioneer-crawler&tab=updates" class="nav-tab <?php echo $active_tab === 'updates' ? 'nav-tab-active' : ''; ?>">Updates</a>
                <a href="?page=fictioneer-crawler&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=fictioneer-crawler&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'jobs':
                        $this->render_jobs_tab();
                        break;
                    case 'glossaries':
                        $this->render_glossaries_tab();
                        break;
                    case 'updates':
                        $this->render_updates_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab($api_key);
                        break;
                    case 'logs':
                        $this->render_logs_inline();
                        break;
                    default:
                        $this->render_jobs_tab();
                        break;
                }
                ?>
            </div>
        </div>
        
        <script>
        function testAPI() {
            const resultDiv = document.getElementById('api-test-result');
            resultDiv.innerHTML = '<p>Testing...</p>';
            
            fetch('<?php echo rest_url('crawler/v1/health'); ?>')
                .then(response => response.json())
                .then(data => {
                    resultDiv.innerHTML = '<div class="notice notice-success"><p>✓ API is working! ' + 
                        'WordPress: ' + data.wordpress + ', PHP: ' + data.php + '</p></div>';
                })
                .catch(error => {
                    resultDiv.innerHTML = '<div class="notice notice-error"><p>✗ API test failed: ' + 
                        error.message + '</p></div>';
                });
        }
        </script>
        
        <style>
        .card {
            background: white;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .card h2 {
            margin-top: 0;
        }
        .card code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .card pre {
            background: #f5f5f5;
            padding: 15px;
            overflow-x: auto;
            border-radius: 3px;
        }
        </style>
        <?php
    }

    /**
     * Handle Admin Actions
     */
    private function handle_admin_actions() {
        // Handle API key regeneration
        if (isset($_POST['regenerate_api_key']) && check_admin_referer('crawler_regenerate_key')) {
            $api_key = wp_generate_password(32, false);
            update_option('fictioneer_crawler_api_key', $api_key);
            echo '<div class="notice notice-success"><p>API key regenerated successfully!</p></div>';
        }

        // Handle Job Submission
        if (isset($_POST['add_crawler_job']) && check_admin_referer('crawler_add_job')) {
            $source_type = sanitize_text_field($_POST['source_type']);
            $novel_url = esc_url_raw($_POST['novel_url']);
            $epub_url = '';

            // Handle File Upload if source type is epub
            if ($source_type === 'epub' && !empty($_FILES['epub_file']['name'])) {
                if ($_FILES['epub_file']['type'] !== 'application/epub+zip') {
                    echo '<div class="notice notice-error"><p>Invalid file type. Please upload an EPUB file.</p></div>';
                } else {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    $uploaded = wp_handle_upload($_FILES['epub_file'], array('test_form' => false));
                    if (isset($uploaded['error'])) {
                        echo '<div class="notice notice-error"><p>Upload failed: ' . esc_html($uploaded['error']) . '</p></div>';
                    } else {
                        $epub_url = $uploaded['url'];
                    }
                }
            }

            $max_chapters = intval($_POST['max_chapters']);
            $batch_size = intval($_POST['batch_size']);
            $model_type = sanitize_text_field($_POST['model_type']);
            $custom_model = sanitize_text_field($_POST['model_custom']);
            $enable_glossary = isset($_POST['enable_glossary']);
            $source_lang = sanitize_text_field($_POST['source_lang']);
            $target_lang = sanitize_text_field($_POST['target_lang']);

            $model = $model_type;
            if ($model_type === 'custom' && !empty($custom_model)) {
                $model = $custom_model;
            }

            $valid_request = false;
            if ($source_type === 'web' && !empty($novel_url)) {
                $valid_request = true;
            } elseif ($source_type === 'epub' && !empty($epub_url)) {
                $valid_request = true;
            }

            if ($valid_request) {
                 $job_data = array(
                    'job_type' => $source_type,
                    'url' => $novel_url,
                    'epub_url' => $epub_url,
                    'max_chapters' => $max_chapters,
                    'batch_size' => $batch_size,
                    'model' => $model,
                    'glossary' => $enable_glossary,
                    'source_lang' => $source_lang,
                    'target_lang' => $target_lang,
                    'status' => 'pending',
                    'timestamp' => current_time('mysql')
                );
                update_option('fictioneer_crawler_current_job', array($job_data)); 
                echo '<div class="notice notice-success"><p>Job added to queue!</p></div>';
            } else {
                 if ($source_type === 'web') {
                    echo '<div class="notice notice-error"><p>Novel URL is required.</p></div>';
                 } elseif ($source_type === 'epub' && empty($epub_url) && empty($uploaded['error'])) {
                    echo '<div class="notice notice-error"><p>EPUB file is required.</p></div>';
                 }
            }
        }

        // Handle Job Deletion
        if (isset($_POST['delete_crawler_job']) && check_admin_referer('crawler_delete_job')) {
            delete_option('fictioneer_crawler_current_job');
            echo '<div class="notice notice-success"><p>Job deleted!</p></div>';
        }

        // Handle Glossary Save
        if (isset($_POST['save_glossary']) && check_admin_referer('crawler_save_glossary')) {
            $novel_dir = sanitize_text_field($_POST['novel_dir']);
            $glossary_content = wp_unslash($_POST['glossary_content']);
            
            // Basic JSON validation
            $json_data = json_decode($glossary_content);
            
            if ($json_data === null && !empty($glossary_content)) {
                echo '<div class="notice notice-error"><p>Invalid JSON format.</p></div>';
            } else {
                $glossary_path = FICTIONEER_CRAWLER_PATH . 'crawler/novels/' . $novel_dir . '/glossary.json';
                if (file_exists(dirname($glossary_path))) {
                    file_put_contents($glossary_path, $glossary_content);
                    echo '<div class="notice notice-success"><p>Glossary saved successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Novel folder not found.</p></div>';
                }
            }
        }

        // Handle Plugin Update
        if (isset($_POST['update_plugin_action']) && check_admin_referer('crawler_update_plugin')) {
            if (!current_user_can('update_plugins')) {
                echo '<div class="notice notice-error"><p>You do not have permission to update plugins.</p></div>';
                return;
            }

            $update_url = esc_url_raw($_POST['update_url']);
            
            if (!empty($update_url)) {
                // Initializing the filesystem
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                WP_Filesystem();
                global $wp_filesystem;

                $temp_file = download_url($update_url);

                if (is_wp_error($temp_file)) {
                    echo '<div class="notice notice-error"><p>Error downloading file: ' . esc_html($temp_file->get_error_message()) . '</p></div>';
                } else {
                    $unzip_result = unzip_file($temp_file, FICTIONEER_CRAWLER_PATH);
                    
                    if (is_wp_error($unzip_result)) {
                        echo '<div class="notice notice-error"><p>Error unzipping file: ' . esc_html($unzip_result->get_error_message()) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>Plugin updated successfully!</p></div>';
                    }
                    
                    // Cleanup
                    @unlink($temp_file);
                }
            } else {
                echo '<div class="notice notice-error"><p>Please provide a valid URL.</p></div>';
            }
        }
    }

    /**
     * Render Jobs Tab
     */
    private function render_jobs_tab() {
        // Get current job
        $current_jobs = get_option('fictioneer_crawler_current_job', array());
        $current_job = (!empty($current_jobs) && is_array($current_jobs)) ? $current_jobs[0] : null;
        ?>
        <div class="card">
            <h2>🚀 Control Panel</h2>
            
            <?php if ($current_job): ?>
                <h3>Active Job</h3>
                <table class="widefat fixed" style="margin-bottom: 15px;">
                    <tbody>
                        <?php if (isset($current_job['job_type']) && $current_job['job_type'] === 'epub'): ?>
                        <tr>
                            <th style="width: 150px;">Epub File</th>
                            <td><a href="<?php echo esc_url($current_job['epub_url']); ?>" target="_blank">Download EPUB</a></td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <th style="width: 150px;">Novel URL</th>
                            <td><?php if(isset($current_job['url'])) { echo '<a href="' . esc_url($current_job['url']) . '" target="_blank">' . esc_html($current_job['url']) . '</a>'; } else { echo '-'; } ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Source / Target</th>
                            <td>
                                <?php echo isset($current_job['source_lang']) ? esc_html($current_job['source_lang']) : 'Auto'; ?> 
                                → 
                                <?php echo isset($current_job['target_lang']) ? esc_html($current_job['target_lang']) : 'en'; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td><span style="background: #e5e5e5; padding: 3px 8px; border-radius: 3px; font-weight: bold;"><?php echo esc_html(strtoupper($current_job['status'])); ?></span></td>
                        </tr>
                        <tr>
                            <th>Model</th>
                            <td><code><?php echo esc_html($current_job['model']); ?></code></td>
                        </tr>
                            <tr>
                            <th>Settings</th>
                            <td>
                                <strong>Max Chapters:</strong> <?php echo intval($current_job['max_chapters']); ?><br>
                                <strong>Batch Size:</strong> <?php echo intval($current_job['batch_size']); ?><br>
                                <strong>Glossary:</strong> <?php echo $current_job['glossary'] ? '✅ Enabled' : '❌ Disabled'; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Created</th>
                            <td><?php echo esc_html($current_job['timestamp']); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <form method="post">
                    <?php wp_nonce_field('crawler_delete_job'); ?>
                        <input type="hidden" name="delete_crawler_job" value="1">
                    <button type="submit" class="button button-link-delete" 
                            onclick="return confirm('Are you sure you want to delete this job?');">
                        🗑️ Delete Job
                    </button>
                </form>

            <?php else: ?>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('crawler_add_job'); ?>
                    <input type="hidden" name="add_crawler_job" value="1">
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">Source Type</th>
                                <td>
                                    <fieldset>
                                        <label><input type="radio" name="source_type" value="web" checked onclick="toggleSourceType()"> Web URL</label>
                                        &nbsp;&nbsp;
                                        <label><input type="radio" name="source_type" value="epub" onclick="toggleSourceType()"> EPUB File</label>
                                    </fieldset>
                                </td>
                            </tr>

                            <tr id="row_novel_url">
                                <th scope="row"><label for="novel_url">Novel URL</label></th>
                                <td>
                                    <input name="novel_url" type="url" id="novel_url" value="" class="regular-text" placeholder="https://example.com/novel/123">
                                    <p class="description">The index page URL of the novel to crawl.</p>
                                </td>
                            </tr>

                            <tr id="row_epub_file" style="display:none;">
                                <th scope="row"><label for="epub_file">EPUB File</label></th>
                                <td>
                                    <input name="epub_file" type="file" id="epub_file" accept=".epub">
                                    <p class="description">Upload an EPUB file to process.</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="source_lang">Language Source</label></th>
                                <td>
                                    <select name="source_lang" id="source_lang">
                                        <option value="auto">Auto Detect</option>
                                        <option value="zh-CN">Chinese (Simplified)</option>
                                        <option value="zh-TW">Chinese (Traditional)</option>
                                        <option value="ja">Japanese</option>
                                        <option value="ko">Korean</option>
                                        <option value="en">English</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="target_lang">Language Target</label></th>
                                <td>
                                    <select name="target_lang" id="target_lang">
                                        <option value="en">English (default)</option>
                                        <option value="es">Spanish</option>
                                        <option value="fr">French</option>
                                        <option value="de">German</option>
                                        <option value="pt">Portuguese</option>
                                    </select>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><label for="max_chapters">Max Chapters</label></th>
                                <td>
                                    <input name="max_chapters" type="number" id="max_chapters" value="50" class="small-text">
                                    <p class="description">Maximum number of chapters to process in this run.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="batch_size">Batch Size</label></th>
                                <td>
                                    <input name="batch_size" type="number" id="batch_size" value="10" class="small-text">
                                    <p class="description">Chapters per batch (parallel requests).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="model_type">AI Model</label></th>
                                <td>
                                    <select name="model_type" id="model_type" onchange="toggleCustomModel(this.value)">
                                        <option value="gemini-1.5-flash">Google Gemini Flash Lite</option>
                                        <option value="gemini-1.5-pro">Google Gemini Pro</option>
                                        <option value="deepseek-r1">DeepSeek R1</option>
                                        <option value="custom">Other</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="custom_model_row" style="display:none;">
                                <th scope="row"><label for="model_custom">Custom Model Name</label></th>
                                <td>
                                    <input name="model_custom" type="text" id="model_custom" value="" class="regular-text" placeholder="e.g. gpt-4o">
                                    <p class="description">Enter the specific model identifier strings.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Options</th>
                                <td>
                                    <fieldset>
                                        <label for="enable_glossary">
                                            <input name="enable_glossary" type="checkbox" id="enable_glossary" value="1" checked>
                                            Enable Glossary Mode
                                        </label>
                                        <p class="description">Use glossary translation for better consistency.</p>
                                    </fieldset>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary">Queue Job</button>
                    </p>
                </form>
                
                <script>
                function toggleCustomModel(val) {
                    const row = document.getElementById('custom_model_row');
                    row.style.display = val === 'custom' ? 'table-row' : 'none';
                }

                function toggleSourceType() {
                    const type = document.querySelector('input[name="source_type"]:checked').value;
                    const webRow = document.getElementById('row_novel_url');
                    const epubRow = document.getElementById('row_epub_file');
                    
                    if (type === 'web') {
                        webRow.style.display = 'table-row';
                        epubRow.style.display = 'none';
                        document.getElementById('novel_url').required = true;
                        document.getElementById('epub_file').required = false;
                    } else {
                        webRow.style.display = 'none';
                        epubRow.style.display = 'table-row';
                        document.getElementById('novel_url').required = false;
                        document.getElementById('epub_file').required = true;
                    }
                }
                // Initialize
                document.addEventListener('DOMContentLoaded', toggleSourceType);
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Glossaries Tab
     */
    private function render_glossaries_tab() {
        $novels_path = FICTIONEER_CRAWLER_PATH . 'crawler/novels/';
        $novels = glob($novels_path . 'novel_*', GLOB_ONLYDIR);
        
        $selected_novel = isset($_POST['novel_dir']) ? sanitize_text_field($_POST['novel_dir']) : '';
        $glossary_content = '';
        
        if ($selected_novel) {
            $glossary_file = $novels_path . $selected_novel . '/glossary.json';
            if (file_exists($glossary_file)) {
                $glossary_content = file_get_contents($glossary_file);
            } else {
                $glossary_content = "{\n    \"terms\": {\n        \n    }\n}";
            }
        }
        ?>
        <div class="card">
            <h2>📖 Manage Glossaries</h2>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="novel_dir">Select Novel</label></th>
                        <td>
                            <select name="novel_dir" id="novel_dir" onchange="this.form.submit()">
                                <option value="">-- Select Novel --</option>
                                <?php foreach ($novels as $novel_path): 
                                    $dirname = basename($novel_path);
                                    // Try to get title from metadata
                                    $title = $dirname;
                                    $meta_file = $novel_path . '/metadata.json';
                                    if (file_exists($meta_file)) {
                                        $meta = json_decode(file_get_contents($meta_file), true);
                                        if (isset($meta['title'])) {
                                            $title = $meta['title'] . ' (' . $dirname . ')';
                                        }
                                    }
                                ?>
                                    <option value="<?php echo esc_attr($dirname); ?>" <?php selected($selected_novel, $dirname); ?>><?php echo esc_html($title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </form>

            <?php if ($selected_novel): ?>
                <hr>
                <form method="post">
                    <?php wp_nonce_field('crawler_save_glossary'); ?>
                    <input type="hidden" name="save_glossary" value="1">
                    <input type="hidden" name="novel_dir" value="<?php echo esc_attr($selected_novel); ?>">
                    
                    <h3>Glossary JSON</h3>
                    <p class="description">Edit the translation glossary for this novel. Must be valid JSON.</p>
                    <textarea name="glossary_content" rows="15" class="large-text code" style="font-family: monospace;"><?php echo esc_textarea($glossary_content); ?></textarea>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary">Save Glossary</button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Updates Tab
     */
    private function render_updates_tab() {
        ?>
        <div class="card">
            <h2>🛠️ Updates & Tools</h2>
            
            <h3>Update Plugin</h3>
            <p>Update or reinstall the crawler plugin from a ZIP URL.</p>
            <form method="post">
                <?php wp_nonce_field('crawler_update_plugin'); ?>
                <input type="hidden" name="update_plugin_action" value="1">
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="update_url">Zip URL</label></th>
                        <td>
                            <input name="update_url" type="url" id="update_url" value="" class="regular-text" placeholder="https://example.com/plugin.zip">
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary" onclick="return confirm('This will overwrite the current plugin files. Continue?');">Update Plugin</button>
                </p>
            </form>
            
            <hr>
            
            <h3>Tools</h3>
            <p>
                <!-- Include link or logic for repair -->
                <a href="<?php echo admin_url('admin.php?page=fictioneer-crawler&tab=updates&tool=repair_chapters'); ?>" class="button button-secondary disabled" onclick="return false;">Repair Chapters (Coming Soon)</a>
            </p>
        </div>
        <?php
    }

    /**
     * Render Settings Tab
     */
    private function render_settings_tab($api_key) {
        ?>
        <div class="card">
            <h2>🔑 API Key</h2>
            <p>Use this API key to authenticate with the REST API:</p>
            <div style="background: #f5f5f5; padding: 15px; border-left: 4px solid #2271b1; margin: 15px 0;">
                <code style="font-size: 14px; user-select: all;"><?php echo esc_html($api_key); ?></code>
            </div>
            <form method="post" style="margin-top: 10px;">
                <?php wp_nonce_field('crawler_regenerate_key'); ?>
                <button type="submit" name="regenerate_api_key" class="button" 
                        onclick="return confirm('Are you sure? You will need to update the crawler script with the new key.');">
                    🔄 Regenerate API Key
                </button>
            </form>
        </div>
        
        <div class="card">
            <h2>📚 REST API Endpoints</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Purpose</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code><?php echo rest_url('crawler/v1/health'); ?></code></td>
                        <td>GET</td>
                        <td>Health check (no auth)</td>
                    </tr>
                    <tr>
                        <td><code><?php echo rest_url('crawler/v1/story'); ?></code></td>
                        <td>POST</td>
                        <td>Create story (requires API key)</td>
                    </tr>
                    <tr>
                        <td><code><?php echo rest_url('crawler/v1/chapter'); ?></code></td>
                        <td>POST</td>
                        <td>Create chapter (requires API key)</td>
                    </tr>
                </tbody>
            </table>
            <p style="margin-top: 15px;"><strong>Authentication:</strong> Include API key in <code>X-API-Key</code> header or <code>api_key</code> parameter.</p>
        </div>
        
        <div class="card">
            <h2>🐍 Python Crawler Setup</h2>
            <p><strong>1. Navigate to crawler directory:</strong></p>
            <pre>cd <?php echo FICTIONEER_CRAWLER_PATH; ?>crawler/</pre>
            
            <p><strong>2. Install Python dependencies:</strong></p>
            <pre>pip install requests beautifulsoup4 lxml googletrans==4.0.0rc1</pre>
            
            <p><strong>3. Configure the crawler:</strong></p>
            <p>Edit <code>config.json</code> and add your API key:</p>
            <pre>{
  "wordpress_url": "<?php echo site_url(); ?>",
  "api_key": "<?php echo esc_html($api_key); ?>",
  "max_chapters_per_run": 5
}</pre>
            
            <p><strong>4. Run the crawler:</strong></p>
            <pre>python crawler.py https://www.xbanxia.cc/books/396941.html</pre>
        </div>
        
        <div class="card">
            <h2>📁 Folder Structure</h2>
            <p>The crawler organizes data in this structure:</p>
            <pre>crawler/
├── novels/
│   └── novel_name/
│       ├── metadata.json          # Novel info, cover, description
│       ├── cover.jpg               # Downloaded cover image
│       ├── chapters_raw/           # Original Chinese chapters
│       │   ├── chapter_001.html
│       │   ├── chapter_002.html
│       │   └── ...
│       └── chapters_translated/    # Translated English chapters
│           ├── chapter_001.html
│           ├── chapter_002.html
│           └── ...
└── logs/
    └── crawler.log</pre>
        </div>
        
        <div class="card">
            <h2>✅ Test API Connection</h2>
            <button type="button" class="button button-primary" onclick="testAPI()">Test API Connection</button>
            <div id="api-test-result" style="margin-top: 15px;"></div>
        </div>
        <?php
    }
    
    /**
     * Render Logs Tab Inline
     */
    private function render_logs_inline() {
        $log_dir = FICTIONEER_CRAWLER_PATH . 'logs/';
        $log_files = glob($log_dir . 'crawler-*.log');
        
        if ($log_files) {
             rsort($log_files);
        } else {
             $log_files = array();
        }
        
        if (empty($log_files)) {
            echo '<div class="notice notice-info"><p>No log files found.</p></div>';
            return;
        }
        
        $current_log = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : basename($log_files[0]);
        $log_file = $log_dir . $current_log;
        
        if (!file_exists($log_file)) {
            echo '<div class="notice notice-error"><p>Log file not found.</p></div>';
            return;
        }
        ?>
        <div class="card">
            <h2>Select Log File</h2>
            <select onchange="window.location.href='?page=fictioneer-crawler&tab=logs&file=' + this.value">
                <?php foreach ($log_files as $file) : ?>
                    <option value="<?php echo basename($file); ?>" <?php selected($current_log, basename($file)); ?>>
                        <?php echo basename($file); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="card">
            <h2><?php echo esc_html($current_log); ?></h2>
            <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; max-height: 600px;"><?php
                echo esc_html(file_get_contents($log_file));
            ?></pre>
        </div>
        <?php
    }
    
    /**
     * Render logs page
     */
    public function render_logs_page() {
        ?>
        <div class="wrap">
            <h1>Crawler Logs</h1>
            
            <?php
            $log_dir = FICTIONEER_CRAWLER_PATH . 'logs/';
            $log_files = glob($log_dir . 'crawler-*.log');
            rsort($log_files);
            
            if (empty($log_files)) {
                echo '<div class="notice notice-info"><p>No log files found.</p></div>';
                return;
            }
            
            $current_log = isset($_GET['file']) ? $_GET['file'] : basename($log_files[0]);
            $log_file = $log_dir . $current_log;
            
            if (!file_exists($log_file)) {
                echo '<div class="notice notice-error"><p>Log file not found.</p></div>';
                return;
            }
            ?>
            
            <div class="card">
                <h2>Select Log File</h2>
                <select onchange="window.location.href='?page=fictioneer-crawler-logs&file=' + this.value">
                    <?php foreach ($log_files as $file) : ?>
                        <option value="<?php echo basename($file); ?>" <?php selected($current_log, basename($file)); ?>>
                            <?php echo basename($file); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="card">
                <h2><?php echo $current_log; ?></h2>
                <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; max-height: 600px;"><?php
                    echo esc_html(file_get_contents($log_file));
                ?></pre>
            </div>
        </div>
        
        <style>
        .card {
            background: white;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        </style>
        <?php
    }
    
    /**
     * Activation hook
     */
    public function activate() {
        // Create logs directory
        $log_dir = FICTIONEER_CRAWLER_PATH . 'logs/';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        // Create .htaccess to protect logs
        $htaccess = $log_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
        
        flush_rewrite_rules();
    }
}

// Initialize plugin
Fictioneer_Novel_Crawler_REST::get_instance();
