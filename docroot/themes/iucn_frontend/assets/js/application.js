(function ($) {
  $('select').select2({
    placeholder: function () {
      $(this).data('placeholder');
    }
  });

  $('input[type="checkbox"]', '#iucn-search-form').bootstrapSwitch({
    labelWidth: 9,
    offText: Drupal.t('or'),
    onText: Drupal.t('and'),
    size: 'mini'
  });

  $('.search-facets').removeClass('invisible');

  function submitSearchForm() {
    $('#iucn-search-form').submit();
  }

  $('select', '#iucn-search-form').change(submitSearchForm);
  $('input[type="checkbox"]', '#iucn-search-form').on('switchChange.bootstrapSwitch', submitSearchForm);
})(jQuery);