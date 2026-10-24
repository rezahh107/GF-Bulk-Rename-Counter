<?php
/**
 * Authentic disposable WordPress/GFAPI status-race worker. Independent WP-CLI
 * processes are used for acknowledgment and status mutation.
 */
if (!defined('ABSPATH') || !class_exists('GFAPI')) {
    throw new RuntimeException('Real WordPress and Gravity Forms runtime required');
}
global $wpdb;
$lab = (string)getenv('BRC_LAB_DIR');
$fixture_file = $lab . '/qf-status-fixtures.json';
$action = (string)getenv('BRC_STATUS_ACTION');
$case = (string)getenv('BRC_STATUS_CASE');
$fixture = is_file($fixture_file)
    ? json_decode(file_get_contents($fixture_file), true, 512, JSON_THROW_ON_ERROR) : [];
$invoke = static function(string $method, ...$args) {
    return (new ReflectionMethod(\GFBulkRename\New_Download::class, $method))->invoke(null, ...$args);
};
$assert = static function($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$emit = static function(array $values): void {
    echo 'QF_STATUS_WORKER ' . wp_json_encode($values, JSON_UNESCAPED_SLASHES) . PHP_EOL;
};
$table = \GFFormsModel::get_entry_table_name();
$assert(is_string($table) && (bool)preg_match('/^[A-Za-z0-9_]+$/', $table), 'Unknown GF entry table');
$database_status = static function(int $entry_id) use ($wpdb, $table): string {
    return (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d", $entry_id));
};
try {
    if ($action === 'setup') {
        $other = json_decode(file_get_contents($lab . '/independent.json'), true, 512, JSON_THROW_ON_ERROR);
        $source = GFAPI::get_entry((int)$other['cases']['one']['entry_ids'][0]);
        $file = is_array($source) ? (string)($source['1'] ?? '') : '';
        $assert($file !== '', 'Requires authentic file-backed source Entry');
        foreach (['baseline', 'candidate'] as $mode) {
            foreach (['before', 'during', 'after'] as $phase) {
                $name = "{$mode}_{$phase}";
                $form = GFAPI::add_form([
                    'title' => "QF status ACK cutoff {$name}",
                    'fields' => [['id'=>1, 'type'=>'fileupload', 'label'=>'Verified uploaded file']],
                    'button'=>['type'=>'text', 'text'=>'Submit'],
                ]);
                $assert(!is_wp_error($form) && (int)$form > 0, 'Could not add isolated GF form');
                $entry = GFAPI::add_entry(['form_id'=>(int)$form, '1'=>$file]);
                $assert(!is_wp_error($entry) && (int)$entry > 0, 'Could not add isolated GF Entry');
                $invoke('seed', (int)$form);
                $invoke('seed_pending', (int)$form);
                $fixture[$name]=['form'=>(int)$form,'entry'=>(int)$entry,'file'=>$file];
            }
        }
        file_put_contents($fixture_file, wp_json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $emit(['setup'=>$fixture]);
        exit;
    }
    if ($action === 'source') {
        $r=new ReflectionMethod('GFAPI','update_entry_property');
        $lines=file($r->getFileName());
        $emit(['gfapi_status_source'=>[
            'path'=>substr($r->getFileName(),strlen(ABSPATH)),
            'start'=>$r->getStartLine(),'end'=>$r->getEndLine(),
            'body'=>implode('',array_slice($lines,$r->getStartLine()-1,min(130,$r->getEndLine()-$r->getStartLine()+1))),
        ],'entry_table'=>$table]);
        exit;
    }
    $assert(isset($fixture[$case]),'Unknown QF status case');
    $form = (int)$fixture[$case]['form'];
    $entry_id = (int)$fixture[$case]['entry'];
    if ($action === 'prepare') {
        $raw = $invoke('raw',$form);
        $pending = $invoke('seed_pending',$form);
        $files = $invoke('plan',$form,[$entry_id]);
        $assert(count($files)===1, 'Status tests require exactly one genuine file');
        $op = [
            'v'=>2, 'id'=>bin2hex(random_bytes(16)), 'user'=>get_current_user_id(),
            'form'=>$form,'expires'=>time()+900,'base'=>hash('sha256',$raw),
            'all'=>[$entry_id],'ids'=>[$entry_id],'files'=>count($files),
            'zero_entries'=>0,'fingerprint'=>$invoke('fingerprint',$files),
            'zip_sha'=>'','zero'=>false,'status'=>'prepared',
        ];
        $invoke('publish_prepared',$form,$raw,$pending,$op);
        $zip=\GFBulkRename\Flat_Zip_Download::write_verified_zip($files);
        try { $hash=hash_file('sha256',$zip); } finally { @unlink($zip); }
        $assert(is_string($hash) && strlen($hash)===64,'Failed to hash genuine ZIP');
        $invoke('mark_ready',$form,$op['id'],$hash);
        $fixture[$case]['operation']=$op['id'];
        $fixture[$case]['sha']=$hash;
        file_put_contents($fixture_file,wp_json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $emit(['prepared'=>$case,'entry'=>$entry_id,'operation'=>$op['id'],'files'=>count($files),'sha'=>$hash]);
    } elseif ($action === 'mutate') {
        $before=$database_status($entry_id);
        $assert($before==='active','Status writer expects active source');
        $trace=[];
        add_filter('query',static function(string $sql) use (&$trace,$table):string {
            if(strpos($sql,$table)!==false && preg_match('/\b(?:UPDATE|INSERT|SELECT)\b/i',$sql)) {
                $trace[]=$sql;
            }
            return $sql;
        });
        $begin=microtime(true);
        $ok=GFAPI::update_entry_property($entry_id,'status','spam');
        $elapsed=round(microtime(true)-$begin,4);
        $assert(!is_wp_error($ok) && $ok!==false,'GFAPI status mutation returned error');
        $after=$database_status($entry_id);
        $assert($after==='spam','GFAPI status mutation not committed');
        $emit(['mutated'=>$case,'entry'=>$entry_id,'before'=>$before,'after'=>$after,
            'elapsed_seconds'=>$elapsed,'queries'=>$trace]);
    } elseif ($action === 'ack') {
        $assert(isset($fixture[$case]['operation']),'No prepared operation');
        $_SERVER['REQUEST_METHOD']='POST';
        $_POST=[
            'form_id'=>(string)$form,
            '_wpnonce'=>wp_create_nonce('gfbrc_new_'.$form),
            'operation'=>$fixture[$case]['operation'],
            'confirmed'=>'yes','zero_reviewed'=>'no','sha256'=>$fixture[$case]['sha'],
        ];
        \GFBulkRename\New_Download::ack();
        throw new RuntimeException('ACK returned without actual WP JSON exit');
    } elseif ($action === 'state') {
        $state=$invoke('state',$invoke('raw',$form));
        $selection=$invoke('snapshot',$form,$state);
        $entry=GFAPI::get_entry($entry_id);
        $emit(['case'=>$case,'entry'=>$entry_id,
            'db_status'=>$database_status($entry_id),
            'gfapi_status'=>is_array($entry)?($entry['status']??null):null,
            'rev'=>$state['rev'],'known'=>$state['known'],'covered'=>$state['covered'],
            'new_ids'=>$selection[1],
            'coverage_matches'=> $state['rev']>0 ? $state['covered']===[[$entry_id,$entry_id]]:count($state['covered'])===0,
        ]);
    } else { throw new RuntimeException('Unrecognized QF status action'); }
} catch (Throwable $e) {
    fwrite(STDERR,'QF_STATUS_WORKER_ERROR '.$e->getMessage().PHP_EOL);
    exit(42);
}
