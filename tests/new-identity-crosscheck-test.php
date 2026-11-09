<?php
/** Pure falsifiable controls; no production data, database or user actions. */
define('ABSPATH', '/tmp/');
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_SLASHES); }
function maybe_unserialize($value) { return $value; }
function gform_get_meta($id, $key) { return false; }
class GFAPI {
    public static array $entries = [];
    public static ?array $formFields = null;
    public static function get_form($id) {
        return ['fields' => self::$formFields ??
            [(object)['id' => 1, 'type' => 'fileupload']]];
    }
    public static function get_entry($id) { return self::$entries[$id] ?? false; }
}
require dirname(__DIR__) . '/includes/class-new_identity_crosscheck.php';
use GFBulkRename\New_Identity_Crosscheck as Check;
function eq($actual, $expected, $label) {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL $label expected=" . var_export($expected, true) .
            ' actual=' . var_export($actual, true) . "\n"); exit(1);
    }
}
function result($obs, $new, $plan, $before, $after, $stable=true) {
    return Check::evaluate($obs,$new,$plan,$before,$after,$stable);
}
$obs = ['complete'=>true,'ids'=>[4,11],'excluded'=>2,'highest'=>16,
    'previous_covered_highest'=>3,'values'=>[],
    'occurrences'=>[
        ['entry_id'=>11,'field_id'=>'1','slot'=>0,'url'=>'https://example.test/a.txt','deleted'=>false],
        ['entry_id'=>11,'field_id'=>'1','slot'=>1,'url'=>'https://example.test/b.txt','deleted'=>false]
    ]];
$plan = [
    ['entry_id'=>11,'field_id'=>'1','url'=>'https://example.test/a.txt',
        'size'=>4,'sha256'=>str_repeat('a',64)],
    ['entry_id'=>11,'field_id'=>'1','url'=>'https://example.test/b.txt',
        'size'=>5,'sha256'=>str_repeat('b',64)]
];
$src = [0=>['size'=>4,'sha256'=>str_repeat('a',64)],
        1=>['size'=>5,'sha256'=>str_repeat('b',64)]];
$r = result($obs,[4,11],$plan,$src,$src);
eq($r['classification'],'EXPECTED_EXCLUSION','normal eligibility and known exclusion');
eq($r['eligible_entries'],2,'count from IDs, not 16-3');
eq($r['eligible_files'],2,'file occurrences independent of Entry count');
eq($r['previous_covered_highest'],3,'cumulative ACK highest');
$clean = $obs; $clean['excluded']=0;
eq(result($clean,[4,11],$plan,$src,$src)['classification'],
    'NO_OBSERVED_MISMATCH','clean independent full set');
$r = result($clean,[11],$plan,$src,$src);
eq($r['classification'],'CONFIRMED_MISMATCH','missing eligible zero-file Entry');
eq($r['discrepancies'][0]['entry_id'],4,'independent omitted Entry identity');
$r = result($clean,[4,11],[$plan[0]],$src,$src);
eq($r['classification'],'CONFIRMED_MISMATCH','internally valid reduced file plan');
eq($r['discrepancies'][0]['slot'],1,'missing file slot');
eq(result($clean,[4,11],$plan,$src,[0=>$src[0]])['classification'],
    'INCONCLUSIVE','source unreadable or changed after plan');
eq(result($clean,[4,11],$plan,$src,$src,false)['classification'],
    'INCONCLUSIVE','status/field timing changed');
foreach ([11.5, '11', true, 0] as $invalid) {
    $bad=$clean; $bad['ids']=[4,$invalid];
    eq(result($bad,[4,11],$plan,$src,$src)['classification'],
        'INCONCLUSIVE','malformed identity must never coerce');
}
$bad=$clean; $bad['ids']=[4,4];
eq(result($bad,[4,11],$plan,$src,$src)['classification'],
    'INCONCLUSIVE','duplicate Entry identity');
$extra=$plan;
$extra[]=['entry_id'=>16,'field_id'=>'1','url'=>'https://example.test/c.txt',
    'size'=>1,'sha256'=>str_repeat('c',64)];
eq(result($clean,[4,11],$extra,$src,$src)['classification'],
    'INCONCLUSIVE','extra selected occurrence not in observation');
$known=$obs; $known['ids']=[]; $known['occurrences']=[]; $known['excluded']=3;
eq(result($known,[],[],[],[])['classification'],'EXPECTED_EXCLUSION',
    'known/inactive Entry intentionally excluded');
$first=$clean; $first['previous_covered_highest']=null;
eq(result($first,[4,11],$plan,$src,$src)['previous_covered_highest'],null,
    'no invented pre-ACK reference');
$dup=$clean; $dup['occurrences'][1]['url']=$dup['occurrences'][0]['url'];
$two=[$plan[0],$plan[0]]; $repeat=[0=>$src[0],1=>$src[0]];
eq(result($dup,[4,11],$two,$repeat,$repeat)['classification'],
    'NO_OBSERVED_MISMATCH','same URL repeated in two slots');
eq(result($dup,[4,11],[$two[0]],$repeat,$repeat)['classification'],
    'CONFIRMED_MISMATCH','duplicate-source multiplicity omission');
