import os
try:
    import ebooklib
    from ebooklib import epub
except ImportError:
    ebooklib = None
    epub = None
from bs4 import BeautifulSoup

class EpubParser:
    def __init__(self, logger):
        self.logger = logger
        if not ebooklib:
            self.logger.warning("ebooklib not installed. EpubParser will not function.")

    def parse_novel_info(self, epub_path):
        """
        Parses metadata from an EPUB file.
        Returns a dictionary with title, author, description, cover_url, and empty chapters list.
        """
        if not ebooklib:
            self.logger.error("ebooklib not available.")
            return None

        try:
            book = epub.read_epub(epub_path)
            
            # Extract title (DC_TITLE)
            # get_metadata returns a list of tuples like [(value, metadata_dict), ...]
            titles = book.get_metadata('DC', 'title')
            title = titles[0][0] if titles else "Unknown Title"
            
            # Extract author (DC_CREATOR)
            creators = book.get_metadata('DC', 'creator')
            author = creators[0][0] if creators else "Unknown Author"
            
            # Extract description (DC_DESCRIPTION)
            descriptions = book.get_metadata('DC', 'description')
            description = descriptions[0][0] if descriptions else "No description available"
            
            # Clean HTML from description if present
            if description and '<' in description and '>' in description:
                soup = BeautifulSoup(description, 'html.parser')
                description = soup.get_text()

            # Extract cover image (placeholder)
            # Could iterate items to find ITEM_COVER or check manifest
            cover_url = None
            
            return {
                'title': title,
                'author': author,
                'description': description,
                'cover_url': cover_url,
                'chapters': []
            }

        except Exception as e:
            self.logger.error(f"Error parsing EPUB info from {epub_path}: {e}")
            return None

    def extract_chapters(self, epub_path):
        """
        Extracts chapters from an EPUB file.
        Returns a list of dictionaries with title, url, and content.
        """
        if not ebooklib:
            self.logger.error("ebooklib not available.")
            return []

        chapters = []
        try:
            book = epub.read_epub(epub_path)
            
            # Iterate through documents
            # Note: For strict reading order, iterating 'spine' is usually better, 
            # but we follow the instruction to iterate items of type ITEM_DOCUMENT.
            count = 1
            for item in book.get_items_of_type(ebooklib.ITEM_DOCUMENT):
                try:
                    content = item.get_content()
                    soup = BeautifulSoup(content, 'html.parser')
                    
                    # Extract title
                    # Try <title>, then <h1>, then fallback
                    chapter_title = f"Chapter {count}"
                    if soup.title and soup.title.string:
                        chapter_title = soup.title.string.strip()
                    else:
                        h1 = soup.find('h1')
                        if h1:
                            chapter_title = h1.get_text().strip()
                    
                    # Extract content (using body if available to avoid full html structure repetition)
                    body = soup.find('body')
                    if body:
                        # minimal cleanup could happen here
                        html_content = body.decode_contents()
                    else:
                        html_content = str(soup)

                    chapters.append({
                        'title': chapter_title,
                        'url': f"file://{os.path.abspath(epub_path)}#{item.get_id()}",
                        'content': html_content
                    })
                    count += 1
                except Exception as item_error:
                    self.logger.warning(f"Failed to parse item {item.get_id()} in {epub_path}: {item_error}")
                    continue
            
            return chapters

        except Exception as e:
            self.logger.error(f"Error extracting chapters from {epub_path}: {e}")
            return []
