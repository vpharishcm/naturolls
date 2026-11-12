<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public const PERMISSIONS = [
        'order.cancel' => 'Cancel orders',
        'discount.apply' => 'Authorise discounts',
        'refund.create' => 'Issue refunds',
        'invoice.edit_anytime' => 'Edit bills at any time',
        'stock.correct' => 'Correct stock and record wastage',
        'production.record' => 'Record daily production',
        'production.reverse' => 'Edit or reverse production',
        'catalog.manage' => 'Manage products and categories',
        'customers.manage' => 'Manage customers',
        'payments.manage' => 'Reconcile payments and close cash',
        'reports.view' => 'View reports',
        'promotions.manage' => 'Manage promotions',
        'loyalty.adjust' => 'Adjust loyalty points',
        'staff.manage' => 'Manage staff',
        'settings.manage' => 'Change settings',
        'backups.manage' => 'Manage backups',
    ];

    public const STAFF_PERMISSIONS = ['production.record', 'customers.manage'];

    public function run(): void
    {
        $this->seedAccess();
        if ($this->command) {
            $this->command->warn('Demo logins: admin / admin123 (PIN 1234), staff / staff123. Change them before going live.');
        }
        $this->seedCatalog();
        app(SettingsService::class)->update([
            'business.name' => 'Naturolls',
            'receipt.footer' => 'Thank you for visiting',
        ]);

        $customer = Customer::firstOrCreate(['mobile' => '9000000001'], ['name' => 'Demo Customer']);
        app(LoyaltyService::class)->register($customer);

        Promotion::firstOrCreate(['name' => 'Demo: 10% off above ₹200'], [
            'discount_type' => 'percent', 'discount_value' => 10, 'scope' => 'order', 'min_purchase' => 200, 'is_active' => true,
        ]);
    }

    public function seedAccess(): void
    {
        $admin = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Owner / Admin']);
        $staff = Role::firstOrCreate(['name' => 'staff'], ['label' => 'Counter staff']);
        foreach (self::PERMISSIONS as $name => $label) {
            Permission::firstOrCreate(['name' => $name], ['label' => $label]);
        }
        $admin->permissions()->sync(Permission::pluck('id'));
        $staff->permissions()->sync(Permission::whereIn('name', self::STAFF_PERMISSIONS)->pluck('id'));

        User::firstOrCreate(['username' => 'admin'], ['name' => 'Owner', 'role_id' => $admin->id, 'password' => 'admin123', 'pin' => '1234']);
        User::firstOrCreate(['username' => 'staff'], ['name' => 'Counter Staff', 'role_id' => $staff->id, 'password' => 'staff123']);
    }

    private function seedCatalog(): void
    {
        $popsicles = Category::firstOrCreate(['name' => 'Popsicles'], ['display_order' => 0, 'parent_id' => null]);
        $cats = [];
        foreach (['Water based' => $popsicles->id, 'Milk based' => $popsicles->id, 'Waffles' => null, 'Ice Cream' => null, 'Milkshakes' => null, 'Beverages' => null] as $name => $parent) {
            $cats[$name] = Category::firstOrCreate(['name' => $name], ['parent_id' => $parent, 'display_order' => count($cats) + 1]);
        }

        // Names and prices follow the design file. Costs, GST rates and opening stock are demo values to replace.
        $rows = [
            ['Mango', 'Water based', 20, 'mango', null], ['Strawberry', 'Water based', 20, 'strawberry', null], ['Orange', 'Water based', 20, 'orange', null],
            ['Pineapple', 'Water based', 25, 'pineapple', null], ['Grape', 'Water based', 25, 'grape', null],
            ['Malai Kulfi', 'Milk based', 30, 'pop', '#F3E2C0'], ['Pista Kulfi', 'Milk based', 35, 'pop', '#A9C98A'], ['Chocolate Milk', 'Milk based', 30, 'pop', '#6B4226'],
            ['Choco Waffle', 'Waffles', 70, 'waffle', '#6B4226'], ['Strawberry Waffle', 'Waffles', 70, 'waffle', '#F0516F'], ['Butterscotch Waffle', 'Waffles', 80, 'waffle', '#E8A33D'],
            ['Vanilla Scoop', 'Ice Cream', 50, 'pop', '#FBF1D5'], ['Chocolate Scoop', 'Ice Cream', 60, 'pop', '#6B4226'],
            ['Mango Shake', 'Milkshakes', 90, 'pop', '#FFB020'], ['Oreo Shake', 'Milkshakes', 110, 'pop', '#3E3A44'],
            ['Water Bottle', 'Beverages', 20, 'pop', '#BFE3F2'],
        ];
        $inventory = app(InventoryService::class);
        foreach ($rows as $i => [$name, $cat, $price, $art, $color]) {
            $product = Product::firstOrCreate(['name' => $name], [
                'category_id' => $cats[$cat]->id,
                'sku' => 'NR-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'code' => (string) (100 + $i),
                'price' => $price,
                'cost' => round($price * 0.45, 2),
                'gst_rate' => 5,
                'art' => $art,
                'art_color' => $color,
                'min_stock' => 5,
                'display_order' => $i,
                'is_favourite' => $i < 3,
            ]);
            if ($product->wasRecentlyCreated) {
                $inventory->opening($inventory->ensureRow($product)->id, '40.000', null);
            }
        }
    }
}
