<?php
/**
 * VladInc Ecosystem Release Packager
 * Generates vladinc_v2.0.zip ready for deployment on vladinc.ru
 */

$root = __DIR__;
$outputZip = $root . '/vladinc_v2.0.zip';

if (file_exists($outputZip)) {
    @unlink($outputZip);
}

$zip = new ZipArchive();
if ($zip->open($outputZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Error: Cannot create zip archive at {$outputZip}\n");
}

$excludedTopDirs = ['mama', 'shibalingo', 'backups', '.git', '.github', '.vscode'];
$excludedFiles = ['vladinc_v2.0.zip', 'package_release.php'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
foreach ($iterator as $file) {
    $pathname = $file->getPathname();
    $relativePath = substr($pathname, strlen($root) + 1);
    $normalized = str_replace('\\', '/', $relativePath);
    $parts = explode('/', $normalized);

    // Skip excluded directories
    if (in_array(strtolower($parts[0]), $excludedTopDirs, true)) {
        continue;
    }

    // Skip excluded files
    if (in_array(basename($pathname), $excludedFiles, true)) {
        continue;
    }

    if ($file->isDir()) {
        $zip->addEmptyDir($normalized);
    } elseif ($file->isFile()) {
        $zip->addFile($pathname, $normalized);
        $count++;
    }
}

$zip->close();

$sizeMb = round(filesize($outputZip) / (1024 * 1024), 2);
echo "Release archive created successfully!\n";
echo "File: {$outputZip}\n";
echo "Total files packed: {$count}\n";
echo "Size: {$sizeMb} MB\n";
