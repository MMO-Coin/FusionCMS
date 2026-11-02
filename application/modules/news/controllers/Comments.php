<?php

use CodeIgniter\Events\Events;
use MX\MX_Controller;

/**
 * Comments News Controller Class
 * @property news_model $news_model news_model Class
 * @property comments_model $comments_model comments_model Class
 */
class Comments extends MX_Controller
{
    public function __construct()
    {
        //Call the constructor of MX_Controller
        parent::__construct();

        $this->load->model('comments_model');
        $this->load->model('news_model');

        $this->load->config('news');
    }

    /**
     * Load the comments of one article
     *
     * @param Int $id
     */
    public function get($id)
    {
        requirePermission("canViewComments");
        $comments_html = $this->renderPage($id, 0);

        $values = array(
                    "form" => ($this->user->isOnline()) ? "onSubmit='Ajax.submitComment(" . $id . ");return false'" : "onSubmit='UI.alert(\"Please log in to comment!\");return false'",
                    "online" => $this->user->isOnline(),
                    "field_id" => "id='comment_field_" . $id . "'",
                    "comments" => $comments_html,
                    "comments_id" => "id='comments_area_" . $id . "'",
                    "id" => $id,
                );

        $output = $this->template->loadPage("article_comments.tpl", $values);

        die($output);
    }

    public function page($id)
    {
        requirePermission('canViewComments');
        die($this->renderPage($id, $this->input->get('before')));
    }

    private function renderPage($id, $before): string
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $before = filter_var($before, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false || $before === false || !$this->news_model->articleExists($id, true)) {
            http_response_code(400);
            return '';
        }
        $key = 'comments_' . $id . '_v2_' . $before . '_' . getLang();
        $data = $this->cache->get($key);
        if ($data === false) {
            $data = $this->comments_model->getCommentPage($id, $before);
            $this->cache->save($key, $data, 60);
        }
        $data += [
            'id' => $id, 'url' => $this->template->page_url,
            'user_is_gm' => hasPermission('canRemoveComment'),
        ];
        $data['comments_html'] = $this->template->loadPage('comments.tpl', $data);
        return $this->template->loadPage('comment_page.tpl', $data);
    }

    /**
     * Submit a comment to an article
     *
     * @param Int $id
     */
    public function add($id = false)
    {
        requirePermission("canAddComment");

        if (!$id) {
            die();
        }

        // Check if article exist and if you can comment it
        if ($this->news_model->articleExists($id, true) && $this->user->isOnline()) {
            $message = $this->input->post('content');

            if (is_string($message) && mb_strlen(trim($message)) > 0 && mb_strlen($message) <= 255) {
                // Format the comment
                $comment = array(
                    "timestamp" => time(),
                    "article_id" => $id,
                    "author_id" => $this->user->getId(),
                    "content" => $message,
                    "is_gm" => (hasPermission('postCommentAsStaff')) ? 1 : 0
                );

                $this->comments_model->addComment($comment);

                // Add log
                $this->dblogger->createLog("user", "add", "Added comment", ['ID' => $id]);

                Events::trigger('onAddComment', $id, $message);

                // Get last comment
                $comment_arr = $this->comments_model->getLastComment($id);

                // Add values
                $comment_arr['profile'] = $this->template->page_url . "profile/" . $comment_arr['author_id'];
                $comment_arr['avatar'] = $this->user->getAvatar($comment_arr['author_id'], "small");
                $comment_arr['author'] = $this->user->getNickname($comment_arr['author_id']);
                $comment_arr['content'] = $this->template->format($message);
                $comment_arr['url'] = $this->template->page_url;
                $comment_arr['is_gm'] = $comment['is_gm'];

                // Clear cache
                $this->cache->delete('news_*.cache');
                $this->cache->delete('comments_' . $id . '_*.cache');

                // Load the comment template, also check if we are a staff member
                $data = array(
                    'comments' => array($comment_arr),
                    'user_is_gm' => hasPermission('postCommentAsStaff'),
                    'url' => $this->template->page_url
                );

                die($this->template->loadPage("comments.tpl", $data));
            }
        }
        http_response_code(422);
        echo lang('comment_submit_error', 'news');
    }

    /**
     * Delete the comment with the given id.
     *
     * @param bool $id
     */
    public function delete($id = false)
    {
        requirePermission("canRemoveComment");

        if (!$id) {
            die();
        }

        $articleId = $this->comments_model->deleteComment($id);
        $this->cache->delete('news_*.cache');
        $this->cache->delete('comments_' . $articleId . '_*.cache');

        // Add log
		$this->dblogger->createLog("admin", "delete", "Deleted comment", ['ID' => $id]);

        Events::trigger('onDeleteComment', $id, $articleId);

        die('Success');
    }
}
