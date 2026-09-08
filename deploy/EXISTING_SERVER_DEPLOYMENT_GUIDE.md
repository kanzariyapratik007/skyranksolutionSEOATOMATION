# 🛡️ પૂર્વ-હાલમાં ચાલતા (Live) AWS Server પર આ પ્રોજેક્ટ ઉમેરવાની ગાઈડ

જો તમારા AWS EC2 Server પર **પહેલાથી જ એક પ્રોજેક્ટ ચાલુ છે** અને તમારે જૂના પ્રોજેક્ટને કોઈપણ નુકસાન કર્યા વિના આ **SEO System** ઉમેરવો છે, તો નીચેની સરળ રીત અનુસરો:

---

## 🔒 સુરક્ષિત રીત (Safe Deployment Approach)

જૂના પ્રોજેક્ટને કંઈપણ અસર ન થાય તે માટે:
1. **જૂની ફાઇલો સલામત રહેશે**: જૂનો પ્રોજેક્ટ `/var/www/html/` ના મેઈન ફોલ્ડરમાં રહેશે, જ્યારે આ નવો પ્રોજેક્ટ સબ-ફોલ્ડર `/var/www/html/seo-system/` અથવા અલગ ફોલ્ડરમાં રહેશે.
2. **નવો અલગ Database બનશે**: જૂના ડેટાબેઝને અડ્યા વગર MySQL માં **`seo_system`** નામનો નવો અલગ ડેટાબેઝ બનશે.
3. **જૂનો ડેટા કે સાઈટ બંધ નહીં થાય (Zero Downtime)**.

---

## 🚀 લાઈવ સર્વર પર સેટઅપ કરવાના સ્ટેપ્સ

### Step 1: તમારા લાઈવ EC2 સર્વર માં SSH કનેક્ટ કરો
```bash
ssh -i "your-key.pem" ubuntu@<YOUR_EXISTING_EC2_IP>
```

---

### Step 2: આ પ્રોજેક્ટ લાઈવ સર્વર પર ડાઉનલોડ કરો
સર્વર પર જૂના પ્રોજેક્ટને અડ્યા વગર આ સબ-ફોલ્ડરમાં ક્લોન કરો:

```bash
git clone https://github.com/Leranmore123/seo12.git /var/www/html/seo-system
```

---

### Step 3: સેફ સેટઅપ સ્ક્રિપ્ટ રન કરો (Safe Setup Script)

```bash
chmod +x /var/www/html/seo-system/deploy/add_to_existing_server.sh
sudo /var/www/html/seo-system/deploy/add_to_existing_server.sh
```

આ સ્ક્રિપ્ટ આપોઆપ:
- જૂની ફાઈલોને અડ્યા વગર ફક્ત `/var/www/html/seo-system` માં નવી ફાઈલો મુકશે.
- MySQL માં નવો ડેટાબેઝ `seo_system` બનાવશે.
- જરૂરી PHP extensions ચેક કરી ઇન્સ્ટોલ કરશે.

---

### Step 4: Database Import કરો

નવા બનાવેલા ડેટાબેઝમાં આ ટેબલો ઈમ્પોર્ટ કરો:
```bash
sudo mysql -u root seo_system < /var/www/html/seo-system/database.sql
```

---

### Step 5: બ્રાઉઝરમાં એક્સેસ કરો (Access in Browser)

તમારા જૂના ડોમેન અથવા IP ની પાછળ `/seo-system/` લખીને ઓપન કરો:

- **જો ડોમેન હોય**: `http://your-domain.com/seo-system/`
- **જો IP હોય**: `http://<YOUR_EC2_IP>/seo-system/`

---

### 🔹 (વૈકલ્પિક) જો સબડોમેન (Subdomain) પર ચલાવવું હોય (e.g. `seo.yourdomain.com`)

જો તમારે જૂની સાઈટ `yourdomain.com` પર રાખવી હોય અને આ નવી સાઈટ `seo.yourdomain.com` પર ચલાવવી હોય:

```bash
sudo ./deploy/setup_virtualhost.sh seo-system seo.yourdomain.com
sudo certbot --apache -d seo.yourdomain.com
```

---

🎉 **તમારો જૂનો પ્રોજેક્ટ યથાવત સુરક્ષિત રહેશે અને નવો SEO System પણ તે જ સર્વર પર લાઈવ થઈ જશે!**
