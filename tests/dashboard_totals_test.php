<?php

namespace CodeIgniter\Events {
    class Events { public static array $queries = []; public static function trigger($event, ...$args) { if ($event === 'DBQuery') self::$queries[] = (string) $args[0]; } }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('ENVIRONMENT', 'testing'); define('CI_DEBUG', false);
    function log_message(...$args) {}
    function config(...$args) { return (object) ['limitZeroAsAll' => true]; }
    class CI_Model { public $db; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/modules/admin/models/Dashboard_model.php';
    function checkDashboard($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
    function graphTotals($rows) { $totals = []; foreach ($rows as $row) $totals[(int) $row['ago']] = ($totals[(int) $row['ago']] ?? 0) + (int) $row['ipCount']; return $totals; }
    function exerciseDashboard($db): void {
        $db->query('CREATE TABLE visitor_log (date VARCHAR(10), ip VARCHAR(45))');
        $db->query('CREATE UNIQUE INDEX unique_date_ip ON visitor_log (date, ip)');
        $db->query('CREATE TABLE daily_signups (date VARCHAR(10) PRIMARY KEY, amount INT)');
        $db->query('CREATE TABLE monthly_income (month VARCHAR(7) PRIMARY KEY, amount DECIMAL(12,2))');
        $db->query('CREATE TABLE monthly_votes (month VARCHAR(7) PRIMARY KEY, amount INT)');
        foreach ([['2023-12-31','Y'], ['2024-02-28','P'], ['2024-02-29','P'], ['2024-03-01','A'], ['2024-03-30','A'], ['2024-03-31','A'], ['2024-03-31','B'], ['2024-04-01','future']] as [$date,$ip]) $db->table('visitor_log')->insert(['date'=>$date,'ip'=>$ip]);
        foreach (['2024-02-29'=>4,'2024-03-01'=>2,'2024-03-30'=>3,'2024-03-31'=>7,'2024-04-01'=>99] as $date=>$amount) $db->table('daily_signups')->insert(compact('date','amount'));
        foreach (['2024-01'=>99,'2024-02'=>5.25,'2024-03'=>12.50,'2024-04'=>99] as $month=>$amount) $db->table('monthly_income')->insert(compact('month','amount'));
        foreach (['2024-02'=>4,'2024-03'=>10,'2024-04'=>99] as $month=>$amount) $db->table('monthly_votes')->insert(compact('month','amount'));
        $model = new Dashboard_model(); $model->db=$db;
        $march = new DateTimeImmutable('2024-03-31 23:59:59', new DateTimeZone('Asia/Karachi'));
        \CodeIgniter\Events\Events::$queries=[];
        $totals=$model->getTotals($march);
        checkDashboard(count(\CodeIgniter\Events\Events::$queries)===4, 'Dashboard totals use four aggregate queries');
        checkDashboard($totals['signups']===['today'=>7,'month'=>12,'this'=>12,'last'=>4], 'March-end signup totals sum the full calendar period and exclude future rows');
        checkDashboard($totals['unique']===['today'=>2,'month'=>2] && $totals['views']===['today'=>2,'month'=>4], 'Unique visitors and daily visitor totals keep distinct documented meanings');
        checkDashboard((float)$totals['income']['this']===12.5 && (float)$totals['income']['last']===5.25 && (int)$totals['votes']['this']===10 && (int)$totals['votes']['last']===4, 'Previous monthly income and votes use February at March31');
        $model->getTotals($march);
        checkDashboard(count(\CodeIgniter\Events\Events::$queries)===4, 'Repeated totals reuse the same request snapshot');
        $daily=graphTotals($model->getGraph(true,[0,1],$march));
        checkDashboard($daily===[0=>4,1=>2], 'Daily graphs include leap-day February and exclude future records');
        $year=graphTotals($model->getGraph(false,[0,1],$march));
        checkDashboard($year===[0=>6,1=>1], 'Year graphs share the visitor-day metric and calendar year bounds');
        $april=$model->getTotals(new DateTimeImmutable('2024-04-01'));
        checkDashboard($april['signups']===['today'=>99,'month'=>99,'this'=>99,'last'=>12], 'The first day of a month starts a new current-month total');
        $january=Dashboard_model::periods(new DateTimeImmutable('2025-01-01'));
        checkDashboard($january['previous_month']==='2024-12-01' && $january['last_key']==='2024-12', 'January comparisons cross the year boundary correctly');
        $leap=Dashboard_model::periods(new DateTimeImmutable('2024-02-29'));
        checkDashboard($leap['month']==='2024-02-01' && $leap['previous_month']==='2024-01-01' && $leap['tomorrow']==='2024-03-01', 'Leap-day ranges use half-open calendar bounds');
        \CodeIgniter\Events\Events::$queries=[];
        checkDashboard($model->getGraph(true,[-1,11,'bad',[]],$march)===[] && !\CodeIgniter\Events\Events::$queries, 'Malformed graph periods never trigger an unbounded history read');
        $empty=$model->getTotals(new DateTimeImmutable('2027-01-01'));
        checkDashboard($empty['signups']['month']===0 && $empty['unique']['month']===0 && (float)$empty['income']['this']===0.0, 'Empty reporting periods return usable zero totals');
        $model=new Dashboard_model();$model->db=$db;
        \CodeIgniter\Events\Events::$queries=[];
        $model->getUnique('today');$model->getUnique('month');$model->getViews('today');$model->getViews('month');
        $model->getIncome('this');$model->getVotes('last');$model->getSignupsDaily('month');$model->getSignupsMonthly('last');
        checkDashboard(count(\CodeIgniter\Events\Events::$queries)===4, 'Legacy metric getters share one aggregate snapshot');
    }
    exerciseDashboard(new CodeIgniter\Database\SQLite3\Connection(['database'=>':memory:','DBDebug'=>true]));
    if (!getenv('FUSION_TEST_MYSQL_HOST')) exit;
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'),['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Only a disposable loopback database is allowed');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $schema='fusion_dashboard_'.bin2hex(random_bytes(6));$nonce=bin2hex(random_bytes(16));
    $admin=new mysqli(getenv('FUSION_TEST_MYSQL_HOST'),'root',getenv('FUSION_TEST_MYSQL_PASSWORD'),'',(int)(getenv('FUSION_TEST_MYSQL_PORT')?:3306));
    $admin->query("CREATE DATABASE `$schema`");$admin->select_db($schema);$admin->query('CREATE TABLE _dashboard_owner (nonce CHAR(32))');$admin->query("INSERT INTO _dashboard_owner VALUES ('$nonce')");
    try { exerciseDashboard(new CodeIgniter\Database\MySQLi\Connection(['hostname'=>getenv('FUSION_TEST_MYSQL_HOST'),'username'=>'root','password'=>getenv('FUSION_TEST_MYSQL_PASSWORD'),'port'=>(int)(getenv('FUSION_TEST_MYSQL_PORT')?:3306),'database'=>$schema,'DBDebug'=>true,'charset'=>'utf8mb4'])); }
    finally { if (!preg_match('/^fusion_dashboard_[a-f0-9]{12}$/D',$schema) || !hash_equals($nonce,$admin->query('SELECT nonce FROM _dashboard_owner')->fetch_assoc()['nonce'])) throw new RuntimeException('Refusing unrelated database cleanup');$admin->query("DROP DATABASE `$schema`"); }
}
