<?php

use App\Models\Category;
use App\Models\Contact;
use Database\Seeders\ContactCategoriesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('contact factory creates valid model', function () {
    $contact = Contact::factory()->create();

    expect($contact)->id->not->toBeNull()
        ->and($contact->name)->not->toBeNull()
        ->and($contact->email)->not->toBeNull();
});

test('category factory creates valid model', function () {
    $category = Category::factory()->create();

    expect($category)->id->not->toBeNull()
        ->and($category->name)->not->toBeNull()
        ->and($category->slug)->not->toBeNull();
});

test('contact belongs to many categories', function () {
    $contact = Contact::factory()->create();
    $categories = Category::factory()->count(3)->create();

    $contact->categories()->attach($categories);

    expect($contact->categories)->toHaveCount(3)
        ->and($contact->categories->pluck('id')->toArray())->toBe($categories->pluck('id')->toArray());
});

test('category belongs to many contacts', function () {
    $category = Category::factory()->create();
    $contacts = Contact::factory()->count(2)->create();

    $category->contacts()->attach($contacts);

    expect($category->contacts)->toHaveCount(2)
        ->and($category->contacts->pluck('id')->toArray())->toBe($contacts->pluck('id')->toArray());
});

test('contact categories seeder creates base categories', function () {
    $this->seed(ContactCategoriesSeeder::class);

    expect(Category::count())->toBe(4)
        ->and(Category::where('slug', 'proveedores')->exists())->toBeTrue()
        ->and(Category::where('slug', 'soporte')->exists())->toBeTrue()
        ->and(Category::where('slug', 'administracion')->exists())->toBeTrue()
        ->and(Category::where('slug', 'otros')->exists())->toBeTrue();
});

test('contact categories seeder is idempotent', function () {
    $this->seed(ContactCategoriesSeeder::class);
    $this->seed(ContactCategoriesSeeder::class);

    expect(Category::count())->toBe(4);
});

test('pivot table enforces unique category contact pair', function () {
    $contact = Contact::factory()->create();
    $category = Category::factory()->create();

    $contact->categories()->attach($category);

    // Attempting to attach the same pair again should throw due to unique constraint
    expect(fn () => $contact->categories()->attach($category))->toThrow(QueryException::class);
});