GFAPI::$entries=[
    4=>['id'=>'4','form_id'=>'9','status'=>'active','1'=>''],
    11=>['id'=>'11','form_id'=>'9','status'=>'active','1'=>'https://example.test/a.txt']
];
$observed=Check::observe(9,[
    ['id'=>'3','status'=>'spam'],['id'=>'4','status'=>'active'],
    ['id'=>'11','status'=>'active'],['id'=>'16','status'=>'trash']
],[[3,3]],[[3,3]]);
eq($observed['ids'],[4,11],'GF identity source, gaps and inactive statuses');
eq(count($observed['occurrences']),1,'GF file occurrence read outside NEW plan');
eq($observed['excluded'],2,'known/inactive exclusions');
GFAPI::$entries[11]['form_id']='10';
eq(Check::observe(9,[['id'=>'11','status'=>'active']],[],[])['complete'],false,
    'cross-form Entry cannot qualify');
GFAPI::$entries[11]['form_id']='9';
GFAPI::$entries[11]['id']=11.5;
eq(Check::observe(9,[['id'=>'11','status'=>'active']],[],[])['complete'],false,
    'fractional GF Entry cannot qualify');
GFAPI::$entries[11]['id']='11';
GFAPI::$entries[11]['status']='trash';
eq(Check::observe(9,[['id'=>'11','status'=>'active']],[],[])['complete'],false,
    'status changed across observation');
$rows=array_map(static fn($id)=>['id'=>$id,'status'=>'active'],range(1,501));
eq(Check::observe(9,$rows,[],[])['complete'],false,'bounded 500 candidate scan');
// PRI-FND-001 discriminator: Entry and source values remain unchanged while
// the authentic GF-shaped metadata upload field set changes.
GFAPI::$entries = [11 => ['id'=>'11', 'form_id'=>'9', 'status'=>'active',
    '1'=>'https://example.test/a.txt']];
GFAPI::$formFields = [(object)['id'=>1, 'type'=>'fileupload']];
$schemaObs = Check::observe(9, [['id'=>'11', 'status'=>'active']], [], []);
eq($schemaObs['complete'], true, 'valid schema observation');
eq($schemaObs['upload_schema'], [['id'=>1, 'type'=>'fileupload']],
    'canonical observed upload identity and type');
eq(Check::stable_upload_schema(9, $schemaObs), true, 'unchanged schema before plan');
eq(Check::stable_entries(9, $schemaObs), true, 'unchanged schema after plan');
$oneSource = [0 => ['size'=>4,'sha256'=>str_repeat('a',64)]];
$onePlan = [['entry_id'=>11, 'field_id'=>'1',
    'url'=>'https://example.test/a.txt', 'size'=>4, 'sha256'=>str_repeat('a',64)]];
eq(result($schemaObs, [11], $onePlan, $oneSource, $oneSource,
    Check::stable_entries(9, $schemaObs))['classification'],
    'NO_OBSERVED_MISMATCH', 'stable schema clean result');
eq(result($schemaObs, [], [], $oneSource, $oneSource,
    Check::stable_entries(9, $schemaObs))['classification'],
    'CONFIRMED_MISMATCH', 'stable schema missing Entry');
eq(result($schemaObs, [11], [], $oneSource, $oneSource,
    Check::stable_entries(9, $schemaObs))['classification'],
    'CONFIRMED_MISMATCH', 'stable schema missing occurrence');
$drift = [
    'removed' => [],
    'changed_type' => [(object)['id'=>1,'type'=>'text']],
    'added_upload' => [(object)['id'=>1,'type'=>'fileupload'],
        (object)['id'=>2,'type'=>'fileupload']],
    'invalid_identity' => [(object)['id'=>1.5,'type'=>'fileupload']],
    'duplicate_identity' => [(object)['id'=>1,'type'=>'fileupload'],
        (object)['id'=>'1','type'=>'text']],
    'missing_type' => [(object)['id'=>1]],
];
foreach ($drift as $case => $fields) {
    GFAPI::$formFields = $fields;
    // Preplan AND post-plan guards must be able to independently falsify the
    // verdict. Removing/bypassing this check makes the assertion fail.
    eq(Check::stable_upload_schema(9, $schemaObs), false, 'preplan drift ' . $case);
    eq(Check::stable_entries(9, $schemaObs), false, 'postplan drift ' . $case);
    $stable = Check::stable_upload_schema(9, $schemaObs) &&
        Check::stable_entries(9, $schemaObs);
    eq(result($schemaObs, [11], [], $oneSource, $oneSource, $stable)['classification'],
        'INCONCLUSIVE', 'schema drift takes priority over apparent omission ' . $case);
}
GFAPI::$formFields = [(object)['id'=>2,'type'=>'text'],
    (object)['id'=>1,'type'=>'fileupload']];
eq(Check::stable_upload_schema(9, $schemaObs), true, 'non-upload field irrelevant');
GFAPI::$formFields = [(object)['id'=>1,'type'=>'fileupload'],
    (object)['id'=>2,'type'=>'fileupload']];
$twoFields = Check::observe(9, [['id'=>'11','status'=>'active']], [], []);
GFAPI::$formFields = [(object)['id'=>2,'type'=>'fileupload'],
    (object)['id'=>1,'type'=>'fileupload']];
eq(Check::stable_upload_schema(9, $twoFields), true, 'upload order irrelevant');
GFAPI::$formFields = [(object)['id'=>3.5, 'type'=>'fileupload']];
eq(Check::observe(9, [['id'=>'11','status'=>'active']], [], [])['complete'],
    false, 'invalid schema at observation fails closed');
GFAPI::$formFields = null;
echo "PASS_NEW_IDENTITY_CROSSCHECK_ISOLATED_NEGATIVE_AND_POSITIVE_CONTROLS\n";
