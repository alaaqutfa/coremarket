<?php

namespace App\Http\Controllers;

use App\Models\AppTranslation;
use Illuminate\Http\Request;
use App\Models\Language;
use App\Models\Translation;
use App\Models\User;
use Cache;
use Storage;
use Session;

class LanguageController extends Controller
{
    public function __construct() {
        // Staff Permission Check
        $this->middleware(['permission:language_setup'])->only('index','create','edit','destroy');
    }

    public function changeLanguage(Request $request)
    {
    	$request->session()->put('locale', $request->locale);
        $language = Language::where('code', $request->locale)->first();
        $request->session()->put('langcode', $language->app_lang_code);
    	flash(translate('Language changed to ').$language->name)->success();
    }

    public function index(Request $request)
    {
        $languages = Language::paginate(10);
        return view('backend.setup_configurations.languages.index', compact('languages'));
    }

    public function create(Request $request)
    {
        return view('backend.setup_configurations.languages.create');
    }

    public function store(Request $request)
    {
        if(Language::where('code',$request->code)->first()){
            flash(translate('This code is already used for another language'))->error();
            return back();
        }

        $language = new Language;
        $language->name = $request->name;
        $language->code = $request->code;
        $language->app_lang_code = $request->app_lang_code;
        $language->save();   

        Cache::forget('app.languages');

        flash(translate('Language has been inserted successfully'))->success();
        return redirect()->route('languages.index');
    }

    public function show(Request $request, $id)
    {
        $language = Language::findOrFail($id);

        return $this->renderTranslationView(
            $request,
            $language,
            'backend.setup_configurations.languages.language_view',
            route('languages.key_value_store')
        );
    }

    public function limitedIndex(Request $request)
    {
        $sort_search = null;
        $languages = Language::query()
            ->where('status', 1)
            ->orderBy('name');

        if ($request->has('search')) {
            $sort_search = $request->search;
            $languages->where('name', 'like', '%' . $sort_search . '%');
        }

        return view('backend.website_settings.translations.index', [
            'languages' => $languages->paginate(15),
            'sort_search' => $sort_search,
        ]);
    }

    public function limitedShow(Request $request, $id)
    {
        $language = Language::query()
            ->where('status', 1)
            ->findOrFail($id);

        return $this->renderTranslationView(
            $request,
            $language,
            'backend.setup_configurations.languages.language_view',
            route('website.translations.update')
        );
    }

    public function edit($id)
    {
        $language = Language::findOrFail($id);
        return view('backend.setup_configurations.languages.edit', compact('language'));
    }

    public function update(Request $request, $id)
    {
        if(Language::where('code', $request->code)->where('id', '!=', $id)->first()){
            flash(translate('This code is already used for another language'))->error();
            return back();
        }
        $language = Language::findOrFail($id);
        if (env('DEFAULT_LANGUAGE') == $language->code && env('DEFAULT_LANGUAGE') != $request->code) {
            flash(translate('Default language code cannot be edited'))->error();
            return back();
        } elseif ($language->code == 'en' && $request->code != 'en') {
            flash(translate('English language code cannot be edited'))->error();
            return back();
        }
        
        $language->name = $request->name;
        $language->code = $request->code;
        $language->app_lang_code = $request->app_lang_code; 
        $language->save();
        
        Cache::forget('app.languages');

        flash(translate('Language has been updated successfully'))->success();
        return redirect()->route('languages.index');
    }

    public function key_value_store(Request $request)
    {
        $language = Language::findOrFail($request->id);
        $this->persistTranslations($language, (array) $request->values);

        flash(translate('Translations updated for').' '.$language->name)->success();
        return back();
    }

    public function limitedKeyValueStore(Request $request)
    {
        $language = Language::query()
            ->where('status', 1)
            ->findOrFail($request->id);

        $this->persistTranslations($language, (array) $request->values);

        flash(translate('Translations updated for').' '.$language->name)->success();
        return back();
    }

    public function update_status(Request $request)
    {
        $language = Language::findOrFail($request->id);
        if($language->code == env('DEFAULT_LANGUAGE') && $request->status == 0) {
            flash(translate('Default language cannot be inactive'))->error();
            return 1;
        }
        $language->status = $request->status;
        if($language->save()){
            flash(translate('Status updated successfully'))->success();
            return 1;
        }
        return 0;
    }

