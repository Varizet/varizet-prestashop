{extends file='page.tpl'}
{block name='page_content'}
  <section class="varizet-error">
    <h1 class="h2">{$varizet_title|escape:'html':'UTF-8'}</h1>
    <p class="alert alert-danger">{$varizet_message|escape:'html':'UTF-8'}</p>
    <p>
      {if $varizet_retry_url}<a class="btn btn-primary" href="{$varizet_retry_url|escape:'html':'UTF-8'}">{$varizet_retry|escape:'html':'UTF-8'}</a>{/if}
      <a class="btn btn-secondary" href="{$varizet_orders_url|escape:'html':'UTF-8'}">{$varizet_back|escape:'html':'UTF-8'}</a>
    </p>
  </section>
{/block}
