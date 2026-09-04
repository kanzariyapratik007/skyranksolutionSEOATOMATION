# SEO 80/20 System

**80% Automatic · 20% Manual**

## Quick Setup

### 1. Database
```sql
-- Import database.sql in phpMyAdmin or run:
mysql -u root -p < database.sql
```

### 2. Config
Edit `config.php` (database only). API keys go in **API Keys** page or `config.local.php`:
```php
define('DB_HOST', '127.0.0.1:3307');  // XAMPP MySQL port
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'seo_system');
```
Copy `config.local.php.example` → `config.local.php` OR use **api-setup.php** after login.

**Required API:** [OpenAI ChatGPT](https://platform.openai.com/api-keys) (`sk-...`)  
**Optional:** [DataForSEO](https://app.dataforseo.com) rank

Open `setup.php` in browser to verify installation.

### 3. Upload
Upload all files to your server's `seo-system/` folder.

### 4. Register & Login
Go to `http://yourdomain.com/seo-system/register.php`

### 5. Add Project
- Website URL: your site
- Target Keyword: e.g., "power bi training in btm"
- Target Site: site to analyze

### 6. Run SEO
Click **"Run All SEO"** button — system does 80% automatically!

---

## Cron Jobs Setup

```bash
# Daily rank check at 9 AM
0 9 * * * php /path/to/seo-system/cron-daily.php

# Weekly report every Monday at 9 AM
0 9 * * 1 php /path/to/seo-system/cron-weekly.php
```

---

## What's 80% Auto vs 20% Manual

| Feature | 80% Auto | 20% Manual |
|---------|----------|------------|
| Backlinks | Prepares 35+ sites with instructions | You submit on each site |
| On-Page SEO | Fetches & analyzes your site | You apply the fixes |
| Rank Tracking | Checks Google rank daily | Nothing needed |
| Keywords | Fetches 100+ from Google Suggest | You select which to target |
| Competitors | Finds & analyzes top 5 | You review & act |
| Content | Generates 5 SEO articles | You edit & publish |
| Excel Report | Auto-generates full report | One-click download |

---

## Files

```
seo-system/
├── config.php          # Database & settings
├── database.sql        # DB schema
├── index.php           # Login
├── register.php        # Register
├── logout.php          # Logout
├── dashboard.php       # Main dashboard
├── add-project.php     # Add new project
├── seo-80-20.php       # Main SEO hub
├── backlink-system.php # 35+ backlink opportunities
├── onpage-analyzer.php # On-page SEO checker
├── rank-tracker.php    # Google rank tracking
├── keyword-research.php# Google Suggest keywords
├── competitor-analysis.php # Top 5 competitors
├── content-generator.php   # 5 SEO articles
├── export-excel.php    # CSV/Excel export
├── cron-daily.php      # Daily rank check
├── cron-weekly.php     # Weekly report
├── style.css           # Styles
├── script.js           # JavaScript
├── .htaccess           # Security & performance
└── includes/
    └── navbar.php      # Navigation
```
