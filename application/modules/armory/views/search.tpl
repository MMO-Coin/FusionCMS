<div class="page-subbody mt-0">
    <form id="armory-search" onSubmit="Search.show_data();return false;">
        <div class="input-group">
            <label class="visually-hidden" for="search_field">{lang("search_placeholder", "armory")}</label>
            <input minlength="3" maxlength="100" required class="col-xs-12 col-sm-12 col-md-12 col-lg-3 form-control mx-1" type="text" id="search_field" name="search_field" placeholder="{lang("search_placeholder", "armory")}">
        
            <label class="visually-hidden" for="realm">{lang("realm", "armory")}</label>
            <select class="col-xs-12 col-sm-12 col-md-12 col-lg-3 mx-1" id="realm" name="realm" onchange="Search.toggle();return false;">
                <option value="0" disabled>{lang("realm", "armory")}</option>
                {foreach from=$realms item=realm}
                    <option {if $realm@first}selected{/if} value="{$realm->getId()}">{$realm->getName()}</option>
                {/foreach}
            </select>
        
            <label class="visually-hidden" for="table">{lang("search_type", "armory")}</label>
            <select class="col-xs-12 col-sm-12 col-md-12 col-lg-3 mx-1" id="table" name="table" onchange="Search.toggle();return false;">
                <option value="items">{lang("items", "armory")}</option>
                <option value="guilds">{lang("guilds", "armory")}</option>
                <option value="characters">{lang("characters", "armory")}</option>
            </select>
        
            <button class="nice_button mx-1" type="submit">{lang("search_button", "armory")}</button>
        </div>
    </form>
    <p id="armory-error" class="d-none" role="alert"></p>
</div>

<div class="page-subbody mt-4 p-4 mt-0 table-responsive" id="search_box">
	<table class="nice_table" id="search_results_items">
        <thead>
            <tr>
				<th>{lang("name", "armory")}</th>
				<th>{lang("level", "armory")}</th>
				<th>{lang("required", "armory")}</th>
				<th>{lang("type", "armory")}</th>
			</tr>
        </thead>
        <tbody></tbody>
    </table>
    <table class="nice_table" id="search_results_characters">
        <thead>
            <tr>
				<th style="width:10px"></th>
				<th>{lang("name", "armory")}</th>
				<th>{lang("faction", "armory")}</th>
				<th>{lang("level", "armory")}</th>
				<th></th>
			</tr>
        </thead>
        <tbody></tbody>
    </table>
    <table class="nice_table" id="search_results_guilds">
        <thead>
            <tr>
				<th>{lang("name", "armory")}</th>
				<th>{lang("members", "armory")}</th>
				<th>{lang("owner", "armory")}</th>
			</tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
