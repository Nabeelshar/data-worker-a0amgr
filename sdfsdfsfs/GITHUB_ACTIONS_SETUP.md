# GitHub Actions Crawler Setup

## Setup Instructions

### 1. Push your code to GitHub

```bash
cd "c:\Users\Nab\Local Sites\volarenewnovels\app\public\wp-content\plugins\getnovels"

# Initialize git if not already done
git init
git add .
git commit -m "Add novel crawler with GitHub Actions"

# Create repo on GitHub, then:
git remote add origin https://github.com/YOUR_USERNAME/getnovels.git
git branch -M main
git push -u origin main
```

### 2. Add Secrets to GitHub

Go to: **GitHub Repo → Settings → Secrets and variables → Actions → New repository secret**

Add these secrets:

1. **GOOGLE_CREDENTIALS_JSON**
   - Copy the entire contents of `jadepetals-1b7f6ae3a0ad.json`
   - Paste as the secret value

2. **Optional: Update config.json with your live site URL**
   - The crawler will use `config.json` settings
   - Make sure `wordpress_url` and `api_key` are correct

### 3. Enable GitHub Actions

1. Go to **Actions** tab in your GitHub repo
2. Click "I understand my workflows, go ahead and enable them"
3. You'll see "Novel Crawler" workflow listed

### 4. Run the Crawler

**Option A: Manual Run (Test First)**
1. Go to **Actions → Novel Crawler**
2. Click "Run workflow" dropdown
3. Enter:
   - Category URL: `https://www.xbanxia.cc/list/1_1.html`
   - Max pages: `1` (for testing)
4. Click "Run workflow"

**Option B: Automatic Schedule**
- Runs every 6 hours automatically: 00:00, 06:00, 12:00, 18:00 UTC
- Processes 5 pages per run (configurable)

### 5. Monitor Progress

- **Actions tab** → Click on running workflow → View logs in real-time
- Download `crawler_state.json` artifact to see progress
- Check your WordPress site for new chapters

## Schedule Options

Edit `.github/workflows/crawler.yml` to change schedule:

```yaml
# Every 6 hours (current)
- cron: '0 */6 * * *'

# Every 12 hours
- cron: '0 */12 * * *'

# Once per day at 3 AM UTC
- cron: '0 3 * * *'

# Twice per day at 6 AM and 6 PM UTC
- cron: '0 6,18 * * *'
```

## Troubleshooting

### If workflow fails:
1. Check **Actions** logs for error messages
2. Download error logs artifact
3. Verify secrets are set correctly
4. Check config.json has correct WordPress URL and API key

### If hitting timeout (6 hours):
- Reduce `max_pages` in the workflow file
- Currently set to 5 pages per run
- Each page has ~30 novels, each novel processes 11 chapters

### Cost Estimation:
- Education plan: 3,000 minutes/month FREE
- Each run: ~30-60 minutes (depending on pages)
- 4 runs/day × 60 min = 240 min/day
- ~7,200 min/month (exceeds free tier)
- **Solution:** Reduce to 2 runs/day or fewer pages per run

## Recommended Settings:

**For staying within free tier:**
```yaml
schedule:
  - cron: '0 6,18 * * *'  # 2x per day
# max_pages: 3-5
```

This gives you:
- 2 runs per day
- ~60 minutes per run
- ~120 minutes/day = 3,600 min/month ✅ (within free tier)
