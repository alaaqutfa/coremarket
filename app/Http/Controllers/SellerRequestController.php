<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use Illuminate\Http\Request;
use App\Models\Seller;
use App\Models\SellerRequest;
use App\Models\User;
use App\Models\Shop;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Wishlist;
use Illuminate\Support\Facades\Hash;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\ShopVerificationNotification;
use Cache;
use Illuminate\Support\Facades\Notification;

class SellerRequestController extends Controller
{
    public function __construct()
    {
        // Staff Permission Check
        $this->middleware(['permission:view_all_seller'])->only('index');
        $this->middleware(['permission:view_seller_profile'])->only('profile_modal');
        $this->middleware(['permission:login_as_seller'])->only('login');
        $this->middleware(['permission:pay_to_seller'])->only('payment_modal');
        $this->middleware(['permission:edit_seller'])->only('edit');
        $this->middleware(['permission:delete_seller'])->only('destroy');
        $this->middleware(['permission:ban_seller'])->only('ban');
    }

    public function index()
    {
        $request = SellerRequest::with('seller')->get();

        return view('backend.sellers.requests.index', compact('request'));
    }

    public function updateApproval(Request $request)
    {
        $requestId = $request->id;
        $status = $request->status; 

        $request = SellerRequest::find($requestId);
        
        if ($request) {
            $request->approved = $status;
            $request->save();
            return response()->json(1);  
        }

        return response()->json(0); 
    }

    public function destroy($id)
    {
        $request = SellerRequest::findOrFail($id);
        $request->delete();

        return back();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
  
}
