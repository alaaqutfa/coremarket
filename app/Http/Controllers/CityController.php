<?php
namespace App\Http\Controllers;

use App\Models\City;
use App\Models\CityTranslation;
use App\Models\State;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function __construct()
    {
        // Staff Permission Check
        $this->middleware(['permission:manage_shipping_cities'])->only('index', 'create', 'destroy');
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {

        $sort_city      = $request->sort_city;
        $sort_state     = $request->sort_state;
        $cities_queries = City::query();
        if ($request->sort_city) {
            $cities_queries->where('name', 'like', "%$sort_city%");
        }
        if ($request->sort_state) {
            $cities_queries->where('state_id', $request->sort_state);
        }
        $cities  = $cities_queries->orderBy('status', 'desc')->paginate(15);

        // $tr_city = [
        //     21787 => 'جرف الصخر',
        //     21788 => 'سدّة الهندية',
        //     21789 => 'الحلة',
        //     21790 => 'المدحتية',
        //     21791 => 'المسيب',
        //     21792 => 'القاسم',
        //     21793 => 'بغداد',
        //     21794 => 'دهوك',
        //     21795 => 'زاخو',
        //     21796 => 'بعقوبة',
        //     21797 => 'خانقين',
        //     21798 => 'جلولاء',
        //     21799 => 'كفري',
        //     21800 => 'مندلي',
        //     21801 => 'الفهود',
        //     21802 => 'الخالص',
        //     21803 => 'المقدادية',
        //     21804 => 'الشطرة',
        //     21805 => 'عنكاوة',
        //     21806 => 'تشقالاوة',
        //     21807 => 'أربيل',
        //     21808 => 'رواندوز',
        //     21809 => 'كربلاء',
        //     21810 => 'الهندية',
        //     21811 => 'أربيل',
        //     21812 => 'علي الغربي',
        //     21813 => 'العمارة',
        //     21814 => 'المجر الكبير',
        //     21815 => 'قره قوش',
        //     21816 => 'سنجار',
        //     21817 => 'تلعفر',
        //     21818 => 'تل كيف',
        //     21819 => 'الموصل',
        //     21820 => 'الشيخان',
        //     21821 => 'بلد',
        //     21822 => 'بيجي',
        //     21823 => 'الضلوعية',
        //     21824 => 'سامراء',
        //     21825 => 'تكريت',
        //     21826 => 'طوز',
        //     21827 => 'الدجيل',
        //     21828 => 'العزيزية',
        //     21829 => 'الحي',
        //     21830 => 'الكوت',
        //     21831 => 'النعمانية',
        //     21832 => 'الصويرة',
        //     21833 => 'عانة',
        //     21834 => 'هيت',
        //     21835 => 'راوة',
        //     21836 => 'الفلوجة',
        //     21837 => 'الحبانية',
        //     21838 => 'حديثة',
        //     21839 => 'الرمادي',
        //     21840 => 'الرطبة',
        //     21841 => 'أبو الخصيب',
        //     21842 => 'الحرية',
        //     21843 => 'شط العرب',
        //     21844 => 'البصرة',
        //     21845 => 'الفاو',
        //     21846 => 'القرنة',
        //     21847 => 'الزبير',
        //     21848 => 'الرميثة',
        //     21849 => 'السماوة',
        //     21850 => 'عفك',
        //     21851 => 'الديوانية',
        //     21852 => 'الحمزة',
        //     21853 => 'الغماس',
        //     21854 => 'الشامية',
        //     21855 => 'الشنافية',
        //     21856 => 'الكوفة',
        //     21857 => 'المشخاب',
        //     21858 => 'النجف',
        //     21859 => 'جمجمال',
        //     21860 => 'حلبجة',
        //     21861 => 'كوسنجق',
        //     21862 => 'بن جوين',
        //     21863 => 'قلعة دزة',
        //     21864 => 'السليمانية',
        //     21865 => 'عقرة',
        //     21866 => 'كركوك',
        // ];
        // foreach ($tr_city as $city_id => $name_ar) {
        //     CityTranslation::updateOrCreate(
        //         ['city_id' => $city_id, 'lang' => 'iq'],
        //         ['name' => $name_ar]
        //     );
        // }
        $states = State::where('status', 1)->get();

        return view('backend.setup_configurations.cities.index', compact('cities', 'states', 'sort_city', 'sort_state'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $city = new City;

        $city->name     = $request->name;
        $city->cost     = $request->cost;
        $city->state_id = $request->state_id;

        $city->save();

        flash(translate('City has been inserted successfully'))->success();

        return back();
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $lang   = $request->lang;
        $city   = City::findOrFail($id);
        $states = State::where('status', 1)->get();
        return view('backend.setup_configurations.cities.edit', compact('city', 'lang', 'states'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $city = City::findOrFail($id);
        if ($request->lang == env("DEFAULT_LANGUAGE")) {
            $city->name = $request->name;
        }

        $city->state_id = $request->state_id;
        $city->cost     = $request->cost;

        $city->save();

        $city_translation       = CityTranslation::firstOrNew(['lang' => $request->lang, 'city_id' => $city->id]);
        $city_translation->name = $request->name;
        $city_translation->save();

        flash(translate('City has been updated successfully'))->success();
        return back();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $city = City::findOrFail($id);
        $city->city_translations()->delete();
        City::destroy($id);

        flash(translate('City has been deleted successfully'))->success();
        return redirect()->route('cities.index');
    }

    public function updateStatus(Request $request)
    {
        $city         = City::findOrFail($request->id);
        $city->status = $request->status;
        $city->save();

        return 1;
    }
}
