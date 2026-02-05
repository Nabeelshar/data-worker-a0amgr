"""
File management for novel content
"""

import os
import json
import requests
from urllib.parse import urlparse


class FileManager:
    def __init__(self, logger):
        self.logger = logger
    
    def save_metadata(self, novel_id, metadata):
        """Save novel metadata to JSON file"""
        novel_dir = os.path.join('novels', f'novel_{novel_id}')
        os.makedirs(novel_dir, exist_ok=True)
        
        filepath = os.path.join(novel_dir, 'metadata.json')
        with open(filepath, 'w', encoding='utf-8') as f:
            json.dump(metadata, f, ensure_ascii=False, indent=2)
        
        return filepath
    
    def save_chapter(self, novel_id, chapter_number, title, content, novel_name='', is_translated=False):
        """Save chapter content to HTML file"""
        novel_dir = os.path.join('novels', f'novel_{novel_id}')
        
        if is_translated:
            chapters_dir = os.path.join(novel_dir, 'chapters_translated')
        else:
            chapters_dir = os.path.join(novel_dir, 'chapters_raw')
        
        os.makedirs(chapters_dir, exist_ok=True)
        
        # Format: NovelName_Chapter_001.html
        # Sanitize filename (remove Windows reserved chars)
        safe_novel_name = novel_name
        for char in ['<', '>', ':', '"', '/', '\\', '|', '?', '*']:
            safe_novel_name = safe_novel_name.replace(char, '')
        
        safe_novel_name = safe_novel_name.replace(' ', '_')[:50]
        filename = f"{safe_novel_name}_Chapter_{chapter_number:03d}.html"
        filepath = os.path.join(chapters_dir, filename)
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(f"<h1>{title}</h1>\n\n{content}")
        
        return filename
    
    def create_directories(self, novel_id):
        """Create directory structure for novel"""
        novel_dir = os.path.join('novels', f'novel_{novel_id}')
        chapters_raw_dir = os.path.join(novel_dir, 'chapters_raw')
        chapters_translated_dir = os.path.join(novel_dir, 'chapters_translated')
        
        os.makedirs(chapters_raw_dir, exist_ok=True)
        os.makedirs(chapters_translated_dir, exist_ok=True)
        
        return chapters_raw_dir, chapters_translated_dir
    
    def download_cover(self, novel_id, cover_url):
        """Download cover image from URL"""
        novel_dir = os.path.join('novels', f'novel_{novel_id}')
        os.makedirs(novel_dir, exist_ok=True)
        
        # Get file extension from URL
        parsed_url = urlparse(cover_url)
        ext = os.path.splitext(parsed_url.path)[1] or '.jpg'
        filename = f'cover{ext}'
        filepath = os.path.join(novel_dir, filename)
        
        # Download image
        response = requests.get(cover_url, timeout=30)
        response.raise_for_status()
        
        with open(filepath, 'wb') as f:
            f.write(response.content)
        
        return filename
    
    def load_crawler_state(self):
        """Load crawler state from JSON file"""
        state_file = 'crawler_state.json'
        if os.path.exists(state_file):
            with open(state_file, 'r', encoding='utf-8') as f:
                return json.load(f)
        return {'processed_novels': {}, 'last_category_page': None}
    
    def save_crawler_state(self, state):
        """Save crawler state to JSON file"""
        state_file = 'crawler_state.json'
        with open(state_file, 'w', encoding='utf-8') as f:
            json.dump(state, f, ensure_ascii=False, indent=2)
    
    def update_novel_progress(self, novel_url, status, chapters_crawled=0, chapters_total=0, story_id=None):
        """Update progress for a specific novel"""
        state = self.load_crawler_state()
        import datetime
        state['processed_novels'][novel_url] = {
            'status': status,  # 'in_progress', 'completed', 'failed'
            'chapters_crawled': chapters_crawled,
            'chapters_total': chapters_total,
            'story_id': story_id,
            'last_updated': datetime.datetime.now().isoformat()
        }
        self.save_crawler_state(state)
    
    def get_local_chapter_cache(self, story_id):
        """Get cached chapter numbers for a story (avoids WordPress API calls)"""
        state = self.load_crawler_state()
        cache_key = f'story_{story_id}_chapters'
        return state.get('chapter_cache', {}).get(cache_key, set())
    
    def update_local_chapter_cache(self, story_id, chapter_numbers):
        """Update local cache of chapter numbers for a story"""
        state = self.load_crawler_state()
        if 'chapter_cache' not in state:
            state['chapter_cache'] = {}
        cache_key = f'story_{story_id}_chapters'
        # Convert set to list for JSON serialization
        if isinstance(chapter_numbers, set):
            chapter_numbers = list(chapter_numbers)
        state['chapter_cache'][cache_key] = chapter_numbers
        self.save_crawler_state(state)
    
    def add_chapter_to_cache(self, story_id, chapter_number):
        """Add a single chapter to the cache"""
        cached = set(self.get_local_chapter_cache(story_id))
        cached.add(chapter_number)
        self.update_local_chapter_cache(story_id, cached)

    def save_glossary(self, novel_id, glossary_data):
        """Save glossary to JSON file"""
        novel_dir = os.path.join('novels', f'novel_{novel_id}')
        os.makedirs(novel_dir, exist_ok=True)
        
        filepath = os.path.join(novel_dir, 'glossary.json')
        with open(filepath, 'w', encoding='utf-8') as f:
            json.dump(glossary_data, f, ensure_ascii=False, indent=2)
            
        return filepath

    def load_glossary(self, novel_id):
        """Load glossary from JSON file"""
        novel_dir = os.path.join('novels', f'novel_{novel_id}')
        filepath = os.path.join(novel_dir, 'glossary.json')
        
        if os.path.exists(filepath):
            with open(filepath, 'r', encoding='utf-8') as f:
                return json.load(f)
        return []

