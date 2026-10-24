<?php
/** Source/API + actual InnoDB metadata lock experiment, temporary CI only. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Authentic GF runtime needed'); }
global $wpdb;
$lab=getenv('BRC_LAB_DIR');
$act=(string)getenv('BRC_META_ACTION');
$case=(string)getenv('BRC_META_CASE');
$gate=(string)getenv('BRC_META_GATE');
$fixture=$lab.'/qf-meta-probe.json';
$formdata=is_file($fixture)?json_decode(file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR):[];
$table=\GFFormsModel::get_entry_meta_table_name();
if (!preg_match('/^[A-Za-z0-9_]+$/',$table))throw new RuntimeException('Untrusted meta table name');
$original=json_decode(file_get_contents($lab.'/independent.json'),true,512,JSON_THROW_ON_ERROR)['cases'];
$source1=(string)(GFAPI::get_entry((int)$original['one']['entry_ids'][0])['1']??'');
$source2=(string)(GFAPI::get_entry((int)$original['multiple']['entry_ids'][0])['1']??'');
if (!$source1 || !$source2 || $source1===$source2) throw new RuntimeException('Two verified distinct GF uploaded files needed');
$emit=static function($label,$data):void {echo $label.' '.wp_json_encode($data,JSON_UNESCAPED_SLASHES).PHP_EOL;};
$assert=static function($ok,$msg):void {if(!$ok)throw new RuntimeException($msg);};
if ($act==='source') {
    foreach ([['GFAPI','update_entry_field'],['GFFormsModel','update_entry_field_value'],
        ['GFFormsModel','update_lead_field_value'],['GFFormsModel','get_entry_meta_table_name']] as $api) {
        if (!method_exists($api[0],$api[1]))continue;
        $r=new ReflectionMethod($api[0],$api[1]);
        $src=file($r->getFileName());
        $length=min(185,$r->getEndLine()-$r->getStartLine()+1);
        $emit('QF_GF_SOURCE',['method'=>implode('::',$api),
            'file'=>substr($r->getFileName(),strlen(ABSPATH)),
            'start'=>$r->getStartLine(),'end'=>$r->getEndLine(),
            'source'=>implode('',array_slice($src,$r->getStartLine()-1,$length))]);
    }
    $indexes=$wpdb->get_results("SHOW INDEX FROM {$table}",ARRAY_A);
    $emit('QF_META_SCHEMA',['table'=>$table,'engine'=>$wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)),
        'indexes'=>$indexes]);
    exit;
}
if($act==='setup'){
    foreach(['existing','absent'] as $key){
        $form=GFAPI::add_form(['title'=>'QF meta range lock '. $key,
            'fields'=>[['id'=>1,'type'=>'fileupload','label'=>'Upload']],
            'button'=>['type'=>'text','text'=>'Submit']]);
        $assert(!is_wp_error($form),'GF form create failed');
        $id=GFAPI::add_entry(['form_id'=>(int)$form,'1'=>$key==='existing'?$source1:'']);
        $assert(!is_wp_error($id),'GF Entry create failed');
        $formdata[$key]=['form'=>(int)$form,'id'=>(int)$id,'before'=>$key==='existing'?$source1:''];
    }
    file_put_contents($fixture,wp_json_encode($formdata));
    $emit('QF_META_SETUP',$formdata);
    exit;
}
$assert(isset($formdata[$case]),'No fixture for case');
$id=(int)$formdata[$case]['id'];
if ($act==='inspect') {
    $meta=$wpdb->get_results($wpdb->prepare("SELECT id,entry_id,meta_key,meta_value FROM {$table} WHERE entry_id=%d ORDER BY id",$id),ARRAY_A);
    $value=GFAPI::get_entry($id)['1']??null;
    $emit('QF_META_STATE',['case'=>$case,'entry_id'=>$id,'value'=>$value,'meta'=>$meta]);
    exit;
}
if ($act==='lock') {
    $assert($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')!==false,'RR cannot be set');
    $assert($wpdb->query('START TRANSACTION')!==false,'DB transaction cannot start');
    // Invoke the ACTUAL production metadata locking method, not an
    // approximation of its SQL. This proves the selected enforcement seam
    // blocks the independent real GFAPI UPDATE and absent-field INSERT.
    (new ReflectionMethod(\GFBulkRename\New_Download::class, 'lock_entry_metadata'))
        ->invoke(null, [$id]);
    $row=$wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE entry_id=%d",$id),ARRAY_A);
    $assert(file_put_contents($gate.'.locked',wp_json_encode(['pid'=>getmypid(),'rows'=>count($row)]))!==false,'lock marker write');
    $until=microtime(true)+35;
    while(!is_file($gate.'.go')){
        if(microtime(true)>$until){$wpdb->query('ROLLBACK');throw new RuntimeException('Lock release timeout');}
        usleep(20000);
    }
    $assert($wpdb->query('COMMIT')!==false,'DB lock transaction commit failed');
    $emit('QF_META_LOCK_RELEASED',['case'=>$case,'rows'=>count($row)]);
    exit;
}
if ($act==='mutate') {
    if($gate)file_put_contents($gate.'.writer_ready',(string)getmypid());
    $trace=[];
    add_filter('query',static function($sql)use(&$trace,$table){
        if(strpos($sql,$table)!==false)$trace[]=$sql;
        return $sql;
    });
    $before=(string)(GFAPI::get_entry($id)['1']??'');
    $ok=GFAPI::update_entry_field($id,1,$source2);
    $assert(!is_wp_error($ok)&&$ok!==false,'GFAPI update_entry_field failed');
    $after=(string)(GFAPI::get_entry($id)['1']??'');
    $assert($after===$source2,'Updated GF source not readable');
    $emit('QF_META_WRITER',['case'=>$case,'before'=>$before,'after'=>$after,
        'queries'=>$trace,'changed'=>$before!==$after]);
    exit;
}
throw new RuntimeException('Unknown QF meta probe action');
