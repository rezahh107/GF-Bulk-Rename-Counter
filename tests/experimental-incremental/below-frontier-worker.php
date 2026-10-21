<?php
/**
 * Disposable qualification: exact acknowledged Entry identities in nonautoload
 * per-form WordPress Option with atomic SQL CAS. Not a product implementation.
 *
 * The caller-supplied snapshot/ACK/ZIP evidence is test-controlled and is NOT
 * a complete proof of an authentic HTTP delivery or immutable server operation.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Authentic WordPress/Gravity Forms required');
}
$job = json_decode(file_get_contents((string)getenv('BRC_RECON_JOB')), true, 512, JSON_THROW_ON_ERROR);
$action = (string)($job['action'] ?? '');
global $wpdb;
function rq_assert(bool $ok, string $why): void {
    if (!$ok) { throw new RuntimeException($why); }
}
function rq_key(int $form): string {
    rq_assert($form > 0, 'Invalid form');
    return 'gfbrc_only_reconciliation_qualification_' . $form;
}
function rq_encode(array $state): string {
    return (string) wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function rq_raw(int $form): string {
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",rq_key($form)));
    rq_assert(is_string($raw) && $raw !== '', 'Missing qualification state');
    return $raw;
}
function rq_state(string $raw): array {
    $s = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    rq_assert(is_array($s) && isset($s['rev'],$s['ranges']) && is_int($s['rev'])
        && is_array($s['ranges']), 'Invalid persisted state');
    return $s;
}
function rq_ids(array $ranges): array {
    $values = [];
    foreach($ranges as $range) {
        rq_assert(is_array($range) && count($range) === 2 &&
            is_int($range[0]) && is_int($range[1]) &&
            $range[0] > 0 && $range[0] <= $range[1], 'Invalid range');
        foreach(range($range[0],$range[1]) as $id) {
            rq_assert(!isset($values[$id]), 'Overlapping ranges');
            $values[$id] = $id;
        }
    }
    ksort($values,SORT_NUMERIC);
    return array_values($values);
}
function rq_compact(array $ids): array {
    foreach($ids as $id) { rq_assert(is_int($id) && $id > 0,'Invalid Entry identity'); }
    $ids = array_values(array_unique($ids));
    sort($ids,SORT_NUMERIC);
    $r=[];
    foreach($ids as $id) {
        $i=count($r)-1;
        if($i >= 0 && $id === $r[$i][1]+1) { $r[$i][1]=$id; }
        else { $r[]=[$id,$id]; }
    }
    return $r;
}
function rq_clear(int $form): void {
    wp_cache_delete(rq_key($form),'options');
    wp_cache_delete('notoptions','options');
    wp_cache_delete('alloptions','options');
}
function rq_snapshot(int $form): array {
    global $wpdb;
    rq_assert($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')!==false,
        'Isolation not supported');
    rq_assert($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')!==false,
        'Could not start InnoDB snapshot');
    $ids=[];
    for($offset=0;$offset<5000;$offset+=47) {
        $total=0;
        $part=GFAPI::get_entry_ids($form,[
            'status'=>'active','field_filters'=>[['key'=>'1','value'=>'match']]
        ],['key'=>'id','direction'=>'ASC','is_numeric'=>true],
        ['offset'=>$offset,'page_size'=>47],$total);
        rq_assert(is_array($part),'GFAPI snapshot enumeration failed');
        foreach($part as $id) { $ids[]=(int)$id; }
        if(count($part)<47) { break; }
    }
    rq_assert($wpdb->query('COMMIT')!==false, 'Failed to finish snapshot transaction');
    rq_assert(count($ids) === count(array_unique($ids)),'Snapshot contains duplicates');
    $sorted=$ids; sort($sorted,SORT_NUMERIC);
    rq_assert($ids === $sorted, 'Snapshot not ordered');
    return $ids;
}
function rq_ack(int $form,array $op,string $ack,?string $frozen_raw=null): array {
    global $wpdb;
    $old_raw=$frozen_raw ?? rq_raw($form);
    $old=rq_state($old_raw);
    if(!isset($op['id'],$op['form'],$op['ids'],$op['artifact_verified']) ||
       !is_string($op['id']) || $op['id'] === '' || $ack !== $op['id'] ||
       $op['form'] !== $form || $op['artifact_verified'] !== true ||
       !is_array($op['ids']) || !$op['ids']) {
        return ['outcome'=>'NO_VALID_ACK','state'=>$old];
    }
    $incoming=$op['ids'];
    foreach($incoming as $id) {
        if(!is_int($id) || $id <= 0) {
            return ['outcome'=>'INVALID_ID_SET','state'=>$old];
        }
    }
    if(count(array_unique($incoming))!==count($incoming)) {
        return ['outcome'=>'INVALID_ID_SET','state'=>$old];
    }
    $old_ids=rq_ids($old['ranges']);
    if(!array_diff($incoming,$old_ids)) {
        return ['outcome'=>'ALREADY_ACKNOWLEDGED','state'=>$old];
    }
    $next=['rev'=>$old['rev']+1,'ranges'=>rq_compact(array_merge($old_ids,$incoming))];
    $next_raw=rq_encode($next);
    $count=$wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s
         WHERE option_name = %s AND BINARY option_value = BINARY %s",
         $next_raw,rq_key($form),$old_raw));
    rq_clear($form);
    if($count===false) { return ['outcome'=>'DB_ERROR','state'=>rq_state(rq_raw($form))]; }
    $db_raw=rq_raw($form);
    rq_assert(get_option(rq_key($form)) === $db_raw,
        'Option cache mismatch after reconciliation CAS');
    if($count!==1) {
        return ['outcome'=>'CAS_CONFLICT','state'=>rq_state($db_raw)];
    }
    rq_assert($db_raw === $next_raw,'Committed state readback mismatch');
    return ['outcome'=>'COMMITTED','state'=>$next];
}
function rq_gate(string $prefix, array $meta): void {
    file_put_contents($prefix.'.ready', rq_encode($meta));
    $end=microtime(true)+50;
    while(!is_file($prefix.'.go')) {
        if(microtime(true)>$end) { throw new RuntimeException('Racing worker barrier timeout'); }
        usleep(25000);
    }
}
$form=(int)($job['form']??0);
if($action==='setup') {
    $id=GFAPI::add_form([
        'title'=>'GFBRC isolated below-frontier reconciliation lab',
        'fields'=>[['id'=>1,'type'=>'text','label'=>'Membership']],
        'button'=>['type'=>'text','text'=>'Submit']
    ]);
    rq_assert(!is_wp_error($id) && (int)$id>0,'GFAPI add_form failed');
    $form=(int)$id;
    rq_assert(add_option(rq_key($form),rq_encode(['rev'=>0,'ranges'=>[]]),'',false),
        'Could not create disposable option');
    $result=['form'=>$form,'state'=>rq_state(rq_raw($form))];
} elseif($action==='hold') {
    rq_assert($wpdb->query('START TRANSACTION')!==false,'Cannot start delayed writer');
    $id=GFAPI::add_entry(['form_id'=>$form,'1'=>'match']);
    rq_assert(!is_wp_error($id) && (int)$id>0,'GFAPI delayed insertion failed');
    rq_gate((string)$job['barrier'],['id'=>(int)$id]);
    rq_assert($wpdb->query('COMMIT')!==false,'Cannot commit delayed writer');
    $result=['committed'=>(int)$id];
} elseif($action==='add') {
    $ids=[];
    $times=(int)($job['count']??1);
    rq_assert($times>=1 && $times<=120,'Unbounded test insert');
    for($i=0;$i<$times;$i++) {
        $id=GFAPI::add_entry(['form_id'=>$form,'1'=>(string)($job['marker']??'match')]);
        rq_assert(!is_wp_error($id) && (int)$id>0,'GFAPI insertion failed');
        $ids[]=(int)$id;
    }
    $result=['ids'=>$ids];
} elseif($action==='mutate') {
    $id=(int)$job['id'];
    if($job['kind']==='status') {
        $ok=GFAPI::update_entry_property($id,'status',(string)$job['value']);
    } else {
        $ok=GFAPI::update_entry_field($id,1,(string)$job['value']);
    }
    rq_assert(!is_wp_error($ok) && $ok !== false, 'Entry mutation failed');
    $result=['id'=>$id,'kind'=>$job['kind'],'value'=>$job['value']];
} elseif($action==='snapshot') {
    $ids=rq_snapshot($form);
    $result=['ids'=>$ids,'max'=>$ids?max($ids):0];
} elseif($action==='evaluate') {
    $visible=rq_snapshot($form);
    $state=rq_state(rq_raw($form));
    $acked=rq_ids($state['ranges']);
    $missing=array_values(array_diff($visible,$acked));
    sort($missing,SORT_NUMERIC);
    $frontier=$acked?max($acked):0;
    $result=['eligible'=>$visible,'acked'=>$acked,
        'unacknowledged'=>$missing,'rev'=>$state['rev'],
        'frontier_diagnostic_only'=>$frontier,
        'option_bytes'=>strlen(rq_raw($form))];
} elseif($action==='ack') {
    $raw=rq_raw($form);
    $state=rq_state($raw);
    if(isset($job['barrier'])) {
        rq_gate((string)$job['barrier'],['pid'=>getmypid(),'rev'=>$state['rev']]);
    }
    $result=rq_ack($form,$job['op'],(string)$job['ack'],$raw);
} elseif($action==='read') {
    $raw=rq_raw($form);
    rq_assert(get_option(rq_key($form)) === $raw,
        'WordPress readback inconsistent');
    $s=rq_state($raw);
    $result=['state'=>$s,'acked'=>rq_ids($s['ranges']),
        'bytes'=>strlen($raw)];
} elseif($action==='cleanup') {
    $deleted=delete_option(rq_key($form));
    $result=['deleted'=>$deleted,'absent'=>get_option(rq_key($form))===false];
} else { throw new RuntimeException('Invalid reconciliation qualification action'); }
echo 'RECON_RESULT '.rq_encode($result).PHP_EOL;
