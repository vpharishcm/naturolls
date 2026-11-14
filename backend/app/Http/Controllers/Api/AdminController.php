<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveDiceOfferRequest;
use App\Http\Requests\SavePromotionRequest;
use App\Http\Requests\SaveStaffRequest;
use App\Models\AuditLog;
use App\Models\BackupLog;
use App\Models\DiceOffer;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function promotions()
    {
        return $this->ok(Promotion::with('products:id,name', 'categories:id,name', 'getProducts:id,name', 'getCategories:id,name')->latest('id')->get());
    }

    public function storePromotion(SavePromotionRequest $request)
    {
        return $this->ok($this->savePromotion(new Promotion, $request), [], 201);
    }

    public function updatePromotion(SavePromotionRequest $request, Promotion $promotion)
    {
        return $this->ok($this->savePromotion($promotion, $request));
    }

    public function diceOffers()
    {
        return $this->ok(DiceOffer::latest('id')->get());
    }

    public function storeDiceOffer(SaveDiceOfferRequest $request)
    {
        return $this->ok($this->saveDiceOffer(new DiceOffer, $request), [], 201);
    }

    public function updateDiceOffer(SaveDiceOfferRequest $request, DiceOffer $diceOffer)
    {
        return $this->ok($this->saveDiceOffer($diceOffer, $request));
    }

    public function destroyDiceOffer(Request $request, DiceOffer $diceOffer)
    {
        $diceOffer->update(['is_active' => false]);
        $diceOffer->delete();
        $this->audit->record($request->user(), 'dice_offer.deleted', $diceOffer);

        return $this->ok();
    }

    private function saveDiceOffer(DiceOffer $offer, SaveDiceOfferRequest $request): DiceOffer
    {
        $data = $request->validated();
        $before = $offer->exists ? $offer->only(['name', 'min_bill', 'starts_on', 'ends_on', 'is_active', 'faces']) : null;
        // Keep only what each face type uses, so an old amount or item list cannot linger on a changed face.
        $data['faces'] = array_map(fn ($f) => array_filter([
            'type' => $f['type'],
            'label' => trim((string) ($f['label'] ?? '')),
            'amount' => $f['type'] === 'discount' ? number_format((float) $f['amount'], 2, '.', '') : null,
            'product_ids' => $f['type'] === 'free_item' ? array_map('intval', $f['product_ids'] ?? []) : null,
            'category_ids' => $f['type'] === 'free_item' ? array_map('intval', $f['category_ids'] ?? []) : null,
        ], fn ($v) => $v !== null), array_values($data['faces']));
        $offer->fill($data + ['is_active' => true])->save();
        $this->audit->record($request->user(), $before ? 'dice_offer.updated' : 'dice_offer.created', $offer, $before, $offer->only(['name', 'min_bill', 'starts_on', 'ends_on', 'is_active', 'faces']));

        return $offer;
    }

    public function destroyPromotion(Request $request, Promotion $promotion)
    {
        $promotion->update(['is_active' => false]);
        $promotion->delete();
        $this->audit->record($request->user(), 'promotion.deleted', $promotion);

        return $this->ok();
    }

    private function savePromotion(Promotion $promotion, SavePromotionRequest $request): Promotion
    {
        return DB::transaction(function () use ($promotion, $request) {
            $type = $request->validated('discount_type');
            $promotion->fill(collect($request->validated())->except(['product_ids', 'category_ids', 'get_product_ids', 'get_category_ids'])->all() + ['buy_qty' => null, 'get_qty' => null]);
            $getScope = $type === 'buy_get' ? ($request->validated('get_scope') ?? 'same') : null;
            $promotion->get_scope = $getScope;
            // Only the deal types use these, so an offer changed to another type leaves nothing behind.
            $promotion->discount_value = $type === 'buy_get' ? 0 : $request->validated('discount_value');
            $promotion->buy_qty = in_array($type, ['buy_get', 'bundle'], true) ? $request->validated('buy_qty') : null;
            $promotion->get_qty = $type === 'buy_get' ? $request->validated('get_qty') : null;
            $promotion->save();
            $promotion->products()->sync($request->validated('product_ids') ?? []);
            $promotion->categories()->sync($request->validated('category_ids') ?? []);
            $promotion->getProducts()->sync($getScope === 'product' ? $request->validated('get_product_ids') : []);
            $promotion->getCategories()->sync($getScope === 'category' ? $request->validated('get_category_ids') : []);
            $this->audit->record($request->user(), 'promotion.saved', $promotion, null, $request->validated());

            return $promotion->load('products:id,name', 'categories:id,name', 'getProducts:id,name', 'getCategories:id,name');
        });
    }

    public function staff()
    {
        return $this->ok(['staff' => User::with('role:id,name,label')->orderBy('name')->get(), 'roles' => Role::orderBy('id')->get()]);
    }

    public function storeStaff(SaveStaffRequest $request)
    {
        $data = $request->validate(['username' => [Rule::unique('users', 'username')], 'password' => ['required']]) + $request->validated();
        $user = User::create(array_filter($data, fn ($v) => $v !== null));
        $this->audit->record($request->user(), 'staff.created', $user, null, ['username' => $user->username]);

        return $this->ok($user->load('role:id,name,label'), [], 201);
    }

    public function updateStaff(SaveStaffRequest $request, User $user)
    {
        $request->validate(['username' => [Rule::unique('users', 'username')->ignore($user->id)]]);
        $user->update(array_filter($request->validated(), fn ($v) => $v !== null));
        if ($user->is_active === false) {
            $user->tokens()->delete();
        }
        $this->audit->record($request->user(), 'staff.updated', $user, null, ['username' => $user->username, 'is_active' => $user->is_active]);

        return $this->ok($user->load('role:id,name,label'));
    }

    public function settings(SettingsService $settings)
    {
        return $this->ok($settings->all());
    }

    public function updateSettings(Request $request, SettingsService $settings)
    {
        $values = $request->validate(['values' => ['required', 'array']])['values'];
        $updated = $settings->update($values);
        $this->audit->record($request->user(), 'settings.updated', null, null, $values);

        return $this->ok($updated);
    }

    public function auditLogs(Request $request)
    {
        $q = AuditLog::with('user:id,name', 'authorizer:id,name')->latest('id');
        if ($action = $request->query('action')) {
            $q->where('action', 'like', "{$action}%");
        }

        return $this->page($q->paginate($this->perPage()));
    }

    public function backups()
    {
        return $this->ok(BackupLog::with('user:id,name')->latest('id')->limit(100)->get());
    }

    public function runBackup(Request $request, BackupService $backups)
    {
        return $this->ok($backups->run('manual', $request->user()), [], 201);
    }

    public function downloadBackup(BackupLog $backup, BackupService $backups)
    {
        $path = $backups->directory().DIRECTORY_SEPARATOR.basename((string) $backup->file_name);
        abort_unless($backup->file_name && is_file($path), 404);

        return response()->download($path);
    }
}
