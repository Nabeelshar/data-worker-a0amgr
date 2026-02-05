<?php
/**
 * HTML Parser for crawling novel content
 */

if (!defined('ABSPATH')) {
    exit;
}

class HTML_Parser {
    
    /**
     * Parse category page to get novel URLs
     * 
     * @param string $html HTML content
     * @return array Array of novel data
     */
    public static function parse_category_page($html) {
        $novels = array();
        
        if (empty($html)) {
            return $novels;
        }
        
        // Load HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Find all novel items using the pop-book2 class
        $novelNodes = $xpath->query("//li[contains(@class, 'pop-book2')]");
        
        foreach ($novelNodes as $node) {
            $novel = array();
            
            // Get novel URL
            $linkNode = $xpath->query(".//a[@href and h2]", $node)->item(0);
            if ($linkNode) {
                $novel['url'] = $linkNode->getAttribute('href');
                
                // Make sure URL is absolute
                if (strpos($novel['url'], 'http') !== 0) {
                    $novel['url'] = 'https://www.xbanxia.cc' . $novel['url'];
                }
            }
            
            // Get title
            $titleNode = $xpath->query(".//h2[contains(@class, 'pop-tit')]", $node)->item(0);
            if ($titleNode) {
                $novel['title'] = trim($titleNode->textContent);
            }
            
            // Get author
            $authorNode = $xpath->query(".//span[contains(@class, 'pop-intro')]", $node)->item(0);
            if ($authorNode) {
                $novel['author'] = trim($authorNode->textContent);
            }
            
            // Get cover image
            $imgNode = $xpath->query(".//img", $node)->item(0);
            if ($imgNode) {
                $novel['cover'] = $imgNode->getAttribute('data-original');
                if (empty($novel['cover'])) {
                    $novel['cover'] = $imgNode->getAttribute('src');
                }
                
                // Skip if no cover image
                if (strpos($novel['cover'], 'nocover.jpg') !== false) {
                    $novel['cover'] = '';
                }
            }
            
            if (!empty($novel['url'])) {
                $novels[] = $novel;
            }
        }
        
        return $novels;
    }
    
    /**
     * Parse novel page to get metadata and chapters
     * 
     * @param string $html HTML content
     * @return array Novel data
     */
    public static function parse_novel_page($html, $novel_url = '') {
        $novel = array(
            'title' => '',
            'author' => '',
            'cover' => '',
            'description' => '',
            'category' => '',
            'status' => '',
            'last_update' => '',
            'chapters' => array()
        );
        
        if (empty($html)) {
            return $novel;
        }
        
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Get title
        $titleNode = $xpath->query("//div[contains(@class, 'book-describe')]/h1")->item(0);
        if ($titleNode) {
            $novel['title'] = trim($titleNode->textContent);
        }
        
        // Get author
        $authorNode = $xpath->query("//div[contains(@class, 'book-describe')]//p[contains(text(), '作者')]//a")->item(0);
        if ($authorNode) {
            $novel['author'] = trim($authorNode->textContent);
        }
        
        // Get cover image
        $imgNode = $xpath->query("//div[contains(@class, 'book-img')]//img")->item(0);
        if ($imgNode) {
            $novel['cover'] = $imgNode->getAttribute('data-original');
            if (empty($novel['cover'])) {
                $novel['cover'] = $imgNode->getAttribute('src');
            }
        }
        
        // Get category
        $categoryNode = $xpath->query("//div[contains(@class, 'book-describe')]//p[contains(text(), '類型')]")->item(0);
        if ($categoryNode) {
            $text = $categoryNode->textContent;
            $novel['category'] = trim(str_replace('類型︰', '', $text));
        }
        
        // Get status
        $statusNode = $xpath->query("//div[contains(@class, 'book-describe')]//p[contains(text(), '狀態')]")->item(0);
        if ($statusNode) {
            $text = $statusNode->textContent;
            $novel['status'] = trim(str_replace('狀態︰', '', $text));
        }
        
        // Get last update
        $updateNode = $xpath->query("//div[contains(@class, 'book-describe')]//p[contains(text(), '更新')]")->item(0);
        if ($updateNode) {
            $text = $updateNode->textContent;
            $novel['last_update'] = trim(str_replace('最近更新︰', '', $text));
        }
        
        // Get description
        $descNode = $xpath->query("//div[contains(@class, 'describe-html')]")->item(0);
        if ($descNode) {
            // Remove extra HTML and get text
            $novel['description'] = self::clean_html_content($dom->saveHTML($descNode));
        }
        
        // Get chapters
        $chapterNodes = $xpath->query("//div[contains(@class, 'book-list')]//li/a");
        
        foreach ($chapterNodes as $index => $chapterNode) {
            $chapter = array();
            
            $chapter['title'] = trim($chapterNode->textContent);
            $chapter['url'] = $chapterNode->getAttribute('href');
            $chapter['order'] = $index + 1;
            
            // Make sure URL is absolute
            if (strpos($chapter['url'], 'http') !== 0) {
                // Extract base URL from novel URL
                $base_url = parse_url($novel_url);
                if ($base_url) {
                    $chapter['url'] = $base_url['scheme'] . '://' . $base_url['host'] . $chapter['url'];
                }
            }
            
            if (!empty($chapter['url'])) {
                $novel['chapters'][] = $chapter;
            }
        }
        
        return $novel;
    }
    
