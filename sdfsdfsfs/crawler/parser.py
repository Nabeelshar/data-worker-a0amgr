"""
HTML parser module for ttkan.co novels
"""

import requests
from bs4 import BeautifulSoup
from urllib.parse import urljoin
import json
import time
import random

try:
    import cloudscraper
except ImportError:
    cloudscraper = None

try:
    from fake_useragent import UserAgent
except ImportError:
    UserAgent = None


class NovelParser:
    def __init__(self, logger):
        self.logger = logger
        self.ua = UserAgent() if UserAgent else None
        
        # Initialize session with browser-like behavior
        if cloudscraper:
            try:
                self.session = cloudscraper.create_scraper(
                    browser={
                        'browser': 'chrome',
                        'platform': 'windows',
                        'desktop': True
                    },
                    delay=10
                )
                self.logger("Initialized CloudScraper session")
            except Exception as e:
                self.logger(f"Failed to init CloudScraper: {e}, falling back to requests")
                self.session = requests.Session()
        else:
            self.session = requests.Session()
            
        # Common headers for both requests/cloudscraper
        headers = {
            'User-Agent': self.get_random_ua(),
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
            'Accept-Encoding': 'gzip, deflate, br',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
            'Sec-Fetch-Dest': 'document',
            'Sec-Fetch-Mode': 'navigate',
            'Sec-Fetch-Site': 'none',
            'Sec-Fetch-User': '?1',
            'Cache-Control': 'max-age=0',
        }
        self.session.headers.update(headers)
    
    def get_random_ua(self):
        if self.ua:
            try:
                return self.ua.random
            except:
                pass
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36'

    def parse_novel_page(self, url):
        """Parse novel page to extract metadata and chapter list"""
        # Random delay before request to behave like human
        time.sleep(random.uniform(1, 3))
        
        # Update referer for specific requests
        domain = 'https://www.ttkan.co'
        self.session.headers.update({'Referer': domain})
        
        try:
            response = self.session.get(url, timeout=30)
            response.raise_for_status()
        except Exception as e:
            self.logger(f"Request failed: {e}. Retrying with new session...")
            # Re-init session on failure
            self.__init__(self.logger)
            time.sleep(5)
            response = self.session.get(url, timeout=30)

        response.encoding = 'utf-8'
        soup = BeautifulSoup(response.content, 'lxml')
        
        # Extract novel ID from URL
        # URL format: https://www.ttkan.co/novel/chapters/novel_id
        novel_id = url.rstrip('/').split('/')[-1]
        
        # Extract metadata
        novel_data = {
            'title': '',
            'author': '',
            'description': '',
            'cover_url': '',
            'type': '',
            'status': '',
            'last_updated': '',
            'latest_chapter': '',
            'chapters': []
        }
        
        # Find novel info section
        novel_info = soup.find('div', class_='novel_info')
        if novel_info:
            # Title
            h1 = novel_info.find('h1')
            if h1:
                novel_data['title'] = h1.get_text(strip=True)
            
            # Cover image
            amp_img = novel_info.find('amp-img')
            if amp_img:
                novel_data['cover_url'] = amp_img.get('src', '')
                if novel_data['cover_url'].startswith('//'):
                    novel_data['cover_url'] = 'https:' + novel_data['cover_url']
            
            # Extract metadata from list
            ul = novel_info.find('ul')
            if ul:
                for li in ul.find_all('li'):
                    text = li.get_text(strip=True)
                    if text.startswith('作者'):
                        author_link = li.find('a')
                        if author_link:
                            novel_data['author'] = author_link.get_text(strip=True)
                    elif text.startswith('類別'):
                        novel_data['type'] = text.replace('類別：', '').strip()
                    elif text.startswith('狀態'):
                        novel_data['status'] = text.replace('狀態：', '').strip()
        
        # Description
        description_div = soup.find('div', class_='description')
        if description_div:
            novel_data['description'] = description_div.get_text(separator='\n', strip=True)

        # Fetch chapters from API
        # API URL: https://www.ttkan.co/api/nq/amp_novel_chapters?language=tw&novel_id={novel_id}
        api_url = f"https://www.ttkan.co/api/nq/amp_novel_chapters?language=tw&novel_id={novel_id}"
        try:
            api_response = self.session.get(api_url)
            if api_response.status_code == 200:
                chapters_json = api_response.json()
                items = chapters_json.get('items', [])
                for item in items:
                    chapter_id = item.get('chapter_id')
                    chapter_name = item.get('chapter_name')
                    # Construct chapter URL
                    # Using page_direct format as seen in the site
                    chapter_url = f"https://www.ttkan.co/novel/user/page_direct?novel_id={novel_id}&page={chapter_id}"
                    
                    novel_data['chapters'].append({
                        'title': chapter_name,
                        'url': chapter_url,
                        'chapter_number': chapter_id
                    })
        except Exception as e:
            self.logger(f"Error fetching chapters from API: {e}")
        
        return novel_data, novel_id
    
    def parse_category_page(self, url):
        """Parse category page to extract novel URLs"""
        # Handle API URL or convert web URL to API URL
        if '/api/nq/amp_novel_list' in url:
            api_url = url
        else:
            # Default to rank list if not an API URL
            # You might want to map different web URLs to different API params if needed
            api_url = "https://www.ttkan.co/api/nq/amp_novel_list?language=tw"
            
        try:
            response = self.session.get(api_url)
            if response.status_code == 200:
                data = response.json()
                
                novels = []
                items = data.get('items', [])
                for item in items:
                    novel_id = item.get('novel_id')
                    if novel_id:
                        # Construct novel URL
                        full_url = f"https://www.ttkan.co/novel/chapters/{novel_id}"
                        novels.append(full_url)
                
                # Pagination
                pagination = {'current': 1, 'total': 1, 'next': None}
                
                next_url = data.get('next')
                if next_url:
                    # Handle relative URL
                    if next_url.startswith('/'):
                        pagination['next'] = "https://www.ttkan.co" + next_url
                    else:
                        pagination['next'] = next_url
                    
                    # Try to extract page number from URL for logging
                    import re
                    page_match = re.search(r'page=(\d+)', pagination['next'])
                    if page_match:
                        # The next link has the NEXT page number
                        pagination['current'] = int(page_match.group(1)) - 1
                        pagination['total'] = pagination['current'] + 100 # Unknown total, just ensure loop continues
                
                return novels, pagination
                
        except Exception as e:
            self.logger(f"Error parsing category API: {e}")
            return [], {'current': 1, 'total': 1, 'next': None}
        
        # Fallback to old HTML parsing if API fails or for other URLs
        response = self.session.get(url)
        response.encoding = 'utf-8'
        soup = BeautifulSoup(response.content, 'lxml')
        
        novels = []
        
        # Find all novel links in rank_list
        rank_list = soup.find('div', class_='rank_list')
        if rank_list:
            # The structure is:
            # <div class="pure-u-xl-4-5 ..."><ul><li><a href="/novel/chapters/id"><h2>Title</h2></a></li>...</ul></div>
            # We can find all 'a' tags with href starting with /novel/chapters/
            links = rank_list.find_all('a', href=True)
            for link in links:
                href = link['href']
                if href.startswith('/novel/chapters/'):
                    full_url = urljoin("https://www.ttkan.co", href)
                    if full_url not in novels:
                        novels.append(full_url)
        
        # Pagination
        # ttkan rank pages might not have standard pagination or might use infinite scroll/load more.
        # For now, we assume single page or handle what we see.
        # If there is pagination, it would be good to find it.
        # Based on ttkan.html, there is no obvious pagination at the bottom.
        pagination = {'current': 1, 'total': 1, 'next': None}
        
        return novels, pagination
    
    def parse_chapter_page(self, url):
        """Parse chapter page to extract content"""
        # Retry logic for connection stability
        max_retries = 4
        response = None
        
        for attempt in range(max_retries):
            try:
                # Random delay to be polite and avoid detection
                if attempt > 0:
                    time.sleep(random.uniform(2, 5))
                
                response = self.session.get(url, timeout=30)
                response.raise_for_status()
                break
            except Exception as e:
                if attempt < max_retries - 1:
                    sleep_time = (attempt + 1) * 3
                    self.logger(f"Network error fetching chapter (Attempt {attempt+1}/{max_retries}): {e}. Retrying in {sleep_time}s...")
                    time.sleep(sleep_time)
                    # Re-initialize session on connection errors
                    try:
                        self.session.close()
                    except:
                        pass
                    
                    # Re-create session
                    if cloudscraper:
                        try:
                            self.session = cloudscraper.create_scraper(
                                browser={'browser': 'chrome','platform': 'windows','desktop': True},
                                delay=10
                            )
                        except:
                            self.session = requests.Session()
                    else:
                        self.session = requests.Session()
                    
                    # Restore headers
                    headers = {
                        'User-Agent': self.get_random_ua(),
                        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                        'Accept-Language': 'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
                        'Accept-Encoding': 'gzip, deflate, br',
                        'Connection': 'keep-alive',
                        'Upgrade-Insecure-Requests': '1',
                        'Cache-Control': 'max-age=0',
                    }
                    self.session.headers.update(headers)
                    # Update referer
                    self.session.headers.update({'Referer': 'https://www.ttkan.co'})
                else:
                    self.logger(f"Failed to fetch chapter after {max_retries} attempts: {url}")
                    return None, None

        if not response:
             return None, None

        response.encoding = 'utf-8'
        soup = BeautifulSoup(response.content, 'lxml')
        
        # Extract chapter title
        title_div = soup.find('div', class_='title')
        title = ''
        if title_div:
            h1 = title_div.find('h1')
            if h1:
                title = h1.get_text(strip=True)
        
        # Extract chapter content
        content_div = soup.find('div', class_='content')
        if not content_div:
            return None, None
        
        # Remove unwanted elements
        for tag in content_div.find_all(['script', 'style', 'amp-img', 'center', 'div']):
            # Remove ads which are often in center/div tags inside content
            # Be careful not to remove content paragraphs if they are in divs (though usually they are p)
            # In the sample, ads are in <center><div class="mobadsq"></div></center>
            # and <div id="div_content_end">...</div>
            if tag.name == 'div' and ('mobadsq' in tag.get('class', []) or tag.get('id') == 'div_content_end'):
                tag.decompose()
            elif tag.name == 'center':
                tag.decompose()
            elif tag.name in ['script', 'style', 'amp-img']:
                tag.decompose()
        
        # Get the text content
        # The content is mainly in <p> tags
        paragraphs = content_div.find_all('p')
        lines = []
        for p in paragraphs:
            text = p.get_text(strip=True)
            if text:
                lines.append(text)
        
        content = '\n\n'.join(lines)
        
        return title, content
