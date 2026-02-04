# Python Crawler - Quick Reference

## Installation
```bash
pip install -r requirements.txt
```

## Setup Google Cloud Translation

### Option 1: Service Account Key (Recommended)
1. Go to Google Cloud Console → IAM & Admin → Service Accounts
2. Create service account with "Cloud Translation API User" role
3. Create JSON key and download
4. Set environment variable:
```bash
# Windows PowerShell
$env:GOOGLE_APPLICATION_CREDENTIALS="C:\path\to\key.json"

# Windows CMD
set GOOGLE_APPLICATION_CREDENTIALS=C:\path\to\key.json
```

### Option 2: gcloud CLI
**First install gcloud**: https://cloud.google.com/sdk/docs/install

Then run:
```bash
gcloud auth application-default login
```

## Configuration
Edit `config.json`:
```json
{
  "wordpress_url": "http://volarenewnovels.local",
  "api_key": "Fr9yOke8qhGvVthc65gp0CQVvacrW0Cb",
  "google_project_id": "jadepetals",
  "max_chapters_per_run": 10,
  "delay_between_requests": 2,
  "translate": true,
  "target_language": "en"
}
```

## Usage
```bash
# Crawl single novel
python crawler.py https://www.ttkan.co/novel/chapters/novel_id

# Crawl category
python crawler.py https://www.ttkan.co/novel/rank

# Process more chapters (edit config.json first)
# Set "max_chapters_per_run": 50
python crawler.py https://www.ttkan.co/novel/chapters/novel_id
```

## Module Structure

### crawler.py
Main orchestrator. Coordinates all modules.

### parser.py
HTML parsing for ttkan.co:
- `parse_novel_page(url)`: Extract title, author, description, chapters (via API)
- `parse_chapter_page(url)`: Extract chapter content, remove AMP tags
- `parse_category_page(url)`: Extract novel links from rank/category pages

### translator.py
Google Cloud Translate integration:
- Supports v2 (Basic) and v3 (Advanced) APIs
- Auto-chunks long text
- Handles errors gracefully

### wordpress_api.py
WordPress REST API client:
- `test_connection()`: Health check
- `create_story(data)`: Create/get story
- `create_chapter(data)`: Create chapter

### file_manager.py
File operations:
- `save_metadata()`: Save novel info to JSON
- `save_chapter()`: Save raw/translated HTML
- `create_directories()`: Setup folder structure

### config_loader.py
Loads `config.json`

## Output Structure
```
novels/
└── novel_396941/
    ├── metadata.json              # Novel info
    ├── chapters_raw/              # Original Chinese
    │   ├── chapter_001.html
    │   └── chapter_002.html
    └── chapters_translated/       # English
        ├── chapter_001.html
        └── chapter_002.html
```

## Workflow
1. Parse novel page → Extract metadata + chapter URLs
2. Translate metadata (if enabled)
3. Create story in WordPress via REST API
4. For each chapter:
   - Fetch & parse chapter page
   - Translate content (if enabled)
   - Save to local files
   - Create in WordPress
5. Repeat with delay between requests

## Common Issues

### ModuleNotFoundError
```bash
pip install -r requirements.txt
```

### Translation disabled warning
Translation requires:
1. `google_project_id` in config.json
2. `GOOGLE_APPLICATION_CREDENTIALS` environment variable set
3. Google Cloud Translation API enabled in your project

### API connection failed
- Verify WordPress site is running
- Check `wordpress_url` in config.json
- Confirm API key matches WordPress admin

## Tips
- Start with `max_chapters_per_run: 2` for testing
- Use `delay_between_requests: 2-5` to avoid rate limiting
- Check `novels/` folder for saved content
- Review WordPress admin logs if chapters don't appear
