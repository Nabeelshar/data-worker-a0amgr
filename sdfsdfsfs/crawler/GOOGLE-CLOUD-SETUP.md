# Google Cloud Translation Setup Guide

## Prerequisites
- Google Cloud account
- Active billing enabled
- Project created (e.g., "jadepetals")

## Step-by-Step Setup

### 1. Enable Translation API
1. Go to https://console.cloud.google.com/
2. Select your project: **jadepetals**
3. Go to **APIs & Services** → **Library**
4. Search for "Cloud Translation API"
5. Click **Enable**

### 2. Create Service Account
1. Go to **IAM & Admin** → **Service Accounts**
2. Click **Create Service Account**
3. Name: `novel-translator`
4. Click **Create and Continue**
5. Grant role: **Cloud Translation API User**
6. Click **Done**

### 3. Create JSON Key
1. Find your service account in the list
2. Click the **⋮** (3 dots) menu → **Manage keys**
3. Click **Add Key** → **Create new key**
4. Choose **JSON**
5. Click **Create**
6. Save the downloaded file (e.g., `jadepetals-key.json`)

### 4. Configure Crawler
Place the JSON key file in a safe location, then:

**Windows PowerShell:**
```powershell
$env:GOOGLE_APPLICATION_CREDENTIALS="C:\path\to\jadepetals-key.json"
```

**Windows CMD:**
```cmd
set GOOGLE_APPLICATION_CREDENTIALS=C:\path\to\jadepetals-key.json
```

**To make it permanent (PowerShell as Administrator):**
```powershell
[System.Environment]::SetEnvironmentVariable('GOOGLE_APPLICATION_CREDENTIALS', 'C:\path\to\jadepetals-key.json', 'User')
```

### 5. Verify Setup
```bash
cd crawler
python crawler.py https://www.xbanxia.cc/books/396941.html
```

You should see:
```
Using Google Cloud Translation API v3 with project: jadepetals
```

## Troubleshooting

### Error: "Your default credentials were not found"
- Verify environment variable is set: `echo $env:GOOGLE_APPLICATION_CREDENTIALS`
- Check file path is correct
- Restart terminal/PowerShell after setting variable

### Error: "Permission denied"
- Verify service account has "Cloud Translation API User" role
- Check API is enabled in Google Cloud Console

### Translation still disabled
1. Check `config.json` has `"google_project_id": "jadepetals"`
2. Set environment variable correctly
3. Restart terminal

## Without Service Account (Alternative)

If you have gcloud installed, you can use:
```bash
gcloud auth application-default login
```

**Install gcloud**: https://cloud.google.com/sdk/docs/install

## Security Notes
- Never commit JSON key files to git
- Store keys securely
- Add `*.json` to `.gitignore`
- Rotate keys periodically in Google Cloud Console