    /**
     * Parse chapter page to get content
     * 
     * @param string $html HTML content
     * @return array Chapter data
     */
    public static function parse_chapter_page($html) {
        $chapter = array(
            'title' => '',
            'content' => '',
            'next_chapter' => ''
        );
        
        if (empty($html)) {
            return $chapter;
        }
        
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Get title
        $titleNode = $xpath->query("//h1[@id='nr_title' or contains(@class, 'post-title')]")->item(0);
        if ($titleNode) {
            $chapter['title'] = trim($titleNode->textContent);
        }
        
        // Get content
        $contentNode = $xpath->query("//div[@id='nr1']")->item(0);
        if ($contentNode) {
            // Clone node to manipulate
            $contentClone = $contentNode->cloneNode(true);
            
            // Remove script tags, ads, etc.
            $scriptsToRemove = $xpath->query(".//script", $contentClone);
            foreach ($scriptsToRemove as $script) {
                $script->parentNode->removeChild($script);
            }
            
            // Get HTML content
            $content = $dom->saveHTML($contentClone);
            
            // Clean up content
            $chapter['content'] = self::clean_chapter_content($content);
        }
        
        // Get next chapter link
        $nextNode = $xpath->query("//a[@rel='next' or @id='next_url']")->item(0);
        if ($nextNode) {
            $chapter['next_chapter'] = $nextNode->getAttribute('href');
        }
        
        return $chapter;
    }
    
    /**
     * Parse pagination info from category page
     * 
     * @param string $html HTML content
     * @return array Pagination data
     */
    public static function parse_pagination($html) {
        $pagination = array(
            'current_page' => 1,
            'total_pages' => 1,
            'next_page_url' => ''
        );
        
        if (empty($html)) {
            return $pagination;
        }
        
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Get current page and total pages from pagestats
        $pageStatsNode = $xpath->query("//em[@id='pagestats']")->item(0);
        if ($pageStatsNode) {
            $text = $pageStatsNode->textContent;
            if (preg_match('/(\d+)\/(\d+)/', $text, $matches)) {
                $pagination['current_page'] = (int)$matches[1];
                $pagination['total_pages'] = (int)$matches[2];
            }
        }
        
        // Get next page URL
        $nextNode = $xpath->query("//a[contains(@class, 'next')]")->item(0);
        if ($nextNode) {
            $pagination['next_page_url'] = $nextNode->getAttribute('href');
            
            // Make sure URL is absolute
            if (strpos($pagination['next_page_url'], 'http') !== 0) {
                $pagination['next_page_url'] = 'https://www.xbanxia.cc' . $pagination['next_page_url'];
            }
        }
        
        return $pagination;
    }
    