    public function update_rtl_status(Request $request)
    {
        $language = Language::findOrFail($request->id);
        $language->rtl = $request->status;
        if($language->save()){
            flash(translate('RTL status updated successfully'))->success();
            return 1;
        }
        return 0;
    }

    public function destroy($id)
    {
        $language = Language::findOrFail($id);
        if (env('DEFAULT_LANGUAGE') == $language->code) {
            flash(translate('Default language cannot be deleted'))->error();
        } elseif($language->code == 'en') {
            flash(translate('English language cannot be deleted'))->error();
        }
        else {
            if($language->code == Session::get('locale')){
                Session::put('locale', env('DEFAULT_LANGUAGE'));
            }
            Language::destroy($id);
            flash(translate('Language has been deleted successfully'))->success();
        }
        return redirect()->route('languages.index');
    }


    //App-Translation
    public function importEnglishFile(Request $request){
        $path = Storage::disk('local')->put('app-translations', $request->lang_file);

        $contents = file_get_contents(public_path($path));
        
        try {
            foreach(json_decode($contents) as $key => $value){
                AppTranslation::updateOrCreate(
                    ['lang' => 'en', 'lang_key' => $key],
                    ['lang_value' => $value]
                );
            }
        } catch (\Throwable $th) {
            //throw $th;
        }

        flash(translate('Translation keys has been imported successfully. Go to App Translation for more..'))->success();
        return back();
    }

    public function showAppTranlsationView(Request $request, $id)
    {
        $sort_search = null;
        $language = Language::findOrFail($id);
        $lang_keys = AppTranslation::where('lang', 'en');
        if ($request->has('search')){
            $sort_search = $request->search;
            $lang_keys = $lang_keys->where('lang_key', 'like', '%'.$sort_search.'%');
        }
        $lang_keys = $lang_keys->paginate(50);
        return view('backend.setup_configurations.languages.app_translation', compact('language','lang_keys','sort_search'));
    }

    public function storeAppTranlsation(Request $request){
        $language = Language::findOrFail($request->id);
        foreach ($request->values as $key => $value) {
            AppTranslation::updateOrCreate(
                ['lang' => $language->app_lang_code, 'lang_key' => $key],
                ['lang_value' => $value]
            );
        }
        flash(translate('App Translations updated for ').$language->name)->success();
        return back();
    }

    public function exportARBFile($id){
        $language = Language::findOrFail($id);
        try {
            // Write into the json file
            $filename = "app_{$language->app_lang_code}.arb";
            $contents = AppTranslation::where('lang', $language->app_lang_code)->pluck('lang_value', 'lang_key')->toJson();
            
            return response()->streamDownload(function () use ($contents) {
                echo $contents;
            }, $filename);
        } catch (\Exception $e) {
            dd($e);
        }
    }

    public function get_translation($unique_identifier)
    {
        return redirect()->route('home');
    }

    protected function renderTranslationView(Request $request, Language $language, string $view, string $formAction)
    {
        $sort_search = null;
        $lang_keys = Translation::where('lang', 'en');

        if ($request->has('search')){
            $sort_search = $request->search;
            $lang_keys = $lang_keys->where('lang_key', 'like', '%'.preg_replace('/[^A-Za-z0-9\_]/', '', str_replace(' ', '_', strtolower($sort_search))).'%');
        }

        return view($view, [
            'language' => $language,
            'lang_keys' => $lang_keys->paginate(50),
            'sort_search' => $sort_search,
            'formAction' => $formAction,
        ]);
    }

    protected function persistTranslations(Language $language, array $values): void
    {
        foreach ($values as $key => $value) {
            $translation_def = Translation::where('lang_key', $key)->where('lang', $language->code)->latest()->first();
            if($translation_def == null){
                $translation_def = new Translation;
                $translation_def->lang = $language->code;
                $translation_def->lang_key = $key;
            }

            $translation_def->lang_value = $value;
            $translation_def->save();
        }

        Cache::forget('translations-'.$language->code);
    }
}
