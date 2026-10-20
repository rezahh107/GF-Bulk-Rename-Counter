<?php
/**
 * Non-production qualification: real GFAPI pagination/chronology and empty-entry boundary.
 * Executed ONLY against disposable synthetic WordPress in native CI; never shipped.
 */
declare(strict_types=1);
if (!defined('ABSPATH') || !class_exists('GFAPI') ||
    !defined('GFBULKRENAME_VERSION') || GFBULKRENAME_VERSION !== '2.1.0') {
    throw new RuntimeException('Pinned genuine WP/GF/GFBRC required');
}
function iq_assert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function iq_ids(int $form, array $criteria, array $sort, int $offset, int $size): array {
    $count = 0;
    $result = GFAPI::get_entry_ids($form, $criteria, $sort,
        ['offset' => $offset, 'page_size' => $size], $count);
    if (!is_array($result)) {
        throw new RuntimeException('GFAPI enumeration failed: ' .
            (is_wp_error($result) ? $result->get_error_message() : gettype($result)));
    }
    return array_map('intval', $result);
}
function iq_add(int $form, string $bucket, string $status = 'active', ?string $date = null): int {
    $input = ['form_id' => $form, '1' => $bucket, 'status' => $status];
    if ($date !== null) { $input['date_created'] = $date; }
    $id = GFAPI::add_entry($input);
    if (is_wp_error($id) || !is_numeric($id) || (int)$id <= 0) {
        throw new RuntimeException('GFAPI add_entry failed');
    }
    return (int)$id;
}
$form = GFAPI::add_form([
    'title' => 'GFBRC isolated incremental qualification',
    'fields' => [
        ['id' => 1, 'type' => 'text', 'label' => 'Bucket'],
        ['id' => 2, 'type' => 'fileupload', 'label' => 'Upload'],
    ],
    'button' => ['type' => 'text', 'text' => 'Submit'],
]);
iq_assert(!is_wp_error($form) && (int)$form > 0, 'Synthetic form creation failed');
$form = (int)$form;
$first_spam = iq_add($form, 'match', 'spam');
$expected = [];
for ($i = 0; $i < 154; $i++) { $expected[] = iq_add($form, 'match'); }
for ($i = 0; $i < 5; $i++) { iq_add($form, 'different'); }
$criteria = [
    'status' => 'active',
    'field_filters' => [['key' => '1', 'value' => 'match']],
];
$asc = ['key' => 'id', 'direction' => 'ASC', 'is_numeric' => true];
$desc = ['key' => 'id', 'direction' => 'DESC', 'is_numeric' => true];
$whole = iq_ids($form, $criteria, $asc, 0, 500);
iq_assert($whole === $expected, 'GFAPI 154-ID all-matching result differs from oracle');
$page = iq_ids($form, $criteria, $asc, 0, 25);
iq_assert($page === array_slice($expected, 0, 25), 'Current-page scope differs');
echo 'PASS_GFAPI_154_FILTERED_IDS_AND_25_CURRENT_PAGE form=' . $form . PHP_EOL;

// Native descending offset enumeration has a mutable head: insert between pages.
$before_desc = iq_ids($form, $criteria, $desc, 0, 40);
$inserted = iq_add($form, 'match');
$observed_desc = $before_desc;
for ($offset = 40; ; $offset += 40) {
    $chunk = iq_ids($form, $criteria, $desc, $offset, 40);
    if (!$chunk) { break; }
    array_push($observed_desc, ...$chunk);
    iq_assert($offset < 400, 'Unbounded descending enumeration');
}
iq_assert(count($observed_desc) !== count(array_unique($observed_desc)),
    'Expected insertion-shift duplicate in descending offset pagination not reproduced');
iq_assert(!in_array($inserted, $observed_desc, true),
    'Inserted entry unexpectedly in a pre-insertion first-page snapshot');
echo 'PASS_GFAPI_DESC_OFFSET_INSERTION_DUPLICATES_AND_SNAPSHOT_EXCLUDES_INSERTED'
    . ' count=' . count($observed_desc) . ' unique=' . count(array_unique($observed_desc)) . PHP_EOL;

// ASC is only insert-stable if filter membership of old IDs cannot change.
$before_asc = iq_ids($form, $criteria, $asc, 0, 40);
$activated = GFAPI::update_entry_property($first_spam, 'status', 'active');
iq_assert(!is_wp_error($activated) && $activated !== false, 'Cannot activate old Entry');
$observed_asc = $before_asc;
for ($offset = 40; ; $offset += 40) {
    $chunk = iq_ids($form, $criteria, $asc, $offset, 40);
    if (!$chunk) { break; }
    array_push($observed_asc, ...$chunk);
    iq_assert($offset < 400, 'Unbounded ascending enumeration');
}
iq_assert(count($observed_asc) !== count(array_unique($observed_asc)),
    'Expected old-activation shift duplicate in ascending offset pagination not reproduced');
echo 'PASS_GFAPI_ASC_OFFSET_OLD_ACTIVATION_DUPLICATES'
    . ' count=' . count($observed_asc) . ' unique=' . count(array_unique($observed_asc)) . PHP_EOL;

// Chronology: GFAPI-supplied creation timestamp may be backdated on a new INSERT.
$frontier = GFAPI::get_entry(max($expected));
iq_assert(is_array($frontier) && isset($frontier['date_created']), 'No created timestamp');
$backdated = iq_add($form, 'match', 'active', '2011-02-03 04:05:06');
$back = GFAPI::get_entry($backdated);
iq_assert(is_array($back), 'Backdated Entry missing');
if ((int)$back['id'] > max($expected) &&
    strcmp((string)$back['date_created'], (string)$frontier['date_created']) < 0) {
    echo 'PASS_GFAPI_BACKDATED_NEW_INSERT_FALSIFIES_TIMESTAMP_WATERMARK'
        . ' id=' . $backdated . ' date=' . $back['date_created'] . PHP_EOL;
} else {
    echo 'NOT_PROVEN_GFAPI_BACKDATED_INSERT date=' . $back['date_created'] . PHP_EOL;
}
$a = iq_add($form, 'different', 'active', '2011-02-03 04:05:06');
$b = iq_add($form, 'different', 'active', '2011-02-03 04:05:06');
$aa = GFAPI::get_entry($a); $bb = GFAPI::get_entry($b);
if (is_array($aa) && is_array($bb) && $aa['date_created'] === $bb['date_created']) {
    echo 'PASS_GFAPI_DUPLICATE_CREATION_TIMESTAMPS' . PHP_EOL;
} else {
    echo 'NOT_PROVEN_GFAPI_DUPLICATE_CREATION_TIMESTAMPS' . PHP_EOL;
}

// Existing planner must NOT be accidentally represented as covering zero-file Entries.
try {
    \GFBulkRename\Flat_Zip_Download::plan($form, [$expected[0]]);
    throw new RuntimeException('Unexpected zero-file ZIP plan success');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Unexpected zero-file ZIP plan success') { throw $e; }
    iq_assert(str_contains($e->getMessage(), 'No eligible files found'),
        'Unexpected zero-file plan failure: ' . $e->getMessage());
    echo 'PASS_PROVED_CURRENT_PLANNER_REJECTS_ALL_ZERO_FILE_ENTRY_SET' . PHP_EOL;
}
echo 'QUALIFICATION_ONLY_NO_PERSISTENT_DOWNLOAD_HISTORY_WRITTEN' . PHP_EOL;
