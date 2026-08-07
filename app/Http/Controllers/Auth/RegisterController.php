<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\BusinessSetting;
use App\Models\Cart;
use App\Models\User;
use App\Rules\Recaptcha;
use App\Support\InternationalPhone;
use Cookie;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Session;

class RegisterController extends Controller
{
    use RegistersUsers;

    protected $redirectTo = '/';

    public function __construct()
    {
        $this->middleware('guest');
    }

    protected function validator(array $data)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => [
                'nullable',
                function ($attribute, $value, $fail) use ($data) {
                    if (trim((string) $value) !== ''
                        && InternationalPhone::normalize($data['country_code'] ?? null, $value) === null) {
                        $fail(translate('Please enter a valid international phone number.'));
                    }
                },
            ],
            'country_code' => 'nullable|string|max:4',
            'password' => 'required|string|min:6|confirmed',
            'address' => 'nullable|string|max:500',
            'country_id' => 'nullable|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'nullable|exists:cities,id',
            'postal_code' => 'nullable|string|max:20',
            'g-recaptcha-response' => [
                Rule::when(get_setting('google_recaptcha') == 1, ['required', new Recaptcha()], ['sometimes']),
            ],
        ];

        return Validator::make($data, $rules);
    }

    protected function create(array $data)
    {
        $userData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ];

        if ($phone = InternationalPhone::normalize($data['country_code'] ?? null, $data['phone'] ?? null)) {
            $userData['phone'] = $phone['phone'];
            $userData['country_code'] = $phone['country_code'];

            if (addon_is_activated('otp_system')) {
                $userData['verification_code'] = rand(100000, 999999);
            }
        }

        $user = User::create($userData);

        if (session('temp_user_id') != null) {
            if ($user->user_type == 'customer') {
                Cart::where('temp_user_id', session('temp_user_id'))
                    ->update([
                        'user_id' => $user->id,
                        'temp_user_id' => null,
                    ]);
            } else {
                Cart::where('temp_user_id', session('temp_user_id'))->delete();
            }
            Session::forget('temp_user_id');
        }

        if (Cookie::has('referral_code')) {
            $referredByUser = User::where('referral_code', Cookie::get('referral_code'))->first();
            if ($referredByUser != null) {
                $user->referred_by = $referredByUser->id;
                $user->save();
            }
        }

        return $user;
    }

    public function register(Request $request)
    {
        $data = $this->validator($request->all())->validate();
        $phone = InternationalPhone::normalize($data['country_code'] ?? null, $data['phone'] ?? null);

        if ($phone && User::query()
            ->where('country_code', $phone['country_code'])
            ->where('phone', $phone['phone'])
            ->exists()) {
            return back()->withErrors(['phone' => translate('Phone already exists.')])->withInput();
        }

        $request->merge($phone ?: ['phone' => null, 'country_code' => null]);
        $user = $this->create($request->all());
        $this->guard()->login($user);

        if (BusinessSetting::where('type', 'email_verification')->first()->value != 1) {
            $user->email_verified_at = now();
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

        $hasAddress = collect(['address', 'country_id', 'state_id', 'city_id', 'postal_code'])
            ->contains(fn (string $field) => $request->filled($field));

        if ($hasAddress) {
            try {
                $address = new Address;
                $address->user_id = $user->id;
                $address->address = $request->address;
                $address->country_id = $request->country_id;
                $address->state_id = $request->state_id;
                $address->city_id = $request->city_id;
                $address->longitude = $request->longitude;
                $address->latitude = $request->latitude;
                $address->postal_code = $request->postal_code;
                $address->set_default = 1;
                $address->phone = $phone['e164'] ?? null;
                $address->save();
            } catch (\Throwable $th) {
                $user->delete();
                flash(translate('Registration failed. Please try again later.'))->error();
            }
        }

        return $this->registered($request, $user)
            ?: redirect($this->redirectPath());
    }

    protected function registered(Request $request, $user)
    {
        return session('link') != null
            ? redirect(session('link'))
            : redirect()->route('home');
    }
}
