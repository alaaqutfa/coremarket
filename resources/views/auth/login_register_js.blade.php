<script src="{{ static_asset('assets/js/vendors.js') }}"></script>
<script>
    (function($) {
        // USE STRICT
        "use strict";

        AIZ.data = {
            csrf: $('meta[name="csrf-token"]').attr("content"),
            appUrl: $('meta[name="app-url"]').attr("content"),
            fileBaseUrl: $('meta[name="file-base-url"]').attr("content"),
        };
        AIZ.plugins = {
            notify: function(type = "dark", message = "") {
                $.notify({
                    // options
                    message: message,
                }, {
                    // settings
                    showProgressbar: true,
                    delay: 2500,
                    mouse_over: "pause",
                    placement: {
                        from: "bottom",
                        align: "left",
                    },
                    animate: {
                        enter: "animated fadeInUp",
                        exit: "animated fadeOutDown",
                    },
                    type: type,
                    template: '<div data-notify="container" class="aiz-notify alert alert-{0}" role="alert">' +
                        '<button type="button" aria-hidden="true" data-notify="dismiss" class="close"><i class="las la-times"></i></button>' +
                        '<span data-notify="message">{2}</span>' +
                        '<div class="progress" data-notify="progressbar">' +
                        '<div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%;"></div>' +
                        "</div>" +
                        "</div>",
                });
            }
        };

    })(jQuery);
</script>
<script>
    @foreach (session('flash_notification', collect())->toArray() as $message)
        AIZ.plugins.notify('{{ $message['level'] }}', '{{ $message['message'] }}');
    @endforeach

    $('.password-toggle').click(function() {
        var $this = $(this);
        if ($this.siblings('input').attr('type') == 'password') {
            $this.siblings('input').attr('type', 'text');
            $this.removeClass('la-eye').addClass('la-eye-slash');
        } else {
            $this.siblings('input').attr('type', 'password');
            $this.removeClass('la-eye-slash').addClass('la-eye');
        }
    });
</script>

<script type="text/javascript">
    (function () {
        var input = document.querySelector('#phone-code');
        if (!input || typeof intlTelInput === 'undefined') {
            return;
        }

        var initialCountry = @json(strtolower((string) get_setting('authentication_default_phone_country', 'lb')));
        var previousCountryCode = @json((string) old('country_code'));
        if (previousCountryCode && window.intlTelInputGlobals) {
            var previousCountry = window.intlTelInputGlobals.getCountryData()
                .find(function (country) { return country.dialCode === previousCountryCode; });
            initialCountry = previousCountry ? previousCountry.iso2 : initialCountry;
        }
        var iti = intlTelInput(input, {
            initialCountry: initialCountry,
            separateDialCode: true,
            utilsScript: "{{ static_asset('assets/js/intlTelutils.js') }}?1590403638580"
        });

        function syncCountryCode() {
            var country = iti.getSelectedCountryData();
            $('input[name=country_code]').val(country.dialCode || '');
        }

        syncCountryCode();
        input.addEventListener('countrychange', syncCountryCode);

        window.toggleEmailPhone = function (button) {
            var phoneGroup = $('.phone-form-group');
            var emailGroup = $('.email-form-group');

            if (phoneGroup.hasClass('d-none')) {
                phoneGroup.removeClass('d-none');
                emailGroup.addClass('d-none');
                $('input[name=email]').val('');
                $(button).html('<i>*{{ translate('Use Email Instead') }}</i>');
            } else {
                phoneGroup.addClass('d-none');
                emailGroup.removeClass('d-none');
                $('input[name=phone]').val('');
                $(button).html('<i>*{{ translate('Use Phone Number Instead') }}</i>');
            }
        };
    })();
</script>
