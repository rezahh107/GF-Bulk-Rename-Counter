<?php
/** Real, disposable WP/GF snapshot qualification. Not shipped in release ZIP. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('WordPress + real GF required'); }
$job = json_decode(file_get_contents((string)getenv('BRC_SNAPSHOT_JOB')),true,512,JSON_THROW_ON_ERROR);
$action = $job['action'];
$lab = getenv('BRC_LAB_DIR');
function sq_check($condition, string $why): void {
    if (!$condition) { throw new RuntimeException($why); }
}
function sq_entry(int $form, string $marker, string $status='active'): int {
    $id = GFAPI::add_entry(['form_id'=>$form, '1'=>$marker, 'status'=>$status]);
    sq_check(!is_wp_error($id) && (int)$id > 0, 'Real GFAPI insertion failed');
    return (int)$id;
}
function sq_get(int $form, int $offset, int $limit): array {
    $count=0;
    $result=GFAPI::get_entry_ids($form, [
        'status'=>'active', 'field_filters'=>[['key'=>'1','value'=>'match']]
    ], ['key'=>'id','direction'=>'ASC','is_numeric'=>true],
        ['offset'=>$offset,'page_size'=>$limit],$count);
    sq_check(is_array($result), 'GFAPI filtered enumeration failed');
    return array_map('intval',$result);
}
function sq_signal(string $prefix, array $details=[]): void {
    file_put_contents($prefix.'.ready',wp_json_encode($details));
    $stop=microtime(true)+45;
    while(!is_file($prefix.'.go')) {
        if(microtime(true)>$stop) { throw new RuntimeException('Synchronization barrier timeout'); }
        usleep(25000);
    }
}
function sq_ids(int $form): array {
    $all=[];
    for($offset=0; $offset<3000; $offset+=53) {
        $batch=sq_get($form,$offset,53);
        array_push($all,...$batch);
        if(count($batch)<53) { break; }
    }
    sq_check(count($all)===count(array_unique($all)), 'Duplicated IDs in current query');
    return $all;
}
global $wpdb;
if($action==='bootstrap') {
    $f=GFAPI::add_form([
        'title'=>'Temporary exact snapshot test',
        'fields'=>[['id'=>1,'type'=>'text','label'=>'Marker']],
        'button'=>['type'=>'text','text'=>'Submit'],
    ]);
    sq_check(!is_wp_error($f) && (int)$f>0, 'Could not create test form');
    $form=(int)$f;
    $spam=sq_entry($form,'match','spam');
    $ids=[];
    for($i=0;$i<620;$i++) { $ids[]=sq_entry($form,'match'); }
    for($i=0;$i<9;$i++) { sq_entry($form,'other'); }
    $result=['form'=>$form,'expected'=>$ids,'spam'=>$spam,
      'status_change'=>$ids[65],'filter_change'=>$ids[118],
      'delete'=>$ids[257], 'size'=>count($ids)];
    sq_check(sq_ids($form)===$ids,'Seed GFAPI oracle mismatch');
} elseif($action==='scan') {
    $form=(int)$job['form'];$kind=$job['kind'];$prefix=$job['barrier'];
    if($kind==='repeatable') {
        sq_check($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')!==false,
            'Repeatable-read isolation unavailable');
        sq_check($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')!==false,
            'Consistent snapshot unavailable');
    }
    $all=sq_get($form,0,53);
    sq_signal($prefix,['first'=>count($all),'first_id'=>$all[0]??null,'transaction'=>$kind]);
    for($offset=53;$offset<1800;$offset+=53) {
        $batch=sq_get($form,$offset,53);
        array_push($all,...$batch);
        if(count($batch)<53) { break; }
    }
    if($kind==='repeatable') { sq_check($wpdb->query('COMMIT')!==false,'Read transaction commit failed'); }
    $result=['count'=>count($all),'unique'=>count(array_unique($all)),
        'ids'=>$all,'kind'=>$kind];
} elseif($action==='mutate') {
    $form=(int)$job['form'];
    $r1=GFAPI::update_entry_property((int)$job['spam'],'status','active');
    $r2=GFAPI::update_entry_property((int)$job['status_change'],'status','spam');
    $r3=GFAPI::update_entry_field((int)$job['filter_change'],1,'other');
    $r4=GFAPI::delete_entry((int)$job['delete']);
    foreach([$r1,$r2,$r3,$r4] as $v) { sq_check(!is_wp_error($v)&&$v!==false,'GF mutation failed'); }
    $inserted=sq_entry($form,'match');
    $result=['inserted'=>$inserted, 'active'=>$job['spam'],
       'status_removed'=>$job['status_change'],
       'membership_removed'=>$job['filter_change'], 'deleted'=>$job['delete']];
} elseif($action==='hold_insert') {
    $form=(int)$job['form'];
    sq_check($wpdb->query('START TRANSACTION')!==false,'Delayed transaction start failed');
    $id=sq_entry($form,'match');
    sq_signal($job['barrier'], ['allocated_id'=>$id,'in_transaction'=>true]);
    sq_check($wpdb->query('COMMIT')!==false,'Delayed transaction commit failed');
    $result=['committed_id'=>$id];
} elseif($action==='insert') {
    $result=['id'=>sq_entry((int)$job['form'],'match')];
} elseif($action==='capture') {
    $form=(int)$job['form'];
    sq_check($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')!==false,
        'Isolation setup failed');
    sq_check($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')!==false,
        'Cannot establish snapshot');
    $visible=sq_ids($form);
    $max=$visible?max($visible):0;
    sq_check($wpdb->query('COMMIT')!==false,'Snapshot commit failed');
    $result=['ids'=>$visible,'frontier'=>$max,'count'=>count($visible)];
} elseif($action==='read_after') {
    $form=(int)$job['form'];
    $id=(int)$job['id'];
    $e=GFAPI::get_entry($id);
    sq_check(is_array($e)&&(int)$e['form_id']===$form,
        'Previously delayed committed GF Entry not visible on readback');
    $all=sq_ids($form);
    sq_check(in_array($id,$all,true),'Committed Entry absent in filtered GFAPI enumeration');
    $result=['id'=>$id, 'ids'=>$all, 'max'=>max($all)];
} elseif($action==='info') {
    $table=GFFormsModel::get_entry_table_name();
    $meta=$wpdb->prefix.'gf_entry_meta';
    $engines=[];
    foreach([$table,$meta] as $name) {
        $engines[$name]=$wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $name));
    }
    $source=[];
    foreach([['GFAPI','get_entry_ids'],['GFAPI','add_entry'],
             ['GFFormsModel','search_leads']] as [$class,$method]) {
        if(method_exists($class,$method)) {
            $rm=new ReflectionMethod($class,$method);
            $source[]=['name'=>$class.'::'.$method,
                'file'=>str_replace(ABSPATH,'',$rm->getFileName()),
                'start'=>$rm->getStartLine(),'end'=>$rm->getEndLine(),
                'sha256'=>hash_file('sha256',$rm->getFileName())];
        }
    }
    $result=['engines'=>$engines,'isolation'=>$wpdb->get_var('SELECT @@tx_isolation'),
        'wp'=>get_bloginfo('version'),'php'=>PHP_VERSION,
        'mariadb'=>$wpdb->db_version(),'source'=>$source];
} else { throw new RuntimeException('Unsupported snapshot probe action'); }
echo 'SNAPSHOT_RESULT '.wp_json_encode($result).PHP_EOL;
