#!/usr/bin/env python3
"""
Copy Medium session from system Chrome to selenium profile
Run AFTER closing Chrome browser
"""
import os, shutil, sys

SCRIPT_DIR     = os.path.dirname(os.path.abspath(__file__))
SRC_DEFAULT    = os.path.join(os.environ['LOCALAPPDATA'], 'Google', 'Chrome', 'User Data', 'Default')
DST_PROFILE    = os.path.join(SCRIPT_DIR, 'chrome_profile_medium')
DST_DEFAULT    = os.path.join(DST_PROFILE, 'Default')

print("=" * 60)
print("  Copying Medium session from Chrome browser")
print("=" * 60)

# Check Chrome is closed
import subprocess
result = subprocess.run(['tasklist', '/FI', 'IMAGENAME eq chrome.exe'], 
                       capture_output=True, text=True)
if 'chrome.exe' in result.stdout.lower():
    print("\n[ERROR] Chrome is still running!")
    print("Please close ALL Chrome windows and run again.")
    sys.exit(1)

print(f"\nSource: {SRC_DEFAULT}")
print(f"Destination: {DST_DEFAULT}")

# Clean destination
if os.path.exists(DST_PROFILE):
    shutil.rmtree(DST_PROFILE, ignore_errors=True)
os.makedirs(DST_DEFAULT, exist_ok=True)

# Files to copy
files_to_copy = [
    'Cookies',
    'Login Data', 
    'Web Data',
    'Preferences',
    'Secure Preferences',
    'Local State',
]

copied = 0
for f in files_to_copy:
    src = os.path.join(SRC_DEFAULT, f)
    dst = os.path.join(DST_DEFAULT, f)
    if os.path.exists(src):
        try:
            shutil.copy2(src, dst)
            size = os.path.getsize(dst)
            print(f"  [OK] {f} ({size:,} bytes)")
            copied += 1
        except Exception as e:
            print(f"  [SKIP] {f}: {e}")

# Copy Local Storage
ls_src = os.path.join(SRC_DEFAULT, 'Local Storage', 'leveldb')
ls_dst = os.path.join(DST_DEFAULT, 'Local Storage', 'leveldb')
if os.path.exists(ls_src):
    os.makedirs(ls_dst, exist_ok=True)
    try:
        shutil.copytree(ls_src, ls_dst, dirs_exist_ok=True)
        print(f"  [OK] Local Storage (leveldb)")
        copied += 1
    except Exception as e:
        print(f"  [SKIP] Local Storage: {e}")

# Copy Session Storage
ss_src = os.path.join(SRC_DEFAULT, 'Session Storage')
ss_dst = os.path.join(DST_DEFAULT, 'Session Storage')
if os.path.exists(ss_src):
    try:
        shutil.copytree(ss_src, ss_dst, dirs_exist_ok=True)
        print(f"  [OK] Session Storage")
        copied += 1
    except Exception as e:
        print(f"  [SKIP] Session Storage: {e}")

# Copy Medium IndexedDB
idb_name = 'https_medium.com_0.indexeddb.leveldb'
idb_src = os.path.join(SRC_DEFAULT, 'IndexedDB', idb_name)
idb_dst = os.path.join(DST_DEFAULT, 'IndexedDB', idb_name)
if os.path.exists(idb_src):
    os.makedirs(os.path.dirname(idb_dst), exist_ok=True)
    try:
        shutil.copytree(idb_src, idb_dst, dirs_exist_ok=True)
        print(f"  [OK] Medium IndexedDB")
        copied += 1
    except Exception as e:
        print(f"  [SKIP] Medium IndexedDB: {e}")

# Copy Service Worker for Medium
sw_src = os.path.join(SRC_DEFAULT, 'Service Worker')
sw_dst = os.path.join(DST_DEFAULT, 'Service Worker')
if os.path.exists(sw_src):
    try:
        shutil.copytree(sw_src, sw_dst, dirs_exist_ok=True)
        print(f"  [OK] Service Worker")
        copied += 1
    except Exception as e:
        print(f"  [SKIP] Service Worker: {e}")

print(f"\n[OK] {copied} items copied to selenium profile!")
print(f"[OK] Profile: {DST_PROFILE}")
print("\nNow run: python medium_post.py to post on Medium")
print("Done.")
