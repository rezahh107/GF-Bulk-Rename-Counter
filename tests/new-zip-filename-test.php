<?php
/** Fail-before-fix regression for stable, UTC, collision-resistant NEW ZIP names. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-new_download.php';

$method = new ReflectionMethod(\GFBulkRename\New_Download::class, 'zip_filename');
$method->setAccessible(true);
$created = gmmktime(11, 25, 30, 11, 8, 2026);
$op = [
    'id' => str_repeat('a', 32),
    'expires' => $created + 900,
    'ids' => range(1, 83),
];
$filename = static fn(int $form, array $operation, int $files): string =>
    $method->invoke(null, $form, $operation, $files);
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL_NEW_ZIP_FILENAME: {$message}\n");
        exit(1);
    }
};

date_default_timezone_set('Pacific/Auckland');
$first = $filename(12, $op, 83);
$check((bool)preg_match(
    '/^gfbrc-new-12-20261108T112530Z-83-entries-83-files-[0-9a-f]{24}\.zip$/D',
    $first
), 'UTC timestamp and existing counts must be visible');
$check((bool)preg_match('/^[A-Za-z0-9._-]+$/D', $first) && strlen($first) <= 255,
    'Windows/macOS/browser-safe length and characters');
$check(strpos($first, $op['id']) === false, 'raw operation token must not leak');
$check($filename(12, $op, 83) === $first, 'same operation must be stable on retry');

$second = $op;
$second['id'] = str_repeat('b', 32);
$check($second['expires'] === $op['expires'], 'test fixture must share exact second');
$check($filename(12, $second, 83) !== $first,
    'independent same-second operation must produce a different name');
$check($filename(13, $op, 83) !== $first, 'different forms must remain identifiable');
$check($filename(12, $op, 84) !== $first, 'file count must remain identifiable');
$later = $op;
$later['expires'] += 1;
$check(str_contains($filename(12, $later, 83), '20261108T112531Z'),
    'timestamp must reflect the operation creation second');
$check(strcmp($filename(12, $op, 83), $filename(12, $later, 83)) < 0,
    'UTC date/time prefix must sort chronologically');

echo "PASS_NEW_ZIP_FILENAME_UTC_SAFE_SAME_SECOND_DISTINCT_STABLE_RETRY\n";
