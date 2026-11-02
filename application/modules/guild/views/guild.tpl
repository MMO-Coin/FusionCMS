<div class="row">
	{if !$guild}
		<div class="text-center h1 my-5">{lang("doesnt_exist", "guild")}</div>
	{else}
		<div class="col-lg-4 py-5 pe-lg-5">
			<div class="section-header d-flex justify-content-between">
				{$guild.guildName|escape}
			</div>
			<div class="setion-body">
				<div class="mb-4 motd">
					<div class="motd">
						{if $guildMotd}
							"{nl2br(trim($guildMotd))}"
						{else}
							{lang("no_motd", "guild")}
						{/if}
					</div>
					<div class="mt-4">
						<span class="fw-bold">{$member_count}</span> {lang("members", "guild")}, {$realmName|escape}
					</div>
				</div>
			</div>
		</div>
		
		<div class="col-lg-8 py-lg-5 pb-5 pb-lg-0">
			<div class="section-header">{lang("members", "guild")}</div>
			<div class="section-body">
				<div class="table-responsive"><table class="table text-nowrap nice_table">
					<thead>
						<tr>
							<th>{lang("members", "guild")}</th>
							<th>{lang("race", "guild")}</th>
							<th>{lang("class", "guild")}</th>
							<th>{lang("faction", "guild")}</th>
							<th>{lang("level", "guild")}</th>
							<th>{lang("rank", "guild")}</th>
						</tr>
					</thead>
                    <tbody>
                        {foreach from=$members item=character}
                        <tr data-member="{$character.guid}">
                            <td><a href="{$url}character/{$realmId}/{$character.guid}">{$character.name|escape}</a>{if $character.guid == $guild.leaderguid} <i class="fa-duotone fa-crown" aria-label="{lang('leader', 'guild')}"></i>{/if}</td>
                            <td><img src="{$url}application/images/stats/{$character.raceId}-{$character.gender}.gif" width="20" alt="{$character.raceId}"></td>
                            <td><img src="{$url}application/images/stats/{$character.classId}.gif" width="20" alt="{$character.classId}"></td>
                            <td><img src="{$url}application/images/factions/{$character.faction}.png" width="20" alt="{$character.faction}"></td>
                            <td>{$character.level|escape}</td><td>{$character.rname|escape}</td>
                        </tr>
                        {foreachelse}<tr><td colspan="6">{lang('empty_roster', 'guild')}</td></tr>{/foreach}
					</tbody>
                </table></div>
                <nav class="d-flex gap-3 my-3" aria-label="{lang('members', 'guild')}">
                    {if $previous}<a rel="prev" href="{$previous|escape}">{lang('roster_previous', 'guild')}</a>{/if}
                    {if $next}<a rel="next" href="{$next|escape}">{lang('roster_next', 'guild')}</a>{/if}
                </nav>
			</div>
		</div>
	{/if}
</div>
