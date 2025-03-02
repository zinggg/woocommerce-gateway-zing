var wpwlOptions = {
  style: 'plain',
  showCVVHint: true,
  brandDetection: true,
  showPlaceholders: true,
  showLabels: false,
  autofocus: 'card.number',
  locale: object_name.lang_code,

  // when form ready
  onReady: function () {
    //  move cvv before cardholder field
    $('.wpwl-group-cvv').after($('.wpwl-group-cardHolder').detach());

    // add bottom
    // var BannerHtml = '<div id="banner"><div id="d1"><img border="0" src="/wp-content/plugins/woocommerce-gateway-cardcorp/assets/images/general/3dmcsc.svg" alt="MasterCard SecureCode"></div><div id="d2"><img border="0" src="/wp-content/plugins/woocommerce-gateway-cardcorp/assets/images/general/3dvbv.svg" alt="VerifiedByVISA"></div><div id="d3"><img border="0" src="/wp-content/plugins/woocommerce-gateway-cardcorp/assets/images/general/3dasb.svg" alt="Secure Payment"></div></div>';

    // $("#cardcorp_payment_container").append(BannerHtml);

    // card-brands list & change effect

    // $('form.wpwl-forms').find('.wpwl-control-cardNumber').after($('.wpwl-brand-card'));
    $('.wpwl-group-cardNumber').after($('.wpwl-group-cardHolder').detach()); //pomeranje polja cardnumber pre polja broj cardholder

    var visa = $('.wpwl-brand:first')
      .clone()
      .removeAttr('class')
      .attr('class', 'wpwl-brand-card wpwl-brand-custom wpwl-brand-VISA');
    var master = $(visa)
      .clone()
      .removeClass('wpwl-brand-VISA')
      .addClass('wpwl-brand-MASTER');
    var maestro = $(visa)
      .clone()
      .removeClass('wpwl-brand-VISA')
      .addClass('wpwl-brand-MAESTRO');
    var amex = $(visa)
      .clone()
      .removeClass('wpwl-brand-VISA')
      .addClass('wpwl-brand-AMEX');
    var diners = $(visa)
      .clone()
      .removeClass('wpwl-brand-VISA')
      .addClass('wpwl-brand-DINERS');
    var jcb = $(visa)
      .clone()
      .removeClass('wpwl-brand-VISA')
      .addClass('wpwl-brand-JCB');
    $('.wpwl-brand:first').after($(visa)).after($(master)).after($(maestro));

    // var cardbrands = object_name.card_brands.toLowerCase();
    // var cardbrandsArray = cardbrands.split(' ');
    // $(".wpwl-brand:first").cardbrandsArray.forEach(function(element) {
    // $("#result").append("after($(" + element + '))');
    // });

    // cancel button
  },

  onChangeBrand: function (e) {
    $('.wpwl-brand-custom').css('opacity', '0.2');
    $('.wpwl-brand-' + e).css('opacity', '5');
  },
};
