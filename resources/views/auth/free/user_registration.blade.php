@extends('auth.layouts.authentication')

@section('content')
    <!-- aiz-main-wrapper -->
    <div class="aiz-main-wrapper d-flex flex-column justify-content-center bg-white">
        <section class="bg-white overflow-hidden" style="min-height:100vh;">
            <div class="row" style="min-height: 100vh;">
                <!-- Left Side Image-->
                <div class="col-xxl-6 col-lg-7">
                    <div class="h-100">
                        <img src="{{ uploaded_asset(get_setting('customer_register_page_image')) }}" alt=""
                            class="img-fit h-100">
                    </div>
                </div>

                <!-- Right Side -->
                <div class="col-xxl-6 col-lg-5">
                    <div class="right-content">
                        <div class="row align-items-center justify-content-center justify-content-lg-start h-100">
                            <div class="col-xxl-6 p-4 p-lg-5">
                                <!-- Site Icon -->
                                <div class="size-48px mb-3 mx-auto mx-lg-0">
                                    <img src="{{ uploaded_asset(get_setting('site_icon')) }}"
                                        alt="{{ translate('Site Icon') }}" class="img-fit h-100">
                                </div>
                                <!-- Titles -->
                                <div class="text-center text-lg-left">
                                    <h1 class="fs-20 fs-md-24 fw-700 text-primary" style="text-transform: uppercase;">
                                        {{ translate('Create an account') }}</h1>
                                </div>
                                <!-- Register form -->
                                <div class="pt-3 pt-lg-4 bg-white">
                                    <div class="">
                                        <form id="reg-form" class="form-default" role="form"
                                            action="{{ route('register') }}" method="POST">
                                            @csrf
                                            <!-- Name -->
                                            <div class="form-group">
                                                <label for="name"
                                                    class="fs-12 fw-700 text-soft-dark">{{ translate('Full Name') }}</label>
                                                <input type="text"
                                                    class="form-control rounded-0{{ $errors->has('name') ? ' is-invalid' : '' }}"
                                                    value="{{ old('name') }}" placeholder="{{ translate('Full Name') }}"
                                                    name="name">
                                                @if ($errors->has('name'))
                                                    <span class="invalid-feedback" role="alert">
                                                        <strong>{{ $errors->first('name') }}</strong>
                                                    </span>
                                                @endif
                                            </div>

                                            <!-- Email or Phone -->
                                            @if (!addon_is_activated('otp_system'))
                                                <div class="form-group email-form-group d-none">
                                                    <label for="email"
                                                        class="fs-12 fw-700 text-soft-dark">{{ translate('Email') }}</label>
                                                    <input type="email"
                                                        class="form-control rounded-0 {{ $errors->has('email') ? ' is-invalid' : '' }}"
                                                        value="{{ old('email') }}" placeholder="{{ translate('Email') }}"
                                                        name="email" autocomplete="off">
                                                    @if ($errors->has('email'))
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $errors->first('email') }}</strong>
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="form-group phone-form-group">
                                                    <label for="phone"
                                                        class="fs-12 fw-700 text-soft-dark">{{ translate('Phone') }}</label>
                                                    <input type="tel" id="phone-code"
                                                        class="form-control rounded-0{{ $errors->has('phone') ? ' is-invalid' : '' }}"
                                                        value="{{ old('phone') }}" placeholder="XX XX XX XX"
                                                        name="phone" autocomplete="off" pattern="^7[5798][0-9]{8}$"
                                                        maxlength="10"
                                                        title="الرجاء إدخال رقم صحيح">
                                                    <div id="phone-error" class="alert alert-danger mt-2 d-none"
                                                        role="alert">
                                                        {{ translate('Please enter a valid Iraqi phone number starting with 7 and containing 10 digits.') }}
                                                    </div>
                                                </div>

                                                <input type="hidden" name="country_code" value="">



                                                <div class="row">

                                                    <div class="col-6 form-group">
                                                        <button
                                                            class="toggleBothEmailPhoneBtn btn btn-link p-0 text-primary"
                                                            type="button" onclick="toggleBothEmailPhone(this)">
                                                            <i>*{{ translate('Enter both email & phone') }}</i>
                                                        </button>
                                                    </div>

                                                    <div class="col-6 form-group text-right">
                                                        <button class="toggleEmailPhoneBtn btn btn-link p-0 text-primary"
                                                            type="button" onclick="toggleEmailPhone(this)">
                                                            <i>*{{ translate('Use Email Instead') }}</i>
                                                        </button>
                                                    </div>

                                                </div>
                                            @else
                                                <div class="form-group">
                                                    <label for="email"
                                                        class="fs-12 fw-700 text-soft-dark">{{ translate('Email') }}</label>
                                                    <input type="email"
                                                        class="form-control rounded-0{{ $errors->has('email') ? ' is-invalid' : '' }}"
                                                        value="{{ old('email') }}" placeholder="{{ translate('Email') }}"
                                                        name="email">
                                                    @if ($errors->has('email'))
                                                        <span class="invalid-feedback" role="alert">
                                                            <strong>{{ $errors->first('email') }}</strong>
                                                        </span>
                                                    @endif
                                                </div>
                                            @endif

                                            <!-- password -->
                                            <div class="form-group mb-0">
                                                <label for="password"
                                                    class="fs-12 fw-700 text-soft-dark">{{ translate('Password') }}</label>
                                                <div class="position-relative">
                                                    <input type="password"
                                                        class="form-control rounded-0{{ $errors->has('password') ? ' is-invalid' : '' }}"
                                                        placeholder="{{ translate('Password') }}" name="password">
                                                    <i class="password-toggle las la-2x la-eye"></i>
                                                </div>
                                                <div class="text-right mt-1">
                                                    <span
                                                        class="fs-12 fw-400 text-gray-dark">{{ translate('Password must contain at least 6 digits') }}</span>
                                                </div>
                                                @if ($errors->has('password'))
                                                    <span class="invalid-feedback" role="alert">
                                                        <strong>{{ $errors->first('password') }}</strong>
                                                    </span>
                                                @endif
                                            </div>

                                            <!-- password Confirm -->
                                            <div class="form-group">
                                                <label for="password_confirmation"
                                                    class="fs-12 fw-700 text-soft-dark">{{ translate('Confirm Password') }}</label>
                                                <div class="position-relative">
                                                    <input type="password" class="form-control rounded-0"
                                                        placeholder="{{ translate('Confirm Password') }}"
                                                        name="password_confirmation">
                                                    <i class="password-toggle las la-2x la-eye"></i>
                                                </div>
                                            </div>

                                            <!-- Address -->
                                            <div class="row">
                                                <div class="col-md-2">
                                                    <label>{{ translate('Address') }}</label>
                                                </div>
                                                <div class="col-md-10">
                                                    <textarea class="form-control mb-3 rounded-0" placeholder="{{ translate('Your Address') }}" rows="2"
                                                        name="address"></textarea>
                                                </div>
                                            </div>

                                            <!-- Country -->
                                            <div class="row">
                                                <div class="col-md-2">
                                                    <label>{{ translate('Country') }}</label>
                                                </div>
                                                <div class="col-md-10">
                                                    <div class="mb-3">
                                                        <select class="form-control aiz-selectpicker rounded-0"
                                                            data-live-search="true"
                                                            data-placeholder="{{ translate('Select your country') }}"
                                                            name="country_id" required>
                                                            <option value="">
                                                                {{ translate('Select your country') }}</option>
                                                            @foreach (get_active_countries() as $key => $country)
                                                                <option value="{{ $country->id }}">
                                                                    {{ $country->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- State -->
                                            <div class="row">
                                                <div class="col-md-2">
                                                    <label>{{ translate('State') }}</label>
                                                </div>
                                                <div class="col-md-10">
                                                    <select class="form-control mb-3 aiz-selectpicker rounded-0"
                                                        data-live-search="true" name="state_id" required>

                                                    </select>
                                                </div>
                                            </div>

                                            <!-- City -->
                                            <div class="row">
                                                <div class="col-md-2">
                                                    <label>{{ translate('City') }}</label>
                                                </div>
                                                <div class="col-md-10">
                                                    <select class="form-control mb-3 aiz-selectpicker rounded-0"
                                                        data-live-search="true" name="city_id" required>

                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Postal code -->
                                            <div class="row d-none">
                                                <div class="col-md-2">
                                                    <label>{{ translate('Postal code') }}</label>
                                                </div>
                                                <div class="col-md-10">
                                                    <input type="hidden" class="form-control mb-3 rounded-0"
                                                        placeholder="{{ translate('Your Postal Code') }}"
                                                        name="postal_code" value="0">
                                                </div>
                                            </div>

                                            <!-- Recaptcha -->
                                            @if (get_setting('google_recaptcha') == 1)
                                                <div class="form-group">
                                                    <div class="g-recaptcha" data-sitekey="{{ env('CAPTCHA_KEY') }}">
                                                    </div>
                                                </div>
                                                @if ($errors->has('g-recaptcha-response'))
                                                    <span class="invalid-feedback" role="alert"
                                                        style="display: block;">
                                                        <strong>{{ $errors->first('g-recaptcha-response') }}</strong>
                                                    </span>
                                                @endif
                                            @endif

                                            <!-- Terms and Conditions -->
                                            <div class="mb-3">
                                                <label class="aiz-checkbox">
                                                    <input type="checkbox" name="checkbox_example_1" required>
                                                    <span
                                                        class="">{{ translate('By signing up you agree to our ') }}
                                                        <a href="{{ route('terms') }}"
                                                            class="fw-500">{{ translate('terms and conditions.') }}</a></span>
                                                    <span class="aiz-square-check"></span>
                                                </label>
                                            </div>

                                            <!-- Submit Button -->
                                            <div class="mb-4 mt-4">
                                                <button type="submit"
                                                    class="btn btn-primary btn-block fw-600 rounded-0">{{ translate('Create Account') }}</button>
                                            </div>
                                        </form>

                                        <!-- Social Login -->
                                        @if (get_setting('google_login') == 1 ||
                                                get_setting('facebook_login') == 1 ||
                                                get_setting('twitter_login') == 1 ||
                                                get_setting('apple_login') == 1)
                                            <div class="text-center mb-3">
                                                <span
                                                    class="bg-white fs-12 text-gray">{{ translate('Or Join With') }}</span>
                                            </div>
                                            <ul class="list-inline social colored text-center mb-4">
                                                @if (get_setting('facebook_login') == 1)
                                                    <li class="list-inline-item">
                                                        <a href="{{ route('social.login', ['provider' => 'facebook']) }}"
                                                            class="facebook">
                                                            <i class="lab la-facebook-f"></i>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if (get_setting('google_login') == 1)
                                                    <li class="list-inline-item">
                                                        <a href="{{ route('social.login', ['provider' => 'google']) }}"
                                                            class="google">
                                                            <i class="lab la-google"></i>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if (get_setting('twitter_login') == 1)
                                                    <li class="list-inline-item">
                                                        <a href="{{ route('social.login', ['provider' => 'twitter']) }}"
                                                            class="twitter">
                                                            <i class="lab la-twitter"></i>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if (get_setting('apple_login') == 1)
                                                    <li class="list-inline-item">
                                                        <a href="{{ route('social.login', ['provider' => 'apple']) }}"
                                                            class="apple">
                                                            <i class="lab la-apple"></i>
                                                        </a>
                                                    </li>
                                                @endif
                                            </ul>
                                        @endif
                                    </div>

                                    <!-- Log In -->
                                    <p class="fs-12 text-gray mb-0">
                                        {{ translate('Already have an account?') }}
                                        <a href="{{ route('user.login') }}"
                                            class="ml-2 fs-14 fw-700 animate-underline-primary">{{ translate('Log In') }}</a>
                                    </p>
                                    <!-- Go Back -->
                                    <a href="{{ url()->previous() }}"
                                        class="mt-3 fs-14 fw-700 d-flex align-items-center text-primary"
                                        style="max-width: fit-content;">
                                        <i class="las la-arrow-left fs-20 mr-1"></i>
                                        {{ translate('Back to Previous Page') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection

@section('script')
    @if (get_setting('google_recaptcha') == 1)
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif

    <script type="text/javascript">
        $(document).on('change', '[name=country_id]', function() {
            var country_id = $(this).val();
            get_states(country_id);
        });

        $(document).on('change', '[name=state_id]', function() {
            var state_id = $(this).val();
            get_city(state_id);
        });

        function get_states(country_id) {
            $('[name="state"]').html("");
            $.ajax({
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                url: "{{ route('get-state') }}",
                type: 'POST',
                data: {
                    country_id: country_id
                },
                success: function(response) {
                    var obj = JSON.parse(response);
                    if (obj != '') {
                        $('[name="state_id"]').html(obj);
                        AIZ.plugins.bootstrapSelect('refresh');
                    }
                }
            });
        }

        function get_city(state_id) {
            $('[name="city"]').html("");
            $.ajax({
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                url: "{{ route('get-city') }}",
                type: 'POST',
                data: {
                    state_id: state_id
                },
                success: function(response) {
                    var obj = JSON.parse(response);
                    if (obj != '') {
                        $('[name="city_id"]').html(obj);
                        AIZ.plugins.bootstrapSelect('refresh');
                    }
                }
            });
        }

        @if (get_setting('google_recaptcha') == 1)
            // making the CAPTCHA  a required field for form submission
            $(document).ready(function() {
                $("#reg-form").on("submit", function(evt) {
                    var response = grecaptcha.getResponse();
                    if (response.length == 0) {
                        //reCaptcha not verified
                        alert("please verify you are human!");
                        evt.preventDefault();
                        return false;
                    }
                    //captcha verified
                    //do the rest of your validations here
                    $("#reg-form").submit();
                });
            });
        @endif

        // function isValidIraqiPhone(phone) {
        //     // تحقق من أن الرقم يبدأ بـ 7 ثم 7 أو 8 أو 9 أو 5 ويليه 8 أرقام
        //     return /^7[5798][0-9]{8}$/.test(phone);
        // }

        function showPhoneError() {
            $('#phone-code').addClass('is-invalid');
            $('#phone-error').removeClass('d-none');
        }

        function hidePhoneError() {
            $('#phone-code').removeClass('is-invalid');
            $('#phone-error').addClass('d-none');
        }

        // تحقق عند تغيير الإدخال
        // $('#phone-code').on('change', function() {
        //     const phone = $(this).val().trim();
        //     isValidIraqiPhone(phone) ? hidePhoneError() : showPhoneError();
        // });

        // تحقق عند إرسال النموذج
        // $('#reg-form').on('submit', function(e) {
        //     const phone = $('#phone-code').val().trim();
        //     if (!isValidIraqiPhone(phone)) {
        //         showPhoneError();
        //         e.preventDefault(); // منع إرسال الفورم
        //     } else {
        //         hidePhoneError();
        //     }
        // });
    </script>
@endsection
