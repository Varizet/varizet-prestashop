<section class="varizet-return" style="margin:1rem 0">
  <p>{$varizet_message|escape:'html':'UTF-8'}</p>
  {if $varizet_open}
    <p>{$varizet_amount_label|escape:'html':'UTF-8'}: <strong style="font-size:1.25em">{$varizet_amount_html nofilter}</strong> ریال {if $varizet_code}<small dir="ltr">({$varizet_code|escape:'html':'UTF-8'})</small>{/if}</p>
    <p><a class="btn btn-primary" href="{$varizet_payment_url|escape:'html':'UTF-8'}">{$varizet_pay_now|escape:'html':'UTF-8'}</a></p>
  {/if}
  <style>.varizet-return .vz-uniq{ background:#fff3cd;color:#8a5a00;border-radius:4px;padding:0 2px }</style>
</section>
