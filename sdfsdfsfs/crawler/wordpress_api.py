"""
WordPress REST API client
"""

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry


class WordPressAPI:
    def __init__(self, wordpress_url, api_key, logger):
        self.wordpress_url = wordpress_url
        self.api_key = api_key
        self.logger = logger
        self._connection_tested = False  # Cache connection test result
        self._connection_ok = False
        
        # OPTIMIZATION: Use session with connection pooling and retry logic
        self.session = requests.Session()
        
        # Configure retry strategy for transient errors
        retry_strategy = Retry(
            total=3,
            backoff_factor=1,
            status_forcelist=[429, 500, 502, 503, 504],
            allowed_methods=["HEAD", "GET", "POST", "PUT", "DELETE", "OPTIONS", "TRACE"]
        )
        
        adapter = HTTPAdapter(
            max_retries=retry_strategy,
            pool_connections=10,  # Keep connections alive
            pool_maxsize=20       # Max concurrent connections
        )
        
        self.session.mount("http://", adapter)
        self.session.mount("https://", adapter)
        
        # Set default headers
        self.session.headers.update({'X-API-Key': self.api_key})
    
    def get_job(self):
        """Get the next crawl job from WordPress"""
        try:
            response = self.session.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/job",
                timeout=10
            )
            if response.status_code == 200:
                result = response.json()
                # Relaxed check: 'success' might not be present in all endpoints
                if result.get('job_available') or (result.get('success') and result.get('job_available')):
                     return result.get('job')
                return None
            return None
        except Exception as e:
            self.logger(f"Error checking for job: {e}")
            return None

    def update_job_status(self, job_id, status, message):
         """Update job status"""
         try:
            # PHP endpoint registers /job/status, not /job/{id}/status
            # We assume PHP handles the current job regardless of ID, or expects ID in body
            # But the register_rest_route doesn't define ID param in URL.
            response = self.session.post(
                f"{self.wordpress_url}/wp-json/crawler/v1/job/status",
                json={'status': status, 'message': message},
                timeout=10
            )
            return response.status_code == 200
         except Exception as e:
             self.logger(f"Error updating job status: {e}")
             return False

    def test_connection(self, force=False):
        """Test connection to WordPress API (cached after first success)"""
        # Use cached result unless force=True
        if self._connection_tested and not force:
            return self._connection_ok, {'cached': True}
        
        try:
            response = self.session.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/health",
                timeout=10
            )
            if response.status_code == 200:
                data = response.json()
                self._connection_tested = True
                self._connection_ok = True
                return True, data
            self._connection_tested = True
            self._connection_ok = False
            return False, f"Status code: {response.status_code}"
        except Exception as e:
            self._connection_tested = True
            self._connection_ok = False
            return False, str(e)
    
    def create_story(self, story_data):
        """Create or get existing story in WordPress"""
        response = self.session.post(
            f"{self.wordpress_url}/wp-json/crawler/v1/story",
            json=story_data,
            timeout=30
        )
        
        if response.status_code in [200, 201]:
            result = response.json()
            return {
                'id': result.get('story_id'),
                'existed': result.get('existed', False)
            }
        else:
            raise Exception(f"Failed to create story: {response.status_code} - {response.text}")
    
    def get_story_chapter_status(self, story_id, total_chapters):
        """Get bulk status of all chapters for a story (FAST!)"""
        try:
            response = self.session.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/story/{story_id}/chapters",
                params={'total_chapters': total_chapters},
                timeout=15
            )
            
            if response.status_code == 200:
                result = response.json()
                return {
                    'success': True,
                    'chapters_count': result.get('chapters_count', 0),
                    'is_complete': result.get('is_complete', False),
                    'existing_chapters': result.get('existing_chapters', [])  # List of chapter numbers
                }
            else:
                # Fallback to individual checks
                return {'success': False, 'chapters_count': 0, 'is_complete': False, 'existing_chapters': []}
        except:
            # Fallback to individual checks
            return {'success': False, 'chapters_count': 0, 'is_complete': False, 'existing_chapters': []}
    
    def check_chapter_exists(self, story_id, chapter_number):
        """Check if chapter already exists in WordPress"""
        try:
            response = self.session.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/chapter/exists",
                params={'story_id': story_id, 'chapter_number': chapter_number},
                timeout=10
            )
            
            if response.status_code == 200:
                result = response.json()
                return {
                    'exists': result.get('exists', False),
                    'chapter_id': result.get('chapter_id')
                }
            else:
                # If endpoint doesn't exist, fallback to False (will crawl)
                return {'exists': False, 'chapter_id': None}
        except:
            # On error, assume doesn't exist (safer to crawl)
            return {'exists': False, 'chapter_id': None}
    
    def create_chapter(self, chapter_data):
        """Create chapter in WordPress"""
        response = self.session.post(
            f"{self.wordpress_url}/wp-json/crawler/v1/chapter",
            json=chapter_data,
            timeout=30
        )
        
        if response.status_code in [200, 201]:
            result = response.json()
            return {
                'id': result.get('chapter_id'),
                'existed': result.get('existed', False)
            }
        else:
            raise Exception(f"Failed to create chapter: {response.status_code} - {response.text}")
    
    def create_chapters_bulk(self, chapters_data):
        """Create multiple chapters in a single API call (OPTIMIZATION)"""
        try:
            response = self.session.post(
                f"{self.wordpress_url}/wp-json/crawler/v1/chapters/bulk",
                json={'chapters': chapters_data},
                timeout=180  # Longer timeout for bulk operations (increased from 120)
            )
            
            if response.status_code in [200, 201]:
                result = response.json()
                return {
                    'success': True,
                    'results': result.get('results', []),
                    'created': result.get('created', 0),
                    'existed': result.get('existed', 0),
                    'failed': result.get('failed', 0)
                }
            else:
                # Fallback to individual creation
                return {'success': False, 'error': response.text}
        except Exception as e:
            # Fallback to individual creation
            return {'success': False, 'error': str(e)}
