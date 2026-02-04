"""
Configuration loader with environment variable support
"""

import json
import os


def load_config(config_path='config.json'):
    """Load configuration from JSON file with environment variable overrides"""
    with open(config_path, 'r', encoding='utf-8') as f:
        config = json.load(f)
    
    # Override sensitive values from environment if available
    if os.environ.get('WORDPRESS_API_KEY'):
        config['api_key'] = os.environ.get('WORDPRESS_API_KEY')
    
    if os.environ.get('OPENROUTER_API_KEY'):
        config['openrouter_api_key'] = os.environ.get('OPENROUTER_API_KEY')
    
    # Set defaults for language settings if not present
    if 'default_source_lang' not in config:
        config['default_source_lang'] = 'zh-CN'
    
    if 'default_target_lang' not in config:
        config['default_target_lang'] = 'en'
    
    return config
