#!/bin/bash
# ------------------------------------------------------------------
# PayViro — build the official website bundle (landing + docs)
# Output: dist/payviro-site.zip  → upload to apps.webiro.ir/payviro
# Author: Pedram Gholizadeh (WEBIRO) — MIT
# ------------------------------------------------------------------
set -e
cd "$(dirname "$0")/.."

SITE=dist/site
rm -rf "$SITE"
mkdir -p "$SITE"

# landing at root, docs under /docs
cp -R landing/. "$SITE/"
cp -R docs "$SITE/docs"

cd "$SITE"
rm -f ../payviro-site.zip
if command -v zip > /dev/null; then
  zip -r -q ../payviro-site.zip .
else
  php -r '
    $zip = new ZipArchive();
    $zip->open(__DIR__ . "/../payviro-site.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen(__DIR__) + 1)); }
    $zip->close(); echo "zip (php) OK\n";
  '
fi
cd ..
echo "✓ dist/payviro-site.zip ساخته شد — محتویات را در apps.webiro.ir/payviro آپلود کنید"
