{if $synchro_product_is_mapped}
    <li>{btn type="list" text=__("synchro.synchronize_product_full") dispatch="dispatch[products.synchro_full]" form="product_update_form"}</li>
    <li>{btn type="list" text=__("synchro.synchronize_product_actualize") dispatch="dispatch[products.synchro_actualize]" form="product_update_form"}</li>
{/if}
