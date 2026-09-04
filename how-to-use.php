<?php
require_once 'config.php';
requireMenuPermission('how-to-use');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>How to Use - SEO 80/20 System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="style.css" rel="stylesheet">
<style>
.guide-section { border-radius: 12px; margin-bottom: 25px; border: 1px solid #e0e0e0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
.guide-header { padding: 15px 20px; border-top-left-radius: 12px; border-top-right-radius: 12px; font-weight: bold; color: white; display: flex; align-items: center; }
.guide-body { padding: 20px; background-color: #ffffff; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; }
.badge-step { font-size: 14px; padding: 5px 12px; border-radius: 20px; }
.step-number { width: 30px; height: 30px; background: #0d6efd; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 10px; }
.gu-text { color: #555; line-height: 1.6; }
.eng-hint { font-size: 0.85rem; color: #6c757d; display: block; margin-top: 2px; }
.highlight-box { background: #f8f9fa; border-left: 4px solid #ffc107; padding: 12px; margin: 10px 0; border-radius: 0 8px 8px 0; font-size: 0.9rem; }
.screen-tag { background: #e9ecef; color: #495057; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 0.85rem; }
</style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4" style="max-width: 960px;">

  <div class="text-center mb-5">
    <h2 class="fw-bold text-primary"><i class="fas fa-graduation-cap me-2"></i>SEO 80/20 System — Complete Guide</h2>
    <p class="text-muted">Simple and detailed step-by-step guidance</p>
    <div class="d-flex justify-content-center gap-2 mt-2">
      <span class="badge bg-primary px-3 py-2"><i class="fas fa-robot me-1"></i> 80% Automatic</span>
      <span class="badge bg-success px-3 py-2"><i class="fas fa-user me-1"></i> 20% Manual</span>
      <span class="badge bg-dark px-3 py-2"><i class="fas fa-brain me-1"></i> ChatGPT Powered</span>
    </div>
  </div>

  <!-- SECTION 1: Client Profile Details Overview -->
  <div class="guide-section">
    <div class="guide-header bg-primary">
      <i class="fas fa-user-circle fa-lg me-2"></i> 1. Client Profile and General Details
    </div>
    <div class="guide-body gu-text">
      <p>When a new client arrives, fill in the following details correctly in the <strong>Client Profile</strong> of their project:</p>
      <ul>
        <li><strong>Business Name:</strong> True name of shop or company (e.g. <code>Learnmore Technologies</code>).</li>
        <li><strong>Contact Name:</strong> Name of the owner or contact person.</li>
        <li><strong>Phone Number &amp; Email:</strong> Client's phone number and email ID. This information is required for local search schema and contact page.</li>
      </ul>
    </div>
  </div>

  <!-- SECTION 3: Google Services Access Details -->
  <div class="guide-section">
    <div class="guide-header bg-dark">
      <i class="fab fa-google fa-lg me-2"></i> 2. Google Services Access Details
    </div>
    <div class="guide-body gu-text">
      
      <!-- Google Search Console -->
      <div class="mb-4">
        <h5 class="text-primary fw-bold"><i class="fas fa-search me-2"></i>Google Search Console (GSC) Access</h5>
        <p><strong>What is this?</strong> This checks whether your website is indexed on Google.</p>
        <div class="highlight-box">
          <strong>How to get in simple steps:</strong><br>
          1. Login to <a href="https://search.google.com/search-console" target="_blank">Google Search Console</a>.<br>
          2. Add new domain (e.g. <code>https://example.com</code>).<br>
          3. Add agency email ID (e.g. <code>your-agency@gmail.com</code>) as a <strong>Delegated Owner</strong> or <strong>Full Access User</strong>.<br>
          4. Enter this email in the profile and press the <strong>"Auto-Verify GSC"</strong> button. Selenium will automatically place the verification tag on the WordPress site!
        </div>
      </div>

      <hr>

      <!-- Google Analytics -->
      <div class="mb-4">
        <h5 class="text-success fw-bold"><i class="fas fa-chart-line me-2"></i>Google Analytics (GA4) Property ID</h5>
        <p><strong>What is this?</strong> This tracks how many visitors come to your site daily and from where.</p>
        <div class="highlight-box">
          <strong>How to get the code in simple steps:</strong><br>
          1. Login to <a href="https://analytics.google.com" target="_blank">Google Analytics</a>.<br>
          2. Click on **Admin** (Settings icon).<br>
          3. Go to **Data Streams** and click on your website.<br>
          4. There you will see the **Measurement ID** in the top right (in `G-XXXXXXXXXX` format).<br>
          5. Copy this ID, paste it here, and click the **"Auto-Install GA"** button. Selenium will automatically set the tracking code in the header of the site!
        </div>
      </div>

      <hr>

      <!-- Google Ads -->
      <div>
        <h5 class="text-warning fw-bold"><i class="fas fa-ad me-2"></i>Google Ads Conversion ID</h5>
        <p><strong>What is this?</strong> This tag is required to run ads on Google and track how many calls or leads were received from the site.</p>
        <div class="highlight-box">
          <strong>How to get the ID in simple steps:</strong><br>
          1. Open <a href="https://ads.google.com" target="_blank">Google Ads Account</a>.<br>
          2. Click on **Tools and Settings** -> **Measurement** -> **Conversions**.<br>
          3. Set up new conversion action. Finally, go to the **Tag Setup** section.<br>
          4. There you will see the **Conversion ID** (in `AW-XXXXXXXXXX` format).<br>
          5. Copy and paste this into the profile and press the **"Auto-Install Ads Tag"** button. Selenium will automatically set this tracking code in the website's code!
        </div>
      </div>

    </div>
  </div>

  <!-- SECTION 4: CMS Admin Login Credentials -->
  <div class="guide-section">
    <div class="guide-header bg-danger">
      <i class="fas fa-lock fa-lg me-2"></i> 3. Website Admin Login Credentials
    </div>
    <div class="guide-body gu-text">
      <p>Our system logs into the backend of the client's site to automatically perform on-page fixes, Google verification, Analytics, and Schema setups. Fill in these details for that:</p>
      <ul>
        <li><strong>Admin Login URL:</strong> The login path of the client's WordPress site (e.g. <code>https://example.com/wp-admin</code>).</li>
        <li><strong>Admin Username / Email:</strong> Username of the WordPress admin account (e.g. <code>admin</code>).</li>
        <li><strong>Admin Password:</strong> Password of that account. (These details are securely saved in encrypted format in the database).</li>
      </ul>
      <div class="alert alert-warning small">
        <i class="fas fa-exclamation-triangle me-1"></i> <strong>Note:</strong> If you do not fill in these details, Selenium will not be able to auto-setup and you will have to copy-paste the code manually.
      </div>
    </div>
  </div>

  <!-- SECTION 5: Competitor Websites -->
  <div class="guide-section">
    <div class="guide-header bg-success">
      <i class="fas fa-users fa-lg me-2"></i> 4. Competitor Websites
    </div>
    <div class="guide-body gu-text">
      <p><strong>What is this?</strong> This field is for studying other sites in your client's industry that are ranking high on Google.</p>
      <div class="highlight-box">
        <strong>How to use:</strong><br>
        1. Search your keyword on Google.<br>
        2. Look at the domain names of the top 2-3 websites appearing on the first page.<br>
        3. Write those site names separated by a comma (`,`) here.<br>
        * For example: <code>competitor1.com, competitor2.in, competitor3.org</code><br>
        4. Save the profile. Our system will automatically compare their keywords, rankings, and page speeds and present the data!
      </div>
    </div>
  </div>

  <!-- SECTION 6: Local Business Schema -->
  <div class="guide-section">
    <div class="guide-header bg-warning text-dark">
      <i class="fas fa-map-marker-alt fa-lg me-2"></i> 5. Local SEO Schema One-Click Setup
    </div>
    <div class="guide-body gu-text">
      <p><strong>What is this?</strong> This local business data (JSON-LD) is required to rank on the first page for local area customers (e.g., Rajkot, Ahmedabad) in Google Maps and Google Search.</p>
      <div class="highlight-box">
        <strong>How to setup:</strong><br>
        1. In Section 2, fill in the exact shop/business address and operating hours.<br>
        * Address example: <code>101, Business Hub, Kalawad Road, Rajkot, Gujarat 360005</code><br>
        * Hours example: <code>Mo-Sa 09:00-19:00</code> (Monday to Saturday 9 AM to 7 PM)<br>
        2. Save the page.<br>
        3. Go to Section 6 and click the **"Auto-Install Local Business Schema"** button.<br>
        4. Selenium will automatically generate the location schema of your client and install it into the WordPress code!
      </div>
    </div>
  </div>

  <!-- SOCIAL ACCOUNTS MANAGEMENT -->
  <div class="guide-section">
    <div class="guide-header bg-info text-dark">
      <i class="fas fa-users-cog fa-lg me-2"></i> 6. How to link Social Media Accounts
    </div>
    <div class="guide-body gu-text">
      <p>How to set up accounts where our daily backlinks will be posted:</p>
      
      <!-- Single Platform Multiple Accounts -->
      <div class="mb-3">
        <h6 class="fw-bold text-primary"><i class="fas fa-plus-circle me-1"></i>How to add more than 5 accounts for 1 platform:</h6>
        <p>If you want to add 5 different Pinterest or WordPress accounts for the same client:</p>
        <ol>
          <li>Go to the **Submissions** page from the menu.</li>
          <li>Click **"Add Credentials"** or **"Add More Account"** on the desired platform (e.g. Pinterest).</li>
          <li>Enter the username and password for the first account and save.</li>
          <li>Click **"Add More Account"** again, write the second account credentials, and save.</li>
          <li>This way you can configure unlimited accounts. The system will automatically rotate through all accounts to post daily!</li>
        </ol>
      </div>

      <hr>

      <!-- Bulk Upload -->
      <div>
        <h6 class="fw-bold text-success"><i class="fas fa-file-import me-1"></i>Add all accounts at once (Bulk Add):</h6>
        <p>If you want to add all details at once without filling individual forms:</p>
        <ol>
          <li>Go to **Submissions** page and click on **"Bulk Add Accounts"** button.</li>
          <li>Write the accounts in the text box in the following format:<br>
          <code>platform,username,password</code></li>
          <li>Example:<br>
          <pre class="bg-light p-2 rounded small">bluesky,user1.bsky.social,app-password-here
pinterest,email1@gmail.com,password123
pinterest,email2@gmail.com,password456</pre></li>
          <li>Press **"Add All Accounts"**. Accounts for all platforms will be saved in a second!</li>
        </ol>
      </div>

    </div>
  </div>

  <!-- HOW TO RUN SCHEDULER DAILY -->
  <div class="guide-section">
    <div class="guide-header bg-success">
      <i class="fas fa-clock fa-lg me-2"></i> 7. How to run the Daily Scheduler
    </div>
    <div class="guide-body gu-text">
      <p>Use the following method to write auto-articles daily and submit them to sites automatically:</p>
      <ul>
        <li><strong>Method 1 (Manual Run):</strong> Double-click the **`run-scheduler.bat`** file inside the project folder on your computer every morning. It will start posting to all sites.</li>
        <li><strong>Method 2 (100% Automatic Windows Setup):</strong>
          <ol>
            <li>Open **Task Scheduler** in Windows.</li>
            <li>Click on **Create Basic Task** and name it: <code>SEO Poster</code>.</li>
            <li>Select **Daily** in task trigger and set the morning time.</li>
            <li>In Action, click **Start a program** and select the **`run-auto-schedule.bat`** file.</li>
            <li>Save it successfully. Now, as long as your computer is on, it will run automatically in the background every morning!</li>
          </ol>
        </li>
      </ul>
    </div>
  </div>

  <div class="text-center mt-4 mb-5">
    <a href="dashboard.php" class="btn btn-primary btn-lg me-3">
      <i class="fas fa-tachometer-alt me-2"></i>Go to Dashboard
    </a>
    <a href="add-project.php" class="btn btn-success btn-lg">
      <i class="fas fa-plus me-2"></i>Add New Project
    </a>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
