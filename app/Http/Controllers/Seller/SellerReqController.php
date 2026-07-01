<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;

class SellerReqController extends Controller
{
    public function index()
    {
        $sellerId = Auth::id();

        $request = SellerRequest::where('seller_id', $sellerId)->get();

        return view('seller.Request.index', compact('request'));
    }

    public function create()
    {
        return view('seller.Request.create');
    }
    
    public function store(HttpRequest $request)
    {
        if (Auth::user()->user_type !== 'seller') {
            return response()->json(['error' => 'غير مسموح. يجب أن تكون تاجرًا لإضافة طلب.'], 403);
        }

        $validatedData = $request->validate([
            'kind' => 'required|in:category,brand',
            'name' => 'required|string|max:255',
        ]);

        $sellerId = Auth::id();

        $newRequest = SellerRequest::create([
            'seller_id' => $sellerId,
            'kind' => $validatedData['kind'],
            'name' => $validatedData['name'],
            'approved' => false, 
        ]);

        return back();
    }

    public function destroy($id)
    {
        $request = SellerRequest::findOrFail($id);
        $request->delete();

        return back();
    }
}
