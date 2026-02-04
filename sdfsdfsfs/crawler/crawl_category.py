"""
Category crawler - Crawls all novels from a category page with pagination
"""

import sys
import time
from crawler import NovelCrawler


def crawl_category(category_url, max_pages=None):
    """Crawl all novels from a category with pagination"""
    crawler = NovelCrawler()
    
    current_url = category_url
    page_num = 1
    total_novels_processed = 0
    
    print("\n" + "="*60)
    print(f"Starting Category Crawl: {category_url}")
    print("="*60 + "\n")
    
    while current_url:
        print(f"\n{'='*60}")
        print(f"Processing Category Page {page_num}")
        print(f"{'='*60}\n")
        
        # Parse category page
        novels, pagination = crawler.parser.parse_category_page(current_url)
        
        print(f"Found {len(novels)} novels on page {pagination['current']}/{pagination['total']}")
        print(f"Category Page: {current_url}\n")
        
        # Check if we should stop at max_pages
        if max_pages and page_num > max_pages:
            print(f"\nReached maximum pages limit ({max_pages}). Stopping.")
            break
        
        # Crawl each novel on this page
        for idx, novel_url in enumerate(novels, 1):
            print(f"\n[Novel {idx}/{len(novels)} on page {page_num}]")
            print(f"URL: {novel_url}")
            
            # Check if novel was already processed
            state = crawler.file_manager.load_crawler_state()
            novel_progress = state['processed_novels'].get(novel_url, {})
            
            if novel_progress.get('status') == 'completed':
                print(f"✓ Skipping: Already completed ({novel_progress.get('chapters_crawled')} chapters)")
                total_novels_processed += 1
                continue
            
            # Crawl the novel
            try:
                crawler.crawl_novel(novel_url)
                total_novels_processed += 1
                print(f"\n✓ Novel completed successfully\n")
            except KeyboardInterrupt:
                print("\n\n⚠ Crawl interrupted by user")
                print(f"Progress saved. Processed {total_novels_processed} novels so far.")
                print(f"Resume by running the same command again.\n")
                sys.exit(0)
            except Exception as e:
                print(f"\n✗ Error crawling novel: {e}")
                # Mark as failed
                crawler.file_manager.update_novel_progress(
                    novel_url, 'failed',
                    chapters_crawled=0,
                    chapters_total=0,
                    story_id=None
                )
                print(f"Continuing to next novel...\n")
                continue
            
            # Small delay between novels
            time.sleep(2)
        
        # Update state with last processed page
        state = crawler.file_manager.load_crawler_state()
        state['last_category_page'] = current_url
        crawler.file_manager.save_crawler_state(state)
        
        # Move to next page
        if pagination['next']:
            current_url = pagination['next']
            page_num += 1
            print(f"\n→ Moving to next page: {current_url}")
            time.sleep(3)  # Delay between pages
        else:
            print(f"\n✓ Reached last page of category")
            break
    
    print("\n" + "="*60)
    print("Category Crawl Complete!")
    print("="*60)
    print(f"Total novels processed: {total_novels_processed}")
    print(f"Pages processed: {page_num}")
    print("")


def main():
    if len(sys.argv) < 2:
        print("Usage: python crawl_category.py <category_url> [max_pages]")
        print("\nExample:")
        print("  python crawl_category.py https://www.ttkan.co/novel/rank")
        print("  python crawl_category.py https://www.ttkan.co/novel/rank 5")
        print("\nThis will crawl all novels from the category, with automatic resume on failure.")
        sys.exit(1)
    
    category_url = sys.argv[1]
    max_pages = int(sys.argv[2]) if len(sys.argv) > 2 else None
    
    if max_pages:
        print(f"Will process maximum {max_pages} pages")
    
    crawl_category(category_url, max_pages)


if __name__ == '__main__':
    main()