    /**
     * Clean HTML content
     * 
     * @param string $html HTML content
     * @return string Cleaned content
     */
    private static function clean_html_content($html) {
        // Remove script tags
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $html);
        
        // Remove style tags
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/i', '', $html);
        
        // Remove comments
        $html = preg_replace('/<!--(.|\s)*?-->/', '', $html);
        
        // Convert <br> to newlines
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        
        // Strip remaining tags but keep paragraph structure
        $html = strip_tags($html, '<p><strong><em><u><a>');
        
        // Clean up whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        $html = trim($html);
        
        return $html;
    }
    
    /**
     * Clean chapter content
     * 
     * @param string $content Chapter content
     * @return string Cleaned content
     */
    private static function clean_chapter_content($content) {
        // Remove wrapper divs
        $content = preg_replace('/<div[^>]*>/', '', $content);
        $content = str_replace('</div>', '', $content);
        
        // Convert <br> to paragraphs
        $content = preg_replace('/<br\s*\/?>\s*<br\s*\/?>/i', '</p><p>', $content);
        $content = preg_replace('/<br\s*\/?>/i', '<br>', $content);
        
        // Remove ads and promotional text
        $content = preg_replace('/本站無彈出廣告/u', '', $content);
        $content = preg_replace('/請.*?光臨/u', '', $content);
        
        // Clean up whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Wrap in paragraph if not already
        if (strpos($content, '<p>') === false) {
            $lines = explode('<br>', $content);
            $content = '<p>' . implode('</p><p>', array_filter($lines)) . '</p>';
        }
        
        return trim($content);
    }
    
    /**
     * Fetch HTML from URL with retry logic
     * 
     * @param string $url URL to fetch
     * @param int $retries Number of retry attempts
     * @return string|false HTML content or false on failure
     */
    public static function fetch_url($url, $retries = 3) {
        $args = array(
            'timeout' => 30,
            'user-agent' => get_option('crawler_user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
            'sslverify' => false,
            'httpversion' => '1.1'
        );
        
        $last_error = '';
        
        for ($i = 0; $i < $retries; $i++) {
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                
                // Check for network errors that should be retried
                $network_errors = array('cURL error', 'Could not resolve host', 'timed out', 'Failed to connect');
                $should_retry = false;
                
                foreach ($network_errors as $err) {
                    if (stripos($last_error, $err) !== false) {
                        $should_retry = true;
                        break;
                    }
                }
                
                if ($should_retry && $i < $retries - 1) {
                    $wait = pow(2, $i);
                    Fictioneer_Crawler_Logger::warning('Network error, retrying...', array(
                        'url' => substr($url, 0, 80),
                        'attempt' => $i + 1,
                        'error' => $last_error,
                        'wait' => $wait
                    ));
                    sleep($wait);
                    continue;
                }
                
                Fictioneer_Crawler_Logger::error('Failed to fetch URL: ' . substr($url, 0, 80), array(
                    'error' => $last_error,
                    'attempts' => $i + 1
                ));
                throw new Exception("Network error: $last_error");
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code >= 200 && $status_code < 300) {
                return wp_remote_retrieve_body($response);
            }
            
            // Server error - retry
            if ($status_code >= 500 && $i < $retries - 1) {
                $wait = pow(2, $i);
                Fictioneer_Crawler_Logger::warning('Server error, retrying...', array(
                    'url' => substr($url, 0, 80),
                    'status' => $status_code,
                    'attempt' => $i + 1,
                    'wait' => $wait
                ));
                sleep($wait);
                continue;
            }
            
            Fictioneer_Crawler_Logger::error('HTTP error for URL: ' . substr($url, 0, 80), array(
                'status_code' => $status_code
            ));
            throw new Exception("HTTP error: $status_code");
        }
        
        throw new Exception("Failed after $retries attempts: $last_error");
    }
}
