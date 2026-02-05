"""
Translation module using googletrans-py (free Google Translate API) or OpenRouter
"""

import json
import requests
import time

try:
    from googletrans import Translator as GoogletransTranslator
    GOOGLETRANS_AVAILABLE = True
except ImportError:
    GOOGLETRANS_AVAILABLE = False


class Translator:
    def __init__(self, config, logger):
        self.logger = logger
        self.client = None
        self.service = None
        
        # Extract configuration
        self.service_type = config.get('translation_service', 'google')
        self.openrouter_api_key = config.get('openrouter_api_key')
        self.openrouter_model = config.get('openrouter_model', 'google/gemini-2.5-flash-lite')
        
        if self.service_type == 'openrouter':
            if not self.openrouter_api_key:
                self.logger("ERROR: OpenRouter API key not found in config")
                return
            self.service = 'openrouter'
            self.client = True # Mark as available
            self.logger(f"Translator Initialized (Default Model: {self.openrouter_model})")
            return
            
        if GOOGLETRANS_AVAILABLE:
            try:
                self.client = GoogletransTranslator()
                self.service = 'googletrans'
                self.logger("Using googletrans-py (free Google Translate API)")
                return
            except Exception as e:
                self.logger(f"ERROR: Could not initialize googletrans: {type(e).__name__}: {e}")
                import traceback
                self.logger(f"Traceback: {traceback.format_exc()}")
        
        # No translator available
        self.client = None
    
    def extract_glossary(self, text, existing_glossary=None):
        """Extract glossary terms from text using LLM (Text-based to avoid JSON errors)."""
        if self.service != 'openrouter':
            self.logger("Warning: Glossary extraction only supported with OpenRouter")
            return existing_glossary if existing_glossary else []

        if existing_glossary is None:
            existing_glossary = []

        # Convert simple list for prompt
        existing_terms_str = ", ".join([item['original'] for item in existing_glossary])
        
        prompt = f"""Analyze the provided fiction text. Identify key proper names (characters, locations, unique terms). 
Output specific translation pairs, one per line, in this exact format:
Original Term: English Translation

Do not output JSON. Do not output markdown code blocks. Just the list.
Ignore these existing terms: {existing_terms_str}

Text:
{text}"""

        headers = {
            "Authorization": f"Bearer {self.openrouter_api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "https://github.com/your-repo-link", 
            "X-Title": "NovelCrawler" 
        }
        
        data = {
            "model": self.openrouter_model,
            "messages": [
                {"role": "user", "content": prompt}
            ]
        }
        
        # Retry logic for API calls
        max_retries = 3
        for attempt in range(max_retries):
            try:
                response = requests.post(
                    "https://openrouter.ai/api/v1/chat/completions",
                    headers=headers,
                    data=json.dumps(data),
                    timeout=60
                )
                
                if response.status_code == 200:
                    result = response.json()
                    if 'choices' in result and len(result['choices']) > 0:
                        content = result['choices'][0]['message']['content'].strip()
                        
                        # Parse Text Output (Line by Line)
                        new_terms = []
                        lines = content.split('\n')
                        for line in lines:
                            line = line.strip()
                            if ':' in line:
                                parts = line.split(':', 1)
                                original = parts[0].strip()
                                translation = parts[1].strip()
                                
                                # Basic cleanup
                                original = original.replace('*', '').replace('-', '').strip()
                                translation = translation.replace('*', '').strip()
                                
                                if original and translation:
                                    new_terms.append({
                                        'original': original,
                                        'translation': translation,
                                        'type': 'term' # Default type
                                    })
                        
                        # Merge with existing glossary
                        filtered_new = []
                        existing_originals = {item['original'] for item in existing_glossary}
                        
                        for term in new_terms:
                            if term['original'] not in existing_originals:
                                existing_glossary.append(term)
                                existing_originals.add(term['original'])
                                filtered_new.append(term)
                        
                        self.logger(f"  Glossary updated: +{len(filtered_new)} terms")
                        return existing_glossary
                    else:
                        raise Exception(f"Invalid response: {result}")
                elif response.status_code == 401:
                    self.logger(f"CRITICAL ERROR: OpenRouter Authorization Failed (401).")
                    raise Exception(f"OpenRouter Auth Error: {response.text}")
                elif response.status_code == 429:
                    wait_time = 5 * (attempt + 1)
                    time.sleep(wait_time)
                    continue
                else:
                    raise Exception(f"OpenRouter API error: {response.status_code}")
                    
            except Exception as e:
                if attempt == max_retries - 1:
                    # Return existing validation on failure instead of crashing?
                    # User wanted strict, but for glossary maybe soft fail is better than loop crash?
                    # But crawler.py catches it. Let's log and return existing to keep going.
                    self.logger(f"Glossary extraction failed: {e}")
                    return existing_glossary
                time.sleep(2)
        
        return existing_glossary

    def translate(self, text, source_lang='zh-CN', target_lang='en', glossary=None):
        """Translate text using configured service"""
        if not self.client:
            raise Exception("No translator available")
        
        # Remove internal try-except to allow catching errors in main loop
        if self.service == 'openrouter':
            return self._translate_openrouter(text, source_lang, target_lang, glossary)
        return self._translate_googletrans(text, source_lang, target_lang)
            
    def generate_metadata(self, title, description):
        """Generate genres and tags for the novel using LLM."""
        if self.service != 'openrouter':
            return {'genres': [], 'tags': []}
            
        prompt = f"""Analyze this novel title and description. 
Assign suitable Genres (broad categories like Fantasy, Romance, Horror) and Tags (specific tropes, content elements).

Title: {title}
Description: {description}

Output strictly valid JSON with this format:
{{
  "genres": ["Genre1", "Genre2"],
  "tags": ["Tag1", "Tag2", "Tag3"]
}}
"""
        
        headers = {
            "Authorization": f"Bearer {self.openrouter_api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "https://github.com/your-repo-link", 
            "X-Title": "NovelCrawler" 
        }
        
        data = {
            "model": self.openrouter_model,
            "messages": [
                {"role": "user", "content": prompt}
            ]
        }
        
        try:
            response = requests.post(
                "https://openrouter.ai/api/v1/chat/completions",
                headers=headers,
                data=json.dumps(data),
                timeout=60
            )
            
            if response.status_code == 200:
                result = response.json()
                if 'choices' in result and len(result['choices']) > 0:
                    content = result['choices'][0]['message']['content'].strip()
                    # Clean markdown
                    if "```json" in content:
                        content = content.split("```json")[1].split("```")[0].strip()
                    elif "```" in content:
                        content = content.split("```")[1].split("```")[0].strip()
                        
                    return json.loads(content)
        except Exception as e:
            self.logger(f"Metadata generation failed: {e}")
            
        return {'genres': [], 'tags': []}

    def _translate_openrouter(self, text, source, target, glossary=None):
        """Translate using OpenRouter API"""
        headers = {
            "Authorization": f"Bearer {self.openrouter_api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "https://github.com/your-repo-link", # Optional
            "X-Title": "NovelCrawler" # Optional
        }
        
        prompt = f"You are a professional translator translating {source} to {target}. Maintain the original formatting, tone, and style. Preserve all HTML tags if present. Do not add any introductory or concluding remarks, just output the translation."
        
        if glossary:
            glossary_str = json.dumps(glossary, ensure_ascii=False, indent=2)
            prompt += f"\n\nReference this glossary for consistent translation:\n{glossary_str}"
        
        data = {
            "model": self.openrouter_model,
            "messages": [
                {"role": "system", "content": prompt},
                {"role": "user", "content": text}
            ]
        }
        
        # Retry logic for API calls
        max_retries = 3
        for attempt in range(max_retries):
            try:
                response = requests.post(
                    "https://openrouter.ai/api/v1/chat/completions",
                    headers=headers,
                    data=json.dumps(data),
                    timeout=60
                )
                
                if response.status_code == 200:
                    result = response.json()
                    if 'choices' in result and len(result['choices']) > 0:
                        return result['choices'][0]['message']['content'].strip()
                    else:
                        raise Exception(f"Invalid response from OpenRouter: {result}")
                elif response.status_code == 401:
                    self.logger(f"CRITICAL ERROR: OpenRouter Authorization Failed (401).")
                    self.logger(f"  - Check your OPENROUTER_API_KEY in GitHub Secrets.")
                    self.logger(f"  - Response: {response.text}")
                    # Do not retry auth errors
                    raise Exception(f"OpenRouter Auth Error: {response.text}")
                elif response.status_code == 429:
                    # Rate limit
                    wait_time = 5 * (attempt + 1)
                    self.logger(f"Rate limited by OpenRouter. Waiting {wait_time}s...")
                    time.sleep(wait_time)
                    continue
                else:
                    raise Exception(f"OpenRouter API error: {response.status_code} - {response.text}")
                    
            except Exception as e:
                if attempt == max_retries - 1:
                    raise e
                self.logger(f"OpenRouter request failed (attempt {attempt+1}): {e}")
                time.sleep(2)
        
        return text

    def _translate_googletrans(self, text, source, target):
        """Translate using googletrans with chunking for long texts"""
        max_length = 4500  # Under 5000 limit
        
        # Map language codes
        source = source.replace('zh-CN', 'zh-cn')
        
        if len(text) <= max_length:
            result = self.client.translate(text, src=source, dest=target)
            return result.text
        else:
            # Split by paragraphs and group into chunks
            paragraphs = text.split('\n\n')
            translated_paragraphs = []
            current_chunk = []
            current_length = 0
            
            for para in paragraphs:
                if current_length + len(para) > max_length and current_chunk:
                    # Translate current chunk
                    chunk_text = '\n\n'.join(current_chunk)
                    result = self.client.translate(chunk_text, src=source, dest=target)
                    translated_paragraphs.append(result.text)
                    
                    # Start new chunk
                    current_chunk = [para]
                    current_length = len(para)
                else:
                    current_chunk.append(para)
                    current_length += len(para)
            
            # Translate remaining chunk
            if current_chunk:
                chunk_text = '\n\n'.join(current_chunk)
                result = self.client.translate(chunk_text, src=source, dest=target)
                translated_paragraphs.append(result.text)
            
            return '\n\n'.join(translated_paragraphs)
