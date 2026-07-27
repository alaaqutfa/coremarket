<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Staff;
use App\Models\User;
use App\Services\CoreMarketBranchService;
use App\Services\CoreMarketStaffGovernanceService;
use DomainException;
use Hash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class StaffController extends Controller
{
    public function __construct() {
        // Staff Permission Check
        $this->middleware(['permission:view_all_staffs'])->only('index');
        $this->middleware(['permission:add_staff'])->only('create');
        $this->middleware(['permission:edit_staff'])->only('edit');
        $this->middleware(['permission:edit_staff'])->only('suspend');
        $this->middleware(['permission:delete_staff'])->only('destroy');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(CoreMarketStaffGovernanceService $governance)
    {
        $staffs = Staff::with(['user.branches', 'role'])->paginate(10);
        $canHardDeleteStaff = $governance->canDeleteStaff(auth()->user());

        return view('backend.staff.staffs.index', compact('staffs', 'canHardDeleteStaff'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(CoreMarketStaffGovernanceService $governance, CoreMarketBranchService $branches)
    {
        $roles = $governance->rolesAssignableBy(auth()->user());
        $activeBranches = $branches->activeBranches();
        if ($activeBranches->isEmpty()) {
            $activeBranches = collect([$branches->ensureDefaultBranch()]);
        }

        return view('backend.staff.staffs.create', compact('roles', 'activeBranches'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, CoreMarketStaffGovernanceService $governance, CoreMarketBranchService $branches)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'mobile' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'integer'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:store_branches,id'],
            'primary_branch_id' => ['nullable', 'integer', 'exists:store_branches,id'],
        ]);

        try {
            $governance->assertCanCreateStaff(auth()->user());
            DB::transaction(function () use ($request, $governance, $branches) {
                $user = new User;
                $user->name = $request->name;
                $user->email = $request->email;
                $user->phone = $request->mobile;
                $user->user_type = 'staff';
                $user->password = Hash::make($request->password);
                $user->save();

                $role = $governance->assignPresetToUser($user, (int) $request->role_id, auth()->user());
                $staff = new Staff;
                $staff->user_id = $user->id;
                $staff->role_id = $role->id;
                $staff->save();
                $branches->assignStaff($user, $request->input('branch_ids', []), (int) $request->input('primary_branch_id') ?: null);
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['staff' => $exception->getMessage()])->withInput();
        }

        flash(translate('Staff has been inserted successfully'))->success();

        return redirect()->route('staffs.index');
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
    public function edit($id, CoreMarketStaffGovernanceService $governance, CoreMarketBranchService $branches)
    {
        $staff = Staff::with('user.branches')->findOrFail(decrypt($id));
        $roles = $governance->rolesAssignableBy(auth()->user());
        $activeBranches = $branches->activeBranches();

        return view('backend.staff.staffs.edit', compact('staff', 'roles', 'activeBranches'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, CoreMarketStaffGovernanceService $governance, CoreMarketBranchService $branches)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'mobile' => ['required', 'string', 'max:100'],
            'role_id' => ['required', 'integer'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:store_branches,id'],
            'primary_branch_id' => ['nullable', 'integer', 'exists:store_branches,id'],
        ]);
        $staff = Staff::findOrFail($id);
        $user = $staff->user;
        try {
            DB::transaction(function () use ($request, $governance, $branches, $staff, $user) {
                $user->name = $request->name;
                $user->email = $request->email;
                $user->phone = $request->mobile;
                if (strlen((string) $request->password) > 0) {
                    $user->password = Hash::make($request->password);
                }
                $user->save();
                $role = $governance->assignPresetToUser($user, (int) $request->role_id, auth()->user());
                $staff->role_id = $role->id;
                $staff->save();
                $branches->assignStaff($user, $request->input('branch_ids', []), (int) $request->input('primary_branch_id') ?: null);
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['staff' => $exception->getMessage()])->withInput();
        }

        flash(translate('Staff has been updated successfully'))->success();

        return redirect()->route('staffs.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, CoreMarketStaffGovernanceService $governance)
    {
        abort_unless($governance->canDeleteStaff(auth()->user()), 403);
        User::destroy(Staff::findOrFail($id)->user->id);
        if(Staff::destroy($id)){
            flash(translate('Staff has been deleted successfully'))->success();
            return redirect()->route('staffs.index');
        }

        flash(translate('Something went wrong'))->error();
        return back();
    }

    public function suspend(Staff $staff, CoreMarketStaffGovernanceService $governance): RedirectResponse
    {
        abort_unless($staff->user && $governance->canSuspendStaff(auth()->user(), $staff->user), 403);
        $staff->user->forceFill(['banned' => $staff->user->banned ? 0 : 1])->save();
        flash(translate($staff->user->banned ? 'Staff account suspended successfully' : 'Staff account activated successfully'))->success();

        return back();
    }
}
