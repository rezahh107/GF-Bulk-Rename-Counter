<?php
/**
 * Qualification ONLY: real Gravity Forms 3.1.1.1 entry ID assignment contract.
 * Evaluated with WP-CLI in disposable WordPress; never packaged in GFBRC.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI') ||
    !defined('GFBULKRENAME_VERSION') || GFBULKRENAME_VERSION !== '2.1.0') {
    throw new RuntimeException('Pinned genuine WordPress / GF / GFBRC is required');
}
function order_assert(bool $yes, string $what): void {
    if (!$yes) { throw new RuntimeException($what); }
}
function order_added(array $entry): int {
    $id = GFAPI::add_entry($entry);
    if (is_wp_error($id) || !is_numeric($id) || (int)$id < 1) {
        throw new RuntimeException('GFAPI insertion failed');
    }
    return (int)$id;
}
function order_entry(int $id): array {
    $value = GFAPI::get_entry($id);
    if (!is_array($value)) { throw new RuntimeException('GFAPI readback failed: ' . $id); }
    return $value;
}
global $wpdb;
$table = GFFormsModel::get_lead_table_name();
order_assert(is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1,
    'Unexpected GF Entry table identifier');
$column = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'id'", ARRAY_A);
order_assert(is_array($column) && ($column['Key'] ?? '') === 'PRI' &&
    str_contains(strtolower((string)($column['Extra'] ?? '')), 'auto_increment'),
    'Pinned GF Entry schema does not prove AUTO_INCREMENT primary key');
echo 'PASS_REAL_GF_ENTRY_ID_GLOBAL_TABLE_AUTO_INCREMENT table=' . $table . PHP_EOL;
$api = new ReflectionMethod('GFAPI', 'add_entry');
$save = new ReflectionMethod('GFFormsModel', 'save_entry');
$submit = new ReflectionMethod('GFAPI', 'submit_form');
foreach ([$api, $save, $submit] as $method) {
    $file = $method->getFileName();
    order_assert(is_string($file) && is_file($file), 'Pinned method source unavailable');
    echo 'PINNED_GF_SOURCE ' . $method->getDeclaringClass()->getName() . '::' .
        $method->getName() . ' file=' . str_replace(ABSPATH, '', $file) .
        ' lines=' . $method->getStartLine() . '-' . $method->getEndLine() .
        ' sha256=' . hash_file('sha256', $file) . PHP_EOL;
}
// Capture narrow, directly inspected implementation statements with line provenance.
// These are diagnostics, never a substitute for the genuine API behavior tests.
foreach ([$api, $save] as $method) {
    $lines = file($method->getFileName());
    foreach (range($method->getStartLine(), $method->getEndLine()) as $line) {
        $code = trim($lines[$line-1]);
        if (preg_match('/(?:unset\s*\(\s*\$entry\s*\[.id.|insert_id|\$wpdb->insert\s*\(|insert_lead|save_entry|auto_increment)/i', $code)) {
            echo 'GF_SOURCE_OBSERVATION ' . $method->getName() . ':' . $line .
                ' ' . substr($code, 0, 190) . PHP_EOL;
        }
    }
}
$forms = [];
foreach (['A','B'] as $suffix) {
    $created = GFAPI::add_form([
        'title' => 'Entry order qualifier ' . $suffix,
        'fields' => [
            ['id'=>1,'type'=>'text','label'=>'Marker','isRequired'=>true],
            ['id'=>2,'type'=>'fileupload','label'=>'Attachment'],
        ],
        'button' => ['type'=>'text','text'=>'Submit'],
    ]);
    order_assert(!is_wp_error($created) && is_numeric($created), 'Form creation failed');
    $forms[$suffix] = (int)$created;
}
$baseline = (int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `{$table}`");
$id1 = order_added(['form_id'=>$forms['A'],'1'=>'api-first']);
$id2 = order_added(['form_id'=>$forms['B'],'1'=>'api-other-form']);
order_assert($baseline < $id1 && $id1 < $id2, 'Global ID allocation sequence mismatch');
$forced_id = $id1; // Try an occupied historical ID through *public GFAPI*.
$id3 = order_added(['id'=>$forced_id,'form_id'=>$forms['A'],
    'date_created'=>'2011-02-03 04:05:06','1'=>'api-forced-id-ignored']);
order_assert($id3 > $id2 && $id3 !== $forced_id, 'GFAPI accepted caller-supplied historical ID');
order_assert((string)order_entry($id1)['1'] === 'api-first',
    'Historical Entry unexpectedly overwritten');
order_assert(order_entry($id3)['date_created'] === '2011-02-03 04:05:06',
    'GFAPI backdated timestamp not retained');
echo 'PASS_GFAPI_IGNORES_OCCUPIED_EXPLICIT_ID_AND_BACKDATE'
    . ' baseline=' . $baseline . ' ids=' . implode(',', [$id1,$id2,$id3]) . PHP_EOL;
$batch = GFAPI::add_entries([
    ['form_id'=>$forms['A'],'id'=>$id1,'1'=>'batch-a'],
    ['form_id'=>$forms['B'],'id'=>$id2,'1'=>'batch-b'],
]);
order_assert(is_array($batch) && count($batch) === 2 &&
    is_numeric($batch[0]) && is_numeric($batch[1]) &&
    (int)$batch[0] > $id3 && (int)$batch[1] > (int)$batch[0],
    'GFAPI batch creation did not assign new sequential IDs');
echo 'PASS_GFAPI_BATCH_CREATION_IGNORES_CALLER_IDS ids=' . implode(',', $batch) . PHP_EOL;

// GFAPI::submit_form() traverses validation, save, actions and confirmation.
$submit_response = GFAPI::submit_form($forms['A'], ['input_1'=>'public-lifecycle']);
order_assert(is_array($submit_response) && !empty($submit_response['is_valid']) &&
    !empty($submit_response['entry_id']), 'Normal form submission failed');
$submitted = (int)$submit_response['entry_id'];
order_assert($submitted > (int)$batch[1] &&
    (int)order_entry($submitted)['form_id'] === $forms['A'],
    'Normal submission did not obtain new global ID');
echo 'PASS_REAL_GF_FORM_SUBMISSION_ID_ORDER id=' . $submitted . PHP_EOL;

// Ordinary UPDATE operations have to preserve Entry identity.
$order_before = order_entry($id1);
$counter_update = GFAPI::update_entry_field($id1, 1, 'counter-update-existing');
$meta_update = gform_update_meta($id1, 'qualification_counter', '1001');
$status_update = GFAPI::update_entry_property($id1, 'status', 'spam');
order_assert(!is_wp_error($counter_update) && $counter_update !== false &&
    !is_wp_error($status_update) && $status_update !== false,
    'Existing-Entry update failed');
$after = order_entry($id1);
order_assert((int)$after['id'] === $id1 &&
    $after['1'] === 'counter-update-existing' &&
    $after['status'] === 'spam' &&
    (string)gform_get_meta($id1, 'qualification_counter') === '1001',
    'Existing Entry update changed identity/state incorrectly');
$order_max = (int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `{$table}`");
order_assert($order_max === $submitted, 'Existing-Entry update created a new Entry');
echo 'PASS_FIELD_COUNTER_META_STATUS_UPDATE_DOES_NOT_CREATE_NEW_ID old=' .
    $id1 . ' max=' . $order_max . PHP_EOL;

// Bounded creation frontier: freeze IDs first and never expand it at ACK time.
$pre = order_added(['form_id'=>$forms['A'],'1'=>'before-snapshot']);
$snapshot = [$pre];
$snapshot_max = max($snapshot);
$mid = order_added(['form_id'=>$forms['A'],'1'=>'during-enumeration']);
$post = order_added(['form_id'=>$forms['A'],'1'=>'after-snapshot-before-ack']);
order_assert($pre < $mid && $mid < $post && $snapshot_max === $pre,
    'Snapshot sequencing invalid');
$simulated_ack = $snapshot_max;
order_assert($mid > $simulated_ack && $post > $simulated_ack &&
    !in_array($mid, $snapshot, true) && !in_array($post, $snapshot, true),
    'Simulated ACK would skip post-snapshot Entry');
echo 'PASS_BOUNDED_FROZEN_ID_FRONTIER_AFTER_NEW_CREATION'
    . ' snapshot=' . $pre . ' outside=' . $mid . ',' . $post . PHP_EOL;

// Negative controls are deliberately constructed (not real GF database insertion).
$invalid_new = ['id'=>$simulated_ack,'created_after_ack'=>true];
order_assert(!($invalid_new['id'] > $simulated_ack) &&
    $invalid_new['created_after_ack'] === true, 'Synthetic lower-ID falsifier ineffective');
$old_edited = ['id'=>$id1,'modified_after_ack'=>true];
order_assert(!($old_edited['id'] > $simulated_ack),
    'Existing Entry incorrectly classified by high-water comparator');
$unsafe_ack = max($snapshot_max,$mid,$post);
order_assert($unsafe_ack > $simulated_ack &&
    !in_array($post,$snapshot,true),
    'Unsafe acknowledgment control failed to detect out-of-snapshot coverage');
echo 'PASS_SYNTHETIC_NEGATIVE_CONTROLS_LOWER_ID_OLD_UPDATE_UNFROZEN_ACK' . PHP_EOL;

if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
$plugins = get_plugins();
$names = array_keys($plugins);
$importers = array_values(array_filter($names, static fn($p) =>
    (bool)preg_match('/import|migrat|restore/i', $p)));
echo 'INSTALLED_IMPORTER_CANDIDATES ' . wp_json_encode($importers) . PHP_EOL;
$page_id = wp_insert_post([
    'post_type'=>'page','post_status'=>'publish',
    'post_title'=>'GFBRC disposable public submission qualifier',
    'post_content'=>'[gravityform id="' . $forms['A'] .
        '" title="false" description="false" ajax="false"]',
],true);
order_assert(!is_wp_error($page_id) && (int)$page_id>0,
    'Disposable public WordPress form page failed');
$path = getenv('BRC_LAB_DIR') . '/entry-order-qualifier.json';
order_assert(file_put_contents($path, wp_json_encode([
    'form_id'=>$forms['A'], 'page_url'=>get_permalink((int)$page_id),
    'entry_id_before_browser'=>$post,
    'baseline'=>$baseline, 'new_ids'=>[$id1,$id2,$id3,(int)$batch[0],(int)$batch[1],$submitted,$pre,$mid,$post]
], JSON_PRETTY_PRINT)) !== false, 'Cannot persist disposable test manifest');
echo 'ENTRY_ORDER_BROWSER_FIXTURE_CREATED form=' . $forms['A'] .
    ' last=' . $post . PHP_EOL;
