<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'sku'              => 'WD-SAMSUNG-8KG',
                'name'             => 'Samsung 8kg Front Load Washer Dryer',
                'description'      => 'AI-powered inverter motor, 5-star energy rating.',
                'stock_quantity'   => 10,
                'reserved_quantity'=> 0,
                'price'            => 2499.00,
                'is_active'        => true,
            ],
            [
                'sku'              => 'WD-LG-10KG',
                'name'             => 'LG 10kg TurboWash Washer Dryer',
                'description'      => 'Steam cleaning, TurboWash 360.',
                'stock_quantity'   => 5,
                'reserved_quantity'=> 0,
                'price'            => 3299.00,
                'is_active'        => true,
            ],
            [
                'sku'              => 'WD-DISCONTINUED',
                'name'             => 'Legacy Model (Discontinued)',
                'description'      => 'No longer sold.',
                'stock_quantity'   => 0,
                'reserved_quantity'=> 0,
                'price'            => 999.00,
                'is_active'        => false,
            ],
        ];

        foreach ($products as $data) {
            Product::updateOrCreate(['sku' => $data['sku']], $data);
        }

        $this->command->info('Seeded ' . count($products) . ' products.');
    }
}
