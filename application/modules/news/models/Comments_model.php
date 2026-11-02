<?php

require_once dirname(__DIR__, 3) . '/libraries/PageBounds.php';

class Comments_model extends CI_Model
{
    /**
     * Get comments
     *
     * @param  Int $id
     * @return Array
     */
    public function getComments($id, $before = 0, $limit = 25)
    {
        return $this->getCommentPage($id, $before, $limit)['comments'];
    }

    public function getCommentPage($id, $before = 0, $limit = 25): array
    {
        $bounds = PageBounds::bounds($before, $limit);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$bounds || $id === false) return ['comments' => [], 'next_cursor' => 0];
        $builder = $this->db->table('comments')->where('article_id', $id)->where('content !=', '');
        if ($bounds[0]) $builder->where('id <', $bounds[0]);
        $rows = $builder->orderBy('id', 'DESC')->limit($bounds[1] + 1)->get()->getResultArray();
        $more = count($rows) > $bounds[1];
        $rows = array_slice($rows, 0, $bounds[1]);
        $next = $more ? (int) end($rows)['id'] : 0;

        if ($rows) {
            $ids = array_values(array_unique(array_column($rows, 'author_id')));
            $profiles = $this->db->table('account_data a')->select('a.id, a.nickname, v.avatar')
                ->join('avatars v', 'v.id = a.avatar', 'left')->whereIn('a.id', $ids)->get()->getResultArray();
            $profiles = array_column($profiles, null, 'id');
            $missing = array_values(array_filter($ids, static fn($id) => empty($profiles[$id]['nickname'])));
            $names = [];
            if ($missing) {
                $accounts = $this->external_account_model->getConnection()->table(table('account'))
                    ->select(columns('account', ['id', 'username']))->whereIn(column('account', 'id'), $missing)
                    ->get()->getResultArray();
                $names = array_column($accounts, 'username', 'id');
            }
            foreach ($rows as &$row) {
                $profile = $profiles[$row['author_id']] ?? [];
                $row['author'] = ($profile['nickname'] ?? '') ?: ($names[$row['author_id']] ?? lang('system', 'news'));
                $row['avatar'] = base_url() . 'application/images/avatar/' . ($profile['avatar'] ?? '');
                $row['content'] = $this->template->format($row['content']);
            }
        }
        return ['comments' => $rows, 'next_cursor' => $next];
    }

    /**
     * Get last comment by the user
     *
     * @param  Int $id
     * @return Array
     */
    public function getLastComment($id)
    {
        $query = $this->db->table('comments')->select()->where('article_id', $id)->where('author_id', $this->user->getId())->orderBy('id', 'desc')->limit(1)->get();

        if ($query->getNumRows() > 0) {
            $result = $query->getResultArray();

            return $result[0];
        } else {
            return [];
        }
    }

    /**
     * Submit a comment
     *
     * @param Array $comment
     */
    public function addComment($comment)
    {
        $this->db->table('comments')->insert($comment);

        $this->db->query("UPDATE articles SET comments = comments + 1 WHERE id=?", array($comment['article_id']));
    }

    public function deleteComment($id)
    {
        $query = $this->db->query("SELECT article_id FROM comments WHERE id=?", array($id));

        if ($query->getNumRows() > 0) {
            $row = $query->getResultArray();
        } else {
            die("Comment doesn't exist. Yay");
        }

        $this->db->transStart();
        $this->db->query("DELETE FROM comments WHERE id=?", array($id));
        $this->db->query("UPDATE articles SET comments = comments - 1 WHERE id=?", array($row[0]['article_id']));
        $this->db->transComplete();

        return $row[0]['article_id'];
    }
}
