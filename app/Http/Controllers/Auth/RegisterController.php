<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
// use App\Http\Controllers\OTPVerificationController;
use App\Models\Address;
use App\Models\BusinessSetting;
use App\Models\Cart;
use App\Models\User;
use App\Rules\Recaptcha;
use Cookie;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Session;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        $rules = [
            'name'                 => 'required|string|max:255',
            'password'             => 'required|string|min:6|confirmed',
            'g-recaptcha-response' => [
                Rule::when(get_setting('google_recaptcha') == 1, ['required', new Recaptcha()], ['sometimes']),
            ],
        ];

        // إذا كان هناك بريد إلكتروني أو هاتف
        if (! empty($data['email']) || ! empty($data['phone'])) {
            $rules['email'] = 'nullable|email|max:255|unique:users';
            $rules['phone'] = [
                'nullable',
                Rule::requiredIf(empty($data['email'])),
                'unique:users,phone,NULL,id,country_code,' . $data['country_code'],
            ];
            $rules['country_code'] = [
                Rule::requiredIf(! empty($data['phone'])),
            ];
        } else {
            // إذا لم يتم إدخال أي منهما
            $rules['email_or_phone'] = 'required';
        }

        $rules['address']     = 'string|max:500';
        $rules['country_id']  = 'required|exists:countries,id';
        $rules['state_id']    = 'required|exists:states,id';
        $rules['city_id']     = 'required|exists:cities,id';
        $rules['postal_code'] = 'nullable|string|max:20';

        return Validator::make($data, $rules);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        $userData = [
            'name'         => $data['name'],
            'password'     => Hash::make($data['password']),
            'country_code' => $data['country_code'],
        ];

        // إذا كان هناك بريد إلكتروني
        if (! empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $userData['email'] = $data['email'];
        }

        // إذا كان هناك هاتف
        if (! empty($data['phone'])) {
            $userData['phone']        = $data['phone'];
            $userData['country_code'] = $data['country_code'];

            if (addon_is_activated('otp_system')) {
                $userData['verification_code'] = rand(100000, 999999);
            }
        }

        $user = User::create($userData);

        // إرسال رمز التحقق إذا كان هناك هاتف
        // if (! empty($data['phone']) && addon_is_activated('otp_system')) {
        // $otpController = new OTPVerificationController;
        // $otpController->send_code($user);
        // }

        // تحديث سلة التسوق
        if (session('temp_user_id') != null) {
            if (auth()->user()->user_type == 'customer') {
                Cart::where('temp_user_id', session('temp_user_id'))
                    ->update([
                        'user_id'      => auth()->user()->id,
                        'temp_user_id' => null,
                    ]);
            } else {
                Cart::where('temp_user_id', session('temp_user_id'))->delete();
            }
            Session::forget('temp_user_id');
        }

        // إدارة الإحالات
        if (Cookie::has('referral_code')) {
            $referral_code    = Cookie::get('referral_code');
            $referred_by_user = User::where('referral_code', $referral_code)->first();
            if ($referred_by_user != null) {
                $user->referred_by = $referred_by_user->id;
                $user->save();
            }
        }

        return $user;
    }

    public function register(Request $request)
    {
        // التحقق من عدم وجود مستخدم بنفس البريد أو الهاتف
        if (! empty($request->email) && User::where('email', $request->email)->exists()) {
            flash(translate('Email already exists.'))->error();
            return back();
        }

        if (! empty($request->phone) && User::where('phone', $request->phone)->exists()) {
            flash(translate('Phone already exists.'))->error();
            return back();
        }

        // إذا لم يتم إدخال أي من الحقلين
        if (empty($request->email) && empty($request->phone)) {
            flash(translate('You must provide either email or phone number.'))->error();
            return back();
        }

        $this->validator($request->all())->validate();

        $user = $this->create($request->all());

        $this->guard()->login($user);

        // معالجة التحقق من البريد الإلكتروني إذا كان موجودًا
        if (! empty($user->email)) {
            if (BusinessSetting::where('type', 'email_verification')->first()->value != 1) {
                $user->email_verified_at = date('Y-m-d H:m:s');
                $user->save();
                offerUserWelcomeCoupon();
                flash(translate('Registration successful.'))->success();
            } else {
                try {
                    $user->sendEmailVerificationNotification();
                    flash(translate('Registration successful. Please verify your email.'))->success();
                } catch (\Throwable $th) {
                    $user->delete();
                    flash(translate('Registration failed. Please try again later.'))->error();
                }
            }
        } else {
            $user->email_verified_at = date('Y-m-d H:m:s');
            $user->save();
            offerUserWelcomeCoupon();
            // إذا كان التسجيل بالهاتف فقط
            flash(translate('Registration successful. Please verify your phone number.'))->success();
        }

        try {
            $address              = new Address;
            $address->user_id     = $user->id;
            $address->address     = $request->address;
            $address->country_id  = $request->country_id;
            $address->state_id    = $request->state_id;
            $address->city_id     = $request->city_id;
            $address->longitude   = $request->longitude;
            $address->latitude    = $request->latitude;
            $address->postal_code = $request->postal_code;
            $address->set_default = 1;
            $address->phone       = '+' . $request->country_code . $request->phone;
            $address->save();
        } catch (\Throwable $th) {
            $user->delete();
            flash(translate('Registration failed. Please try again later.'))->error();
        }

        return $this->registered($request, $user)
        ?: redirect($this->redirectPath());
    }
    protected function registered(Request $request, $user)
    {
        // if ($user->email == null) {
        //     return redirect()->route('verification');
        // } else
        if (session('link') != null) {
            return redirect(session('link'));
        } else {
            return redirect()->route('home');
        }
    }
}
