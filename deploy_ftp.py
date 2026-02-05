import ftplib
import os
import sys

FTP_HOST = "89.117.157.9"
FTP_USER = "u213229939"
FTP_PASS = "FeatherQuill$7777"
REMOTE_PATH = "public_html/wp-content/plugins"

def upload_files():
    try:
        print(f"Connecting to {FTP_HOST}...")
        ftp = ftplib.FTP(FTP_HOST)
        ftp.login(FTP_USER, FTP_PASS)
        print("Connected.")
        
        # Debug: List current directory
        print("Current directory:", ftp.pwd())
        
        # Try to find the correct path
        # Common paths to check
        potential_paths = [
            "public_html/wp-content/plugins",
            "domains/featherquill.org/public_html/wp-content/plugins",
            "wp-content/plugins"
        ]
        
        found_path = False
        for path in potential_paths:
            try:
                print(f"Trying to navigate to {path}...")
                ftp.cwd(path)
                found_path = True
                print(f"Success! Now in {ftp.pwd()}")
                break
            except Exception:
                print(f"Failed.")
                # Reset to root/home if failed navigation mess things up
                # FTP cwd usually just fails and stays put, but if we are deep in a wrong tree..
                # Re-login or go to root might be safer, but let's trust the loop order or add ftp.cwd("/")
                try:
                    ftp.cwd("/")
                except:
                    pass
        
        if not found_path:
             print("Could not find standard paths. Searching root...")
             ftp.dir()
             return

        # Navigate to plugins folder (already there if loop succeeded)

        # Find plugin folder
        plugins = ftp.nlst()
        target_folder = None
        if "workingcrawler" in plugins:
            target_folder = "workingcrawler"
        elif "getnovels-crawler" in plugins:
            target_folder = "getnovels-crawler"
        elif "getnovels" in plugins:
            target_folder = "getnovels"
        
        if not target_folder:
            print(f"Plugin folder not found in {ftp.pwd()}. Available: {plugins}")
            return

        print(f"Found plugin folder: {target_folder}")
        ftp.cwd(target_folder)
        
        # Upload main file (if modified)
        if os.path.exists("getnovels-crawler.php"):
            print("Uploading getnovels-crawler.php...")
            with open("getnovels-crawler.php", "rb") as f:
                ftp.storbinary("STOR getnovels-crawler.php", f)
        else:
            print("Error: getnovels-crawler.php not found locally.")

        # Upload includes
        try:
            ftp.cwd("includes")
        except:
             if "includes" not in ftp.nlst():
                print("Creating includes directory...")
                ftp.mkd("includes")
                ftp.cwd("includes")
        
        if os.path.exists("includes/class-crawler-rest-api.php"):
            print("Uploading includes/class-crawler-rest-api.php...")
            with open("includes/class-crawler-rest-api.php", "rb") as f:
                ftp.storbinary("STOR class-crawler-rest-api.php", f) 
            print("Uploaded class-crawler-rest-api.php successfully")
        else:
            print("Error: includes/class-crawler-rest-api.php not found locally.")

        # Upload get_key.php (temp)
        if os.path.exists("get_key.php"):
            print("Uploading get_key.php...")
            with open("get_key.php", "rb") as f:
                ftp.storbinary("STOR get_key.php", f)
            print("Uploaded get_key.php successfully")
            
        print("Upload complete!")
        ftp.quit()
        
    except Exception as e:
        print(f"FTP Error: {e}")

if __name__ == "__main__":
    # Ensure checking from correct directory
    # If this script is in root, os.getcwd() should be root
    print(f"Local working directory: {os.getcwd()}")
    upload_files()