<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Tax;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function __construct()
    {
        // Staff Permission Check
        $this->middleware(['permission:vat_&_tax_setup'])->only('index', 'create', 'edit', 'destroy');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $all_taxes = Tax::orderBy('created_at', 'desc')->get();
        $allcategories = Category::where('level', 0)->get();
        $categories = Category::where('digital', 0)->where('level', 0)->get();
        $services = Category::where('digital', 1)->where('level', 0)->get();
        return view('backend.setup_configurations.tax.index', compact('all_taxes', 'allcategories', 'categories', 'services'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $tax = new Tax;
        $tax->name = $request->name;
        $tax->type = $request->type;
        $tax->tax_type = $request->tax_type;
        $tax->tax_value = $request->tax_value;
        // $pickup_point->address = $request->address;

        if ($request->type === 'physical') {
            $tax->tax_category = $request->category_id;
        } else {
            $tax->tax_category = $request->service_id;
        }

        if ($tax->save()) {

            flash(translate('Tax has been inserted successfully'))->success();
            return redirect()->route('tax.index');
        } else {
            flash(translate('Something went wrong'))->error();
            return back();
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $tax = Tax::findOrFail($id);
        $allcategories = Category::where('level', 0)->get();
        $categories = Category::where('digital', 0)->where('level', 0)->get();
        $services = Category::where('digital', 1)->where('level', 0)->get();
        return view('backend.setup_configurations.tax.edit', compact('tax','allcategories','categories','services'));
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
        $tax = Tax::findOrFail($id);
        $tax->name = $request->name;
        $tax->type = $request->type;
        $tax->tax_category = $request->tax_category;
        $tax->tax_type = $request->tax_type;
        $tax->tax_value = $request->tax_value;
        //        $language->code = $request->code;

        if ($request->type === 'physical') {
            $tax->tax_category = $request->category_id;
        } else {
            $tax->tax_category = $request->service_id;
        }

        if ($tax->save()) {
            flash(translate('Tax has been updated successfully'))->success();
            return redirect()->route('tax.index');
        } else {
            flash(translate('Something went wrong'))->error();
            return back();
        }
    }

    public function change_tax_status(Request $request)
    {
        $tax = Tax::findOrFail($request->id);
        if ($tax->tax_status == 1) {
            $tax->tax_status = 0;
        } else {
            $tax->tax_status = 1;
        }

        if ($tax->save()) {
            return 1;
        }
        return 0;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $tax = Tax::findOrFail($id);
        $tax->product_taxes()->delete();
        if (Tax::destroy($id)) {
            flash(translate('Tax has been deleted successfully'))->success();
            return redirect()->route('tax.index');
        } else {
            flash(translate('Something went wrong'))->error();
            return back();
        }
    }
}
