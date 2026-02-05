#!/usr/bin/env python3
"""
Novel Crawler for xbanxia.cc
Crawls novels and posts them to WordPress via REST API
"""

import sys
import os
import json
import time
import requests
from epub_parser import EpubParser
from translator import Translator
from parser import NovelParser
from wordpress_api import WordPressAPI
from file_manager import FileManager
from config_loader import load_config


class NovelCrawler:
    def __init__(self, config_path='config.json'):
        """Initialize the crawler with configuration"""
        # Force unbuffered output globally (Python 3.7+)
        sys.stdout.reconfigure(line_buffering=True)
        
        self.config = load_config(config_path)
        
        # Configuration
        self.wordpress_url = self.config['wordpress_url']
        self.api_key = self.config['api_key']
        self.google_project_id = self.config.get('google_project_id', '')
        self.google_credentials_file = self.config.get('google_credentials_file', '')
        self.max_chapters = self.config.get('max_chapters_per_run', 5)
        self.delay = self.config.get('delay_between_requests', 2)
        self.should_translate = self.config.get('translate', False)
        self.target_language = self.config.get('target_language', 'en')
        
        # Initialize modules
        self.translator = None
        if self.should_translate:
            # Always initialize translator when translation is needed
            self.translator = Translator(self.config, self.log)
        
        # CRITICAL: Verify translator initialized
        if self.should_translate:
            if not self.translator or not self.translator.client:
                self.log("CRITICAL ERROR: Translation Required But Unavailable")
                self.log("Please check if googletrans==4.0.0rc1 is installed: pip install googletrans==4.0.0rc1")
                raise Exception("Translation service initialization failed")
        
        self.parser = NovelParser(self.log)
        self.wordpress = WordPressAPI(self.wordpress_url, self.api_key, self.log)
        self.file_manager = FileManager(self.log)
        
        # OPTIMIZATION: Batch configuration
        self.bulk_chapter_size = self.config.get('bulk_chapter_size', 50)  # Create chapters in batches (increased from 25)
    
    def log(self, message, flush=True):
        try:
            print(message, flush=flush)
        except UnicodeEncodeError:
            print(message.encode('ascii', 'replace').decode('ascii'), flush=flush)
    
    def process_chapters_in_batches(self, chapters_data, story_id, novel_url, total_chapters):
        """
        Process chapters in batches for optimal performance
        CRITICAL: Maintains sequential order of chapters
        """
        total_to_process = len(chapters_data)
        chapters_created = 0
        chapters_existed = 0
        
        # Process in batches
        for batch_start in range(0, total_to_process, self.bulk_chapter_size):
            batch_end = min(batch_start + self.bulk_chapter_size, total_to_process)
            batch = chapters_data[batch_start:batch_end]
            
            batch_num = (batch_start // self.bulk_chapter_size) + 1
            total_batches = (total_to_process + self.bulk_chapter_size - 1) // self.bulk_chapter_size
            
            self.log(f"\n  📦 Batch {batch_num}/{total_batches}: Creating chapters {batch[0]['chapter_number']}-{batch[-1]['chapter_number']}...")
            
            # Try bulk creation first
            bulk_result = self.wordpress.create_chapters_bulk(batch)
            
            if bulk_result['success']:
                # Bulk creation succeeded
                self.log(f"    ✓ Batch complete: {bulk_result['created']} created, {bulk_result['existed']} existed, {bulk_result['failed']} failed ({len(batch)} total)")
                chapters_created += bulk_result['created']
                chapters_existed += bulk_result['existed']
                
                # Update progress after each batch
                last_chapter_num = batch[-1]['chapter_number']
                self.file_manager.update_novel_progress(
                    novel_url, 'in_progress',
                    chapters_crawled=last_chapter_num,
                    chapters_total=total_chapters,
                    story_id=story_id
                )
            else:
                # Fallback to individual creation (maintains order)
                self.log(f"    ⚠ Bulk failed, falling back to individual creation...")
                for chapter_data in batch:
                    try:
                        chapter_result = self.wordpress.create_chapter(chapter_data)
                        if chapter_result.get('existed'):
                            chapters_existed += 1
                        else:
                            chapters_created += 1
                        
                        # Update progress after each chapter
                        self.file_manager.update_novel_progress(
                            novel_url, 'in_progress',
                            chapters_crawled=chapter_data['chapter_number'],
                            chapters_total=total_chapters,
                            story_id=story_id
                        )
                    except Exception as e:
                        self.log(f"    ✗ Failed chapter {chapter_data['chapter_number']}: {e}")
                        raise  # Stop on error to maintain sequence
            
            # Delay between batches (not after last batch)
            if batch_end < total_to_process:
                time.sleep(self.delay)
        
        return chapters_created, chapters_existed
    
    def run_worker_mode(self):
        """Run in worker mode, polling for jobs"""
        self.log("\n" + "="*50)
        self.log("Starting Crawler Worker Mode")
        self.log("="*50 + "\n")
        
        while True:
            try:
                self.log("Polling for jobs...")
                job = self.wordpress.get_job()
                
                if job:
                    # Handle list response from API (Double safety)
                    if isinstance(job, list):
                        if len(job) > 0:
                            job = job[0]
                        else:
                            self.log("Received empty job list")
                            continue

                    self.log(f"Job received! ID: {job.get('job_id')}")
                    
                    # Update status to processing
                    job_id = job.get('job_id')
                    if job_id:
                        self.wordpress.update_job_status(job_id, 'processing', 'Starting job...')
                    
                    try:
                        self.process_job(job)
                        if job_id:
                            self.wordpress.update_job_status(job_id, 'completed', 'Job completed successfully')
                    except Exception as e:
                        self.log(f"Job failed: {e}")
                        import traceback
                        traceback.print_exc()
                        if job_id:
                            self.wordpress.update_job_status(job_id, 'failed', str(e))
                
                else:
                    self.log("No jobs available. Sleeping 30s...")
                    time.sleep(30)
                    
            except KeyboardInterrupt:
                self.log("Worker mode stopped by user")
                break
            except Exception as e:
                self.log(f"Worker error: {e}")
                time.sleep(30)

    def process_job(self, job_data):
        """Process a specific job"""
        # Extract job parameters
        novel_url = job_data.get('url')
        max_chapters = job_data.get('max_chapters', 10)
        model = job_data.get('model')
        glossary_mode = job_data.get('glossary', job_data.get('glossary_mode', False))
        
        job_type = job_data.get('job_type', 'web')
        epub_url = job_data.get('epub_url')
        source_lang = job_data.get('source_lang', 'auto')
        target_lang = job_data.get('target_lang', 'en')

        self.log(f"Processing job type: {job_type}")
        if job_type == 'web':
            self.log(f"Processing novel: {novel_url}")
        else:
            self.log(f"Processing epub: {epub_url}")

        self.log(f"Max chapters: {max_chapters}")
        self.log(f"Job Requested Model: {model if model else 'Default'}")
        self.log(f"Glossary Mode: {glossary_mode}")
        
        # dynamic translator update
        if model and self.should_translate and self.translator:
            if hasattr(self.translator, 'openrouter_model') and self.translator.openrouter_model != model:
                self.log(f"  Switching Translator Model to: {model}")
                self.translator.openrouter_model = model
        
        # Simplified crawl logic tailored for jobs
        # 1. Fetch novel info
        if job_type == 'epub':
            self.log("Downloading Epub...")
            if not os.path.exists('temp'):
                os.makedirs('temp')
            
            epub_filename = f"temp/job_{job_data.get('job_id', int(time.time()))}.epub"
            try:
                response = requests.get(epub_url, stream=True)
                response.raise_for_status()
                with open(epub_filename, 'wb') as f:
                    for chunk in response.iter_content(chunk_size=8192):
                        f.write(chunk)
                self.log(f"Epub downloaded to {epub_filename}")
                
                epub_parser = EpubParser(self.log)
                novel_data = epub_parser.parse_novel_info(epub_filename)
                chapters = epub_parser.extract_chapters(epub_filename)
                novel_data['chapters'] = chapters
                
                # Defaults for fields not in epub
                if 'type' not in novel_data: novel_data['type'] = 'epub'
                if 'status' not in novel_data: novel_data['status'] = 'Completed'
                
                # Mock novel_id
                import hashlib
                novel_id = hashlib.md5(epub_url.encode()).hexdigest()[:8]
                novel_url = epub_url # Override for consistent usage downstream
                
            except Exception as e:
                self.log(f"Failed to process epub: {e}")
                raise e
        else:
            novel_data, novel_id = self.parser.parse_novel_page(novel_url)
        
        # 2. Check/Create story
        if self.should_translate:
             if not self.translator or not self.translator.client:
                 raise Exception("Transmission Required but Translator not available")
             
             try:
                 translated_title = self.translator.translate(novel_data['title'], source_lang=source_lang, target_lang=target_lang)
                 translated_description = self.translator.translate(novel_data['description'], source_lang=source_lang, target_lang=target_lang)
             except Exception as e:
                 raise Exception(f"Failed to translate metadata: {e}")
        else:
             translated_title = novel_data['title']
             translated_description = novel_data['description']

        story_data = {
            'title': translated_title,
            'description': translated_description,
            'title_zh': novel_data['title'],
            'author': novel_data['author'],
            'url': novel_url,
            'cover_url': novel_data['cover_url'],
            'cover_path': None
        }
        
        story_result = self.wordpress.create_story(story_data)
        story_id = story_result['id']
        
        # 3. Determine chapters to process
        chapter_status = self.wordpress.get_story_chapter_status(story_id, len(novel_data['chapters']))
        existing_chapters = set(chapter_status.get('existing_chapters', []))
        
        chapters_to_do = []
        for i, ch in enumerate(novel_data['chapters'], 1):
             if i not in existing_chapters:
                 chapters_to_do.append((i, ch))
                 if len(chapters_to_do) >= max_chapters:
                     break
        
        if not chapters_to_do:
            self.log("No new chapters to process.")
            return

        # 4. Glossary Loop
        batch_size = 5 # Small batch for glossary consistency context
        current_glossary = self.file_manager.load_glossary(novel_id)
        
        total_batches = (len(chapters_to_do) + batch_size - 1) // batch_size
        
        for b_idx in range(total_batches):
            batch = chapters_to_do[b_idx * batch_size : (b_idx + 1) * batch_size]
            self.log(f"Processing batch {b_idx + 1}/{total_batches} ({len(batch)} chapters)")
            
            # Fetch Raw Content
            raw_contents = [] 
            batch_text_context = ""
            
            for chap_num, chap_info in batch:
                if job_type == 'epub':
                     title = chap_info.get('title')
                     content = chap_info.get('content')
                else:
                    title, content = self.parser.parse_chapter_page(chap_info['url'])
                    time.sleep(1) 
                
                if title and content:
                    raw_contents.append({
                        'num': chap_num, 
                        'title': title, 
                        'content': content,
                        'url': chap_info['url']
                    })
                    batch_text_context += f"{title}\n{content}\n"
            
            # Glossary Extraction
            if glossary_mode and self.should_translate and self.translator:
                self.log("Generating/Updating glossary...")
                try:
                    # Pass limited context to avoid token limits
                    current_glossary = self.translator.extract_glossary(batch_text_context[:10000], current_glossary)
                    self.file_manager.save_glossary(novel_id, current_glossary)
                except Exception as e:
                    raise Exception(f"Glossary extraction failed (Required): {e}")

            # Translate & Upload
            prepared_chapters = []
            for item in raw_contents:
                if self.should_translate:
                    try:
                        trans_title = self.translator.translate(item['title'], glossary=current_glossary, source_lang=source_lang, target_lang=target_lang)
                        trans_content = self.translator.translate(item['content'], glossary=current_glossary, source_lang=source_lang, target_lang=target_lang)
                    except Exception as e:
                         raise Exception(f"Chapter translation failed (Required): {e}")
                else:
                    trans_title = item['title']
                    trans_content = item['content']
                
                prepared_chapters.append({
                    'title': f"{translated_title} Chapter {item['num']}",
                    'title_zh': item['title'],
                    'content': trans_content,
                    'story_id': story_id,
                    'url': item['url'],
                    'chapter_number': item['num']
                })
            
            # Upload Batch
            if prepared_chapters:
                self.process_chapters_in_batches(prepared_chapters, story_id, novel_url, len(novel_data['chapters']))
            
            self.wordpress.update_job_status(job_data['job_id'], 'processing', f"Processed batch {b_idx + 1}/{total_batches}")

    def crawl_category(self, category_url, max_pages=None):
        """Crawl all novels from a category page with pagination"""
        self.log("\n" + "="*50)
        self.log("Starting Category Crawler")
        self.log("="*50 + "\n")
        self.log(f"Category URL: {category_url}\n")
        
        current_url = category_url
        page_count = 0
        total_novels_processed = 0
        
        while current_url:
            page_count += 1
            
            # Stop if max_pages limit reached
            if max_pages and page_count > max_pages:
                self.log(f"\n✓ Reached max pages limit ({max_pages})")
                break
            
            try:
                # Parse category page
                self.log(f"\n{'='*50}")
                self.log(f"Processing Page {page_count}")
                self.log(f"{'='*50}")
                self.log(f"URL: {current_url}\n")
                
                novels, pagination = self.parser.parse_category_page(current_url)
                
                self.log(f"Found {len(novels)} novels on page {pagination['current']}/{pagination['total']}")
                
                # Process each novel on this page
                for idx, novel_url in enumerate(novels, 1):
                    self.log(f"\n--- Novel {idx}/{len(novels)} on Page {page_count} ---")
                    self.log(f"URL: {novel_url}")
                    
                    try:
                        self.crawl_novel(novel_url)
                        total_novels_processed += 1
                    except KeyboardInterrupt:
                        self.log("\n\n⚠ Interrupted by user")
                        self.log(f"Processed {total_novels_processed} novels across {page_count} pages")
                        return
                    except Exception as e:
                        self.log(f"✗ Error crawling novel: {e}")
                        import traceback
                        traceback.print_exc()
                        continue
                
                # Move to next page
                current_url = pagination.get('next')
                
                if current_url:
                    self.log(f"\n→ Moving to next page: {current_url}")
                    time.sleep(self.delay)  # Delay between pages
                else:
                    self.log(f"\n✓ Reached last page ({pagination['current']}/{pagination['total']})")
                    break
                    
            except KeyboardInterrupt:
                self.log("\n\n⚠ Interrupted by user")
                self.log(f"Processed {total_novels_processed} novels across {page_count} pages")
                return
            except Exception as e:
                self.log(f"\n✗ Error processing page: {e}")
                import traceback
                traceback.print_exc()
                break
        
        self.log("\n" + "="*50)
        self.log("Category Crawling Complete!")
        self.log("="*50)
        self.log(f"Total pages processed: {page_count}")
        self.log(f"Total novels processed: {total_novels_processed}")
        self.log("")
    
    def crawl_novel(self, novel_url):
        """Main crawling process"""
        self.log("\n" + "="*50)
        self.log("Starting Novel Crawler")
        self.log("="*50 + "\n")
        
        # Check crawler state for this novel
        crawler_state = self.file_manager.load_crawler_state()
        novel_progress = crawler_state['processed_novels'].get(novel_url, {})
        
        resume_from_chapter = 0
        
        # Check if novel is truly completed (all chapters processed)
        if novel_progress.get('status') == 'completed':
            chapters_crawled = novel_progress.get('chapters_crawled', 0)
            chapters_total = novel_progress.get('chapters_total', 0)
            
            # Only skip if all chapters are truly done
            if chapters_crawled >= chapters_total:
                self.log(f"✓ Novel already fully completed: {novel_url}")
                self.log(f"  All {chapters_crawled}/{chapters_total} chapters processed")
                self.log(f"  Story ID: {novel_progress.get('story_id')}")
                return
            else:
                # Marked completed but not all chapters done - resume
                self.log(f"⟳ Novel marked completed but has more chapters: {novel_url}")
                self.log(f"  Resuming from chapter {chapters_crawled + 1}")
                resume_from_chapter = chapters_crawled
        
        if novel_progress.get('status') == 'in_progress':
            resume_from_chapter = novel_progress.get('chapters_crawled', 0)
            self.log(f"⟳ Resuming novel: {novel_url}")
            self.log(f"  Continuing from chapter {resume_from_chapter + 1}")
            self.log(f"  Progress: {resume_from_chapter}/{novel_progress.get('chapters_total')} chapters")
        
        # Step 1: Test WordPress connection (CACHED after first success)
        self.log("[1/6] Testing WordPress API connection...")
        success, result = self.wordpress.test_connection()
        if success:
            cached_indicator = " (cached)" if result.get('cached') else ""
            self.log(f"  Connected{cached_indicator} (WordPress v{result.get('wordpress', 'unknown')})")
        else:
            self.log(f"  Failed: {result}")
            return
        
        # Step 2: Fetch and parse novel page
        self.log("\n[2/6] Fetching novel page...")
        novel_data, novel_id = self.parser.parse_novel_page(novel_url)
        self.log(f"  Fetched ({len(str(novel_data))} bytes)")
        
        # Step 3: Parse novel data
        self.log("\n[3/6] Parsing novel data...")
        self.log(f"  Title: {novel_data['title']}")
        self.log(f"  Author: {novel_data['author']}")
        self.log(f"  Chapters found: {len(novel_data['chapters'])}")
        
        # Step 4: Translate title and description
        self.log("\n[4/6] Translating metadata...")
        if self.should_translate and self.translator and self.translator.client:
            # Check if already translated in metadata
            existing_metadata_path = os.path.join('novels', f'novel_{novel_id}', 'metadata.json')
            if os.path.exists(existing_metadata_path):
                try:
                    with open(existing_metadata_path, 'r', encoding='utf-8') as f:
                        existing_meta = json.load(f)
                        if existing_meta.get('title_translated'):
                            translated_title = existing_meta['title_translated']
                            self.log(f"  Using cached title: {translated_title}")
                        else:
                            translated_title = self.translator.translate(novel_data['title'])
                            self.log(f"  Title (EN): {translated_title}")
                        
                        if existing_meta.get('description_translated'):
                            translated_description = existing_meta['description_translated']
                            self.log(f"  Using cached description")
                        else:
                            translated_description = self.translator.translate(novel_data['description'])
                            self.log(f"  Description (EN): Translated")
                except:
                    translated_title = self.translator.translate(novel_data['title'])
                    translated_description = self.translator.translate(novel_data['description'])
                    self.log(f"  Title (EN): {translated_title}")
                    self.log(f"  Description (EN): Translated")
            else:
                translated_title = self.translator.translate(novel_data['title'])
                translated_description = self.translator.translate(novel_data['description'])
                self.log(f"  Title (EN): {translated_title}")
                self.log(f"  Description (EN): Translated")
        else:
            translated_title = novel_data['title']
            translated_description = novel_data['description']
            self.log("  Translation disabled")
        
        # Step 5: Check if story exists in WordPress using translated title
        self.log("\n[5/6] Checking if story exists...")
        story_data_check = {
            'title': translated_title,  # Use translated title for lookup
            'description': translated_description,  # Use translated description
            'title_zh': novel_data['title'],
            'author': novel_data['author'],
            'url': novel_url,
            'cover_url': novel_data['cover_url'],
            'cover_path': None  # Don't download yet
        }
        
        story_result = self.wordpress.create_story(story_data_check)
        story_id = story_result['id']
        
        if story_result.get('existed'):
            self.log(f"  Story exists (ID: {story_id})")
            
            # 🚀 OPTIMIZATION: Check if all chapters exist BEFORE downloading cover
            chapter_status = self.wordpress.get_story_chapter_status(story_id, len(novel_data['chapters']))
            
            if chapter_status['success'] and chapter_status['is_complete']:
                self.log(f"  ✓✓✓ NOVEL COMPLETE! All {chapter_status['chapters_count']} chapters exist - SKIPPING! ✓✓✓")
                # Update progress and exit early
                self.file_manager.update_novel_progress(novel_url, 'completed', 
                    chapters_crawled=len(novel_data['chapters']),
                    chapters_total=len(novel_data['chapters']),
                    story_id=story_id)
                return
            else:
                self.log(f"  Novel incomplete ({chapter_status['chapters_count']}/{len(novel_data['chapters'])} chapters) - continuing...")
                # Store chapter status for later use to avoid re-checking
                existing_chapter_set = set(chapter_status.get('existing_chapters', []))
        else:
            self.log(f"  Story created (ID: {story_id})")
            existing_chapter_set = set()  # New story, no chapters exist
        
        # Only download cover if we're processing chapters
        self.log("\n[5/6] Downloading cover...")
        # Download cover image if available
        cover_path = None
        if novel_data['cover_url']:
            try:
                cover_filename = self.file_manager.download_cover(novel_id, novel_data['cover_url'])
                cover_path = os.path.join('novels', f'novel_{novel_id}', cover_filename)
                self.log(f"  Cover downloaded: {cover_filename}")
            except Exception as e:
                self.log(f"  Failed to download cover: {e}")
        
        # Save metadata
        metadata = {
            'title': novel_data['title'],
            'title_translated': translated_title,
            'author': novel_data['author'],
            'description': novel_data['description'],
            'description_translated': translated_description,
            'type': novel_data['type'],
            'status': novel_data['status'],
            'cover_url': novel_data['cover_url'],
            'source_url': novel_url,
            'total_chapters': len(novel_data['chapters'])
        }
        self.file_manager.save_metadata(novel_id, metadata)
        
        # Always update WordPress story with complete metadata after translation/download
        # This ensures cover, description, and translated title are all up-to-date
        story_data_final = {
            'title': translated_title,
            'description': translated_description,
            'title_zh': novel_data['title'],
            'author': novel_data['author'],
            'url': novel_url,
            'cover_url': novel_data['cover_url'],
            'cover_path': cover_path
        }
        self.wordpress.create_story(story_data_final)
        
        # Step 6: Process chapters
        self.log(f"\n[6/6] Processing chapters (max {self.max_chapters}, batches of {self.bulk_chapter_size})...")
        
        # Create chapter directories
        self.file_manager.create_directories(novel_id)
        
        # Store novel titles for chapter filenames
        novel_title_raw = novel_data['title']
        novel_title_translated = translated_title
        
        # Auto-resume from WordPress if local state is empty or behind
        # This is crucial for GitHub Actions where local state is lost
        if 'existing_chapter_set' in locals() and existing_chapter_set:
            max_existing = max(existing_chapter_set)
            if max_existing > resume_from_chapter:
                resume_from_chapter = max_existing
                self.log(f"  Auto-resuming from WordPress status: Chapter {resume_from_chapter}")
        
        # Determine chapter range to process
        start_chapter = resume_from_chapter + 1
        end_chapter = min(resume_from_chapter + self.max_chapters, len(novel_data['chapters']))
        chapters_to_process = novel_data['chapters'][resume_from_chapter:end_chapter]
        
        if resume_from_chapter > 0:
            self.log(f"  Resuming from chapter {start_chapter} to {end_chapter}")
        
        # Use cached chapter status from Step 5 to avoid redundant API call
        if 'existing_chapter_set' not in locals():
            # Only check if we didn't already get it in Step 5
            chapter_status = self.wordpress.get_story_chapter_status(story_id, len(novel_data['chapters']))
            
            if chapter_status['success']:
                if chapter_status['chapters_count'] > 0:
                    self.log(f"  Found {chapter_status['chapters_count']} existing chapters - will skip those")
                    existing_chapter_set = set(chapter_status['existing_chapters'])
                else:
                    existing_chapter_set = set()
            else:
                # Fallback: will check individually
                self.log("  Bulk check unavailable - checking chapters individually")
                existing_chapter_set = None
        else:
            self.log(f"  Using cached chapter status (avoids API call)")
        
        # PHASE 1: Crawl and translate all chapters (sequential to maintain order)
        self.log(f"\n  Phase 1: Crawling & translating chapters...")
        prepared_chapters = []  # List to store prepared chapter data in order
        chapters_existed = 0
        
        for idx, chapter in enumerate(chapters_to_process, start=start_chapter):
            self.log(f"\n  Chapter {idx}/{len(novel_data['chapters'])}: {chapter['title']}")
            
            # Check if chapter exists (use bulk result if available)
            if existing_chapter_set is not None:
                if idx in existing_chapter_set:
                    self.log(f"    ✓ Already in WordPress - Skipped crawl/translate")
                    chapters_existed += 1
                    continue
            else:
                # Fallback to individual check
                chapter_check = self.wordpress.check_chapter_exists(story_id, idx)
                if chapter_check['exists']:
                    self.log(f"    ✓ Already in WordPress (ID: {chapter_check['chapter_id']}) - Skipped crawl/translate")
                    chapters_existed += 1
                    continue
            
            # Parse chapter content
            title, content = self.parser.parse_chapter_page(chapter['url'])
            if not content:
                self.log("    Skipped (no content found)")
                continue
            
            self.log(f"    Extracted {len(content)} characters")
            
            # Save raw chapter
            raw_filename = self.file_manager.save_chapter(novel_id, idx, title, content, novel_title_raw, is_translated=False)
            self.log(f"    Saved to {raw_filename}")
            
            # Translate if enabled
            if self.should_translate and self.translator and self.translator.client:
                # Check if translated file already exists
                translated_dir = os.path.join('novels', f'novel_{novel_id}', 'chapters_translated')
                safe_novel_name = novel_title_translated.replace(' ', '_').replace('/', '_').replace('\\', '_')[:50]
                translated_filepath = os.path.join(translated_dir, f"{safe_novel_name}_Chapter_{idx:03d}.html")
                
                if os.path.exists(translated_filepath):
                    # Read existing translation
                    with open(translated_filepath, 'r', encoding='utf-8') as f:
                        translated_html = f.read()
                        # Extract title and content from HTML
                        import re
                        title_match = re.search(r'<h1>(.*?)</h1>', translated_html, re.DOTALL)
                        translated_title = title_match.group(1) if title_match else title
                        content_match = re.search(r'</h1>\s*(.+)', translated_html, re.DOTALL)
                        translated_content = content_match.group(1).strip() if content_match else content
                    self.log(f"    Using cached translation")
                else:
                    # Retry translation with exponential backoff
                    max_retries = 10
                    retry_delay = 0
                    translated_title = None
                    translated_content = None
                    
                    for attempt in range(max_retries):
                        try:
                            if attempt > 0:
                                self.log(f"    Translation retry {attempt}/{max_retries} (waiting {retry_delay}s)...")
                                time.sleep(retry_delay)
                            
                            translated_title = self.translator.translate(title)
                            translated_content = self.translator.translate(content)
                            self.log(f"    Translated")
                            break
                        except Exception as e:
                            self.log(f"    Translation error: {e}")
                            if attempt < max_retries - 1:
                                retry_delay = min(600, 2 ** attempt)  # Max 10 minutes
                            else:
                                self.log(f"    CRITICAL: Translation failed after {max_retries} attempts")
                                self.log(f"    STOPPING: Cannot proceed without translation for chapter {idx}")
                                return
                    
                    if not translated_title or not translated_content:
                        self.log(f"    CRITICAL: Translation failed for chapter {idx}")
                        self.log(f"    STOPPING: Cannot proceed without translation")
                        return
            else:
                translated_title = title
                translated_content = content
            
            # Save translated chapter
            translated_filename = self.file_manager.save_chapter(novel_id, idx, translated_title, translated_content, novel_title_translated, is_translated=True)
            self.log(f"    Saved to {translated_filename}")
            
            # Prepare chapter data for batch creation (maintain order)
            chapter_wordpress_title = f"{novel_title_translated} Chapter {idx}"
            chapter_data = {
                'title': chapter_wordpress_title,
                'title_zh': title,
                'content': translated_content,
                'story_id': story_id,
                'url': chapter['url'],
                'chapter_number': idx  # CRITICAL: ensures sequential order
            }
            prepared_chapters.append(chapter_data)
            self.log(f"    ✓ Prepared for batch upload")
        
        # PHASE 2: Batch upload to WordPress (maintains sequential order)
        if prepared_chapters:
            self.log(f"\n  Phase 2: Uploading {len(prepared_chapters)} chapters to WordPress...")
            chapters_created, chapters_uploaded_existed = self.process_chapters_in_batches(
                prepared_chapters, story_id, novel_url, len(novel_data['chapters'])
            )
        else:
            chapters_created = 0
            chapters_uploaded_existed = 0
        
        # Determine if novel is completed or just reached max_chapters limit
        total_chapters_crawled = chapters_created + chapters_existed + chapters_uploaded_existed
        if total_chapters_crawled >= len(novel_data['chapters']):
            # All chapters processed - mark as completed
            status = 'completed'
            self.log("\n✓ All chapters processed!")
        else:
            # More chapters available - mark as in_progress
            status = 'in_progress'
            self.log(f"\n⚠ Reached max_chapters limit ({self.max_chapters}). {len(novel_data['chapters']) - total_chapters_crawled} chapters remaining.")
        
        self.file_manager.update_novel_progress(
            novel_url, status,
            chapters_crawled=total_chapters_crawled,
            chapters_total=len(novel_data['chapters']),
            story_id=story_id
        )
        
        # Summary
        self.log("\n" + "="*50)
        self.log("Crawling Complete!")
        self.log("="*50)
        self.log(f"Story ID: {story_id}")
        self.log(f"Chapters created (new): {chapters_created}")
        self.log(f"Chapters existed (skipped): {chapters_existed + chapters_uploaded_existed}")
        self.log(f"Total processed: {chapters_created + chapters_existed + chapters_uploaded_existed}")
        self.log("")


def main():
    if len(sys.argv) > 1 and sys.argv[1] == '--worker':
        try:
            crawler = NovelCrawler()
            crawler.run_worker_mode()
        except Exception as e:
            print(f"Worker Error: {e}", flush=True)
            import traceback
            traceback.print_exc()
        return

    if len(sys.argv) < 2:
        print("Usage: python crawler.py <url> [max_pages]")
        print("       python crawler.py --worker")
        print("\nExamples:")
        print("  Worker:   python crawler.py --worker")
        print("  Novel:    python crawler.py https://www.ttkan.co/novel/chapters/novel_id")
        print("  Category: python crawler.py https://www.ttkan.co/novel/rank")
        print("  Category: python crawler.py https://www.ttkan.co/novel/rank 5")
        sys.exit(1)
    
    url = sys.argv[1]
    max_pages = int(sys.argv[2]) if len(sys.argv) > 2 else None
    
    try:
        crawler = NovelCrawler()
        
        # Detect URL type and call appropriate method
        if '/novel/rank' in url or '/novel/class' in url:
            # Category URL
            crawler.crawl_category(url, max_pages)
        elif '/novel/chapters/' in url:
            # Novel URL
            crawler.crawl_novel(url)
        else:
            # Fallback for old xbanxia URLs or unknown
            if '/list/' in url:
                crawler.crawl_category(url, max_pages)
            elif '/books/' in url:
                crawler.crawl_novel(url)
            else:
                print(f"Error: Unknown URL type: {url}", flush=True)
                print("URL should contain '/novel/rank' (category) or '/novel/chapters/' (novel)", flush=True)
                sys.exit(1)
            
    except Exception as e:
        print(f"Error: {e}", flush=True)
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == '__main__':
    main()
