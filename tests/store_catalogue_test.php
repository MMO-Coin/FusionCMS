<?php

namespace CodeIgniter\Events {
    class Events {
        public static array $queries = [];
        public static function trigger($event, ...$arguments) { if ($event === 'DBQuery') self::$queries[] = (string) $arguments[0]; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('ENVIRONMENT', 'testing'); define('CI_DEBUG', false);
    function log_message(...$arguments) {}
    function config(...$arguments) { return (object) ['limitZeroAsAll' => true]; }
    class CI_Model { public $db; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/modules/store/models/Store_model.php';
    function checkCatalogue($condition, $message): void { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
    function catalogueIds(array $rows): array { return array_map('intval', array_column($rows, 'id')); }
    function exerciseCatalogue($db): void {
        $db->query('CREATE TABLE store_groups (id INT PRIMARY KEY, title VARCHAR(100), orderNumber INT)');
        $db->query('CREATE TABLE store_items (id INT PRIMARY KEY, itemid TEXT, itemcount TEXT, name VARCHAR(100), quality INT, vp_price INT, dp_price INT, realm INT, description VARCHAR(150), icon VARCHAR(100), `group` INT, tooltip INT, `query` TEXT, command TEXT)');
        $db->table('store_groups')->insert(['id' => 1, 'title' => 'Equipment', 'orderNumber' => 1]);
        $db->table('store_groups')->insert(['id' => 31, 'title' => 'Weapons', 'orderNumber' => 2]);
        $base = ['itemid'=>'123','itemcount'=>'1','name'=>'Catalogue item','quality'=>3,'vp_price'=>5,'dp_price'=>2,'realm'=>1,
            'description'=>'Catalogue description','icon'=>'inv_misc_questionmark','group'=>31,'tooltip'=>1,'query'=>'private delivery SQL','command'=>'private delivery command'];
        for ($id = 1; $id <= 125; $id++) $db->table('store_items')->insert(array_replace($base, ['id'=>$id,'name'=>sprintf('Catalogue item %03d',$id),'vp_price'=>$id % 2 ? 0 : 5,'dp_price'=>$id % 2 ? 2 : 0]));
        foreach ([[1001,0,'Literal%Sword'],[1002,null,'Literal_Sword'],[1003,999999,"O'Name"]] as [$id,$group,$name]) $db->table('store_items')->insert(array_replace($base,['id'=>$id,'group'=>$group,'name'=>$name]));
        $db->table('store_items')->insert(array_replace($base,['id'=>1004,'group'=>1,'name'=>'<b>Rare Sword</b>','quality'=>4]));
        $db->table('store_items')->insert(array_replace($base,['id'=>2001,'realm'=>2,'name'=>'Remote realm item']));
        $model = new Store_model(); $model->db = $db;
        checkCatalogue($model->countCatalogueItems(1) === 129 && $model->countCatalogueItems(2) === 1, 'Catalogue counts only the selected realm');
        checkCatalogue(count($model->getItems(1)) === 25, 'Legacy item reads remain bounded');
        foreach ([0,25,50,100] as $offset) checkCatalogue(catalogueIds($model->getCatalogueItems(1,['group'=>'31'],$offset)) === range($offset+1,$offset+25), 'Selected group has stable page at offset ' . $offset);
        checkCatalogue(!$model->getCatalogueItems(1,['group'=>'31'],125), 'A page beyond the group contains no records');
        checkCatalogue(count($model->getCatalogueItems(1,[],0,99999)) === 100, 'Oversized catalogue pages are capped at 100');
        checkCatalogue(catalogueIds($model->getCatalogueItems(1,['group'=>'0'],0,100)) === [1001,1002,1003] && $model->countCatalogueItems(1,['group'=>'0']) === 3, 'Zero, null and deleted groups stay visible as ungrouped items');
        $groups = $model->getCatalogueGroups(1);
        checkCatalogue(count($groups) === 3 && array_map('intval',array_column($groups,'id')) === [1,31,0], 'Group metadata uses only the selected realm and one ungrouped bucket');
        checkCatalogue(!$model->getCatalogueItems(1,['group'=>'999999']), 'A deleted group ID cannot act as a selectable existing group');
        foreach (['Literal%','Literal_',"O'Name"] as $name) checkCatalogue($model->countCatalogueItems(1,['name'=>$name]) === 1 && count($model->getCatalogueItems(1,['name'=>$name])) === 1, 'Catalogue treats search text literally: ' . $name);
        checkCatalogue($model->countCatalogueItems(1,['name'=>"' OR 1=1 --"]) === 0, 'Search values stay bound instead of becoming SQL');
        checkCatalogue($model->countCatalogueItems(1,['group'=>'31','currency'=>'vp']) === 62 && $model->countCatalogueItems(1,['group'=>'31','currency'=>'dp']) === 63, 'Payment filters count matching offers across the whole group');
        checkCatalogue($model->countCatalogueItems(1,['quality'=>'4']) === 1 && catalogueIds($model->getCatalogueItems(1,['quality'=>'4'])) === [1004], 'Quality counts and items use the same filter');
        checkCatalogue(catalogueIds($model->getCatalogueItems(1,['group'=>'31','sort'=>'priceVp'],0,3)) === [1,3,5], 'Price sorting uses an ID tie breaker');
        checkCatalogue(catalogueIds($model->getCatalogueItems(1,['sort'=>'quality'],0,1)) === [1004], 'Quality sorting is applied by the server before the page limit');
        foreach ([[0,0],[-1,25],[0,-1],[[],25],[0,'all']] as [$offset,$limit]) checkCatalogue(!$model->getCatalogueItems(1,[],$offset,$limit), 'Malformed catalogue bounds never fetch all records');
        foreach ([['group'=>[]],['quality'=>'8'],['currency'=>'token'],['name'=>[]],['name'=>str_repeat('x',101)],['sort'=>'name DESC; DROP TABLE store_items']] as $filter) checkCatalogue(!$model->getCatalogueItems(1,$filter) && $model->countCatalogueItems(1,$filter) === 0, 'Malformed catalogue filters have no matching query');
        checkCatalogue(!$model->getCatalogueItems(-1) && !$model->getCatalogueItems([]), 'Malformed realm identifiers are rejected');
        \CodeIgniter\Events\Events::$queries=[];
        $rows=$model->getCatalogueItems(1,[],0,25); $model->countCatalogueItems(1);
        checkCatalogue(count(\CodeIgniter\Events\Events::$queries) === 2, 'An item page and matching count use two queries without per-item lookups');
        checkCatalogue(!array_key_exists('query',$rows[0]) && !array_key_exists('command',$rows[0]), 'Catalogue reads do not expose delivery SQL or console commands');
    }
    exerciseCatalogue(new CodeIgniter\Database\SQLite3\Connection(['database'=>':memory:','DBDebug'=>true]));
    if (!getenv('FUSION_TEST_MYSQL_HOST')) exit;
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'),['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Only a disposable loopback test server is allowed');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $schema='fusion_catalogue_'.bin2hex(random_bytes(6)); $nonce=bin2hex(random_bytes(16));
    $admin=new mysqli(getenv('FUSION_TEST_MYSQL_HOST'),'root',getenv('FUSION_TEST_MYSQL_PASSWORD'),'',(int)(getenv('FUSION_TEST_MYSQL_PORT')?:3306));
    $admin->query("CREATE DATABASE `$schema`");$admin->select_db($schema);
    $admin->query('CREATE TABLE _catalogue_owner (nonce CHAR(32))');$admin->query("INSERT INTO _catalogue_owner VALUES ('$nonce')");
    try {
        $db=new CodeIgniter\Database\MySQLi\Connection(['hostname'=>getenv('FUSION_TEST_MYSQL_HOST'),'username'=>'root','password'=>getenv('FUSION_TEST_MYSQL_PASSWORD'),'port'=>(int)(getenv('FUSION_TEST_MYSQL_PORT')?:3306),'database'=>$schema,'DBDebug'=>true,'charset'=>'utf8mb4']);
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'");
        exerciseCatalogue($db);
    } finally {
        $owner=$admin->query('SELECT nonce FROM _catalogue_owner')->fetch_assoc();
        if (!preg_match('/^fusion_catalogue_[a-f0-9]{12}$/D',$schema) || !hash_equals($nonce,$owner['nonce'])) throw new RuntimeException('Refusing unexpected schema cleanup');
        $admin->query("DROP DATABASE `$schema`");
    }
}
