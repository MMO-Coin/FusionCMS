<section class="box big">
    <h2>MMOCoin Donation Packages</h2>
    
    <table class="table">
        <thead>
            <tr>
                <th>MMOCoin Amount</th>
                <th>DP Amount</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            {if $packages}
                {foreach from=$packages item=p}
                    <tr>
                        <td>{$p.price}</td>
                        <td>{$p.points}</td>
                        <td>
                            <a href="javascript:void(0)" onClick="Admin.edit({$p.id}, '{$p.price}', '{$p.points}')" data-hint="Edit"><i class="fa fa-pencil"></i></a>
                            &nbsp;&nbsp;&nbsp;
                            <a href="javascript:void(0)" onClick="Admin.delete({$p.id})" data-hint="Delete"><i class="fa fa-trash"></i></a>
                        </td>
                    </tr>
                {/foreach}
            {else}
                <tr><td colspan="3">No packages have been added yet.</td></tr>
            {/if}
        </tbody>
    </table>
</section>

<section class="box big">
    <h2>Add New Package</h2>
    
    <form onSubmit="Admin.add(this); return false;" class="page_form">
        <label for="price">MMOCoin Amount</label>
        <input type="text" name="price" id="price" required placeholder="e.g. 50"/>
        
        <label for="points">Donation Points</label>
        <input type="text" name="points" id="points" required placeholder="e.g. 50"/>
        
        <input type="submit" value="Add Package" />
    </form>
</section>

<!-- Settings info -->
<section class="box big">
    <h2>Module Configuration</h2>
    <div style="padding: 15px;">
        <p>Merchant Wallet, RPC URL, and Coin Contract Address are configured in <code>application/modules/donate_mmo/config/donate_mmo.php</code></p>
    </div>
</section>
