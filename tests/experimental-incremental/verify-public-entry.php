<?php
/** Test-only: verify the public browser submission reached the genuine GF Entry table. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Real WordPress/GF needed'); }
$meta = json_decode(file_get_contents(getenv('BRC_LAB_DIR').'/entry-order-qualifier.json'),
    true, 512, JSON_THROW_ON_ERROR);
$form = (int)$meta['form_id'];
$count = 0;
$ids = GFAPI::get_entry_ids($form, ['status'=>'active',
    'field_filters'=>[['key'=>'1','value'=>'genuine-public-browser']]],
    ['key'=>'id','direction'=>'ASC','is_numeric'=>true],
    ['offset'=>0,'page_size'=>20], $count);
if (!is_array($ids) || count($ids)!==1) {
    throw new RuntimeException('Expected exactly one genuine public browser submission Entry');
}
$id = (int)$ids[0];
$entry = GFAPI::get_entry($id);
if (!is_array($entry) || $id <= (int)$meta['entry_id_before_browser'] ||
    (int)$entry['form_id']!==$form || $entry['1']!=='genuine-public-browser') {
    throw new RuntimeException('Public browser Entry ID/field readback mismatch');
}
echo 'PASS_REAL_PUBLIC_BROWSER_ENTRY_ID_HIGHER_THAN_FRONTIER'
    .' previous='.(int)$meta['entry_id_before_browser'].' new='.$id.PHP_EOL;
