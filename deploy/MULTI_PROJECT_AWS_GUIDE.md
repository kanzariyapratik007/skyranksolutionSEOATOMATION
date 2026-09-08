# 🌐 એક જ AWS EC2 સર્વર પર 2 અથવા વધુ પ્રોજેક્ટ ચલાવવાની ગાઈડ (Multi-Project AWS Deployment)

એક જ AWS EC2 Server પર **SEO System** ની સાથે **બીજો કોઈપણ પ્રોજેક્ટ (PHP/Node/Python/HTML)** કેવી રીતે સેટ કરવો તેની સરળ ગાઈડ.

---

## 📌 2 અલગ રીતો (2 Ways to Run Multiple Projects)

### 🔹 રીત 1: Virtual Hosts / Subdomains વડે (સૌથી ઉત્તમ રીત - Recommended)
જો તમારી પાસે ડોમેન્સ અથવા સબડોમેન્સ હોય:
- `seo.yourdomain.com` -> SEO System ચાલશે
- `project2.yourdomain.com` -> બીજો પ્રોજેક્ટ ચાલશે

### 🔹 રીત 2: Sub-folders વડે (Domain વગર - Using EC2 IP)
જો તમે ફક્ત EC2 IP થી ચલાવવા માગતા હોવ:
- `http://<EC2-IP>/seo-system/` -> SEO System
- `http://<EC2-IP>/project2/` -> બીજો પ્રોજેક્ટ

---

## 🚀 રીત 1 (Subdomain / VirtualHost) સેટઅપ કરવાની રીત

### Step 1: ફોલ્ડર સ્ટ્રક્ચર બનાવો
તમારા સર્વર પર બંને પ્રોજેક્ટ માટે અલગ ફોલ્ડર બનાવો:

```bash
# 1. SEO System માટે ફોલ્ડર
sudo mkdir -p /var/www/seo-system

# 2. બીજા પ્રોજેક્ટ માટે ફોલ્ડર
sudo mkdir -p /var/www/project2
```

---

### Step 2: VirtualHost સેટઅપ સ્ક્રિપ્ટ રન કરો

તમે આપણી સેટઅપ સ્ક્રિપ્ટ નો ઉપયોગ કરીને સેકન્ડોમાં નવું ડોમેન કોન્ફિગર કરી શકો છો:

```bash
# SEO System માટે
sudo ./deploy/setup_virtualhost.sh seo-system seo.yourdomain.com

# બીજા પ્રોજેક્ટ માટે
sudo ./deploy/setup_virtualhost.sh project2 project2.yourdomain.com
```

---

### Step 3: MySQL મા બંને પ્રોજેક્ટ માટે અલગ Database બનાવો

```bash
sudo mysql -u root

# 1. SEO System DB
CREATE DATABASE seo_system;

# 2. Second Project DB
CREATE DATABASE project2_db;

# Exit MySQL
EXIT;
```

પછી સંબંધિત SQL ફાઈલો ઈમ્પોર્ટ કરો:
```bash
# SEO DB Import
sudo mysql -u root seo_system < /var/www/seo-system/database.sql

# Project 2 DB Import
sudo mysql -u root project2_db < /var/www/project2/database.sql
```

---

### Step 4: બંને ડોમેન માટે Free SSL (HTTPS) સેટ કરો

એક જ કમાન્ડથી બંને સબડોમેન પર HTTPS ફ્રી SSL સેટ થઈ જશે:

```bash
sudo certbot --apache -d seo.yourdomain.com -d project2.yourdomain.com
```

---

## 📂 રીત 2 (Sub-folder approach - IP એડ્રેસ સાથે)

જો ડોમેન ના હોય અને ફક્ત EC2 ના IP એડ્રેસ પરથી ચલાવવું હોય:

1. ફાઇલો `/var/www/html/` મા નીચે મુજબ મૂકો:
   - `/var/www/html/seo-system/`
   - `/var/www/html/project2/`
2. બ્રાઉઝરમાં એક્સેસ કરો:
   - `http://<EC2_PUBLIC_IP>/seo-system/`
   - `http://<EC2_PUBLIC_IP>/project2/`

---

🎉 **હવે તમારા એક જ AWS EC2 Server પર બંને પ્રોજેક્ટ વિના કોઈ પ્રશ્ને સમાંતર (Parallel) ચાલશે!**
