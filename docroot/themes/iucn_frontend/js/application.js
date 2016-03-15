jQuery('select').select2({
    //@ToDo: Configure placeholders
    placeholder: 'Add...'
});

jQuery('input[type="checkbox"]', '#iucn-search-form').bootstrapSwitch({
    onText: Drupal.t('and'),
    offText: Drupal.t('or')
});

jQuery('select', '#iucn-search-form').change(submitSearchForm);
jQuery('input[type="checkbox"]', '#iucn-search-form').on('switchChange.bootstrapSwitch', submitSearchForm);

function submitSearchForm() {
    jQuery('#iucn-search-form').submit();
}