<?php

namespace CodeIgniter\Events {
    class Events {
        public static array $queries = [];
        public static function trigger($event, ...$arguments) {
            if ($event === 'DBQuery') self::$queries[] = (string) $arguments[0];
        }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('ENVIRONMENT', 'testing');
    define('CI_DEBUG', false);
    function log_message(...$arguments) {}
    function config(...$arguments) { return (object) ['limitZeroAsAll' => true]; }
    function base_url() { return 'https://example.invalid/'; }
    function lang($key, $module) { return $key; }
    function table($name) { return $name; }
    function column($table, $name) { return $name; }
    function columns($table, $names) { return implode(', ', $names); }
    class CI_Model { public $db; public $template; public $external_account_model; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../application/modules/news/models/News_model.php';
    require __DIR__ . '/../application/modules/news/models/Comments_model.php';
    require __DIR__ . '/../application/modules/messages/models/Inbox_model.php';
    require __DIR__ . '/../application/modules/admin/models/Accounts_model.php';

    function checkList(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
        echo "PASS: $message\n";
    }
    function ids($rows): array { return array_map('intval', array_column((array) $rows, 'id')); }
    function exerciseLists($db): void {
        $db->query('CREATE TABLE articles (id INTEGER PRIMARY KEY, timestamp INT)');
        $db->query('CREATE TABLE account (id INTEGER PRIMARY KEY, username VARCHAR(40), email VARCHAR(100), joindate VARCHAR(30), expansion INT)');
        $db->query('CREATE TABLE private_message (id INTEGER PRIMARY KEY, user_id INT, sender_id INT, deleted_user INT, deleted_sender INT, time INT)');
        $db->query('CREATE TABLE comments (id INTEGER PRIMARY KEY, article_id INT, author_id INT, content VARCHAR(255), timestamp INT, is_gm INT)');
        $db->query('CREATE TABLE account_data (id INTEGER PRIMARY KEY, nickname VARCHAR(40), avatar INT)');
        $db->query('CREATE TABLE avatars (id INTEGER PRIMARY KEY, avatar VARCHAR(100))');
        for ($id = 1; $id <= 67; $id++) $db->table('articles')->insert(['id' => $id, 'timestamp' => 1000]);
        for ($id = 1; $id <= 230; $id++) $db->table('account')->insert(['id' => $id, 'username' => sprintf('User%03d', $id), 'email' => 'player@example.invalid', 'joindate' => '2026-11-02', 'expansion' => 0]);
        for ($id = 1; $id <= 120; $id++) $db->table('private_message')->insert(['id' => $id, 'user_id' => 1, 'sender_id' => 2, 'deleted_user' => 0, 'deleted_sender' => 0, 'time' => 1000]);
        for ($id = 1; $id <= 72; $id++) $db->table('comments')->insert(['id' => $id, 'article_id' => 1, 'author_id' => $id % 3 + 1, 'content' => $id <= 2 ? '' : '<b>Comment ' . $id . '</b>', 'timestamp' => 1000, 'is_gm' => 0]);
        for ($id = 1; $id <= 3; $id++) $db->table('account_data')->insert(['id' => $id, 'nickname' => $id === 2 ? '' : 'Nickname' . $id, 'avatar' => 1]);
        $db->table('avatars')->insert(['id' => 1, 'avatar' => 'default.png']);
        $news = new News_model(); $inbox = new inbox_model(); $comments = new Comments_model();
        $accounts = (new ReflectionClass(Accounts_model::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Accounts_model::class, 'connection'))->setValue($accounts, $db);
        foreach ([$news, $inbox, $comments] as $model) $model->db = $db;
        $news->template = $comments->template = new class { public function format($value) { return is_array($value) ? $value : htmlspecialchars($value); } };
        $comments->external_account_model = new class($db) {
            public function __construct(private $connection) {}
            public function getConnection() { return $this->connection; }
        };
        $pages = [];
        foreach ([0, 10, 20] as $offset) {
            $page = ids($news->getArticles($offset, 10));
            checkList($page === range(67 - $offset, 58 - $offset), 'News returns exactly its requested page at offset ' . $offset);
            $pages = array_merge($pages, $page);
        }
        checkList(count(array_unique($pages)) === 30, 'News pages do not overlap with tied timestamps');
        checkList(count($news->getArticles(60, 10)) === 7 && !$news->getArticles(100, 10), 'News last and empty pages are bounded');
        for ($id = 68; $id <= 167; $id++) $db->table('articles')->insert(['id' => $id, 'timestamp' => 1000]);
        checkList(count($news->getArticles(0, 99999)) === 100, 'Oversized news requests use a capped page');
        foreach ([[-1, 10], [[], 10], [0, 0], [0, -1], [0, 'bad']] as [$offset, $limit]) {
            checkList(!$news->getArticles($offset, $limit) && !$inbox->getMessages(1, $offset, $limit)
                && !$accounts->get_users($limit, $offset), 'Malformed list bounds never request all records');
        }
        checkList(ids($inbox->getMessages(1, 10, 10)) === range(110, 101), 'Inbox uses stable time and ID ordering');
        checkList(ids($inbox->getSent(2, 20, 10)) === range(100, 91), 'Sent messages use their requested offset');
        checkList(count($inbox->getMessages(1, 0, 9999)) === 100, 'Inbox pages are capped at 100');
        $db->table('private_message')->where('id', 120)->update(['deleted_user' => 1]);
        checkList((int) $inbox->countMessages(1) === 119 && ids($inbox->getMessages(1, 0, 1)) === [119], 'Deleted inbox entries do not affect visible page counts');
        \CodeIgniter\Events\Events::$queries = [];
        checkList($accounts->count_filtered_users('User0') === 99, 'Account search returns its matching count');
        $query = end(\CodeIgniter\Events\Events::$queries);
        checkList(str_contains(strtoupper($query), 'COUNT(*)') && !str_contains($query, 'SELECT *'), 'Account counts use one aggregate query');
        checkList(ids($accounts->get_users(10, 10, 'User0')) === range(11, 20), 'Account results have deterministic pages');
        checkList(count($accounts->get_users(99999, 0)) === 100, 'Account search pages are capped');
        checkList($accounts->count_filtered_users('%') === 0 && $accounts->count_filtered_users('_') === 0, 'Account search treats wildcard characters literally');
        checkList($accounts->count_filtered_users("' OR 1=1 --") === 0, 'Account search values remain bound parameters');
        \CodeIgniter\Events\Events::$queries = [];
        $first = $comments->getCommentPage(1);
        checkList(ids($first['comments']) === range(72, 48) && $first['next_cursor'] === 48, 'Comment pages return 25 nonempty records and a cursor');
        checkList(count(\CodeIgniter\Events\Events::$queries) === 3, 'Comment profiles and fallback usernames use three queries for the whole page');
        $byAuthor = array_column($first['comments'], 'author', 'author_id');
        checkList($byAuthor[1] === 'Nickname1' && $byAuthor[2] === 'User002', 'Batch profiles preserve nickname and account-name fallback');
        checkList(str_contains($first['comments'][0]['content'], '&lt;b&gt;'), 'Comments still pass through the content formatter');
        $db->table('comments')->insert(['id' => 1000, 'article_id' => 1, 'author_id' => 1, 'content' => 'New comment', 'timestamp' => 1001, 'is_gm' => 0]);
        $second = $comments->getCommentPage(1, $first['next_cursor']);
        checkList(ids($second['comments']) === range(47, 23), 'A concurrent new comment does not move the next cursor page');
        $last = $comments->getCommentPage(1, $second['next_cursor']);
        checkList(ids($last['comments']) === range(22, 3) && $last['next_cursor'] === 0, 'The final comment page has no load-more cursor');
        checkList(!$comments->getComments(1, -1) && !$comments->getComments(1, 0, 0), 'Invalid comment bounds are rejected');
        checkList(PageBounds::clamp(100000, 30, 10) === 20 && PageBounds::clamp(-1, 30, 10) === 0, 'Out-of-range page offsets clamp to a real page');
        checkList(PageBounds::size(99999) === 100 && PageBounds::size(null) === 10, 'Configured page sizes have safe defaults and a maximum');
    }
    exerciseLists(new CodeIgniter\Database\SQLite3\Connection(['database' => ':memory:', 'DBDebug' => true]));
    if (!getenv('FUSION_TEST_MYSQL_HOST')) exit;
    if (!in_array(getenv('FUSION_TEST_MYSQL_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Only a disposable loopback test server is allowed');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $schema = 'fusion_list_' . bin2hex(random_bytes(6)); $nonce = bin2hex(random_bytes(16));
    $admin = new mysqli(getenv('FUSION_TEST_MYSQL_HOST'), 'root', getenv('FUSION_TEST_MYSQL_PASSWORD'), '', (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306));
    $admin->query("CREATE DATABASE `$schema`"); $admin->select_db($schema);
    $admin->query('CREATE TABLE _list_owner (nonce CHAR(32))'); $admin->query("INSERT INTO _list_owner VALUES ('$nonce')");
    try {
        exerciseLists(new CodeIgniter\Database\MySQLi\Connection(['hostname' => getenv('FUSION_TEST_MYSQL_HOST'), 'username' => 'root',
            'password' => getenv('FUSION_TEST_MYSQL_PASSWORD'), 'port' => (int) (getenv('FUSION_TEST_MYSQL_PORT') ?: 3306), 'database' => $schema, 'DBDebug' => true, 'charset' => 'utf8mb4']));
    } finally {
        $owner = $admin->query('SELECT nonce FROM _list_owner')->fetch_assoc();
        if (!preg_match('/^fusion_list_[a-f0-9]{12}$/D', $schema) || !hash_equals($nonce, $owner['nonce'])) throw new RuntimeException('Refusing unexpected schema cleanup');
        $admin->query("DROP DATABASE `$schema`");
    }
}
