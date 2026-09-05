#!/usr/bin/env python3
import os
import sys
import time
import ftplib

FTP_HOST = "78.108.80.36"
FTP_USER = "f240971_vlad"
FTP_PASS = "VladZhuk2026"

DESTINATIONS = [
    {
        "name": "vladislavb.ru",
        "dir": "/vladislavb.ru/www",
        "base_url": "https://vladislavb.ru",
    },
    {
        "name": "vladinc.ru/vlados",
        "dir": "/vladinc.ru/www/vlados",
        "base_url": "https://vladinc.ru/vlados",
    }
]

def ensure_remote_dir(ftp, path):
    parts = path.strip("/").split("/")
    curr = ""
    for part in parts:
        curr += "/" + part
        try:
            ftp.cwd(curr)
        except ftplib.error_perm:
            try:
                ftp.mkd(curr)
                ftp.cwd(curr)
            except Exception:
                pass

def upload_single_file(ftp, local_file, target_dir, public_base):
    if not os.path.exists(local_file):
        return

    file_size = os.path.getsize(local_file)
    file_name = os.path.basename(local_file)

    ensure_remote_dir(ftp, target_dir)
    ftp.cwd(target_dir)

    print(f"Uploading '{file_name}' ({file_size / (1024 * 1024):.2f} MB) to {target_dir}/...")

    uploaded = 0
    start_time = time.time()
    last_print = start_time

    def callback(chunk):
        nonlocal uploaded, last_print
        uploaded += len(chunk)
        now = time.time()
        if now - last_print >= 2.0 or uploaded == file_size:
            last_print = now
            pct = (uploaded / file_size) * 100
            elapsed = now - start_time
            speed = (uploaded / (1024 * 1024)) / elapsed if elapsed > 0 else 0
            print(f"  {file_name}: {uploaded / (1024 * 1024):.1f}/{file_size / (1024 * 1024):.1f} MB ({pct:.1f}%) @ {speed:.2f} MB/s")

    with open(local_file, "rb") as f:
        ftp.storbinary(f"STOR {file_name}", f, blocksize=1048576, callback=callback)

    total_time = time.time() - start_time
    print(f"  ✓ {file_name} uploaded in {total_time:.2f}s -> {public_base}/{file_name}")

def sync_all(files):
    print(f"Connecting to FTP {FTP_HOST}...")
    ftp = ftplib.FTP()
    ftp.connect(FTP_HOST, 21, timeout=60)
    ftp.login(FTP_USER, FTP_PASS)

    for dest in DESTINATIONS:
        print(f"\n--- Syncing to {dest['name']} ({dest['dir']}) ---")
        for f in files:
            upload_single_file(ftp, f, dest["dir"], dest["base_url"])

    ftp.quit()
    print("\nAll files successfully synced to FTP servers!")

if __name__ == "__main__":
    files_to_sync = sys.argv[1:] if len(sys.argv) > 1 else [
        "/workspaces/Vladaero/vlados/build/vlados.iso",
        "/workspaces/Vladaero/vlados/web/index.html",
    ]
    sync_all(files_to_sync)
