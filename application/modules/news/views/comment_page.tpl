<div class="comment-page">
    {$comments_html}
    {if $next_cursor}
        <button type="button" class="nice_button comment-load-more" data-before="{$next_cursor}" onclick="Ajax.loadMoreComments({$id}, this)">{lang('comments_more', 'news')}</button>
    {/if}
</div>
