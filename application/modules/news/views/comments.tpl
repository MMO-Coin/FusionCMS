<span class="comments">
	{foreach from=$comments item=comment}
		<div class="row {if $comment.is_gm}staff_comment{/if} comment mb-3" id="{$comment.id}">
			<div class="col-md-12 col-lg-4">
				<img class="avatar rounded-circle shadow-lg" alt="" loading="lazy" src="{$comment.avatar|escape}">
			</div>
			<div class="col-md-12 col-lg-8">
				<div class="Comment-head">
					<div class="Comment-title text-ellipsis">
							<strong><a href="{$url}profile/{$comment.author_id}">{if $comment.is_gm}<img src="{$url}application/images/icons/icon_blizzard.gif" align="absmiddle" style="border:none !important;margin-right:0px !important;box-shadow:none !important;-webkit-box-shadow:none !important;-moz-box-shadow:none !important;"/>&nbsp;{/if} {$comment.author|escape}</a></strong>
						{lang('on', 'news')} <i class="fa-duotone fa-clock"></i> {date("F j, Y", $comment.timestamp)}
						<span class="float-end">
							<span>
							{if hasPermission("canRemoveComment")}
								<button type="button" class="comment-delete" aria-label="{lang('delete', 'news')|escape}" onClick="Ajax.remove(this, {$comment.id})"><i class="fa-duotone fa-trash-alt" aria-hidden="true"></i></button>
							{/if}
							</span>
						</span>
					</div>
					<div class="Comment-metadata">
						
					</div>
				</div>
		
				<div class="divider"></div>
				
				<p>{$comment.content}</p>
				
			</div>
		</div>
	{/foreach}
</span>
