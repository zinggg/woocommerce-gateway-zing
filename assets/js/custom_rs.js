﻿var wpwlOptions = {
  style: 'plain',
  showCVVHint: true,
  brandDetection: true,
  showPlaceholders: true,
  showLabels: false,
  autofocus: 'card.number',
  locale: object_name.lang_code,
  labels: {
    cardHolder: 'Card user',
    cvv: 'CVV',
    brand: ' ',
    expiryDate: 'Expiry Date',
    cardNumber: 'Card Number',
    cvvHint: 'Three numbers on the back of your card.',
    cvvHintAmex: 'Four numbers on the back of your card.',
  },
  errorMessages: {
    cardHolderError: "__('CHECKOUT_TITLE_DESC', 'wc-asbecommerce')",
    cardNumberError: 'Wrong card number',
    cvvError: 'Wrong CVV',
    expiryMonthError: 'Incorrect Expiry Month',
    expiryYearError: 'Incorrect Expiry Year',
  },

  // when form ready
  onReady: function () {
    ///  rename pay button
    $('.wpwl-button-pay').html('Confirm');
    ///  fadeout loader screen
    $('.load-container').delay(1000).fadeOut(1000);
    ///  move cvv before cardholder field
    $('.wpwl-group-cvv').after($('.wpwl-group-cardHolder').detach());

    /// add allsecure logo to top
    var LogoHtml =
      '<div id="r0"><img border="0" src= "' +
      object_name.plugin_url +
      '/woocommerce-gateway-cardcorp/assets/images/style001/allsecure.png" alt="AllSecure" ></div>';
    $('form.wpwl-form-card').find('.wpwl-wrapper-brand').before(LogoHtml);

    // /// add bottom
    // var BannerHtml =
    //     '<div id="banner"><div id="r1"><img border="0" src="' +
    //     object_name.plugin_url +
    //     '/woocommerce-gateway-cardcorp/assets/images/style001/msc.png" alt="MasterCard SecureCode"></div><div id="r2"><img border="0" src="' +
    //     object_name.plugin_url +
    //     '/woocommerce-gateway-cardcorp/assets/images/style001/vbv.png" alt="VerifiedByVISA"></div><div id="r3"><img border="0" src="' +
    //     object_name.plugin_url +
    //     '/woocommerce-gateway-cardcorp/assets/images/style001/secure-payment.png" alt="Secure Payment"></div></div>';

    // $("#cardcorp_payment_container").append(BannerHtml);

    /// card-brands list & change effect
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
    $('.wpwl-brand:first').after($(visa)).after($(master)).after($(maestro));
  },

  onChangeBrand: function (e) {
    $('.wpwl-brand-custom').css('opacity', '0.3');
    $('.wpwl-brand-' + e).css('opacity', '1');
  },
  // change border of pci environment when focus blure
  onFocusIframeCommunication: function () {
    this.$iframe.css({ borderColor: '#1abc9c', outline: 0 });
  },
  onBlurIframeCommunication: function () {
    this.$iframe.css({ borderColor: '', outline: '' });
  },

  // after button clicked
  ///  rename pay button again
  onAfterSubmit: function () {
    $('.wpwl-button-pay').html('Procesiram...');
  },
};
