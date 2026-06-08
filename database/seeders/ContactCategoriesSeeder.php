<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class ContactCategoriesSeeder extends Seeder
{
    /**
     * Base categories for contacts.
     */
    private const CATEGORIES = [
        ['name' => 'Proveedores', 'slug' => 'proveedores', 'description' => 'Proveedores de bienes y servicios', 'color' => '#3B82F6'],
        ['name' => 'Soporte', 'slug' => 'soporte', 'description' => 'Equipo de soporte técnico', 'color' => '#10B981'],
        ['name' => 'Administración', 'slug' => 'administracion', 'description' => 'Área administrativa', 'color' => '#F59E0B'],
        ['name' => 'Otros', 'slug' => 'otros', 'description' => 'Categoría general sin clasificar', 'color' => '#6B7280'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            Category::query()->firstOrCreate(
                ['slug' => $category['slug']],
                $category,
            );
        }
    }
}
