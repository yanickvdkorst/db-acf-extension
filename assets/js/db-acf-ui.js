(function($) {

  const ACFDB = {

    modals: [],

    init() {
      ACFDB.removeSinglePreviewModal();
      ACFDB.addPreviewModalLinkMarkup();

      acf.addAction('load_field/type=flexible_content', function(field) {
        field.$el.find('.acf-flexible-content:first > .values > .layout:not(.fc-modal)').each(function() {
          ACFDB.addModal($(this));
        });
      });

      acf.addAction('after_duplicate', function($clone, $el) {
        if ($el.is('.layout')) ACFDB.addModal($el);
      });

      acf.addAction('append', function($el) {
        if ($el.is('.layout')) ACFDB.addModal($el);
      });

      acf.addAction('invalid_field', function(field) {
        ACFDB.invalidField(field.$el);
      });

      acf.addAction('valid_field', function(field) {
        ACFDB.validField(field.$el);
      });

      $(document).keyup(function(e) {
        if (e.keyCode === 27 && $('body').hasClass('acf-modal-open')) {
          ACFDB.close();
        }
      });
    },

    removeSinglePreviewModal() {
      const flexibleContentField = acf.getField('acf-field-flexible-content');
      flexibleContentField._open = function() {
        const $popup = $(this.$el.children('.tmpl-popup').html());
        if ($popup.find('a').length === 1) {
          flexibleContentField.add($popup.find('a').attr('data-layout'));
          return false;
        }
        return flexibleContentField.apply(this, arguments);
      };
    },

    addPreviewModalLinkMarkup() {
      $('body').on('click', 'a[data-name="add-layout"]', function() {
        $('.acf-fc-popup a').each(function() {
          const html = '<div class="acf-fc-popup-label">' + $(this).html() + '</div><div class="acf-fc-popup-image"></div>';
          $(this).html(html);
        });
      });
    },

    addModal($layout) {
      $layout.addClass('fc-modal').removeClass('-collapsed');

      const $header = $layout.find('> .acf-fc-layout-actions-wrap');
      if (!$header.length) return;

      let $controls = $header.find('> .acf-fc-layout-controls');
      if (!$controls.length) {
        $controls = $('<div class="acf-fc-layout-controls"></div>').appendTo($header);
      }

      // remove any old pencil duplicates
      $controls.find('a[data-event="edit-layout"]').remove();

      // add pencil-alt
      const $edit = $(
        '<a class="acf-js-tooltip" href="#" data-event="edit-layout" title="Bewerk layout">' +
          '<span class="acf-icon -pencil-alt"></span>' +
        '</a>'
      );
      $edit.on('click.acfdb', function(e) {
        e.preventDefault();
        e.stopPropagation();
        ACFDB.open.call(this);
      });
      $controls.prepend($edit);

// --- Voeg knop toe om layout te toggle ---
const $toggleBtn = $(
    '<a href="#" class="acf-layout-toggle-btn acf-js-tooltip" title="Toggle layout" data-name="toggle-layout">' + 
      '<span class="acf-icon -toggle-alt"></span>'
    + '</a>'
);
$edit.after($toggleBtn);

$toggleBtn.on('click', function(e){
    e.preventDefault();
    
    const $btn = $(this);
    const $icon = $btn.find('span');
    const $layout = $btn.closest('.layout');
    const fcField = acf.getInstance($layout.closest('.acf-field-flexible-content'));
    if (!fcField) return;

    // ACF toggle
    fcField.onClickToggleLayout(null, $layout);

    // icon class toggle
    $icon.toggleClass('disabled');
});
      // header click opent modal, behalve als pencil wordt geklikt
      $header.off('click.acfdb').on('click.acfdb', function(e) {
        if ($(e.target).closest('.acf-fc-layout-controls').length) return;
        ACFDB.open.call(this);
      });

      // fields container
      if ($layout.find('> .acf-fields, > .acf-table').length === 0) {
        $layout.append('<div class="acf-fields"></div>');
      }
      $layout.find('> .acf-fields, > .acf-table').wrapAll('<div class="acf-fc-modal-content" />');

      // dubbelklik header opent modal
      $layout.find('> .acf-fc-layout-handle').off('dblclick').on('dblclick', ACFDB.open);
    },

    open() {
      const $layout = $(this).closest('.layout');
      const caption = $layout.find('.acf-fc-layout-title strong').first().text();

      const closeBtn = $('<a class="dashicons dashicons-no -cancel" />').on('click', ACFDB.close);
      $layout.find('> .acf-fc-modal-title').html(caption).append(closeBtn);

      $layout.addClass('-modal');
      ACFDB.modals.push($layout);

      // disable dragging while modal is open
      $layout.closest('.acf-flexible-content').find('.acf-fc-layout-handle').css('pointer-events', 'none');

      ACFDB.overlay(true);
    },

    close() {
      const $layout = ACFDB.modals.pop();
      if (!$layout) return;

      const fc = $layout.parents('.acf-field-flexible-content:first');
      const field = acf.getInstance(fc);
      if (field && field.closeLayout) field.closeLayout(field.$layout($layout.index()));

      $layout.find('> .acf-fc-modal-title').html('');
      $layout.removeClass('-modal').css('visibility', '');
      $layout.addClass('-highlight-closed');

      // re-enable dragging
      $layout.closest('.acf-flexible-content').find('.acf-fc-layout-handle').css('pointer-events', '');

      setTimeout(() => $layout.removeClass('-highlight-closed'), 750);
      ACFDB.overlay(false);
    },

    overlay(show) {
      if (show && !$('body').hasClass('acf-modal-open')) {
        $('<div id="acf-flexible-content-modal-overlay" />').on('click', ACFDB.close).appendTo('body');
        $('body').addClass('acf-modal-open');
      } else if (!show && ACFDB.modals.length === 0) {
        $('#acf-flexible-content-modal-overlay').remove();
        $('body').removeClass('acf-modal-open');
      }
      ACFDB.refresh();
    },

    refresh() {
      $.each(ACFDB.modals, function() {
        $(this).css('visibility', 'hidden').removeClass('-animate');
      });
      const index = ACFDB.modals.length - 1;
      if (index in ACFDB.modals) {
        ACFDB.modals[index].css('visibility', 'visible').addClass('-animate');
      }
    },

    invalidField($el) {
      $el.parents('.layout').addClass('layout-error-messages');
    },

    validField($el) {
      $el.parents('.layout').each(function() {
        const $layout = $(this);
        if (!$layout.find('.acf-error').length) $layout.removeClass('layout-error-messages');
      });
    }

  };

  $(function() {
    ACFDB.init();
  });

})(jQuery);