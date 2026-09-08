# 🚀 AWS EC2 Deployment Guide for SEO System (Gujarati & English)

આ ગાઈડ વડે તમે તમારા **SEO System (PHP + MySQL)** ને **AWS EC2 (Ubuntu 22.04)** પર સરળતાથી ડેપ્લોય (Deploy) કરી શકશો.

---

## Step 1: AWS EC2 Instance બનાવો (Launch EC2 Instance)

1. **AWS Console** માં સાઈન ઈન કરો અને **EC2 Dashboard** પર જાઓ.
2. **Launch Instance** બટન પર ક્લિક કરો.
3. **Name**: `seo-system-server` આપો.
4. **OS Image (AMI)**: **Ubuntu Server 22.04 LTS (HVM)** સિલેક્ટ કરો.
5. **Instance Type**: `t2.micro` અથવા `t3.micro` (Free Tier).
6. **Key Pair (SSH)**:
   - "Create new key pair" પર ક્લિક કરી `.pem` ફાઈલ ડાઉનલોડ કરો (દા.ત. `seo-key.pem`).
7. **Network Settings (Security Group Rules)**:
   - ✅ **Allow SSH traffic** (Port 22)
   - ✅ **Allow HTTP traffic from the internet** (Port 80)
   - ✅ **Allow HTTPS traffic from the internet** (Port 443)
8. **Storage**: 8 GB / 20 GB gp3 SSD.
9. **Launch Instance** પર ક્લિક કરો.

---

## Step 2: EC2 સર્વર સાથે SSH Connect કરો

તમારા ટર્મિનલ (Terminal / Command Prompt / Git Bash) માંથી આ કમાન્ડ ચલાવો:

```bash
chmod 400 seo-key.pem
ssh -i "seo-key.pem" ubuntu@<YOUR_EC2_PUBLIC_IP>
```
*(જેમ કે: `ssh -i "seo-key.pem" ubuntu@13.233.xx.xx`)*

---

## Step 3: ઓટોમેટેડ સેટઅપ સ્ક્રિપ્ટ રન કરો (Run Setup Script)

સર્વર કનેક્ટ થયા પછી project નો સેટઅપ સ્ક્રિપ્ટ રન કરો:

```bash
# Repo ક્લોન કરો અથવા ફાઇલો અપલોડ કરો
git clone https://github.com/Leranmore123/seo12.git /var/www/html/seo-system

# સ્ક્રિપ્ટ એક્ઝીક્યુટેબલ બનાવો
chmod +x /var/www/html/seo-system/deploy/aws_setup.sh

# સેટઅપ સ્ક્રિપ્ટ રન કરો
sudo /var/www/html/seo-system/deploy/aws_setup.sh
```

---

## Step 4: Database ઈમ્પોર્ટ કરો (Import Database)

```bash
sudo mysql -u root seo_system < /var/www/html/seo-system/database.sql
```

જો જૂનો ડેટા પણ ઈમ્પોર્ટ કરવો હોય:
```bash
sudo mysql -u root seo_system < /var/www/html/seo-system/backup.sql
```

---

## Step 5: Web Access & Domain Connection

1. બ્રાઉઝરમાં તમારું EC2 IP એડ્રેસ ખોલો:
   `http://<YOUR_EC2_PUBLIC_IP>/seo-system/`
2. જો તમે ડોમેન (Domain Name like `seo.yourdomain.com`) એડ કરેલું હોય, તો DNS માં **A Record** ઈન્સર્ટ કરો (`Point to EC2 IP`).

---

## Step 6: Free SSL Certificate (HTTPS) સેટ કરો

ડોમેન લિંક થઈ ગયા પછી ફ્રી SSL સર્ટિફિકેટ ઈન્સ્ટોલ કરવા માટે:

```bash
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com
```

---

## Step 7: Cron Job ચકાસણી (Rank Tracking & Daily Reports)

સિસ્ટમમાં દરરોજ આપોઆપ Rank tracking અને Weekly Reports માટે Cron job ઓટોમેટીક સેટ થઈ ગયેલ છે.
ચકાસવા માટે કમાન્ડ:

```bash
sudo crontab -u www-data -l
```

---

🎉 **તમારું SEO System AWS EC2 પર સફળતાપૂર્વક Live થઈ ગયું છે!**
