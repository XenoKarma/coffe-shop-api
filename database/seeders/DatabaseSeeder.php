<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Users seeder
        User::create([
            'name' => 'Admin NARA',
            'email' => 'admin@nara.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'Kasir NARA',
            'email' => 'cashier@nara.test',
            'password' => Hash::make('password'),
            'role' => 'cashier',
        ]);

        // Category seeder
        $coffee = Category::create([
            'name' => 'Coffee',
            'description' => 'Coffee based beverages',
        ]);

        $nonCoffee = Category::create([
            'name' => 'Non Coffee',
            'description' => 'Non coffee beverages',
        ]);

        $food = Category::create([
            'name' => 'Food',
            'description' => 'Main dishes',
        ]);

        $snack = Category::create([
            'name' => 'Snack',
            'description' => 'Light meals and snacks',
        ]);

        // product seeder
        Product::create([
            'category_id' => $coffee->id,
            'name' => 'Americano',
            'sku' => 'COF-AME',
            'description' => null,
            'price' => 18000,
            'image' => null,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $coffee->id,
            'name' => 'Cafe Latte',
            'sku' => 'COF-LAT',
            'description' => null,
            'price' => 22000,
            'image' => null,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $coffee->id,
            'name' => 'Cappuccino',
            'sku' => 'COF-CAP',
            'description' => null,
            'price' => 22000,
            'image' => null,
            'is_active' => true,
        ]);

        // non coffe
        Product::create([
            'category_id' => $nonCoffee->id,
            'name' => 'Matcha Latte',
            'sku' => 'NC-MAT',
            'description' => null,
            'price' => 24000,
            'image' => null,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $nonCoffee->id,
            'name' => 'Chocolate',
            'sku' => 'NC-CHO',
            'description' => null,
            'price' => 22000,
            'image' => null,
            'is_active' => true,
        ]);

        // food
        Product::create([
            'category_id' => $food->id,
            'name' => 'Nasi Goreng',
            'sku' => 'FOO-NGR',
            'description' => null,
            'price' => 28000,
            'image' => null,
            'is_active' => true,
        ]);

        // snack
        Product::create([
            'category_id' => $snack->id,
            'name' => 'French Fries',
            'sku' => 'SNK-FRF',
            'description' => null,
            'price' => 18000,
            'image' => null,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $snack->id,
            'name' => 'Chicken Wings',
            'sku' => 'SNK-WNG',
            'description' => null,
            'price' => 25000,
            'image' => null,
            'is_active' => true,
        ]);
    }
}
