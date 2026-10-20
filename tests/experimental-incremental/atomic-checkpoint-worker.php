<?php
/** One independent WP-CLI PHP process per checkpoint request, qualification only. */
if (!defined('ABSPATH')) { throw new RuntimeException('WordPress runtime unavailable'); }
require_once (string)getenv('BRC_IQ_LIB');
$job = json_decode(file_get_contents((string)getenv('BRC_IQ_JOB')), true, 512, JSON_THROW_ON_ERROR);
$action = $job['action'] ?? '';
$form = (int)($job['form'] ?? 0);
if ($action === 'forms') {
    $ids = [];
    foreach (['A','B'] as $label) {
        $id = GFAPI::add_form([
            'title'=>'GFBRC temporary checkpoint test '.$label,
            'fields'=>[['id'=>1,'type'=>'text','label'=>'Test']],
            'button'=>['type'=>'text','text'=>'Submit'],
        ]);
        cq_assert(!is_wp_error($id) && (int)$id > 0, 'Disposable Form creation failed');
        $ids[] = (int)$id;
    }
    $result = ['forms'=>$ids,'wp'=>get_bloginfo('version'),'php'=>PHP_VERSION,
        'mariadb'=>$GLOBALS['wpdb']->db_version(),
        'external_cache'=>wp_using_ext_object_cache(),
        'cache_class'=>get_class($GLOBALS['wp_object_cache']),
        'engine'=>$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $GLOBALS['wpdb']->options
        ))];
} elseif ($action === 'reset') {
    $result = cq_reset($form, (int)($job['frontier'] ?? 100));
} elseif ($action === 'cleanup') {
    $deleted = delete_option(cq_key($form));
    $result = ['deleted'=>$deleted, 'absent'=>get_option(cq_key($form)) === false];
} elseif ($action === 'read') {
    $raw = cq_db_raw($form);
    $result = ['db'=>cq_decode($raw),
        'api'=>cq_decode((string)get_option(cq_key($form))),
        'match'=>get_option(cq_key($form)) === $raw];
} elseif ($action === 'commit' || $action === 'unsafe_wp_update') {
    // Explicitly warm per-process WP option cache BEFORE process synchronization.
    $api_before = get_option(cq_key($form));
    $raw_before = cq_db_raw($form);
    cq_assert(is_string($api_before), 'WordPress Option read failed');
    if (!empty($job['barrier'])) {
        $ready = (string)$job['barrier'] . '.ready';
        $go = (string)$job['barrier'] . '.go';
        file_put_contents($ready, cq_encode([
            'pid'=>getmypid(),'rev'=>cq_decode($raw_before)['rev'],
            'api_same'=>$api_before === $raw_before,
        ]));
        $until = microtime(true) + 35;
        while (!is_file($go)) {
            if (microtime(true) >= $until) { throw new RuntimeException('Barrier timed out'); }
            usleep(20000);
        }
    }
    if ($action === 'unsafe_wp_update') {
        $unsafe = $job['unsafe_target'];
        // Deliberately demonstrate absence of a version guard in update_option.
        $ok = update_option(cq_key($form), cq_encode([
            'frontier'=>$unsafe,'rev'=>1,'last_op'=>'UNSAFE-'.$unsafe,
        ]),false);
        $result = ['outcome'=>'UNGUARDED_WP_UPDATE', 'update_returned'=>$ok,
            'db'=>cq_decode(cq_db_raw($form))];
    } else {
        $result = cq_commit($form,$job['snapshot'],(string)($job['ack'] ?? ''),$raw_before);
    }
    $result['pre_read'] = cq_decode($raw_before);
} elseif ($action === 'gf_seed') {
    global $wpdb;
    $table = GFFormsModel::get_entry_table_name();
    $before = (int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `{$table}`");
    $id = GFAPI::add_entry(['form_id'=>$form,'1'=>'snapshot-entry']);
    cq_assert(!is_wp_error($id) && (int)$id > $before, 'Genuine GF Entry ID not above frontier');
    $result=['frontier'=>$before,'first_id'=>(int)$id];
} elseif ($action === 'gf_append') {
    $ids=[];
    for($i=0;$i<2;$i++) {
        $id=GFAPI::add_entry(['form_id'=>$form,'1'=>'outside-snapshot-'.$i]);
        cq_assert(!is_wp_error($id), 'GF Entry insertion failed');
        $ids[]=(int)$id;
    }
    $result=['ids'=>$ids];
} else { throw new RuntimeException('Unsupported qualification action'); }
echo 'IQ_RESULT ' . cq_encode($result) . PHP_EOL;
