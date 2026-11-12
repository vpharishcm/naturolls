<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoyaltyAdjustRequest;
use App\Http\Requests\SaveCustomerRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Services\AuditService;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private LoyaltyService $loyalty, private AuditService $audit) {}

    public function index(Request $request)
    {
        $q = Customer::query()->with('loyaltyAccount')->withCount('invoices')->withSum('invoices as total_spent', 'total')->orderBy('name');
        if ($search = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%"));
        }

        return $this->page($q->paginate($this->perPage()));
    }

    public function show(Customer $customer)
    {
        $customer->load('loyaltyAccount.transactions')->loadCount('invoices')->loadSum('invoices as total_spent', 'total');
        $invoices = $customer->invoices()->with('payments')->latest('id')->limit(50)->get();

        return $this->ok($customer->toArray() + ['recent_invoices' => InvoiceResource::collection($invoices)->resolve()]);
    }

    public function store(SaveCustomerRequest $request)
    {
        $customer = Customer::create(collect($request->validated())->except('join_loyalty')->all());
        if ($request->boolean('join_loyalty')) {
            $this->loyalty->register($customer);
        }
        $this->audit->activity($request->user(), 'customer.created', $customer->name);

        return $this->ok($customer->load('loyaltyAccount'), [], 201);
    }

    public function update(SaveCustomerRequest $request, Customer $customer)
    {
        $customer->update(collect($request->validated())->except('join_loyalty')->all());
        if ($request->boolean('join_loyalty')) {
            $this->loyalty->register($customer);
        }

        return $this->ok($customer->load('loyaltyAccount'));
    }

    public function destroy(Customer $customer)
    {
        // The number is free for a new record; past bills keep their own copy of it.
        $customer->update(['mobile' => null]);
        $customer->delete();

        return $this->ok();
    }

    public function joinLoyalty(Customer $customer)
    {
        return $this->ok($this->loyalty->register($customer), [], 201);
    }

    public function adjustPoints(LoyaltyAdjustRequest $request, LoyaltyAccount $account)
    {
        $account = $this->loyalty->adjust($account, (int) $request->points, $request->note, $request->user());
        $this->audit->record($request->user(), 'loyalty.adjusted', $account, null, ['points' => $request->points], $request->note);

        return $this->ok($account);
    }
}
